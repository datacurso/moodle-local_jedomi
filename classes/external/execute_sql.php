<?php
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

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

class execute_sql extends external_api {

    use companion_hmac;

    /** Maximum rows the function will ever return. */
    private const MAX_ROWS = 100;

    /** Hard query timeout in milliseconds, enforced per DB engine (PostgreSQL statement_timeout,
     *  MariaDB max_statement_time, MySQL MAX_EXECUTION_TIME). */
    private const TIMEOUT_MS = 5000;

    /** Rol PostgreSQL de solo lectura bajo el que se ejecuta la consulta (ver cli/setup_sql_sandbox.php). */
    public const SANDBOX_ROLE = 'jedomi_ro';

    /** Tablas (sin prefijo) sin ningún privilegio para el rol sandbox: credenciales, tokens, secretos, sesiones. */
    public const SANDBOX_DENIED_TABLES = [
        'sessions', 'external_tokens', 'external_services_users', 'user_private_key', 'user_password_history',
        'user_password_resets', 'user_preferences', 'oauth2_access_token', 'oauth2_refresh_token', 'oauth2_system_account',
        'auth_oauth2_linked_login', 'config', 'config_plugins', 'config_log', 'mnet_host', 'mnet_application',
        'mnet_session', 'webdav_locks', 'tool_mobile_app_secret', 'local_jedomi_telegram_links', 'local_jedomi_telegram_sessions',
    ];

    /** Tablas con columnas vetadas: el rol sólo recibe SELECT sobre las demás columnas. */
    public const SANDBOX_DENIED_COLUMNS = [
        'user' => ['password', 'secret'],
    ];

    /** Tables whose rows hold credentials/secrets: SELECT * (or alias.*) on them is rejected (2ª capa, sólo aviso temprano). */
    private const SENSITIVE_TABLES = [
        'user', 'config', 'config_plugins', 'config_log', 'sessions', 'external_tokens', 'user_private_key',
        'user_password_history', 'user_password_resets', 'oauth2_access_token', 'oauth2_refresh_token',
        'oauth2_system_account', 'auth_oauth2_linked_login', 'mnet_host', 'mnet_application', 'webdav_locks',
    ];

    /** Column names that are never selectable, whatever the table. */
    private const SENSITIVE_COLUMNS = ['password', 'secret', 'token', 'sid', 'privatekey', 'publickey', 'salt',
        'refreshtoken', 'accesstoken', 'clientsecret', 'hmac_secret', 'api_key', 'apikey', 'passwordresettoken'];

