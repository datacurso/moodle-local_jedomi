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
 * JEDOMI-SEC-017: inside their own courses, the SQL of a teacher follows the permissions of each activity, blind
 * marking, the read capabilities of grades and logs, and hidden categories. Administrators and managers are not limited.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\sql_guard
 */
final class sql_activity_guard_test extends \advanced_testcase {
    /** @var string Forum posts of the own course. */
    private const POSTS_SQL = 'SELECT d.name, p.subject FROM {forum_discussions} d JOIN {forum_posts} p '
        . 'ON p.discussion = d.id WHERE d.course = OWN';

    /** @var string Authors of the submissions of the own course. */
    private const SUBMISSIONS_SQL = 'SELECT a.name, s.userid, s.status FROM {assign} a JOIN {assign_submission} s '
        . 'ON s.assignment = a.id WHERE a.course = OWN';

    /** @var string Grades of the own course. */
    private const GRADES_SQL = 'SELECT gi.itemname, gg.userid, gg.finalgrade FROM {grade_items} gi JOIN {grade_grades} gg '
        . 'ON gg.itemid = gi.id WHERE gi.courseid = OWN';

    /**
     * A course with a teacher, a student, a forum with a post and an assignment with a submission of the student.
     *
     * @param array $assign Settings of the assignment (such as blindmarking).
     * @return array{course: \stdClass, teacher: int, student: int, forum: \stdClass, assign: \stdClass}
     */
    private function scenario(array $assign = []): array {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $student = $gen->create_and_enrol($course, 'student');
        $forum = $gen->create_module('forum', ['course' => $course->id]);
        $gen->get_plugin_generator('mod_forum')->create_discussion((object) ['course' => $course->id,
            'forum' => $forum->id, 'userid' => $student->id, 'subject' => 'FORUM-POST', 'message' => 'Text']);
        $assign = $gen->create_module('assign', ['course' => $course->id, 'name' => 'ASSIGN-NAME'] + $assign);
        $DB->insert_record('assign_submission', (object) ['assignment' => $assign->id, 'userid' => $student->id,
            'status' => 'submitted', 'timecreated' => time(), 'timemodified' => time(), 'latest' => 1]);
        return ['course' => $course, 'teacher' => (int) $teacher->id, 'student' => (int) $student->id, 'forum' => $forum,
            'assign' => $assign];
    }

    /**
     * Put the course id in a query written with OWN.
     *
     * @param string $sql Query.
     * @param \stdClass $course Course.
     * @return string
     */
    private static function own(string $sql, \stdClass $course): string {
        return str_replace('OWN', (string) (int) $course->id, $sql);
    }

