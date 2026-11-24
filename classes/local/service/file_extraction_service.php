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
use local_jedomi\ai\model_settings;

/**
 * Text of a file attached in the web chat, read by the AI service through the Datacurso provider.
 *
 * Documents (.pdf, .csv, .txt, .md, and Word and PowerPoint .doc, .docx, .ppt, .pptx) go to
 * POST /jedomi/v1/files/extract, which reads their text for free; a PDF without text (scanned) is read
 * again by the vision model through files/read. Audio
 * files and microphone recordings are transcribed by the audio model. Each kind is refused when its
 * feature is off in the Jedomi settings.
 *
 * With the custom service, documents are read only while its documents are on ({@see model_settings::is_documents_enabled()},
 * otherwise documents_error_custom): a PDF goes to its document service and its pages to its vision model
 * ({@see vision_service}); a text file (.txt, .csv, .md) is read on the site, up to MAX_TEXT_BYTES and cut at
 * MAX_TEXT_CHARS characters; Word and PowerPoint files are refused (documents_error_custom_office), as a generic
 * OpenAI-compatible service has no route that reads them.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_extraction_service {
    /** @var string AI service endpoint. */
    public const ENDPOINT = '/jedomi/v1/files/extract';

    /** @var string[] Word and PowerPoint extensions, read as text by the AI service without a model. */
    public const OFFICE_EXTENSIONS = ['doc', 'docx', 'ppt', 'pptx'];

    /** @var string[] Accepted document extensions. */
    public const EXTENSIONS = ['pdf', 'csv', 'txt', 'md', 'doc', 'docx', 'ppt', 'pptx'];

    /** @var string[] Text extensions the custom service reads on the site. */
    public const TEXT_EXTENSIONS = ['txt', 'csv', 'md'];

    /** @var int Largest text file read on the site with the custom service, in bytes (2 MB). */
    public const MAX_TEXT_BYTES = 2097152;

    /** @var int Most characters kept from a text file read on the site; the rest is cut. */
    public const MAX_TEXT_CHARS = 200000;

    /** @var int Total request timeout, in seconds. */
    private const TIMEOUT = 120;

    /** @var array<int, array{0: string, 1: string}> Code and message string for each file error of the AI service. */
    private const SERVICE_ERRORS = [
        413 => ['file_too_large', 'file_error_too_large'],
        415 => ['file_unsupported', 'file_error_unsupported'],
        422 => ['file_no_text', 'file_error_no_text'],
    ];

    /** @var array<string, array{0: string, 1: string}> Code and message string for each local error. */
    private const LOCAL_ERRORS = [
        'file_error_unsupported' => ['file_unsupported', 'file_error_unsupported'],
        'files_error_disabled' => ['files_disabled', 'files_error_disabled'],
        'documents_error_custom' => ['documents_unavailable', 'documents_error_custom'],
        'documents_error_custom_office' => ['documents_unavailable', 'documents_error_custom_office'],
        'documents_error_deadline' => ['documents_unavailable', 'documents_error_deadline'],
        'file_error_too_large' => ['file_too_large', 'file_error_too_large'],
    ];

    /**
     * Read the text of an uploaded document, or the transcription of an uploaded audio file.
     *
     * @param int $userid User who uploaded the file.
     * @param string $path Path of the uploaded file on disk.
     * @param string $filename Original file name.
     * @param ai_services_api|null $api Provider client; null for Jedomi's own.
     * @return array{text: string, format: string, pages: int, rows: int, columns: int, chars: int, seconds: int,
     *     truncated: bool}
     * @throws \moodle_exception file_error_upload_too_large (above provider_upload_service::MAX_UPLOAD_BYTES, before
     *         anything else), file_error_unsupported, files_error_disabled, documents_error_custom, audio_error_*, or any
     *         provider error.
     */
    public static function extract_upload(int $userid, string $path, string $filename, ?ai_services_api $api = null): array {
        provider_upload_service::require_upload_size($path);
        $filename = clean_param($filename, PARAM_FILE);
        if ($filename !== '' && audio_service::is_supported($filename)) {
            $audio = audio_service::transcribe_upload($userid, $path, $filename, $api);
            return self::result(['text' => $audio['text'], 'format' => 'audio',
                'seconds' => (int) round($audio['duration_seconds'])]);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($filename === '' || !in_array($extension, self::EXTENSIONS, true) || !is_readable($path)) {
            throw new \moodle_exception('file_error_unsupported', 'local_jedomi');
        }
        if (!model_settings::is_files_enabled()) {
            throw new \moodle_exception('files_error_disabled', 'local_jedomi');
        }
        if (!model_settings::is_documents_enabled()) {
            throw new \moodle_exception('documents_error_custom', 'local_jedomi');
        }
        if (ai_service::is_custom()) {
            return self::extract_with_custom_service($userid, $path, $filename, $extension, $api);
        }

        try {
            $response = provider_upload_service::send($userid, $path, $filename, self::ENDPOINT, [], self::TIMEOUT, $api);
        } catch (\moodle_exception $e) {
            if ($extension !== 'pdf' || provider_upload_service::http_status($e) !== 422) {
                throw $e;
            }
            // A scanned PDF has no text: its pages are read by the vision model.
            $read = vision_service::read_upload($userid, $path, $filename, '', $api);
            return self::result(['text' => $read['text'], 'format' => 'pdf', 'pages' => (int) $read['pages_total']]);
        }

        return self::result($response);
    }

    /**
     * Read a document with the custom service: a PDF through its document service and vision model, a text file on the
     * site; Word and PowerPoint files are refused.
     *
     * @param int $userid User who uploaded the file.
     * @param string $path Path of the uploaded file on disk.
     * @param string $filename Clean file name.
     * @param string $extension Lowercase extension.
     * @param ai_services_api|null $api Provider client; unused by the custom service.
     * @return array
     * @throws \moodle_exception documents_error_custom_office, file_error_too_large, docservice_error_* or custom service
     *         errors.
     */
    private static function extract_with_custom_service(
        int $userid,
        string $path,
        string $filename,
        string $extension,
        ?ai_services_api $api
    ): array {
        if ($extension === 'pdf') {
            $read = vision_service::read_upload($userid, $path, $filename, '', $api);
            return self::result(['text' => $read['text'], 'format' => 'pdf', 'pages' => (int) $read['pages_total'],
                'truncated' => !empty($read['truncated'])]);
        }
        if (!in_array($extension, self::TEXT_EXTENSIONS, true)) {
            throw new \moodle_exception('documents_error_custom_office', 'local_jedomi');
        }
        return self::read_text_file($path, $extension);
    }

    /**
     * Read a text file on the site: UTF-8 (another encoding is read as Windows-1252), without its byte order mark, cut
     * at MAX_TEXT_CHARS characters. A CSV file also reports its rows and the columns of its first row.
     *
     * @param string $path Path of the file on disk.
     * @param string $extension Lowercase extension: txt, csv or md.
     * @return array
     * @throws \moodle_exception file_error_too_large over MAX_TEXT_BYTES.
     */
    private static function read_text_file(string $path, string $extension): array {
        if ((int) filesize($path) > self::MAX_TEXT_BYTES) {
            throw new \moodle_exception('file_error_too_large', 'local_jedomi');
        }
        $text = (string) file_get_contents($path);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = \core_text::convert($text, 'windows-1252', 'utf-8');
        }
        $text = str_replace("\r\n", "\n", $text);
        $truncated = \core_text::strlen($text) > self::MAX_TEXT_CHARS;
        if ($truncated) {
            $text = \core_text::substr($text, 0, self::MAX_TEXT_CHARS);
        }
        $result = ['text' => $text, 'format' => 'text', 'chars' => \core_text::strlen($text), 'truncated' => $truncated];
        if ($extension === 'csv') {
            $lines = array_values(array_filter(explode("\n", $text), static fn(string $line): bool => trim($line) !== ''));
            $result += ['rows' => count($lines), 'columns' => empty($lines) ? 0 : count(str_getcsv($lines[0]))];
            $result['format'] = 'csv';
        }
        return self::result($result);
    }

    /**
     * Whether a file is a Word or PowerPoint document.
     *
     * @param string $filename File name.
     * @return bool
     */
    public static function is_office_document(string $filename): bool {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::OFFICE_EXTENSIONS, true);
    }

    /**
     * Message for the chat model with the text of an attached file: the question, the file name and its text.
     *
     * The name and the text of the file are untrusted data (JEDOMI-SEC-005, RISK-001), see {@see self::untrusted_text()}.
     *
     * @param string $question Question of the user; without one, a summary is asked.
     * @param string $filename Name of the file.
     * @param string $text Text read from the file.
     * @return string
     */
    public static function compose_message(string $question, string $filename, string $text): string {
        $question = trim($question) !== '' ? trim($question) : get_string('analyze_file', 'local_jedomi');
        return $question . "\n\n" . self::untrusted_text($filename, $text);
    }

    /**
     * Answer of extract_file.php: the fields read from the file plus untrusted_text, the only text the panel sends to
     * the AI (RES-001).
     *
     * @param string $filename Name of the file.
     * @param array $result Fields returned by {@see self::extract_upload()}.
     * @return array
     */
    public static function extraction_response(string $filename, array $result): array {
        return $result + ['untrusted_text' => self::untrusted_text($filename, (string) ($result['text'] ?? ''))];
    }

    /**
     * Name and text of an attached file, between untrusted data markers (JEDOMI-SEC-005, SEC-JD-006, RISK-001).
     *
     * Whoever made the file is not the user, and also chose its name: the rule of the system message tells the AI never
     * to follow instructions written inside the block, and markers written in the file are neutralised so it cannot
     * close the block.
     *
     * @param string $filename Name of the file.
     * @param string $text Text read from the file.
     * @return string
     */
    public static function untrusted_text(string $filename, string $text): string {
        return (new \local_jedomi\local\untrusted())->wrap(
            'file',
            get_string('attached_file_info', 'local_jedomi', $filename) . "\n" . trim($text)
        );
    }

    /**
     * Code and user message for a failed extraction; internal details never reach the message.
     *
     * @param \Throwable $e Failure.
     * @return array{code: string, message: string}
     */
    public static function error_for(\Throwable $e): array {
        if ($e instanceof \moodle_exception && $e->module === 'local_jedomi' && str_starts_with($e->errorcode, 'audio_error_')) {
            return audio_service::error_for($e);
        }
        if (
            $e instanceof \moodle_exception && $e->module === 'local_jedomi'
                && (str_starts_with($e->errorcode, 'image_error_') || str_starts_with($e->errorcode, 'docservice_error_'))
        ) {
            return vision_service::error_for($e);
        }
        return provider_upload_service::error_for($e, self::SERVICE_ERRORS, self::LOCAL_ERRORS);
    }

    /**
     * Result with every field and its type.
     *
     * @param array $response Decoded answer of the AI service, or the fields already known.
     * @return array{text: string, format: string, pages: int, rows: int, columns: int, chars: int, seconds: int,
     *     truncated: bool}
     */
    private static function result(array $response): array {
        return [
            'text' => (string) ($response['text'] ?? ''),
            'format' => clean_param((string) ($response['format'] ?? 'text'), PARAM_ALPHA),
            'pages' => (int) ($response['pages'] ?? 0),
            'rows' => (int) ($response['rows'] ?? 0),
            'columns' => (int) ($response['columns'] ?? 0),
            'chars' => (int) ($response['chars'] ?? 0),
            'seconds' => (int) ($response['seconds'] ?? 0),
            'truncated' => !empty($response['truncated']),
        ];
    }
}
