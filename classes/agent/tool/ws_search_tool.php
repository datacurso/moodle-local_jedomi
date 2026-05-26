<?php
/**
 * ws_search tool — searches Moodle web service functions by keyword.
 *
 * Uses the schema\ws_indexer (semantic search over WS function definitions)
 * to find relevant functions matching the user's intent. Returns function
 * name, component, description, params, and returns structure so the AI
 * can find the right WS to call.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

use local_jedomi\schema\ws_indexer;

class ws_search_tool implements agent_tool {

    public function get_name(): string {
        return 'ws_search';
    }

    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'ws_search',
                'description' => 'Search Moodle web service functions by intent or keywords. Returns function name, component, description, params, returns.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Intent or keywords in ENGLISH (e.g. "create user", "enrol student", "get course grades").',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum results (default: 8, max: 20).',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    public function execute(\stdClass $params): string {
        $query = trim($params->query ?? '');
        if (empty($query)) {
            return json_encode(['error' => 'Search query required.']);
        }

        $start = microtime(true);
        $limit = isset($params->limit) ? min((int) $params->limit, 20) : 8;

        try {
            $entries = ws_indexer::search($query, [], $limit);

            if (empty($entries)) {
                return json_encode([
                    'success' => true,
                    'count' => 0,
                    'row_count' => 0,
                    'functions' => [],
                    'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                    'message' => "No web services found matching: {$query}",
                ]);
            }

            $functions = [];
            foreach ($entries as $entry) {
                $f = [
                    'name' => $entry['name'],
                    'component' => $entry['component'] ?? '',
                    'description' => $entry['description'] ?? '',
                    'params' => $entry['params'] ?? '(none)',
                    'returns' => $entry['returns'] ?? 'void',
                ];
                $functions[] = $f;
            }

            return json_encode([
                'success' => true,
                'count' => count($functions),
                'row_count' => count($functions),
                'functions' => $functions,
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => 'ws_search failed: ' . $e->getMessage(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);
        }
    }
}
