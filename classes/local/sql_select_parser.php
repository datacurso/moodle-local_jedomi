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
 * Tokenizer and parser of the small SELECT subset a user who does not manage every course may run.
 *
 * Every token of the query must fit this grammar, so nothing the guard cannot follow (CASE, OR, NOT, subqueries,
 * UNION, comments, quoted identifiers, variables, other functions...) can reach the database:
 *
 *     SELECT [DISTINCT] item [, item]... FROM table [, table | [INNER] JOIN table ON conditions
 *         | LEFT [OUTER] JOIN table ON conditions]... [WHERE conditions] [GROUP BY expr [, expr]...]
 *         [HAVING conditions] [ORDER BY expr [ASC|DESC] [, ...]] [LIMIT number [OFFSET number]]
 *     item       := * | qualifier.* | expr [[AS] alias]
 *     table      := {name} | prefixed_name, with an optional [AS] alias
 *     expr       := column | qualifier.column | number | 'string' | COUNT(*) | function([DISTINCT] expr [, expr]...)
 *     function   := COUNT | SUM | AVG | MIN | MAX | ROUND | COALESCE
 *     conditions := condition [AND condition]...
 *     condition  := expr (= | <> | != | < | <= | > | >=) expr | expr IN (number [, number]...) | expr IS [NOT] NULL
 *                 | expr LIKE 'string'
 *
 * HAVING only filters rows the WHERE clause already limited, so its conditions never count as a course filter. Functions
 * that exist in a single database (such as FROM_UNIXTIME, which PostgreSQL lacks) are outside the subset.
 *
 * Placeholders (? and :name) take the value of their parameter: a whole number becomes a number, anything else a string.
 * A violation is reported with a moodle_exception whose error code is the rejection reason of sql_guard.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sql_select_parser {
    /** @var string[] Functions allowed in the subset, always called with parentheses. */
    private const FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max', 'round', 'coalesce'];

    /** @var string[] Functions that accept DISTINCT before their argument. */
    private const AGGREGATES = ['count', 'sum', 'avg', 'min', 'max'];

    /** @var string[] Comparison operators allowed in the subset. */
    private const COMPARISONS = ['=', '<>', '!=', '<', '<=', '>', '>='];

    /**
     * @var string[] Words that are never a column or an alias, on top of the reserved words of every database Moodle
     *      supports: the keywords of the subset and keywords some databases accept unreserved but read as clauses.
     */
    private const KEYWORDS = ['select', 'distinct', 'from', 'join', 'inner', 'left', 'outer', 'on', 'where', 'and', 'in',
        'is', 'not', 'null', 'like', 'group', 'order', 'by', 'asc', 'desc', 'limit', 'offset', 'as', 'or', 'xor', 'case',
        'when', 'then', 'else', 'end', 'union', 'intersect', 'except', 'minus', 'with', 'recursive', 'lateral', 'window',
        'over', 'partition', 'returning', 'having', 'using', 'natural', 'right', 'full', 'cross', 'straight_join', 'table',
        'values', 'into', 'for', 'lock', 'fetch', 'qualify', 'json_table', 'between', 'regexp', 'rlike', 'sounds', 'div',
        'mod', 'escape', 'collate', 'binary', 'interval', 'exists', 'any', 'some', 'all', 'true', 'false', 'unknown',
        'current_role', 'rownum', 'sysdate', 'procedure', 'use', 'force', 'ignore', 'only', 'ilike', 'similar'];

    /** @var array[] Tokens of the query, each with a type and a value. */
    private array $tokens;

    /** @var int Position of the next token. */
    private int $pos = 0;

    /** @var string Database prefix in lowercase. */
    private string $prefix;

    /** @var array[] Table instances: table, ref, kind (from, inner or left) and on (conditions of the join). */
    private array $instances = [];

    /** @var array[] Conditions of the WHERE clause. */
    private array $where = [];

    /** @var array[] Every column reference: qualifier (null when unqualified), column and clause. */
    private array $columns = [];

    /** @var string[] Aliases given to the select items. */
    private array $aliases = [];

    /** @var array Qualifiers of the select items that read every column ("*" gives null). */
    private array $stars = [];

    /** @var bool[]|null Words that cannot be a column or an alias (as keys), loaded once. */
    private static ?array $reserved = null;

    /**
     * Constructor.
     *
     * @param array[] $tokens Tokens from tokenize().
     * @param string $prefix Database prefix in lowercase.
     */
    private function __construct(array $tokens, string $prefix) {
        $this->tokens = $tokens;
        $this->prefix = $prefix;
    }

    /**
     * Split a query into tokens, MySQL style: words (lowercase), {table} placeholders, numbers, single-quoted strings
     * (with backslash escapes and doubled quotes), operators, punctuation, comments and anything else.
     *
     * Placeholders become a number token (whole number parameter) or a string token (any other value). Whitespace is
     * left out; comments and unknown characters are kept as tokens so the parser rejects them.
     *
     * @param string $sql Query.
     * @param array $params Positional or named parameters.
     * @return array[] Tokens, each with type (word, table, number, string, op, punct, comment or other) and value.
     * @throws \moodle_exception sql_construct_rejected when the regular expression fails, so no query is read in part.
     */
    public static function tokenize(string $sql, array $params): array {
        // The values the database binds, read as moodle_database::fix_sql_params() reads them: question marks take the
        // values in order whatever their keys, a named placeholder takes the value of its exact key (JEDOMI-SEC-015).
        // sql_guard::params_rejection() refuses beforehand any parameters that could be read in another way.
        $positional = array_values($params);
        $named = $params;
        $pattern = '/\G(?:(?<space>[ \t\r\n]+)|(?<comment>\/\*.*?(?:\*\/|$)|--[^\n]*|#[^\n]*)'
            . '|(?<table>\{[a-z][a-z0-9_]*\})|(?<word>[A-Za-z_][A-Za-z0-9_]*)|(?<number>\d+(?:\.\d+)?)(?![A-Za-z0-9_.])'
            . "|(?<string>'(?:[^'\\\\]|\\\\.|'')*')|(?<op><=>|<=|>=|<>|!=|[=<>])|(?<punct>[,.()*])"
            . '|(?<param>\?|:[a-z][a-z0-9_]*)|(?<other>.))/s';
        $tokens = [];
        $offset = 0;
        $index = 0;
        $length = strlen($sql);
        while ($offset < $length) {
            $matched = preg_match($pattern, $sql, $m, PREG_UNMATCHED_AS_NULL, $offset);
            if ($matched === false) {
                // A query read only in part could pass for less than it is (RISK-004).
                throw new \moodle_exception('sql_construct_rejected', 'local_jedomi');
            }
            if ($matched === 0) {
                break;
            }
            $offset += strlen($m[0]);
            foreach (['space', 'comment', 'table', 'word', 'number', 'string', 'op', 'punct', 'param', 'other'] as $type) {
                if ($m[$type] !== null) {
                    break;
                }
            }
            if ($type === 'space') {
                continue;
            }
            $value = $m[$type];
            if ($type === 'param') {
                $param = $value === '?' ? ($positional[$index++] ?? null) : ($named[substr($value, 1)] ?? null);
                $whole = is_int($param) || is_bool($param) || (is_string($param) && ctype_digit($param));
                $type = $whole ? 'number' : 'string';
                $value = $whole ? (string) (int) $param : '';
            } else if ($type === 'word') {
                $value = strtolower($value);
            } else if ($type === 'table') {
                $value = substr($value, 1, -1);
            }
            $tokens[] = ['type' => $type, 'value' => $value];
        }
        return $tokens;
    }

    /**
     * Parse the tokens of a query of the subset.
     *
     * @param array[] $tokens Tokens from tokenize().
     * @param string $prefix Database prefix in lowercase.
     * @return array Instances, where (conditions), columns, aliases and stars of the query.
     * @throws \moodle_exception sql_construct_rejected, or sql_table_not_allowed for a table outside this site.
     */
    public static function parse(array $tokens, string $prefix): array {
        $parser = new self($tokens, $prefix);
        $parser->query();
        return [
            'instances' => $parser->instances,
            'where' => $parser->where,
            'columns' => $parser->columns,
            'aliases' => $parser->aliases,
            'stars' => $parser->stars,
        ];
    }

    /**
     * Parse the whole query.
     */
    private function query(): void {
        $this->expect('select');
        $this->accept('distinct');
        do {
            $this->select_item();
        } while ($this->accept_punct(','));
        $this->expect('from');
        $this->table('from');
        while (true) {
            if ($this->accept_punct(',')) {
                $this->table('from');
            } else if ($this->accept('join') || ($this->accept('inner') && $this->expect('join'))) {
                $this->joined_table('inner');
            } else if ($this->accept('left')) {
                $this->accept('outer');
                $this->expect('join');
                $this->joined_table('left');
            } else {
                break;
            }
        }
        if ($this->accept('where')) {
            $this->where = $this->conditions('where');
        }
        if ($this->accept('group')) {
            $this->expect('by');
            do {
                $this->expr('group');
            } while ($this->accept_punct(','));
        }
        if ($this->accept('having')) {
            // Parsed for its columns only: the course filter must be in WHERE or in the join of the table.
            $this->conditions('having');
        }
        if ($this->accept('order')) {
            $this->expect('by');
            do {
                $this->expr('order');
                if (!$this->accept('asc')) {
                    $this->accept('desc');
                }
            } while ($this->accept_punct(','));
        }
        if ($this->accept('limit')) {
            $this->number();
            if ($this->accept('offset')) {
                $this->number();
            }
        }
        if ($this->pos < count($this->tokens)) {
            $this->reject();
        }
    }

    /**
     * Parse one item of the select list.
     */
    private function select_item(): void {
        if ($this->accept_punct('*')) {
            $this->stars[] = null;
            return;
        }
        if ($this->is_punct(1, '.') && $this->is_punct(2, '*')) {
            $this->stars[] = $this->qualifier();
            $this->pos += 3;
            return;
        }
        $this->expr('select');
        $alias = $this->alias();
        if ($alias !== null) {
            $this->aliases[] = $alias;
        }
    }

    /**
     * Parse a table after a JOIN, with its ON conditions.
     *
     * @param string $kind Kind of join: inner or left.
     */
    private function joined_table(string $kind): void {
        $index = $this->table($kind);
        $this->expect('on');
        $this->instances[$index]['on'] = $this->conditions('on');
    }

    /**
     * Parse a table of the FROM clause with its alias.
     *
     * @param string $kind How it is joined: from, inner or left.
     * @return int Index of the new instance.
     * @throws \moodle_exception sql_table_not_allowed for a table of another database or without the prefix.
     */
    private function table(string $kind): int {
        $token = $this->tokens[$this->pos] ?? null;
        if ($token !== null && $token['type'] === 'table') {
            $table = $token['value'];
            $ref = $this->prefix . $table;
        } else if ($token !== null && $token['type'] === 'word' && !$this->is_reserved($token['value'])) {
            if ($this->is_punct(1, '.')) {
                throw new \moodle_exception('sql_table_not_allowed', 'local_jedomi');
            }
            $ref = $token['value'];
            if ($this->prefix === '') {
                $table = $ref;
            } else if (strpos($ref, $this->prefix) === 0) {
                $table = substr($ref, strlen($this->prefix));
            } else {
                throw new \moodle_exception('sql_table_not_allowed', 'local_jedomi');
            }
        } else {
            $this->reject();
        }
        $this->pos++;
        $ref = $this->alias() ?? $ref;
        foreach ($this->instances as $instance) {
            if ($instance['ref'] === $ref) {
                $this->reject();
            }
        }
        $this->instances[] = ['table' => $table, 'ref' => $ref, 'kind' => $kind, 'on' => []];
        return count($this->instances) - 1;
    }

    /**
     * Parse an optional alias, with or without AS.
     *
     * @return string|null
     */
    private function alias(): ?string {
        $explicit = $this->accept('as');
        $token = $this->tokens[$this->pos] ?? null;
        if ($token !== null && $token['type'] === 'word' && !$this->is_reserved($token['value'])) {
            $this->pos++;
            return $token['value'];
        }
        if ($explicit) {
            $this->reject();
        }
        return null;
    }

    /**
     * Parse conditions joined by AND.
     *
     * @param string $clause Clause the conditions belong to.
     * @return array[] Conditions: op (a comparison, in, null or like), left and right operands, values of IN.
     */
    private function conditions(string $clause): array {
        $conditions = [];
        do {
            $left = $this->expr($clause);
            $token = $this->tokens[$this->pos] ?? null;
            if ($token !== null && $token['type'] === 'op' && in_array($token['value'], self::COMPARISONS, true)) {
                $this->pos++;
                $conditions[] = ['op' => $token['value'], 'left' => $left, 'right' => $this->expr($clause)];
            } else if ($this->accept('in')) {
                $this->expect_punct('(');
                $values = [];
                do {
                    $values[] = $this->number();
                } while ($this->accept_punct(','));
                $this->expect_punct(')');
                $conditions[] = ['op' => 'in', 'left' => $left, 'values' => $values];
            } else if ($this->accept('is')) {
                $this->accept('not');
                $this->expect('null');
                $conditions[] = ['op' => 'null', 'left' => $left];
            } else if ($this->accept('like')) {
                $this->expect_type('string');
                $conditions[] = ['op' => 'like', 'left' => $left];
            } else {
                $this->reject();
            }
        } while ($this->accept('and'));
        return $conditions;
    }

    /**
     * Parse an expression: a column, a number, a string or a call to an allowed function.
     *
     * @param string $clause Clause the expression belongs to.
     * @return array Operand: type (column, number, string or function), with qualifier and column, or value.
     */
    private function expr(string $clause): array {
        $token = $this->tokens[$this->pos] ?? ['type' => 'end', 'value' => ''];
        if ($token['type'] === 'number' || $token['type'] === 'string') {
            $this->pos++;
            return ['type' => $token['type'], 'value' => $token['value']];
        }
        if ($token['type'] === 'word' && $this->is_punct(1, '(')) {
            if (!in_array($token['value'], self::FUNCTIONS, true)) {
                $this->reject();
            }
            $this->pos += 2;
            if ($token['value'] === 'count' && $this->accept_punct('*')) {
                $this->expect_punct(')');
                return ['type' => 'function'];
            }
            if (in_array($token['value'], self::AGGREGATES, true)) {
                $this->accept('distinct');
            }
            do {
                $this->expr($clause);
            } while ($this->accept_punct(','));
            $this->expect_punct(')');
            return ['type' => 'function'];
        }
        $qualifier = null;
        if ($this->is_punct(1, '.')) {
            $qualifier = $this->qualifier();
            $this->pos += 2;
        }
        $column = $this->tokens[$this->pos] ?? null;
        if ($column === null || $column['type'] !== 'word' || $this->is_reserved($column['value'])) {
            $this->reject();
        }
        $this->pos++;
        $this->columns[] = ['qualifier' => $qualifier, 'column' => $column['value'], 'clause' => $clause];
        return ['type' => 'column', 'qualifier' => $qualifier, 'column' => $column['value']];
    }

    /**
     * Read the qualifier at the current position (an alias, a prefixed table name or a {table} placeholder).
     *
     * @return string Qualifier as the table instances name it.
     */
    private function qualifier(): string {
        $token = $this->tokens[$this->pos] ?? null;
        if ($token !== null && $token['type'] === 'table') {
            return $this->prefix . $token['value'];
        }
        if ($token === null || $token['type'] !== 'word' || $this->is_reserved($token['value'])) {
            $this->reject();
        }
        return $token['value'];
    }

    /**
     * Read a number token.
     *
     * @return string
     */
    private function number(): string {
        return $this->expect_type('number');
    }

    /**
     * Consume the given keyword when it comes next.
     *
     * @param string $word Keyword in lowercase.
     * @return bool
     */
    private function accept(string $word): bool {
        $token = $this->tokens[$this->pos] ?? null;
        if ($token !== null && $token['type'] === 'word' && $token['value'] === $word) {
            $this->pos++;
            return true;
        }
        return false;
    }

    /**
     * Consume the given keyword or reject the query.
     *
     * @param string $word Keyword in lowercase.
     * @return bool Always true.
     */
    private function expect(string $word): bool {
        if (!$this->accept($word)) {
            $this->reject();
        }
        return true;
    }

    /**
     * Consume the given punctuation when it comes next.
     *
     * @param string $punct Punctuation.
     * @return bool
     */
    private function accept_punct(string $punct): bool {
        if ($this->is_punct(0, $punct)) {
            $this->pos++;
            return true;
        }
        return false;
    }

    /**
     * Consume the given punctuation or reject the query.
     *
     * @param string $punct Punctuation.
     */
    private function expect_punct(string $punct): void {
        if (!$this->accept_punct($punct)) {
            $this->reject();
        }
    }

    /**
     * Consume a token of the given type or reject the query.
     *
     * @param string $type Token type.
     * @return string Value of the token.
     */
    private function expect_type(string $type): string {
        $token = $this->tokens[$this->pos] ?? null;
        if ($token === null || $token['type'] !== $type) {
            $this->reject();
        }
        $this->pos++;
        return $token['value'];
    }

    /**
     * Tell whether the token at a distance from the current position is the given punctuation.
     *
     * @param int $ahead Distance from the current position.
     * @param string $punct Punctuation.
     * @return bool
     */
    private function is_punct(int $ahead, string $punct): bool {
        $token = $this->tokens[$this->pos + $ahead] ?? null;
        return $token !== null && $token['type'] === 'punct' && $token['value'] === $punct;
    }

    /**
     * Tell whether a word is a keyword or a reserved word of a database Moodle supports, so it is never a name.
     *
     * @param string $word Word in lowercase.
     * @return bool
     */
    private function is_reserved(string $word): bool {
        global $CFG;
        if (self::$reserved === null) {
            require_once($CFG->libdir . '/ddllib.php');
            $words = array_merge(array_keys(\sql_generator::getAllReservedWords()), self::KEYWORDS);
            self::$reserved = array_fill_keys($words, true);
        }
        return isset(self::$reserved[$word]);
    }

    /**
     * Reject the query as outside the subset.
     *
     * @return never
     * @throws \moodle_exception sql_construct_rejected
     */
    private function reject(): never {
        throw new \moodle_exception('sql_construct_rejected', 'local_jedomi');
    }
}
