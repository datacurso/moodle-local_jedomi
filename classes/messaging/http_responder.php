<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_jedomi\messaging;

/**
 * Sends the HTTP responses of the webhooks, including the early response that lets the request go on after it.
 *
 * Code that answers a webhook gets it from the container, \core\di::get(http_responder::class), so a test registers a
 * recording double and no header, flush or session close happens in PHPUnit.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class http_responder {
    /**
     * Headers of an early response: Content-Length must match the body (with a larger value a proxy keeps waiting for
     * the missing bytes), the connection is closed and compression is refused, so nothing holds the response back.
     *
     * @param string $contenttype Content type.
     * @param string $body Body.
     * @return string[]
     */
    public static function closing_headers(string $contenttype, string $body): array {
        return [
            'Content-Type: ' . $contenttype,
            'Content-Length: ' . strlen($body),
            'Connection: close',
            'Content-Encoding: identity',
        ];
    }

    /**
     * Send a response.
     *
     * @param int $status HTTP status code.
     * @param string[] $headers Header lines.
     * @param string $body Body.
     */
    public function send(int $status, array $headers, string $body): void {
        http_response_code($status);
        foreach ($headers as $header) {
            header($header);
        }
        echo $body;
    }

    /**
     * Send a response and close the connection, so the caller can keep processing after it.
     *
     * @param int $status HTTP status code.
     * @param string[] $headers Header lines; they should include those of closing_headers().
     * @param string $body Body.
     * @param bool $closesession Whether to close the session first (its lock is released and it is not written later).
     */
    public function send_and_close(int $status, array $headers, string $body, bool $closesession = false): void {
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        if ($closesession) {
            \core\session\manager::write_close();
        }
        $this->send($status, $headers, $body);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
