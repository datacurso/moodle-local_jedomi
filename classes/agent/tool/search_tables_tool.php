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
 * search_tables tool — searches Moodle database tables by keyword.
 *
 * Uses the schema\table_indexer (search over install.xml metadata) to
 * find tables matching the user's intent. Returns table names and
 * descriptions so the AI can then use describe_table or execute_sql
 * with the correct table name.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

use local_jedomi\local\safe_error;
use local_jedomi\schema\table_indexer;

/**
 * Agent tool that searches indexed Moodle database tables by keyword
 * and returns matching table names with their comments.
 */
class search_tables_tool implements agent_tool {
    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'search_tables'.
     */
    public function get_name(): string {
        return 'search_tables';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with a required query string and optional result limit.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'search_tables',
                'description' => 'Search database tables by name or intent. Returns table name, comment.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Intent or keywords in ENGLISH (e.g. "user grades", "course enrolment", "student").',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum results (default: 8, max: 20).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * Run a keyword search against the table index and time the lookup.
     *
     * @param \stdClass $params Decoded tool arguments; expects 'query' and optional 'limit' (default 8, max 20).
     * @return string JSON-encoded matching tables with counts and duration, or an error message.
     */
    public function execute(\stdClass $params): string {
        $query = trim($params->query ?? '');
        if (empty($query)) {
            return json_encode(['error' => get_string('telegram_search_query_required', 'local_jedomi')]);
        }

        $start = microtime(true);
        $limit = isset($params->limit) ? min((int) $params->limit, 20) : 8;

        try {
            $entries = table_indexer::search($query, $limit);

            if (empty($entries)) {
                return json_encode([
                    'success' => true,
                    'count' => 0,
                    'row_count' => 0,
                    'tables' => [],
                    'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                    'message' => get_string('telegram_no_tables_found', 'local_jedomi', $query),
                ]);
            }

            $tables = [];
            foreach ($entries as $entry) {
                $tables[] = [
                    'name' => $entry['name'],
                    'comment' => $entry['comment'] ?? '',
                ];
            }

            return json_encode([
                'success' => true,
                'count' => count($tables),
                'row_count' => count($tables),
                'tables' => $tables,
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            return json_encode([
                'error' => get_string('agent_tool_search_tables_failed', 'local_jedomi', safe_error::for_model($e)),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);
        }
    }
}
