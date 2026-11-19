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

namespace local_jedomi;

use local_jedomi\agent\agent_manager;
use local_jedomi\local\service\student_data_service;
use local_jedomi\local\sql_guard;
use local_jedomi\local\ws_runner;
use local_jedomi\task\agent_runner;
use local_jedomi\telegram\webhook_handler;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Decision 4: an excluded course is outside Jedomi, not only without the panel.
 *
 * In "exclude" mode the listed courses are excluded; in "include" (only the listed courses) mode every course that is
 * not listed is excluded. The role outside a course, the course lists of the permissions, the teacher SQL, the own data
 * tools, the web service calls and the agents ignore excluded courses.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\course_access
 * @covers     ::local_jedomi_user_role
 * @covers     ::local_jedomi_generate_spt_for_user
 * @covers     \local_jedomi\local\sql_guard
 * @covers     \local_jedomi\local\ws_runner
 * @covers     \local_jedomi\local\service\student_data_service
 * @covers     \local_jedomi\agent\agent_manager
 * @covers     \local_jedomi\task\agent_runner
 * @covers     \local_jedomi\telegram\webhook_handler
 */
final class course_exclusion_test extends \advanced_testcase {
    /**
     * Save the availability mode and the list of courses as the Courses tab does.
     *
     * @param string $mode Availability mode: all, include or exclude.
     * @param int[] $courseids Listed courses.
     * @return void
     */
    private function set_availability(string $mode, array $courseids): void {
        set_config('course_mode', $mode, 'local_jedomi');
        set_config('course_list', implode(',', $courseids), 'local_jedomi');
    }