    /**
     * Change a capability of the editing teacher role in a context and forget the cached permissions and activities.
     *
     * @param string $capability Capability.
     * @param int $permission CAP_PROHIBIT, CAP_ALLOW...
     * @param \context $context Context of the override.
     */
    private function override(string $capability, int $permission, \context $context): void {
        global $DB;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability($capability, $permission, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        get_fast_modinfo(0, 0, true);
    }

    /**
     * Control: with the default permissions the teacher reads the forum posts, the submissions and the grades of the
     * own course.
     */
    public function test_teacher_with_default_permissions_reads_the_activities_of_the_own_course(): void {
        $s = $this->scenario();

        foreach ([self::POSTS_SQL, self::SUBMISSIONS_SQL, self::GRADES_SQL] as $sql) {
            $this->assertNull(sql_guard::rejection_reason(self::own($sql, $s['course']), $s['teacher']), $sql);
        }
        $result = query_runner::run(self::own(self::SUBMISSIONS_SQL, $s['course']), [], $s['teacher'], 50);
        $this->assertSame([(string) $s['student']], array_map('strval', array_column($result['rows'], 'userid')));
    }

    /**
     * JEDOMI-SEC-017: a teacher who may not view one forum does not read the posts of the forums of the course, nor the
     * list of activities, while the other activity types stay readable.
     */
    public function test_teacher_without_the_view_capability_of_an_activity_does_not_read_its_rows(): void {
        $s = $this->scenario();
        $this->override('mod/forum:viewdiscussion', CAP_PROHIBIT, \context_module::instance($s['forum']->cmid));

        $this->assertSame('sql_activity_rejected', sql_guard::rejection_reason(self::own(self::POSTS_SQL, $s['course']),
            $s['teacher']));
        $this->assertSame('sql_activity_rejected', sql_guard::rejection_reason(
            self::own('SELECT cm.id, cm.instance FROM {course_modules} cm WHERE cm.course = OWN', $s['course']),
            $s['teacher']
        ));
        try {
            query_runner::run(self::own(self::POSTS_SQL, $s['course']), [], $s['teacher'], 50);
            $this->fail('The posts of a forum the teacher may not view must not be read');
        } catch (\moodle_exception $e) {
            $this->assertSame('sql_activity_rejected', $e->errorcode);
        }
        $this->assertNull(sql_guard::rejection_reason(self::own(self::SUBMISSIONS_SQL, $s['course']), $s['teacher']));
    }

    /**
     * JEDOMI-SEC-017: an activity hidden from a teacher who may not view hidden activities keeps its rows out of reach.
     */
    public function test_teacher_does_not_read_the_rows_of_an_activity_hidden_from_them(): void {
        $s = $this->scenario();
        set_coursemodule_visible($s['assign']->cmid, 0);
        $this->override('moodle/course:viewhiddenactivities', CAP_PROHIBIT, \context_course::instance($s['course']->id));

        $this->assertSame('sql_activity_rejected', sql_guard::rejection_reason(
            self::own(self::SUBMISSIONS_SQL, $s['course']),
            $s['teacher']
        ));
        $this->assertSame('sql_activity_rejected', sql_guard::rejection_reason(
            self::own('SELECT name FROM {assign} WHERE course = OWN', $s['course']),
            $s['teacher']
        ));
        $this->assertNull(sql_guard::rejection_reason(self::own(self::POSTS_SQL, $s['course']), $s['teacher']));
    }

    /**
     * JEDOMI-SEC-017 (NEW-POC-5): with the grade capabilities prohibited in the course, the grades are not read.
     */
    public function test_teacher_without_the_grade_capabilities_does_not_read_grades(): void {
        $s = $this->scenario();
        $this->override('moodle/grade:viewall', CAP_PROHIBIT, \context_course::instance($s['course']->id));

        $this->assertSame('sql_activity_rejected', sql_guard::rejection_reason(self::own(self::GRADES_SQL, $s['course']),
            $s['teacher']));
    }

    /**
     * JEDOMI-SEC-017: the authors of a blind marking assignment whose identities are not revealed stay hidden from a
     * teacher without mod/assign:viewblinddetails; the assignment itself stays readable.
     */
    public function test_blind_marking_hides_the_authors_until_revealed(): void {
        global $DB;
        $s = $this->scenario(['blindmarking' => 1]);
        $sql = self::own(self::SUBMISSIONS_SQL, $s['course']);

        $this->assertSame('sql_blind_marking_rejected', sql_guard::rejection_reason($sql, $s['teacher']));
        $this->assertSame('sql_blind_marking_rejected', sql_guard::rejection_reason(
            self::own('SELECT m.userid, m.id FROM {assign} a JOIN {assign_user_mapping} m ON m.assignment = a.id '
                . 'WHERE a.course = OWN', $s['course']),
            $s['teacher']
        ));
        $this->assertNull(sql_guard::rejection_reason(
            self::own('SELECT name, blindmarking FROM {assign} WHERE course = OWN', $s['course']),
            $s['teacher']
        ));

        $DB->set_field('assign', 'revealidentities', 1, ['id' => $s['assign']->id]);
        $this->assertNull(sql_guard::rejection_reason($sql, $s['teacher']));
    }

    /**
     * JEDOMI-SEC-017: a teacher allowed to see the identities of blind marking reads the authors.
     */
    public function test_blind_marking_authors_are_read_with_viewblinddetails(): void {
        $s = $this->scenario(['blindmarking' => 1]);
        $this->override('mod/assign:viewblinddetails', CAP_ALLOW, \context_module::instance($s['assign']->cmid));

        $this->assertNull(sql_guard::rejection_reason(self::own(self::SUBMISSIONS_SQL, $s['course']), $s['teacher']));
    }

    /**
     * JEDOMI-SEC-017: hidden categories are read only with moodle/category:viewhiddencategories; limited to the visible
     * categories, the query runs and leaves the hidden one out.
     */
    public function test_hidden_categories_need_the_capability(): void {
        $s = $this->scenario();
        $this->getDataGenerator()->create_category(['name' => 'HIDDEN-CATEGORY', 'visible' => 0]);

        $this->assertSame('sql_category_rejected', sql_guard::rejection_reason('SELECT id, name FROM {course_categories}',
            $s['teacher']));
        $this->assertSame('sql_category_rejected', sql_guard::rejection_reason(
            self::own('SELECT c.fullname, cc.name FROM {course} c JOIN {course_categories} cc ON cc.id = c.category '
                . 'WHERE c.id = OWN', $s['course']),
            $s['teacher']
        ));

        $visible = 'SELECT id, name FROM {course_categories} WHERE visible = 1';
        $this->assertNull(sql_guard::rejection_reason($visible, $s['teacher']));
        $result = query_runner::run($visible, [], $s['teacher'], 50);
        $this->assertNotContains('HIDDEN-CATEGORY', array_column($result['rows'], 'name'));

        $roleid = create_role('Hidden categories', 'hiddencategories', '');
        $system = \context_system::instance();
        assign_capability('moodle/category:viewhiddencategories', CAP_ALLOW, $roleid, $system->id, true);
        role_assign($roleid, $s['teacher'], $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertNull(sql_guard::rejection_reason('SELECT id, name FROM {course_categories}', $s['teacher']));
    }

    /**
     * JEDOMI-SEC-017: administrators and managers keep reading hidden activities, blind marking authors, grades and
     * hidden categories.
     */
    public function test_administrator_and_manager_are_not_limited_by_activities(): void {
        $s = $this->scenario(['blindmarking' => 1]);
        set_coursemodule_visible($s['assign']->cmid, 0);
        $this->getDataGenerator()->create_category(['name' => 'HIDDEN-CATEGORY', 'visible' => 0]);
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $queries = [
            self::own(self::SUBMISSIONS_SQL, $s['course']),
            self::own(self::POSTS_SQL, $s['course']),
            self::own(self::GRADES_SQL, $s['course']),
            'SELECT id, name FROM {course_categories}',
        ];

        foreach ([(int) get_admin()->id, (int) $manager->id] as $userid) {
            foreach ($queries as $sql) {
                $this->assertNull(sql_guard::rejection_reason($sql, $userid), $sql);
            }
        }
        $result = query_runner::run('SELECT name FROM {course_categories}', [], (int) get_admin()->id, 50);
        $this->assertContains('HIDDEN-CATEGORY', array_column($result['rows'], 'name'));
    }
}
