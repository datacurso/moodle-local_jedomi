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

/**
 * Unit tests for lib.php functions in local_jedomi.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Test cases for local_jedomi_get_user_role_for_user and local_jedomi_generate_spt_for_user.
 *
 * @covers \local_jedomi_get_user_role_for_user()
 * @covers \local_jedomi_get_user_role()
 * @covers \local_jedomi_generate_spt_for_user()
 * @covers \local_jedomi_is_role_allowed()
 * @covers \local_jedomi_is_telegram_role_allowed()
 * @covers \local_jedomi_get_tool_definitions()
 */
final class lib_test extends \advanced_testcase {
    /**
     * Setup test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // ... ========================
    // local_jedomi_get_user_role_for_user
    // ========================

    /**
     * MDL-UNIT-016: a site administrator gets the 'admin' role.
     */
    public function test_get_user_role_for_user_admin(): void {
        $admin = get_admin();
        $this->assertNotFalse($admin, 'Admin user must exist');

        $role = local_jedomi_get_user_role_for_user((int) $admin->id);
        $this->assertEquals('admin', $role);
    }

    /**
     * MDL-UNIT-016: a user who can update users at site level gets the 'manager' role.
     */
    public function test_get_user_role_for_user_manager(): void {
        global $DB;

        $manager = $this->getDataGenerator()->create_user();
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        role_assign($managerrole->id, $manager->id, \context_system::instance());

        $role = local_jedomi_get_user_role_for_user((int) $manager->id);
        $this->assertEquals('manager', $role);
    }

    /**
     * MDL-UNIT-016: a user who can manage activities in a course gets the 'teacher' role.
     */
    public function test_get_user_role_for_user_teacher(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $role = local_jedomi_get_user_role_for_user((int) $teacher->id);
        $this->assertEquals('teacher', $role);
    }

    /**
     * MDL-UNIT-016: any other user gets the 'student' role.
     */
    public function test_get_user_role_for_user_student(): void {
        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $role = local_jedomi_get_user_role_for_user((int) $student->id);
        $this->assertEquals('student', $role);
    }

    /**
     * MDL-UNIT-016: a user without enrolments is classified as 'student'.
     */
    public function test_get_user_role_for_user_no_enrolments(): void {
        $user = $this->getDataGenerator()->create_user();

        $role = local_jedomi_get_user_role_for_user((int) $user->id);
        $this->assertEquals('student', $role);
    }

    /**
     * MDL-UNIT-016: outside a course (Telegram), a user who is teacher somewhere gets 'teacher' (higher priority).
     */
    public function test_get_user_role_for_user_teacher_over_student(): void {
        $user = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        // Teacher in course1, student in course2.
        $this->getDataGenerator()->enrol_user($user->id, $course1->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user->id, $course2->id, 'student');

        $role = local_jedomi_get_user_role_for_user((int) $user->id);
        $this->assertEquals('teacher', $role);
    }

    // ... ========================
    // local_jedomi_generate_spt_for_user
    // ========================

    /**
     * MDL-UNIT-015: no permissions are issued for a user that does not exist.
     */
    public function test_generate_spt_for_user_nonexistent(): void {
        $result = local_jedomi_generate_spt_for_user(999999, 'student');
        $this->assertEquals([], $result);
    }

    /**
     * MDL-UNIT-015: no permissions are issued for a deleted user.
     */
    public function test_generate_spt_for_user_deleted(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;

        // Mark user as deleted.
        $DB->set_field('user', 'deleted', 1, ['id' => $userid]);

        $result = local_jedomi_generate_spt_for_user($userid, 'student');
        $this->assertEquals([], $result);
    }

