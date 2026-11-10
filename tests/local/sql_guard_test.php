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
 * Tests for the course check of the shared guard of the database query offered to the AI.
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
            'no course data' => ['SELECT COUNT(*) FROM {user}', []],
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

        $this->assertSame('sql_course_rejected', sql_guard::rejection_reason('SELECT fullname FROM {course} WHERE id = :cid',
            $teacher, ['cid' => $other]));
        $this->assertSame('sql_course_rejected', sql_guard::rejection_reason('SELECT ra.userid FROM {role_assignments} ra '
            . 'JOIN {context} ctx ON ctx.id = ra.contextid WHERE ctx.contextlevel = 50 AND ctx.instanceid = ' . $other,
            $teacher));
        $this->assertSame('sql_course_unfiltered_rejected', sql_guard::rejection_reason('SELECT fullname FROM {course}',
            $teacher));
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
        }
    }
}
