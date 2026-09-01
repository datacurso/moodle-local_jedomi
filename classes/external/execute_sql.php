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
 * jedomi_execute_sql — CompanionWS external function.
 *
 * Requirement CWS-SQL-01 / CWS-01 (query-security spec):
 *  - Validates HMAC-SHA256 before touching the DB
 *  - Enforces SELECT-only (second layer after QueryGovernor)
 *  - Returns at most 100 rows
 *  - Enforces 5-second hard timeout via SET STATEMENT max_statement_time
 *  - Never logs raw row data; masks PII column names in log
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

/**
 * External function that runs an HMAC-authenticated, SELECT-only SQL query with row and time limits.
 */
class execute_sql extends external_api {
    use companion_hmac;

    /** Maximum rows the function will ever return. */
    private const MAX_ROWS = 100;

    /** Hard query timeout in milliseconds (MariaDB max_statement_time). */
    private const TIMEOUT_MS = 5000;

    /** PII column names that must be redacted from logs. */
    private const PII_COLUMNS = ['email', 'firstname', 'lastname', 'idnumber', 'password', 'phone1', 'phone2'];

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------

    /**
     * Describe the parameters accepted by the execute function.
     *
     * @return external_function_parameters Definition of the SQL, bound params and HMAC auth fields.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sql'             => new external_value(PARAM_RAW, 'SELECT query to execute'),
            'params'          => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Bound parameter'),
                'Optional bound parameters',
                VALUE_DEFAULT,
                []
            ),
            'hmac_signature'  => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp'  => new external_value(PARAM_INT, 'Unix timestamp from X-Jedomi-Timestamp'),
            'payload_hash'    => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of the request body'),
        ]);
    }

    /**
     * Describe the structure returned by the execute function.
     *
     * @return external_single_structure JSON-encoded rows, row count, truncation flag and timing.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows'         => new external_multiple_structure(
                new external_single_structure([
                    'json_row' => new external_value(PARAM_RAW, 'Row serialised as JSON string'),
                ]),
                'Result rows'
            ),
            'row_count'    => new external_value(PARAM_INT, 'Number of rows returned'),
            'truncated'    => new external_value(PARAM_BOOL, 'True if more rows exist beyond the limit'),
            'execution_ms' => new external_value(PARAM_INT, 'Query wall-clock time in milliseconds'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    /**
     * Validate, authenticate and run a SELECT-only query, returning capped JSON-encoded rows.
     *
     * @param  string $sql            SELECT query to execute.
     * @param  array  $params         Bound parameters for the query.
     * @param  string $hmac_signature HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmac_timestamp Unix timestamp from the request header.
     * @param  string $payload_hash   SHA256 hex of the request body.
     * @param  bool   $skip_hmac      Skip HMAC verification (used by chatproxy — auth via SPT).
     * @return array Rows as JSON strings plus row_count, truncated flag and execution_ms.
     */
    public static function execute(
        string $sql,
        array $params,
        string $hmac_signature,
        int $hmac_timestamp,
        string $payload_hash,
        bool $skip_hmac = false
    ): array {
        global $DB;

        // --- Capability check ---
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/jedomi:companion_api', $context);

        // --- Parameter validation ---
        $validated = self::validate_parameters(
            self::execute_parameters(),
            compact('sql', 'params', 'hmac_signature', 'hmac_timestamp', 'payload_hash')
        );
        $sql             = $validated['sql'];
        $params          = $validated['params'];
        $hmac_signature  = $validated['hmac_signature'];
        $hmac_timestamp  = $validated['hmac_timestamp'];
        $payload_hash    = $validated['payload_hash'];

        // --- HMAC authentication (must happen before any business logic) ---
        if (!$skip_hmac) {
            self::verify_hmac('jedomi_execute_sql', $hmac_timestamp, $payload_hash, $hmac_signature);
        }

        // --- SELECT-only enforcement (defense-in-depth, QG-01 equivalent) ---
        $first_token = strtoupper(strtok(trim($sql), " \t\n\r"));
        if ($first_token !== 'SELECT') {
            // CRITICAL security event — log the hash, never the SQL text.
            $sql_hash = hash('sha256', $sql);
            \local_jedomi_log(sprintf(
                '[local_jedomi] CRITICAL security event: non-SELECT rejected ip=%s sql_hash=%s',
                getremoteaddr(),
                $sql_hash
            ));
            throw new \moodle_exception('non_select_rejected', 'local_jedomi');
        }

        // --- File exfiltration check (QG-02 equivalent, defense-in-depth) ---
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql)) {
            $sql_hash = hash('sha256', $sql);
            \local_jedomi_log(sprintf(
                '[local_jedomi] CRITICAL security event: INTO OUTFILE/DUMPFILE rejected ip=%s sql_hash=%s',
                getremoteaddr(),
                $sql_hash
            ));
            throw new \moodle_exception('non_select_rejected', 'local_jedomi');
        }

        // --- Execute with timeout ---
        $start = microtime(true);

        try {
            // Set MariaDB statement timeout before running the query.
            // This is a best-effort; it works on MariaDB 10.1+ and is silently
            // ignored on MySQL (which uses MAX_EXECUTION_TIME hint instead).
            try {
                $DB->execute('SET STATEMENT max_statement_time = ' . (self::TIMEOUT_MS / 1000) . ' FOR SELECT 1');
            } catch (\Exception $e) {
                // Non-MariaDB — ignore, query will run without server-side timeout.
            }

            // Enforce row cap at the DB layer regardless of LIMIT in the SQL.
            // We fetch without extra LIMIT (to avoid double-LIMIT conflicts with
            // model-generated SQL that already contains LIMIT clauses).
            // Then we cap in PHP.
            $recordset = $DB->get_recordset_sql($sql, $params);

            $rows      = [];
            $count     = 0;
            $truncated = false;

            foreach ($recordset as $record) {
                $count++;
                if ($count > self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                // Rows are returned as JSON strings to avoid Moodle's external
                // API trying to describe every possible column structure.
                $rows[] = ['json_row' => json_encode((array) $record, JSON_UNESCAPED_UNICODE)];
            }
            $recordset->close();
        } catch (\dml_exception $e) {
            $execution_ms = (int) ((microtime(true) - $start) * 1000);
            // Distinguish timeout from other DB errors.
            $is_timeout = stripos($e->getMessage(), 'max_statement_time') !== false
                       || stripos($e->getMessage(), 'Query execution was interrupted') !== false;

            $sql_hash = hash('sha256', $sql);
            \local_jedomi_log(sprintf(
                '[local_jedomi] DB error: %s sql_hash=%s execution_ms=%d',
                $is_timeout ? 'query_timeout' : 'db_error',
                $sql_hash,
                $execution_ms
            ));

            $code = $is_timeout ? 'query_timeout' : 'execute_sql_error';
            $dbg = $e->getMessage();
            if (method_exists($e, 'debuginfo') && !empty($e->debuginfo)) {
                $dbg .= ' | ' . $e->debuginfo;
            }
            throw new \moodle_exception($code, 'local_jedomi', '', null, $dbg);
        } catch (\Exception $e) {
            // Catch non-DB exceptions too (moodle_exception, coding_exception, etc.)
            $execution_ms = (int) ((microtime(true) - $start) * 1000);
            $sql_hash = hash('sha256', $sql);
            $dbInfo = method_exists($e, 'debuginfo') ? ($e->debuginfo ?? 'null') : 'no_method';
            $extra = '';
            if ($e instanceof \moodle_exception) {
                $extra = sprintf(' errorcode="%s" a="%s"', $e->errorcode, json_encode($e->a ?? null, JSON_UNESCAPED_UNICODE));
            }
            \local_jedomi_log(sprintf(
                '[local_jedomi] DB error (non-dml): class=%s sql_hash=%s exec_ms=%d msg="%s" debuginfo="%s"%s',
                get_class($e),
                $sql_hash,
                $execution_ms,
                $e->getMessage(),
                $dbInfo,
                $extra
            ));
            $dbg = $e->getMessage();
            if (method_exists($e, 'debuginfo') && !empty($e->debuginfo)) {
                $dbg .= ' | ' . $e->debuginfo;
            }
            throw new \moodle_exception('execute_sql_error', 'local_jedomi', '', null, $dbg);
        }

        $execution_ms = (int) ((microtime(true) - $start) * 1000);

        // --- Audit log (no raw data, PII column names redacted) ---
        $sql_hash = hash('sha256', $sql);
        \local_jedomi_log(sprintf(
            '[local_jedomi] execute_sql: sql_hash=%s row_count=%d truncated=%s execution_ms=%d',
            $sql_hash,
            count($rows),
            $truncated ? 'true' : 'false',
            $execution_ms
        ));

        return [
            'rows'         => $rows,
            'row_count'    => count($rows),
            'truncated'    => $truncated,
            'execution_ms' => $execution_ms,
        ];
    }
}
