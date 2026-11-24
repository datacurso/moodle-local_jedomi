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

/**
 * Tests for the call_webservice agent tool and the web service search and description of the agents (JEDOMI-SEC-019).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\ws_tool
 * @covers     \local_jedomi\agent\tool\ws_search_tool
 * @covers     \local_jedomi\agent\tool\ws_describe_tool
 * @covers     \local_jedomi\local\ws_runner
 */
final class ws_tool_test extends \advanced_testcase {
    use \local_jedomi\tests\other_plugins_debugging;

    /**
     * A teacher of a new course whose role list holds the given functions.
     *
     * @param string[] $functions Web service functions of the teacher list.
     * @return int Teacher user id.
     */
    private function teacher_with_list(array $functions): int {
        $gen = $this->getDataGenerator();
        $teacher = $gen->create_and_enrol($gen->create_course(), 'editingteacher');
        set_config('ws_allowlist_teacher', json_encode($functions), 'local_jedomi');
        return (int) $teacher->id;
    }

    /**
     * Run call_webservice for a user.
     *
     * @param int $userid Agent creator.
     * @param string $function Web service function name.
     * @param mixed $params Value of the "params" argument.
     * @return array Decoded result.
     */
    private function call(int $userid, string $function, $params = null): array {
        $args = ['function' => $function];
        if ($params !== null) {
            $args['params'] = $params;
        }
        return json_decode((new ws_tool($userid))->execute((object) $args), true);
    }

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
     * The tool definition names the tool and requires the function.
     */
    public function test_definition(): void {
        $tool = new ws_tool();

        $this->assertSame('call_webservice', $tool->get_name());
        $this->assertSame(['function'], $tool->get_definition()['function']['parameters']['required']);
    }

    /**
     * An empty name, an administrator without a saved list, a function outside the list and parameters that are not a
     * JSON object are refused before any call.
     */
    public function test_refusals_before_the_call(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;
        unset_config('ws_allowlist_admin', 'local_jedomi');
        $teacherid = $this->teacher_with_list(['core_webservice_get_site_info']);

        $this->assertSame('Empty function name', $this->call($teacherid, '  ')['error'] ?? null);
        $this->assertSame(
            get_string('ws_agent_allowlist_required', 'local_jedomi'),
            $this->call($adminid, 'core_webservice_get_site_info')['error'] ?? null
        );
        $this->assertSame('WS not in allowlist for role: teacher', $this->call($teacherid, 'core_user_get_users')['error'] ?? null);
        $this->assertSame(
            get_string('ws_invalid_parameters', 'local_jedomi', 'params must be a JSON object'),
            $this->call($teacherid, 'core_webservice_get_site_info', 'not json')['error'] ?? null
        );
    }

    /**
     * A listed function runs as the agent creator, and the session user is restored afterwards.
     */
    public function test_listed_function_runs_as_the_creator(): void {
        global $USER;
        $this->resetAfterTest();
        $teacherid = $this->teacher_with_list(['core_webservice_get_site_info']);
        $session = $this->getDataGenerator()->create_user();
        $this->setUser($session);

        $result = $this->call($teacherid, 'core_webservice_get_site_info', '{}');

        $this->assertTrue($result['success'] ?? false);
        $this->assertSame($teacherid, (int) $result['data']['userid']);
        $this->assertSame((int) $session->id, (int) $USER->id, 'The session user is restored');
    }

    /**
     * A listed function whose declared capabilities the creator holds nowhere is refused, and nothing is written.
     */
    public function test_listed_function_without_declared_capability_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $teacherid = $this->teacher_with_list(['core_user_create_users']);
        $params = json_encode(['users' => [[
            'username' => 'jedomiagentnew',
            'firstname' => 'New',
            'lastname' => 'User',
            'email' => 'jedomiagentnew@example.com',
            'password' => 'Passw0rd!',
        ]]]);

        $result = $this->call($teacherid, 'core_user_create_users', $params);

        $this->assertSame(get_string('error_ws_capability', 'local_jedomi', 'core_user_create_users'), $result['error'] ?? null);
        $this->assertFalse($DB->record_exists('user', ['username' => 'jedomiagentnew']));
    }

    /**
     * While some course is outside Jedomi, a listed function outside the restricted allowlist is refused.
     */
    public function test_restricted_mode_refuses_functions_outside_the_allowlist(): void {
        $this->resetAfterTest();
        set_config(
            'ws_allowlist_admin',
            json_encode(['core_role_assign_roles', 'core_webservice_get_site_info']),
            'local_jedomi'
        );
        $adminid = (int) get_admin()->id;
        $this->exclude_a_course();

        $refused = $this->call($adminid, 'core_role_assign_roles', json_encode(['assignments' => []]));
        $this->assertSame(get_string('error_ws_restricted', 'local_jedomi', 'core_role_assign_roles'), $refused['error'] ?? null);
        $this->assertTrue($this->call($adminid, 'core_webservice_get_site_info', '{}')['success'] ?? false);
    }

    /**
     * The search and the description of the agents show only what the creator may call: the list of the role and,
     * while some course is outside Jedomi, only the restricted allowlist.
     */
    public function test_search_and_description_follow_the_course_rule(): void {
        $this->resetAfterTest();
        set_config(
            'ws_allowlist_admin',
            json_encode(['core_user_delete_users', 'core_webservice_get_site_info']),
            'local_jedomi'
        );
        $adminid = (int) get_admin()->id;
        $search = new ws_search_tool($adminid);
        $describe = new ws_describe_tool($adminid);
        $denied = static fn(string $json): bool => str_contains($json, 'allowlist');

        $this->assertFalse($denied($describe->execute((object) ['wsname' => 'core_user_delete_users'])));

        $this->exclude_a_course();
        $this->assertTrue($denied($describe->execute((object) ['wsname' => 'core_user_delete_users'])));
        $this->assertFalse($denied($describe->execute((object) ['wsname' => 'core_webservice_get_site_info'])));
        $found = json_decode($search->execute((object) ['query' => 'delete users site info', 'limit' => 20]), true);
        $this->assertTrue($found['success']);
        foreach ($found['functions'] as $function) {
            $this->assertArrayHasKey($function['name'], \local_jedomi\local\ws_runner::RESTRICTED_ALLOWLIST);
        }
        $this->assertNotContains('core_user_delete_users', array_column($found['functions'], 'name'));
        $this->discard_debugging_of_other_plugins();
    }

    /**
     * The search and the description of the agents leave out listed functions whose declared capabilities the creator
     * holds nowhere.
     */
    public function test_search_and_description_leave_out_functions_without_declared_capability(): void {
        $this->resetAfterTest();
        $teacherid = $this->teacher_with_list(['core_user_create_users', 'core_webservice_get_site_info']);
        $search = new ws_search_tool($teacherid);
        $describe = new ws_describe_tool($teacherid);

        $this->assertStringContainsString(
            'allowlist',
            $describe->execute((object) ['wsname' => 'core_user_create_users'])
        );
        $found = json_decode($search->execute((object) ['query' => 'create users site info', 'limit' => 20]), true);
        $this->assertNotContains('core_user_create_users', array_column($found['functions'], 'name'));
        $this->discard_debugging_of_other_plugins();
    }
}
