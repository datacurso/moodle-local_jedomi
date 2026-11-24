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
use core_reportbuilder\system_report;
use lang_string;
use local_jedomi\reportbuilder\local\entities\query_log as query_log_entity;

/**
 * Recent queries system report of the Query profiler tab.
 *
 * Renders {local_jedomi_query_log} with the Report Builder framework, providing filters, pagination, sorting and
 * download. The user of a query is the one it ran for or, for a scheduled agent, the owner of the agent.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query_log extends system_report {
    /**
     * Initialise the report: main table, entities, columns and filters.
     */
    protected function initialise(): void {
        $this->add_query_log_entities();
        $this->set_default_per_page(10);

        $this->add_columns_from_entities([
            'query_log:origin',
            'user:fullname',
            'query_log:query',
            'query_log:duration',
            'query_log:rowcount',
            'query_log:timecreated',
        ]);
        $this->get_column('user:fullname')->set_title(new lang_string('query_profiler_user', 'local_jedomi'));

        $this->add_query_log_filters();

        $this->set_initial_sort_column('query_log:timecreated', SORT_DESC);
        $this->set_downloadable(true, static::get_name());
    }

    /**
     * Add the profiled query entity as main table and the user each query ran for.
     */
    protected function add_query_log_entities(): void {
        $entitymain = new query_log_entity();
        $mainalias = $entitymain->get_table_alias('local_jedomi_query_log');

        $this->set_main_table('local_jedomi_query_log', $mainalias);
        $this->add_entity($entitymain);

        // The user is the one the query ran for or, for a scheduled agent (or a query without user), the agent owner.
        $entityuser = new user();
        $useralias = $entityuser->get_table_alias('user');
        $agentalias = 'jqla';
        $this->add_entity($entityuser
            ->add_join("LEFT JOIN {local_jedomi_agents} {$agentalias} ON {$agentalias}.id = {$mainalias}.agentid")
            ->add_join("LEFT JOIN {user} {$useralias} ON {$useralias}.id = CASE
                WHEN ({$mainalias}.channel = 'agent' OR {$mainalias}.userid = 0) AND {$mainalias}.agentid > 0
                THEN {$agentalias}.userid ELSE {$mainalias}.userid END"));
    }

    /**
     * Filters shared by the recent and the slowest queries: channel, duration, user, query and date.
     */
    protected function add_query_log_filters(): void {
        $this->add_filters_from_entities([
            'query_log:channel',
            'query_log:duration',
            'user:fullname',
            'query_log:query',
            'query_log:timecreated',
        ]);
        $this->get_filter('user:fullname')->set_header(new lang_string('query_profiler_user', 'local_jedomi'));
    }

    /**
     * Validate access to view this report.
     *
     * @return bool
     */
    protected function can_view(): bool {
        return has_capability('moodle/site:config', $this->get_context());
    }

    /**
     * Get the visible name of the report.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('query_profiler_recent', 'local_jedomi');
    }
}
