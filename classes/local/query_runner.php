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

namespace local_jedomi\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Runs the read-only database query of the AI, shared by the web chat, Telegram and the companion web service.
 *
 * The channels authenticate the caller in their own way (session and chat role, linked Telegram account, or the
 * companion API permission with its signature), then use role_can_query() as the role gate of the chat channels and
 * run() for the checks and the execution, so they cannot diverge.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query_runner {
    /** @var string[] Chat roles that may run database queries. */
    public const QUERY_ROLES = ['admin', 'manager', 'teacher'];

    /** @var int Hard query timeout in milliseconds (MariaDB max_statement_time). */
    private const TIMEOUT_MS = 5000;

    /**
     * Tell whether a chat role may run database queries (the students never can).
     *
     * @param string $role Chat role of the user (admin, manager, teacher or student).
     * @return bool
     */
    public static function role_can_query(string $role): bool {
        return in_array($role, self::QUERY_ROLES, true);
    }

    /**
     * Check and run a read-only query for a user, returning at most the given number of rows without sensitive columns.
     *
     * @param string $sql Query (SELECT only).
     * @param array $params Bound parameters.
     * @param int $userid User the query runs for: courses they do not manage are refused unless they manage every one.
     * @param int $maxrows Maximum rows returned.
     * @return array Rows (arrays), truncated (bool: more rows exist) and execution_ms (int).
     * @throws \moodle_exception non_select_rejected, a sql_guard reason, query_timeout or execute_sql_error.
     */
    public static function run(string $sql, array $params, int $userid, int $maxrows): array {
        global $DB;

        $firsttoken = strtoupper((string) strtok(trim($sql), " \t\n\r"));
        if ($firsttoken !== 'SELECT') {
            // A security event: only the hash of the query is logged, never its text.
            \local_jedomi_log(sprintf(
                '[local_jedomi] CRITICAL security event: non-SELECT rejected ip=%s sql_hash=%s',
                getremoteaddr(),
                hash('sha256', $sql)
            ));
            throw new \moodle_exception('non_select_rejected', 'local_jedomi');
        }

        // Sensitive data, dangerous functions and courses the user does not manage (shared by every channel).
        $rejection = sql_guard::rejection_reason($sql, $userid, $params);
        if ($rejection !== null) {
            \local_jedomi_log(sprintf(
                '[local_jedomi] security event: %s ip=%s sql_hash=%s',
                $rejection,
                getremoteaddr(),
                hash('sha256', $sql)
            ));
            throw new \moodle_exception($rejection, 'local_jedomi');
        }

        $start = microtime(true);
        try {
            // Best effort: MariaDB 10.1+ only; other databases run the query without a server-side timeout.
            try {
                $DB->execute('SET STATEMENT max_statement_time = ' . (self::TIMEOUT_MS / 1000) . ' FOR SELECT 1');
                // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- comment-only catch.
            } catch (\Exception $e) {
                // Not MariaDB: nothing to do.
            }

            // The row cap is applied while reading, so a LIMIT written by the AI never conflicts with ours.
            $recordset = $DB->get_recordset_sql($sql, $params);
            $rows = [];
            $truncated = false;
            foreach ($recordset as $record) {
                if (count($rows) >= $maxrows) {
                    $truncated = true;
                    break;
                }
                $rows[] = sql_guard::filter_row((array) $record);
            }
            $recordset->close();
        } catch (\dml_exception $e) {
            $istimeout = stripos($e->getMessage(), 'max_statement_time') !== false
                || stripos($e->getMessage(), 'Query execution was interrupted') !== false;
            \local_jedomi_log(sprintf(
                '[local_jedomi] DB error: %s sql_hash=%s execution_ms=%d',
                $istimeout ? 'query_timeout' : 'db_error',
                hash('sha256', $sql),
                (int) ((microtime(true) - $start) * 1000)
            ));
            $code = $istimeout ? 'query_timeout' : 'execute_sql_error';
            throw new \moodle_exception($code, 'local_jedomi', '', null, $e->getMessage());
        } catch (\Exception $e) {
            // The message, its parameters and the debug info can quote the query: only the code is logged.
            \local_jedomi_log(sprintf(
                '[local_jedomi] DB error (non-dml): class=%s sql_hash=%s exec_ms=%d%s',
                get_class($e),
                hash('sha256', $sql),
                (int) ((microtime(true) - $start) * 1000),
                $e instanceof \moodle_exception ? sprintf(' errorcode="%s"', $e->errorcode) : ''
            ));
            throw new \moodle_exception('execute_sql_error', 'local_jedomi', '', null, $e->getMessage());
        }

        $executionms = (int) ((microtime(true) - $start) * 1000);
        \local_jedomi_log(sprintf(
            '[local_jedomi] execute_sql: sql_hash=%s row_count=%d truncated=%s execution_ms=%d',
            hash('sha256', $sql),
            count($rows),
            $truncated ? 'true' : 'false',
            $executionms
        ));
        return ['rows' => $rows, 'truncated' => $truncated, 'execution_ms' => $executionms];
    }
}
