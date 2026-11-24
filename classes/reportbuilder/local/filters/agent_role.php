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

namespace local_jedomi\reportbuilder\local\filters;

use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\database;

/**
 * Role filter of the agents report.
 *
 * The roles of an agent are stored as a JSON list of role short names, so the chosen role is matched inside that list
 * instead of comparing the whole field.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_role extends select {
    /**
     * SQL of the filter: the agents whose role list holds (or does not hold) the chosen role short name.
     *
     * @param array $values Filter values.
     * @return array [string $sql, array $params]
     */
    public function get_sql_filter(array $values): array {
        global $DB;

        $operator = (int) ($values["{$this->name}_operator"] ?? self::ANY_VALUE);
        $value = (string) ($values["{$this->name}_value"] ?? '');
        if (
            !in_array($operator, [self::EQUAL_TO, self::NOT_EQUAL_TO], true)
            || !array_key_exists($value, $this->get_select_options())
        ) {
            return ['', []];
        }

        $name = database::generate_param_name();
        $fieldsql = 'COALESCE(' . $this->filter->get_field_sql() . ", '')";
        $sql = $DB->sql_like($fieldsql, ":{$name}", false, false, $operator === self::NOT_EQUAL_TO);
        $params = $this->filter->get_field_params();
        $params[$name] = '%"' . $DB->sql_like_escape($value) . '"%';
        return [$sql, $params];
    }
}
