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
        ];
    }

    /**
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
     * MDL-INT-042: a function name inside a string literal is data, not a call.
     */
    public function test_function_names_inside_strings_are_not_rejected(): void {
        $this->resetAfterTest();

        $sql = "SELECT id FROM {course} WHERE fullname = 'sleep() and load_file'";

        $this->assertNull(sql_guard::rejection_reason($sql, (int) get_admin()->id));
    }

    /**
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
}
