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
 * Tests for the time limit and the row cap of the read-only query runner.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\query_runner
 */
final class query_runner_test extends \advanced_testcase {
    /** @var string MySQL optimizer hint for the time limit of the chat channels. */
    private const MYSQL_HINT = '/*+ MAX_EXECUTION_TIME(5000) */';

    /**
     * Run a test with a recording database reporting the given type.
     *
     * @param string $family Database family.
     * @param string $vendor Database vendor.
     * @param callable $test Test, given the recording database.
     */
    private function with_database(string $family, string $vendor, callable $test): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/recording_database.php');
        $realdb = $DB;
        $fake = new \local_jedomi\recording_database($realdb, $family, $vendor);
        try {
            $DB = $fake;
            $test($fake);
        } finally {
            $DB = $realdb;
        }
    }

    /**
     * MDL-UNIT-121: on MySQL the time limit is an optimizer hint right after the leading SELECT, whatever its case and
     * the whitespace before it, and the row cap is given to the database.
     */
    public function test_mysql_query_gets_the_time_limit_hint_and_the_row_cap(): void {
        $query = query_runner::bounded_query("  \n\tselect id, fullname FROM {course}", 'mysql', 5000, 101);

        $this->assertSame("  \n\tselect " . self::MYSQL_HINT . ' id, fullname FROM {course}', $query['sql']);
        $this->assertSame(101, $query['limitnum']);
    }

    /**
     * MDL-UNIT-121: on MySQL a query with common table expressions gets the hint on its main SELECT, not inside an
     * expression.
     */
    public function test_mysql_hint_goes_to_the_main_select_of_a_with_query(): void {
        $query = query_runner::bounded_query('WITH c AS (SELECT id FROM {course}) SELECT id FROM c', 'mysql', 30000, 1001);

        $this->assertSame(
            'WITH c AS (SELECT id FROM {course}) SELECT /*+ MAX_EXECUTION_TIME(30000) */ id FROM c',
            $query['sql']
        );
    }

    /**
     * MDL-UNIT-121: On MariaDB the time limit is a SET STATEMENT prefix of the query itself, in seconds.
     */
    public function test_mariadb_query_gets_the_set_statement_prefix(): void {
        $query = query_runner::bounded_query('SELECT id FROM {course}', 'mariadb', 5000, 101);

        $this->assertSame('SET STATEMENT max_statement_time=5.000 FOR SELECT id FROM {course}', $query['sql']);
        $this->assertSame(101, $query['limitnum']);
    }

    /**
     * MDL-UNIT-121: on PostgreSQL and the databases without a per-query limit the query text is not changed, only the
     * row cap is set.
     *
     * @dataProvider unchanged_variant_provider
     * @param string $variant Database variant.
     */
    public function test_query_text_is_unchanged_without_a_per_query_hint(string $variant): void {
        $query = query_runner::bounded_query('SELECT id FROM {course}', $variant, 5000, 51);

        $this->assertSame('SELECT id FROM {course}', $query['sql']);
        $this->assertSame(51, $query['limitnum']);
    }

    /**
     * Variants whose query text carries no time limit.
     *
     * @return array
     */
    public static function unchanged_variant_provider(): array {
        return ['postgres' => ['postgres'], 'other databases' => ['none']];
    }

    /**
     * MDL-UNIT-121: A LIMIT of the query itself is capped to the rows the runner reads instead of adding a second one.
     *
     * @dataProvider own_limit_provider
     * @param string $variant Database variant.
     * @param string $sql Query.
     * @param string $expected Query with the capped limit (without the time limit).
     */
    public function test_own_limit_of_the_query_is_capped(string $variant, string $sql, string $expected): void {
        $query = query_runner::bounded_query($sql, $variant, 5000, 101);

        $this->assertSame($expected, $query['sql']);
        $this->assertSame(0, $query['limitnum'], 'No second limit is added');
    }

    /**
     * Queries with their own limit.
     *
     * @return array
     */
    public static function own_limit_provider(): array {
        return [
            'larger limit' => ['postgres', 'SELECT id FROM {course} LIMIT 5000', 'SELECT id FROM {course} LIMIT 101'],
            'smaller limit kept' => ['postgres', 'SELECT id FROM {course} limit 7', 'SELECT id FROM {course} limit 7'],
            'offset and count' => ['postgres', 'SELECT id FROM {course} LIMIT 10, 900', 'SELECT id FROM {course} LIMIT 10, 101'],
            'limit and offset' => [
                'postgres',
                'SELECT id FROM {course} LIMIT 900 OFFSET 3',
                'SELECT id FROM {course} LIMIT 101 OFFSET 3',
            ],
            'limit all' => ['postgres', 'SELECT id FROM {course} LIMIT ALL', 'SELECT id FROM {course} LIMIT 101'],
            'fetch first' => [
                'postgres',
                'SELECT id FROM {course} FETCH FIRST 900 ROWS ONLY',
                'SELECT id FROM {course} FETCH FIRST 101 ROWS ONLY',
            ],
            'mysql with hint' => [
                'mysql',
                "SELECT id FROM {course} WHERE fullname <> 'limit 3' LIMIT 900",
                'SELECT ' . self::MYSQL_HINT . " id FROM {course} WHERE fullname <> 'limit 3' LIMIT 101",
            ],
        ];
    }

    /**
     * MDL-UNIT-121: A LIMIT inside a subquery, a string, a quoted name or a comment is not the limit of the query.
     *
     * @dataProvider inner_limit_provider
     * @param string $sql Query.
     */
    public function test_limit_that_is_not_the_query_limit_is_left_alone(string $sql): void {
        $query = query_runner::bounded_query($sql, 'postgres', 5000, 101);

        $this->assertSame($sql, $query['sql']);
        $this->assertSame(101, $query['limitnum']);
    }

    /**
     * Queries whose LIMIT word is not the limit of the query.
     *
     * @return array
     */
    public static function inner_limit_provider(): array {
        return [
            'subquery' => ['SELECT id FROM (SELECT id FROM {course} LIMIT 3) c'],
            'string' => ["SELECT id FROM {course} WHERE fullname = 'x limit 3'"],
            'comment' => ['SELECT id /* limit 3 */ FROM {course}'],
        ];
    }

    /**
     * MDL-UNIT-121: a trailing semicolon or comment is removed, so the row cap Moodle appends cannot be commented out
     * or follow the end of the statement.
     *
     * @dataProvider trailing_provider
     * @param string $variant Database variant.
     * @param string $sql Query.
     */
    public function test_trailing_comment_or_semicolon_cannot_hide_the_row_cap(string $variant, string $sql): void {
        $query = query_runner::bounded_query($sql, $variant, 5000, 101);

        $this->assertSame('SELECT id FROM {course}', $query['sql']);
        $this->assertSame(101, $query['limitnum']);
    }

    /**
     * Queries ending in a semicolon or a comment.
     *
     * @return array
     */
    public static function trailing_provider(): array {
        return [
            'semicolon' => ['postgres', 'SELECT id FROM {course} ; '],
            'line comment' => ['postgres', "SELECT id FROM {course} -- all of them\n"],
            'line comment without line break' => ['postgres', 'SELECT id FROM {course} -- all of them'],
            'semicolon without space' => ['none', 'SELECT id FROM {course};'],
            'block comment' => ['postgres', 'SELECT id FROM {course} /* all */'],
        ];
    }

    /**
     * MDL-UNIT-121: a query that cannot be bounded safely is refused: a limit that is not a number, an unterminated
     * string or comment, or a statement without a SELECT.
     *
     * @dataProvider unbounded_provider
     * @param string $variant Database variant.
     * @param string $sql Query.
     */
    public function test_query_that_cannot_be_bounded_is_refused(string $variant, string $sql): void {
        $this->assertNull(query_runner::bounded_query($sql, $variant, 5000, 101));
    }

    /**
     * Queries that cannot be bounded.
     *
     * @return array
     */
    public static function unbounded_provider(): array {
        return [
            'limit placeholder' => ['postgres', 'SELECT id FROM {course} LIMIT :n'],
            'limit expression' => ['mysql', 'SELECT id FROM {course} LIMIT (SELECT 5)'],
            'count placeholder after an offset' => ['mysql', 'SELECT id FROM {course} LIMIT 10, :n'],
            'fetch placeholder' => ['postgres', 'SELECT id FROM {course} FETCH FIRST :n ROWS ONLY'],
            'unterminated string' => ['mysql', "SELECT id FROM {course} WHERE fullname = 'x"],
            'unterminated comment' => ['postgres', 'SELECT id FROM {course} /* x'],
            'no select' => ['mysql', 'SHOW TABLES'],
        ];
    }

    /**
     * MDL-INT-043: On MySQL run() sends the hint and the row cap to the database and sets no session variable.
     */
    public function test_run_on_mysql_sends_the_hint_and_the_row_cap(): void {
        $this->resetAfterTest();
        $this->with_database('mysql', 'mysql', function (\local_jedomi\recording_database $db): void {
            query_runner::run('SELECT id FROM {course}', [], get_admin()->id, 50);

            $this->assertCount(1, $db->queries);
            $this->assertStringContainsString(self::MYSQL_HINT, $db->queries[0]['sql']);
            $this->assertSame(51, $db->queries[0]['limitnum']);
            $this->assertSame([], $db->statements, 'No session variable is left on the connection');
        });
    }

    /**
     * MDL-INT-043: On MySQL served by the mysqli driver but reporting a MariaDB server the MariaDB prefix is used.
     */
    public function test_run_on_mariadb_sends_the_set_statement_prefix(): void {
        $this->resetAfterTest();
        $this->with_database('mysql', 'mariadb', function (\local_jedomi\recording_database $db): void {
            query_runner::run('SELECT id FROM {course}', [], get_admin()->id, 50);

            $this->assertStringStartsWith('SET STATEMENT max_statement_time=5.000 FOR SELECT', $db->queries[0]['sql']);
            $this->assertSame([], $db->statements);
        });
    }

    /**
     * MDL-INT-043: on PostgreSQL the session timeout is set for the query and restored afterwards, also when the query
     * fails.
     */
    public function test_run_on_postgres_sets_and_restores_the_statement_timeout(): void {
        $this->resetAfterTest();
        $this->with_database('postgres', 'postgres', function (\local_jedomi\recording_database $db): void {
            $db->failure = new \dml_read_exception('ERROR: canceling statement due to statement timeout', 'SELECT', []);

            try {
                query_runner::run('SELECT id FROM {course}', [], get_admin()->id, 50);
                $this->fail('The timeout must be reported');
            } catch (\moodle_exception $e) {
                $this->assertSame('query_timeout', $e->errorcode);
            }

            $this->assertSame([
                "SELECT current_setting('statement_timeout')",
                'SET statement_timeout = 5000',
                'SELECT set_config(?, ?, false) [statement_timeout, 0]',
            ], $db->statements);
        });
    }

    /**
     * MDL-INT-043: a query stopped by the MySQL or MariaDB time limit is reported as a timeout, not as a database
     * error.
     *
     * @dataProvider timeout_error_provider
     * @param string $vendor Database vendor.
     * @param string $error Error of the database.
     */
    public function test_query_stopped_by_the_time_limit_is_a_timeout(string $vendor, string $error): void {
        $this->resetAfterTest();
        $this->with_database('mysql', $vendor, function (\local_jedomi\recording_database $db) use ($error): void {
            $db->failure = new \dml_read_exception($error, 'SELECT', []);

            try {
                query_runner::run('SELECT id FROM {course}', [], get_admin()->id, 50);
                $this->fail('The timeout must be reported');
            } catch (\moodle_exception $e) {
                $this->assertSame('query_timeout', $e->errorcode);
            }
        });
    }

    /**
     * Errors of the databases when the time limit stops a query.
     *
     * @return array
     */
    public static function timeout_error_provider(): array {
        return [
            'mysql' => ['mysql', 'Query execution was interrupted, maximum statement execution time exceeded'],
            'mariadb' => ['mariadb', 'Query execution was interrupted (max_statement_time exceeded)'],
        ];
    }

    /**
     * MDL-INT-043: Another database error is not reported as a timeout, and carries the message of the database as debug
     * information.
     */
    public function test_other_database_error_is_not_a_timeout(): void {
        $this->resetAfterTest();
        $this->with_database('mysql', 'mysql', function (\local_jedomi\recording_database $db): void {
            $db->failure = new \dml_read_exception("Unknown column 'nope' in 'field list'", 'SELECT nope FROM mdl_x', [7]);

            try {
                query_runner::run('SELECT id FROM {course}', [], get_admin()->id, 50);
                $this->fail('The error must be reported');
            } catch (\moodle_exception $e) {
                $this->assertSame('execute_sql_error', $e->errorcode);
                // The model gets the database's own message, without the query and parameters the exception repeats.
                $this->assertSame("Unknown column 'nope' in 'field list'", $e->debuginfo);
            }
        });
    }

    /**
     * MDL-INT-043: A query whose limit cannot be capped is refused before reaching the database.
     */
    public function test_run_refuses_a_query_whose_limit_cannot_be_capped(): void {
        $this->resetAfterTest();
        $this->with_database('postgres', 'postgres', function (\local_jedomi\recording_database $db): void {
            try {
                query_runner::run('SELECT id FROM {course} LIMIT :n', ['n' => 100000], get_admin()->id, 50);
                $this->fail('The query must be refused');
            } catch (\moodle_exception $e) {
                $this->assertSame('sql_construct_rejected', $e->errorcode);
            }
            $this->assertSame([], $db->queries);
        });
    }

    /**
     * MDL-INT-043: on the real database the runner reads one row more than the cap to tell that the result was
     * truncated.
     */
    public function test_row_cap_on_the_real_database(): void {
        $this->resetAfterTest();
        for ($i = 0; $i < 4; $i++) {
            $this->getDataGenerator()->create_course();
        }
        $adminid = (int) get_admin()->id;

        $capped = query_runner::run('SELECT id FROM {course} ORDER BY id', [], $adminid, 2);
        $ownlimit = query_runner::run('SELECT id FROM {course} ORDER BY id LIMIT 500', [], $adminid, 2);
        $smalllimit = query_runner::run('SELECT id FROM {course} ORDER BY id LIMIT 2;', [], $adminid, 2);
        $all = query_runner::run('SELECT id FROM {course} ORDER BY id', [], $adminid, 50);

        $this->assertCount(2, $capped['rows']);
        $this->assertTrue($capped['truncated']);
        $this->assertCount(2, $ownlimit['rows']);
        $this->assertTrue($ownlimit['truncated']);
        $this->assertCount(2, $smalllimit['rows']);
        $this->assertFalse($smalllimit['truncated']);
        $this->assertCount(5, $all['rows']);
        $this->assertFalse($all['truncated']);
    }

    /**
     * The row limit of the web chat is the setting of the General tab (10 to 1000, 100 by default); the other channels
     * keep their fixed limits.
     *
     * @return void
     */
    public function test_web_row_limit_is_a_setting(): void {
        $this->resetAfterTest();

        unset_config('sql_row_limit_web', 'local_jedomi');
        $this->assertSame(100, query_runner::row_limit('web'));
        $this->assertSame(100, query_runner::row_limit('unknown'), 'Any other channel gets the web chat limit');

        set_config('sql_row_limit_web', '250', 'local_jedomi');
        $this->assertSame(250, query_runner::row_limit('web'));
        $this->assertSame(250, query_runner::row_limit('unknown'));
        $this->assertSame(50, query_runner::row_limit('telegram'));
        $this->assertSame(100, query_runner::row_limit('companion'));
        $this->assertSame(1000, query_runner::row_limit('agent'));

        set_config('sql_row_limit_web', '5', 'local_jedomi');
        $this->assertSame(query_runner::WEB_ROW_LIMIT_MIN, query_runner::row_limit('web'));
        set_config('sql_row_limit_web', '5000', 'local_jedomi');
        $this->assertSame(query_runner::WEB_ROW_LIMIT_MAX, query_runner::row_limit('web'));
        set_config('sql_row_limit_web', 'abc', 'local_jedomi');
        $this->assertSame(100, query_runner::row_limit('web'));
    }
}
