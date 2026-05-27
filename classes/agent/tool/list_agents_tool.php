<?php
/**
 * List Agents tool — lists all configured scheduled AI agents.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

class list_agents_tool implements agent_tool {

    public function get_name(): string {
        return 'list_agents';
    }

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
            'agents'  => array_map(function($a) {
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
