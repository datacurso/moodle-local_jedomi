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
 * Tests for the shared guard of the database query offered to the AI.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\sql_guard
 */
final class sql_guard_test extends \advanced_testcase {
    /**
     * A teacher of one course and another course they do not teach.
     *
     * @return array{0: int, 1: int, 2: int} Teacher id, own course id and other course id.
     */
    private function teacher(): array {
        $this->resetAfterTest();
        $own = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $own->id, 'editingteacher');
        return [(int) $teacher->id, (int) $own->id, (int) $other->id];
    }

    /**
     * Put the course ids in a query written with OWN and OTHER.
     *
     * @param string $sql Query.
     * @param int $own Own course id.
     * @param int $other Other course id.
     * @return string
     */
    private static function with_ids(string $sql, int $own, int $other): string {
        return str_replace(['OWN', 'OTHER'], [(string) $own, (string) $other], $sql);
    }

    /**
     * Queries on course data that are not limited to the courses of the teacher.
     *
     * @return array<string, array{0: string}>
     */
    public static function unfiltered_query_provider(): array {
        return [
            'every course' => ['SELECT fullname FROM {course}'],
            'every enrolment method' => ['SELECT * FROM {enrol}'],
            'enrolments of every course' => ['SELECT u.id FROM {user_enrolments} ue JOIN {user} u ON u.id = ue.userid'],
            'every grade' => ['SELECT finalgrade FROM {grade_grades}'],
            'every log' => ['SELECT * FROM {logstore_standard_log}'],
            'every role assignment' => ['SELECT userid FROM {role_assignments}'],
            'filter widened with or' => ['SELECT fullname FROM {course} WHERE id = OWN OR 1 = 1'],
            'union with every course' => ['SELECT fullname FROM {course} WHERE id = OWN UNION SELECT fullname FROM {course}'],
            'filter only on a left joined table' => ['SELECT c.fullname FROM {course} c LEFT JOIN {enrol} e ON e.courseid = OWN'],
            'cross product with a filtered table' => ['SELECT c.fullname FROM {course} c JOIN {enrol} e ON e.id > 0 '
                . 'WHERE e.courseid = OWN'],
            'filter inside a function' => ['SELECT fullname FROM {course} WHERE IF(id = OWN, 1, 1)'],
            'range of courses' => ['SELECT fullname FROM {course} WHERE id BETWEEN OWN AND OTHER'],
            'filter only in a subquery' => ['SELECT fullname FROM {course} WHERE id IN (SELECT courseid FROM {enrol} '
                . 'WHERE courseid = OWN)'],
            'negated filter' => ['SELECT fullname FROM {course} WHERE NOT id = OWN'],
            'course level without the course' => ['SELECT ra.userid FROM {role_assignments} ra JOIN {context} ctx '
                . 'ON ctx.id = ra.contextid WHERE ctx.instanceid = OWN'],
        ];
    }

    /**
     * MDL-UNIT-108: a teacher only reads the data of the courses they manage.
     * MDL-INT-042: a teacher cannot read course data without limiting it to the courses they teach.
     *
     * @dataProvider unfiltered_query_provider
     * @param string $sql Query.
     */
    public function test_teacher_queries_on_course_data_must_be_limited_to_their_courses(string $sql): void {
        [$teacher, $own, $other] = $this->teacher();

        $this->assertNotNull(sql_guard::rejection_reason(self::with_ids($sql, $own, $other), $teacher));
    }

    /**
     * Queries on course data limited to the course of the teacher, and queries on no course data.
     *
     * @return array<string, array{0: string, 1: array}>
     */
    public static function filtered_query_provider(): array {
        return [
            'own course' => ['SELECT fullname FROM {course} WHERE id = OWN', []],
            'grades of the own course' => ['SELECT u.firstname, gg.finalgrade FROM {grade_items} gi JOIN {grade_grades} gg '
                . 'ON gg.itemid = gi.id JOIN {user} u ON u.id = gg.userid WHERE gi.courseid = OWN', []],
            'enrolments with a named parameter' => ['SELECT u.id FROM {user_enrolments} ue JOIN {enrol} e '
                . 'ON e.id = ue.enrolid JOIN {user} u ON u.id = ue.userid WHERE e.courseid = :cid', ['cid' => 'OWN']],
            'enrolments with a positional parameter' => ['SELECT ue.userid FROM {enrol} e LEFT JOIN {user_enrolments} ue '
                . 'ON ue.enrolid = e.id WHERE e.courseid = ?', ['OWN']],
            'roles in the course context' => ['SELECT ra.userid FROM {role_assignments} ra JOIN {context} ctx '
                . 'ON ctx.id = ra.contextid WHERE ctx.contextlevel = 50 AND ctx.instanceid = OWN', []],
            'activities joined to the own course' => ['SELECT cm.id FROM {course} c JOIN {course_modules} cm '
                . 'ON cm.course = c.id WHERE c.id IN (OWN)', []],
            'tables without personal data' => ['SELECT r.shortname, m.name, cc.name FROM {role} r, {modules} m, '
                . '{course_categories} cc', []],
            'participant names of the own course' => ['SELECT u.id, u.firstname, u.lastname FROM {user_enrolments} ue '
                . 'JOIN {enrol} e ON e.id = ue.enrolid JOIN {user} u ON u.id = ue.userid WHERE e.courseid = OWN', []],
            'participant names through the role assignments' => ['SELECT u.firstname, u.lastname, r.shortname '
                . 'FROM {role_assignments} ra JOIN {context} ctx ON ctx.id = ra.contextid JOIN {role} r ON r.id = ra.roleid '
                . 'JOIN {user} u ON u.id = ra.userid WHERE ctx.contextlevel = 50 AND ctx.instanceid = OWN', []],
        ];
    }

    /**
     * MDL-UNIT-108: a teacher reads the data of their courses, also with the course as a parameter, the roles, activity
     * types and categories, and the id and names of the users of their courses.
     * MDL-INT-042: a teacher can read the data of the courses they teach, also with parameters.
     *
     * @dataProvider filtered_query_provider
     * @param string $sql Query.
     * @param array $params Parameters, with OWN for the own course.
     */
    public function test_teacher_queries_limited_to_their_courses_are_allowed(string $sql, array $params): void {
        [$teacher, $own, $other] = $this->teacher();
        $params = array_map(static fn($param) => self::with_ids($param, $own, $other), $params);

        $this->assertNull(sql_guard::rejection_reason(self::with_ids($sql, $own, $other), $teacher, $params));
    }

    /**
     * MDL-UNIT-108: a course the teacher does not manage is refused also when it comes as a parameter of the query.
     * MDL-INT-042: a course the teacher does not teach is refused also when it comes as a parameter or as a context.
     */
    public function test_teacher_cannot_name_another_course_in_parameters_or_contexts(): void {
        [$teacher, $own, $other] = $this->teacher();

        $this->assertSame('sql_course_rejected', sql_guard::rejection_reason(
            'SELECT fullname FROM {course} WHERE id = :cid',
            $teacher,
            ['cid' => $other]
        ));
        $this->assertSame('sql_course_rejected', sql_guard::rejection_reason(
            'SELECT ra.userid FROM {role_assignments} ra '
            . 'JOIN {context} ctx ON ctx.id = ra.contextid WHERE ctx.contextlevel = 50 AND ctx.instanceid = ' . $other,
            $teacher
        ));
        $this->assertSame('sql_course_unfiltered_rejected', sql_guard::rejection_reason(
            'SELECT fullname FROM {course}',
            $teacher
        ));
    }

    /**
     * Queries of a teacher that would read private data of the whole site, with the reason they are rejected.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function private_data_provider(): array {
        $participants = 'FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid JOIN {user} u ON u.id = ue.userid '
            . 'WHERE e.courseid = OWN';
        return [
            'private messages' => ['SELECT id, useridfrom, fullmessage FROM {messages}', 'sql_table_not_allowed'],
            'notifications' => ['SELECT id, useridto, fullmessage FROM {notifications}', 'sql_table_not_allowed'],
            'user preferences' => ['SELECT userid, name, value FROM {user_preferences}', 'sql_table_not_allowed'],
            'database catalogue' => ['SELECT table_name FROM information_schema.tables', 'sql_table_not_allowed'],
            'database accounts' => ['SELECT user, host FROM mysql.user', 'sql_table_not_allowed'],
            'messages in a comma join' => ['SELECT r.name FROM {role} r, {messages} m', 'sql_table_not_allowed'],
            'messages in a subquery' => ['SELECT c.fullname, (SELECT fullmessage FROM {messages} LIMIT 1) AS m FROM {course} c '
                . 'WHERE c.id = OWN', 'sql_table_not_allowed'],
            'messages behind quotes in comments' => ["SELECT /* ' */ fullmessage FROM {messages} /* ' */",
                'sql_table_not_allowed'],
            'grades read through a subquery of an allowed table' => ['SELECT name FROM {role} WHERE id = '
                . '(SELECT MAX(finalgrade) FROM {grade_grades})', 'sql_course_unfiltered_rejected'],
            'every user' => ['SELECT id, firstname, lastname FROM {user}', 'sql_table_not_allowed'],
            'e-mail and phone of every user' => ['SELECT id, email, phone1 FROM {user}', 'sql_user_column_rejected'],
            'every column of the administrator' => ['SELECT * FROM {user} WHERE id = 2', 'sql_user_column_rejected'],
            'every column of the participants' => ['SELECT u.* ' . $participants, 'sql_user_column_rejected'],
            'e-mail of the participants' => ['SELECT u.firstname, u.email ' . $participants, 'sql_user_column_rejected'],
            'last IP of the participants' => ['SELECT u.id, lastip ' . $participants, 'sql_user_column_rejected'],
            'participants filtered by a private column' => ['SELECT u.firstname ' . $participants . " AND u.city = 'x'",
                'sql_user_column_rejected'],
            'users tied only in a left join' => ['SELECT u.firstname FROM {user} u LEFT JOIN {user_enrolments} ue '
                . 'ON ue.userid = u.id JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = OWN', 'sql_table_not_allowed'],
            'users tied to another user' => ['SELECT u2.firstname FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid '
                . 'JOIN {user} u ON u.id = ue.userid JOIN {user} u2 ON u2.id > u.id WHERE e.courseid = OWN',
                'sql_table_not_allowed'],
        ];
    }

    /**
     * MDL-UNIT-108: other users, other or all user columns and any other table (private messages, notifications,
     * preferences) are rejected to a user who does not manage every course.
     * MDL-INT-042: a teacher who does not manage every course cannot read private data of the site: messages,
     * notifications, preferences, other databases, users outside their courses or private user columns.
     *
     * @dataProvider private_data_provider
     * @param string $sql Query.
     * @param string $reason Expected rejection reason.
     */
    public function test_teacher_cannot_read_private_data_of_the_site(string $sql, string $reason): void {
        [$teacher, $own, $other] = $this->teacher();

        $this->assertSame($reason, sql_guard::rejection_reason(self::with_ids($sql, $own, $other), $teacher));
    }

    /**
     * Queries that call functions or clauses rejected for every user, written to dodge a simple text check.
     *
     * @return array<string, array{0: string}>
     */
    public static function blocked_function_provider(): array {
        return [
            'file reading' => ["SELECT LOAD_FILE('/etc/passwd') AS f"],
            'file reading with comments and mixed case' => ["SELECT /*comment*/ LoAd_FiLe /**/ ('/etc/passwd') AS f"],
            'file reading behind quotes in comments' => ["SELECT /* ' */ LOAD_FILE('/etc/passwd') /* ' */ AS f"],
            'file reading in an executable comment' => ["SELECT 1 /*!50000 , LOAD_FILE('/etc/passwd') */"],
            'pause' => ['SELECT SLEEP(1) AS s'],
            'pause with a line break' => ["SeLeCt sleep\n(1) AS s"],
            'benchmark' => ["SELECT BENCHMARK(1000000, MD5('x')) AS b"],
            'lock' => ["SELECT GET_LOCK('jedomi', 1) AS l"],
            'server variable' => ['SELECT @@datadir AS d'],
            'server variable with spaces' => ['SELECT @@ version AS v'],
            'file writing' => ["SELECT id FROM {course} /* x */ InTo   OutFile '/tmp/jedomi.txt'"],
            'file dumping' => ["SELECT id FROM {course} INTO\nDUMPFILE '/tmp/jedomi.bin'"],
            'PostgreSQL pause' => ['SELECT pg_sleep(1)'],
            'PostgreSQL file reading' => ["SELECT pg_read_file('/etc/passwd')"],
            'PostgreSQL large object' => ["SELECT lo_import('/etc/passwd')"],
            'other database link' => ["SELECT * FROM dblink('host=x', 'SELECT 1') AS t(a int)"],
            'server version' => ['SELECT VERSION() AS v'],
            'database account' => ['SELECT USER() AS u'],
            'database account with spaces' => ["SELECT id, user\n() AS u FROM {course}"],
            'database name' => ['SELECT DATABASE() AS d'],
            'schema name' => ['SELECT SCHEMA () AS s'],
            'connection identifier' => ['SELECT CONNECTION_ID() AS c'],
            'current account' => ['SELECT CURRENT_USER AS u'],
            'current account as a call' => ['SELECT current_user() AS u'],
            'session account' => ['SELECT SESSION_USER() AS u'],
            'system account' => ['SELECT system_user AS u'],
            'server variables together' => ['SELECT VERSION(), USER(), DATABASE()'],
            'PostgreSQL database name' => ['SELECT current_database()'],
            'PostgreSQL server setting' => ["SELECT current_setting('data_directory')"],
            'PostgreSQL server address' => ['SELECT inet_server_addr()'],
        ];
    }

    /**
     * MDL-UNIT-107: dangerous functions are rejected however they are written, for every user, administrators included.
     * MDL-INT-042: functions and clauses that read or write server files, pause or lock the database or reveal server
     * variables are rejected for every user, administrators included, whatever the case, spaces or comments.
     *
     * @dataProvider blocked_function_provider
     * @param string $sql Query.
     */
    public function test_dangerous_functions_are_rejected_for_every_user(string $sql): void {
        [$teacher] = $this->teacher();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);

        foreach ([(int) get_admin()->id, (int) $manager->id, $teacher, 0] as $userid) {
            $this->assertSame('sql_function_rejected', sql_guard::rejection_reason($sql, $userid), "User {$userid}");
        }
    }

    /**
     * MDL-UNIT-107: a normal read query with similar words in its texts or column names is not rejected by mistake.
     * MDL-INT-042: a function name inside a string literal is data, not a call.
     */
    public function test_function_names_inside_strings_are_not_rejected(): void {
        $this->resetAfterTest();

        $sql = "SELECT id FROM {course} WHERE fullname = 'sleep() and load_file'";
        $this->assertNull(sql_guard::rejection_reason($sql, (int) get_admin()->id));

        $similar = "SELECT id AS sleeping, fullname AS benchmarks, shortname AS load_files, summary AS get_locks "
            . "FROM {course} WHERE summary LIKE '%into outfile%'";
        $this->assertNull(sql_guard::rejection_reason($similar, (int) get_admin()->id));

        $columns = 'SELECT m.id, m.version, u.username AS user_name FROM {analytics_models} m '
            . "JOIN {user} u ON u.id = m.usermodified WHERE m.version > 0 AND u.username <> 'version() user() database()'";
        $this->assertNull(
            sql_guard::rejection_reason($columns, (int) get_admin()->id),
            'Columns and tables named like the server information functions are not calls'
        );
    }

    /**
     * MDL-UNIT-108: administrators and managers keep reading every non sensitive table.
     * MDL-INT-042: administrators and managers keep reading every course without filters.
     */
    public function test_administrator_and_manager_are_not_limited(): void {
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);

        foreach ([(int) get_admin()->id, (int) $manager->id] as $userid) {
            $this->assertNull(sql_guard::rejection_reason('SELECT fullname FROM {course}', $userid));
            $this->assertNull(sql_guard::rejection_reason('SELECT * FROM {enrol} WHERE courseid = 1 OR 1 = 1', $userid));
            $this->assertNull(sql_guard::rejection_reason('SELECT id, email FROM {user}', $userid));
            $this->assertNull(sql_guard::rejection_reason('SELECT id, useridfrom FROM {messages}', $userid));
        }
    }

    /**
     * Sensitive tables of the site.
     *
     * @return array<string, array{0: string}>
     */
    public static function sensitive_table_provider(): array {
        $tables = ['config', 'config_log', 'sessions', 'external_tokens', 'user_private_key', 'user_password_history',
            'user_password_resets', 'user_devices', 'auth_oauth2_linked_login', 'oauth2_issuer', 'messageinbound_datakeys',
            'repository_instance_config', 'portfolio_instance_config', 'local_jedomi_telegram_links'];
        return array_combine($tables, array_map(static fn(string $table): array => [$table], $tables));
    }

    /**
     * MDL-UNIT-020: reading a table of configuration and its log, sessions, tokens, private keys, password history and
     * resets, devices, external authentication, inbound message keys, repository and portfolio configuration or Telegram
     * links is rejected for every user, administrators included.
     *
     * @dataProvider sensitive_table_provider
     * @param string $table Table.
     */
    public function test_sensitive_tables_are_rejected_for_every_user(string $table): void {
        [$teacher] = $this->teacher();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);

        foreach ([(int) get_admin()->id, (int) $manager->id, $teacher] as $userid) {
            $this->assertNotNull(sql_guard::rejection_reason("SELECT id FROM {{$table}}", $userid), "{$table} for {$userid}");
        }
    }

    /**
     * Queries of a teacher that hide or widen the course filter with syntax outside the allowed subset, with the reason.
     *
     * @return array<string, array{0: string, 1: array, 2: string}>
     */
    public static function bypass_provider(): array {
        $ue = 'FROM {user} u JOIN {user_enrolments} ue ON ue.userid = u.id JOIN {enrol} e ON e.id = ue.enrolid';
        $gg = 'FROM {grade_grades} gg JOIN {grade_items} gi ON gi.id = gg.itemid';
        $rejected = 'sql_construct_rejected';
        return [
            'H1 filter hidden in a CASE' => ['SELECT c.id FROM {course} c WHERE CASE WHEN 0 = 1 THEN 1 AND c.id = OWN '
                . 'AND 1 = 1 ELSE 1 = 1 END', [], $rejected],
            'H1 grades of every course behind a CASE' => ["SELECT gi.courseid, gg.finalgrade {$gg} WHERE CASE WHEN 0 = 1 "
                . 'THEN 1 AND gi.courseid = OWN AND 1 = 1 ELSE 1 = 1 END', [], $rejected],
            'H1 users of every course behind a CASE' => ["SELECT u.id, u.firstname {$ue} WHERE CASE WHEN 0 = 1 THEN 1 "
                . 'AND e.courseid = OWN AND 1 = 1 ELSE 1 = 1 END', [], $rejected],
            'H2 backquoted reserved word as alias' => ["SELECT gg.itemid FROM {enrol} \x60where\x60, {grade_grades} gg "
                . 'WHERE 1 = 1 AND courseid = OWN', [], $rejected],
            'H3 control character after a double dash' => ["SELECT c.id FROM {course} c WHERE 1 = 1 --\x01 AND c.id = OWN\n",
                [], $rejected],
            'H4 placeholder inside a comment' => ['SELECT c.id FROM {course} c WHERE /* ? */ c.id = ?', ['OWN', 'OTHER'],
                $rejected],
            'H4 placeholder inside a line comment' => ["SELECT c.id FROM {course} c WHERE c.id = ? -- ?\n", ['OTHER', 'OWN'],
                $rejected],
            'H4 placeholder inside a string' => ["SELECT c.id FROM {course} c WHERE c.fullname <> '?' AND c.id = ?",
                ['OWN', 'OTHER'], $rejected],
            'H6 double-quoted column' => ["SELECT u.\"email\" {$ue} WHERE e.courseid = OWN", [], $rejected],
            'OR' => ['SELECT c.id FROM {course} c WHERE c.id = OWN OR 1 = 1', [], $rejected],
            'XOR' => ['SELECT c.id FROM {course} c WHERE c.id = OWN XOR 1 = 1', [], $rejected],
            'double pipe' => ['SELECT c.id FROM {course} c WHERE c.id = OWN || 1 = 1', [], $rejected],
            'NOT' => ['SELECT c.id FROM {course} c WHERE NOT c.id <> OWN', [], $rejected],
            'parenthesised condition' => ['SELECT c.id FROM {course} c WHERE (c.id = OWN)', [], $rejected],
            'IF' => ['SELECT c.id FROM {course} c WHERE IF(0, c.id = OWN, 1) = 1', [], $rejected],
            'BETWEEN' => ['SELECT c.id FROM {course} c WHERE c.id = OWN AND c.id BETWEEN 1 AND OTHER', [], $rejected],
            'subquery' => ['SELECT r.id FROM {role} r WHERE r.id IN (SELECT 1)', [], $rejected],
            'WITH' => ['WITH x AS (SELECT 1) SELECT r.id FROM {role} r', [], $rejected],
            'TABLE statement' => ['SELECT c.id FROM {course} c WHERE c.id = OWN AND (1, 1) IN (TABLE mdl_modules)', [],
                $rejected],
            'block comment' => ['SELECT c.id FROM {course} c /* x */ WHERE c.id = OWN', [], $rejected],
            'line comment' => ["SELECT c.id FROM {course} c WHERE c.id = OWN -- x\n", [], $rejected],
            'hash comment' => ["SELECT c.id FROM {course} c WHERE c.id = OWN # x\n", [], $rejected],
            'MariaDB executable comment' => ['SELECT c.id FROM {course} c WHERE c.id = OWN /*M! OR 1 = 1 */', [],
                'sql_function_rejected'],
            'user variable' => ['SELECT @a FROM {course} c WHERE c.id = OWN', [], $rejected],
            'unknown function' => ['SELECT CONCAT(c.fullname, c.shortname) FROM {course} c WHERE c.id = OWN', [], $rejected],
            // CURRENT_USER is a server information function, refused for every user before the grammar is checked.
            'server keyword without parentheses' => ['SELECT CURRENT_USER FROM {course} c WHERE c.id = OWN', [],
                'sql_function_rejected'],
            'keyword without parentheses' => ['SELECT CURRENT_DATE FROM {course} c WHERE c.id = OWN', [], $rejected],
            'unknown column' => ['SELECT c.nosuchcolumn FROM {course} c WHERE c.id = OWN', [], $rejected],
            'USING' => ['SELECT c.id FROM {course} c JOIN {enrol} e USING (id) WHERE c.id = OWN', [], $rejected],
            'NATURAL JOIN' => ['SELECT c.id FROM {course} c NATURAL JOIN {enrol} e WHERE c.id = OWN', [], $rejected],
            'RIGHT JOIN' => ['SELECT c.id FROM {course} c RIGHT JOIN {enrol} e ON e.courseid = c.id WHERE c.id = OWN', [],
                $rejected],
            'CROSS JOIN' => ['SELECT c.id FROM {course} c CROSS JOIN {enrol} e WHERE c.id = OWN', [], $rejected],
            'STRAIGHT_JOIN' => ['SELECT c.id FROM {course} c STRAIGHT_JOIN {enrol} e ON e.courseid = c.id WHERE c.id = OWN',
                [], $rejected],
            'semicolon' => ['SELECT c.id FROM {course} c WHERE c.id = OWN;', [], $rejected],
            'hexadecimal number' => ['SELECT c.id FROM {course} c WHERE c.id = 0x10', [], $rejected],
            'arithmetic' => ['SELECT c.id FROM {course} c WHERE c.id = OWN + 1', [], $rejected],
            'duplicated alias' => ['SELECT c.id FROM {course} c JOIN {enrol} c ON c.courseid = OWN WHERE c.id = OWN', [],
                $rejected],
        ];
    }

    /**
     * MDL-UNIT-108: a teacher cannot hide or widen the course filter with CASE, quoted identifiers, comments, control
     * characters, placeholders the database binds differently, or any syntax outside the allowed subset.
     *
     * @dataProvider bypass_provider
     * @param string $sql Query.
     * @param array $params Parameters, with OWN and OTHER for the course ids.
     * @param string $reason Expected rejection reason.
     */
    public function test_teacher_cannot_bypass_the_course_filter(string $sql, array $params, string $reason): void {
        [$teacher, $own, $other] = $this->teacher();
        $params = array_map(static fn($param) => self::with_ids($param, $own, $other), $params);

        $this->assertSame($reason, sql_guard::rejection_reason(self::with_ids($sql, $own, $other), $teacher, $params));
    }

    /**
     * Queries a teacher keeps running on their own course with the allowed subset.
     *
     * @return array<string, array{0: string, 1: array}>
     */
    public static function allowed_subset_provider(): array {
        $grades = 'FROM {grade_items} gi JOIN {grade_grades} gg ON gg.itemid = gi.id WHERE gi.courseid = OWN';
        return [
            'own course data' => ['SELECT id, fullname FROM {course} WHERE id = OWN', []],
            'participant names' => ['SELECT u.firstname, u.lastname FROM {user} u JOIN {user_enrolments} ue '
                . 'ON ue.userid = u.id JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = OWN', []],
            'aggregates grouped, ordered and limited' => ['SELECT gi.id, gi.itemname, COUNT(gg.id) AS total, '
                . 'ROUND(AVG(gg.finalgrade), 2) AS average, MIN(gg.finalgrade) AS lowest, MAX(gg.finalgrade) highest, '
                . "SUM(gg.finalgrade) AS points, COALESCE(MAX(gg.feedback), '') AS feedback {$grades} "
                . 'GROUP BY gi.id, gi.itemname ORDER BY average DESC, gi.id ASC LIMIT 10 OFFSET 0', []],
            'count of every row' => ["SELECT COUNT(*) AS total, COUNT(DISTINCT gg.userid) AS users {$grades}", []],
            'missing grades' => ["SELECT DISTINCT gg.userid {$grades} AND gg.finalgrade IS NULL", []],
            'present grades' => ["SELECT gg.userid {$grades} AND gg.finalgrade IS NOT NULL AND gg.finalgrade >= 5.5 "
                . 'AND gg.finalgrade < 10 AND gg.finalgrade <> 7 AND gg.finalgrade != 8 AND gg.finalgrade <= 9 '
                . 'AND gg.finalgrade > 1', []],
            'name search' => ["SELECT id, shortname FROM {course} WHERE id = OWN AND fullname LIKE '%it''s%'", []],
            'left outer join' => ['SELECT cm.id, m.name FROM {course_modules} cm LEFT OUTER JOIN {modules} m ON m.id = cm.module '
                . 'WHERE cm.course = OWN', []],
            'inner join and prefixed qualifier' => ['SELECT e.id FROM {course} JOIN {enrol} e ON e.courseid = {course}.id '
                . 'WHERE {course}.id = ?', ['OWN']],
            'lowercase keywords and line breaks' => ["select c.id\nfrom {course} as c\nwhere c.id in (OWN)\n", []],
        ];
    }

    /**
     * MDL-UNIT-108: the allowed subset keeps the usual reports of a teacher: own course data, participant names, grade
     * aggregates with GROUP BY, ORDER BY and LIMIT, IS NULL and LIKE.
     *
     * @dataProvider allowed_subset_provider
     * @param string $sql Query.
     * @param array $params Parameters, with OWN for the own course.
     */
    public function test_teacher_queries_in_the_allowed_subset_are_allowed(string $sql, array $params): void {
        [$teacher, $own, $other] = $this->teacher();
        $params = array_map(static fn($param) => self::with_ids($param, $own, $other), $params);

        $this->assertNull(sql_guard::rejection_reason(self::with_ids($sql, $own, $other), $teacher, $params));
    }

    /**
     * Queries rejected for every user, administrators included, because the guard and the database could read them
     * differently.
     *
     * @return array<string, array{0: string, 1: array, 2: string}>
     */
    public static function ambiguous_query_provider(): array {
        return [
            'control character after a double dash' => ["SELECT id FROM {course} WHERE 1 = 1 --\x01 , id\n", [],
                'sql_construct_rejected'],
            'null byte' => ["SELECT id FROM {course}\x00", [], 'sql_construct_rejected'],
            'non ASCII byte after a double dash' => ["SELECT id FROM {course} --\xc2\xa0 x\n", [], 'sql_construct_rejected'],
            'placeholder inside a comment' => ['SELECT id FROM {course} WHERE /* ? */ id = ?', [1, 2],
                'sql_construct_rejected'],
            'placeholder inside a string' => ["SELECT id FROM {course} WHERE fullname = '?' AND id = ?", [1, 2],
                'sql_construct_rejected'],
            'named placeholder inside a comment' => ['SELECT id FROM {course} WHERE id = :a /* :b */', ['a' => 1, 'b' => 2],
                'sql_construct_rejected'],
            'MariaDB executable comment' => ['SELECT id FROM {course} /*M! , 1 */', [], 'sql_function_rejected'],
            'MariaDB executable comment in lowercase with a version' => ['SELECT id FROM {course} /* m!100000 , 1 */', [],
                'sql_function_rejected'],
            'optimizer hint' => ['SELECT /*+ MAX_EXECUTION_TIME(1) */ id FROM {course}', [], 'sql_function_rejected'],
        ];
    }

    /**
     * MDL-UNIT-107: control characters, placeholders the database binds differently than the guard reads them and
     * executable comments are rejected for every user, administrators included.
     *
     * @dataProvider ambiguous_query_provider
     * @param string $sql Query.
     * @param array $params Parameters.
     * @param string $reason Expected rejection reason.
     */
    public function test_ambiguous_queries_are_rejected_for_every_user(string $sql, array $params, string $reason): void {
        [$teacher] = $this->teacher();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);

        foreach ([(int) get_admin()->id, (int) $manager->id, $teacher, 0] as $userid) {
            $this->assertSame($reason, sql_guard::rejection_reason($sql, $userid, $params), "User {$userid}");
        }
    }

    /**
     * Quoting that PostgreSQL reads differently than MySQL, so the guard rejects it there.
     *
     * @return array<string, array{0: string}>
     */
    public static function postgres_quoting_provider(): array {
        return [
            'double-quoted column' => ['SELECT u."email" FROM {user} u'],
            'double-quoted table' => ['SELECT value FROM "mdl_config"'],
            'dollar quoting' => ["SELECT \$\$'\$\$, value FROM mdl_config -- '"],
            'tagged dollar quoting' => ['SELECT $x$ text $x$ AS t'],
            'escape string' => ["SELECT id FROM {course} WHERE fullname = E'a'"],
            'backslash in a string' => ["SELECT id FROM {course} WHERE fullname = 'a\\' OR 1 = 1 --'"],
            'nested comment' => ["SELECT id /* /* */ , value FROM mdl_config /* */ */ FROM {course}"],
        ];
    }

    /**
     * MDL-UNIT-107: on PostgreSQL, double-quoted identifiers, dollar quoting, escape strings, backslashes and nested
     * comments are rejected, so the guard never reads a quoted identifier as a string; on MySQL they stay plain strings.
     *
     * @dataProvider postgres_quoting_provider
     * @param string $sql Query.
     */
    public function test_postgres_quoting_is_rejected(string $sql): void {
        $this->resetAfterTest();

        $this->assertSame('sql_construct_rejected', sql_guard::syntax_rejection($sql, 'postgres'));
    }

    /**
     * MDL-UNIT-107: the syntax check accepts plain queries on PostgreSQL and MySQL double-quoted strings.
     */
    public function test_plain_queries_pass_the_syntax_check(): void {
        $this->resetAfterTest();

        $this->assertNull(sql_guard::syntax_rejection(
            "SELECT id FROM {course} WHERE fullname = 'it''s' AND id = ?",
            'postgres'
        ));
        $this->assertNull(sql_guard::syntax_rejection("SELECT id::text FROM {course} /* note */ WHERE id = :id", 'postgres'));
        $this->assertNull(
            sql_guard::syntax_rejection('SELECT id FROM {course} WHERE fullname = "a" AND id = ?', 'mysql'),
            'In MySQL a double-quoted text is a string'
        );
        $this->assertSame(
            'sql_construct_rejected',
            sql_guard::syntax_rejection('SELECT id FROM {course} WHERE fullname = "a?" AND id = ?', 'mysql'),
            'Moodle binds the placeholder inside the string as well, so the parameters would shift'
        );
    }

    /**
     * MDL-UNIT-020: asking explicitly for a sensitive column (passwords, secrets, tokens, session keys, link codes) of
     * any table is rejected, also for administrators, and reading every column of an allowed table leaves the sensitive
     * columns out of the result.
     */
    public function test_sensitive_columns_are_rejected_or_left_out(): void {
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;
        foreach (
            ['u.password FROM {user} u', 'secret FROM {user}', 'token FROM {course}', 'sessionkey FROM {user}',
                'linkcode FROM {user}'] as $columns
        ) {
            $this->assertNotNull(sql_guard::rejection_reason('SELECT id, ' . $columns, $admin), $columns);
        }

        $row = sql_guard::filter_row(['id' => 2, 'username' => 'admin', 'password' => 'hash', 'secret' => 's', 'token' => 't',
            'sessionkey' => 'k', 'linkcode' => 'c', 'email' => 'admin@example.com']);
        $this->assertSame(['id' => 2, 'username' => 'admin', 'email' => 'admin@example.com'], $row);
        $this->assertNull(
            sql_guard::rejection_reason('SELECT * FROM {user} WHERE id = 2', $admin),
            'Every column of an allowed table can be asked for; the sensitive ones are removed from the result'
        );
    }

    /**
     * Columns whose names show they hold a credential or a secret, in core and plugin tables.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function secret_column_provider(): array {
        return [
            'backpack refresh token' => ['refreshtoken', 'badge_backpack_oauth2'],
            'LTI consumer secret' => ['consumersecret', 'enrol_lti_users'],
            'assignment approval token' => ['approval_token', 'local_assign_ai_pending'],
            'forum approval token' => ['approval_token', 'local_forum_ai_pending'],
            'linked login confirmation token' => ['confirmtoken', 'auth_oauth2_linked_login'],
            'guest password' => ['guestpassword', 'bigbluebuttonbn'],
            'quit password' => ['quitpassword', 'quizaccess_seb_quizsettings'],
            'service salt' => ['servicesalt', 'lti'],
            'access token' => ['accesstoken', 'local_jedomi_test'],
            'API key' => ['api_key', 'local_jedomi_test'],
            'API key without underscore' => ['apikey', 'local_jedomi_test'],
            'private key' => ['privatekey', 'local_jedomi_test'],
            'short password' => ['passwd', 'local_jedomi_test'],
            'secret key' => ['secretkey', 'local_jedomi_test'],
            'group enrolment key' => ['enrolmentkey', 'groups'],
        ];
    }

    /**
     * MDL-UNIT-020: columns of any table whose names show they hold a credential or a secret are rejected for every
     * user, also administrators, and left out of the rows of a query reading every column.
     *
     * @dataProvider secret_column_provider
     * @param string $column Column.
     * @param string $table Table.
     */
    public function test_columns_named_as_secrets_are_rejected_and_left_out(string $column, string $table): void {
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);

        foreach ([(int) get_admin()->id, (int) $manager->id] as $userid) {
            $this->assertSame(
                'sql_sensitive_rejected',
                sql_guard::rejection_reason("SELECT id, {$column} FROM {{$table}}", $userid),
                "User {$userid}"
            );
            $this->assertSame(
                'sql_sensitive_rejected',
                sql_guard::rejection_reason('SELECT t.id, t.' . strtoupper($column) . " FROM {{$table}} t", $userid),
                "User {$userid}, qualified and in uppercase"
            );
        }
        $this->assertSame(['id' => 1], sql_guard::filter_row(['id' => 1, strtoupper($column) => 'value']));
    }

    /**
     * MDL-UNIT-020: columns that only look like secrets (token counters of the AI actions, a flag telling whether a
     * password is used, the type or the address of a token, versions) stay readable for administrators and are kept in
     * the rows.
     */
    public function test_harmless_columns_named_like_secrets_stay_allowed(): void {
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;
        $queries = [
            'SELECT id, prompttokens, completiontoken FROM {ai_action_generate_text}',
            'SELECT id, usepassword FROM {lesson}',
            'SELECT id, accesstokenurl FROM {enrol_lti_app_registration}',
            'SELECT id, version FROM {analytics_models}',
        ];
        foreach ($queries as $sql) {
            $this->assertNull(sql_guard::rejection_reason($sql, $admin), $sql);
        }
        $row = ['id' => 1, 'prompttokens' => 10, 'completiontoken' => 20, 'usepassword' => 1, 'tokentype' => 0,
            'accesstokenurl' => 'https://example.com', 'confirmtokenexpires' => 0];
        $this->assertSame($row, sql_guard::filter_row($row));
    }

    /**
     * Every table of Jedomi declared in db/install.xml, plus one that a future version could add.
     *
     * @return array Table name keyed by itself.
     */
    public static function jedomi_table_provider(): array {
        $xml = file_get_contents(__DIR__ . '/../../db/install.xml');
        preg_match_all('/<TABLE NAME="(local_jedomi_[a-z0-9_]+)"/', $xml, $matches);
        $tables = array_merge($matches[1], ['local_jedomi_future_table']);
        return array_combine($tables, array_map(static fn(string $table): array => [$table], $tables));
    }

    /**
     * JEDOMI-SEC-003: every table of Jedomi, present or future, is sensitive: its rows (Telegram sessions, pending
     * actions, agent instructions, feedback, query log...) are rejected for every user, administrators included.
     *
     * @dataProvider jedomi_table_provider
     * @param string $table Table.
     */
    public function test_every_jedomi_table_is_rejected_for_every_user(string $table): void {
        [$teacher] = $this->teacher();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);

        $this->assertTrue(sql_guard::is_sensitive_table($table), $table);
        $this->assertTrue(sql_guard::is_sensitive_table(strtoupper($table)), $table);
        foreach ([(int) get_admin()->id, (int) $manager->id, $teacher] as $userid) {
            $this->assertSame(
                'sql_sensitive_rejected',
                sql_guard::rejection_reason("SELECT id FROM {{$table}}", $userid),
                "{$table} for {$userid}"
            );
        }
    }

    /**
     * JEDOMI-SEC-003: the tables of Jedomi feedback and agents are among the sensitive ones, and the existing list
     * of sensitive site tables is kept.
     */
    public function test_feedback_and_agents_tables_are_sensitive(): void {
        $tables = array_keys(self::jedomi_table_provider());
        $this->assertContains('local_jedomi_feedback', $tables);
        $this->assertContains('local_jedomi_agents', $tables);
        $this->assertContains('local_jedomi_pending_actions', $tables);
        $this->assertContains('local_jedomi_telegram_sessions', $tables);
        foreach (['config', 'sessions', 'external_tokens', 'oauth2_issuer', 'local_jedomi_telegram_links'] as $table) {
            $this->assertTrue(sql_guard::is_sensitive_table($table), $table);
        }
        $this->assertFalse(sql_guard::is_sensitive_table('course'));
        $this->assertFalse(sql_guard::is_sensitive_table('local_jedomiextra'));
    }

    /**
     * JEDOMI-SEC-003: a manager cannot read the hidden instruction of an agent of the administrator with a query.
     */
    public function test_manager_cannot_read_the_instruction_of_an_admin_agent(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $DB->insert_record('local_jedomi_agents', (object) [
            'userid' => (int) get_admin()->id, 'name' => 'Admin agent', 'prompt' => 'SECRET ADMIN INSTRUCTION',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        try {
            \local_jedomi\local\query_runner::run('SELECT prompt FROM {local_jedomi_agents}', [], (int) $manager->id, 10);
            $this->fail('The instruction of the agent was read');
        } catch (\moodle_exception $e) {
            $this->assertSame('sql_sensitive_rejected', $e->errorcode);
        }
    }

    /**
     * Queries with a carriage return, which ends a "--" comment on PostgreSQL and SQL Server but not for the guard.
     *
     * @return array<string, array{0: string}>
     */
    public static function carriage_return_provider(): array {
        return [
            'code after a comment ended by a carriage return' => ["SELECT 1 --x\rFROM {config}"],
            'sensitive column after a comment ended by a carriage return' => ["SELECT id -- x\r, password FROM {user}"],
            'carriage return as a line break' => ["SELECT id\rFROM {course}"],
            'Windows line break' => ["SELECT id\r\nFROM {course}"],
        ];
    }

    /**
     * RISK-003: a carriage return is rejected on every database family with its own reason, so code the database runs
     * after a "--x\r" comment can never be hidden from the guard.
     *
     * @dataProvider carriage_return_provider
     * @param string $sql Query.
     */
    public function test_carriage_return_is_rejected_on_every_family(string $sql): void {
        $this->resetAfterTest();

        foreach (['postgres', 'mssql', 'oracle', 'mysql', 'sqlite'] as $family) {
            $this->assertSame('sql_carriage_return_rejected', sql_guard::syntax_rejection($sql, $family), $family);
        }
        foreach ([(int) get_admin()->id, 0] as $userid) {
            $this->assertSame('sql_carriage_return_rejected', sql_guard::rejection_reason($sql, $userid), "User {$userid}");
        }
    }

    /**
     * RISK-003: the reason tells the AI to write line breaks as "\n", so it can write the query again.
     */
    public function test_carriage_return_reason_tells_how_to_write_the_query(): void {
        $this->assertStringContainsString('\n', get_string('sql_carriage_return_rejected', 'local_jedomi'));
    }

    /**
     * RISK-004: a query longer than the limit is rejected before it is read, and a query of the limit is still read.
     */
    public function test_queries_longer_than_the_limit_are_rejected(): void {
        $this->resetAfterTest();
        $head = "SELECT id FROM {course} WHERE fullname = '";
        $fits = $head . str_repeat('a', sql_guard::MAX_QUERY_LENGTH - strlen($head) - 1) . "'";
        $this->assertSame(sql_guard::MAX_QUERY_LENGTH, strlen($fits));

        $this->assertNull(sql_guard::rejection_reason($fits, (int) get_admin()->id));
        $this->assertSame('sql_too_long_rejected', sql_guard::rejection_reason($fits . ' ', (int) get_admin()->id));
        $this->assertSame('sql_too_long_rejected', sql_guard::syntax_rejection($fits . ' ', 'postgres'));
    }

    /**
     * Run a function with a backtracking limit low enough to make the regular expressions of the guard fail on a long
     * literal, restoring the settings afterwards.
     *
     * @param callable $test Function to run.
     */
    private static function with_failing_regex(callable $test): void {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '10');
        try {
            $test();
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }
    }

    /**
     * RISK-004: when the regular expression that reads the query fails, the query is rejected instead of being read as
     * a query without code (fail closed).
     */
    public function test_queries_the_guard_cannot_read_are_rejected(): void {
        $this->resetAfterTest();
        $sql = "SELECT value FROM {config} WHERE name = '" . str_repeat('a', 2000) . "'";
        $reasons = [];

        self::with_failing_regex(static function () use ($sql, &$reasons): void {
            $reasons[] = sql_guard::rejection_reason($sql, (int) get_admin()->id);
            $reasons[] = sql_guard::syntax_rejection($sql, 'postgres');
        });

        $this->assertSame(['sql_construct_rejected', 'sql_construct_rejected'], $reasons);
    }

    /**
     * RISK-004: the tokenizer of the course check rejects a query it cannot read to the end, instead of returning the
     * tokens read before the failure.
     */
    public function test_tokenizer_rejects_a_query_it_cannot_read(): void {
        $sql = "SELECT id FROM {course} WHERE fullname = '" . str_repeat('a', 2000) . "'";
        $code = null;

        self::with_failing_regex(static function () use ($sql, &$code): void {
            try {
                sql_select_parser::tokenize($sql, []);
            } catch (\moodle_exception $e) {
                $code = $e->errorcode;
            }
        });

        $this->assertSame('sql_construct_rejected', $code);
        $this->assertCount(8, sql_select_parser::tokenize($sql, []), 'The same query is read when the expression works');
    }
}
