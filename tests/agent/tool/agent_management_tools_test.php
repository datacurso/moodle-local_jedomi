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

use local_jedomi\agent\agent_manager;
use local_jedomi\agent\tool_executor;

/**
 * Tests for the agent management tools of the agents, which act with the permissions of the agent creator.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\create_agent_tool
 * @covers     \local_jedomi\agent\tool\delete_agent_tool
 * @covers     \local_jedomi\agent\tool_executor
 */
final class agent_management_tools_test extends \advanced_testcase {
    /**
     * MDL-INT-032: an agent of a manager cannot create agents on behalf of an administrator nor delete theirs, even when
     * the task runs as the administrator; it still manages the agents of its own creator.
     */
    public function test_agent_of_a_manager_cannot_act_on_agents_of_an_administrator(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int) get_admin()->id;
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $foreign = agent_manager::create_agent((object) ['userid' => $adminid, 'prompt' => 'Admin report',
            'schedule_cron' => '0 8 * * *']);
        tool_executor::get_definitions(1, (int) $manager->id);

        $created = json_decode(tool_executor::execute('create_agent', json_encode(['prompt' => 'Spy',
            'schedule_cron' => '0 8 * * *', 'userid' => $adminid])), true);
        $deleted = json_decode(tool_executor::execute('delete_agent', json_encode(['id' => $foreign])), true);
        $own = json_decode(tool_executor::execute('create_agent', json_encode(['prompt' => 'Mine',
            'schedule_cron' => '0 8 * * *'])), true);

        $this->assertSame('permission_denied', $created['error'] ?? null);
        $this->assertSame(1, $DB->count_records('local_jedomi_agents', ['userid' => $adminid]));
        $this->assertSame('permission_denied', $deleted['error'] ?? null);
        $this->assertNotNull(agent_manager::get_agent($foreign));
        $this->assertTrue($own['success'] ?? false);
        $this->assertSame(
            (int) $manager->id,
            (int) agent_manager::get_agent($own['agent_id'])->userid,
            'The agent of the manager creates agents owned by the manager'
        );
    }
}
