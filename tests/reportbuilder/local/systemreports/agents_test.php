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

namespace local_jedomi\reportbuilder\local\systemreports;

use core_reportbuilder\external\systemreports\retrieve;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\user_filter_manager;
use core_reportbuilder\system_report_factory;
use local_jedomi\agent\agent_manager;
use local_jedomi\reportbuilder\local\helpers\filtered_ids;

/**
 * Tests for the agents system report of the Agents tab.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\reportbuilder\local\systemreports\agents
 * @covers     \local_jedomi\reportbuilder\local\entities\agent
 * @covers     \local_jedomi\reportbuilder\local\filters\agent_role
 * @covers     \local_jedomi\reportbuilder\local\helpers\filtered_ids
 */
final class agents_test extends \advanced_testcase {
    /**
     * Create an agent.
     *
     * @param int $userid Owner.
     * @param array $fields Other fields.
     * @return int Agent id.
     */
    private function agent(int $userid, array $fields = []): int {
        return agent_manager::create_agent((object) array_merge([
            'userid' => $userid, 'prompt' => 'Weekly report', 'schedule_cron' => '0 8 * * *', 'enabled' => 1,
        ], $fields));
    }

    /**
     * The report for the current user, with the given filter values saved for them.
     *
     * @param array $filters Filter values.
     * @return agents
     */
    private function report(array $filters = []): agents {
        $report = system_report_factory::create(agents::class, \context_system::instance());
        user_filter_manager::set($report->get_report_persistent()->get('id'), $filters);
        return $report;
    }

    /**
     * Rows of the report for the current user, as the table shows them, without tags.
     *
     * @param array $filters Filter values.
     * @return array[] Each row: creator, name, schedule, status and last run.
     */
    private function rows(array $filters = []): array {
        $this->report($filters);
        $result = retrieve::execute(agents::class, ['contextid' => \context_system::instance()->id], '', '', 0, [], 0, 100);
        return array_map(
            fn(array $row): array => array_map(fn($value): string => trim(strip_tags((string) $value)), $row['columns']),
            $result['data']['rows']
        );
    }

    /**
     * A user with the manager role of the site.
     *
     * @param string $firstname First name.
     * @return \stdClass
     */
    private function manager(string $firstname): \stdClass {
        $user = $this->getDataGenerator()->create_user(['firstname' => $firstname, 'lastname' => 'Manager']);
        $this->getDataGenerator()->role_assign('manager', $user->id);
        return $user;
    }

    /**
     * MDL-INT-022: the table shows the creator, the name, the readable schedule, the status with the failures and the
     * last run of each agent.
     */
    public function test_columns_show_creator_name_schedule_status_and_last_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Pérez']);
        $id = $this->agent((int) $creator->id, ['name' => 'Daily digest', 'schedule_cron' => '0 8 * * *']);
        $DB->update_record('local_jedomi_agents', (object) ['id' => $id, 'failcount' => 2, 'lastrun' => 1700000000]);
        $this->agent((int) $creator->id, ['name' => 'Off agent', 'enabled' => 0]);

        $rows = $this->rows();

