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

/**
 * Tests for the course of the chat requests: never taken from the browser without checking it.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_jedomi_course_request_error
 * @covers     ::local_jedomi_signed_request_course
 * @covers     ::local_jedomi_is_same_course
 */
final class course_request_test extends \advanced_testcase {
    /**
     * Chat permissions signed for a course, as the panel configuration signs them on the page of that course.
     *
     * @param \stdClass|null $course Course of the page; null for the site home.
     * @return array Signed permissions.
     */
    private function permissions_on_page(?\stdClass $course): array {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_course($course ?? get_site());
        return \local_jedomi_generate_spt(\local_jedomi_get_user_role());
    }

    /**
     * MDL-INT-095: a course that does not exist, or that the user cannot enter, is refused; an enrolled course, the
     * site and any course of an administrator are accepted.
     *
     * @return void
     */
    public function test_only_courses_the_user_can_enter_are_accepted(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $own = $gen->create_course();
        $other = $gen->create_course();
        $student = $gen->create_and_enrol($own, 'student');

        $this->setUser($student);
        $this->assertNull(\local_jedomi_course_request_error((int) $own->id), 'Enrolled course');
        $this->assertNull(\local_jedomi_course_request_error(0), 'Outside a course');
        $this->assertNull(\local_jedomi_course_request_error(SITEID), 'Site home');
        $this->assertSame('course_access_denied', \local_jedomi_course_request_error((int) $other->id)['code']);
        $this->assertSame('course_access_denied', \local_jedomi_course_request_error((int) $other->id + 1000)['code']);
        $this->assertSame(
            get_string('chat_error_course_access', 'local_jedomi'),
            \local_jedomi_course_request_error((int) $other->id)['message']
        );

        $this->setAdminUser();
        $this->assertNull(\local_jedomi_course_request_error((int) $other->id), 'An administrator enters every course');
    }

    /**
     * MDL-INT-095: a request sent after the panel got its permissions runs with the signed course; the course the
     * browser names must be that one.
     *
     * @return void
     */
    public function test_course_of_a_request_is_the_signed_one(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $own = $gen->create_course();
        $second = $gen->create_course();
        $student = $gen->create_and_enrol($own, 'student');
        $gen->enrol_user($student->id, $second->id, 'student');
        $this->setUser($student);
        $spt = $this->permissions_on_page($own);

        $signed = \local_jedomi_signed_request_course($spt, null);
        $this->assertNull($signed['error']);
        $this->assertSame((int) $own->id, $signed['courseid'], 'Without a course in the request, the signed one');

        $this->assertNull(\local_jedomi_signed_request_course($spt, (int) $own->id)['error']);

        // Another course, even one the user can enter, or the site home are refused.
        $this->assertSame('course_mismatch', \local_jedomi_signed_request_course($spt, (int) $second->id)['error']['code']);
        $this->assertSame('course_mismatch', \local_jedomi_signed_request_course($spt, 0)['error']['code']);
    }

    /**
     * MDL-INT-095: the site home and "outside a course" are the same place, so permissions signed on the site home
     * are accepted with either.
     *
     * @return void
     */
    public function test_site_permissions_accept_the_site_and_no_course(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $spt = $this->permissions_on_page(null);

        $this->assertNull(\local_jedomi_signed_request_course($spt, 0)['error']);
        $this->assertNull(\local_jedomi_signed_request_course($spt, SITEID)['error']);
        $this->assertTrue(\local_jedomi_is_same_course(0, SITEID));
        $this->assertFalse(\local_jedomi_is_same_course(SITEID, SITEID + 1));
    }

    /**
     * MDL-INT-095: requests without permissions, with permissions of another session or with altered permissions are
     * refused before the course is used.
     *
     * @return void
     */
    public function test_missing_or_altered_permissions_are_refused(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $own = $gen->create_course();
        $other = $gen->create_course();
        $student = $gen->create_and_enrol($own, 'student');
        $this->setUser($student);
        $spt = $this->permissions_on_page($own);

        $this->assertSame('invalid_spt', \local_jedomi_signed_request_course(null, null)['error']['code']);
        $this->assertSame('invalid_spt', \local_jedomi_signed_request_course('{}', null)['error']['code']);

        $altered = $spt;
        $altered['course_id'] = (int) $other->id;
        $this->assertSame('invalid_spt', \local_jedomi_signed_request_course($altered, (int) $other->id)['error']['code']);
    }

    /**
     * MDL-INT-095: a user who lost access to the course since the permissions were signed is refused.
     *
     * @return void
     */
    public function test_signed_course_the_user_left_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($student);
        $spt = $this->permissions_on_page($course);

        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->unenrol_user($instance, (int) $student->id);

        $this->assertSame('course_access_denied', \local_jedomi_signed_request_course($spt, null)['error']['code']);
    }
}