    /**
     * A teacher of one course who is a student in another one.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Teacher, taught course, course where they study.
     */
    private function teacher_and_courses(): array {
        $gen = $this->getDataGenerator();
        $taught = $gen->create_course();
        $studied = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $taught->id, 'editingteacher');
        $gen->enrol_user($teacher->id, $studied->id, 'student');
        return [$teacher, $taught, $studied];
    }

    /**
     * The site is always in Jedomi; a course follows the mode and the list.
     */
    public function test_course_in_jedomi_follows_the_mode(): void {
        $this->resetAfterTest();
        $listed = (int) $this->getDataGenerator()->create_course()->id;
        $other = (int) $this->getDataGenerator()->create_course()->id;

        $this->set_availability('all', [$listed]);
        $this->assertTrue(course_access::is_course_in_jedomi($listed));
        $this->assertTrue(course_access::is_course_in_jedomi($other));

        $this->set_availability('exclude', [$listed]);
        $this->assertFalse(course_access::is_course_in_jedomi($listed));
        $this->assertTrue(course_access::is_course_in_jedomi($other));
        $this->assertTrue(course_access::is_course_in_jedomi(SITEID));
        $this->assertSame([$other], course_access::filter_course_ids([$listed, $other]));

        $this->set_availability('include', [$listed]);
        $this->assertTrue(course_access::is_course_in_jedomi($listed));
        $this->assertFalse(course_access::is_course_in_jedomi($other));
        $this->assertTrue(course_access::is_course_in_jedomi(0));
        $this->assertSame([$listed], course_access::filter_course_ids([$listed, $other]));
    }

    /**
     * The parsed course list follows a change of the saved list made during the same request (REL-004).
     */
    public function test_course_list_change_is_seen_in_the_same_request(): void {
        $this->resetAfterTest();
        $first = (int) $this->getDataGenerator()->create_course()->id;
        $second = (int) $this->getDataGenerator()->create_course()->id;

        $this->set_availability('exclude', [$first]);
        $this->assertFalse(course_access::is_course_in_jedomi($first));
        $this->set_availability('exclude', [$second]);

        $this->assertTrue(course_access::is_course_in_jedomi($first));
        $this->assertFalse(course_access::is_course_in_jedomi($second));
    }

    /**
     * The role outside a course ignores excluded courses, in exclude mode and in "only listed" mode; a teacher whose
     * taught courses are all excluded is a student on the front page, in Telegram and in the agents.
     */
    public function test_role_outside_a_course_ignores_excluded_courses(): void {
        $this->resetAfterTest();
        [$teacher, $taught, $studied] = $this->teacher_and_courses();
        $userid = (int) $teacher->id;

        $this->assertSame('teacher', local_jedomi_get_user_role_for_user($userid), 'Control: all courses in Jedomi');

        $this->set_availability('exclude', [(int) $taught->id]);
        $this->assertSame('student', local_jedomi_get_user_role_for_user($userid));
        $this->assertSame('student', local_jedomi_user_role($userid, SITEID), 'Front page');
        [$agentrole] = agent_runner::run_conditions($userid, 'site', null);
        $this->assertSame('student', $agentrole, 'Agents');

        $this->set_availability('include', [(int) $studied->id]);
        $this->assertSame('student', local_jedomi_get_user_role_for_user($userid), 'Only listed: the taught course is out');

        $this->set_availability('include', [(int) $taught->id]);
        $this->assertSame('teacher', local_jedomi_get_user_role_for_user($userid), 'Only listed: the taught course is in');
    }

    /**
     * A teacher with another taught course that stays in Jedomi keeps the teacher role.
     */
    public function test_teacher_with_a_course_left_in_jedomi_stays_teacher(): void {
        $this->resetAfterTest();
        [$teacher, $taught] = $this->teacher_and_courses();
        $kept = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $kept->id, 'editingteacher');

        $this->set_availability('exclude', [(int) $taught->id]);

        $this->assertSame('teacher', local_jedomi_get_user_role_for_user((int) $teacher->id));
    }

    /**
     * The course lists signed in the permissions of the web chat outside a course and of Telegram leave out the
     * excluded courses.
     */
    public function test_signed_course_lists_leave_out_excluded_courses(): void {
        $this->resetAfterTest();
        [$teacher, $taught] = $this->teacher_and_courses();
        $kept = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $kept->id, 'editingteacher');
        $this->set_availability('exclude', [(int) $taught->id]);

        $spt = local_jedomi_generate_spt_for_user((int) $teacher->id, 'teacher');

        $this->assertSame([(int) $kept->id], $spt['course_ids_owned']);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $taught->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $kept->id, 'student');
        $spt = local_jedomi_generate_spt_for_user((int) $student->id, 'student');
        $this->assertSame([(int) $kept->id], $spt['course_ids_enrolled']);
    }

    /**
     * Telegram computes the role, the course lists and the courses named to the AI without excluded courses.
     */
    public function test_telegram_context_leaves_out_excluded_courses(): void {
        $this->resetAfterTest();
        [$teacher, $taught, $studied] = $this->teacher_and_courses();
        $this->set_availability('exclude', [(int) $taught->id]);
        $method = new \ReflectionMethod(webhook_handler::class, 'get_user_full_context');

        $context = $method->invoke(null, (int) $teacher->id);

        $this->assertSame('student', $context['role']);
        $this->assertSame([], $context['course_ids_owned']);
        $this->assertSame([(int) $studied->id], $context['course_ids_enrolled']);
        $this->assertSame(['student' => [$studied->fullname]], $context['courses_by_role']);
    }

    /**
     * The teacher SQL never accepts an excluded course, also one the teacher manages; a course left in Jedomi is
     * accepted as before. Site-wide admin SQL is unchanged.
     */
    public function test_teacher_sql_refuses_excluded_courses(): void {
        $this->resetAfterTest();
        [$teacher, $taught] = $this->teacher_and_courses();
        $kept = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $kept->id, 'editingteacher');
        $this->set_availability('exclude', [(int) $taught->id]);
        $sql = 'SELECT fullname FROM {course} WHERE id = :cid';

        $inlist = "SELECT fullname FROM {course} WHERE id IN ({$kept->id}, {$taught->id})";

        $teacherid = (int) $teacher->id;
        $this->assertSame('sql_course_rejected', sql_guard::rejection_reason($sql, $teacherid, ['cid' => (int) $taught->id]));
        $this->assertSame('sql_course_rejected', sql_guard::rejection_reason($inlist, (int) $teacher->id));
        $this->assertNull(sql_guard::rejection_reason($sql, (int) $teacher->id, ['cid' => (int) $kept->id]));
        $admin = (int) get_admin()->id;
        $this->assertNull(sql_guard::rejection_reason($sql, $admin, ['cid' => (int) $taught->id]), 'Admin SQL is unchanged');
    }

    /**
     * The own data tools (my courses, grades and assignments) never list or accept an excluded course.
     */
    public function test_own_data_tools_leave_out_excluded_courses(): void {
        $this->resetAfterTest();
        [$teacher, $taught, $studied] = $this->teacher_and_courses();
        $this->set_availability('exclude', [(int) $taught->id]);

        $ids = array_column(student_data_service::my_courses((int) $teacher->id), 'id');

        $this->assertSame([(int) $studied->id], $ids);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_not_own_course', 'local_jedomi'));
        student_data_service::my_grades((int) $teacher->id, (int) $taught->id);
    }

    /**
     * A web service call that names an excluded course (courseid or courseids, at any depth) is refused in every
     * channel, before the function runs; a course in Jedomi runs as before.
     */
    public function test_web_service_call_naming_an_excluded_course_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $excluded = $this->getDataGenerator()->create_course();
        $kept = $this->getDataGenerator()->create_course();
        $this->set_availability('exclude', [(int) $excluded->id]);
        $method = ws_runner::resolve('core_course_get_contents');

        $this->assertIsArray(ws_runner::call($method, ['courseid' => (int) $kept->id]));
        try {
            ws_runner::call($method, ['courseid' => (int) $excluded->id]);
            $this->fail('A call on an excluded course must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_course_excluded', $e->errorcode);
        }
        $this->assertSame([(int) $excluded->id], ws_runner::excluded_courses_in([
            'options' => [['courseids' => [(int) $kept->id, (int) $excluded->id]]],
        ]));
    }

    /**
     * Error code of a web service call, or null when it runs.
     *
     * @param string $wsname Web service function name.
     * @param array $args Arguments by name.
     * @return string|null
     */
    private function call_error(string $wsname, array $args): ?string {
        try {
            ws_runner::call(ws_runner::resolve($wsname), $args);
            return null;
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
    }

    /**
     * Error the course gate gives to the arguments of a call, without running it: error_course_excluded when they name
     * an excluded course, error_argument_unplaced when one cannot be placed, null when they pass. Used for functions
     * outside the restricted allowlist, which the call itself refuses first.
     *
     * @param string $wsname Web service function name.
     * @param array $args Arguments by name.
     * @return string|null
     */
    private function gate_error(string $wsname, array $args): ?string {
        $args = ws_runner::validate(ws_runner::resolve($wsname), $args);
        if (!empty(ws_runner::excluded_courses_in($args, $wsname))) {
            return 'error_course_excluded';
        }
        return empty(ws_runner::unplaced_arguments($args, $wsname)) ? null : 'error_argument_unplaced';
    }

    /**
     * While some course is outside Jedomi, a function outside the allowlist is refused before it is validated or run,
     * whatever its arguments; when every course is in Jedomi it runs as before.
     */
    public function test_restricted_mode_refuses_functions_outside_the_allowlist(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $excluded = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $noteid = (int) $this->getDataGenerator()->get_plugin_generator('core_notes')->create_instance(
            ['courseid' => (int) $excluded->id, 'userid' => (int) $user->id]
        )->id;
        $this->set_availability('exclude', [(int) $excluded->id]);

        $refused = [
            'core_notes_delete_notes' => ['notes' => [$noteid]],
            'core_get_fragment' => ['component' => 'core', 'callback' => 'x', 'contextid' => 1, 'args' => []],
            'core_tag_get_tagindex' => ['tagindex' => ['tag' => 'x', 'tc' => 1]],
            'core_search_get_results' => ['query' => 'x'],
            'core_group_get_course_user_groups' => ['courseid' => 0, 'userid' => (int) $user->id],
        ];
        foreach ($refused as $wsname => $args) {
            try {
                ws_runner::call(ws_runner::resolve($wsname), $args);
                $this->fail("{$wsname} must be refused");
            } catch (\moodle_exception $e) {
                $this->assertSame('error_ws_restricted', $e->errorcode, $wsname);
                $this->assertStringContainsString($wsname, $e->getMessage(), $wsname);
            }
        }
        $this->assertTrue($DB->record_exists('post', ['id' => $noteid]), 'The refused function did not run');

        $this->set_availability('all', []);
        $this->assertSame([], ws_runner::call(ws_runner::resolve('core_notes_delete_notes'), ['notes' => [$noteid]]));
        $this->assertFalse($DB->record_exists('post', ['id' => $noteid]), 'Unrestricted: every function runs as before');
    }

    /**
     * Every function of the restricted allowlist is a web service function of this Moodle, and an allowlisted function
     * runs on a course in Jedomi and is refused on an excluded course.
     */
    public function test_restricted_allowlist_functions_exist_and_keep_the_course_gate(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertNotEmpty(ws_runner::RESTRICTED_ALLOWLIST);
        foreach (array_keys(ws_runner::RESTRICTED_ALLOWLIST) as $wsname) {
            $this->assertTrue($DB->record_exists('external_functions', ['name' => $wsname]), $wsname);
        }
        $excluded = $this->getDataGenerator()->create_course();
        $kept = $this->getDataGenerator()->create_course();
        $this->set_availability('exclude', [(int) $excluded->id]);

        $this->assertNull($this->call_error('core_course_get_contents', ['courseid' => (int) $kept->id]));
        $this->assertSame(
            'error_course_excluded',
            $this->call_error('core_course_get_contents', ['courseid' => (int) $excluded->id])
        );
    }

    /**
     * core_enrol_get_enrolled_users takes a group in its {name, value} options, which Moodle does not check against the
     * course: a group of an excluded course refuses the call.
     */
    public function test_enrolled_users_group_option_of_an_excluded_course_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $excluded = $gen->create_course();
        $kept = $gen->create_course();
        $excludedgroup = $gen->create_group(['courseid' => $excluded->id]);
        $keptgroup = $gen->create_group(['courseid' => $kept->id]);
        $this->set_availability('exclude', [(int) $excluded->id]);
        $args = static fn(int $groupid): array => ['courseid' => (int) $kept->id,
            'options' => [['name' => 'groupid', 'value' => (string) $groupid]]];

        $this->assertSame(
            'error_course_excluded',
            $this->call_error('core_enrol_get_enrolled_users', $args((int) $excludedgroup->id))
        );
        $this->assertNull($this->call_error('core_enrol_get_enrolled_users', $args((int) $keptgroup->id)));
    }

    /**
     * Calls that name a course through any argument: its id, an activity (course module, instance), a context, a group,
     * the course ids of core_course_* functions ("ids", "id" of courses) and a context given by level and instance.
     *
     * @param \stdClass $course Course.
     * @return array<string, array{0: string, 1: array}> Function name and arguments, by case.
     */
    private function calls_naming(\stdClass $course): array {
        $gen = $this->getDataGenerator();
        $forum = $gen->create_module('forum', ['course' => $course->id]);
        $assign = $gen->create_module('assign', ['course' => $course->id]);
        $group = $gen->create_group(['courseid' => $course->id]);
        $coursecontext = (int) \context_course::instance($course->id)->id;
        $modulecontext = (int) \context_module::instance($forum->cmid)->id;
        $files = ['component' => 'course', 'filearea' => 'summary', 'itemid' => 0, 'filepath' => '/', 'filename' => ''];
        return [
            'courseid' => ['core_course_get_contents', ['courseid' => (int) $course->id]],
            'cmid' => ['core_course_get_course_module', ['cmid' => (int) $forum->cmid]],
            'course contextid' => ['core_files_get_files', ['contextid' => $coursecontext] + $files],
            'module contextid' => ['core_files_get_files', ['contextid' => $modulecontext] + $files],
            'contextlevel and instanceid' => ['core_files_get_files',
                ['contextid' => -1, 'contextlevel' => 'course', 'instanceid' => (int) $course->id] + $files],
            'groupids' => ['core_group_get_groups', ['groupids' => [(int) $group->id]]],
            'forumid' => ['mod_forum_get_forum_discussions', ['forumid' => (int) $forum->id]],
            'assignmentid' => ['mod_assign_lock_submissions', ['assignmentid' => (int) $assign->id, 'userids' => []]],
            'assignmentids' => ['mod_assign_get_submissions', ['assignmentids' => [(int) $assign->id]]],
            'options ids' => ['core_course_get_courses', ['options' => ['ids' => [(int) $course->id]]]],
            'by field id' => ['core_course_get_courses_by_field', ['field' => 'id', 'value' => (string) $course->id]],
            'by field ids' => ['core_course_get_courses_by_field', ['field' => 'ids', 'value' => SITEID . ',' . $course->id]],
            'by field shortname' => ['core_course_get_courses_by_field', ['field' => 'shortname', 'value' => $course->shortname]],
            'courses id' => ['core_course_update_courses', ['courses' => [['id' => (int) $course->id, 'fullname' => 'Renamed']]]],
        ];
    }

    /**
     * Any argument that names an excluded course refuses the call, at any depth (RISK-001); the same call on a course in
     * Jedomi passes the gate.
     */
    public function test_web_service_call_naming_an_excluded_course_by_any_argument_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $excluded = $this->getDataGenerator()->create_course();
        $kept = $this->getDataGenerator()->create_course();
        $this->set_availability('exclude', [(int) $excluded->id]);

        foreach ($this->calls_naming($excluded) as $case => [$wsname, $args]) {
            $this->assertSame('error_course_excluded', $this->gate_error($wsname, $args), "Excluded: {$case}");
        }
        foreach ($this->calls_naming($kept) as $case => [$wsname, $args]) {
            $this->assertNotSame('error_course_excluded', $this->gate_error($wsname, $args), "In Jedomi: {$case}");
        }
    }

    /**
     * The course lists of web service results leave out excluded courses (RISK-002): the courses of the course-listing
     * functions (by id, with the total adjusted) and any listed record whose course is excluded.
     */
    public function test_web_service_results_leave_out_excluded_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $excluded = $gen->create_course(['fullname' => 'Jedomi listed excluded', 'shortname' => 'jedomiexcluded']);
        $kept = $gen->create_course(['fullname' => 'Jedomi listed kept', 'shortname' => 'jedomikept']);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $excluded->id, 'student');
        $gen->enrol_user($user->id, $kept->id, 'student');
        $this->set_availability('exclude', [(int) $excluded->id]);
        $call = static fn(string $wsname, array $args) => ws_runner::call(ws_runner::resolve($wsname), $args);

        $ids = array_map('intval', array_column($call('core_course_get_courses', []), 'id'));
        $this->assertContains((int) $kept->id, $ids);
        $this->assertNotContains((int) $excluded->id, $ids);

        $courses = $call('core_enrol_get_users_courses', ['userid' => (int) $user->id]);
        $this->assertSame([(int) $kept->id], array_map('intval', array_column($courses, 'id')));

        $search = $call('core_course_search_courses', ['criterianame' => 'search', 'criteriavalue' => 'Jedomi listed']);
        $this->assertSame([(int) $kept->id], array_map('intval', array_column($search['courses'], 'id')));
        $this->assertSame(1, (int) $search['total']);

        $this->set_availability('all', []);
        $courses = $call('core_enrol_get_users_courses', ['userid' => (int) $user->id]);
        $this->assertCount(2, $courses, 'Control: every course in Jedomi');
    }

    /**
     * A known kind of identifier that names nothing (a deleted activity) does not refuse the call: it names no excluded
     * course and the function reports it. A bare "id" of a function Jedomi does not know cannot be placed (FIX-001).
     */
    public function test_web_service_call_with_an_unknown_identifier(): void {
        $this->resetAfterTest();
        $excluded = $this->getDataGenerator()->create_course();
        $this->set_availability('exclude', [(int) $excluded->id]);

        $this->assertSame([], ws_runner::excluded_courses_in(['cmid' => 999999, 'forumid' => 999999, 'contextid' => 999999]));
        $this->assertSame([], ws_runner::unplaced_arguments(['cmid' => 999999, 'forumid' => 999999, 'contextid' => 999999]));
        $this->assertSame([], ws_runner::excluded_courses_in(['id' => (int) $excluded->id]), 'A bare id names no course');
        $this->assertSame(['id'], ws_runner::unplaced_arguments(['id' => (int) $excluded->id]), 'but cannot be placed');
        $this->assertSame([(int) $excluded->id], ws_runner::excluded_courses_in(
            ['options' => ['ids' => [(int) $excluded->id]]],
            'core_course_get_courses'
        ));
        $this->assertSame([], ws_runner::unplaced_arguments(['options' => ['ids' => [5]]], 'core_course_get_courses'));
    }

    /**
     * core_course_import_course copies one course into another: an excluded course on either side refuses the call
     * (FIX-001).
     */
    public function test_import_course_with_an_excluded_course_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $excluded = (int) $this->getDataGenerator()->create_course()->id;
        $kept = (int) $this->getDataGenerator()->create_course()->id;
        $other = (int) $this->getDataGenerator()->create_course()->id;
        $this->set_availability('exclude', [$excluded]);

        $this->assertSame(
            'error_course_excluded',
            $this->gate_error('core_course_import_course', ['importfrom' => $excluded, 'importto' => $kept])
        );
        $this->assertSame(
            'error_course_excluded',
            $this->gate_error('core_course_import_course', ['importfrom' => $kept, 'importto' => $excluded])
        );
        $args = ['importfrom' => $kept, 'importto' => $other];
        $this->assertSame([], ws_runner::excluded_courses_in($args, 'core_course_import_course'));
        $this->assertSame([], ws_runner::unplaced_arguments($args, 'core_course_import_course'));
    }

    /**
     * Calls that name a record of a course that param_targets does not read: a forum discussion or post, a section, a
     * calendar event, a quiz attempt, a workshop submission, a glossary or database entry, a group conversation, and an
     * activity given by module and instance.
     *
     * @param \stdClass $course Course.
     * @return array<string, array{0: string, 1: array}> Function name and arguments, by case.
     */
    private function calls_naming_records(\stdClass $course): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $user = $gen->create_and_enrol($course, 'student');
        $forum = $gen->create_module('forum', ['course' => $course->id]);
        $discussion = $gen->get_plugin_generator('mod_forum')->create_discussion(
            ['course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id]
        );
        $post = $gen->get_plugin_generator('mod_forum')->create_post(
            ['discussion' => $discussion->id, 'userid' => $user->id, 'parent' => $discussion->firstpost]
        );
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0], 'id', MUST_EXIST);
        $event = $gen->create_event(['courseid' => $course->id, 'eventtype' => 'course']);
        $quiz = $gen->create_module('quiz', ['course' => $course->id]);
        $attemptid = $DB->insert_record('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $user->id, 'attempt' => 1,
            'uniqueid' => 900000 + (int) $quiz->id, 'layout' => '', 'state' => 'inprogress']);
        $workshop = $gen->create_module('workshop', ['course' => $course->id]);
        $submissionid = $gen->get_plugin_generator('mod_workshop')->create_submission($workshop->id, $user->id);
        $glossary = $gen->create_module('glossary', ['course' => $course->id]);
        $entry = $gen->get_plugin_generator('mod_glossary')->create_content($glossary);
        $group = $gen->create_group(['courseid' => $course->id, 'enablemessaging' => 1]);
        $conversation = \core_message\api::get_conversation_by_area(
            'core_group',
            'groups',
            (int) $group->id,
            (int) \context_course::instance($course->id)->id
        );
        return [
            'discussionid' => ['mod_forum_get_discussion_posts',
                ['discussionid' => (int) $discussion->id, 'sortby' => 'created', 'sortdirection' => 'DESC']],
            'postid' => ['mod_forum_get_discussion_post', ['postid' => (int) $post->id]],
            'sectionid' => ['core_course_get_activity_chooser_footer',
                ['courseid' => SITEID, 'sectionid' => (int) $section->id]],
            'eventid' => ['core_calendar_get_calendar_event_by_id', ['eventid' => (int) $event->id]],
            'attemptid' => ['mod_quiz_get_attempt_summary', ['attemptid' => (int) $attemptid]],
            'submissionid' => ['mod_workshop_get_submission', ['submissionid' => (int) $submissionid]],
            'glossary entry id' => ['mod_glossary_get_entry_by_id', ['id' => (int) $entry->id]],
            'conversationid' => ['core_message_get_conversation',
                ['userid' => (int) $user->id, 'conversationid' => (int) $conversation->id, 'includecontactrequests' => false,
                    'includeprivacyinfo' => false]],
            'module and instance' => ['core_course_get_course_module_by_instance',
                ['module' => 'forum', 'instance' => (int) $forum->id]],
        ];
    }

    /**
     * A record of an excluded course named by an argument param_targets does not read refuses the call (FIX-001); the
     * same record of a course in Jedomi passes both gates.
     */
    public function test_web_service_call_naming_a_record_of_an_excluded_course_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $excluded = $this->getDataGenerator()->create_course();
        $kept = $this->getDataGenerator()->create_course();
        $excludedcalls = $this->calls_naming_records($excluded);
        $keptcalls = $this->calls_naming_records($kept);
        $this->set_availability('exclude', [(int) $excluded->id]);

        foreach ($excludedcalls as $case => [$wsname, $args]) {
            $this->assertSame('error_course_excluded', $this->gate_error($wsname, $args), "Excluded: {$case}");
        }
        foreach ($keptcalls as $case => [$wsname, $args]) {
            $this->assertNotContains(
                $this->gate_error($wsname, $args),
                ['error_course_excluded', 'error_argument_unplaced'],
                "In Jedomi: {$case}"
            );
        }
    }

    /**
     * An identifier Jedomi cannot place in a course refuses the call while some courses are outside Jedomi (fail closed,
     * FIX-001), and runs as before when every course is in Jedomi. Users, cohorts, categories, roles and the system
     * context are placed: they belong to no course.
     */
    public function test_web_service_call_with_an_unplaced_identifier_is_refused_only_when_restricted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $excluded = $gen->create_course();
        $user = $gen->create_user();
        $cohort = $gen->create_cohort();
        $category = $gen->create_category();
        $args = ['notificationid' => 999999, 'timeread' => 0];

        $this->set_availability('all', []);
        $this->assertNotSame('error_argument_unplaced', $this->call_error('core_message_mark_notification_read', $args));

        $this->set_availability('exclude', [(int) $excluded->id]);
        $this->assertSame('error_ws_restricted', $this->call_error('core_message_mark_notification_read', $args));
        $this->assertSame('error_argument_unplaced', $this->gate_error('core_message_mark_notification_read', $args));
        $this->assertSame(['notificationid'], ws_runner::unplaced_arguments($args, 'core_message_mark_notification_read'));
        $this->assertSame(['questionid', 'items.*.itemid', 'postid'], ws_runner::unplaced_arguments(
            ['questionid' => 3, 'items' => [['itemid' => 4]], 'postid' => 5],
            'core_unknown_function'
        ));

        $this->assertNull($this->call_error(
            'core_cohort_add_cohort_members',
            ['members' => [['cohorttype' => ['type' => 'id', 'value' => (string) $cohort->id],
            'usertype' => ['type' => 'id',
            'value' => (string) $user->id]]]]
        ));
        $this->assertNull($this->call_error(
            'core_course_get_categories',
            ['criteria' => [['key' => 'id', 'value' => (string) $category->id]]]
        ));
        $placed = ['userid' => 2, 'categoryid' => 3, 'cohortids' => [4], 'roleid' => 5, 'contextid' => 1,
            'assignments' => [['roleid' => 5, 'userid' => 2, 'contextlevel' => 'user', 'instanceid' => 2]],
            'notes' => [['clientnoteid' => 'x', 'userid' => 2]], 'files' => ['contextid' => 1, 'itemid' => 7],
            'plugindata' => ['onlinetext_editor' => ['text' => 'a', 'format' => 1, 'itemid' => 8]], 'draftitemid' => 9,
            'otheruserid' => 2, 'authorid' => 2];
        $this->assertSame([], ws_runner::unplaced_arguments($placed, 'core_unknown_function'));
    }

    /**
     * Nested course lists of web service results (enrolledcourses of users, courses lists keyed by id) leave out excluded
     * courses (FIX-002).
     */
    public function test_nested_course_lists_leave_out_excluded_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $excluded = $gen->create_course();
        $kept = $gen->create_course();
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $excluded->id, 'student');
        $gen->enrol_user($user->id, $kept->id, 'student');
        $this->set_availability('exclude', [(int) $excluded->id]);
        $call = static fn(string $wsname, array $args) => ws_runner::call(ws_runner::resolve($wsname), $args);

        $users = $call('core_enrol_get_enrolled_users', ['courseid' => (int) $kept->id]);
        $this->assertCount(1, $users);
        $this->assertSame([(int) $kept->id], array_map('intval', array_column($users[0]['enrolledcourses'], 'id')));

        $users = $call(
            'core_user_get_course_user_profiles',
            ['userlist' => [['userid' => (int) $user->id, 'courseid' => (int) $kept->id]]]
        );
        $this->assertSame([(int) $kept->id], array_map('intval', array_column($users[0]['enrolledcourses'], 'id')));

        $this->set_availability('all', []);
        $users = $call('core_enrol_get_enrolled_users', ['courseid' => (int) $kept->id]);
        $this->assertCount(2, $users[0]['enrolledcourses'], 'Control: every course in Jedomi');
    }

    /**
     * The chat endpoints (chat proxy, conversation, extract_file and read_file) share one server-side gate that refuses
     * permissions signed for an excluded course, in both modes.
     */
    public function test_chat_gate_refuses_a_signed_excluded_course(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $excluded = $gen->create_course();
        $student = $gen->create_and_enrol($excluded, 'student');
        $this->setUser($student);

        $this->set_availability('exclude', [(int) $excluded->id]);
        $this->assertSame('course_unavailable', local_jedomi_signed_course_error((int) $excluded->id, null)['code']);
        $this->assertSame('course_unavailable', local_jedomi_signed_course_error((int) $excluded->id, (int) $excluded->id)['code']);

        $this->set_availability('include', [(int) $gen->create_course()->id]);
        $this->assertSame('course_unavailable', local_jedomi_signed_course_error((int) $excluded->id, null)['code']);

        $this->set_availability('all', []);
        $this->assertNull(local_jedomi_signed_course_error((int) $excluded->id, null));
    }

    /**
     * An agent cannot be saved for a context of an excluded course (a course, or an activity or block of it).
     */
    public function test_agent_cannot_be_saved_in_an_excluded_course(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $excluded = $gen->create_course();
        $kept = $gen->create_course();
        $this->set_availability('exclude', [(int) $excluded->id]);
        $agent = (object) [
            'prompt' => 'Summary',
            'schedule_cron' => '0 8 * * *',
            'context_type' => 'course',
            'userid' => (int) get_admin()->id,
        ];

        $agent->context_id = \context_course::instance($excluded->id)->id;
        $this->assertSame(get_string('agent_invalid_context_excluded', 'local_jedomi'), agent_manager::validation_error($agent));

        $agent->context_id = \context_course::instance($kept->id)->id;
        $this->assertNull(agent_manager::validation_error($agent));

        $contextid = (int) \context_course::instance($excluded->id)->id;
        $preview = \local_jedomi\agent\preview_runner::run('Summary', (int) get_admin()->id, 'course', $contextid);
        $this->assertFalse($preview['ok'], 'The preview of an agent in an excluded course is refused');
        $this->assertSame('course_excluded', $preview['error_code']);
    }

    /**
     * The labels of the modes name the selected courses as outside Jedomi, not only without the panel (READ-001).
     */
    public function test_mode_labels_say_the_courses_are_outside_jedomi(): void {
        foreach (['exclude', 'include'] as $mode) {
            $description = get_string('course_mode_' . $mode . '_desc', 'local_jedomi');
            $this->assertStringContainsString('outside Jedomi', $description, $mode);
            $this->assertStringContainsString('agents', $description, $mode);
        }
        $this->assertSame('All courses except the selected ones', get_string('course_mode_exclude', 'local_jedomi'));
        $this->assertSame('Only the selected courses', get_string('course_mode_include', 'local_jedomi'));
    }

    /**
     * The course an agent context belongs to: a course context and the contexts inside it; none for the rest.
     */
    public function test_agent_context_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->assertSame((int) $course->id, course_access::context_course_id(\context_course::instance($course->id)->id));
        $this->assertSame((int) $course->id, course_access::context_course_id(\context_module::instance($page->cmid)->id));
        $this->assertSame(0, course_access::context_course_id(\context_system::instance()->id));
        $this->assertSame(0, course_access::context_course_id(0));
    }

    /**
     * The admin commands that name a course (backup, create activity) refuse a course outside Jedomi in both modes, from
     * every channel (they all reach cli_registry::execute), and run on a course in Jedomi (RISK-001).
     */
    public function test_admin_commands_refuse_an_excluded_course(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int) get_admin()->id;
        $gen = $this->getDataGenerator();
        $excluded = $gen->create_course();
        $kept = $gen->create_course();
        $keptpage = $gen->create_module('page', ['course' => $kept->id]);
        $excludedpage = $gen->create_module('page', ['course' => $excluded->id]);
        $createmod = static fn(int $courseid, ?int $beforemod = null): array => \local_jedomi\schema\cli_registry::execute(
            'create_mod',
            ['courseid' => $courseid, 'resource_type' => 'label', 'section' => 0,
                'parameters' => ['name' => 'Jedomi label', 'intro' => '<p>Jedomi label</p>']]
                + ($beforemod === null ? [] : ['beforemod' => $beforemod]),
            $adminid
        );

        foreach (['exclude' => [(int) $excluded->id], 'include' => [(int) $kept->id]] as $mode => $list) {
            $this->set_availability($mode, $list);
            $backup = \local_jedomi\schema\cli_registry::execute('backup', ['courseid' => (int) $excluded->id], $adminid);
            $this->assertSame('error_course_excluded', $backup['error'] ?? null, "backup, {$mode} mode");
            $this->assertSame('error_course_excluded', $createmod((int) $excluded->id)['error'] ?? null, "create_mod, {$mode}");
            $before = $createmod((int) $kept->id, (int) $excludedpage->cmid);
            $this->assertSame('error_course_excluded', $before['error'] ?? null, "create_mod before an excluded activity, {$mode}");
            $this->assertArrayHasKey('success', \local_jedomi\schema\cli_registry::execute('purge_caches', [], $adminid));
        }

        $backup = \local_jedomi\schema\cli_registry::execute('backup', ['courseid' => (int) $kept->id], $adminid);
        $this->assertTrue($backup['success'] ?? false, 'A course in Jedomi is backed up: ' . json_encode($backup));

        if (!file_exists($CFG->dirroot . '/local/coursegen/classes/mod_manager.php')) {
            $this->markTestSkipped('local_coursegen is not installed; create_mod cannot run on a course in Jedomi.');
        }
        $created = $createmod((int) $kept->id, (int) $keptpage->cmid);
        $this->assertTrue($created['success'] ?? false, 'An activity is created in a course in Jedomi: ' . json_encode($created));
    }

    /**
     * A user override event of an activity (courseid 0, with modulename and instance) belongs to the course of the
     * activity: the calendar events of an excluded course are left out, those of a course in Jedomi are kept (RISK-002).
     */
    public function test_calendar_override_events_of_an_excluded_course_are_left_out(): void {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $excluded = $gen->create_course();
        $kept = $gen->create_course();
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $excluded->id, 'student');
        $gen->enrol_user($user->id, $kept->id, 'student');
        $quiz = $gen->create_module('quiz', ['course' => $excluded->id]);
        $assign = $gen->create_module('assign', ['course' => $kept->id]);
        $start = time() + DAYSECS;
        $events = [];
        foreach (['quiz' => $quiz, 'assign' => $assign] as $modname => $instance) {
            $this->setAdminUser();
            $events[$modname] = (int) \calendar_event::create((object) [
                'name' => "Override {$modname}", 'description' => '', 'format' => FORMAT_HTML,
                'courseid' => 0, 'groupid' => 0, 'userid' => (int) $user->id, 'categoryid' => 0,
                'modulename' => $modname, 'instance' => (int) $instance->id, 'priority' => 0,
                'eventtype' => $modname === 'quiz' ? 'close' : 'due', 'timestart' => $start, 'timeduration' => 0,
                'visible' => 1,
            ], false)->id;
        }
        $this->setUser($user);
        $call = static fn(): array => array_map('intval', array_column(ws_runner::call(
            ws_runner::resolve('core_calendar_get_calendar_events'),
            ['options' => ['userevents' => 1, 'siteevents' => 0, 'timestart' => time(), 'timeend' => $start + DAYSECS]]
        )['events'], 'id'));

        $this->set_availability('exclude', [(int) $excluded->id]);
        $ids = $call();
        $this->assertNotContains($events['quiz'], $ids, 'Override event of an excluded course left out');
        $this->assertContains($events['assign'], $ids, 'Override event of a course in Jedomi kept');

        // Moodle fills "courseid" of these events from the activity today; a record that keeps courseid 0 with the
        // activity is still placed by "modulename" and "instance".
        $filter = new \ReflectionMethod(ws_runner::class, 'without_excluded_records');
        $filter->setAccessible(true);
        $records = ['events' => [
            ['id' => 1, 'courseid' => 0, 'modulename' => 'quiz', 'instance' => (int) $quiz->id],
            ['id' => 2, 'courseid' => 0, 'modulename' => 'assign', 'instance' => (int) $assign->id],
            ['id' => 3, 'courseid' => 0, 'modulename' => 'notamodule', 'instance' => (int) $quiz->id],
            ['id' => 4, 'courseid' => 0, 'modulename' => '', 'instance' => 0],
        ]];
        $this->assertSame([2, 3, 4], array_column($filter->invoke(null, $records)['events'], 'id'));

        $this->set_availability('all', []);
        $this->assertContains($events['quiz'], $call(), 'Control: every course in Jedomi');
    }
}
