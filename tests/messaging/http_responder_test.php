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

namespace local_jedomi\messaging;

/**
 * Tests for the HTTP responder shared by the webhooks.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\http_responder
 */
final class http_responder_test extends \basic_testcase {
    /**
     * The headers of an early response declare the exact length, close the connection and forbid compression.
     *
     * @dataProvider body_provider
     * @param string $body Body.
     */
    public function test_closing_headers_declare_the_exact_length(string $body): void {
        $headers = http_responder::closing_headers('application/json', $body);

        $this->assertSame([
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
            'Connection: close',
            'Content-Encoding: identity',
        ], $headers);
    }

    /**
     * Bodies.
     *
     * @return array
     */
    public static function body_provider(): array {
        return ['empty' => [''], 'json' => ['{"ok":true}'], 'multibyte' => ['"ñandú"']];
    }
}