    /**
     * MDL-UNIT-015: an administrator gets signed permissions with the 'admin' role.
     */
    public function test_generate_spt_for_user_admin(): void {
        $admin = get_admin();
        $this->assertNotFalse($admin);

        $result = local_jedomi_generate_spt_for_user((int) $admin->id, 'admin');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('userid', $result);
        $this->assertArrayHasKey('role', $result);
        $this->assertArrayHasKey('capabilities', $result);
        $this->assertArrayHasKey('tools', $result);
        $this->assertArrayHasKey('signature', $result);
        $this->assertArrayHasKey('expires', $result);

        $this->assertEquals('admin', $result['role']);
        $this->assertEquals((int) $admin->id, $result['userid']);
        $this->assertEquals($admin->username, $result['username']);
        $this->assertEquals([], $result['course_ids_owned']);
        $this->assertEquals([], $result['course_ids_enrolled']);

        // Admin should have all capabilities as true.
        $this->assertTrue($result['capabilities']['can_view_all_grades']);
        $this->assertTrue($result['capabilities']['can_manage_users']);
        $this->assertTrue($result['capabilities']['can_manage_courses']);
    }

    /**
     * MDL-UNIT-015: a teacher gets signed permissions with the 'teacher' role and their courses.
     */
    public function test_generate_spt_for_user_teacher(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $result = local_jedomi_generate_spt_for_user((int) $teacher->id, 'teacher');

        $this->assertIsArray($result);
        $this->assertEquals('teacher', $result['role']);
        $this->assertContains((int) $course->id, $result['course_ids_owned']);
        $this->assertEquals([], $result['course_ids_enrolled']);

        // Teacher should have some capabilities.
        $this->assertArrayHasKey('can_view_all_grades', $result['capabilities']);
        $this->assertArrayHasKey('can_send_messages', $result['capabilities']);
    }

    /**
     * MDL-UNIT-015: a student gets signed permissions with the 'student' role and their enrolled courses.
     */
    public function test_generate_spt_for_user_student(): void {
        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $result = local_jedomi_generate_spt_for_user((int) $student->id, 'student');

        $this->assertIsArray($result);
        $this->assertEquals('student', $result['role']);
        $this->assertEquals([], $result['course_ids_owned']);
        $this->assertContains((int) $course->id, $result['course_ids_enrolled']);
    }

    /**
     * Test that generate_spt_for_user produces a valid HMAC signature.
     */
    public function test_generate_spt_for_user_signature(): void {
        $admin = get_admin();
        $this->assertNotFalse($admin);

        $result = local_jedomi_generate_spt_for_user((int) $admin->id, 'admin');

        $this->assertArrayHasKey('signature', $result);
        $this->assertNotEmpty($result['signature']);

        // Verify signature: reconstruct payload without signature and check HMAC.
        $staticsecret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';
        $signature = $result['signature'];
        unset($result['signature']);

        $expectedsig = hash_hmac('sha256', local_jedomi_spt_signing_string($result), $staticsecret);

        $this->assertEquals($expectedsig, $signature);
    }

    /**
     * A student's SPT stays valid after the JSON round trip through the browser.
     *
     * The tools without parameters declare "properties" as an empty object, which comes back as an empty array.
     *
     * @covers ::local_jedomi_spt_signing_string
     * @covers \local_jedomi\proxy\spt_validator::validate
     */
    public function test_student_spt_survives_the_browser_round_trip(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->set_course_page($course);

        $spt = local_jedomi_generate_spt('student');
        $this->assertStringContainsString('"properties":{}', json_encode($spt['tools']));

        $returned = json_decode(json_encode($spt), true);

        $this->assertSame('student', \local_jedomi\proxy\spt_validator::validate($returned));
    }

    /**
     * MDL-UNIT-015: the signature changes when the content of the permissions changes.
     */
    public function test_generate_spt_for_user_signature_depends_on_the_content(): void {
        set_config('spt_secret', 'site-own-secret-for-tests', 'local_jedomi');
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $first = local_jedomi_generate_spt_for_user((int) $student->id, 'student');
        $second = local_jedomi_generate_spt_for_user((int) $other->id, 'student');
        $asteacher = local_jedomi_generate_spt_for_user((int) $student->id, 'teacher');

        $this->assertNotEmpty($first['signature']);
        $this->assertNotSame($first['signature'], $second['signature'], 'Another user must get another signature');
        $this->assertNotSame($first['signature'], $asteacher['signature'], 'Another role must get another signature');
    }

