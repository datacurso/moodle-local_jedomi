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

use core_reportbuilder\local\report\column;
use lang_string;
use local_jedomi\reportbuilder\local\entities\query_log as query_log_entity;

/**
 * Slowest queries system report of the Query profiler tab.
 *
 * Groups {local_jedomi_query_log} by query text with the native column aggregation of system reports (Moodle 4.5),
 * showing the average and maximum duration and the number of runs of each query, slowest average first. It has the
 * same filters as the recent queries report.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query_log_aggregate extends query_log {
    /**
     * Initialise the report: main table, entities, grouped columns and filters.
     */
    protected function initialise(): void {
        $this->add_query_log_entities();
        $this->set_default_per_page(10);
        $mainalias = $this->get_main_table_alias();

        $this->add_columns_from_entities(['query_log:query']);

        $this->add_column((new column(
            'avgduration',
            new lang_string('query_profiler_avg', 'local_jedomi'),
            'query_log'
        ))
            ->set_type(column::TYPE_FLOAT)
            ->add_field("{$mainalias}.duration_ms")
            ->set_is_sortable(true)
            ->set_aggregation('avg')
            ->add_callback(static fn($value): string => query_log_entity::format_duration((float) $value, true)));

        $this->add_column((new column(
            'maxduration',
            new lang_string('query_profiler_max', 'local_jedomi'),
            'query_log'
        ))
            ->set_type(column::TYPE_FLOAT)
            ->add_field("{$mainalias}.duration_ms")
            ->set_is_sortable(true)
            ->set_aggregation('max')
            ->add_callback(static fn($value): string => query_log_entity::format_duration((float) $value, false)));

        $this->add_column((new column(
            'execcount',
            new lang_string('query_profiler_count', 'local_jedomi'),
            'query_log'
        ))
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$mainalias}.id")
            ->set_is_sortable(true)
            ->set_aggregation('count'));

        $this->add_query_log_filters();

        $this->set_initial_sort_column('query_log:avgduration', SORT_DESC);
        $this->set_downloadable(true, static::get_name());
    }

    /**
     * Get the visible name of the report.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('query_profiler_slowest', 'local_jedomi');
    }
}