    /** PII column names that must be redacted from logs. */
    private const PII_COLUMNS = ['email', 'firstname', 'lastname', 'idnumber', 'password', 'phone1', 'phone2'];

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sql'             => new external_value(PARAM_RAW,  'SELECT query to execute'),
            'params'          => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Bound parameter'),
                'Optional bound parameters',
                VALUE_DEFAULT,
                []
            ),
            'hmac_signature'  => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp'  => new external_value(PARAM_INT,         'Unix timestamp from X-Jedomi-Timestamp'),
            'payload_hash'    => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of the request body'),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows'         => new external_multiple_structure(
                new external_single_structure([
                    'json_row' => new external_value(PARAM_RAW, 'Row serialised as JSON string'),
                ]),
                'Result rows'
            ),
            'row_count'    => new external_value(PARAM_INT,  'Number of rows returned'),
            'truncated'    => new external_value(PARAM_BOOL, 'True if more rows exist beyond the limit'),
            'execution_ms' => new external_value(PARAM_INT,  'Query wall-clock time in milliseconds'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    /**
     * @param  string $sql
     * @param  array  $params
     * @param  string $hmac_signature
     * @param  int    $hmac_timestamp
     * @param  string $payload_hash
     * @param  bool   $skip_hmac     Skip HMAC verification (used by chatproxy — auth via SPT).
     * @return array
     */
    public static function execute(
        string $sql,
        array  $params,
        string $hmac_signature,
        int    $hmac_timestamp,
        string $payload_hash,
        bool   $skip_hmac = false
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
            error_log(sprintf(
                '[local_jedomi] CRITICAL security event: non-SELECT rejected ip=%s sql_hash=%s',
                getremoteaddr(),
                $sql_hash
            ));
            throw new \moodle_exception('non_select_rejected', 'local_jedomi');
        }

        // --- File exfiltration check (QG-02 equivalent, defense-in-depth) ---
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $sql)) {
            $sql_hash = hash('sha256', $sql);
            error_log(sprintf(
                '[local_jedomi] CRITICAL security event: INTO OUTFILE/DUMPFILE rejected ip=%s sql_hash=%s',
                getremoteaddr(),
                $sql_hash
            ));
            throw new \moodle_exception('non_select_rejected', 'local_jedomi');
        }

        // --- Sensitive data denylist (QA r3 #4: the companion token must not become a DB-admin) ---
        self::reject_sensitive_sql($sql);

        // --- Execute with timeout ---
        $start = microtime(true);
        $transaction = null;
        $reset_sql = null;
        $sandboxed = false;

        try {
            // Server-side hard timeout, per engine (QA r3 #2: the previous MariaDB-only syntax was ignored on
            // PostgreSQL, leaving queries unbounded). PostgreSQL: statement_timeout de sesión, restablecido al terminar
            // (session statement_timeout). MariaDB: session max_statement_time (seconds). MySQL: session MAX_EXECUTION_TIME (ms).
            $family = $DB->get_dbfamily();
            $sandboxed = false;
            if ($family === 'postgres') {
                // Sandbox de privilegios (QA r4): la base de datos impone qué tablas/columnas son legibles. Sin el rol
                // la función NO se ejecuta (fallo cerrado): php local/jedomi/cli/setup_sql_sandbox.php
                $hasrole = $DB->get_field_sql('SELECT 1 FROM pg_roles WHERE rolname = :r', ['r' => self::SANDBOX_ROLE]);
                if (!$hasrole) {
                    throw new \moodle_exception('sql_sandbox_missing', 'local_jedomi', '', self::SANDBOX_ROLE);
                }
                // Ajuste de sesión (no LOCAL): evita una transacción delegada cuyo cursor cancelado dejaría la
                // conexión en estado abortado para la siguiente llamada. Se restablece siempre en la limpieza.
                $DB->execute('SET statement_timeout = ' . (int) self::TIMEOUT_MS);
                $reset_sql = 'SET statement_timeout = 0';
                $DB->execute('SET ROLE "' . self::SANDBOX_ROLE . '"');
                $sandboxed = true;
            } else if ($family === 'mysql') {
                $info = $DB->get_server_info();
                if (stripos($info['description'] ?? '', 'mariadb') !== false) {
                    $DB->execute('SET SESSION max_statement_time = ' . (self::TIMEOUT_MS / 1000));
                    $reset_sql = 'SET SESSION max_statement_time = 0';
                } else {
                    $DB->execute('SET SESSION MAX_EXECUTION_TIME = ' . (int) self::TIMEOUT_MS);
                    $reset_sql = 'SET SESSION MAX_EXECUTION_TIME = 0';
                }
            }

            // Ejecución SIN cursor: en PostgreSQL get_recordset_sql() usa "DECLARE ... CURSOR WITH HOLD" y el
            // statement_timeout de sesión NO cancela ese cursor (verificado: pg_sleep(8) completaba en 8 s). Se envuelve
            // la consulta del modelo en una subconsulta con row_number() como primera columna (clave única que exige
            // get_records_sql, sin colapsar filas duplicadas) y LIMIT propio (sin chocar con un LIMIT del modelo).
            $wrapped = 'SELECT ROW_NUMBER() OVER () AS jedomi_rownum, jedomi_q.* FROM (' . rtrim($sql, "; \t\n\r") . ') jedomi_q LIMIT ' . (self::MAX_ROWS + 1);
            $records = $DB->get_records_sql($wrapped, $params);

            $rows      = [];
            $count     = 0;
            $truncated = false;

            foreach ($records as $record) {
                $count++;
                if ($count > self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                $arr = (array) $record;
                unset($arr['jedomi_rownum']);
                // Rows are returned as JSON strings to avoid Moodle's external
                // API trying to describe every possible column structure.
                $rows[] = ['json_row' => json_encode($arr, JSON_UNESCAPED_UNICODE)];
            }
            if ($sandboxed) {
                $DB->execute('RESET ROLE');
                $sandboxed = false;
            }
            if ($transaction) {
                $transaction->allow_commit();
                $transaction = null;
            }
            if ($reset_sql) {
                $DB->execute($reset_sql);
                $reset_sql = null;
            }

        } catch (\Throwable $e) {
            // Un único manejador para dml_exception, moodle_exception y errores del motor PHP (p. ej. el TypeError
            // que lanza el driver pgsql cuando statement_timeout cancela la consulta: pg_num_rows(false)).
            // Limpieza GARANTIZADA: ninguna transacción ni ajuste de sesión debe sobrevivir a esta función (QA r3).
            if ($sandboxed) {
                try { $DB->execute('RESET ROLE'); } catch (\Throwable $ignored) { /* conexión posiblemente rota */ }
                $sandboxed = false;
            }
            if ($transaction) {
                try { $transaction->rollback($e); } catch (\Throwable $ignored) { /* ya se relanza abajo */ }
                $transaction = null;
            }
            if ($DB->is_transaction_started()) {
                try { $DB->force_transaction_rollback(); } catch (\Throwable $ignored) { /* última defensa */ }
            }
            if ($reset_sql) {
                try { $DB->execute($reset_sql); } catch (\Throwable $ignored) { /* sesión posiblemente rota */ }
                $reset_sql = null;
            }
            $execution_ms = (int) ((microtime(true) - $start) * 1000);
            $dbg = $e->getMessage();
            if (property_exists($e, 'debuginfo') && !empty($e->debuginfo)) {
                $dbg .= ' | ' . $e->debuginfo;
            }
            // Timeout: mensajes de MariaDB / MySQL / PostgreSQL, o cancelación del driver justo al vencer el límite.
            $is_timeout = stripos($dbg, 'max_statement_time') !== false
                       || stripos($dbg, 'Query execution was interrupted') !== false
                       || stripos($dbg, 'maximum statement execution time exceeded') !== false
                       || stripos($dbg, 'statement timeout') !== false
                       || (($e instanceof \TypeError || $e instanceof \dml_exception) && $execution_ms >= self::TIMEOUT_MS - 250);

            $sql_hash = hash('sha256', $sql);
            $extra = $e instanceof \moodle_exception
                ? sprintf(' errorcode="%s" a="%s"', $e->errorcode, json_encode($e->a ?? null, JSON_UNESCAPED_UNICODE)) : '';
            error_log(sprintf(
                '[local_jedomi] DB error: %s class=%s sql_hash=%s execution_ms=%d msg="%s"%s',
                $is_timeout ? 'query_timeout' : 'db_error', get_class($e), $sql_hash, $execution_ms, $e->getMessage(), $extra
            ));
            if ($e instanceof \moodle_exception && in_array($e->errorcode, ['sensitive_data_rejected', 'sql_sandbox_missing'], true)) {
                throw $e;
            }
            if (stripos($dbg, 'permission denied') !== false) {
                // El rol sandbox ha vetado una tabla/columna (incluye fila completa, row_to_json, ::text, subconsultas).
                throw new \moodle_exception('sensitive_data_rejected', 'local_jedomi', '', 'privilegios de BD', $dbg);
            }
            throw new \moodle_exception($is_timeout ? 'query_timeout' : 'execute_sql_error', 'local_jedomi', '', null, $dbg);
        }

        $execution_ms = (int) ((microtime(true) - $start) * 1000);

        // --- Audit log (no raw data, PII column names redacted) ---
        $sql_hash = hash('sha256', $sql);
        error_log(sprintf(
            '[local_jedomi] execute_sql: sql_hash=%s row_count=%d truncated=%s execution_ms=%d sandbox=%s',
            $sql_hash,
            count($rows),
            $truncated ? 'true' : 'false',
            $execution_ms,
            $family === 'postgres' ? self::SANDBOX_ROLE : 'none'
        ));

        return [
            'rows'         => $rows,
            'row_count'    => count($rows),
            'truncated'    => $truncated,
            'execution_ms' => $execution_ms,
        ];
    }

    /**
     * Rejects SELECTs that would expose credentials or secrets:
     *  - any reference to a sensitive column name (password, secret, token, sid, ...), and
     *  - SELECT * / alias.* when a sensitive table appears in FROM/JOIN.
     *
     * @param string $sql
     * @throws \moodle_exception sensitive_data_rejected
     */
    private static function reject_sensitive_sql(string $sql): void {
        // Sin literales de cadena (evita falsos positivos como LIKE '%secret%') y sin COUNT(*).
        $lower = preg_replace("/'(?:[^']|'')*'/", "''", strtolower($sql));
        $lower = preg_replace('/\bcount\s*\(\s*\*\s*\)/', 'count(1)', $lower);
        foreach (self::SENSITIVE_COLUMNS as $col) {
            if (preg_match('/\b' . preg_quote($col, '/') . '\b/', $lower)) {
                self::log_sensitive_rejection($sql, 'column:' . $col);
                throw new \moodle_exception('sensitive_data_rejected', 'local_jedomi', '', $col);
            }
        }
        if (preg_match('/(^|[\s,(])(\w+\.)?\*/', $lower)) {
            foreach (self::SENSITIVE_TABLES as $table) {
                $pattern = '/\b(from|join)\s+(\{' . $table . '\}|' . '[a-z0-9_]*_' . $table . '\b|' . $table . '\b)/';
                if (preg_match($pattern, $lower)) {
                    self::log_sensitive_rejection($sql, 'select_star:' . $table);
                    throw new \moodle_exception('sensitive_data_rejected', 'local_jedomi', '', $table . '.*');
                }
            }
        }
    }

    private static function log_sensitive_rejection(string $sql, string $reason): void {
        error_log(sprintf('[local_jedomi] execute_sql rejected sensitive query reason=%s ip=%s sql_hash=%s',
            $reason, getremoteaddr(), hash('sha256', $sql)));
    }
}
