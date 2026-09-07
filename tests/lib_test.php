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
 * @covers \local_jedomi_generate_spt_for_user()
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
     * Test that an admin user gets 'admin' role.
     */
    public function test_get_user_role_for_user_admin(): void {
        $admin = get_admin();
        $this->assertNotFalse($admin, 'Admin user must exist');

        $role = local_jedomi_get_user_role_for_user((int) $admin->id);
        $this->assertEquals('admin', $role);
    }

    /**
     * Test that a user with manager role at system level gets 'manager'.
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
     * Test that a user enrolled as editingteacher in a course gets 'teacher'.
     */
    public function test_get_user_role_for_user_teacher(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $role = local_jedomi_get_user_role_for_user((int) $teacher->id);
        $this->assertEquals('teacher', $role);
    }

    /**
     * Test that a user enrolled as student gets 'student'.
     */
    public function test_get_user_role_for_user_student(): void {
        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $role = local_jedomi_get_user_role_for_user((int) $student->id);
        $this->assertEquals('student', $role);
    }

    /**
     * Test that a user with no enrolments falls back to 'student'.
     */
    public function test_get_user_role_for_user_no_enrolments(): void {
        $user = $this->getDataGenerator()->create_user();

        $role = local_jedomi_get_user_role_for_user((int) $user->id);
        $this->assertEquals('student', $role);
    }

    /**
     * Test that a user who is both teacher and student gets 'teacher' (higher priority).
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
     * Test that generate_spt_for_user returns empty array for non-existent user.
     */
    public function test_generate_spt_for_user_nonexistent(): void {
        $result = local_jedomi_generate_spt_for_user(999999, 'student');
        $this->assertEquals([], $result);
    }

    /**
     * Test that generate_spt_for_user returns empty array for deleted user.
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
     * Test that generate_spt_for_user returns correct structure for admin user.
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
     * Test that generate_spt_for_user returns course_ids_owned for teacher user.
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
     * Test that generate_spt_for_user returns course_ids_enrolled for student user.
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

        ksort($result);
        $expectedsig = hash_hmac('sha256', json_encode($result), $staticsecret);

        $this->assertEquals($expectedsig, $signature);
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
}
