<?php
/**
 * search_tables tool — searches Moodle database tables by keyword.
 *
 * Uses the schema\table_indexer (search over install.xml metadata) to
 * find tables matching the user's intent. Returns table names and
 * descriptions so the AI can then use describe_table or execute_sql
 * with the correct table name.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

use local_jedomi\schema\table_indexer;

class search_tables_tool implements agent_tool {

    public function get_name(): string {
        return 'search_tables';
    }

    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'search_tables',
                'description' => 'Search database tables by name or intent. Returns table name, comment.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Intent or keywords in ENGLISH (e.g. "user grades", "course enrolment", "student").',
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
            $entries = table_indexer::search($query, $limit);

            if (empty($entries)) {
                return json_encode([
                    'success' => true,
                    'count' => 0,
                    'row_count' => 0,
                    'tables' => [],
                    'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                    'message' => "No tables found matching: {$query}",
                ]);
            }

            $tables = [];
            foreach ($entries as $entry) {
                $tables[] = [
                    'name' => $entry['name'],
                    'comment' => $entry['comment'] ?? '',
                ];
            }

            return json_encode([
                'success' => true,
                'count' => count($tables),
                'row_count' => count($tables),
                'tables' => $tables,
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => 'search_tables failed: ' . $e->getMessage(),
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);
        }
    }
}
