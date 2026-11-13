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

    /**
     * @var int Time limit of a query of the chat channels and the companion web service, in milliseconds.
     *
     * Enforced by the database for this query only: a MySQL optimizer hint, a MariaDB SET STATEMENT prefix or, on
     * PostgreSQL, the statement_timeout of the session restored right after the query. SQL Server and Oracle have no
     * such per-query limit, so there only the row cap applies.
     */
    public const TIMEOUT_MS = 5000;

    /**
     * @var array<string, array{0: int, 1: int}> Row limit and time limit (ms) of the queries of each channel.
     *
     * The chat channels answer a person who waits, so they keep few rows and a short time; Telegram shows fewer rows
     * because its messages are short; the agents run unattended, so they get more rows and more time. The AI is told
     * the limits of its channel (see local_jedomi_get_tool_definitions()).
     */
    public const CHANNEL_LIMITS = [
        'web' => [100, self::TIMEOUT_MS],
        'telegram' => [50, self::TIMEOUT_MS],
        'companion' => [100, self::TIMEOUT_MS],
        'agent' => [1000, 30000],
    ];

    /**
     * Maximum rows a query returns in a channel.
     *
     * @param string $channel web, telegram, companion or agent; anything else gets the web chat limit.
     * @return int
     */
    public static function row_limit(string $channel): int {
        return (self::CHANNEL_LIMITS[$channel] ?? self::CHANNEL_LIMITS['web'])[0];
    }

    /**
     * Time limit of a query in a channel, in milliseconds.
     *
     * @param string $channel web, telegram, companion or agent; anything else gets the web chat limit.
     * @return int
     */
    public static function time_limit_ms(string $channel): int {
        return (self::CHANNEL_LIMITS[$channel] ?? self::CHANNEL_LIMITS['web'])[1];
    }

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
     * @param string $channel web, telegram or companion: the query that reaches the database is recorded in the query
     *     profiler with its channel; empty for none.
     * @return array Rows (arrays), truncated (bool: more rows exist) and execution_ms (int).
     * @throws \moodle_exception non_select_rejected, a sql_guard reason, query_timeout or execute_sql_error.
     */
    public static function run(string $sql, array $params, int $userid, int $maxrows, string $channel = ''): array {
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
            $result = self::fetch($sql, $params, $maxrows, self::TIMEOUT_MS);
            $rows = $result['rows'];
            $truncated = $result['truncated'];
        } catch (\dml_exception $e) {
            if ($channel !== '') {
                $failedms = (microtime(true) - $start) * 1000;
                \local_jedomi\agent\query_profiler::log_channel_query($channel, $userid, $sql, $failedms, 0);
            }
            $istimeout = self::is_timeout($e);
            \local_jedomi_log(sprintf(
                '[local_jedomi] DB error: %s sql_hash=%s execution_ms=%d',
                $istimeout ? 'query_timeout' : 'db_error',
                hash('sha256', $sql),
                (int) ((microtime(true) - $start) * 1000)
            ));
            $code = $istimeout ? 'query_timeout' : 'execute_sql_error';
            // The database's own message (an unknown column, a syntax error) is what the model needs to fix its query; it
            // is returned to the model as debug info, never logged here. A read or write exception keeps it apart from
            // the query and its parameters, which the debug info of the exception repeats.
            throw new \moodle_exception($code, 'local_jedomi', '', null, self::database_message($e));
        } catch (\Exception $e) {
            if ($e instanceof \moodle_exception && $e->errorcode === 'sql_construct_rejected') {
                // A limit that cannot be capped: refused before reaching the database.
                \local_jedomi_log(sprintf(
                    '[local_jedomi] security event: sql_construct_rejected ip=%s sql_hash=%s',
                    getremoteaddr(),
                    hash('sha256', $sql)
                ));
                throw $e;
            }
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

        $elapsedms = (microtime(true) - $start) * 1000;
        if ($channel !== '') {
            \local_jedomi\agent\query_profiler::log_channel_query($channel, $userid, $sql, $elapsedms, count($rows));
        }
        $executionms = (int) $elapsedms;
        \local_jedomi_log(sprintf(
            '[local_jedomi] execute_sql: sql_hash=%s row_count=%d truncated=%s execution_ms=%d',
            hash('sha256', $sql),
            count($rows),
            $truncated ? 'true' : 'false',
            $executionms
        ));
        return ['rows' => $rows, 'truncated' => $truncated, 'execution_ms' => $executionms];
    }

    /**
     * Run a read query with a time limit and read at most the given number of rows from the database.
     *
     * It does not check the query: the caller runs sql_guard first. The time limit only applies to this query and
     * the database returns at most $maxrows + 1 rows (the extra row tells that the result was truncated), so neither
     * the time nor the memory depend on the query.
     *
     * @param string $sql Query (SELECT, or WITH followed by a SELECT).
     * @param array $params Bound parameters.
     * @param int $maxrows Maximum rows returned.
     * @param int $timeoutms Time limit in milliseconds.
     * @return array Rows (arrays without sensitive columns) and truncated (bool: more rows exist).
     * @throws \moodle_exception sql_construct_rejected when the rows cannot be capped, or a dml_exception.
     */
    public static function fetch(string $sql, array $params, int $maxrows, int $timeoutms): array {
        global $DB;

        $variant = self::db_variant();
        $query = self::bounded_query($sql, $variant, $timeoutms, $maxrows + 1);
        if ($query === null) {
            throw new \moodle_exception('sql_construct_rejected', 'local_jedomi');
        }

        // PostgreSQL has no per-query hint: the session timeout is set for this query and restored right after it.
        // Inside a transaction that the query aborts the restore fails, but the rollback undoes the SET as well.
        $previoustimeout = null;
        if ($variant === 'postgres') {
            $previoustimeout = (string) $DB->get_field_sql("SELECT current_setting('statement_timeout')");
            $DB->execute('SET statement_timeout = ' . $timeoutms);
        }
        try {
            $recordset = $DB->get_recordset_sql($query['sql'], $params, 0, $query['limitnum']);
            $rows = [];
            $truncated = false;
            try {
                foreach ($recordset as $record) {
                    if (count($rows) >= $maxrows) {
                        $truncated = true;
                        break;
                    }
                    $rows[] = sql_guard::filter_row((array) $record);
                }
            } finally {
                $recordset->close();
            }
        } finally {
            if ($previoustimeout !== null) {
                try {
                    $DB->get_field_sql('SELECT set_config(?, ?, false)', ['statement_timeout', $previoustimeout]);
                    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- comment-only catch.
                } catch (\dml_exception $e) {
                    // Aborted transaction: its rollback restores the timeout.
                }
            }
        }
        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Database variant that decides how the time limit is enforced: mysql, mariadb, postgres or none.
     *
     * @return string
     */
    public static function db_variant(): string {
        global $DB;

        $family = $DB->get_dbfamily();
        if ($family === 'postgres') {
            return 'postgres';
        }
        if ($family !== 'mysql') {
            return 'none';
        }
        if ($DB->get_dbvendor() === 'mariadb') {
            return 'mariadb';
        }
        // The mysqli driver connected to a MariaDB server.
        $info = $DB->get_server_info();
        return stripos((string) ($info['description'] ?? ''), 'mariadb') !== false ? 'mariadb' : 'mysql';
    }

    /**
     * Add the time limit and the row cap to a query accepted by sql_guard, without running it.
     *
     * The time limit: on MySQL the optimizer hint MAX_EXECUTION_TIME right after the main SELECT (the guard rejects
     * hints in the query, so only the runner adds one); on MariaDB a SET STATEMENT max_statement_time prefix; elsewhere
     * nothing (PostgreSQL uses the session timeout, see fetch()).
     *
     * The row cap: without a LIMIT of its own, limitnum asks Moodle to add one; with a numeric LIMIT (or FETCH FIRST),
     * its row count is lowered to $fetchrows. Wrapping the query in a derived table is avoided because valid queries
     * break there (MySQL rejects a derived table with two columns of the same name, as SELECT * over a join gives).
     * Trailing semicolons and comments are removed so the LIMIT appended by Moodle cannot be commented out.
     *
     * @param string $sql Query.
     * @param string $variant Database variant (see db_variant()).
     * @param int $timeoutms Time limit in milliseconds.
     * @param int $fetchrows Maximum rows read from the database.
     * @return array|null Query (sql) and the limit for Moodle (limitnum, 0 when the query has its own), or null when
     *     the query has no main SELECT, a limit that is not a number, or an unterminated string or comment.
     */
    public static function bounded_query(string $sql, string $variant, int $timeoutms, int $fetchrows): ?array {
        $mask = self::mask($sql, $variant === 'mysql' || $variant === 'mariadb');
        if ($mask === null) {
            return null;
        }
        $length = strlen(rtrim($mask, " \t\r\n;"));
        $sql = substr($sql, 0, $length);
        $mask = substr($mask, 0, $length);

        // Words outside parentheses, with their offsets (a word after a dot is a column name).
        $words = [];
        $depth = 0;
        preg_match_all('/[A-Za-z_][A-Za-z0-9_$]*|[()]/', $mask, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$token, $offset]) {
            if ($token === '(' || $token === ')') {
                $depth += $token === '(' ? 1 : -1;
            } else if ($depth === 0 && ($offset === 0 || $mask[$offset - 1] !== '.')) {
                $words[] = [strtolower($token), $offset];
            }
        }
        $select = null;
        $limit = null;
        foreach ($words as [$word, $offset]) {
            if ($word === 'select' && $select === null) {
                $select = $offset;
            } else if ($word === 'limit' || $word === 'fetch') {
                $limit = [$word, $offset];
            }
        }
        if ($select === null) {
            return null;
        }

        $limitnum = $fetchrows;
        if ($limit !== null) {
            [$word, $offset] = $limit;
            // The row count must be a number (or ALL): a placeholder or an expression could not be capped.
            $pattern = $word === 'limit'
                ? '/\G\s+(?:(\d+)\s*,\s*)?(\d+|all)\b(?!\s*,)/i'
                : '/\G\s+(?:first|next)\s+(\d*)\s*rows?\b/i';
            if (!preg_match($pattern, $mask, $m, PREG_OFFSET_CAPTURE, $offset + strlen($word))) {
                return null;
            }
            [$count, $countoffset] = $word === 'limit' ? $m[2] : $m[1];
            if ($count !== '' && (!ctype_digit($count) || (int) $count > $fetchrows)) {
                $sql = substr_replace($sql, (string) $fetchrows, $countoffset, strlen($count));
            }
            $limitnum = 0;
        }

        if ($variant === 'mysql') {
            $sql = substr_replace($sql, ' /*+ MAX_EXECUTION_TIME(' . $timeoutms . ') */', $select + 6, 0);
        } else if ($variant === 'mariadb') {
            $sql = 'SET STATEMENT max_statement_time=' . sprintf('%.3F', $timeoutms / 1000) . ' FOR ' . $sql;
        }
        return ['sql' => $sql, 'limitnum' => $limitnum];
    }

    /**
     * The message of the database in a database exception, without the query and the parameters.
     *
     * @param \dml_exception $e Database exception.
     * @return string
     */
    public static function database_message(\dml_exception $e): string {
        if (($e instanceof \dml_read_exception || $e instanceof \dml_write_exception) && trim((string) $e->error) !== '') {
            return trim((string) $e->error);
        }
        return (string) ($e->debuginfo ?: $e->getMessage());
    }

    /**
     * Tell whether a database error is a query stopped by its time limit.
     *
     * @param \Throwable $e Error.
     * @return bool
     */
    public static function is_timeout(\Throwable $e): bool {
        // The error of the database itself, not the debug info that quotes the query.
        $error = $e instanceof \dml_read_exception ? (string) $e->error : $e->getMessage();
        return (bool) preg_match(
            '/query execution was interrupted|max_statement_time|maximum statement execution time|statement timeout/i',
            $error
        );
    }

    /**
     * Copy of a query with its string literals and quoted names filled with their quote and its comments blanked, of
     * the same length, so keywords and parentheses can be found by offset.
     *
     * @param string $sql Query.
     * @param bool $mysql Whether MySQL lexing applies (backslash escapes, double-quoted strings, backquotes, "#").
     * @return string|null Mask, or null with an unterminated string, quoted name or comment.
     */
    private static function mask(string $sql, bool $mysql): ?string {
        $quotes = $mysql ? "'\"\x60" : "'\"";
        $length = strlen($sql);
        $mask = '';
        $i = 0;
        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    return null;
                }
                $mask .= str_repeat(' ', $end + 2 - $i);
                $i = $end + 2;
                continue;
            }
            // In MySQL "--" starts a comment only when whitespace (or the end) follows it, and "#" starts one.
            $linecomment = ($mysql && $char === '#') || ($char === '-' && $next === '-'
                && (!$mysql || strpos(" \t\r\n", $sql[$i + 2] ?? ' ') !== false));
            if ($linecomment) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $mask .= str_repeat(' ', $end - $i);
                $i = $end;
                continue;
            }
            if (strpos($quotes, $char) !== false) {
                $j = $i + 1;
                while (true) {
                    if ($j >= $length) {
                        return null;
                    }
                    if ($mysql && $char !== "\x60" && $sql[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($sql[$j] === $char) {
                        if (($sql[$j + 1] ?? '') !== $char) {
                            break;
                        }
                        $j++;
                    }
                    $j++;
                }
                $mask .= str_repeat($char, $j + 1 - $i);
                $i = $j + 1;
                continue;
            }
            $mask .= $char;
            $i++;
        }
        return $mask;
    }
}
