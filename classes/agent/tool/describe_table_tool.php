<?php
/**
 * describe_table tool — shows columns of a Moodle database table.
 *
 * Wraps $DB->get_columns() to return a formatted list of column
 * names, types, and meta types so the AI can explore the schema.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

class describe_table_tool implements agent_tool {

    public function get_name(): string {
        return 'describe_table';
    }

    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'describe_table',
                'description' => 'Show columns of a database table: name, type, meta_type.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tablename' => [
                            'type' => 'string',
                            'description' => 'The database table name (e.g. course, user, grade_grades).',
                        ],
                    ],
                    'required' => ['tablename'],
                ],
            ],
        ];
    }

    public function execute(\stdClass $params): string {
        global $DB;

        $tablename = trim($params->tablename ?? '');
        if (empty($tablename)) {
            return json_encode(['error' => 'Table name required.']);
        }

        $start = microtime(true);

        $columns = $DB->get_columns($tablename);
        if ($columns === false || empty($columns)) {
            return json_encode([
                'error' => "Table not found: {$tablename}",
                'duration_ms' => round((microtime(true) - $start) * 1000, 2),
            ]);
        }

        $result = [];
        foreach ($columns as $col) {
            $entry = [
                'name' => $col->name,
                'type' => $col->type,
            ];
            if (!empty($col->meta_type)) {
                $entry['meta_type'] = $col->meta_type;
            }
            $result[] = $entry;
        }

        return json_encode([
            'success' => true,
            'tablename' => $tablename,
            'column_count' => count($result),
            'row_count' => count($result),
            'columns' => $result,
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ], JSON_UNESCAPED_UNICODE);
    }
}
