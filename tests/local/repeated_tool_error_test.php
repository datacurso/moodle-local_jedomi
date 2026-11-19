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

namespace local_jedomi\local;

/**
 * Tests for the counter of repeated tool errors.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\repeated_tool_error
 */
final class repeated_tool_error_test extends \advanced_testcase {
    /**
     * REL-001: the same call with the same error stops the question at the third time, not before.
     */
    public function test_limit_is_three(): void {
        $errors = new repeated_tool_error();

        $this->assertSame(3, repeated_tool_error::LIMIT);
        $this->assertFalse($errors->record('t|k', '{"error":"no"}'));
        $this->assertFalse($errors->record('t|k', '{"error":"no"}'));
        $this->assertTrue($errors->record('t|k', '{"error":"no"}'));
    }

    /**
     * REL-001: a text result of a failed run is an error; a result that is not JSON and not a failed run is not.
     */
    public function test_non_json_error_result(): void {
        $this->assertSame('Error executing x: boom', repeated_tool_error::error_of('Error executing x: boom'));
        $this->assertNull(repeated_tool_error::error_of('Plain text result'));
        $this->assertNull(repeated_tool_error::error_of('{"rows":[]}'));

        $errors = new repeated_tool_error();
        $errors->record('t|k', 'Error executing x: boom');
        $errors->record('t|k', 'Error executing x: boom');
        $this->assertTrue($errors->record('t|k', 'Error executing x: boom'));
    }

    /**
     * REL-001: an error given as an array is compared by its JSON text.
     */
    public function test_array_error_field(): void {
        $result = json_encode(['error' => ['code' => 'denied', 'field' => 'id']]);

        $this->assertSame('{"code":"denied","field":"id"}', repeated_tool_error::error_of($result));
        $errors = new repeated_tool_error();
        $errors->record('t|k', $result);
        $errors->record('t|k', $result);
        $this->assertFalse($errors->record('t|k', json_encode(['error' => ['code' => 'other']])));
        $this->assertTrue($errors->record('t|k', $result));
    }

    /**
     * REL-001: a success between two failures does not reset the count, and is never counted.
     */
    public function test_success_between_failures(): void {
        $errors = new repeated_tool_error();

        $this->assertFalse($errors->record('t|k', '{"error":"no"}'));
        $this->assertFalse($errors->record('t|k', '{"rows":[1]}'));
        $this->assertFalse($errors->record('t|k', '{"error":"no"}'));
        $this->assertCount(1, $errors->export());
        $this->assertTrue($errors->record('t|k', '{"error":"no"}'));
    }

    /**
     * REL-001: saved counts are restored, and anything but a count is dropped.
     */
    public function test_counts_are_restored_from_the_state(): void {
        $errors = new repeated_tool_error();
        $errors->record('t|k', '{"error":"no"}');
        $errors->record('t|k', '{"error":"no"}');

        $restored = new repeated_tool_error($errors->export() + ['bad' => 'x']);

        $this->assertSame($errors->export(), $restored->export());
        $this->assertTrue($restored->record('t|k', '{"error":"no"}'));
    }

    /**
     * REL-002: the signature of the arguments ignores the order of the keys at every level, but not of list items.
     */
    public function test_args_signature_ignores_key_order(): void {
        $this->assertSame(
            repeated_tool_error::args_signature(['q' => ['a' => 1, 'b' => 2], 'z' => 'x']),
            repeated_tool_error::args_signature(['z' => 'x', 'q' => ['b' => 2, 'a' => 1]])
        );
        $this->assertNotSame(
            repeated_tool_error::args_signature(['ids' => [1, 2]]),
            repeated_tool_error::args_signature(['ids' => [2, 1]])
        );
    }
}
