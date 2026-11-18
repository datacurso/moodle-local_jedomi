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

namespace local_jedomi\proxy;

/**
 * Tests for the size cap of the body of a web chat request (JEDOMI-SEC-006).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\proxy\request_body
 */
final class request_body_test extends \advanced_testcase {
    /**
     * Write a body of a given size to a temporary file, as the request stream would give it.
     *
     * @param int $bytes Size of the body.
     * @return string Path.
     */
    private function body_of(int $bytes): string {
        $path = make_request_directory() . '/body.json';
        file_put_contents($path, str_repeat('a', $bytes));
        return $path;
    }

    /**
     * The cap leaves room for the largest legitimate request: five attached files at the character cap in the worst UTF-8
     * case, plus the rest of the history.
     */
    public function test_cap_fits_the_largest_legitimate_attachments(): void {
        $onemessage = 5 * \local_jedomi\local\service\file_extraction_service::MAX_TEXT_CHARS * 3;
        $this->assertGreaterThan(2 * $onemessage, request_body::MAX_BYTES);
        $this->assertSame(8388608, request_body::MAX_BYTES);
    }

    /**
     * A body up to the cap is read whole.
     */
    public function test_body_up_to_the_cap_is_read(): void {
        $this->resetAfterTest();
        $path = $this->body_of(request_body::MAX_BYTES);

        $body = request_body::read($path, (string) request_body::MAX_BYTES);

        $this->assertSame(request_body::MAX_BYTES, strlen((string) $body));
        $this->assertSame('', request_body::read($this->body_of(0), null));
    }

    /**
     * A body above the cap is refused, also when the request does not declare its length (chunked).
     */
    public function test_body_above_the_cap_is_refused(): void {
        $this->resetAfterTest();
        $path = $this->body_of(request_body::MAX_BYTES + 1);

        $this->assertNull(request_body::read($path, null));
        $this->assertNull(request_body::read($path, ''));
    }

    /**
     * A declared length above the cap is refused before anything is read, whatever the body holds.
     */
    public function test_declared_length_above_the_cap_is_refused_before_reading(): void {
        $this->resetAfterTest();
        $path = $this->body_of(10);

        $this->assertNull(request_body::read($path, (string) (request_body::MAX_BYTES + 1)));
        $this->assertNull(request_body::read('/nonexistent/never-read', (string) (request_body::MAX_BYTES + 1)));
        $this->assertSame(str_repeat('a', 10), request_body::read($path, '10'));
    }
}
