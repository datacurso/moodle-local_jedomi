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

use local_jedomi\schema\ws_indexer;

/**
 * Agent tool that returns the full parameter and return documentation
 * for a single Moodle web service function via the WS indexer.
 */
class ws_describe_tool implements agent_tool {
    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'ws_describe'.
     */
    public function get_name(): string {
        return 'ws_describe';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with the exact WS function name as the only required parameter.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'ws_describe',
                'description' => 'Get full documentation for a specific Moodle web service'
                    . ' function: description, parameters with types, and return structure.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'wsname' => [
                            'type' => 'string',
                            'description' => 'Exact web service function name'
                                . ' (e.g. core_user_create_users, core_course_get_courses).',
                        ],
                    ],
                    'required' => ['wsname'],
                ],
            ],
        ];
    }

    /**
     * Look up the WS function documentation via ws_indexer::describe() and time the lookup.
     *
     * @param \stdClass $params Decoded tool arguments; expects a 'wsname' property with the function name.
     * @return string JSON-encoded function documentation with duration, or an error if not found.
     */
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
