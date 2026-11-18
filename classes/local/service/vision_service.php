<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_jedomi\local\service;

use aiprovider_datacurso\httpclient\ai_services_api;
use local_jedomi\ai\ai_service;
use local_jedomi\ai\client;
use local_jedomi\ai\custom_api;
use local_jedomi\ai\docservice_client;
use local_jedomi\ai\model_settings;

/**
 * Reads images and PDF files with the vision model of Jedomi, through POST /jedomi/v1/files/read.
 *
 * The AI service returns only text: PDF pages with text are read directly and only the pages without
 * text (or the requested ones) go to the vision model. The chat model never receives images.
 *
 * With the custom service, an image is sent as image_url content of POST {url}/v1/chat/completions to the custom
 * vision model, which answers its text. A PDF file is first turned into page images by the document service of the
 * custom service ({@see docservice_client}), and each page (at most docservice_client::MAX_PAGES, only the requested
 * ones when pages are given) is read by the custom vision model the same way. Without the documents of the custom
 * service (its switch or the address of its document service), PDF files are refused with documents_error_custom.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vision_service {
    /** @var string AI service endpoint. */
    public const ENDPOINT = '/jedomi/v1/files/read';

    /** @var string[] Image extensions the service reads. */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var string[] Every extension the service reads. */
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /** @var int Total request timeout, in seconds: several scanned pages may go to the vision model. */
    private const TIMEOUT = 180;

    /** @var int Most skipped page numbers listed in a result; pages_skipped_total gives the full count. */
    public const MAX_SKIPPED_LISTED = 100;

    /** @var int Fewest seconds worth a request of a PDF read with the custom service; with less left, it stops. */
    public const MIN_REQUEST_SECONDS = 5;

    /** @var string[] Errors of the custom service that would repeat on the next page (no answer, timeout, address). */
    private const REPEATING_ERRORS = ['custom_error_unreachable', 'custom_error_url'];

    /** @var \Closure|null Clock of PHPUnit tests: fn(): int, in seconds. */
    private static ?\Closure $testclock = null;

    /** @var int Largest image sent to the custom service, in bytes. */
    public const MAX_CUSTOM_IMAGE_BYTES = 10485760;

    /** @var string Instruction of the custom vision model, before the question of the user. */
    private const CUSTOM_PROMPT = 'Read this image for an assistant that cannot see it. Transcribe all of its text exactly, '
        . 'then describe briefly what it shows (charts, tables, diagrams, photos). Answer only with that content.';

    /** @var array Content type of each image extension. */
    private const IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    /** @var array<int, array{0: string, 1: string}> Code and message string for each error status of the AI service. */
    private const STATUS_ERRORS = [
        413 => ['file_too_large', 'file_error_too_large'],
        415 => ['file_unsupported', 'image_error_unsupported'],
        422 => ['file_no_text', 'image_error_no_text'],
    ];

    /** @var array<string, array{0: string, 1: string}> Code and message string for each local error. */
    private const LOCAL_ERRORS = [
        'files_error_disabled' => ['files_disabled', 'files_error_disabled'],
        'image_error_unsupported' => ['file_unsupported', 'image_error_unsupported'],
        'documents_error_custom' => ['documents_unavailable', 'documents_error_custom'],
        'file_error_too_large' => ['file_too_large', 'file_error_too_large'],
        // Failures of the document service of the custom service: never its answer or its address.
        'docservice_error_url' => ['documents_unavailable', 'documents_error_service'],
        'docservice_error_unreachable' => ['documents_unavailable', 'documents_error_service'],
        'docservice_error_http' => ['documents_unavailable', 'documents_error_service'],
        'docservice_error_invalid' => ['documents_unavailable', 'documents_error_service'],
        'docservice_error_too_large' => ['file_too_large', 'file_error_too_large'],
        'documents_error_service' => ['documents_unavailable', 'documents_error_service'],
    ];

    /**
     * Read a file on disk (a browser upload or a Telegram download).
     *
     * @param int $userid User the file belongs to.
     * @param string $path Path of the file on disk.
     * @param string $filename Original file name; its extension decides the type.
     * @param string $question Question of the user about the file; empty for none.
     * @param ai_services_api|null $api Provider client; null for Jedomi's own.
     * @return array{text: string, model: string, pages_total: int|null, pages_vision: int[], pages_skipped: int[]}
     *         A PDF read with the custom service also has truncated, true when pages were left out.
     * @throws \moodle_exception files_error_disabled, image_error_unsupported, documents_error_custom (a PDF with the
     *         custom service and its documents off), file_error_too_large, docservice_error_*, or any provider or custom
     *         service error.
     */
    public static function read_upload(
        int $userid,
        string $path,
        string $filename,
        string $question = '',
        ?ai_services_api $api = null
    ): array {
        self::require_enabled();
        $filename = self::check_filename($filename);
        if (!is_readable($path)) {
            throw new \moodle_exception('image_error_unsupported', 'local_jedomi');
        }
        if (ai_service::is_custom() && self::is_pdf($filename)) {
            return self::read_pdf_with_custom_service($path, $filename, '', $question);
        }
        if (ai_service::is_custom()) {
            self::require_custom_image($filename, (int) filesize($path));
            return self::read_with_custom_service($filename, (string) file_get_contents($path), $question);
        }
        $response = provider_upload_service::send(
            $userid,
            $path,
            $filename,
            self::ENDPOINT,
            self::params($question, ''),
            self::TIMEOUT,
            $api
        );
        return self::normalise($response);
    }

    /**
     * Read a file of the file storage API, such as the PDF of a course activity.
     *
     * @param \stored_file $file File to read.
     * @param string $pages Pages to read, such as "1,3-5"; empty for the whole file.
     * @param string $question Question about the file; empty for none.
     * @param ai_services_api|null $api Provider client; null for Jedomi's own.
     * @return array{text: string, model: string, pages_total: int|null, pages_vision: int[], pages_skipped: int[]}
     * @throws \moodle_exception files_error_disabled, image_error_unsupported, documents_error_custom (a PDF with the
     *         custom service), file_error_too_large, or any provider or custom service error.
     */
    public static function read_stored_file(
        \stored_file $file,
        string $pages = '',
        string $question = '',
        ?ai_services_api $api = null
    ): array {
        self::require_enabled();
        $filename = self::check_filename($file->get_filename());
        if (ai_service::is_custom() && self::is_pdf($filename)) {
            self::require_custom_documents();
            if ((int) $file->get_filesize() > docservice_client::MAX_PDF_BYTES) {
                throw new \moodle_exception('file_error_too_large', 'local_jedomi');
            }
            $path = make_request_directory() . '/' . $filename;
            $file->copy_content_to($path);
            return self::read_pdf_with_custom_service($path, $filename, $pages, $question);
        }
        if (ai_service::is_custom()) {
            self::require_custom_image($filename, (int) $file->get_filesize());
            return self::read_with_custom_service($filename, $file->get_content(), $question);
        }
        $response = provider_upload_service::send_stored_file(
            $file,
            self::ENDPOINT,
            self::params($question, $pages),
            self::TIMEOUT,
            $api
        );
        return self::normalise($response);
    }

    /**
     * Message for the chat model: the question of the user followed by what the vision model read.
     *
     * What the vision model read can carry text hidden in the image, and the name of the image is chosen by whoever
     * made it, so both go between untrusted data markers (JEDOMI-SEC-005, SEC-JD-007, RISK-001).
     *
     * @param string $question Question of the user; empty for the default "analyze this image" request.
     * @param string $filename Name of the image or file.
     * @param string $text Text returned by the vision model.
     * @return string
     */
    public static function compose_message(string $question, string $filename, string $text): string {
        $question = trim($question) !== '' ? trim($question) : get_string('analyze_image', 'local_jedomi');
        return $question . "\n\n" . (new \local_jedomi\local\untrusted())->wrap(
            'image',
            get_string('attached_image_content', 'local_jedomi', $filename) . "\n" . trim($text)
        );
    }

    /**
     * Code and user message for a failed read; internal details never reach the message.
     *
     * @param \Throwable $e Failure.
     * @return array{code: string, message: string}
     */
    public static function error_for(\Throwable $e): array {
        if ($e instanceof \moodle_exception && $e->module === 'local_jedomi' && $e->errorcode === 'documents_error_pages') {
            return ['code' => 'file_no_text', 'message' => get_string('documents_error_pages', 'local_jedomi', (int) $e->a)];
        }
        return provider_upload_service::error_for($e, self::STATUS_ERRORS, self::LOCAL_ERRORS);
    }

    /**
     * Whether a file name has an extension the service reads.
     *
     * @param string $filename File name.
     * @return bool
     */
    public static function is_supported(string $filename): bool {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Whether a file name is a PDF file.
     *
     * @param string $filename File name.
     * @return bool
     */
    private static function is_pdf(string $filename): bool {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf';
    }

    /**
     * Stop unless the documents of the custom service are on.
     *
     * @throws \moodle_exception documents_error_custom
     */
    private static function require_custom_documents(): void {
        if (!model_settings::is_documents_enabled()) {
            throw new \moodle_exception('documents_error_custom', 'local_jedomi');
        }
    }

    /**
     * Replace the clock of the deadline of a PDF read with the custom service. Only for PHPUnit tests.
     *
     * @param \Closure|null $clock fn(): int returning the current time in seconds; null to restore time().
     * @return void
     */
    public static function set_clock_for_testing(?\Closure $clock): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('vision_service::set_clock_for_testing() is only for PHPUnit tests');
        }
        self::$testclock = $clock;
    }

    /**
     * Read a PDF file with the custom service: the document service turns it into page images, and the custom vision
     * model reads each page, one request per page, in order.
     *
     * The whole read has one deadline, the AI request timeout ({@see client::configured_timeout()}): the document
     * service and each page get the time left (at most their own timeout), and the pages left when less than
     * MIN_REQUEST_SECONDS remain are skipped. A page the vision model fails on is skipped and the read goes on, except
     * after a failure that would repeat on every page (no answer, timeout, refused address), which stops it. The pages
     * read are always kept; a failure is raised only when no page could be read. Each page is logged with its number,
     * duration and outcome, never its content.
     *
     * @param string $path Path of the PDF on disk.
     * @param string $filename Clean file name.
     * @param string $pages Pages to read, such as "1,3-5"; empty for every page the document service returns.
     * @param string $question Question of the user about the file; empty for none.
     * @return array{text: string, model: string, pages_total: int|null, pages_vision: int[], pages_skipped: int[],
     *     pages_skipped_total: int, truncated: bool} The pages read and the pages of the document left unread (at most
     *     MAX_SKIPPED_LISTED listed, all of them counted).
     * @throws \moodle_exception documents_error_custom, documents_error_pages (no asked page is in the document),
     *         file_error_too_large, docservice_error_*, or the custom service error of the first page when no page was
     *         read.
     */
    private static function read_pdf_with_custom_service(string $path, string $filename, string $pages, string $question): array {
        self::require_custom_documents();
        $deadline = self::now() + client::configured_timeout();
        $wanted = self::parse_pages($pages);
        $document = docservice_client::from_settings()->to_images(
            $path,
            $filename,
            $wanted,
            min(docservice_client::TIMEOUT, max(1, $deadline - self::now()))
        );
        if (empty($document['pages'])) {
            throw new \moodle_exception('documents_error_pages', 'local_jedomi', '', $document['total_pages']);
        }

        $read = [];
        $texts = [];
        $failure = null;
        foreach ($document['pages'] as $page) {
            $remaining = $deadline - self::now();
            if ($remaining < self::MIN_REQUEST_SECONDS) {
                self::log_page($page['page'], 0, 'deadline');
                break;
            }
            $start = microtime(true);
            try {
                $answer = self::ask_custom_vision_model(
                    'data:' . $page['type'] . ';base64,' . $page['data'],
                    $question,
                    min(self::TIMEOUT, $remaining)
                );
            } catch (\moodle_exception $e) {
                self::log_page($page['page'], $start, $e->errorcode);
                $failure = $failure ?? $e;
                if (in_array($e->errorcode, self::REPEATING_ERRORS, true)) {
                    break;
                }
                continue;
            }
            self::log_page($page['page'], $start, 'ok');
            $texts[] = get_string('document_page', 'local_jedomi', $page['page']) . "\n" . trim($answer);
            $read[] = $page['page'];
        }
        if (empty($read)) {
            throw $failure ?? new \moodle_exception('documents_error_service', 'local_jedomi');
        }

        // Skipped: every page of the document, or of the request, that was not read (the total is capped by
        // docservice_client::MAX_TOTAL_PAGES); listed up to MAX_SKIPPED_LISTED.
        $total = $document['total_pages'];
        $candidates = $wanted !== null
            ? array_filter($wanted, static fn(int $number): bool => $number <= $total)
            : range(1, max(1, $total));
        $skipped = array_values(array_diff($candidates, $read));
        $skippedtotal = count($skipped);
        $skipped = array_slice($skipped, 0, self::MAX_SKIPPED_LISTED);
        return self::normalise([
            'text' => implode("\n\n", $texts),
            'model' => model_settings::get_vision_model(),
            'pages_total' => $total,
            'pages_vision' => $read,
            'pages_skipped' => $skipped,
        ]) + ['pages_skipped_total' => $skippedtotal, 'truncated' => $skippedtotal > 0];
    }

    /**
     * Current time of the deadline of a PDF read, in seconds.
     *
     * @return int
     */
    private static function now(): int {
        return self::$testclock !== null ? (self::$testclock)() : time();
    }

    /**
     * Write a page of a PDF read with the custom service to the technical log: number, duration and outcome only.
     *
     * @param int $page Page number.
     * @param float $start Moment the request started (microtime), or 0 when none was sent.
     * @param string $outcome ok, deadline, or the error code.
     * @return void
     */
    private static function log_page(int $page, float $start, string $outcome): void {
        require_once(__DIR__ . '/../../../lib.php');
        \local_jedomi_log('VISION_PAGE', [
            'page' => $page,
            'duration_ms' => $start > 0 ? (int) round((microtime(true) - $start) * 1000) : 0,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Page numbers of a pages request such as "1,3-5".
     *
     * Each range is cut to MAX_PAGES pages and the request to MAX_TOTAL_PAGES numbers.
     *
     * @param string $pages Pages request; empty for every page.
     * @return int[]|null Page numbers, or null for every page.
     */
    private static function parse_pages(string $pages): ?array {
        $numbers = [];
        foreach (explode(',', $pages) as $part) {
            if (preg_match('/^\s*(\d{1,9})\s*(?:-\s*(\d{1,9})\s*)?$/', $part, $match)) {
                $first = max(1, (int) $match[1]);
                $last = isset($match[2]) ? min((int) $match[2], $first + docservice_client::MAX_PAGES - 1) : $first;
                $numbers = array_merge($numbers, $first <= $last ? range($first, $last) : []);
            }
            if (count($numbers) >= docservice_client::MAX_TOTAL_PAGES) {
                break;
            }
        }
        $numbers = array_slice(array_values(array_unique($numbers)), 0, docservice_client::MAX_TOTAL_PAGES);
        sort($numbers);
        return empty($numbers) ? null : $numbers;
    }

    /**
     * Stop unless the custom service can read the file: only images up to {@see self::MAX_CUSTOM_IMAGE_BYTES}.
     *
     * @param string $filename Clean file name.
     * @param int $size Size, in bytes.
     * @throws \moodle_exception documents_error_custom for a PDF, file_error_too_large for a large image.
     */
    private static function require_custom_image(string $filename, int $size): void {
        if (!isset(self::IMAGE_TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))])) {
            throw new \moodle_exception('documents_error_custom', 'local_jedomi');
        }
        if ($size > self::MAX_CUSTOM_IMAGE_BYTES) {
            throw new \moodle_exception('file_error_too_large', 'local_jedomi');
        }
    }

    /**
     * Read an image with the vision model of the custom service.
     *
     * @param string $filename Clean image file name; its extension gives the content type.
     * @param string $content Image bytes.
     * @param string $question Question of the user about the image; empty for none.
     * @return array
     * @throws \moodle_exception Custom service errors, or ai_error_invalidanswer when the answer has no text.
     */
    private static function read_with_custom_service(string $filename, string $content, string $question): array {
        $type = self::IMAGE_TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))];
        $text = self::ask_custom_vision_model('data:' . $type . ';base64,' . base64_encode($content), $question);
        return self::normalise(['text' => $text, 'model' => model_settings::get_vision_model()]);
    }

    /**
     * Ask the vision model of the custom service to read one image.
     *
     * @param string $dataurl Image as a data URL.
     * @param string $question Question of the user about the image; empty for none.
     * @param int $timeout Total request timeout, in seconds.
     * @return string Text of the answer.
     * @throws \moodle_exception Custom service errors, or ai_error_invalidanswer when the answer has no text.
     */
    private static function ask_custom_vision_model(string $dataurl, string $question, int $timeout = self::TIMEOUT): string {
        $prompt = self::CUSTOM_PROMPT . (trim($question) !== '' ? "\n\nQuestion of the user: " . trim($question) : '');
        $answer = custom_api::from_settings()->chat([
            'model' => model_settings::get_vision_model(),
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataurl]],
                ],
            ]],
            'max_tokens' => model_settings::get_max_tokens(),
            'stream' => false,
        ], $timeout);
        $text = $answer['choices'][0]['message']['content'] ?? null;
        if (!is_string($text)) {
            throw new \moodle_exception('ai_error_invalidanswer', 'local_jedomi');
        }
        return $text;
    }

    /**
     * Stop when file and image processing is off.
     *
     * @throws \moodle_exception files_error_disabled
     */
    private static function require_enabled(): void {
        if (!model_settings::is_files_enabled()) {
            throw new \moodle_exception('files_error_disabled', 'local_jedomi');
        }
    }

    /**
     * Clean a file name and check its extension.
     *
     * @param string $filename File name.
     * @return string Clean file name.
     * @throws \moodle_exception image_error_unsupported
     */
    private static function check_filename(string $filename): string {
        $filename = clean_param($filename, PARAM_FILE);
        if ($filename === '' || !self::is_supported($filename)) {
            throw new \moodle_exception('image_error_unsupported', 'local_jedomi');
        }
        return $filename;
    }

    /**
     * Form fields of the request.
     *
     * @param string $question Question about the file.
     * @param string $pages Pages to read.
     * @return array<string, string>
     */
    private static function params(string $question, string $pages): array {
        return [
            'model' => model_settings::get_vision_model(),
            'question' => trim($question),
            'pages' => trim($pages),
        ];
    }

    /**
     * Keep only the expected fields of the answer, with their types.
     *
     * @param array $response Decoded answer.
     * @return array{text: string, model: string, pages_total: int|null, pages_vision: int[], pages_skipped: int[]}
     */
    private static function normalise(array $response): array {
        $ints = static fn($list): array => is_array($list) ? array_values(array_map('intval', $list)) : [];
        return [
            'text' => (string) ($response['text'] ?? ''),
            'model' => (string) ($response['model'] ?? ''),
            'pages_total' => isset($response['pages_total']) ? (int) $response['pages_total'] : null,
            'pages_vision' => $ints($response['pages_vision'] ?? []),
            'pages_skipped' => $ints($response['pages_skipped'] ?? []),
        ];
    }
}
