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
 * jedomi_extract_schema — CompanionWS external function.
 *
 * Requirement CWS-SCHEMA-01:
 *  - Returns the complete Moodle DB schema in the same JSON format as moodle_schema.json
 *  - Handles 515+ tables without timeout (120s limit)
 *  - Optional row counts via SHOW TABLE STATUS (adds ~2s max)
 *  - Optional domain filter (table name prefix matching)
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
 * External function that extracts the full Moodle database schema as JSON.
 */
class extract_schema extends external_api {
    use companion_hmac;

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------

    /**
     * Describe the parameters accepted by the execute function.
     *
     * @return external_function_parameters Row-count flag, domain filters and HMAC auth fields.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'include_row_counts' => new external_value(
                PARAM_BOOL,
                'Include approximate row counts from SHOW TABLE STATUS (default false)',
                VALUE_DEFAULT,
                false
            ),
            'domains' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Domain name filter'),
                'If provided, return only tables whose names contain one of these strings',
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
     * @return external_single_structure Schema JSON, table count, timestamps and timing.
     */
    public static function execute_returns(): external_single_structure {
        // The full schema is too dynamic to describe column-by-column in Moodle's
        // external API type system.  We return it as a single JSON string.
        return new external_single_structure([
            'schema_json'   => new external_value(PARAM_RAW, 'Full schema serialised as JSON'),
            'total_tables'  => new external_value(PARAM_INT, 'Number of tables in the response'),
            'generated_at'  => new external_value(PARAM_TEXT, 'ISO 8601 generation timestamp'),
            'moodle_version' => new external_value(PARAM_TEXT, 'Moodle version string'),
            'execution_ms'  => new external_value(PARAM_INT, 'Extraction time in milliseconds'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    /**
     * Authenticate the request and build the database schema from INFORMATION_SCHEMA.
     *
     * @param  bool   $include_row_counts Include approximate row counts from SHOW TABLE STATUS.
     * @param  array  $domains            Substring filters applied to table names; empty for all tables.
     * @param  string $hmac_signature     HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmac_timestamp     Unix timestamp from the request header.
     * @param  string $payload_hash       SHA256 hex of the request body.
     * @return array Schema JSON string, total table count, generation timestamp, version and timing.
     */
    public static function execute(
        bool $include_row_counts,
        array $domains,
        string $hmac_signature,
        int $hmac_timestamp,
        string $payload_hash
    ): array {
        global $DB, $CFG;

        // --- Capability check ---
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/jedomi:companion_api', $context);

        // --- Parameter validation ---
        $validated = self::validate_parameters(
            self::execute_parameters(),
            compact('include_row_counts', 'domains', 'hmac_signature', 'hmac_timestamp', 'payload_hash')
        );
        $include_row_counts = $validated['include_row_counts'];
        $domains            = $validated['domains'];
        $hmac_signature     = $validated['hmac_signature'];
        $hmac_timestamp     = $validated['hmac_timestamp'];
        $payload_hash       = $validated['payload_hash'];

        // --- HMAC authentication ---
        self::verify_hmac('jedomi_extract_schema', $hmac_timestamp, $payload_hash, $hmac_signature);

        // 120-second timeout per spec (515+ tables can be slow).
        @set_time_limit(120);

        $start = microtime(true);

        // --- Fetch table list from INFORMATION_SCHEMA ---
        $dbname = $CFG->dbname;

        $tables_rs = $DB->get_recordset_sql(
            "SELECT TABLE_NAME, TABLE_COMMENT
               FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = ?
                AND TABLE_TYPE = 'BASE TABLE'
              ORDER BY TABLE_NAME",
            [$dbname]
        );

        // --- Optional: row counts from SHOW TABLE STATUS ---
        $row_counts = [];
        if ($include_row_counts) {
            $status_rs = $DB->get_recordset_sql("SHOW TABLE STATUS FROM `{$dbname}`");
            foreach ($status_rs as $row) {
                $row_counts[$row->name] = (int) $row->rows;
            }
            $status_rs->close();
        }

        // --- Build schema ---
        $schema = [];
        foreach ($tables_rs as $table_row) {
            $table_name = $table_row->table_name;

            // Domain filter (substring match on table name).
            if (!empty($domains)) {
                $match = false;
                foreach ($domains as $domain) {
                    if (stripos($table_name, $domain) !== false) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }

            $schema[$table_name] = self::describe_table($DB, $dbname, $table_name, $table_row->table_comment ?? '');

            if ($include_row_counts && isset($row_counts[$table_name])) {
                $schema[$table_name]['row_count'] = $row_counts[$table_name];
            }
        }
        $tables_rs->close();

        $execution_ms  = (int) ((microtime(true) - $start) * 1000);
        $total_tables  = count($schema);
        $generated_at  = date('c'); // ISO 8601

        // Moodle version — $CFG->release is populated by Moodle's bootstrap.
        $moodle_version = $CFG->release ?? 'unknown';

        $response = [
            'generated_at'   => $generated_at,
            'moodle_version' => $moodle_version,
            'total_tables'   => $total_tables,
            'tables'         => $schema,
        ];

        \local_jedomi_log(sprintf(
            '[local_jedomi] extract_schema: total_tables=%d include_row_counts=%s execution_ms=%d',
            $total_tables,
            $include_row_counts ? 'true' : 'false',
            $execution_ms
        ));

        return [
            'schema_json'    => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'total_tables'   => $total_tables,
            'generated_at'   => $generated_at,
            'moodle_version' => $moodle_version,
            'execution_ms'   => $execution_ms,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Describe one table: its columns (type, nullability, keys) and foreign keys.
     *
     * @param  \moodle_database $DB         Database connection used for the INFORMATION_SCHEMA queries.
     * @param  string           $dbname     Database (schema) name to inspect.
     * @param  string           $table_name Table to describe.
     * @param  string           $comment    Table comment from INFORMATION_SCHEMA.TABLES.
     * @return array Table comment, column definitions and foreign key list.
     */
    private static function describe_table(\moodle_database $DB, string $dbname, string $table_name, string $comment): array {
        // Columns.
        $cols_rs = $DB->get_recordset_sql(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, COLUMN_KEY, EXTRA
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME   = ?
              ORDER BY ORDINAL_POSITION",
            [$dbname, $table_name]
        );

        $columns = [];
        foreach ($cols_rs as $col) {
            $columns[$col->column_name] = [
                'type'     => $col->column_type,
                'nullable' => ($col->is_nullable === 'YES'),
                'default'  => $col->column_default,
                'comment'  => $col->column_comment ?: null,
                'key'      => $col->column_key ?: null, // PRI, UNI, MUL
                'extra'    => $col->extra ?: null, // auto_increment, etc.
            ];
        }
        $cols_rs->close();

        // Foreign keys.
        $fks_rs = $DB->get_recordset_sql(
            "SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
               FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
               JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                    ON tc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
                   AND tc.TABLE_SCHEMA      = kcu.TABLE_SCHEMA
                   AND tc.TABLE_NAME        = kcu.TABLE_NAME
              WHERE kcu.TABLE_SCHEMA           = ?
                AND kcu.TABLE_NAME             = ?
                AND tc.CONSTRAINT_TYPE         = 'FOREIGN KEY'
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL",
            [$dbname, $table_name]
        );

        $foreign_keys = [];
        foreach ($fks_rs as $fk) {
            $foreign_keys[] = [
                'column'            => $fk->column_name,
                'references_table'  => $fk->referenced_table_name,
                'references_column' => $fk->referenced_column_name,
            ];
        }
        $fks_rs->close();

        return [
            'comment'     => $comment,
            'columns'     => $columns,
            'foreign_keys' => $foreign_keys,
        ];
    }
}
