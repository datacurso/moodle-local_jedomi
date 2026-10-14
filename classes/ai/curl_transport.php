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

/**
 * cURL implementation of the AI transport.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

/**
 * The only place in the plugin that talks to cURL for AI completions.
 */
final class curl_transport implements transport {
    /** @var int Seconds allowed to establish the TCP connection. */
    public const CONNECT_TIMEOUT = 10;

    /**
     * POST a body and buffer the complete response.
     *
     * @param string $url Absolute URL.
     * @param string[] $headers Header lines in "Name: value" form.
     * @param string $body Raw request body.
     * @param int $timeout Total timeout in seconds.
     * @return array{code:int,body:string,errno:int,error:string}
     */
    public function post(string $url, array $headers, string $body, int $timeout): array {
        $buffer = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use (&$buffer): int {
                $buffer .= $data;
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $result = [
            'code'  => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body'  => $buffer,
            'errno' => (int) curl_errno($ch),
            'error' => (string) curl_error($ch),
        ];
        curl_close($ch);

        return $result;
    }
}
