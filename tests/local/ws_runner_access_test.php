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

namespace local_jedomi\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the checks ws_runner applies before a web service function runs (JEDOMI-SEC-019) and for the functions the
 * web service search and description may show.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\ws_runner
 */
final class ws_runner_access_test extends \advanced_testcase {
    /**
     * Keep a course outside Jedomi, so only the restricted allowlist runs.
     *
     * @return void
     */
    private function exclude_a_course(): void {
        $course = $this->getDataGenerator()->create_course();
        set_config('course_mode', 'exclude', 'local_jedomi');
        set_config('course_list', (string) $course->id, 'local_jedomi');
    }

    /**
     * A teacher of a new course.
     *
     * @return \stdClass
     */
    private function teacher(): \stdClass {
        $gen = $this->getDataGenerator();
        return $gen->create_and_enrol($gen->create_course(), 'editingteacher');
    }

    /**
     * Error code of a call, or an empty string when it runs.
     *
     * @param string $wsname Web service function name.
     * @param array $args Arguments by name.
     * @return string
     */
    private function call_error(string $wsname, array $args): string {
        try {
            ws_runner::call(ws_runner::resolve($wsname), $args);
        } catch (\moodle_exception $e) {
            return (string) $e->errorcode;
        }
        return '';
    }

    /**
     * A user who holds none of the capabilities a function declares is refused before the function runs.
     */
    public function test_call_refuses_a_user_without_any_declared_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->teacher());

        $error = $this->call_error('core_user_create_users', ['users' => [[
            'username' => 'jedomiwsnew',
            'firstname' => 'New',
            'lastname' => 'User',
            'email' => 'jedomiwsnew@example.com',
            'password' => 'Passw0rd!',
        ]]]);

        $this->assertSame('error_ws_capability', $error);
        $this->assertFalse($DB->record_exists('user', ['username' => 'jedomiwsnew']), 'The function did not run');
    }

    /**
     * The declared capabilities are alternatives: one of them, held anywhere, is enough for the function to run.
     */
    public function test_call_runs_with_one_of_the_declared_capabilities(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        // A student holds moodle/calendar:manageownentries (authenticated user), not manageentries.
        $this->setUser($gen->create_and_enrol($gen->create_course(), 'student'));

        $result = ws_runner::call(ws_runner::resolve('core_calendar_get_calendar_events'), [
            'options' => ['userevents' => 1, 'siteevents' => 0, 'timestart' => time(), 'timeend' => time() + DAYSECS],
        ]);

        $this->assertArrayHasKey('events', $result);
    }

    /**
     * A function that requires login is refused without a logged-in user and for the guest.
     */
    public function test_call_requires_login(): void {
        $this->resetAfterTest();

        $this->setUser(null);
        $this->assertSame('servicerequireslogin', $this->call_error('core_webservice_get_site_info', []));

        $this->setGuestUser();
        $this->assertSame('servicerequireslogin', $this->call_error('core_webservice_get_site_info', []));

        $this->setAdminUser();
        $this->assertSame('', $this->call_error('core_webservice_get_site_info', []));
    }

    /**
     * A function Moodle cannot describe is refused (fail closed).
     */
    public function test_require_access_refuses_an_unknown_function(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        try {
            ws_runner::require_access('core_no_such_function');
            $this->fail('An unknown function is refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('ws_not_found', $e->errorcode);
        }
        try {
            ws_runner::require_access('');
            $this->fail('A method without a registered function is refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('ws_not_found', $e->errorcode);
        }
    }

    /**
     * The function runs with a fresh $PAGE and $COURSE, and the caller gets its own back.
     */
    public function test_call_restores_page_and_course(): void {
        global $PAGE, $COURSE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $PAGE;
        $courseid = (int) $COURSE->id;

        $result = ws_runner::call(ws_runner::resolve('core_course_get_contents'), ['courseid' => (int) $course->id]);

        $this->assertIsArray($result);
        $this->assertSame($page, $PAGE);
        $this->assertSame($courseid, (int) $COURSE->id);
    }

    /**
     * The declared capability check: alternatives, any context, unknown capabilities not held, nothing declared passes.
     */
    public function test_holds_declared_capability(): void {
        $this->resetAfterTest();
        $teacherid = (int) $this->teacher()->id;

        $this->assertTrue(ws_runner::holds_declared_capability('', $teacherid), 'Nothing declared');
        $this->assertTrue(ws_runner::holds_declared_capability('moodle/course:update', $teacherid), 'Held in a course');
        $this->assertFalse(ws_runner::holds_declared_capability('moodle/site:config', $teacherid));
        $this->assertTrue(ws_runner::holds_declared_capability('moodle/site:config, moodle/course:update', $teacherid));
        $this->assertFalse(ws_runner::holds_declared_capability('local/jedomi:nosuchcapability', $teacherid));
        $this->assertFalse(ws_runner::holds_declared_capability('moodle/course:update', 0), 'No user');
        $this->assertTrue(ws_runner::holds_declared_capability('moodle/site:config', (int) get_admin()->id));
    }

    /**
     * While some course is outside Jedomi the search lists only the 39 functions that may run, also for an
     * administrator without a saved list, and a role list keeps only those of them.
     */
    public function test_search_keeps_only_the_restricted_allowlist_while_courses_are_excluded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int) get_admin()->id;
        unset_config('ws_allowlist_admin', 'local_jedomi');
        set_config('ws_allowlist_manager', json_encode(['core_user_delete_users', 'core_course_get_contents']), 'local_jedomi');

        $this->assertNull(ws_runner::searchable_functions('admin'));
        $this->assertSame(
            ['core_user_delete_users', 'core_course_get_contents'],
            ws_runner::searchable_functions('manager', $adminid)
        );

        $this->exclude_a_course();
        $admin = ws_runner::searchable_functions('admin');
        $this->assertCount(39, $admin);
        $expected = array_keys(ws_runner::RESTRICTED_ALLOWLIST);
        sort($expected);
        sort($admin);
        $this->assertSame($expected, $admin);
        $this->assertSame(['core_course_get_contents'], ws_runner::searchable_functions('manager', $adminid));
    }

    /**
     * The search and the description leave out functions whose declared capabilities the user holds nowhere.
     */
    public function test_search_and_description_leave_out_functions_without_declared_capability(): void {
        $this->resetAfterTest();
        $teacherid = (int) $this->teacher()->id;
        set_config(
            'ws_allowlist_teacher',
            json_encode(['core_user_create_users', 'core_webservice_get_site_info']),
            'local_jedomi'
        );

        $this->assertSame(['core_webservice_get_site_info'], ws_runner::searchable_functions('teacher', $teacherid));
        $this->assertFalse(ws_runner::describable('teacher', 'core_user_create_users', $teacherid));
        $this->assertTrue(ws_runner::describable('teacher', 'core_webservice_get_site_info', $teacherid));
        $this->assertFalse(ws_runner::describable('teacher', 'core_course_get_courses', $teacherid), 'Not in the list');
    }

    /**
     * The description follows the course rule: while some course is outside Jedomi, only the allowlist is described.
     */
    public function test_description_follows_the_course_rule(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('ws_allowlist_admin', 'local_jedomi');

        $this->assertTrue(ws_runner::describable('admin', 'core_user_delete_users'));
        $this->assertTrue(ws_runner::describable('admin', 'core_course_get_contents'));

        $this->exclude_a_course();
        $this->assertFalse(ws_runner::describable('admin', 'core_user_delete_users'));
        $this->assertTrue(ws_runner::describable('admin', 'core_course_get_contents'));
    }
}
