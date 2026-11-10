<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_jedomi\external;

/**
 * Tests for the read-only database query used by the AI tools.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\execute_sql
 */
final class execute_sql_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the web service checks the plugin state through it.');
        }
    }

    /**
     * Run a query as the chat proxy does (authenticated by the chat permissions, not by HMAC).
     *
     * @param string $sql Query.
     * @return array Result of the web service.
     */
    private function run_query(string $sql): array {
        return execute_sql::execute($sql, [], '', 0, '', true);
    }

    /**
     * Statements that are not a read query.
     *
     * @return array<string, array{0: string}>
     */
    public static function non_read_statement_provider(): array {
        return [
            'update' => ["UPDATE {course} SET fullname = 'Changed' WHERE id = :id"],
            'delete' => ['DELETE FROM {course} WHERE id = :id'],
            'insert' => ["INSERT INTO {course_categories} (name) VALUES ('Injected')"],
            'drop' => ['DROP TABLE {course}'],
            'truncate' => ['TRUNCATE TABLE {course}'],
            'administrative statement' => ['SET GLOBAL max_connections = 1'],
            'show' => ['SHOW TABLES'],
            'read query writing a file' => ["SELECT fullname FROM {course} INTO OUTFILE '/tmp/jedomi_courses.txt'"],
            'leading spaces before a write' => ["   \n DELETE FROM {course} WHERE id = :id"],
        ];
    }

    /**
     * MDL-UNIT-019: any statement that is not a read query is rejected without running.
     *
     * @dataProvider non_read_statement_provider
     * @param string $sql Statement.
     */
    public function test_statements_that_are_not_read_queries_are_rejected(string $sql): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Untouched']);
        $sql = str_replace(':id', (string) (int) $course->id, $sql);

        try {
            $this->run_query($sql);
            $this->fail('A statement that is not a read query must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('non_select_rejected', $e->errorcode);
            $this->assertSame(get_string('non_select_rejected', 'local_jedomi'), $e->getMessage());
        }
        $this->assertSame('Untouched', $DB->get_field('course', 'fullname', ['id' => $course->id]));
    }

    /**
     * MDL-UNIT-019: an empty query is rejected with a clear message.
     */
    public function test_empty_query_is_rejected_with_a_clear_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        try {
            $this->run_query('   ');
            $this->fail('An empty query must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('non_select_rejected', 'local_jedomi'), $e->getMessage());
        }
    }

    /**
     * MDL-UNIT-019: a valid read query on allowed tables runs and returns its rows.
     */
    public function test_valid_read_query_returns_rows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Readable course']);

        $result = $this->run_query('SELECT id, fullname FROM {course} WHERE id = ' . (int) $course->id);

        $this->assertSame(1, $result['row_count']);
        $this->assertFalse($result['truncated']);
        $row = json_decode($result['rows'][0]['json_row'], true);
        $this->assertSame('Readable course', $row['fullname']);
    }

    /**
     * MDL-UNIT-019: without the required privilege the query is denied.
     */
    public function test_query_is_denied_without_the_privilege(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        try {
            $this->run_query('SELECT id FROM {course}');
            $this->fail('A user without the privilege must not run queries');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        // The capability has no language string yet, which only raises a developer notice.
        $this->resetDebugging();
    }

    /**
     * Queries that read credentials, keys, tokens, site configuration or sessions, written in several ways.
     *
     * @return array<string, array{0: string}>
     */
    public static function sensitive_query_provider(): array {
        return [
            'site configuration' => ['SELECT name, value FROM {config}'],
            'plugin settings and keys' => ['SELECT plugin, name, value FROM {config_plugins}'],
            'sessions' => ['SELECT sid, userid FROM {sessions}'],
            'web service tokens' => ['SELECT token, userid FROM {external_tokens}'],
            'password hashes' => ['SELECT username, password FROM {user}'],
            'token in a join' => ['SELECT u.username, t.token FROM {user} u JOIN {external_tokens} t ON t.userid = u.id'],
            'session in a subquery' => ['SELECT id, username FROM {user} WHERE id IN (SELECT userid FROM {sessions})'],
            'plugin keys with an alias' => ["SELECT cp.value FROM {config_plugins} cp WHERE cp.name = 'spt_secret'"],
            'configuration in a union' => ['SELECT fullname FROM {course} UNION SELECT value FROM {config}'],
        ];
    }

    /**
     * MDL-UNIT-020: no query can read credentials, keys, tokens, site configuration or sessions, however it is written.
     *
     * @dataProvider sensitive_query_provider
     * @param string $sql Query.
     */
    public function test_queries_on_sensitive_tables_are_rejected(string $sql): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user();

        try {
            $result = $this->run_query($sql);
        } catch (\moodle_exception $e) {
            // A database error is not a rejection: the query was sent to the database.
            $this->assertNotContains($e->errorcode, ['execute_sql_error', 'query_timeout'], 'The query must be rejected before running');
            return;
        }
        $this->fail('A query reading sensitive tables must be rejected; it returned ' . $result['row_count'] . ' row(s)');
    }
}
