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
 * @covers     \local_jedomi\agent\tool\list_agents_tool
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
        $executor = new tool_executor(1, (int) $manager->id);

        $created = json_decode($executor->execute('create_agent', json_encode(['prompt' => 'Spy',
            'schedule_cron' => '0 8 * * *', 'userid' => $adminid])), true);
        $deleted = json_decode($executor->execute('delete_agent', json_encode(['id' => $foreign])), true);
        $own = json_decode($executor->execute('create_agent', json_encode(['prompt' => 'Mine',
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

    /**
     * MDL-INT-099: the list of agents of a manager, in the web chat, Telegram and the agents, keeps the instruction of their
     * own agents and of the agents of a teacher, and omits the instruction of the agents of another manager and of an
     * administrator.
     */
    public function test_list_agents_of_a_manager_omits_the_hidden_instructions(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $managera = $generator->create_user();
        $managerb = $generator->create_user();
        $generator->role_assign('manager', $managera->id);
        $generator->role_assign('manager', $managerb->id);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $generator->create_course()->id, 'editingteacher');
        $owners = ['Own marker' => (int) $managera->id, 'Teacher marker' => (int) $teacher->id,
            'Other manager marker' => (int) $managerb->id, 'Admin marker' => (int) get_admin()->id];
        foreach ($owners as $prompt => $userid) {
            agent_manager::create_agent((object) ['userid' => $userid, 'prompt' => $prompt, 'schedule_cron' => '0 8 * * *']);
        }
        $this->setUser($managera);

        $outputs = [
            'web chat' => \local_jedomi\proxy\handler::run_tool('list_agents', [], 'manager'),
            'Telegram' => \local_jedomi\telegram\webhook_handler::execute_tool('list_agents', [], (int) $managera->id),
            'agents' => (new list_agents_tool((int) $managera->id))->execute((object) []),
        ];

        foreach ($outputs as $channel => $output) {
            $this->assertStringContainsString('Own marker', $output, "{$channel}: own instruction");
            $this->assertStringContainsString('Teacher marker', $output, "{$channel}: instruction of a lower rank");
            $this->assertStringNotContainsString('Other manager marker', $output, "{$channel}: same rank");
            $this->assertStringNotContainsString('Admin marker', $output, "{$channel}: higher rank");
        }
        foreach (['web chat', 'agents'] as $channel) {
            $agents = json_decode($outputs[$channel], true)['agents'];
            $this->assertCount(4, $agents, "{$channel}: every agent is still listed");
            $this->assertCount(2, array_filter(array_column($agents, 'prompt_hidden')), "{$channel}: two hidden");
        }
    }
}
