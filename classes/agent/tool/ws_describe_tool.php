<?php
/**
 * ws_describe tool — gets full documentation for a specific Moodle WS function.
 *
 * Uses schema\ws_indexer::describe() to return the complete parameter
 * and return structure for a known web service function. The AI should
 * call this AFTER ws_search and BEFORE ws_execute to verify required
 * parameters and return types.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

use local_jedomi\schema\ws_indexer;

class ws_describe_tool implements agent_tool {

    public function get_name(): string {
        return 'ws_describe';
    }

    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'ws_describe',
                'description' => 'Get full documentation for a specific Moodle web service function: description, parameters with types, and return structure.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'wsname' => [
                            'type' => 'string',
                            'description' => 'Exact web service function name (e.g. core_user_create_users, core_course_get_courses).',
                        ],
                    ],
                    'required' => ['wsname'],
                ],
            ],
        ];
    }

    public function execute(\stdClass $params): string {
        $wsname = trim($params->wsname ?? '');
        if (empty($wsname)) {
            return json_encode(['error' => 'Web service name required.']);
        }

        $start = microtime(true);

        try {
            $info = ws_indexer::describe($wsname);

            if (isset($info['error'])) {
                return json_encode([
                    'error' => "Web service not found: {$wsname}",
                    'detail' => $info['error'],
                    'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                ]);
            }

            return json_encode([
                'success' => true,
                'function' => $info,
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => 'ws_describe failed: ' . $e->getMessage(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);
        }
    }
}
