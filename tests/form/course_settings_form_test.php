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

namespace local_jedomi\form;

/**
 * Tests for the form of the Course availability tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\form\course_settings_form
 */
final class course_settings_form_test extends \advanced_testcase {
    /**
     * A submitted form returns the mode and the selected courses.
     *
     * @return void
     */
    public function test_submitted_values_are_returned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        course_settings_form::mock_submit(['course_mode' => 'include', 'course_ids' => [$course1->id, $course2->id]]);

        $data = (new course_settings_form())->get_data();

        $this->assertSame('include', $data->course_mode);
        $this->assertEqualsCanonicalizing([$course1->id, $course2->id], array_map('intval', $data->course_ids));
    }

    /**
     * An unknown mode is rejected.
     *
     * @return void
     */
    public function test_unknown_mode_is_rejected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        course_settings_form::mock_submit(['course_mode' => 'bogus']);

        $this->assertNull((new course_settings_form())->get_data());
    }
}
