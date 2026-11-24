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

use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\report\action;
use core_reportbuilder\system_report;
use lang_string;
use local_jedomi\agent\agent_manager;
use local_jedomi\reportbuilder\local\entities\agent;
use moodle_url;
use pix_icon;
use stdClass;

/**
 * Agents system report of the Agents tab.
 *
 * Renders {local_jedomi_agents} with the Report Builder framework, providing filters, pagination, sorting and
 * download. The row actions keep the data-agent-action and data-agent-id attributes the local_jedomi/admin/agents
 * module listens to, so they open the same details modal, agent form and confirmations as before.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agents extends system_report {
    /** @var bool[] Whether the current user may manage the agents of each owner, by owner id. */
    private array $canmanage = [];

    /**
     * Initialise the report: main table, entities, columns, filters and actions.
     */
    protected function initialise(): void {
        $entitymain = new agent();
        $mainalias = $entitymain->get_table_alias('local_jedomi_agents');

        $this->set_main_table('local_jedomi_agents', $mainalias);
        $this->add_entity($entitymain);
        $this->add_base_fields("{$mainalias}.id, {$mainalias}.userid, {$mainalias}.enabled");
        $this->set_default_per_page(10);

        // Join the core user entity to resolve the creator's full name.
        $entityuser = new user();
        $useralias = $entityuser->get_table_alias('user');
        $this->add_entity($entityuser->add_join(
            "LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$mainalias}.userid"
        ));

        $this->add_columns_from_entities([
            'user:fullname',
            'agent:name',
            'agent:schedule',
            'agent:status',
            'agent:lastrun',
        ]);
        $this->get_column('user:fullname')->set_title(new lang_string('agent_creator', 'local_jedomi'));

        $this->add_filters_from_entities([
            'agent:role',
            'agent:enabled',
            'agent:contexttype',
            'agent:prompt',
        ]);

        $this->add_actions();

        $this->set_initial_sort_column('agent:name', SORT_ASC);
        $this->set_downloadable(true, get_string('agent_table', 'local_jedomi'));
    }

    /**
     * Row actions: the details for every viewer, the other actions for the agents the viewer may manage.
     */
    protected function add_actions(): void {
        $url = new moodle_url('#');
        $attributes = static fn(string $name): array => ['data-agent-action' => $name, 'data-agent-id' => ':id'];
        $canmanage = fn(stdClass $row): bool => $this->can_manage((int) $row->userid);

        $this->add_action(new action(
            $url,
            new pix_icon('t/preview', ''),
            $attributes('details'),
            false,
            new lang_string('agent_view_details', 'local_jedomi')
        ));
        $this->add_action((new action(
            new moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'agents', 'edit_agent' => ':id']),
            new pix_icon('t/edit', ''),
            $attributes('edit'),
            false,
            new lang_string('agent_edit', 'local_jedomi')
        ))->add_callback($canmanage));
        $this->add_action((new action(
            $url,
            new pix_icon('t/play', ''),
            $attributes('run'),
            false,
            new lang_string('agent_run_now', 'local_jedomi')
        ))->add_callback($canmanage));
        $this->add_action((new action(
            $url,
            new pix_icon('t/hide', ''),
            $attributes('toggle'),
            false,
            new lang_string('agent_disable', 'local_jedomi')
        ))->add_callback(fn(stdClass $row): bool => $canmanage($row) && (int) $row->enabled === 1));
        $this->add_action((new action(
            $url,
            new pix_icon('t/show', ''),
            $attributes('toggle'),
            false,
            new lang_string('agent_enable', 'local_jedomi')
        ))->add_callback(fn(stdClass $row): bool => $canmanage($row) && (int) $row->enabled !== 1));
        $this->add_action_divider();
        $this->add_action((new action(
            $url,
            new pix_icon('t/delete', ''),
            $attributes('delete') + ['class' => 'text-danger'],
            false,
            new lang_string('agent_delete', 'local_jedomi')
        ))->add_callback($canmanage));
    }

    /**
     * Whether the current user may manage the agents of an owner.
     *
     * @param int $ownerid Owner of the agent.
     * @return bool
     */
    private function can_manage(int $ownerid): bool {
        global $USER;
        if (!array_key_exists($ownerid, $this->canmanage)) {
            $this->canmanage[$ownerid] = agent_manager::can_manage_agent((object) ['userid' => $ownerid], (int) $USER->id);
        }
        return $this->canmanage[$ownerid];
    }

    /**
     * Validate access to view this report.
     *
     * @return bool
     */
    protected function can_view(): bool {
        return has_capability('local/jedomi:manageagents', $this->get_context());
    }

    /**
     * Get the visible name of the report.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('agent_table', 'local_jedomi');
    }
}
