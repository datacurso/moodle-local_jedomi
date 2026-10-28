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
 * Test double for Moodle's \curl wrapper.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Returns queued responses instead of performing any network request, and records what it received.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_curl extends \curl {
    /** @var array<int, array{body: string, http_code: int, error: string, errno: int}> Queued responses. */
    public array $queue = [];

    /** @var array<int, array{url: string, params: mixed, options: array}> POST calls received. */
    public array $calls = [];

    /** @var array Options set through setopt(). */
    public array $setoptions = [];

    /**
     * Queue a response for the next request.
     *
     * @param string $body Response body.
     * @param int $httpcode HTTP status code reported through get_info().
     * @param string $error cURL error text (empty for none).
     * @param int $errno cURL error number (0 for none).
     */
    public function enqueue(string $body, int $httpcode = 200, string $error = '', int $errno = 0): void {
        $this->queue[] = ['body' => $body, 'http_code' => $httpcode, 'error' => $error, 'errno' => $errno];
    }

    #[\Override]
    public function setopt($options = []) {
        $this->setoptions = array_merge($this->setoptions, $options);
        parent::setopt($options);
    }

    #[\Override]
    public function post($url, $params = '', $options = []) {
        $this->calls[] = ['url' => (string) $url, 'params' => $params, 'options' => $options];
        $response = array_shift($this->queue) ?? ['body' => '', 'http_code' => 0, 'error' => '', 'errno' => 0];
        $this->info = ['http_code' => $response['http_code']];
        $this->error = $response['error'];
        $this->errno = $response['errno'];
        return $response['body'];
    }

    /**
     * Decoded JSON body of the last POST.
     *
     * @return array
     */
    public function last_body(): array {
        $last = end($this->calls);
        return $last ? (json_decode($last['params'], true) ?? []) : [];
    }
}
