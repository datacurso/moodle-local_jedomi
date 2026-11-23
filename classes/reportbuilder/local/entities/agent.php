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

namespace local_jedomi\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use local_jedomi\agent\agent_manager;
use local_jedomi\reportbuilder\local\filters\agent_role;
use stdClass;

/**
 * Agent entity for the agents report of the Agents tab.
 *
 * Backed by {local_jedomi_agents}. The instruction (prompt) is never shown: it only feeds the name of an agent, hidden
 * when it is made of the first words of an instruction the viewer may not read, and the instruction search, which only
 * matches the instructions the viewer may read.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent extends base {
    /** @var string[] Context types an agent can run in. */
    private const CONTEXT_TYPES = ['site', 'course', 'category', 'user'];

    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'local_jedomi_agents',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity_agent', 'local_jedomi');
    }

    /**
     * Initialise the entity, adding all columns and filters.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }

        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }

        return $this;
    }

    /**
     * Returns the list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $tablealias = $this->get_table_alias('local_jedomi_agents');

        $columns = [];

        // Name column: a name made of the first words of a hidden instruction is hidden too.
        $columns[] = (new column(
            'name',
            new lang_string('agent_name', 'local_jedomi'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$tablealias}.name, {$tablealias}.id, {$tablealias}.userid, {$tablealias}.prompt")
            ->set_is_sortable(true, ["{$tablealias}.name"])
            ->add_callback(static function ($value, stdClass $row): string {
                global $USER;
                $agent = agent_manager::agent_for_actor((object) [
                    'userid' => (int) $row->userid,
                    'name' => $value,
                    'prompt' => (string) $row->prompt,
                ], (int) $USER->id);
                $name = trim((string) $agent->name);
                if ($name === '') {
                    $name = get_string('agent_unnamed', 'local_jedomi') . ' #' . (int) $row->id;
                }
                return \html_writer::tag('strong', s($name));
            });

        // Schedule column: readable schedule, with the cron expression as title.
        $columns[] = (new column(
            'schedule',
            new lang_string('agent_schedule', 'local_jedomi'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$tablealias}.schedule_cron")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                $cron = (string) $value;
                return \html_writer::span(s(agent_manager::describe_schedule($cron)), '', ['title' => $cron]);
            });

        // Status column: enabled or disabled, and failing with the number of consecutive failures.
        $columns[] = (new column(
            'status',
            new lang_string('agent_status', 'local_jedomi'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_fields("{$tablealias}.enabled, {$tablealias}.failcount")
            ->set_is_sortable(true, ["{$tablealias}.enabled"])
            ->add_callback(static function ($value, stdClass $row): string {
                $html = (int) $value === 1
                    ? \html_writer::span(get_string('agent_enabled', 'local_jedomi'), 'badge badge-success bg-success')
                    : \html_writer::span(get_string('agent_disabled', 'local_jedomi'), 'badge badge-secondary bg-secondary');
                $failcount = (int) $row->failcount;
                if ($failcount > 0) {
                    $html .= ' ' . \html_writer::span(
                        get_string('agent_failing', 'local_jedomi'),
                        'badge badge-danger bg-danger',
                        ['title' => get_string('agent_failing_count', 'local_jedomi', $failcount)]
                    );
                }
                return $html;
            });

        // Last run column.
        $columns[] = (new column(
            'lastrun',
            new lang_string('agent_lastrun', 'local_jedomi'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$tablealias}.lastrun")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return empty($value) ? '-' : userdate((int) $value, get_string('strftimedatetimeshort', 'core_langconfig'));
            });

        return $columns;
    }

    /**
     * Returns the list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        global $DB, $USER;
        $tablealias = $this->get_table_alias('local_jedomi_agents');

        $filters = [];

        // Role filter: the role short name inside the JSON list of roles of the agent.
        $filters[] = (new filter(
            agent_role::class,
            'role',
            new lang_string('agent_filter_role', 'local_jedomi'),
            $this->get_entity_name(),
            "{$tablealias}.role_filter"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                $options = [];
                foreach (role_fix_names(get_all_roles(), \context_system::instance(), ROLENAME_ALIAS) as $role) {
                    $options[$role->shortname] = $role->localname;
                }
                return $options;
            });

        // Status filter.
        $filters[] = (new filter(
            select::class,
            'enabled',
            new lang_string('agent_filter_status', 'local_jedomi'),
            $this->get_entity_name(),
            "{$tablealias}.enabled"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                return [
                    1 => get_string('agent_enabled', 'local_jedomi'),
                    0 => get_string('agent_disabled', 'local_jedomi'),
                ];
            });

        // Context type filter.
        $filters[] = (new filter(
            select::class,
            'contexttype',
            new lang_string('agent_filter_context', 'local_jedomi'),
            $this->get_entity_name(),
            "{$tablealias}.context_type"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                $options = [];
                foreach (self::CONTEXT_TYPES as $type) {
                    $options[$type] = get_string('agent_context_type_' . $type, 'local_jedomi');
                }
                return $options;
            });

        // Instruction search: a hidden instruction is seen as empty, so it can never be guessed by searching its words.
        $owners = agent_manager::owners_with_visible_instruction((int) ($USER->id ?? 0));
        if ($owners) {
            [$insql, $inparams] = $DB->get_in_or_equal($owners, SQL_PARAMS_NAMED, database::generate_param_name() . '_');
            $promptsql = "CASE WHEN {$tablealias}.userid {$insql} THEN {$tablealias}.prompt ELSE NULL END";
        } else {
            $inparams = [];
            $promptsql = "CASE WHEN 1 = 0 THEN {$tablealias}.prompt ELSE NULL END";
        }
        $filters[] = (new filter(
            text::class,
            'prompt',
            new lang_string('agent_prompt', 'local_jedomi'),
            $this->get_entity_name(),
            $promptsql,
            $inparams
        ))
            ->add_joins($this->get_joins())
            ->set_limited_operators([
                text::ANY_VALUE,
                text::CONTAINS,
            ]);

        return $filters;
    }
}