    /**
     * MDL-UNIT-016: in the web panel the role follows the permission in the current course.
     *
     * A user who is teacher in one course and student in another is 'teacher' only in the first one, and 'teacher'
     * outside a course.
     */
    public function test_get_user_role_follows_the_current_course(): void {
        global $COURSE;
        $user = $this->getDataGenerator()->create_user();
        $teaching = $this->getDataGenerator()->create_course();
        $learning = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $teaching->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user->id, $learning->id, 'student');
        $this->setUser($user);

        $COURSE = $teaching;
        $this->assertSame('teacher', local_jedomi_get_user_role());

        $COURSE = $learning;
        $this->assertSame('student', local_jedomi_get_user_role());

        // Outside a course the role is the same as in Telegram and the confirmation of actions (MDL-INT-039).
        $COURSE = get_site();
        $this->assertSame('teacher', local_jedomi_get_user_role());
    }

    /**
     * MDL-UNIT-016: the admin and manager roles take priority over the course permission.
     */
    public function test_get_user_role_gives_priority_to_admin_and_manager(): void {
        global $COURSE, $DB;
        $course = $this->getDataGenerator()->create_course();
        $COURSE = $course;

        $this->setAdminUser();
        $this->assertSame('admin', local_jedomi_get_user_role());

        $manager = $this->getDataGenerator()->create_user();
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerrole, $manager->id, \context_system::instance());
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'student');
        $this->setUser($manager);
        $this->assertSame('manager', local_jedomi_get_user_role());
    }

    // ... ========================
    // local_jedomi_is_role_allowed
    // ========================

    /**
     * Give a user a role in a course and make the course the current page.
     *
     * @param \stdClass $course Course of the page.
     * @return void
     */
    private function set_course_page(\stdClass $course): void {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->set_course($course);
        $PAGE->set_context(\context_course::instance($course->id));
    }

    /**
     * Id of a standard role.
     *
     * @param string $shortname Role short name.
     * @return int
     */
    private function role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * MDL-UNIT-011: a user whose roles are all hidden does not see the panel.
     *
     * The roles are site-level assignments, which the rule reads on every page.
     */
    public function test_user_with_every_role_hidden_cannot_see_the_panel(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        role_assign($this->role_id('coursecreator'), $user->id, \context_system::instance());
        set_config('hidden_roles', (string) $this->role_id('coursecreator'), 'local_jedomi');
        $this->setUser($user);
        $this->set_course_page($course);

        $this->assertFalse(local_jedomi_is_role_allowed());
    }

    /**
     * MDL-UNIT-011: a user with at least one visible role sees the panel even if another of their roles is hidden.
     */
    public function test_user_with_one_visible_role_can_see_the_panel(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        role_assign($this->role_id('coursecreator'), $user->id, \context_system::instance());
        role_assign($this->role_id('manager'), $user->id, \context_system::instance());
        set_config('hidden_roles', (string) $this->role_id('coursecreator'), 'local_jedomi');
        $this->setUser($user);
        $this->set_course_page($course);

        $this->assertTrue(local_jedomi_is_role_allowed());
    }

    /**
     * MDL-UNIT-011: a site administrator always sees the panel, even when all their roles are hidden.
     */
    public function test_site_admin_always_sees_the_panel(): void {
        $course = $this->getDataGenerator()->create_course();
        $admin = get_admin();
        role_assign($this->role_id('manager'), $admin->id, \context_system::instance());
        set_config('hidden_roles', (string) $this->role_id('manager'), 'local_jedomi');
        $this->setAdminUser();
        $this->set_course_page($course);

        $this->assertTrue(local_jedomi_is_role_allowed());
    }

    /**
     * MDL-UNIT-011: with no hidden roles every user sees the panel.
     */
    public function test_no_hidden_roles_shows_the_panel_to_everyone(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        unset_config('hidden_roles', 'local_jedomi');
        $this->setUser($user);
        $this->set_course_page($course);

        $this->assertTrue(local_jedomi_is_role_allowed());
    }

    // ... ========================
    // Permission token secret
    // ========================

    /**
     * MDL-UNIT-014: after installation each site has its own generated permission token secret.
     */
    public function test_installation_generates_a_site_specific_permission_secret(): void {
        $secret = (string) get_config('local_jedomi', 'spt_secret');

        $this->assertNotSame('', $secret, 'A permission token secret must be generated when the plugin is installed');
        $this->assertNotSame('jedomi-spt-secret', $secret, 'The generated secret must not be the built-in key');
        $this->assertGreaterThanOrEqual(32, strlen($secret), 'The generated secret must be long enough to resist guessing');
    }

    /**
     * MDL-UNIT-014: with an empty secret the permissions are never signed with a key known from the code.
     */
    public function test_empty_secret_never_signs_with_a_known_key(): void {
        set_config('spt_secret', '', 'local_jedomi');
        $admin = get_admin();

        $spt = local_jedomi_generate_spt_for_user((int) $admin->id, 'admin');

        // What anyone who reads the plugin code could compute for the same payload.
        $payload = $spt;
        unset($payload['signature']);
        ksort($payload);
        $forgeable = hash_hmac('sha256', json_encode($payload), 'jedomi-spt-secret');
        $this->assertNotSame(
            $forgeable,
            $spt['signature'] ?? '',
            'Permissions must not be signed with the built-in key, anyone could forge them'
        );
    }

    // ... ========================
    // Log rotation
    // ========================

    /**
     * Path of the active jedomi log inside the PHPUnit dataroot.
     *
     * @return string Absolute path to jedomi.log
     */
    private function get_log_path(): string {
        global $CFG;
        return $CFG->dataroot . '/local/jedomi/jedomi.log';
    }

    /**
     * Test the log rotates to a numbered file once it exceeds the size cap.
     *
     * MDL-INT-082: the log rotates when it exceeds the maximum size.
     */
    public function test_log_rotates_when_exceeding_max(): void {
        global $CFG;
        $CFG->local_jedomi_logmaxbytes = 1024;
        $logfile = $this->get_log_path();

        // Fill the active log past the cap.
        local_jedomi_log(str_repeat('x', 2048));
        // Next write must rotate first, then append to a fresh file.
        local_jedomi_log('after-rotation marker');

        $this->assertFileExists($logfile . '.1', 'Oversized log should rotate to jedomi.log.1');
        $this->assertStringContainsString('xxx', file_get_contents($logfile . '.1'));
        $active = file_get_contents($logfile);
        $this->assertStringContainsString('after-rotation marker', $active);
        $this->assertLessThan(1024, strlen($active), 'Active log should restart small after rotation');
    }

    /**
     * Test the retention policy: bounded generations, oldest content dropped.
     *
     * MDL-INT-082: the rotation keeps the defined number of files.
     *
     * Policy under test: one active jedomi.log capped at
     * LOCAL_JEDOMI_LOG_MAX_BYTES (overridable via $CFG->local_jedomi_logmaxbytes)
     * plus at most LOCAL_JEDOMI_LOG_MAX_FILES (5) rotated generations
     * (jedomi.log.1 … .5) — a deterministic ~30 MB total. On rotation each
     * generation shifts one position (.4 → .5) and the previous oldest (.5)
     * is deleted. The jedomi.log.lock file guards rotation and holds no data.
     */
    public function test_log_retention_cap(): void {
        global $CFG;
        $CFG->local_jedomi_logmaxbytes = 1024;
        $logfile = $this->get_log_path();
        $logdir = dirname($logfile);
        check_dir_exists($logdir, true, true);

        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($logfile . '.' . $i, "rotated-{$i}\n");
        }
        file_put_contents($logfile, str_repeat('y', 2048));

        local_jedomi_log('trigger rotation');

        $files = array_filter(glob($logfile . '*'), function (string $file): bool {
            return !str_ends_with($file, '.lock');
        });
        $this->assertLessThanOrEqual(6, count($files), 'At most active + 5 rotated files are retained');
        $this->assertStringContainsString('rotated-4', file_get_contents($logfile . '.5'), 'Old .4 should shift to .5');
        $this->assertStringContainsString('yyy', file_get_contents($logfile . '.1'), 'Active log should shift to .1');
        foreach ($files as $file) {
            $this->assertStringNotContainsString('rotated-5', file_get_contents($file), 'Oldest rotated content is dropped');
        }
    }

    /**
     * Test rotation is skipped, without error, while another process holds the lock.
     *
     * MDL-INT-082: no rotation while another process holds the lock.
     *
     * flock() locks are per open file description, so a second fopen+flock in
     * this same process behaves like a concurrent PHP-FPM worker. While the
     * lock is held, a write to an oversized log must NOT rotate (no .1 file)
     * but the entry must still be appended; after releasing the lock the next
     * write rotates normally — proving check and rename chain sit under it.
     */
    public function test_log_rotation_skipped_while_lock_held(): void {
        global $CFG;
        $CFG->local_jedomi_logmaxbytes = 1024;
        $logfile = $this->get_log_path();
        check_dir_exists(dirname($logfile), true, true);

        // Simulate a concurrent worker holding the rotation lock.
        $holder = fopen($logfile . '.lock', 'c');
        $this->assertNotFalse($holder);
        $this->assertTrue(flock($holder, LOCK_EX));

        file_put_contents($logfile, str_repeat('z', 2048));
        local_jedomi_log('while lock held');

        $this->assertFileDoesNotExist($logfile . '.1', 'No rotation may happen while the lock is held elsewhere');
        $this->assertStringContainsString(
            'while lock held',
            file_get_contents($logfile),
            'The worker that skips rotation must still append its entry'
        );

        // Release the lock: the next oversized write rotates normally.
        flock($holder, LOCK_UN);
        fclose($holder);
        local_jedomi_log('after lock released');

        $this->assertFileExists($logfile . '.1', 'Rotation must resume once the lock is free');
        $this->assertStringContainsString('after lock released', file_get_contents($logfile));
    }

    /**
     * Test a log write never throws and appends normally with and without the override.
     *
     * MDL-INT-082: writing the log never interrupts the operation.
     */
    public function test_log_write_never_throws(): void {
        global $CFG;
        $logfile = $this->get_log_path();

        local_jedomi_log('plain entry', ['k' => 'v']);
        $this->assertStringContainsString('plain entry', file_get_contents($logfile));

        $CFG->local_jedomi_logmaxbytes = 1024;
        local_jedomi_log('with override');
        $this->assertStringContainsString('with override', file_get_contents($logfile));
    }

    /**
     * JEDOMI-SEC-009: the error of a tool result is redacted from the technical log, like queries.
     */
    public function test_log_redacts_errors(): void {
        $redacted = local_jedomi_redact_log_data([
            'tool' => 'execute_sql',
            'error' => "Unknown column 'secret' | DBG: SELECT secret FROM mdl_user",
            'nested' => ['error' => 'RuntimeException: raw marker'],
            'class' => 'dml_read_exception',
        ]);

        $this->assertSame('execute_sql', $redacted['tool']);
        $this->assertSame('dml_read_exception', $redacted['class']);
        $this->assertStringStartsWith('[redacted ', $redacted['error']);
        $this->assertStringStartsWith('[redacted ', $redacted['nested']['error']);
    }

    /**
     * MDL-UNIT-085: on the profile, a user with an allowed role can link the account and one whose roles are all
     * disabled cannot.
     *
     * The roles are site-level assignments, which the profile reads on every page.
     *
     * @return void
     */
    public function test_telegram_profile_access_follows_the_allowed_roles(): void {
        global $PAGE;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        role_assign($this->role_id('coursecreator'), $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $PAGE->set_context(\context_system::instance());

        set_config('telegram_hidden_roles', (string) $this->role_id('manager'), 'local_jedomi');
        $this->assertTrue(local_jedomi_is_telegram_role_allowed());

        set_config('telegram_hidden_roles', $this->role_id('coursecreator') . ',' . $this->role_id('manager'), 'local_jedomi');
        $this->assertFalse(local_jedomi_is_telegram_role_allowed());

        unset_config('telegram_hidden_roles', 'local_jedomi');
        $this->assertTrue(local_jedomi_is_telegram_role_allowed());
    }

    /**
     * MDL-UNIT-085: a site administrator always has access to Telegram on the profile, even with every role disabled.
     *
     * @return void
     */
    public function test_telegram_profile_access_never_excludes_admins(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(\context_system::instance());
        set_config('telegram_hidden_roles', implode(',', $DB->get_fieldset_select('role', 'id', '1 = 1')), 'local_jedomi');

        $this->assertTrue(local_jedomi_is_telegram_role_allowed());
    }

    /**
     * MDL-UNIT-090: the profile link and the bot apply the same Telegram role rule, also for roles given in a course.
     *
     * @return void
     */
    public function test_telegram_role_rule_is_the_same_on_the_profile_and_the_bot(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($user);
        $PAGE->set_context(\context_system::instance());
        $bot = static fn(int $userid): bool => \local_jedomi\telegram\webhook_handler::is_user_role_allowed($userid);

        // The student role is given in a course, not on the page the profile is shown.
        set_config('telegram_hidden_roles', (string) $this->role_id('student'), 'local_jedomi');
        $this->assertFalse(local_jedomi_is_telegram_role_allowed());
        $this->assertFalse($bot((int) $user->id));

        set_config('telegram_hidden_roles', (string) $this->role_id('manager'), 'local_jedomi');
        $this->assertTrue(local_jedomi_is_telegram_role_allowed());
        $this->assertTrue($bot((int) $user->id));

        // Administrators always have access on both.
        $this->setAdminUser();
        set_config('telegram_hidden_roles', $this->role_id('student') . ',' . $this->role_id('manager'), 'local_jedomi');
        $this->assertTrue(local_jedomi_is_telegram_role_allowed());
        $this->assertTrue($bot((int) get_admin()->id));
    }

    /**
     * Roles and the exact tools each one receives, with file processing on.
     *
     * @return array
     */
    public static function tools_by_role_provider(): array {
        $common = ['describe_table', 'search_tables', 'ws_describe', 'ws_search'];
        $teacher = array_merge($common, ['document_read', 'execute_sql', 'ws_execute']);
        $manager = array_merge($teacher, ['admin_describe', 'admin_execute', 'admin_search', 'create_agent', 'delete_agent',
            'list_agents', 'site_overview']);
        $admin = array_merge($manager, ['get_site_config']);
        // Students also read their own courses, visible grades and assignments; never other users' data.
        $student = array_merge($common, ['my_assignments', 'my_courses', 'my_grades']);
        return [
            'admin' => ['admin', $admin],
            'manager' => ['manager', $manager],
            'teacher' => ['teacher', $teacher],
            'student' => ['student', $student],
            'unknown role' => ['guest', $common],
            'empty role' => ['', $common],
        ];
    }

    /**
     * MDL-UNIT-094: each role receives exactly its tools; an unknown role gets only the tools common to every role.
     *
     * @dataProvider tools_by_role_provider
     * @param string $role Role.
     * @param string[] $expected Expected tool names.
     * @return void
     */
    public function test_each_role_receives_exactly_its_tools(string $role, array $expected): void {
        $this->resetAfterTest();
        set_config('files_enabled', 1, 'local_jedomi');
        // The administration operations are offered only to who can run them; the administrator can.
        $this->setAdminUser();

        $names = array_map(static fn(array $tool): string => $tool['function']['name'], local_jedomi_get_tool_definitions($role));
        sort($names);
        sort($expected);

        $this->assertSame($expected, $names);
    }

    /**
     * MDL-UNIT-094: without file processing nobody receives the activity PDF reader, and the tools shared with
     * Telegram and agents never include sending messages.
     *
     * @return void
     */
    public function test_pdf_reader_needs_file_processing_and_shared_tools_never_send_messages(): void {
        $this->resetAfterTest();
        set_config('files_enabled', 0, 'local_jedomi');

        foreach (['admin', 'manager', 'teacher', 'student'] as $role) {
            $names = array_map(
                static fn(array $tool): string => $tool['function']['name'],
                local_jedomi_get_tool_definitions($role)
            );
            $this->assertNotContains('document_read', $names, $role);
            $this->assertNotContains('send_messages', $names, $role);
        }
    }

    /**
     * Whether the profile of the current user offers the Telegram page.
     *
     * @return bool
     */
    private function profile_offers_telegram(): bool {
        global $USER;
        $tree = new \core_user\output\myprofile\tree();
        local_jedomi_myprofile_navigation($tree, $USER, true, null);
        return array_key_exists('telegram', $tree->nodes);
    }

    /**
     * Conditions of the Telegram link of the profile, each one missing in turn.
     *
     * @return array<string, array{0: string}>
     */
    public static function missing_condition_provider(): array {
        return [
            'Jedomi disabled' => ['jedomi'],
            'integration off' => ['integration'],
            'bot not verified' => ['verified'],
            'role not allowed' => ['role'],
        ];
    }

    /**
     * MDL-INT-061: the profile shows the Telegram link only with Jedomi enabled, the integration on, the bot verified and
     * the role allowed.
     *
     * @dataProvider missing_condition_provider
     * @param string $missing Condition that is not met.
     */
    public function test_profile_link_needs_every_condition(string $missing): void {
        global $DB;
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the plugin state is not available.');
        }
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($managerroleid, $user->id, \context_system::instance());
        $this->setUser($user);
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_jedomi', true);
        set_config('telegram_enabled', 1, 'local_jedomi');
        set_config('telegram_bot_verified', 1, 'local_jedomi');
        $this->assertTrue($this->profile_offers_telegram(), 'With every condition met the link is shown');

        match ($missing) {
            'jedomi' => \aiprovider_datacurso\local\plugin_settings::set_enabled('local_jedomi', false),
            'integration' => set_config('telegram_enabled', 0, 'local_jedomi'),
            'verified' => set_config('telegram_bot_verified', 0, 'local_jedomi'),
            'role' => set_config('telegram_hidden_roles', (string) $managerroleid, 'local_jedomi'),
        };

        $this->assertFalse($this->profile_offers_telegram());
    }

    /**
     * MDL-INT-086: a site without its own permission token secret, or with the former built-in one, gets a random secret
     * of its own, and a secret of its own is kept.
     *
     * @return void
     */
    public function test_site_gets_its_own_permission_token_secret_and_keeps_it(): void {
        $this->resetAfterTest();
        foreach (['', 'jedomi-spt-secret'] as $old) {
            set_config('spt_secret', $old, 'local_jedomi');
            $secret = local_jedomi_get_spt_secret();
            $this->assertNotSame($old, $secret);
            $this->assertGreaterThanOrEqual(64, strlen($secret));
            $this->assertSame($secret, get_config('local_jedomi', 'spt_secret'), 'The new secret is saved');
        }
        set_config('spt_secret', str_repeat('c0ffee', 12), 'local_jedomi');
        $this->assertSame(str_repeat('c0ffee', 12), local_jedomi_get_spt_secret(), 'An own secret is kept');
    }
}
