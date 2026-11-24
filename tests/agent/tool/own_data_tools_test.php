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
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for the read-only tools that give a student their own courses, grades and assignments.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
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
     * MDL-INT-104: each own data tool returns the data of the user it runs for and never that of another user.
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
     * SYS-E2E-015: the data of a classmate or of a foreign course is refused.
     * MDL-INT-104: asking for another user never gives their data.
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
     * SYS-E2E-015: hidden and not released grades are never shown to the student.
     * MDL-INT-104: hidden and not released grades are excluded.
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
     * SYS-E2E-015: in the web chat the student gets their own visible grades and asking for a classmate is refused.
     * MDL-INT-104: the tools always read the user of the channel, also when another user is asked for.
     * MDL-INT-067: in the web chat the tools read the session user, and in the agents the agent creator, through
     * the same registry; a forged user id is refused in both.
     */
    public function test_channels_use_the_user_of_the_session_or_the_agent(): void {
        $data = $this->scenario();
        $this->setUser($data['student']);

        $web = \local_jedomi\proxy\handler::run_tool('my_grades', [], 'student');
        $webforged = \local_jedomi\proxy\handler::run_tool('my_grades', ['userid' => (int) $data['classmate']->id], 'student');
        $executor = new \local_jedomi\agent\tool_executor(0, (int) $data['student']->id);
        $agent = $executor->execute('my_grades', '{}');
        $agentforged = $executor->execute(
            'my_grades',
            json_encode(['userid' => (int) $data['classmate']->id])
        );

        $this->assertStringContainsString('81.37', $web);
        $this->assertStringContainsString('81.37', $agent);
        foreach ([$webforged, $agentforged] as $forged) {
            $this->assertArrayHasKey('error', json_decode($forged, true));
            $this->assertStringNotContainsString('64.29', $forged);
        }
    }

    /**
     * MDL-INT-104: My courses gives only the visible courses with an active enrolment of the user.
     *
     * @return void
     */
    public function test_my_courses_lists_only_visible_courses_with_an_active_enrolment(): void {
        $data = $this->scenario();
        $generator = $this->getDataGenerator();
        $id = (int) $data['student']->id;
        $hidden = $generator->create_course(['fullname' => 'Hidden Course Marker', 'visible' => 0]);
        $suspended = $generator->create_course(['fullname' => 'Suspended Enrolment Marker']);
        $ended = $generator->create_course(['fullname' => 'Ended Enrolment Marker']);
        $generator->enrol_user($id, $hidden->id, 'student');
        $generator->enrol_user($id, $suspended->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $generator->enrol_user($id, $ended->id, 'student', 'manual', time() - 2 * DAYSECS, time() - DAYSECS);

        $courses = json_decode((new my_courses_tool($id))->execute((object) []), true);

        $names = array_column($courses, 'fullname');
        $this->assertSame(['Own Course Marker'], $names);
        $this->assertSame((int) $data['course']->id, $courses[0]['id']);
    }

    /**
     * MDL-INT-104: grades of anonymous assignments whose identities are not revealed and of activities the user cannot
     * see are excluded, and the totals are only given when no grade was excluded.
     *
     * @return void
     */
    public function test_anonymous_and_hidden_activity_grades_are_excluded_and_totals_follow(): void {
        global $DB;
        $data = $this->scenario();
        $id = (int) $data['student']->id;
        $courseid = (int) $data['course']->id;
        $tool = new my_grades_tool($id);
        grade_regrade_final_grades($courseid);
        $total = get_string('coursetotal', 'grades');

        $visible = $tool->execute((object) []);
        $this->assertStringContainsString('81.37', $visible);
        $this->assertStringContainsString($total, $visible, 'With nothing excluded the total is given');

        $DB->set_field('assign', 'blindmarking', 1, ['id' => $data['item']->iteminstance]);
        $anonymous = $tool->execute((object) []);
        $DB->set_field('assign', 'revealidentities', 1, ['id' => $data['item']->iteminstance]);
        $revealed = $tool->execute((object) []);
        $DB->set_field('assign', 'blindmarking', 0, ['id' => $data['item']->iteminstance]);

        $cm = get_coursemodule_from_instance('assign', $data['item']->iteminstance, $courseid);
        set_coursemodule_visible($cm->id, 0);
        $hiddenactivity = $tool->execute((object) []);

        $this->assertStringNotContainsString('81.37', $anonymous);
        $this->assertStringNotContainsString($total, $anonymous, 'A total never reveals an excluded grade');
        $this->assertStringContainsString('81.37', $revealed, 'Control: revealed identities show the grade');
        $this->assertStringNotContainsString('81.37', $hiddenactivity);
        $this->assertStringNotContainsString($total, $hiddenactivity);
    }

    /**
     * An assignment with opening, due and cut-off dates, in a course with three students: one with a user override and a
     * submission, one in a group with a group override, and one without overrides.
     *
     * @return array Keys student, grouped, plain, opens, due, cutoff, userdue, usercutoff, groupdue.
     */
    private function dated_assignment(): array {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Dates Course']);
        $data = [
            'student' => $generator->create_and_enrol($course, 'student'),
            'grouped' => $generator->create_and_enrol($course, 'student'),
            'plain' => $generator->create_and_enrol($course, 'student'),
            'opens' => time() + HOURSECS,
            'due' => time() + 3 * DAYSECS,
            'cutoff' => time() + 4 * DAYSECS,
            'userdue' => time() + 5 * DAYSECS,
            'usercutoff' => time() + 6 * DAYSECS,
            'groupdue' => time() + 7 * DAYSECS,
        ];
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $data['grouped']->id]);
        $assign = $generator->create_module('assign', ['course' => $course->id, 'name' => 'Dated assignment marker',
            'allowsubmissionsfromdate' => $data['opens'], 'duedate' => $data['due'], 'cutoffdate' => $data['cutoff']]);
        $DB->insert_record('assign_overrides', (object) ['assignid' => $assign->id, 'userid' => $data['student']->id,
            'duedate' => $data['userdue'], 'cutoffdate' => $data['usercutoff']]);
        $DB->insert_record('assign_overrides', (object) ['assignid' => $assign->id, 'groupid' => $group->id,
            'sortorder' => 1, 'duedate' => $data['groupdue']]);
        $DB->insert_record('assign_submission', (object) ['assignment' => $assign->id, 'userid' => $data['student']->id,
            'status' => 'submitted', 'latest' => 1, 'timecreated' => time(), 'timemodified' => time(), 'attemptnumber' => 0,
            'groupid' => 0]);
        return $data;
    }

    /**
     * The only task of a user, with the upcoming events of the calendar, as given by My tasks.
     *
     * @param \stdClass $user User.
     * @return array
     */
    private static function only_task(\stdClass $user): array {
        $result = json_decode((new my_assignments_tool((int) $user->id))->execute((object) []), true);
        return $result['assignments'][0] + ['upcoming' => $result['upcoming_events']];
    }

    /**
     * A date as My tasks gives it to a user.
     *
     * @param int $time Timestamp.
     * @param \stdClass $user User.
     * @return string
     */
    private static function user_date(int $time, \stdClass $user): string {
        return userdate($time, get_string('strftimedatetime', 'core_langconfig'), \core_date::get_user_timezone($user));
    }

    /**
     * MDL-INT-104: My tasks gives the opening, due and cut-off dates, the submission status and the upcoming deadlines of
     * the calendar.
     *
     * @return void
     */
    public function test_my_tasks_give_the_dates_the_submission_status_and_the_deadlines(): void {
        $data = $this->dated_assignment();

        $plain = self::only_task($data['plain']);
        $this->assertSame('Dated assignment marker', $plain['name']);
        $this->assertSame(self::user_date($data['opens'], $data['plain']), $plain['opens']);
        $this->assertSame(self::user_date($data['due'], $data['plain']), $plain['duedate']);
        $this->assertSame(self::user_date($data['cutoff'], $data['plain']), $plain['cutoffdate']);
        $this->assertSame('new', $plain['submission']);
        $this->assertStringContainsString(
            'Dated assignment marker',
            json_encode($plain['upcoming']),
            'The deadline of the next days comes from the calendar'
        );

        $this->assertSame('submitted', self::only_task($data['student'])['submission']);
    }

    /**
     * MDL-INT-115: My tasks applies the user and the group overrides of the dates; another student keeps the original
     * dates.
     *
     * @return void
     */
    public function test_my_tasks_apply_the_user_and_group_overrides(): void {
        $data = $this->dated_assignment();

        $own = self::only_task($data['student']);
        $grouped = self::only_task($data['grouped']);
        $plain = self::only_task($data['plain']);

        $this->assertSame(self::user_date($data['due'], $data['plain']), $plain['duedate'], 'Control: original date');
        $this->assertSame(self::user_date($data['userdue'], $data['student']), $own['duedate'], 'User override');
        $this->assertSame(self::user_date($data['usercutoff'], $data['student']), $own['cutoffdate'], 'User override');
        $this->assertSame(self::user_date($data['groupdue'], $data['grouped']), $grouped['duedate'], 'Group override');
    }

    /**
     * MDL-INT-104: a course that is not one of the courses of the user gets the message that it is not one of their
     * courses.
     *
     * @return void
     */
    public function test_a_foreign_course_gets_the_not_your_course_message(): void {
        $data = $this->scenario();
        $id = (int) $data['student']->id;

        foreach ([new my_grades_tool($id), new my_assignments_tool($id)] as $tool) {
            $result = json_decode($tool->execute((object) ['courseid' => (int) $data['foreign']->id]), true);
            $this->assertSame(get_string('error_not_own_course', 'local_jedomi'), $result['error'] ?? null, $tool->get_name());
        }
    }

    /**
     * MDL-INT-104: a student without courses, grades or tasks gets empty results, not an error.
     *
     * @return void
     */
    public function test_a_student_without_data_gets_empty_results(): void {
        $this->resetAfterTest();
        $id = (int) $this->getDataGenerator()->create_user()->id;

        $this->assertSame([], json_decode((new my_courses_tool($id))->execute((object) []), true));
        $this->assertSame([], json_decode((new my_grades_tool($id))->execute((object) []), true));
        $this->assertSame(
            ['assignments' => [], 'upcoming_events' => []],
            json_decode((new my_assignments_tool($id))->execute((object) []), true)
        );
    }

    /**
     * MDL-INT-113: My tasks shows the extended due date granted to a student, and other students keep the original
     * date, in the web chat and in Telegram (both use the same tool).
     *
     * @return void
     */
    public function test_my_tasks_apply_the_extensions_of_the_student(): void {
        global $DB;
        $data = $this->dated_assignment();
        $assignid = (int) $DB->get_field('assign', 'id', ['name' => 'Dated assignment marker']);
        $extended = time() + 9 * DAYSECS;
        $DB->insert_record('assign_user_flags', (object) ['assignment' => $assignid, 'userid' => $data['plain']->id,
            'locked' => 0, 'mailed' => 0, 'extensionduedate' => $extended, 'workflowstate' => '', 'allocatedmarker' => 0]);

        $withextension = self::only_task($data['plain']);
        $other = self::only_task($data['grouped']);

        $this->assertSame(self::user_date($extended, $data['plain']), $withextension['extensionduedate']);
        $this->assertSame(self::user_date($data['due'], $data['plain']), $withextension['duedate'], 'The due date is kept');
        $this->assertNull($other['extensionduedate'], 'Other students keep the original date');
    }
}
