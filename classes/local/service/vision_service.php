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
use local_jedomi\ai\model_settings;

/**
 * Reads images and PDF files with the vision model of Jedomi, through POST /jedomi/v1/files/read.
 *
 * The AI service returns only text: PDF pages with text are read directly and only the pages without
 * text (or the requested ones) go to the vision model. The chat model never receives images.
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
     * @throws \moodle_exception files_error_disabled, image_error_unsupported, or any provider error.
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
        $response = provider_upload_service::send($userid, $path, $filename, self::ENDPOINT,
            self::params($question, ''), self::TIMEOUT, $api);
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
     * @throws \moodle_exception files_error_disabled, image_error_unsupported, or any provider error.
     */
    public static function read_stored_file(
        \stored_file $file,
        string $pages = '',
        string $question = '',
        ?ai_services_api $api = null
    ): array {
        self::require_enabled();
        self::check_filename($file->get_filename());
        $response = provider_upload_service::send_stored_file($file, self::ENDPOINT,
            self::params($question, $pages), self::TIMEOUT, $api);
        return self::normalise($response);
    }

    /**
     * Message for the chat model: the question of the user followed by what the vision model read.
     *
     * @param string $question Question of the user; empty for the default "analyze this image" request.
     * @param string $filename Name of the image or file.
     * @param string $text Text returned by the vision model.
     * @return string
     */
    public static function compose_message(string $question, string $filename, string $text): string {
        $question = trim($question) !== '' ? trim($question) : get_string('analyze_image', 'local_jedomi');
        return $question . "\n\n" . get_string('attached_image_content', 'local_jedomi', $filename) . "\n" . trim($text);
    }

    /**
     * Code and user message for a failed read; internal details never reach the message.
     *
     * @param \Throwable $e Failure.
     * @return array{code: string, message: string}
     */
    public static function error_for(\Throwable $e): array {
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
