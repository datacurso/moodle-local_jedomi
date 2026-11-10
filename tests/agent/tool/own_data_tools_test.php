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

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests for the read-only tools that give a student their own courses, grades and assignments.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\own_data_tool
 * @covers     \local_jedomi\local\service\student_data_service
 */
final class own_data_tools_test extends \advanced_testcase {
    /**
     * Two students of one course, a course of another user only, and an assignment graded for both students.
     *
     * @return array{student: \stdClass, classmate: \stdClass, course: \stdClass, foreign: \stdClass, item: \grade_item}
     */
    private function scenario(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Own Course Marker']);
        $foreign = $generator->create_course(['fullname' => 'Foreign Course Marker']);
        $student = $generator->create_user();
        $classmate = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->enrol_user($classmate->id, $course->id, 'student');
        $generator->enrol_user($classmate->id, $foreign->id, 'student');
        $assign = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Own assignment marker',
            'duedate' => time() + DAYSECS]);
        $generator->create_module('assign', ['course' => $foreign->id, 'name' => 'Foreign assignment marker']);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'courseid' => $course->id]);
        $item->update_final_grade($student->id, 81.37, 'test');
        $item->update_final_grade($classmate->id, 64.29, 'test');
        return ['student' => $student, 'classmate' => $classmate, 'course' => $course, 'foreign' => $foreign,
            'item' => $item];
    }

    /**
     * MDL-INT-067: each own data tool returns the data of the user it runs for and never that of another user.
     */
    public function test_tools_return_only_the_data_of_the_user(): void {
        $data = $this->scenario();
        $id = (int) $data['student']->id;

        $courses = (new my_courses_tool($id))->execute((object) []);
        $grades = (new my_grades_tool($id))->execute((object) []);
        $assignments = (new my_assignments_tool($id))->execute((object) []);

        $this->assertStringContainsString('Own Course Marker', $courses);
        $this->assertStringNotContainsString('Foreign Course Marker', $courses);
        $this->assertStringContainsString('81.37', $grades);
        $this->assertStringNotContainsString('64.29', $grades);
        $this->assertStringContainsString('Own assignment marker', $assignments);
        $this->assertStringNotContainsString('Foreign assignment marker', $assignments);
    }

    /**
     * MDL-INT-067: a user id or any undeclared argument is refused, and a course of another user is refused.
     */
    public function test_another_user_or_course_cannot_be_requested(): void {
        $data = $this->scenario();
        $id = (int) $data['student']->id;
        $other = (int) $data['classmate']->id;

        foreach ([new my_courses_tool($id), new my_grades_tool($id), new my_assignments_tool($id)] as $tool) {
            $result = $tool->execute((object) ['userid' => $other]);
            $this->assertArrayHasKey('error', json_decode($result, true), $tool->get_name());
            $this->assertStringNotContainsString('64.29', $result);
            $this->assertStringNotContainsString('Foreign', $result);
        }
        $grades = (new my_grades_tool($id))->execute((object) ['courseid' => (int) $data['foreign']->id]);
        $assignments = (new my_assignments_tool($id))->execute((object) ['courseid' => (int) $data['foreign']->id]);

        $this->assertArrayHasKey('error', json_decode($grades, true));
        $this->assertArrayHasKey('error', json_decode($assignments, true));
        $this->assertStringNotContainsString('Foreign assignment marker', $assignments);
    }

    /**
     * MDL-INT-067: hidden grades, grades of hidden grade items and assignment grades not released by the marking
     * workflow are not returned.
     */
    public function test_grades_the_student_cannot_see_are_excluded(): void {
        global $DB;
        $data = $this->scenario();
        $id = (int) $data['student']->id;
        $tool = new my_grades_tool($id);

        $data['item']->set_hidden(1);
        $hiddenitem = $tool->execute((object) []);
        $data['item']->set_hidden(0);
        $grade = \grade_grade::fetch(['itemid' => $data['item']->id, 'userid' => $id]);
        $grade->set_hidden(1);
        $hiddengrade = $tool->execute((object) []);
        $grade->set_hidden(0);
        $DB->set_field('assign', 'markingworkflow', 1, ['id' => $data['item']->iteminstance]);
        $unreleased = $tool->execute((object) []);
        $DB->set_field('assign', 'markingworkflow', 0, ['id' => $data['item']->iteminstance]);
        $visible = $tool->execute((object) []);

        $this->assertStringNotContainsString('81.37', $hiddenitem);
        $this->assertStringNotContainsString('81.37', $hiddengrade);
        $this->assertStringNotContainsString('81.37', $unreleased);
        $this->assertStringContainsString('81.37', $visible, 'Control: the grade is returned once visible');
    }

    /**
     * MDL-INT-067: without a real user (no linked user, guest) the tools read nothing.
     */
    public function test_without_a_user_nothing_is_read(): void {
        $this->scenario();

        foreach ([0, (int) guest_user()->id] as $userid) {
            $result = json_decode((new my_courses_tool($userid))->execute((object) []), true);
            $this->assertSame('permission_denied', $result['error'] ?? null);
        }
    }

    /**
     * MDL-INT-067: in the web chat the tools read the session user, and in the agents the agent creator, through
     * the same registry; a forged user id is refused in both.
     */
    public function test_channels_use_the_user_of_the_session_or_the_agent(): void {
        $data = $this->scenario();
        $this->setUser($data['student']);

        $web = \local_jedomi\proxy\handler::run_tool('my_grades', [], 'student');
        $webforged = \local_jedomi\proxy\handler::run_tool('my_grades', ['userid' => (int) $data['classmate']->id], 'student');
        \local_jedomi\agent\tool_executor::get_definitions(0, (int) $data['student']->id);
        $agent = \local_jedomi\agent\tool_executor::execute('my_grades', '{}');
        $agentforged = \local_jedomi\agent\tool_executor::execute('my_grades',
            json_encode(['userid' => (int) $data['classmate']->id]));

        $this->assertStringContainsString('81.37', $web);
        $this->assertStringContainsString('81.37', $agent);
        foreach ([$webforged, $agentforged] as $forged) {
            $this->assertArrayHasKey('error', json_decode($forged, true));
            $this->assertStringNotContainsString('64.29', $forged);
        }
    }
}
