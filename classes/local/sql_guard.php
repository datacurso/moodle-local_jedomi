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

/**
 * Shared guard for the read-only database query offered to the AI in every channel.
 *
 * The web chat, Telegram and the agents all call it before running a query. It applies four layers:
 *
 * 1. For every user (site administrators included): functions and clauses that read or write server files, pause or
 *    lock the database (row locking clauses such as FOR UPDATE and LOCK IN SHARE MODE included), reach other databases
 *    or reveal server variables or information (version, account, database, connection) are rejected
 *    (sql_function_rejected), and so are executable comments and optimizer hints (MySQL and MariaDB), which the
 *    database runs although they look like comments.
 * 2. For every user: anything the guard and the database could read differently is rejected (sql_construct_rejected):
 *    control characters, placeholders Moodle would bind at another place than the guard reads them (inside a comment
 *    or a string) and, outside MySQL, quoting the guard does not follow (double-quoted identifiers, dollar quoting,
 *    escape strings, backslashes and nested comments on PostgreSQL).
 * 3. For every user: tables and columns that hold credentials, keys, tokens, site configuration or sessions are
 *    rejected (sql_sensitive_rejected); columns are recognised by name in any table (see is_sensitive_column()).
 * 4. For a user who does not manage every course (a teacher), an allowlist instead of a denylist. The query must be a
 *    single SELECT whose every token fits the small grammar of sql_select_parser (sql_construct_rejected), HAVING
 *    included: no CASE,
 *    OR, NOT, subqueries, UNION, comments, quoted identifiers, variables or functions other than COUNT, SUM, AVG, MIN,
 *    MAX, ROUND and COALESCE. Every table it reads must be either:
 *    - course data (the course table, a table with a course column, a known child table of one, the context table or a
 *      table tied to a context), which must then be limited to courses the user manages by plain conditions ANDed in
 *      the WHERE clause or in the join of the table (sql_course_rejected and sql_course_unfiltered_rejected); or
 *    - one of ALLOWED_TABLES, which hold no personal data; or
 *    - the user table, read only through USER_COLUMNS (identity columns, never with "*") and only for users tied to
 *      a course table limited to the managed courses by a join on the user id, such as
 *      "JOIN {user_enrolments} ue ON ue.userid = u.id" with the enrolment limited to the course
 *      (sql_user_column_rejected and sql_table_not_allowed).
 *    Any other table (messages, notifications, user preferences, other databases...) is rejected
 *    (sql_table_not_allowed). Tables, aliases and conditions come from the tokens, never from text patterns.
 *
 * Site administrators and users who manage every course keep reading every table except the sensitive ones.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sql_guard {
    /** @var string[] Tables (without prefix) whose rows hold credentials, keys, tokens, configuration or sessions. */
    private const SENSITIVE_TABLES = [
        'config', 'config_plugins', 'config_log', 'sessions',
        'external_tokens', 'external_services_users', 'user_private_key',
        'user_password_history', 'user_password_resets', 'user_devices',
        'oauth2_issuer', 'oauth2_endpoint', 'oauth2_system_account', 'oauth2_access_token', 'oauth2_refresh_token',
        'oauth2_user_field_mapping', 'auth_oauth2_linked_login',
        'messageinbound_datakeys', 'repository_instance_config', 'portfolio_instance_config',
        'local_jedomi_telegram_links',
    ];

    /**
     * @var string[] Table name prefixes (without the database prefix) that are always sensitive. Every table of Jedomi,
     *      present or future, is one: they hold Telegram sessions, pending actions, the hidden instructions of agents,
     *      feedback and the query log, which are read only through their own tools and pages.
     */
    private const SENSITIVE_TABLE_PREFIXES = ['oauth2_', 'local_jedomi_'];

    /** @var string[] Column names that hold credentials or secrets in any table. */
    private const SENSITIVE_COLUMNS = [
        'password', 'secret', 'clientsecret', 'privatetoken', 'token', 'sid', 'datakey',
        'passwordsaltmain', 'sessionkey', 'linkcode', 'link_code', 'enrolmentkey',
    ];

    /**
     * @var string Pattern of the column names that hold credentials or secrets in any table, core or plugin: names ending
     *      in secret, token, password, passwd, privatekey, apikey, sessionkey or salt (such as refreshtoken, consumersecret,
     *      approval_token, guestpassword or servicesalt), optionally followed by "key". Names that only contain the word,
     *      such as tokentype, accesstokenurl or prompttokens, are not matched.
     */
    private const SENSITIVE_COLUMN_PATTERN =
        '/(?:secret|token|passw(?:or)?d|private_?key|api_?key|session_?key|salt)(?:_?key)?$/';

    /** @var string[] Column names that match SENSITIVE_COLUMN_PATTERN but hold no secret: a token counter and a flag. */
    private const HARMLESS_COLUMNS = ['completiontoken', 'usepassword'];

    /** @var string Backquote character MySQL uses to quote identifiers. */
    private const BACKQUOTE = "\x60";

    /** @var string[] Column names that identify a course. */
    private const COURSE_COLUMNS = ['courseid', 'course', 'course_id'];

    /**
     * @var array<string, array{0: string, 1: string}> Course data tables without a course column: the column that
     *      points to their parent table, and that parent table (whose id it holds).
     */
    private const CHILD_TABLES = [
        'user_enrolments' => ['enrolid', 'enrol'],
        'grade_grades' => ['itemid', 'grade_items'],
        'grade_grades_history' => ['itemid', 'grade_items'],
        'assign_submission' => ['assignment', 'assign'],
        'assign_grades' => ['assignment', 'assign'],
        'assign_user_flags' => ['assignment', 'assign'],
        'assign_user_mapping' => ['assignment', 'assign'],
        'forum_posts' => ['discussion', 'forum_discussions'],
        'quiz_attempts' => ['quiz', 'quiz'],
        'quiz_grades' => ['quiz', 'quiz'],
        'groups_members' => ['groupid', 'groups'],
        'course_modules_completion' => ['coursemoduleid', 'course_modules'],
    ];

    /**
     * @var string[] Tables without course data that a user who does not manage every course may read, because they hold
     *      no personal data: role (role names and archetypes, shown to every teacher in the participants page), modules
     *      (the installed activity types) and course_categories (the category tree shown in the course listing). Tables
     *      with a course column (such as scale or grade_letters) are course data and follow the course check instead.
     */
    private const ALLOWED_TABLES = ['role', 'modules', 'course_categories'];

    /** @var string[] Columns of the user table that a user who does not manage every course may read: the identity. */
    private const USER_COLUMNS = ['id', 'firstname', 'lastname', 'middlename', 'alternatename', 'firstnamephonetic',
        'lastnamephonetic'];

    /**
     * @var string[] Patterns of the functions and clauses rejected for every user: they read or write server files,
     *      pause or lock the database, reach other databases or reveal server variables.
     */
    private const BLOCKED_FUNCTIONS = ['load_file', 'sleep', 'benchmark', 'get_lock', 'release_lock', 'release_all_locks',
        'pg_sleep\w*', 'pg_read_file', 'pg_read_binary_file', 'lo_import', 'lo_export', 'dblink\w*',
        'current_user', 'session_user', 'system_user'];

    /**
     * @var string[] Functions rejected for every user when called (followed by "("), because they reveal the database
     *      server: its version, account, database, schema, connection, settings or address. Without "(" these names are
     *      ordinary columns (such as version) and are accepted.
     */
    private const SERVER_INFO_FUNCTIONS = ['version', 'user', 'database', 'schema', 'connection_id', 'current_database',
        'current_schema', 'current_schemas', 'current_setting', 'inet_server_addr', 'inet_server_port', 'pg_backend_pid'];

    /**
     * @var string[] Patterns of the row locking clauses rejected for every user, site administrators and users who manage
     *      every course included (SEC-JD-037): FOR UPDATE, FOR NO KEY UPDATE, FOR SHARE, FOR KEY SHARE, LOCK IN SHARE
     *      MODE, and the NOWAIT and SKIP LOCKED options. A read-only query never needs to lock rows, and a lock held
     *      until the timeout blocks the writes of the whole site.
     */
    private const LOCKING_CLAUSES = ['for\s+(?:no\s+key\s+)?update', 'for\s+(?:key\s+)?share', 'lock\s+in\s+share\s+mode',
        'nowait', 'skip\s+locked'];

    /** @var string[] Patterns of the placeholders Moodle binds, as lib/dml/moodle_database.php counts them. */
    private const PLACEHOLDERS = ['/\?/', '/(?<!:):[a-z][a-z0-9_]*/', '/\$[1-9][0-9]*/'];

    /**
     * @var int Longest query read, in bytes (RISK-004). Far above any query of the allowed subset, and below the length
     *      at which the PCRE JIT stack of a default PHP build runs out on a single string literal (about 8150 bytes), so
     *      a query that fits is read whole; a longer one is rejected before it is read. A regular expression that still
     *      fails rejects the query as well.
     */
    public const MAX_QUERY_LENGTH = 8000;

    /**
     * Check a query and return why it is rejected, or null when it can run.
     *
     * For a user who does not manage every course, the query must fit the grammar of sql_select_parser, and every table
     * it reads must be course data limited to courses the user manages (with plain conditions ANDed in the WHERE clause
     * or in the join of the table), a table of ALLOWED_TABLES, or the user table read through USER_COLUMNS and tied to
     * such a limited course table.
     *
     * @param string $sql Query as written by the AI (Moodle placeholders or prefixed table names).
     * @param int $userid User the query runs for; 0 skips the course check.
     * @param array $params Query parameters, so a course given as a parameter is checked as well.
     * @return string|null Reason of the rejection (language string identifier) or null when allowed.
     */
    public static function rejection_reason(string $sql, int $userid = 0, array $params = []): ?string {
        global $DB;
        $family = $DB->get_dbfamily();
        $unreadable = self::unreadable_rejection($sql);
        if ($unreadable !== null) {
            return $unreadable;
        }
        $code = self::lex($sql, $family);
        if ($code === null) {
            return 'sql_construct_rejected';
        }
        $code = strtolower($code);
        if (self::has_executable_comment($sql) || self::calls_blocked_function($code)) {
            return 'sql_function_rejected';
        }
        $syntax = self::syntax_rejection($sql, $family);
        if ($syntax !== null) {
            return $syntax;
        }
        foreach (self::referenced_tables($code) as $table) {
            if (self::is_sensitive_table($table)) {
                return 'sql_sensitive_rejected';
            }
        }
        foreach (self::identifiers($code) as $identifier) {
            if (self::is_sensitive_column($identifier)) {
                return 'sql_sensitive_rejected';
            }
        }
        if ($userid > 0 && !self::manages_every_course($userid)) {
            return self::course_rejection($sql, $userid, $params);
        }
        return null;
    }

    /**
     * Reason to reject a query that the guard and the database could read differently, or null.
     *
     * Rejects what unreadable_rejection() rejects (too long, a carriage return) and a query the regular expressions
     * cannot read; control characters other than tab and line feed; a double dash followed by a byte that is not ASCII
     * (whether it starts a comment depends on the character set); a different number of placeholders in the query than
     * in its code (Moodle counts every placeholder, also those inside comments and strings, so the parameters would
     * shift); and, outside MySQL, the quoting the guard does not follow: double quotes and backslashes everywhere, and on
     * PostgreSQL dollar quoting, escape strings and nested comments (and brackets on SQL Server, q-quoting on Oracle).
     *
     * @param string $sql Query.
     * @param string $family Database family, as moodle_database::get_dbfamily() returns it.
     * @return string|null sql_construct_rejected, a reason of unreadable_rejection() or null.
     */
    public static function syntax_rejection(string $sql, string $family): ?string {
        $rejected = 'sql_construct_rejected';
        $unreadable = self::unreadable_rejection($sql);
        if ($unreadable !== null) {
            return $unreadable;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|--[\x80-\xFF]/', $sql) !== 0) {
            return $rejected;
        }
        if (
            ($family !== 'mysql' && strpbrk($sql, '"\\') !== false)
                || ($family === 'postgres' && strpos($sql, '$') !== false)
                || ($family === 'mssql' && strpos($sql, '[') !== false)
        ) {
            return $rejected;
        }
        $comments = [];
        $code = self::lex($sql, $family, $comments);
        if ($code === null) {
            return $rejected;
        }
        if ($family === 'postgres') {
            if (preg_match("/(?<![A-Za-z0-9_])[eE]''/", $code) !== 0) {
                return $rejected;
            }
            foreach ($comments as $comment) {
                if (strpos(substr($comment, 2), '/*') !== false) {
                    return $rejected;
                }
            }
        }
        if ($family === 'oracle' && preg_match("/(?<![A-Za-z0-9_])[nN]?[qQ]''/", $code) !== 0) {
            return $rejected;
        }
        foreach (self::PLACEHOLDERS as $pattern) {
            $count = preg_match_all($pattern, $sql);
            if ($count === false || $count !== preg_match_all($pattern, $code)) {
                return $rejected;
            }
        }
        return null;
    }

    /**
     * Tell whether a query calls a function or uses a clause rejected for every user, row locking clauses included.
     *
     * @param string $code Lowercase query without literals and comments.
     * @return bool
     */
    private static function calls_blocked_function(string $code): bool {
        $code = str_replace(self::BACKQUOTE, ' ', $code);
        $pattern = '/\b(?:' . implode('|', self::BLOCKED_FUNCTIONS) . ')\b|\b(?:' . implode('|', self::SERVER_INFO_FUNCTIONS)
            . ')\s*\(|\b(?:' . implode('|', self::LOCKING_CLAUSES) . ')\b|\binto\s+(?:outfile|dumpfile)\b|@@|(?:^|;)\s*copy\b/';
        return preg_match($pattern, $code) !== 0;
    }

    /**
     * Tell whether a query holds an executable comment or optimizer hint (MySQL "/*!", MariaDB "/*M!", "/*+"), which
     * the database runs although it looks like a comment. Searched in the whole query, strings included.
     *
     * @param string $sql Query.
     * @return bool
     */
    private static function has_executable_comment(string $sql): bool {
        return preg_match('/\/\*\s*(?:m\s*)?[!+]/i', $sql) !== 0;
    }

    /**
     * Pattern of the quoted parts of a query as the guard reads them: string literals, quoted identifiers and comments.
     *
     * The whole match is the only capturing group, so preg_split() with PREG_SPLIT_DELIM_CAPTURE returns the code at even
     * positions and the quoted parts at odd ones. See lex() for the quoting each database family follows.
     *
     * @param string $family Database family, as moodle_database::get_dbfamily() returns it.
     * @return string
     */
    public static function quoted_parts_pattern(string $family): string {
        if ($family === 'mysql') {
            return '/(\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|\x60(?:[^\x60]|\x60\x60)*\x60'
                . '|--(?=[ \t\r\n]|$)[^\n]*|#[^\n]*|\/\*.*?\*\/)/s';
        }
        return '/(\'(?:[^\']|\'\')*\'|--[^\n]*|\/\*.*?\*\/)/s';
    }

    /**
     * Read a query from left to right, replacing its string literals by empty strings, its comments by a space and the
     * characters of its quoted identifiers that are not part of a name by "_", so a quote inside a comment or an
     * identifier, or a comment mark inside a string, cannot hide code from the guard.
     *
     * In MySQL "--" starts a comment only when whitespace follows it ("--1" is a double minus), "#" starts one, strings
     * may use double quotes and backslash escapes, and backquotes quote identifiers. Elsewhere "--" always starts a
     * comment; the double quotes and backslashes that other databases read differently are rejected by
     * syntax_rejection(). Reading more code than the database does keeps the guard on the safe side.
     *
     * A regular expression that fails (a backtracking or JIT stack limit) returns null, never a query read in part, so
     * the caller rejects the query instead of checking less code than the database runs (RISK-004).
     *
     * @param string $sql Query.
     * @param string $family Database family.
     * @param string[] $comments Set to the comments of the query.
     * @return string|null Query read, or null when it could not be read.
     */
    private static function lex(string $sql, string $family, array &$comments = []): ?string {
        $pattern = self::quoted_parts_pattern($family);
        $failed = false;
        $code = preg_replace_callback($pattern, static function (array $m) use (&$comments, &$failed): string {
            $first = $m[0][0];
            if ($first === "'" || $first === '"') {
                return $first . $first;
            }
            if ($first === self::BACKQUOTE) {
                $name = preg_replace('/[^A-Za-z0-9_$]/', '_', substr($m[0], 1, -1));
                $failed = $failed || $name === null;
                return self::BACKQUOTE . $name . self::BACKQUOTE;
            }
            $comments[] = $m[0];
            return ' ';
        }, $sql);
        return $code === null || $failed ? null : $code;
    }

    /**
     * Reason to reject a query before it is read: longer than MAX_QUERY_LENGTH (RISK-004), or with a carriage return,
     * which ends a "--" comment on PostgreSQL and SQL Server while the guard, like MySQL, ends it only at a line feed, so
     * code after it could run unseen (RISK-003). The AI is told to write line breaks as line feeds.
     *
     * @param string $sql Query.
     * @return string|null sql_too_long_rejected, sql_carriage_return_rejected or null.
     */
    private static function unreadable_rejection(string $sql): ?string {
        if (strlen($sql) > self::MAX_QUERY_LENGTH) {
            return 'sql_too_long_rejected';
        }
        if (strpos($sql, "\r") !== false) {
            return 'sql_carriage_return_rejected';
        }
        return null;
    }

    /**
     * Reason to reject a query of a user who does not manage every course, or null when it fits the grammar and reads
     * only course data limited to the managed courses, allowed tables and identity columns of the users of those courses.
     *
     * @param string $sql Query.
     * @param int $userid User the query runs for.
     * @param array $params Query parameters.
     * @return string|null
     */
    private static function course_rejection(string $sql, int $userid, array $params): ?string {
        try {
            $tokens = sql_select_parser::tokenize($sql, $params);
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
        $prefix = self::prefix();
        // Every table named anywhere (placeholders and prefixed names) must be readable, before the grammar is checked.
        $coursedata = false;
        $selects = 0;
        foreach ($tokens as $index => $token) {
            $table = $token['type'] === 'table' ? $token['value'] : null;
            if ($token['type'] === 'word') {
                $selects += $token['value'] === 'select' ? 1 : 0;
                if ($prefix !== '' && strpos($token['value'], $prefix) === 0) {
                    $table = substr($token['value'], strlen($prefix));
                }
                if (in_array($token['value'], ['from', 'join'], true) && !self::is_site_table($tokens, $index + 1, $prefix)) {
                    return 'sql_table_not_allowed';
                }
            }
            if ($table !== null) {
                if (!self::is_readable_table($table)) {
                    return 'sql_table_not_allowed';
                }
                $coursedata = $coursedata || $table === 'user' || self::course_scope($table) !== null;
            }
        }
        // Course data and users are only followed in a single SELECT: a subquery could read them unfiltered.
        if ($coursedata && $selects !== 1) {
            return 'sql_course_unfiltered_rejected';
        }
        try {
            $query = sql_select_parser::parse($tokens, $prefix);
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
        $instances = $query['instances'];
        foreach ($instances as $index => $instance) {
            if (!self::is_readable_table($instance['table'])) {
                return 'sql_table_not_allowed';
            }
            $instances[$index]['scope'] = self::course_scope($instance['table']);
            $instances[$index]['columns'] = self::table_columns($instance['table']);
        }
        $rejection = self::unknown_column_rejection($query, $instances);
        if ($rejection !== null) {
            return $rejection;
        }
        $global = $query['where'];
        $conditions = $query['where'];
        foreach ($instances as $instance) {
            $conditions = array_merge($conditions, $instance['on']);
            if ($instance['kind'] === 'inner') {
                $global = array_merge($global, $instance['on']);
            }
        }
        foreach ($conditions as $condition) {
            foreach (self::named_courses($condition, $instances) as $courseid) {
                if (!self::manages_course($userid, $courseid)) {
                    return 'sql_course_rejected';
                }
            }
        }
        $restricted = [];
        $rejection = self::course_filter_rejection($instances, $global, $userid, $restricted);
        if ($rejection === null) {
            $rejection = self::user_rejection($query, $instances, $global, $restricted);
        }
        return $rejection;
    }

    /**
     * Tell whether the token after FROM or JOIN names a table of this site: a placeholder, or a prefixed name that is not
     * qualified with another database. Anything else that is not a name is left to the grammar.
     *
     * @param array[] $tokens Tokens of the query.
     * @param int $index Index of the token after FROM or JOIN.
     * @param string $prefix Database prefix.
     * @return bool
     */
    private static function is_site_table(array $tokens, int $index, string $prefix): bool {
        $token = $tokens[$index] ?? null;
        if ($token === null || $token['type'] !== 'word') {
            return true;
        }
        $next = $tokens[$index + 1] ?? null;
        if ($next !== null && $next['type'] === 'punct' && $next['value'] === '.') {
            return false;
        }
        return $prefix === '' || strpos($token['value'], $prefix) === 0;
    }

    /**
     * Tell whether a user who does not manage every course may read a table at all: course data, the user table or one
     * of ALLOWED_TABLES.
     *
     * @param string $table Table name without prefix.
     * @return bool
     */
    private static function is_readable_table(string $table): bool {
        return $table === 'user' || in_array($table, self::ALLOWED_TABLES, true) || self::course_scope($table) !== null;
    }

    /**
     * Columns of a table in lowercase.
     *
     * @param string $table Table name without prefix.
     * @return string[] Empty when the table does not exist.
     */
    private static function table_columns(string $table): array {
        global $DB;
        try {
            return array_map('strtolower', array_keys($DB->get_columns($table)));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Reason to reject a query that names a column no table of the query has (a keyword the database could read as a
     * value, such as CURRENT_USER, or a typo), or that qualifies a column or "*" with a name that is not a table of the
     * query. A select alias is accepted in GROUP BY, HAVING and ORDER BY.
     *
     * @param array $query Parsed query.
     * @param array[] $instances Table instances with their columns.
     * @return string|null
     */
    private static function unknown_column_rejection(array $query, array $instances): ?string {
        $refs = array_column($instances, 'ref');
        foreach ($query['stars'] as $qualifier) {
            if ($qualifier !== null && !in_array($qualifier, $refs, true)) {
                return 'sql_construct_rejected';
            }
        }
        foreach ($query['columns'] as $column) {
            $known = false;
            foreach ($instances as $instance) {
                $mine = $column['qualifier'] === null || $column['qualifier'] === $instance['ref'];
                $known = $known || ($mine && in_array($column['column'], $instance['columns'], true));
            }
            $alias = $column['qualifier'] === null && in_array($column['clause'], ['group', 'having', 'order'], true)
                && in_array($column['column'], $query['aliases'], true);
            if (!$known && !$alias) {
                return 'sql_construct_rejected';
            }
        }
        return null;
    }

    /**
     * Index of the table instance a column operand belongs to: the instance its qualifier names, or the only instance
     * that has the column when it is unqualified (as the database resolves it).
     *
     * @param array $operand Operand of a condition.
     * @param array[] $instances Table instances with their columns.
     * @return int|null Null when the operand is not a column or its table cannot be told.
     */
    private static function owner(array $operand, array $instances): ?int {
        if ($operand['type'] !== 'column') {
            return null;
        }
        $owners = [];
        foreach ($instances as $index => $instance) {
            $mine = $operand['qualifier'] === null || $operand['qualifier'] === $instance['ref'];
            if ($mine && in_array($operand['column'], $instance['columns'], true)) {
                $owners[] = $index;
            }
        }
        return count($owners) === 1 ? $owners[0] : null;
    }

    /**
     * Tell whether an operand is the given column of the given table instance.
     *
     * @param array $operand Operand of a condition.
     * @param int $index Index of the table instance.
     * @param string $column Column name.
     * @param array[] $instances Table instances with their columns.
     * @return bool
     */
    private static function is_column(array $operand, int $index, string $column, array $instances): bool {
        return $operand['type'] === 'column' && $operand['column'] === $column && self::owner($operand, $instances) === $index;
    }

    /**
     * Course ids a condition names: a course column, or the id of the course table, compared with or listed as numbers.
     *
     * @param array $condition Condition.
     * @param array[] $instances Table instances with their columns.
     * @return int[]
     */
    private static function named_courses(array $condition, array $instances): array {
        $iscourse = static function (array $operand) use ($instances): bool {
            if ($operand['type'] !== 'column') {
                return false;
            }
            if (in_array($operand['column'], self::COURSE_COLUMNS, true)) {
                return true;
            }
            $owner = self::owner($operand, $instances);
            return $operand['column'] === 'id' && $owner !== null && $instances[$owner]['table'] === 'course';
        };
        if ($condition['op'] === 'in' && $iscourse($condition['left'])) {
            return array_map('intval', $condition['values']);
        }
        if ($condition['op'] !== '=') {
            return [];
        }
        foreach ([[$condition['left'], $condition['right']], [$condition['right'], $condition['left']]] as [$own, $other]) {
            if ($iscourse($own) && $other['type'] === 'number' && ctype_digit($other['value'])) {
                return [(int) $other['value']];
            }
        }
        return [];
    }

    /**
     * Reason to reject a query that reads course data without limiting every course table to courses the user manages,
     * or null when it is limited.
     *
     * @param array[] $instances Table instances with their scope and columns.
     * @param array[] $global Conditions that apply to every row: the WHERE conditions and those of the inner joins.
     * @param int $userid User the query runs for.
     * @param array $restricted Set to the indexes of the instances limited to managed courses (value true).
     * @return string|null
     */
    private static function course_filter_rejection(array $instances, array $global, int $userid, array &$restricted): ?string {
        $restricted = [];
        $scoped = array_filter($instances, static fn(array $instance): bool => $instance['scope'] !== null);
        $ids = [];
        do {
            $changed = false;
            foreach ($scoped as $index => $instance) {
                if (isset($restricted[$index])) {
                    continue;
                }
                $conditions = $instance['kind'] === 'left' ? array_merge($global, $instance['on']) : $global;
                $found = self::restriction($index, $conditions, $instances, $restricted);
                if ($found !== null) {
                    $restricted[$index] = true;
                    $ids = array_merge($ids, $found);
                    $changed = true;
                }
            }
        } while ($changed);
        if (count($restricted) !== count($scoped)) {
            return 'sql_course_unfiltered_rejected';
        }
        foreach (array_unique($ids) as $courseid) {
            if (!self::manages_course($userid, $courseid)) {
                return 'sql_course_rejected';
            }
        }
        return null;
    }

    /**
     * Course ids a course table instance is limited to by the conditions, or null when it is not limited.
     *
     * A course column (or the course id of a course context) limited by "= number" or "IN (numbers)", or equal to the
     * course column of another limited instance; for a child table, its parent column equal to the id of a limited
     * instance of the parent table.
     *
     * @param int $index Index of the instance.
     * @param array[] $conditions Conditions that apply to the instance.
     * @param array[] $instances Every table instance of the query.
     * @param array $restricted Instances already known to be limited, keyed by instance index (value true).
     * @return int[]|null
     */
    private static function restriction(int $index, array $conditions, array $instances, array $restricted): ?array {
        $scope = $instances[$index]['scope'];
        $column = $scope['type'] === 'context' ? 'instanceid' : $scope['column'];
        if ($scope['type'] === 'context' && !self::has_course_level($index, $conditions, $instances)) {
            return null;
        }
        foreach ($conditions as $condition) {
            $whole = $condition['op'] === 'in' && array_filter($condition['values'], 'ctype_digit') === $condition['values'];
            if ($whole && $scope['type'] !== 'child' && self::is_column($condition['left'], $index, $column, $instances)) {
                return array_map('intval', $condition['values']);
            }
            if ($condition['op'] !== '=') {
                continue;
            }
            foreach ([[$condition['left'], $condition['right']], [$condition['right'], $condition['left']]] as [$own, $other]) {
                if (!self::is_column($own, $index, $column, $instances)) {
                    continue;
                }
                if ($scope['type'] !== 'child' && $other['type'] === 'number' && ctype_digit($other['value'])) {
                    return [(int) $other['value']];
                }
                $linked = self::owner($other, $instances);
                if ($linked === null || $linked === $index || !isset($restricted[$linked])) {
                    continue;
                }
                // A child table links to the id of its parent; a course table to the course column of another one.
                $parent = $instances[$linked];
                if ($scope['type'] === 'child') {
                    $key = $parent['table'] === $scope['parent'] ? 'id' : null;
                } else {
                    $key = self::course_key($parent);
                }
                if ($key !== null && $other['column'] === $key) {
                    return [];
                }
            }
        }
        return null;
    }

    /**
     * Column of a table instance that holds a course id: its course column, or the instance id of a course context.
     *
     * @param array $instance Table instance with its scope.
     * @return string|null Null for tables that do not hold a course id.
     */
    private static function course_key(array $instance): ?string {
        $scope = $instance['scope'];
        if ($scope === null || $scope['type'] === 'child') {
            return null;
        }
        return $scope['type'] === 'context' ? 'instanceid' : $scope['column'];
    }

    /**
     * Whether the conditions limit a context instance to the course level.
     *
     * @param int $index Index of the context instance.
     * @param array[] $conditions Conditions that apply to the instance.
     * @param array[] $instances Every table instance of the query.
     * @return bool
     */
    private static function has_course_level(int $index, array $conditions, array $instances): bool {
        foreach ($conditions as $condition) {
            if ($condition['op'] !== '=') {
                continue;
            }
            foreach ([[$condition['left'], $condition['right']], [$condition['right'], $condition['left']]] as [$own, $other]) {
                if (
                    self::is_column($own, $index, 'contextlevel', $instances) && $other['type'] === 'number'
                        && $other['value'] === (string) CONTEXT_COURSE
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Reason to reject a query of a user who does not manage every course that reads the user table, or null when it
     * reads only identity columns of users tied to a course table limited to the managed courses.
     *
     * @param array $query Parsed query.
     * @param array[] $instances Table instances with their scope and columns.
     * @param array[] $global Conditions that apply to every row.
     * @param array $restricted Indexes of the instances limited to managed courses (value true).
     * @return string|null
     */
    private static function user_rejection(array $query, array $instances, array $global, array $restricted): ?string {
        $users = array_keys(array_filter($instances, static fn(array $instance): bool => $instance['table'] === 'user'));
        if (empty($users)) {
            return null;
        }
        // Every column with "*" (COUNT(*) aside) is refused: only listed columns are read.
        if (!empty($query['stars'])) {
            return 'sql_user_column_rejected';
        }
        $usercolumns = $instances[$users[0]]['columns'];
        foreach ($query['columns'] as $column) {
            $private = in_array($column['column'], $usercolumns, true)
                && !in_array($column['column'], self::USER_COLUMNS, true);
            if ($column['qualifier'] !== null) {
                // A qualified column of the user table.
                $owner = array_search($column['qualifier'], array_column($instances, 'ref'), true);
                $private = $private && $owner !== false && $instances[$owner]['table'] === 'user';
            }
            // An unqualified column could belong to the user table: only the identity columns are accepted.
            if ($private) {
                return 'sql_user_column_rejected';
            }
        }
        foreach ($users as $index) {
            $conditions = $instances[$index]['kind'] === 'left' ? array_merge($global, $instances[$index]['on']) : $global;
            $tied = false;
            foreach ($conditions as $condition) {
                if ($condition['op'] !== '=') {
                    continue;
                }
                foreach ([[$condition['left'], $condition['right']], [$condition['right'], $condition['left']]] as [$own, $other]) {
                    $linked = self::owner($other, $instances);
                    $tied = $tied || (self::is_column($own, $index, 'id', $instances) && $linked !== null
                        && isset($restricted[$linked]) && $other['column'] === 'userid');
                }
            }
            if (!$tied) {
                return 'sql_table_not_allowed';
            }
        }
        return null;
    }

    /**
     * How a table holds course data: its course column, its parent table, or none.
     *
     * @param string $table Table name without prefix.
     * @return array|null Scope (type direct with column, context, child with column and parent) or null.
     */
    private static function course_scope(string $table): ?array {
        if ($table === 'course') {
            return ['type' => 'direct', 'column' => 'id'];
        }
        if ($table === 'context') {
            return ['type' => 'context'];
        }
        if (isset(self::CHILD_TABLES[$table])) {
            [$column, $parent] = self::CHILD_TABLES[$table];
            return ['type' => 'child', 'column' => $column, 'parent' => $parent];
        }
        $columns = self::table_columns($table);
        foreach (self::COURSE_COLUMNS as $column) {
            if (in_array($column, $columns, true)) {
                return ['type' => 'direct', 'column' => $column];
            }
        }
        if (in_array('contextid', $columns, true)) {
            return ['type' => 'child', 'column' => 'contextid', 'parent' => 'context'];
        }
        return null;
    }

    /**
     * Tell whether a table, given without the database prefix, holds sensitive data.
     *
     * @param string $table Table name without prefix.
     * @return bool
     */
    public static function is_sensitive_table(string $table): bool {
        $table = strtolower($table);
        if (in_array($table, self::SENSITIVE_TABLES, true)) {
            return true;
        }
        foreach (self::SENSITIVE_TABLE_PREFIXES as $prefix) {
            if (strpos($table, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove the sensitive columns from a result row (for queries reading every column with "*").
     *
     * @param array $row Result row.
     * @return array Row without sensitive columns.
     */
    public static function filter_row(array $row): array {
        foreach (array_keys($row) as $column) {
            if (self::is_sensitive_column((string) $column)) {
                unset($row[$column]);
            }
        }
        return $row;
    }

    /**
     * Tell whether a column name holds credentials or secrets: one of SENSITIVE_COLUMNS, or a name matching
     * SENSITIVE_COLUMN_PATTERN that is not one of HARMLESS_COLUMNS.
     *
     * @param string $column Column name.
     * @return bool
     */
    public static function is_sensitive_column(string $column): bool {
        $column = strtolower($column);
        if (in_array($column, self::SENSITIVE_COLUMNS, true)) {
            return true;
        }
        return !in_array($column, self::HARMLESS_COLUMNS, true) && preg_match(self::SENSITIVE_COLUMN_PATTERN, $column) === 1;
    }

    /**
     * Table names (without prefix) referenced by a query: placeholders, prefixed names and names after FROM or JOIN.
     *
     * @param string $code Lowercase query without literals.
     * @return string[]
     */
    private static function referenced_tables(string $code): array {
        global $DB;
        $tables = [];
        if (preg_match_all('/\{\s*([a-z0-9_]+)\s*\}/', $code, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        $prefix = strtolower($DB->get_prefix());
        foreach (self::identifiers($code) as $identifier) {
            if ($prefix === '' || strpos($identifier, $prefix) === 0) {
                $tables[] = substr($identifier, strlen($prefix));
            }
        }
        if (preg_match_all('/\b(?:from|join)\s+\x60?([a-z0-9_]+)\x60?/', $code, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }
        return array_unique($tables);
    }

    /**
     * Every identifier of a query, with backquotes removed and split on the dots of qualified names.
     *
     * @param string $code Lowercase query without literals.
     * @return string[]
     */
    private static function identifiers(string $code): array {
        preg_match_all('/[a-z_][a-z0-9_]*/', str_replace(self::BACKQUOTE, '', $code), $matches);
        return array_unique($matches[0]);
    }

    /**
     * Database prefix in lowercase.
     *
     * @return string
     */
    private static function prefix(): string {
        global $DB;
        return strtolower($DB->get_prefix());
    }

    /**
     * Tell whether a user manages every course of the site (site administrator or site-level course editor).
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function manages_every_course(int $userid): bool {
        return is_siteadmin($userid) || has_capability('moodle/course:update', \context_system::instance(), $userid);
    }

    /**
     * Tell whether a user manages a course that is in Jedomi.
     *
     * An excluded course is outside Jedomi, so it is never accepted, also when the user manages it.
     *
     * @param int $userid User id.
     * @param int $courseid Course id.
     * @return bool False as well when the course does not exist, so an unknown id is never accepted.
     */
    private static function manages_course(int $userid, int $courseid): bool {
        if (!\local_jedomi\course_access::is_course_in_jedomi($courseid)) {
            return false;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        return $context && has_capability('moodle/course:update', $context, $userid);
    }
}
