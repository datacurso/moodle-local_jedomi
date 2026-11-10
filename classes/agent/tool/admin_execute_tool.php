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
 * Admin Execute tool — execute admin CLI operations to create/modify Moodle data.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that runs a registered admin CLI operation through the
 * CLI registry, restricted to admin and manager roles.
 */
class admin_execute_tool implements agent_tool {
    use acts_for_user;

    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'admin_execute'.
     */
    public function get_name(): string {
        return 'admin_execute';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with a required operation name and optional params object.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'admin_execute',
                'description' => get_string('tool_admin_execute_desc', 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => get_string('tool_admin_execute_name', 'local_jedomi'),
                        ],
                        'params' => [
                            'type' => 'object',
                            'description' => get_string('tool_admin_execute_params', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];
    }

    /**
     * Execute the named CLI operation with the given parameters after an admin/manager role check.
     *
     * @param \stdClass $params Decoded tool arguments; expects 'name' (operation) and optional 'params' (arguments).
     * @return string JSON-encoded execution result, or a permission_denied error.
     */
    public function execute(\stdClass $params): string {
        $role = $this->actor_role();
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $name   = trim($params->name ?? '');
        $params = (array) ($params->params ?? []);

        $result = \local_jedomi\schema\cli_registry::execute($name, $params, $this->actor_id());
        return json_encode($result);
    }
}
