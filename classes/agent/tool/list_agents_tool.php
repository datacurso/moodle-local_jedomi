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
 * List Agents tool — lists all configured scheduled AI agents.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

/**
 * Agent tool that lists the configured scheduled AI agents with their
 * schedule and status details, restricted to admin and manager roles.
 */
class list_agents_tool implements agent_tool {
    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'list_agents'.
     */
    public function get_name(): string {
        return 'list_agents';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with an optional status filter and no required parameters.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_agents',
                'description' => get_string('tool_list_agents_desc', 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => [
                            'type' => 'string',
                            'description' => get_string('tool_list_agents_status', 'local_jedomi'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Fetch agents from agent_manager, optionally filtered by status, after an admin/manager role check.
     *
     * @param \stdClass $params Decoded tool arguments; accepts an optional 'status' filter.
     * @return string JSON-encoded total and normalised agent list, or a permission_denied error.
     */
    public function execute(\stdClass $params): string {
        $role = \local_jedomi_get_user_role();
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $filters = [];
        if (!empty($params->status)) {
            $filters['status'] = $params->status;
        }

        $result = \local_jedomi\agent\agent_manager::get_agents($filters);
        return json_encode([
            'success' => true,
            'total'   => $result['total'],
            'agents'  => array_map(function ($a) {
                return [
                    'id'            => (int) $a->id,
                    'name'          => $a->name ?? '',
                    'prompt'        => $a->prompt,
                    'schedule_cron' => $a->schedule_cron,
                    'context_type'  => $a->context_type,
                    'context_id'    => $a->context_id ? (int) $a->context_id : null,
                    'enabled'       => (bool) $a->enabled,
                    'lastrun'       => $a->lastrun ? (int) $a->lastrun : null,
                    'nextrun'       => (int) $a->nextrun,
                ];
            }, $result['items']),
        ]);
    }
}
