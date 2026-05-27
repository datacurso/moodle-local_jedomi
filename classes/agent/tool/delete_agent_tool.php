<?php
/**
 * Delete Agent tool — deletes a scheduled AI agent by ID.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

class delete_agent_tool implements agent_tool {

    public function get_name(): string {
        return 'delete_agent';
    }

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

    public function execute(\stdClass $params): string {
        $role = \local_jedomi_get_user_role();
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $id = (int) ($params->id ?? 0);
        if ($id <= 0) {
            return json_encode(['error' => 'Valid agent ID required.']);
        }

        $agent = \local_jedomi\agent\agent_manager::get_agent($id);
        if (!$agent) {
            return json_encode(['error' => "Agent #{$id} not found."]);
        }

        \local_jedomi\agent\agent_manager::delete_agent($id);
        return json_encode([
            'success' => true,
            'message' => "Agent #{$id} deleted.",
        ]);
    }
}