        $this->assertCount(2, $rows);
        $this->assertSame(['Daily digest', 'Off agent'], array_column($rows, 1), 'Sorted by name');
        $this->assertSame(fullname($creator), $rows[0][0]);
        $this->assertSame('Every day at 08:00', $rows[0][2]);
        $this->assertStringContainsString(get_string('agent_enabled', 'local_jedomi'), $rows[0][3]);
        $this->assertStringContainsString(get_string('agent_failing', 'local_jedomi'), $rows[0][3]);
        $this->assertStringContainsString(get_string('agent_disabled', 'local_jedomi'), $rows[1][3]);
        $this->assertNotSame('-', $rows[0][4]);
        $this->assertSame('-', $rows[1][4], 'An agent that never ran');
    }

    /**
     * MDL-INT-022: the role, status and context type filters restrict the rows.
     */
    public function test_filters_by_role_status_and_context_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $owner = (int) get_admin()->id;
        $this->agent($owner, ['name' => 'Teachers on', 'role_filter' => json_encode(['editingteacher'])]);
        $this->agent($owner, ['name' => 'Teachers off', 'enabled' => 0, 'role_filter' => json_encode(['editingteacher'])]);
        $this->agent($owner, ['name' => 'Students on', 'role_filter' => json_encode(['student']),
            'context_type' => 'user', 'context_id' => \context_user::instance($owner)->id]);

        $teachers = $this->rows([
            'agent:role_operator' => select::EQUAL_TO, 'agent:role_value' => 'editingteacher',
            'agent:enabled_operator' => select::EQUAL_TO, 'agent:enabled_value' => 1,
        ]);
        $disabled = $this->rows(['agent:enabled_operator' => select::EQUAL_TO, 'agent:enabled_value' => 0]);
        $usercontext = $this->rows(['agent:contexttype_operator' => select::EQUAL_TO, 'agent:contexttype_value' => 'user']);
        $notteachers = $this->rows(['agent:role_operator' => select::NOT_EQUAL_TO, 'agent:role_value' => 'editingteacher']);

        $this->assertSame(['Teachers on'], array_column($teachers, 1));
        $this->assertSame(['Teachers off'], array_column($disabled, 1));
        $this->assertSame(['Students on'], array_column($usercontext, 1));
        $this->assertSame(['Students on'], array_column($notteachers, 1));
    }

    /**
     * MDL-INT-099: searching by instruction only matches the agents whose instruction the viewer may read, and a name
     * made of the first words of a hidden instruction is hidden.
     */
    public function test_prompt_search_only_matches_instructions_the_viewer_may_read(): void {
        $this->resetAfterTest();
        $manager = $this->manager('Mia');
        $adminid = (int) get_admin()->id;
        $this->agent($adminid, ['name' => 'Admin agent', 'prompt' => 'Shared marker of the admin']);
        $this->agent((int) $manager->id, ['name' => 'Manager agent', 'prompt' => 'Shared marker of mine']);
        $this->agent($adminid, ['prompt' => 'Secret words of the admin']);
        $search = ['agent:prompt_operator' => text::CONTAINS, 'agent:prompt_value' => 'Shared marker'];

        $this->setUser($manager);
        $asmanager = $this->rows($search);
        $all = $this->rows();
        $this->setAdminUser();
        $asadmin = $this->rows($search);

        $this->assertSame(['Manager agent'], array_column($asmanager, 1));
        $this->assertSame(['Admin agent', 'Manager agent'], array_column($asadmin, 1));
        $this->assertNotContains(agent_manager::generate_name_from_prompt('Secret words of the admin'), array_column($all, 1));
        $this->assertContains(get_string('agent_unnamed', 'local_jedomi'), array_map(
            fn(string $name): string => trim(preg_replace('/#\d+$/', '', $name)),
            array_column($all, 1)
        ));
    }

    /**
     * MDL-INT-099: every viewer may open the details; editing, running, enabling and deleting are offered only for the
     * agents the viewer may manage, and each action keeps the attributes the agents module listens to.
     */
    public function test_row_actions_follow_the_permission_to_manage_the_agent(): void {
        $this->resetAfterTest();
        $managera = $this->manager('Ann');
        $managerb = $this->manager('Bob');
        $this->setUser($managera);
        $report = $this->report();

        $actions = static function (\stdClass $row) use ($report): array {
            $links = [];
            foreach ($report->get_actions() as $action) {
                if ($action instanceof \core_reportbuilder\local\report\action && ($link = $action->get_action_link($row))) {
                    $links[] = $link->attributes['data-agent-action'] . ':' . $link->attributes['data-agent-id'];
                }
            }
            return $links;
        };

        $own = $actions((object) ['id' => 5, 'userid' => $managera->id, 'enabled' => 1]);
        $other = $actions((object) ['id' => 6, 'userid' => $managerb->id, 'enabled' => 0]);
        $owndisabled = $actions((object) ['id' => 7, 'userid' => $managera->id, 'enabled' => 0]);

        $this->assertSame(['details:5', 'edit:5', 'run:5', 'toggle:5', 'delete:5'], $own);
        $this->assertSame(['details:6'], $other, 'An agent of the same rank is only viewed');
        $this->assertContains('toggle:7', $owndisabled);
    }

    /**
     * MDL-INT-028: the report is open to users who manage agents and closed to the others.
     */
    public function test_only_agent_managers_can_view_the_report(): void {
        $this->resetAfterTest();
        $manager = $this->manager('Max');
        $student = $this->getDataGenerator()->create_user();

        $this->setUser($manager);
        $this->report()->require_can_view();

        $this->setUser($student);
        $this->expectException(\core_reportbuilder\exception\report_access_exception::class);
        $this->report()->require_can_view();
    }

    /**
     * MDL-INT-022: the charts count the agents that match the filters of the report.
     */
    public function test_charts_follow_the_filters_of_the_report(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $owner = (int) get_admin()->id;
        $this->agent($owner, ['name' => 'On', 'role_filter' => json_encode(['editingteacher'])]);
        $this->agent($owner, ['name' => 'Off', 'enabled' => 0, 'role_filter' => json_encode(['student'])]);

        $all = agent_manager::get_chart_stats(['idsql' => filtered_ids::sql($this->report())]);
        $enabled = agent_manager::get_chart_stats(['idsql' => filtered_ids::sql($this->report([
            'agent:enabled_operator' => select::EQUAL_TO, 'agent:enabled_value' => 1,
        ]))]);

        $this->assertSame(1, $all->by_status->enabled);
        $this->assertSame(1, $all->by_status->disabled);
        $this->assertSame(1, $enabled->by_status->enabled);
        $this->assertSame(0, $enabled->by_status->disabled);
        $this->assertSame(['editingteacher' => 1], $enabled->by_role);
    }

    /**
     * MDL-INT-022: ajax/agent_stats.php counts only the agents that match the filters each user saved in the agents
     * report, and the prompt search of a manager never counts an agent whose instruction the manager may not read.
     */
    public function test_agent_stats_endpoint_follows_the_saved_filters(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $admin = (int) get_admin()->id;
        $manager = $this->getDataGenerator()->create_user(['username' => 'statsmanager']);
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->agent($admin, ['name' => 'On', 'prompt' => 'Secret admin marker', 'role_filter' => json_encode(['editingteacher'])]);
        $this->agent($admin, ['name' => 'Off one', 'enabled' => 0, 'role_filter' => json_encode(['student'])]);
        $this->agent($admin, ['name' => 'Off two', 'enabled' => 0, 'role_filter' => json_encode(['student'])]);
        $this->agent((int) $manager->id, ['name' => 'Own', 'prompt' => 'Secret own report', 'enabled' => 0]);

        $this->setAdminUser();
        $this->report(['agent:enabled_operator' => select::EQUAL_TO, 'agent:enabled_value' => 0]);
        $this->setUser($manager);
        $this->report(['agent:prompt_operator' => text::CONTAINS, 'agent:prompt_value' => 'Secret']);

        $request = static function (string $username): array {
            global $CFG;
            $runner = $CFG->dirroot . '/local/jedomi/tests/fixtures/page_runner.php';
            $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
                . escapeshellarg($username) . ' ajax/agent_stats.php {} 2>&1');
            return (array) json_decode(trim($output), true);
        };

        $adminanswer = $request('admin');
        $this->assertTrue($adminanswer['ok'] ?? false, json_encode($adminanswer));
        $this->assertSame(['enabled' => 0, 'disabled' => 3], $adminanswer['charts']['by_status']);
        $this->assertSame(['student' => 2], $adminanswer['charts']['by_role']);

        $manageranswer = $request('statsmanager');
        $this->assertTrue($manageranswer['ok'] ?? false, json_encode($manageranswer));
        $this->assertSame(
            ['enabled' => 0, 'disabled' => 1],
            $manageranswer['charts']['by_status'],
            'Only the own agent: the instruction of the administrator agent is not searched'
        );
    }
}
