<?php
/**
 * Admin Describe tool — get full documentation for a specific admin CLI operation.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

class admin_describe_tool implements agent_tool {

    public function get_name(): string {
        return 'admin_describe';
    }

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

    public function execute(\stdClass $params): string {
        $role = \local_jedomi_get_user_role();
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $name  = trim($params->name ?? '');
        $entry = \local_jedomi\schema\cli_registry::describe($name);
        return json_encode($entry);
    }
}
