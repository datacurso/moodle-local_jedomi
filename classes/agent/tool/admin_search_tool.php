<?php
/**
 * Admin Search tool — search CLI operations available to administrators.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

class admin_search_tool implements agent_tool {

    public function get_name(): string {
        return 'admin_search';
    }

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
