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
 * Sends a file of a user to a Jedomi route of the AI service through the Datacurso provider.
 *
 * The provider's upload_file() reads files from the file storage API, so a file on disk (a browser
 * upload or a Telegram download) is kept as a temporary draft file of the user only while it is sent.
 * The provider adds the licence, the X-Plugin-* headers and the pseudonymised user and site.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_upload_service {
    /**
     * @var int Largest file attached in the web chat (extract_file.php, read_file.php) or read for Telegram, in bytes
     *     (20 MB): the PDF cap of the document service of the custom service (docservice_client::MAX_PDF_BYTES) and the
     *     file cap of the Datacurso AI service, which refuses larger request bodies. A larger file is refused before it is
     *     read or sent (SEC-JD-074). The custom service keeps its smaller caps for images and text files.
     */
    public const MAX_UPLOAD_BYTES = 20971520;

    /** @var ai_services_api|null Provider client used instead of Jedomi's own in PHPUnit tests. */
    private static ?ai_services_api $testapi = null;

    /**
     * Replace the provider client used when a caller passes none. Only for PHPUnit tests.
     *
     * @param ai_services_api|null $api Client to use; null to restore Jedomi's own.
     * @return void
     */
    public static function set_api_for_testing(?ai_services_api $api): void {
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('provider_upload_service::set_api_for_testing() is only for PHPUnit tests');
        }
        self::$testapi = $api;
    }

    /**
     * Stop when a file on disk is larger than MAX_UPLOAD_BYTES, before it is read or sent (SEC-JD-074).
     *
     * @param string $path Path of the file on disk.
     * @return void
     * @throws \moodle_exception file_error_upload_too_large, with the cap in MB.
     */
    public static function require_upload_size(string $path): void {
        if (is_file($path) && (int) filesize($path) > self::MAX_UPLOAD_BYTES) {
            throw new \moodle_exception('file_error_upload_too_large', 'local_jedomi', '', self::max_upload_mb());
        }
    }

    /**
     * MAX_UPLOAD_BYTES in megabytes, as the messages tell it.
     *
     * @return int
     */
    public static function max_upload_mb(): int {
        return intdiv(self::MAX_UPLOAD_BYTES, 1048576);
    }

    /**
     * Send a file on disk to an AI service route and return the decoded answer.
     *
     * @param int $userid User the file belongs to.
     * @param string $path Path of the file on disk.
     * @param string $filename File name sent to the service; must already be clean.
     * @param string $endpoint Route of the AI service.
     * @param array $params Extra form fields; empty values are not sent.
     * @param int $timeout Total request timeout, in seconds, of Jedomi's own client.
     * @param ai_services_api|null $api Provider client; null for Jedomi's own.
     * @return array Decoded answer; empty when it is not an object.
     */
    public static function send(
        int $userid,
        string $path,
        string $filename,
        string $endpoint,
        array $params,
        int $timeout,
        ?ai_services_api $api = null
    ): array {
        $file = get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance($userid)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $userid,
        ], $path);

        try {
            return self::send_stored_file($file, $endpoint, $params, $timeout, $api);
        } finally {
            $file->delete();
        }
    }

    /**
     * Send a file of the file storage API to an AI service route and return the decoded answer.
     *
     * @param \stored_file $file File to send.
     * @param string $endpoint Route of the AI service.
     * @param array $params Extra form fields; empty values are not sent.
     * @param int $timeout Total request timeout, in seconds, of Jedomi's own client.
     * @param ai_services_api|null $api Provider client; null for Jedomi's own.
     * @return array Decoded answer; empty when it is not an object.
     */
    public static function send_stored_file(
        \stored_file $file,
        string $endpoint,
        array $params,
        int $timeout,
        ?ai_services_api $api = null
    ): array {
        $params = array_filter($params, static fn($value): bool => $value !== null && $value !== '');
        $response = ($api ?? self::$testapi ?? new provider_api($timeout))->upload_file($endpoint, $file, $params);
        return is_array($response) ? $response : [];
    }

    /**
     * Code and user message for a failed upload; internal details never reach the message.
     *
     * @param \Throwable $e Failure.
     * @param array $statuserrors [code, message string key] for each HTTP status of the route, keyed by status.
     * @param array $localerrors [code, message string key] for each local_jedomi error code, keyed by error code.
     * @return array Keys code and message.
     */
    public static function error_for(\Throwable $e, array $statuserrors, array $localerrors): array {
        if ($e instanceof \moodle_exception && $e->module === 'local_jedomi' && $e->errorcode === 'file_error_upload_too_large') {
            return ['code' => 'file_too_large',
                'message' => get_string('file_error_upload_too_large', 'local_jedomi', self::max_upload_mb())];
        }
        if ($e instanceof \moodle_exception && $e->module === 'local_jedomi' && isset($localerrors[$e->errorcode])) {
            [$code, $key] = $localerrors[$e->errorcode];
            return ['code' => $code, 'message' => get_string($key, 'local_jedomi')];
        }
        $status = self::http_status($e);
        if ($status !== null && isset($statuserrors[$status])) {
            [$code, $key] = $statuserrors[$status];
            return ['code' => $code, 'message' => get_string($key, 'local_jedomi')];
        }
        return error_message::from_exception($e);
    }

    /**
     * HTTP status of an error answer of the AI service (Datacurso or custom), or null for any other failure.
     *
     * @param \Throwable $e Failure.
     * @return int|null
     */
    public static function http_status(\Throwable $e): ?int {
        if (
            $e instanceof \moodle_exception
                && (($e->module === 'aiprovider_datacurso' && $e->errorcode === 'httperror')
                    || ($e->module === 'local_jedomi' && $e->errorcode === 'custom_error_http'))
        ) {
            return (int) $e->a;
        }
        return null;
    }
}
