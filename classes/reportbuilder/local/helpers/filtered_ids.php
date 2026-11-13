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

namespace local_jedomi\reportbuilder\local\helpers;

use core_reportbuilder\system_report;

/**
 * Ids of the rows a system report shows to the current user, with the filter values they saved.
 *
 * The charts of the Agents tab and the indicators of the Query profiler tab count the same rows as their report, so
 * they follow its filters. The SQL is built as the report table builds it: the base condition and the SQL of each
 * active filter with the saved values.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filtered_ids {
    /**
     * Query that selects the ids of the main table rows that match the report filters of the current user.
     *
     * @param system_report $report Report whose main table has an id field.
     * @return array [string $sql, array $params]
     */
    public static function sql(system_report $report): array {
        $alias = $report->get_main_table_alias();
        $joins = $report->get_joins();
        [$where, $params] = $report->get_base_condition();
        $wheres = $where === '' ? [] : [$where];

        $values = $report->get_filter_values();
        foreach ($report->get_active_filters() as $filter) {
            $filterclass = $filter->get_filter_class();
            [$filtersql, $filterparams] = $filterclass::create($filter)->get_sql_filter($values);
            if ($filtersql === '') {
                continue;
            }
            $joins = array_merge($joins, $filter->get_joins());
            $wheres[] = "({$filtersql})";
            $params = array_merge($params, $filterparams);
        }

        $wheresql = $wheres ? implode(' AND ', $wheres) : '1 = 1';
        $sql = "SELECT {$alias}.id FROM {" . $report->get_main_table() . "} {$alias} " . implode(' ', array_unique($joins))
            . " WHERE {$wheresql}";
        return [$sql, $params];
    }
}
