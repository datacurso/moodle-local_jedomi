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

namespace local_jedomi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

use local_jedomi\messaging\tool_runner;

/**
 * Characterization of the signed permission tokens (SPT) of the web chat, of a user (agents, Telegram) and of a
 * messaging channel: every key, its order, the capabilities, the course lists, the expiry and the signature, byte for
 * byte as the gateway verifies them.
 *
 * The signature is computed here over the payload without its signature, after a JSON round trip and sorted by key,
 * with the secret of the site, so a change in how any of the three tokens is built or signed is caught.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_jedomi_generate_spt
 * @covers     ::local_jedomi_generate_spt_for_user
 * @covers     \local_jedomi\messaging\tool_runner::signed_permissions
 * @covers     \local_jedomi\spt
 */
final class spt_test extends \advanced_testcase {
    /** @var string[] Capability keys of every SPT, in the order they are signed. */
    private const CAPABILITY_KEYS = ['can_view_all_grades', 'can_manage_users', 'can_send_messages', 'can_manage_courses',
        'can_view_reports', 'can_enrol_users', 'can_access_all_groups'];

    /**
     * The web chat SPT of a teacher inside a course: course capabilities, owned courses, the course of the page and
     * the binding to the session.
     */
    public function test_web_chat_token_of_a_teacher_in_a_course(): void {
        global $COURSE, $CFG;
        $this->resetAfterTest();
        [$teacher, $owned, $enrolled] = $this->teacher_with_courses();
        $this->setUser($teacher);
        $COURSE = get_course($owned);

        $before = time();
        $spt = \local_jedomi_generate_spt('teacher');
        $after = time();

        $expected = [
            'capabilities' => array_combine(self::CAPABILITY_KEYS, [true, false, true, true, true, true, true]),
            'course_id' => $owned,
            'course_ids_enrolled' => [],
            'course_ids_owned' => [$owned],
            'expires' => $this->expiry_between($spt, $before, $after),
            'moodle_url' => $CFG->wwwroot,
            'role' => 'teacher',
            'sesskey_hash' => substr(hash('sha256', sesskey()), 0, 16),
            'tools' => \local_jedomi_get_tool_definitions('teacher'),
            'userid' => (int) $teacher->id,
            'username' => 'spt_teacher',
        ];
        $this->assert_signed_token($expected, $spt);
        $this->assertNotContains($enrolled, $spt['course_ids_owned']);
    }

    /**
     * The web chat SPT of a student outside a course: system capabilities and enrolled courses.
     */
    public function test_web_chat_token_of_a_student_outside_a_course(): void {
        global $CFG;
        $this->resetAfterTest();
        [$student, $courseid] = $this->student_with_course();
        $this->setUser($student);

        $before = time();
        $spt = \local_jedomi_generate_spt('student');
        $after = time();

        $expected = [
            'capabilities' => array_combine(self::CAPABILITY_KEYS, [false, false, true, false, false, false, false]),
            'course_id' => (int) SITEID,
            'course_ids_enrolled' => [$courseid],
            'course_ids_owned' => [],
            'expires' => $this->expiry_between($spt, $before, $after),
            'moodle_url' => $CFG->wwwroot,
            'role' => 'student',
            'sesskey_hash' => substr(hash('sha256', sesskey()), 0, 16),
            'tools' => \local_jedomi_get_tool_definitions('student'),
            'userid' => (int) $student->id,
            'username' => 'spt_student',
        ];
        $this->assert_signed_token($expected, $spt);
    }

    /**
     * The SPT of a given user (agents, Telegram): system capabilities of that user, no course, no session binding.
     */
    public function test_token_of_a_user(): void {
        global $CFG;
        $this->resetAfterTest();
        [$teacher, $owned] = $this->teacher_with_courses();
        $this->setAdminUser();

        $before = time();
        $spt = \local_jedomi_generate_spt_for_user((int) $teacher->id, 'teacher');
        $after = time();

        $expected = [
            'capabilities' => array_combine(self::CAPABILITY_KEYS, [false, false, true, false, false, false, false]),
            'course_id' => 0,
            'course_ids_enrolled' => [],
            'course_ids_owned' => [$owned],
            'expires' => $this->expiry_between($spt, $before, $after),
            'moodle_url' => $CFG->wwwroot,
            'role' => 'teacher',
            'tools' => \local_jedomi_get_tool_definitions('teacher', (int) $teacher->id),
            'userid' => (int) $teacher->id,
            'username' => 'spt_teacher',
        ];
        $this->assert_signed_token($expected, $spt);
        $this->assertSame([], \local_jedomi_generate_spt_for_user(999999, 'student'));
    }

