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

namespace local_jedomi;

use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Greeting of the sidebar: it names the activity only where one is open.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('local_jedomi_greeting_key')]
final class greeting_test extends \advanced_testcase {
    /**
     * A student is greeted about the open activity, or about their courses on a course page or the dashboard; other
     * roles get the assistant greeting.
     */
    public function test_greeting_follows_role_and_page(): void {
        $this->resetAfterTest(true);
        $this->assertSame('hello_student', local_jedomi_greeting_key(['activity_id' => 12, 'course_id' => 3], 'student'));
        $this->assertSame('hello_student_general', local_jedomi_greeting_key(['activity_id' => 0, 'course_id' => 3], 'student'));
        $this->assertSame('hello_student_general', local_jedomi_greeting_key(['activity_id' => 0, 'course_id' => 0], 'student'));
        $this->assertSame('hello', local_jedomi_greeting_key(['activity_id' => 12, 'course_id' => 3], 'teacher'));
        $this->assertStringNotContainsString(
            get_string('hello_student', 'local_jedomi'),
            get_string('hello_student_general', 'local_jedomi')
        );
    }
}
