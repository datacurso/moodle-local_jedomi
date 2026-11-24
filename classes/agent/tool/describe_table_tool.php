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

/**
 * describe_table tool — shows columns of a Moodle database table.
 *
 * Wraps $DB->get_columns() to return a formatted list of column
 * names, types, and meta types so the AI can explore the schema.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that lists the columns of a Moodle database table
 * (name, type, meta type) using the DML get_columns() API.
 */
class describe_table_tool implements agent_tool {
    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'describe_table'.
     */
    public function get_name(): string {
        return 'describe_table';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with the table name as the only required parameter.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'describe_table',
                'description' => 'Show columns of a database table: name, type, meta_type.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tablename' => [
                            'type' => 'string',
                            'description' => 'The database table name (e.g. course, user, grade_grades).',
                        ],
                    ],
                    'required' => ['tablename'],
                ],
            ],
        ];
    }

    /**
     * Fetch column metadata for the requested table and time the lookup.
     *
     * @param \stdClass $params Decoded tool arguments; expects a 'tablename' property.
     * @return string JSON-encoded column list with counts and duration, or an error if the table is missing.
     */
    public function execute(\stdClass $params): string {
        global $CFG, $DB;

        $tablename = trim($params->tablename ?? '');
        if (empty($tablename)) {
            return json_encode(['error' => get_string('telegram_table_name_required', 'local_jedomi')]);
        }
        // A sensitive table is never described, as it is never read (see sql_guard), as in the web chat.
        $bare = preg_replace('/^\{(\w+)\}$/', '$1', $tablename);
        if ((string) $CFG->prefix !== '' && strpos($bare, $CFG->prefix) === 0) {
            $bare = substr($bare, strlen($CFG->prefix));
        }
        if ($bare !== '' && \local_jedomi\local\sql_guard::is_sensitive_table($bare)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $start = microtime(true);

        $columns = $DB->get_columns($tablename);
        if ($columns === false || empty($columns)) {
            return json_encode([
                'error' => get_string('table_not_found', 'local_jedomi', $tablename),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);
        }

        $result = [];
        foreach ($columns as $col) {
            $entry = [
                'name' => $col->name,
                'type' => $col->type,
            ];
            if (!empty($col->meta_type)) {
                $entry['meta_type'] = $col->meta_type;
            }
            $result[] = $entry;
        }

        return json_encode([
            'success' => true,
            'tablename' => $tablename,
            'column_count' => count($result),
            'row_count' => count($result),
            'columns' => $result,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ], JSON_UNESCAPED_UNICODE);
    }
}
