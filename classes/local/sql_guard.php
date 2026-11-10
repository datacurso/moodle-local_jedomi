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
 * It classifies the tables and columns a query reads and rejects the queries that would reveal credentials,
 * keys, tokens, site configuration or sessions, and the ones that name courses the user does not manage.
 * The web chat, Telegram and the agents all call it before running a query.
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

    /** @var string[] Words that would let a query escape the course filter of a user who does not manage every course. */
    private const UNFILTERABLE_WORDS = ['or', 'xor', 'union', 'intersect', 'except', 'between', 'right', 'full', 'cross',
        'natural', 'straight_join', 'with'];

    /**
     * Check a query and return why it is rejected, or null when it can run.
     *
     * For a user who does not manage every course, a query that reads course data (the course table, tables with a
     * course column, their child tables, contexts or tables tied to a context) must also limit every one of those
     * tables to courses the user manages, with plain conditions ANDed in the WHERE clause or in the join of the table.
     *
     * @param string $sql Query as written by the AI (Moodle placeholders or prefixed table names).
     * @param int $userid User the query runs for; 0 skips the course check.
     * @param array $params Query parameters, so a course given as a parameter is checked as well.
     * @return string|null Reason of the rejection (language string identifier) or null when allowed.
     */
    public static function rejection_reason(string $sql, int $userid = 0, array $params = []): ?string {
        $code = self::inline_numeric_params(self::strip_literals($sql), $params);
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
            return self::course_filter_rejection($code, $userid);
        }
        return null;
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
     * @param string $code Lowercase query without literals and with the numeric parameters in place.
     * @param int $userid User the query runs for.
     * @return string|null
     */
    private static function course_filter_rejection(string $code, int $userid): ?string {
        $code = trim((string) preg_replace('/\s+/', ' ', str_replace(self::BACKQUOTE, '', $code)));
        $code = rtrim($code, '; ');
        $instances = self::table_instances($code);
        $scoped = array_filter($instances, static fn(array $instance): bool => $instance['scope'] !== null);
        if (empty($scoped)) {
            return null;
        }
        // Only plain conjunctions can be checked: anything that could widen the rows is refused.
        $words = '/\b(?:' . implode('|', self::UNFILTERABLE_WORDS) . ')\b|\|\||\bnot\s*\(|\bnot\s+[a-z0-9_.]+\s*(?:=|<=>|\bin\b)/';
        if (preg_match($words, $code) || preg_match_all('/\bselect\b/', $code) !== 1) {
            return 'sql_course_unfiltered_rejected';
        }
        $where = preg_match('/\bwhere\b(.*?)(?:\bgroup\s+by\b|\border\s+by\b|\bhaving\b|\blimit\b|$)/', $code, $m)
            ? self::conjuncts($m[1]) : [];
        $global = $where;
        foreach ($instances as $instance) {
            if ($instance['kind'] === 'inner') {
                $global = array_merge($global, $instance['on']);
            }
        }
        $single = count($instances) === 1;
        $restricted = [];
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
     * @param array<int, true> $restricted Instances already known to be limited.
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
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $sql);
        $sql = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $sql);
        $sql = preg_replace('/--[^\n]*|#[^\n]*|\/\*.*?\*\//s', ' ', $sql);
        return strtolower((string) $sql);
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
