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
 * Admin Search tool — search CLI operations available to administrators.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that searches the CLI registry for admin operations
 * matching a keyword query, restricted to admin and manager roles.
 */
class admin_search_tool implements agent_tool {
    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'admin_search'.
     */
    public function get_name(): string {
        return 'admin_search';
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
                'name' => 'admin_search',
                'description' => get_string('tool_admin_search_desc', 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => get_string('tool_admin_search_query', 'local_jedomi'),
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => get_string('tool_admin_search_limit', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * Search CLI operations by keyword after an admin/manager role check.
     *
     * @param \stdClass $params Decoded tool arguments; expects 'query' and optional 'limit' (capped at 20).
     * @return string JSON-encoded matches with a result count, or a permission_denied error.
     */
    public function execute(\stdClass $params): string {
        $role = \local_jedomi_get_user_role();
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $query = trim($params->query ?? '');
        $limit = min((int) ($params->limit ?? 8), 20);

        $results = \local_jedomi\schema\cli_registry::search($query, $limit);
        return json_encode(['results' => $results, 'count' => count($results)]);
    }
}
