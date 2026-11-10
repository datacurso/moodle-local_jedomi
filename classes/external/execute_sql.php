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

use aiprovider_datacurso\local\plugin_settings;
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

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------.

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
    // -------------------------------------------------------------------------.

    /**
     * Validate, authenticate and run a SELECT-only query, returning capped JSON-encoded rows.
     *
     * @param  string $sql            SELECT query to execute.
     * @param  array  $params         Bound parameters for the query.
     * @param  string $hmacsignature HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmactimestamp Unix timestamp from the request header.
     * @param  string $payloadhash   SHA256 hex of the request body.
     * @param  bool   $skiphmac      Skip HMAC verification (in-process callers that already authenticated the user).
     * @return array Rows as JSON strings plus row_count, truncated flag and execution_ms.
     */
    public static function execute(
        string $sql,
        array $params,
        string $hmacsignature,
        int $hmactimestamp,
        string $payloadhash,
        bool $skiphmac = false
    ): array {
        global $USER;

        // ... --- Capability check ---
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/jedomi:companion_api', $context);

        // ... --- Parameter validation ---
        $validated = self::validate_parameters(
            self::execute_parameters(),
            [
                'sql'            => $sql,
                'params'         => $params,
                'hmac_signature' => $hmacsignature,
                'hmac_timestamp' => $hmactimestamp,
                'payload_hash'   => $payloadhash,
            ]
        );

        plugin_settings::require_enabled('local_jedomi');

        $sql             = $validated['sql'];
        $params          = $validated['params'];
        $hmacsignature  = $validated['hmac_signature'];
        $hmactimestamp  = $validated['hmac_timestamp'];
        $payloadhash    = $validated['payload_hash'];

        // ... --- HMAC authentication (must happen before any business logic) ---
        if (!$skiphmac) {
            self::verify_hmac(
                'jedomi_execute_sql',
                $hmactimestamp,
                $payloadhash,
                $hmacsignature,
                self::signed_params($validated)
            );
        }

        // ... --- SELECT only, guard and execution with row cap and timeout (shared with the chat channels) ---
        $result = \local_jedomi\local\query_runner::run($sql, $params, (int) $USER->id, self::MAX_ROWS);

        // Rows are returned as JSON strings so the external API does not have to describe every possible column.
        $rows = array_map(
            static fn(array $row): array => ['json_row' => json_encode($row, JSON_UNESCAPED_UNICODE)],
            $result['rows']
        );

        return [
            'rows'         => $rows,
            'row_count'    => count($rows),
            'truncated'    => $result['truncated'],
            'execution_ms' => $result['execution_ms'],
        ];
    }
}
