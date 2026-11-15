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

namespace local_jedomi\agent;

/**
 * Tests for the dispatcher of the agent tools.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool_executor
 */
final class tool_executor_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        tool_executor::reset();
        parent::tearDown();
    }

    /**
     * JEDOMI-SEC-008: a tool that is not offered to the role of the agent creator is never run.
     */
    public function test_tool_not_offered_to_the_role_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id);
        tool_executor::get_definitions(0, (int) $manager->id);

        $result = json_decode(tool_executor::execute('get_site_config', '{"name":"dbpass"}'), true);

        $this->assertFalse(tool_executor::is_offered('get_site_config'));
        $this->assertSame('Unknown tool: get_site_config', $result['error']);
    }

    /**
     * JEDOMI-SEC-001: without tools prepared for a user nothing runs (no tool runs as user 0).
     */
    public function test_nothing_runs_before_the_tools_are_prepared(): void {
        $this->resetAfterTest();
        tool_executor::reset();

        $result = json_decode(tool_executor::execute('search_tables', '{"query":"user"}'), true);

        $this->assertSame('tools_not_prepared', $result['error']);
    }

    /**
     * JEDOMI-SEC-008: call_webservice is the same tool as the offered ws_execute, with the function in "function".
     */
    public function test_call_webservice_is_resolved_to_ws_execute(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['shortname' => 'EXEC-1']);
        set_config('ws_allowlist_admin', json_encode(['core_course_get_courses_by_field']), 'local_jedomi');
        tool_executor::get_definitions(0, (int) get_admin()->id);

        $result = json_decode(tool_executor::execute('call_webservice', json_encode([
            'function' => 'core_course_get_courses_by_field',
            'params' => ['field' => 'id', 'value' => (string) $course->id],
        ])), true);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertSame('EXEC-1', $result['data']['courses'][0]['shortname']);
    }

    /**
     * JEDOMI-SEC-001: without a web service list saved for the role, an agent runs no web service function, even for an
     * administrator.
     */
    public function test_no_saved_list_runs_no_web_service(): void {
        $this->resetAfterTest();
        unset_config('ws_allowlist_admin', 'local_jedomi');
        tool_executor::get_definitions(0, (int) get_admin()->id);

        $result = json_decode(tool_executor::execute('ws_execute', json_encode([
            'wsname' => 'core_webservice_get_site_info',
            'params' => '{}',
        ])), true);

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame(get_string('ws_agent_allowlist_required', 'local_jedomi'), $result['error']);
    }
}
