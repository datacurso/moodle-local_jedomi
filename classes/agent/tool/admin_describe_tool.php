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
 * Admin Describe tool — get full documentation for a specific admin CLI operation.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that returns the full documentation of a single admin CLI
 * operation from the CLI registry.
 */
class admin_describe_tool implements agent_tool {
    use acts_for_user;

    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'admin_describe'.
     */
    public function get_name(): string {
        return 'admin_describe';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with the operation name as the only required parameter.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'admin_describe',
                'description' => get_string('tool_admin_describe_desc', 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => get_string('tool_admin_describe_name', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];
    }

    /**
     * Look up an admin CLI operation in the registry after an admin/manager role check.
     *
     * @param \stdClass $params Decoded tool arguments; expects a 'name' property with the operation name.
     * @return string JSON-encoded operation documentation, or a permission_denied error.
     */
    public function execute(\stdClass $params): string {
        $role = $this->actor_role();
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $name  = trim($params->name ?? '');
        $entry = \local_jedomi\schema\cli_registry::describe($name);
        return json_encode($entry);
    }
}
