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
 * SQL query tool — executes read-only SELECT queries against the Moodle DB.
 *
 * Security:
 *  - Only SELECT and WITH statements are allowed.
 *  - Maximum result rows: 1000, read from the database through the shared query_runner (never more in memory).
 *  - Maximum execution time: 30 seconds for that query only (see query_runner::fetch()), never a session setting.
 *  - Blocklisted tables: config, sessions, oauth, tokens, private keys.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that runs read-only SQL against the Moodle database with
 * role gating, the shared sensitive-data guard, row limits, and profiler logging.
 */
class sql_tool implements agent_tool {
    /** @var int Agent ID being executed (for profiling). */
    private int $agentid;

    /** @var int Agent creator userid (for permission check). */
    private int $userid;

    /** @var string Channel recorded with each profiled query: agent (scheduled run) or preview. */
    private string $channel;

    /**
     * Constructor.
     *
     * @param int $agentid Agent ID recorded with each profiled query; 0 for the preview of a new agent.
     * @param int $userid  Agent creator's user ID, used for the role permission check.
     * @param string $channel agent for a scheduled run, preview for a preview.
     */
    public function __construct(int $agentid = 0, int $userid = 0, string $channel = 'agent') {
        $this->agentid = $agentid;
        $this->userid = $userid;
        $this->channel = $channel === 'preview' ? 'preview' : 'agent';
    }

    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'execute_sql'.
     */
    public function get_name(): string {
        return 'execute_sql';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition: the SQL query (required) and its parameters, with the limits of the agents.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'execute_sql',
                'description' => get_string('tool_execute_sql_desc', 'local_jedomi', [
                    'rows' => \local_jedomi\local\query_runner::row_limit('agent'),
                    'seconds' => (int) (\local_jedomi\local\query_runner::time_limit_ms('agent') / 1000),
                ]),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => get_string('tool_execute_sql_sql', 'local_jedomi'),
                        ],
                        'params' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => get_string('tool_execute_sql_params', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * Validate and run a read-only query, logging duration and row count to the profiler.
     *
     * Allowed roles: admin, manager, teacher. Only SELECT/WITH statements pass validation (SHOW, DESCRIBE
     * and EXPLAIN cannot carry the per-query time limit and row cap), the shared query guard rejects sensitive
     * data, and the shared runner caps the results at 1000 rows and the time at 30 seconds.
     *
     * @param \stdClass $params Decoded tool arguments; expects 'query' (or legacy 'sql') with the SQL text.
     * @return string JSON-encoded rows with count and duration, or an error message.
     */
    public function execute(\stdClass $params): string {
        // ... ── Permission gate: admin/manager/teacher (matches Telegram behaviour).
        // Uses the shared role detection from lib.php. Fail-closed: userid=0 → 'student' → denied.
        $role = \local_jedomi_get_user_role_for_user($this->userid);
        if (!in_array($role, ['admin', 'manager', 'teacher'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        // Accept both 'query' (this class's definition) and 'sql' (lib.php definition — web chat compat).
        $query = trim($params->query ?? $params->sql ?? '');
        if (empty($query)) {
            return json_encode(['error' => 'Empty query']);
        }

        // ... ── Validate read-only ────────────────────────────────────────────────
        $firstword = strtoupper(strtok($query, " \t\n\r\0\x0B"));
        $allowed = ['SELECT', 'WITH'];
        if (!in_array($firstword, $allowed, true)) {
            return json_encode([
                'error' => 'Only SELECT and WITH queries are allowed.',
            ]);
        }

        // ... ── Sensitive tables and courses the user does not manage (shared by every channel) ──
        $rejection = \local_jedomi\local\sql_guard::rejection_reason($query, $this->userid);
        if ($rejection !== null) {
            return json_encode(['error' => get_string($rejection, 'local_jedomi')]);
        }

        // ... ── Execute with a per-query time limit and a row cap read from the database + profiling ──
        $start = microtime(true);

        try {
            $result = \local_jedomi\local\query_runner::fetch(
                $query,
                self::query_params($params),
                \local_jedomi\local\query_runner::row_limit('agent'),
                \local_jedomi\local\query_runner::time_limit_ms('agent')
            );

            $elapsedms = (microtime(true) - $start) * 1000;
            $rowcount = count($result['rows']);

            // ... ── Log to profiler ──────────────────────────────────────────────
            \local_jedomi\agent\query_profiler::log_query(
                $this->agentid,
                $query,
                $elapsedms,
                $rowcount,
                $this->channel,
                $this->userid
            );

            // ... ── Format result for AI (rows already without sensitive columns) ──
            return json_encode([
                'success' => true,
                'rows' => $result['rows'],
                'row_count' => $rowcount,
                'truncated' => $result['truncated'],
                'duration_ms' => round($elapsedms, 2),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $elapsedms = (microtime(true) - $start) * 1000;

            \local_jedomi\agent\query_profiler::log_query(
                $this->agentid,
                $query,
                $elapsedms,
                0,
                $this->channel,
                $this->userid
            );

            // The database message (an unknown column, a syntax error) lets the agent fix its query.
            $detail = ($e instanceof \moodle_exception && !empty($e->debuginfo)) ? ' | ' . $e->debuginfo : '';
            $error = \local_jedomi\local\query_runner::is_timeout($e)
                ? get_string('query_timeout', 'local_jedomi')
                : $e->getMessage() . $detail;
            return json_encode([
                'error' => $error,
                'duration_ms' => round($elapsedms, 2),
            ]);
        }
    }

    /**
     * Parameters of the query sent by the AI (the "params" list of the tool), as positional values.
     *
     * @param \stdClass $params Decoded tool arguments.
     * @return array Positional parameters; empty when none were sent.
     */
    public static function query_params(\stdClass $params): array {
        $values = $params->params ?? [];
        if (is_object($values)) {
            $values = (array) $values;
        }
        if (!is_array($values)) {
            $values = [$values];
        }
        return array_values(array_map(
            static fn($value) => is_scalar($value) || $value === null ? $value : json_encode($value),
            $values
        ));
    }
}