    /**
     * The SPT of a messaging channel: the course lists it computes or is given, and only the tools of the channel.
     */
    public function test_token_of_a_messaging_channel(): void {
        global $CFG;
        $this->resetAfterTest();
        [$student, $courseid] = $this->student_with_course();
        $runner = new tool_runner('telegram', 'Telegram', ['execute_sql', 'list_agents', 'create_agent']);

        $before = time();
        $computed = $runner->signed_permissions((int) $student->id, 'student');
        $given = $runner->signed_permissions((int) $student->id, 'student', [77], [$courseid]);
        $after = time();

        $expected = [
            'capabilities' => array_combine(self::CAPABILITY_KEYS, [false, false, true, false, false, false, false]),
            'course_id' => 0,
            'course_ids_enrolled' => [$courseid],
            'course_ids_owned' => [],
            'expires' => $this->expiry_between($computed, $before, $after),
            'moodle_url' => $CFG->wwwroot,
            'role' => 'student',
            'tools' => $runner->tool_definitions('student', (int) $student->id),
            'userid' => (int) $student->id,
            'username' => 'spt_student',
        ];
        $this->assert_signed_token($expected, $computed);
        $expected['course_ids_owned'] = [77];
        $expected['expires'] = $this->expiry_between($given, $before, $after);
        $this->assert_signed_token($expected, $given);
        $this->assertSame([], $runner->signed_permissions(999999, 'student'));
    }

    /**
     * Every token expires 30 minutes after it is built.
     */
    public function test_tokens_expire_after_thirty_minutes(): void {
        $this->assertSame(30 * MINSECS, spt::EXPIRY);
    }

    /**
     * The builder signs any payload sorted by key with the secret of the site, over its JSON round trip.
     */
    public function test_builder_signs_a_payload_sorted_by_key(): void {
        $this->resetAfterTest();

        $signed = spt::sign(['tools' => [['parameters' => ['properties' => new \stdClass()]]], 'role' => 'student']);

        $this->assertSame(['role', 'tools', 'signature'], array_keys($signed));
        $this->assertSame(
            hash_hmac('sha256', '{"role":"student","tools":[{"parameters":{"properties":[]}}]}', \local_jedomi_get_spt_secret()),
            $signed['signature']
        );
    }

    /**
     * Assert a token is exactly the expected payload, in the same order, signed with the secret of the site.
     *
     * @param array $expected Payload without its signature, sorted by key.
     * @param array $actual Token built by the plugin.
     */
    private function assert_signed_token(array $expected, array $actual): void {
        $canonical = json_decode(json_encode($expected), true);
        ksort($canonical);
        $expected['signature'] = hash_hmac('sha256', json_encode($canonical), \local_jedomi_get_spt_secret());
        $this->assertSame(json_encode($expected), json_encode($actual));
    }

    /**
     * Expiry of a token built between two times: 30 minutes after it was built.
     *
     * @param array $spt Token.
     * @param int $before Time before it was built.
     * @param int $after Time after it was built.
     * @return int The expiry of the token.
     */
    private function expiry_between(array $spt, int $before, int $after): int {
        $this->assertGreaterThanOrEqual($before + 1800, $spt['expires']);
        $this->assertLessThanOrEqual($after + 1800, $spt['expires']);
        return $spt['expires'];
    }

    /**
     * A teacher of one course who is a student of another.
     *
     * @return array [user, id of the course taught, id of the course studied]
     */
    private function teacher_with_courses(): array {
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user(['username' => 'spt_teacher']);
        $owned = (int) $generator->create_course()->id;
        $enrolled = (int) $generator->create_course()->id;
        $generator->enrol_user($teacher->id, $owned, 'editingteacher');
        $generator->enrol_user($teacher->id, $enrolled, 'student');
        return [$teacher, $owned, $enrolled];
    }

    /**
     * A student of one course.
     *
     * @return array [user, course id]
     */
    private function student_with_course(): array {
        $generator = $this->getDataGenerator();
        $student = $generator->create_user(['username' => 'spt_student']);
        $courseid = (int) $generator->create_course()->id;
        $generator->enrol_user($student->id, $courseid, 'student');
        return [$student, $courseid];
    }
}
