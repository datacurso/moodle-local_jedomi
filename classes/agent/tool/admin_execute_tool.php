<?php
/**
 * Admin Execute tool — execute admin CLI operations to create/modify Moodle data.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

class admin_execute_tool implements agent_tool {

    public function get_name(): string {
        return 'admin_execute';
    }

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

    public function execute(\stdClass $params): string {
        $role = \local_jedomi_get_user_role();
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $name   = trim($params->name ?? '');
        $params = $params->params ?? [];

        $result = \local_jedomi\schema\cli_registry::execute($name, $params);
        return json_encode($result);
    }
}
