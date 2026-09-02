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
 *  - Only SELECT / SHOW / DESCRIBE / EXPLAIN statements are allowed.
 *  - Maximum result rows: 1000.
 *  - Maximum execution time: 30 seconds.
 *  - Blocklisted tables: config, sessions, oauth, tokens, private keys.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

/**
 * Agent tool that runs read-only SQL against the Moodle database with
 * role gating, table blocklisting, row limits, and profiler logging.
 */
class sql_tool implements agent_tool {
    /** @var int Agent ID being executed (for profiling). */
    private int $agentid;

    /** @var int Agent creator userid (for permission check). */
    private int $userid;

    /**
     * Constructor.
     *
     * @param int $agentid Agent ID recorded with each profiled query.
     * @param int $userid  Agent creator's user ID, used for the role permission check.
     */
    public function __construct(int $agentid = 0, int $userid = 0) {
        $this->agentid = $agentid;
        $this->userid = $userid;
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
     * @return array Tool definition with the SQL query as the only required parameter.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'execute_sql',
                'description' => 'Execute a read-only SQL query on the Moodle database. Returns'
                    . ' results as JSON array. Only SELECT, SHOW, DESCRIBE, EXPLAIN are allowed.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The SQL query to execute — must start with SELECT, SHOW, DESCRIBE, or EXPLAIN.',
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
     * Allowed roles: admin, manager, teacher. Only SELECT/SHOW/DESCRIBE/EXPLAIN/WITH
     * statements pass validation, blocklisted tables are rejected, and results are
     * capped at 1000 rows.
     *
     * @param \stdClass $params Decoded tool arguments; expects 'query' (or legacy 'sql') with the SQL text.
     * @return string JSON-encoded rows with count and duration, or an error message.
     */
    public function execute(\stdClass $params): string {
        global $DB;

        // ── Permission gate: admin/manager/teacher (matches Telegram behaviour).
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

        // ── Validate read-only ────────────────────────────────────────────────
        $firstword = strtoupper(strtok($query, " \t\n\r\0\x0B"));
        $allowed = ['SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN', 'WITH'];
        if (!in_array($firstword, $allowed, true)) {
            return json_encode([
                'error' => 'Only SELECT, SHOW, DESCRIBE, EXPLAIN and WITH queries are allowed.',
            ]);
        }

        // ── Blocklisted tables ────────────────────────────────────────────────
        $blocklisted = ['config', 'sessions', 'oauth', 'tokens', 'private_key'];
        $queryupper = strtoupper($query);
        foreach ($blocklisted as $tbl) {
            $needle = strtoupper("{{$tbl}}");
            if (strpos($queryupper, $needle) !== false) {
                return json_encode([
                    'error' => "Query references blocked table '{$tbl}'.",
                ]);
            }
        }

        // ── Execute with timeout + profiling ──────────────────────────────────
        $start = microtime(true);

        try {
            // Use a direct ADOdb connection with a query-level timeout hint.
            try {
                $DB->execute("SET SESSION MAX_EXECUTION_TIME = 30000");
            } catch (\Exception $e) {
                // MySQL/MariaDB version may not support this variable — ignore.
            }

            $records = $DB->get_records_sql($query, [], 0, 1000);

            $elapsedms = (microtime(true) - $start) * 1000;
            $rowcount = count($records);

            // ── Log to profiler ──────────────────────────────────────────────
            \local_jedomi\agent\query_profiler::log_query(
                $this->agentid,
                $query,
                $elapsedms,
                $rowcount
            );

            // ── Format result for AI ─────────────────────────────────────────
            $rows = array_map(function (\stdClass $r): array {
                return (array) $r;
            }, array_values($records));

            return json_encode([
                'success' => true,
                'rows' => $rows,
                'row_count' => $rowcount,
                'duration_ms' => round($elapsedms, 2),
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $elapsedms = (microtime(true) - $start) * 1000;

            \local_jedomi\agent\query_profiler::log_query(
                $this->agentid,
                $query,
                $elapsedms,
                0
            );

            return json_encode([
                'error' => $e->getMessage(),
                'duration_ms' => round($elapsedms, 2),
            ]);
        }
    }
}
