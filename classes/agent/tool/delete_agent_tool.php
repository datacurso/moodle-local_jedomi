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
 * Delete Agent tool — deletes a scheduled AI agent by ID.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that deletes an existing scheduled AI agent by its ID,
 * restricted to admin and manager roles.
 */
class delete_agent_tool implements agent_tool {
    use acts_for_user;

    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'delete_agent'.
     */
    public function get_name(): string {
        return 'delete_agent';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with the agent id as the only required parameter.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'delete_agent',
                'description' => get_string('tool_delete_agent_desc', 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => get_string('tool_delete_agent_id', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
        ];
    }

    /**
     * Delete the agent with the given ID after an admin/manager role check and existence lookup.
     *
     * @param \stdClass $params Decoded tool arguments; expects an 'id' property with the agent ID.
     * @return string JSON-encoded success confirmation, or an error if the ID is invalid or not found.
     */
    public function execute(\stdClass $params): string {
        $role = $this->actor_role();
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $id = (int) ($params->id ?? 0);
        if ($id <= 0) {
            return json_encode(['error' => get_string('agent_tool_agent_id_required', 'local_jedomi')]);
        }

        $agent = \local_jedomi\agent\agent_manager::get_agent($id);
        if (!$agent) {
            return json_encode(['error' => get_string('agent_tool_agent_not_found', 'local_jedomi', $id)]);
        }
        if (!\local_jedomi\agent\agent_manager::can_manage_agent($agent, $this->actor_id())) {
            return json_encode(['error' => 'permission_denied']);
        }

        \local_jedomi\agent\agent_manager::delete_agent($id);
        return json_encode([
            'success' => true,
            'message' => get_string('agent_tool_agent_deleted', 'local_jedomi', $id),
        ]);
    }
}
