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

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the time limit and the row cap of the SQL tool of the agents.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\sql_tool
 */
final class sql_tool_test extends \advanced_testcase {
    /**
     * Run a query through the tool as the administrator with a recording database reporting the given type.
     *
     * @param string $vendor Database vendor (MySQL family).
     * @param string $query Query.
     * @param \Throwable|null $failure Failure of the read query.
     * @return array Decoded answer of the tool and the recording database.
     */
    private function run_with_database(string $vendor, string $query, ?\Throwable $failure = null): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/recording_database.php');
        $realdb = $DB;
        $fake = new \local_jedomi\recording_database($realdb, 'mysql', $vendor);
        $fake->failure = $failure;
        try {
            $DB = $fake;
            $answer = (new sql_tool(0, (int) get_admin()->id))->execute((object) ['query' => $query]);
        } finally {
            $DB = $realdb;
        }
        return [json_decode($answer, true), $fake];
    }

    /**
     * MDL-INT-043: on MySQL the agents' query carries its own time limit and row cap and leaves no session variable
     * behind.
     */
    public function test_mysql_query_is_limited_without_a_session_variable(): void {
        $this->resetAfterTest();

        [$answer, $db] = $this->run_with_database('mysql', 'SELECT id FROM {course}');

        $this->assertTrue($answer['success']);
        $this->assertStringContainsString('/*+ MAX_EXECUTION_TIME(30000) */', $db->queries[0]['sql']);
        $this->assertSame(1001, $db->queries[0]['limitnum']);
        $this->assertSame([], $db->statements, 'No SET SESSION is left on the connection');
    }

    /**
     * MDL-INT-043: On MariaDB the agents' query is prefixed with its own statement time limit.
     */
    public function test_mariadb_query_gets_the_statement_time_limit(): void {
        $this->resetAfterTest();

        [, $db] = $this->run_with_database('mariadb', 'SELECT id FROM {course}');

        $this->assertStringStartsWith('SET STATEMENT max_statement_time=30.000 FOR SELECT', $db->queries[0]['sql']);
        $this->assertSame([], $db->statements);
    }

    /**
     * MDL-INT-043: A query stopped by the time limit is reported as a timeout.
     */
    public function test_query_stopped_by_the_time_limit_reports_a_timeout(): void {
        $this->resetAfterTest();
        $failure = new \dml_read_exception('Query execution was interrupted, maximum statement execution time exceeded', '', []);

        [$answer] = $this->run_with_database('mysql', 'SELECT id FROM {course}', $failure);

        $this->assertSame(get_string('query_timeout', 'local_jedomi'), $answer['error']);
    }

    /**
     * MDL-INT-043: Statements whose time and rows cannot be bounded are refused.
     */
    public function test_statement_without_select_is_refused(): void {
        $this->resetAfterTest();

        [$answer, $db] = $this->run_with_database('mysql', 'SHOW TABLES');

        $this->assertArrayHasKey('error', $answer);
        $this->assertSame([], $db->queries);
    }

    /**
     * MDL-INT-043: On the real database the tool returns the rows and tells whether the cap truncated them.
     */
    public function test_real_database_returns_the_rows(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_course();

        $answer = json_decode((new sql_tool(0, (int) get_admin()->id))->execute(
            (object) ['query' => 'SELECT id FROM {course} ORDER BY id']
        ), true);

        $this->assertTrue($answer['success']);
        $this->assertSame(2, $answer['row_count']);
        $this->assertFalse($answer['truncated']);
    }
}
