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
use local_jedomi\ai\error_message;
use local_jedomi\ai\provider_api;

/**
 * Text of a file attached in the web chat, read by the AI service through the Datacurso provider.
 *
 * The upload is kept as a temporary draft file of the user only while it is sent, so the
 * provider's upload_file() can read it from the file storage API.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_extraction_service {
    /** @var string AI service endpoint. */
    public const ENDPOINT = '/jedomi/v1/files/extract';

    /** @var string[] Accepted file extensions. */
    public const EXTENSIONS = ['pdf', 'csv', 'txt', 'md'];

    /** @var int Total request timeout, in seconds. */
    private const TIMEOUT = 120;

    /** @var array<int, array{0: string, 1: string}> Code and message string for each file error of the AI service. */
    private const SERVICE_ERRORS = [
        413 => ['file_too_large', 'file_error_too_large'],
        415 => ['file_unsupported', 'file_error_unsupported'],
        422 => ['file_no_text', 'file_error_no_text'],
    ];

    /**
     * Read the text of an uploaded file.
     *
     * @param int $userid User who uploaded the file.
     * @param string $path Path of the uploaded file on disk.
     * @param string $filename Original file name.
     * @param ai_services_api|null $api Provider client; null for Jedomi's own.
     * @return array{text: string, format: string, pages: int, rows: int, columns: int, chars: int, truncated: bool}
     * @throws \moodle_exception file_error_unsupported, or any provider error.
     */
    public static function extract_upload(int $userid, string $path, string $filename, ?ai_services_api $api = null): array {
        $filename = clean_param($filename, PARAM_FILE);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($filename === '' || !in_array($extension, self::EXTENSIONS, true) || !is_readable($path)) {
            throw new \moodle_exception('file_error_unsupported', 'local_jedomi');
        }

        $fs = get_file_storage();
        $file = $fs->create_file_from_pathname([
            'contextid' => \context_user::instance($userid)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $userid,
        ], $path);

        try {
            $response = ($api ?? new provider_api(self::TIMEOUT))->upload_file(self::ENDPOINT, $file);
        } finally {
            $file->delete();
        }

        $response = is_array($response) ? $response : [];
        return [
            'text' => (string) ($response['text'] ?? ''),
            'format' => clean_param((string) ($response['format'] ?? 'text'), PARAM_ALPHA),
            'pages' => (int) ($response['pages'] ?? 0),
            'rows' => (int) ($response['rows'] ?? 0),
            'columns' => (int) ($response['columns'] ?? 0),
            'chars' => (int) ($response['chars'] ?? 0),
            'truncated' => !empty($response['truncated']),
        ];
    }

    /**
     * Code and user message for a failed extraction; internal details never reach the message.
     *
     * @param \Throwable $e Failure.
     * @return array{code: string, message: string}
     */
    public static function error_for(\Throwable $e): array {
        if ($e instanceof \moodle_exception && $e->module === 'local_jedomi' && $e->errorcode === 'file_error_unsupported') {
            return ['code' => 'file_unsupported', 'message' => get_string('file_error_unsupported', 'local_jedomi')];
        }
        if ($e instanceof \moodle_exception && $e->module === 'aiprovider_datacurso' && $e->errorcode === 'httperror'
                && isset(self::SERVICE_ERRORS[(int) $e->a])) {
            [$code, $key] = self::SERVICE_ERRORS[(int) $e->a];
            return ['code' => $code, 'message' => get_string($key, 'local_jedomi')];
        }
        return error_message::from_exception($e);
    }
}
