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
    /**
     * JEDOMI-SEC-008: a tool that is not offered to the role of the agent creator is never run.
     */
    public function test_tool_not_offered_to_the_role_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id);
        $executor = new tool_executor(0, (int) $manager->id);

        $result = json_decode($executor->execute('get_site_config', '{"name":"dbpass"}'), true);

        $this->assertFalse($executor->is_offered('get_site_config'));
        $this->assertSame('Unknown tool: get_site_config', $result['error']);
    }

    /**
     * READ-003, JEDOMI-SEC-001: the tools offered belong to each executor; an executor prepared for an administrator does
     * not widen what the executor of another user runs, whatever the order they were built in.
     */
    public function test_offered_tools_are_kept_per_executor(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id);

        $formanager = new tool_executor(0, (int) $manager->id);
        $foradmin = new tool_executor(0, (int) get_admin()->id);

        $this->assertTrue($foradmin->is_offered('get_site_config'));
        $this->assertFalse($formanager->is_offered('get_site_config'), 'Building another executor changes nothing');
        $result = json_decode($formanager->execute('get_site_config', '{"name":"sitename"}'), true);
        $this->assertSame('Unknown tool: get_site_config', $result['error']);
        $this->assertFalse((new tool_executor(0, 0))->is_offered('get_site_config'), 'User 0 gets no administrator tool');
    }

    /**
     * READ-003: the executor keeps no static state: its tools and offered names are properties of each instance.
     */
    public function test_executor_has_no_static_state(): void {
        $properties = (new \ReflectionClass(tool_executor::class))->getProperties(\ReflectionProperty::IS_STATIC);

        $this->assertSame([], $properties);
    }

    /**
     * JEDOMI-SEC-008: call_webservice is the same tool as the offered ws_execute, with the function in "function".
     */
    public function test_call_webservice_is_resolved_to_ws_execute(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['shortname' => 'EXEC-1']);
        set_config('ws_allowlist_admin', json_encode(['core_course_get_courses_by_field']), 'local_jedomi');
        $executor = new tool_executor(0, (int) get_admin()->id);

        $result = json_decode($executor->execute('call_webservice', json_encode([
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
        $executor = new tool_executor(0, (int) get_admin()->id);

        $result = json_decode($executor->execute('ws_execute', json_encode([
            'wsname' => 'core_webservice_get_site_info',
            'params' => '{}',
        ])), true);

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame(get_string('ws_agent_allowlist_required', 'local_jedomi'), $result['error']);
    }
}
