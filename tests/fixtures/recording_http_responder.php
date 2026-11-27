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

/**
 * Test double for the HTTP responder.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Responder that records the responses instead of sending headers, flushing or closing the session.
 *
 * Register it with \core\di::set(http_responder::class, $responder); the container is reset after every test.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_http_responder extends http_responder {
    /** @var array[] Responses sent: status, headers, body and whether the connection was closed. */
    public array $responses = [];

    /**
     * Constructor.
     *
     * @param \ArrayObject|null $journal Shared journal the responder appends 'response' to, to check the order of events.
     */
    public function __construct(
        /** @var \ArrayObject|null Shared journal of events. */
        private ?\ArrayObject $journal = null,
    ) {
    }

    /**
     * Record a response that keeps the connection open.
     *
     * @param int $status HTTP status code.
     * @param string[] $headers Header lines.
     * @param string $body Body.
     */
    public function send(int $status, array $headers, string $body): void {
        $this->record($status, $headers, $body, false);
    }

    /**
     * Record a response that closes the connection.
     *
     * @param int $status HTTP status code.
     * @param string[] $headers Header lines.
     * @param string $body Body.
     * @param bool $closesession Whether the session would be closed first.
     */
    public function send_and_close(int $status, array $headers, string $body, bool $closesession = false): void {
        $this->record($status, $headers, $body, true);
    }

    /**
     * The only response sent; fails the test when there is not exactly one.
     *
     * @return array
     */
    public function single(): array {
        if (count($this->responses) !== 1) {
            throw new \coding_exception('Expected one response, got ' . count($this->responses));
        }
        return $this->responses[0];
    }

    /**
     * Record one response.
     *
     * @param int $status HTTP status code.
     * @param string[] $headers Header lines.
     * @param string $body Body.
     * @param bool $closed Whether the connection was closed.
     */
    private function record(int $status, array $headers, string $body, bool $closed): void {
        $this->responses[] = ['status' => $status, 'headers' => $headers, 'body' => $body, 'closed' => $closed];
        if ($this->journal !== null) {
            $this->journal[] = 'response';
        }
    }
}
