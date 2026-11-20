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

use local_jedomi\agent\tool_executor;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the admin_execute agent tool: role gate of the tool and gate of the CLI registry (JEDOMI-SEC-019).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\admin_execute_tool
 * @covers     \local_jedomi\schema\cli_registry
 */
final class admin_execute_tool_test extends \advanced_testcase {
    /**
     * Run the tool for a user.
     *
     * @param int $userid User the tool runs for.
     * @param string $name Operation name.
     * @param array $params Operation parameters.
     * @return array Decoded result.
     */
    private function run_tool(int $userid, string $name, array $params = []): array {
        $tool = new admin_execute_tool($userid);
        return json_decode($tool->execute((object) ['name' => $name, 'params' => (object) $params]), true);
    }

    /**
     * The tool definition names the tool and requires the operation name.
     */
    public function test_definition(): void {
        $tool = new admin_execute_tool(0);
        $definition = $tool->get_definition();

        $this->assertSame('admin_execute', $tool->get_name());
        $this->assertSame('admin_execute', $definition['function']['name']);
        $this->assertSame(['name'], $definition['function']['parameters']['required']);
    }

    /**
     * An administrator runs an operation; an unknown operation is reported.
     */
    public function test_administrator_runs_an_operation(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;

        $this->assertTrue($this->run_tool($adminid, 'purge_caches')['success'] ?? false);
        $this->assertSame('unknown operation: nosuchoperation', $this->run_tool($adminid, 'nosuchoperation')['error'] ?? null);
    }

    /**
     * A teacher, a student and no user are refused by the role gate of the tool.
     */
    public function test_teacher_student_and_no_user_are_refused(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $student = $gen->create_and_enrol($course, 'student');

        foreach ([(int) $teacher->id, (int) $student->id, 0] as $userid) {
            $this->assertSame(['error' => 'permission_denied'], $this->run_tool($userid, 'purge_caches'));
        }
    }

    /**
     * A manager passes the role gate of the tool but is refused by the CLI registry, which requires a site administrator
     * or moodle/site:config; with that capability the same call runs.
     */
    public function test_manager_is_refused_by_the_cli_registry(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $manager = $gen->create_user();
        $gen->role_assign('manager', $manager->id);
        $this->assertSame('manager', \local_jedomi_get_user_role_for_user((int) $manager->id));

        $this->assertSame(['error' => 'permission_denied'], $this->run_tool((int) $manager->id, 'purge_caches'));
        $this->assertFalse(\local_jedomi\schema\cli_registry::can_execute((int) $manager->id));

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        assign_capability('moodle/site:config', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($this->run_tool((int) $manager->id, 'purge_caches')['success'] ?? false);
    }

    /**
     * An agent of a manager calling admin_execute is refused: the tool is not offered to a user the CLI registry
     * refuses.
     */
    public function test_agent_of_a_manager_is_refused(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $manager = $gen->create_user();
        $gen->role_assign('manager', $manager->id);
        $executor = new tool_executor(1, (int) $manager->id);

        $result = json_decode($executor->execute('admin_execute', json_encode(['name' => 'purge_caches'])), true);

        $this->assertSame('Unknown tool: admin_execute', $result['error'] ?? null);
        $this->assertArrayNotHasKey('success', $result);
    }

    /**
     * An agent of an administrator runs admin_execute through the executor.
     */
    public function test_agent_of_an_administrator_runs_an_operation(): void {
        $this->resetAfterTest();
        $executor = new tool_executor(1, (int) get_admin()->id);

        $result = json_decode($executor->execute('admin_execute', json_encode(['name' => 'purge_caches'])), true);

        $this->assertTrue($result['success'] ?? false);
    }
}
