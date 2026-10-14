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
 * HTTP transport contract used by the AI backends.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

/**
 * Minimal blocking HTTP POST abstraction.
 *
 * Backends only need to POST a JSON body and read the whole response
 * (an SSE stream is buffered and parsed afterwards). Abstracting the
 * transport keeps cURL in a single place and lets tests inject canned
 * responses without touching the network.
 */
interface transport {
    /**
     * POST a body and buffer the complete response.
     *
     * Never throws: transport failures are reported through errno/error so
     * callers can tell a timeout apart from a connection refusal.
     *
     * @param string $url Absolute URL.
     * @param string[] $headers Header lines in "Name: value" form.
     * @param string $body Raw request body.
     * @param int $timeout Total timeout in seconds.
     * @return array{code:int,body:string,errno:int,error:string} HTTP status (0 when the
     *         request never completed), buffered body, cURL errno and message.
     */
    public function post(string $url, array $headers, string $body, int $timeout): array;
}
