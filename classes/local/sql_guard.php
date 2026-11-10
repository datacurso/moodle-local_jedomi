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
 * The web chat, Telegram and the agents all call it before running a query. It applies three layers:
 *
 * 1. For every user (site administrators included): functions and clauses that read or write server files, pause or
 *    lock the database, reach other databases or reveal server variables are rejected (sql_function_rejected), and so
 *    are MySQL executable comments, which the database runs although they look like comments.
 * 2. For every user: tables and columns that hold credentials, keys, tokens, site configuration or sessions are
 *    rejected (sql_sensitive_rejected).
 * 3. For a user who does not manage every course (a teacher), an allowlist instead of a denylist. Every table the query
 *    reads, in any FROM or JOIN including subqueries, must be either:
 *    - course data (the course table, a table with a course column, a known child table of one, the context table or a
 *      table tied to a context), which must then be limited to courses the user manages (sql_course_rejected and
 *      sql_course_unfiltered_rejected); or
 *    - one of ALLOWED_TABLES, which hold no personal data; or
 *    - the user table, read only through USER_COLUMNS (identity columns, never with "*") and only for users tied to
 *      a course table limited to the managed courses by a join on the user id, such as
 *      "JOIN {user_enrolments} ue ON ue.userid = u.id" with the enrolment limited to the course
 *      (sql_user_column_rejected and sql_table_not_allowed).
 *    Any other table (messages, notifications, user preferences, other databases...) is rejected
 *    (sql_table_not_allowed). When a part of the query cannot be followed, it is rejected rather than guessed.
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

    /** @var string[] Table name prefixes (without the database prefix) that are always sensitive. */
    private const SENSITIVE_TABLE_PREFIXES = ['oauth2_'];

    /** @var string[] Column names that hold credentials or secrets in any table. */
    private const SENSITIVE_COLUMNS = [
        'password', 'secret', 'clientsecret', 'privatetoken', 'token', 'sid', 'datakey',
        'passwordsaltmain', 'sessionkey', 'linkcode', 'link_code',
    ];

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
        'pg_sleep\w*', 'pg_read_file', 'pg_read_binary_file', 'lo_import', 'lo_export', 'dblink\w*'];

    /** @var string[] Words that would let a query escape the course filter of a user who does not manage every course. */
    private const UNFILTERABLE_WORDS = ['or', 'xor', 'union', 'intersect', 'except', 'between', 'right', 'full', 'cross',
        'natural', 'straight_join', 'with'];

    /**
     * Check a query and return why it is rejected, or null when it can run.
     *
     * For a user who does not manage every course, every table the query reads must be course data limited to courses
     * the user manages (with plain conditions ANDed in the WHERE clause or in the join of the table), a table of
     * ALLOWED_TABLES, or the user table read through USER_COLUMNS and tied to such a limited course table.
     *
     * @param string $sql Query as written by the AI (Moodle placeholders or prefixed table names).
     * @param int $userid User the query runs for; 0 skips the course check.
     * @param array $params Query parameters, so a course given as a parameter is checked as well.
     * @return string|null Reason of the rejection (language string identifier) or null when allowed.
     */
    public static function rejection_reason(string $sql, int $userid = 0, array $params = []): ?string {
        $code = self::inline_numeric_params(self::strip_literals($sql), $params);
        if (self::has_executable_comment($sql) || self::calls_blocked_function($code)) {
            return 'sql_function_rejected';
        }
        foreach (self::referenced_tables($code) as $table) {
            if (self::is_sensitive_table($table)) {
                return 'sql_sensitive_rejected';
            }
        }
        foreach (self::identifiers($code) as $identifier) {
            if (in_array($identifier, self::SENSITIVE_COLUMNS, true)) {
                return 'sql_sensitive_rejected';
            }
        }
        if ($userid > 0 && !self::manages_every_course($userid)) {
            foreach (self::referenced_course_ids($code) as $courseid) {
                if (!self::manages_course($userid, $courseid)) {
                    return 'sql_course_rejected';
                }
            }
            $code = self::normalise($code);
            $tables = self::read_tables($code);
            if ($tables === null) {
                return 'sql_table_not_allowed';
            }
            $coursedata = false;
            foreach ($tables as $table) {
                $scoped = $table === 'user' || self::course_scope($table) !== null;
                if (!$scoped && !in_array($table, self::ALLOWED_TABLES, true)) {
                    return 'sql_table_not_allowed';
                }
                $coursedata = $coursedata || $scoped;
            }
            // Course data and users are only followed in a single SELECT: a subquery could read them unfiltered.
            if ($coursedata && preg_match_all('/\bselect\b/', $code) !== 1) {
                return 'sql_course_unfiltered_rejected';
            }
            $instances = [];
            $restricted = [];
            $rejection = self::course_filter_rejection($code, $userid, $instances, $restricted);
            if ($rejection === null && in_array('user', $tables, true)) {
                $rejection = self::user_rejection($code, $instances, $restricted);
            }
            return $rejection;
        }
        return null;
    }

    /**
     * Tell whether a query calls a function or uses a clause rejected for every user.
     *
     * @param string $code Lowercase query without literals and comments.
     * @return bool
     */
    private static function calls_blocked_function(string $code): bool {
        $code = str_replace(self::BACKQUOTE, ' ', $code);
        $pattern = '/\b(?:' . implode('|', self::BLOCKED_FUNCTIONS) . ')\b|\binto\s+(?:outfile|dumpfile)\b|@@|(?:^|;)\s*copy\b/';
        return (bool) preg_match($pattern, $code);
    }

    /**
     * Tell whether a query holds a MySQL executable comment or optimizer hint, which the database runs although the
     * guard would read it as a comment.
     *
     * @param string $sql Query.
     * @return bool
     */
    private static function has_executable_comment(string $sql): bool {
        $executable = false;
        self::lex($sql, $executable);
        return $executable;
    }

    /**
     * Normalise a query for the structural checks: no backquotes, single spaces and no final semicolon.
     *
     * @param string $code Lowercase query without literals.
     * @return string
     */
    private static function normalise(string $code): string {
        $code = trim((string) preg_replace('/\s+/', ' ', str_replace(self::BACKQUOTE, '', $code)));
        return rtrim($code, '; ');
    }

    /**
     * Tables (without prefix) read by every FROM and JOIN of a query, subqueries included, plus the tables it names as
     * placeholders or with the prefix anywhere else.
     *
     * @param string $code Normalised query.
     * @return string[]|null Null when a table source cannot be followed (derived table, function, other database...).
     */
    private static function read_tables(string $code): ?array {
        $tables = [];
        if (preg_match_all('/\{\s*([a-z0-9_]+)\s*\}/', $code, $matches)) {
            $tables = $matches[1];
        }
        $prefix = self::prefix();
        if ($prefix !== '') {
            foreach (self::identifiers($code) as $identifier) {
                if (strpos($identifier, $prefix) === 0) {
                    $tables[] = substr($identifier, strlen($prefix));
                }
            }
        }
        $offset = 0;
        while (preg_match('/\bfrom\b/', $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $m[0][1] + 4;
            foreach (self::from_items(substr($code, $offset)) as $item) {
                $table = self::item_table($item);
                if ($table === null) {
                    return null;
                }
                $tables[] = $table;
            }
        }
        return array_values(array_unique($tables));
    }

    /**
     * Split the FROM clause that starts a piece of a query into its table sources, at the commas and joins outside
     * parentheses, up to the end of the clause.
     *
     * @param string $rest Normalised query after the FROM keyword.
     * @return string[]
     */
    private static function from_items(string $rest): array {
        $join = '\b(?:natural\s+)?(?:(?:left|right|full)(?:\s+outer)?\s+|inner\s+|cross\s+)?join\b|\bstraight_join\b';
        $end = '\b(?:where|group|order|having|limit|offset|fetch|union|intersect|except|window|for|lock|into|procedure)\b';
        $tokens = preg_split('/(\(|\)|,|;|' . $join . '|' . $end . ')/', $rest, -1, PREG_SPLIT_DELIM_CAPTURE);
        $items = [];
        $current = '';
        $depth = 0;
        foreach ($tokens as $token) {
            if ($depth === 0 && ($token === ')' || $token === ';' || preg_match('/^' . $end . '$/', $token))) {
                break;
            }
            if ($depth === 0 && ($token === ',' || preg_match('/^(?:' . $join . ')$/', $token))) {
                $items[] = $current;
                $current = '';
                continue;
            }
            $depth += $token === '(' ? 1 : ($token === ')' ? -1 : 0);
            $current .= $token;
        }
        $items[] = $current;
        return $items;
    }

    /**
     * Table (without prefix) of one table source of a FROM clause: a placeholder or a prefixed name, with an optional
     * alias and join condition.
     *
     * @param string $item Table source.
     * @return string|null Null when the source is not a plain table of this site.
     */
    private static function item_table(string $item): ?string {
        $pattern = '/^\s*(?:\{\s*([a-z0-9_]+)\s*\}|([a-z0-9_]+))(?:\s+(?:as\s+)?(?!on\b|using\b)[a-z_][a-z0-9_]*)?'
            . '\s*(?:\b(?:on|using)\b.*)?$/s';
        if (!preg_match($pattern, $item, $m)) {
            return null;
        }
        if ($m[1] !== '') {
            return $m[1];
        }
        $prefix = self::prefix();
        if ($prefix === '') {
            return $m[2];
        }
        return strpos($m[2], $prefix) === 0 ? substr($m[2], strlen($prefix)) : null;
    }

    /**
     * Reason to reject a query of a user who does not manage every course that reads the user table, or null when it
     * reads only identity columns of users tied to a course table limited to the managed courses.
     *
     * @param string $code Normalised query.
     * @param array[] $instances Table instances of the query.
     * @param array $restricted Indexes of the instances limited to managed courses (value true).
     * @return string|null
     */
    private static function user_rejection(string $code, array $instances, array $restricted): ?string {
        global $DB;
        $users = array_filter($instances, static fn(array $instance): bool => $instance['table'] === 'user');
        if (empty($users)) {
            return 'sql_table_not_allowed';
        }
        // Every column with "*" (count(*) aside) or with an arithmetic star is refused: only listed columns are read.
        if (strpos((string) preg_replace('/\(\s*\*\s*\)/', '', $code), '*') !== false) {
            return 'sql_user_column_rejected';
        }
        $refs = ['user', '{user}', self::prefix() . 'user'];
        foreach ($users as $instance) {
            $refs[] = $instance['ref'];
        }
        $qualified = '/(\{\s*[a-z0-9_]+\s*\}|[a-z_][a-z0-9_]*)\s*\.\s*([a-z_][a-z0-9_]*)/';
        preg_match_all($qualified, $code, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $qualifier = (string) preg_replace('/\s+/', '', $match[1]);
            if (in_array($qualifier, $refs, true) && !in_array($match[2], self::USER_COLUMNS, true)) {
                return 'sql_user_column_rejected';
            }
        }
        // An unqualified column could belong to the user table: only the identity columns are accepted.
        $usercolumns = array_map('strtolower', array_keys($DB->get_columns('user')));
        $private = array_diff($usercolumns, self::USER_COLUMNS);
        foreach (self::identifiers((string) preg_replace($qualified, ' ', $code)) as $identifier) {
            if (in_array($identifier, $private, true)) {
                return 'sql_user_column_rejected';
            }
        }
        $global = self::global_conditions($code, $instances);
        $links = [];
        foreach (array_keys($restricted) as $index) {
            $other = $instances[$index];
            if (array_key_exists('userid', $DB->get_columns($other['table']))) {
                $links[] = preg_quote($other['ref'], '/') . '\.userid';
            }
        }
        foreach ($users as $instance) {
            $conditions = $instance['kind'] === 'left' ? array_merge($global, $instance['on']) : $global;
            $own = preg_quote($instance['ref'], '/') . '\.id';
            $tied = false;
            foreach ($conditions as $condition) {
                foreach ($links as $link) {
                    if (
                        preg_match('/^' . $own . ' (?:=|<=>) ' . $link . '$/', $condition)
                            || preg_match('/^' . $link . ' (?:=|<=>) ' . $own . '$/', $condition)
                    ) {
                        $tied = true;
                    }
                }
            }
            if (!$tied) {
                return 'sql_table_not_allowed';
            }
        }
        return null;
    }

    /**
     * Conditions that apply to every row of a query: the WHERE conjuncts and the conditions of the inner joins.
     *
     * @param string $code Normalised query.
     * @param array[] $instances Table instances of the query.
     * @return string[]
     */
    private static function global_conditions(string $code, array $instances): array {
        $global = preg_match('/\bwhere\b(.*?)(?:\bgroup\s+by\b|\border\s+by\b|\bhaving\b|\blimit\b|$)/', $code, $m)
            ? self::conjuncts($m[1]) : [];
        foreach ($instances as $instance) {
            if ($instance['kind'] === 'inner') {
                $global = array_merge($global, $instance['on']);
            }
        }
        return $global;
    }

    /**
     * Put the numeric parameters of a query in place of their placeholders, and an empty string for the rest.
     *
     * @param string $code Lowercase query without literals.
     * @param array $params Positional or named parameters.
     * @return string
     */
    private static function inline_numeric_params(string $code, array $params): string {
        $value = static fn($param): string => (is_int($param) || (is_string($param) && ctype_digit($param)))
            ? (string) (int) $param : "''";
        $named = [];
        foreach ($params as $key => $param) {
            if (is_string($key)) {
                $named[strtolower(ltrim($key, ':'))] = $value($param);
            }
        }
        $positional = array_values(array_filter($params, static fn($key): bool => is_int($key), ARRAY_FILTER_USE_KEY));
        $index = 0;
        $code = (string) preg_replace_callback('/\?/', static function () use (&$index, $positional, $value): string {
            return array_key_exists($index, $positional) ? $value($positional[$index++]) : "''";
        }, $code);
        return (string) preg_replace_callback(
            '/(?<![:\w]):([a-z_][a-z0-9_]*)/',
            static fn(array $m): string => $named[$m[1]] ?? "''",
            $code
        );
    }

    /**
     * Reason to reject a query of a user who does not manage every course when it reads course data without limiting
     * every course table to courses the user manages, or null when it is limited.
     *
     * @param string $code Normalised query with the numeric parameters in place.
     * @param int $userid User the query runs for.
     * @param array $instances Set to the table instances of the query.
     * @param array $restricted Set to the indexes of the instances limited to managed courses (value true).
     * @return string|null
     */
    private static function course_filter_rejection(string $code, int $userid, array &$instances, array &$restricted): ?string {
        $instances = self::table_instances($code);
        $restricted = [];
        $scoped = array_filter($instances, static fn(array $instance): bool => $instance['scope'] !== null);
        if (empty($scoped)) {
            return null;
        }
        // Only plain conjunctions can be checked: anything that could widen the rows is refused.
        $words = '/\b(?:' . implode('|', self::UNFILTERABLE_WORDS) . ')\b|\|\||\bnot\s*\(|\bnot\s+[a-z0-9_.]+\s*(?:=|<=>|\bin\b)/';
        if (preg_match($words, $code) || preg_match_all('/\bselect\b/', $code) !== 1) {
            return 'sql_course_unfiltered_rejected';
        }
        $global = self::global_conditions($code, $instances);
        $single = count($instances) === 1;
        $ids = [];
        do {
            $changed = false;
            foreach ($scoped as $index => $instance) {
                if (isset($restricted[$index])) {
                    continue;
                }
                $conditions = $instance['kind'] === 'left' ? array_merge($global, $instance['on']) : $global;
                $found = self::restriction($instance, $conditions, $instances, $restricted, $single);
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
     * Tables read by a query, each with its alias, how it is joined, the conditions of its join and its course scope.
     *
     * @param string $code Normalised query.
     * @return array[] Each: table, ref (alias or table name), kind (from, inner or left), on (conditions), scope.
     */
    private static function table_instances(string $code): array {
        if (!preg_match('/\bfrom\b(.*?)(?:\bwhere\b|\bgroup\s+by\b|\border\s+by\b|\bhaving\b|\blimit\b|$)/', $code, $m)) {
            return [];
        }
        $pieces = preg_split(
            '/\s*(,|\b(?:(?:left|inner)\s+(?:outer\s+)?)?join\b)\s*/',
            trim($m[1]),
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        $instances = [];
        $kind = 'from';
        foreach ($pieces as $piece) {
            if ($piece === ',') {
                $kind = 'from';
                continue;
            }
            if (preg_match('/join$/', $piece)) {
                $kind = strpos($piece, 'left') === 0 ? 'left' : 'inner';
                continue;
            }
            $on = '';
            $parts = preg_split('/\bon\b/', $piece, 2);
            if (count($parts) === 2) {
                [$piece, $on] = $parts;
            }
            if (
                !preg_match(
                    '/^\s*(?:\{\s*([a-z0-9_]+)\s*\}|([a-z0-9_]+))\s*(?:as\s+)?([a-z_][a-z0-9_]*)?\s*$/',
                    $piece,
                    $tm
                )
            ) {
                // A derived table or anything else that cannot be followed is read as unfiltered course data.
                $instances[] = ['table' => '', 'ref' => '', 'kind' => $kind, 'on' => [],
                    'scope' => ['type' => 'unknown']];
                continue;
            }
            $table = $tm[1] !== '' ? $tm[1] : $tm[2];
            $prefix = self::prefix();
            if ($tm[1] === '' && $prefix !== '' && strpos($table, $prefix) === 0) {
                $table = substr($table, strlen($prefix));
            }
            $alias = $tm[3] ?? '';
            $instances[] = ['table' => $table, 'ref' => $alias !== '' ? $alias : $table, 'kind' => $kind,
                'on' => self::conjuncts($on), 'scope' => self::course_scope($table)];
        }
        return $instances;
    }

    /**
     * How a table holds course data: its course column, its parent table, or none.
     *
     * @param string $table Table name without prefix.
     * @return array|null Scope (type direct with column, context, child with column and parent) or null.
     */
    private static function course_scope(string $table): ?array {
        global $DB;
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
        try {
            $columns = array_map('strtolower', array_keys($DB->get_columns($table)));
        } catch (\Throwable $e) {
            $columns = [];
        }
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
     * Split a condition on the ANDs outside parentheses.
     *
     * @param string $condition Condition.
     * @return string[] Conditions with spaces normalised around comparisons and parentheses.
     */
    private static function conjuncts(string $condition): array {
        $result = [];
        $depth = 0;
        $current = '';
        foreach (preg_split('/(\(|\)|\band\b)/', $condition, -1, PREG_SPLIT_DELIM_CAPTURE) as $token) {
            if ($token === 'and' && $depth === 0) {
                $result[] = $current;
                $current = '';
                continue;
            }
            $depth += $token === '(' ? 1 : ($token === ')' ? -1 : 0);
            $current .= $token;
        }
        $result[] = $current;
        $normalised = [];
        foreach ($result as $item) {
            $item = (string) preg_replace('/\s*(<=>|=)\s*/', ' $1 ', $item);
            $item = (string) preg_replace(['/\(\s*/', '/\s*\)/', '/\s*,\s*/', '/\s+/'], ['(', ')', ',', ' '], $item);
            if (trim($item) !== '') {
                $normalised[] = trim($item);
            }
        }
        return $normalised;
    }

    /**
     * Course ids an instance of a course table is limited to by the conditions, or null when it is not limited.
     *
     * @param array $instance Table instance.
     * @param string[] $conditions Conditions that apply to the instance.
     * @param array[] $instances Every table instance of the query.
     * @param array $restricted Instances already known to be limited, keyed by instance index (value true).
     * @param bool $single Whether the query reads only this table (unqualified columns then belong to it).
     * @return int[]|null
     */
    private static function restriction(
        array $instance,
        array $conditions,
        array $instances,
        array $restricted,
        bool $single
    ): ?array {
        $scope = $instance['scope'];
        if ($scope['type'] === 'unknown') {
            return null;
        }
        $ref = '(?:' . preg_quote($instance['ref'], '/') . '\.' . ($single ? ')?' : ')');
        // Columns of other limited instances that hold a course id (for a column of this one holding a course id).
        $coursekeys = [];
        $parentkeys = [];
        foreach ($instances as $index => $other) {
            if (!isset($restricted[$index])) {
                continue;
            }
            if ($other['scope']['type'] === 'direct') {
                $coursekeys[] = preg_quote($other['ref'], '/') . '\.' . $other['scope']['column'];
            }
            if ($other['scope']['type'] === 'context') {
                $coursekeys[] = preg_quote($other['ref'], '/') . '\.instanceid';
            }
            if ($scope['type'] === 'child' && $other['table'] === $scope['parent']) {
                $parentkeys[] = preg_quote($other['ref'], '/') . '\.id';
            }
        }
        $column = match ($scope['type']) {
            'direct' => $scope['column'],
            'context' => 'instanceid',
            'child' => $scope['column'],
        };
        if (
            $scope['type'] === 'context' && !self::has_condition(
                $instance,
                'contextlevel = ' . CONTEXT_COURSE,
                $conditions,
                $single
            )
        ) {
            return null;
        }
        $own = $ref . preg_quote($column, '/');
        $links = $scope['type'] === 'child' ? $parentkeys : $coursekeys;
        foreach ($conditions as $condition) {
            if ($scope['type'] !== 'child') {
                if (
                    preg_match('/^' . $own . ' (?:=|<=>) (\d+)$/', $condition, $m)
                        || preg_match('/^(\d+) (?:=|<=>) ' . $own . '$/', $condition, $m)
                ) {
                    return [(int) $m[1]];
                }
                if (preg_match('/^' . $own . ' in ?\(([\d,]+)\)$/', $condition, $m)) {
                    return array_map('intval', explode(',', $m[1]));
                }
            }
            foreach ($links as $link) {
                if (
                    preg_match('/^' . $own . ' (?:=|<=>) ' . $link . '$/', $condition)
                        || preg_match('/^' . $link . ' (?:=|<=>) ' . $own . '$/', $condition)
                ) {
                    return [];
                }
            }
        }
        return null;
    }

    /**
     * Whether a plain condition on a column of an instance is among the conditions.
     *
     * @param array $instance Table instance.
     * @param string $condition Condition with the unqualified column, such as "contextlevel = 50".
     * @param string[] $conditions Conditions that apply to the instance.
     * @param bool $single Whether unqualified columns belong to the instance.
     * @return bool
     */
    private static function has_condition(array $instance, string $condition, array $conditions, bool $single): bool {
        foreach ($conditions as $item) {
            if ($item === $instance['ref'] . '.' . $condition || ($single && $item === $condition)) {
                return true;
            }
        }
        return false;
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
            if (in_array(strtolower((string) $column), self::SENSITIVE_COLUMNS, true)) {
                unset($row[$column]);
            }
        }
        return $row;
    }

    /**
     * Remove string literals and comments so their content is not read as table or column names.
     *
     * @param string $sql Query.
     * @return string Query with literals replaced by empty strings and comments removed.
     */
    private static function strip_literals(string $sql): string {
        $executable = false;
        return strtolower(self::lex($sql, $executable));
    }

    /**
     * Read a query from left to right, replacing its string literals by empty strings and its comments by a space, so a
     * quote inside a comment or a comment mark inside a string cannot hide code from the guard.
     *
     * As in MySQL, "--" starts a comment only when a space follows it ("--1" is a double minus), and "#" starts one
     * only in MySQL: reading more than the database ignores keeps the guard on the safe side.
     *
     * @param string $sql Query.
     * @param bool $executable Set to true when a comment is a MySQL executable comment or optimizer hint.
     * @return string
     */
    private static function lex(string $sql, bool &$executable): string {
        global $DB;
        $hash = $DB->get_dbfamily() === 'mysql' ? '|#[^\n]*' : '';
        $pattern = "/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\"|--(?=\\s|\$)[^\\n]*{$hash}|\\/\\*.*?\\*\\//s";
        return (string) preg_replace_callback($pattern, static function (array $m) use (&$executable): string {
            $first = $m[0][0];
            if ($first === "'" || $first === '"') {
                return $first . $first;
            }
            if (preg_match('/^\/\*\s*[!+]/', $m[0])) {
                $executable = true;
            }
            return ' ';
        }, $sql);
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
     * Course ids a query names explicitly (course columns, or the id of the course table, compared with numbers).
     *
     * @param string $code Lowercase query without literals.
     * @return int[]
     */
    private static function referenced_course_ids(string $code): array {
        $column = '(?:[a-z0-9_]+\.)?(?:' . implode('|', self::COURSE_COLUMNS) . ')';
        $coursetable = '(?:\{\s*course\s*\}|\b' . preg_quote(self::prefix(), '/') . 'course\b)';
        if (preg_match('/' . $coursetable . '/', $code)) {
            // The id of the course table: bare, or qualified with one of the aliases of the course table.
            $aliases = ['course'];
            if (preg_match_all('/' . $coursetable . '\s+(?:as\s+)?([a-z_][a-z0-9_]*)/', $code, $matches)) {
                $keywords = ['where', 'join', 'left', 'right', 'inner', 'outer', 'cross', 'on', 'group', 'order', 'limit',
                    'union', 'having', 'natural', 'straight_join'];
                $aliases = array_merge($aliases, array_diff($matches[1], $keywords));
            }
            $column = '(?:' . $column . '|(?:(?:' . implode('|', array_unique($aliases)) . ')\.)?id)';
        }
        $ids = [];
        $patterns = [
            '/\b' . $column . '\s*(?:=|<=>|==)\s*(\d+)/',
            '/(\d+)\s*(?:=|<=>)\s*' . $column . '\b/',
            '/\b' . $column . '\s+in\s*\(([\d\s,]+)\)/',
            '/\b' . $column . '\s+between\s+(\d+)\s+and\s+(\d+)/',
        ];
        foreach ($patterns as $index => $pattern) {
            if (!preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                if ($index === 3) {
                    // A range names every course between its limits; only the limits are checked to stay bounded.
                    $ids[] = (int) $match[1];
                    $ids[] = (int) $match[2];
                    continue;
                }
                foreach (preg_split('/[\s,]+/', trim($match[1])) as $value) {
                    if ($value !== '') {
                        $ids[] = (int) $value;
                    }
                }
            }
        }
        return array_values(array_unique($ids));
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
     * Tell whether a user manages a course.
     *
     * @param int $userid User id.
     * @param int $courseid Course id.
     * @return bool False as well when the course does not exist, so an unknown id is never accepted.
     */
    private static function manages_course(int $userid, int $courseid): bool {
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        return $context && has_capability('moodle/course:update', $context, $userid);
    }
}
