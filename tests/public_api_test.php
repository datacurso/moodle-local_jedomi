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

namespace local_jedomi;

use aiprovider_datacurso\local\plugin_settings;

/**
 * Tests for the server API other plugins use to know whether the Jedomi chat is offered.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\public_api
 */
final class public_api_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
        $this->resetAfterTest();
        plugin_settings::set_enabled('local_jedomi', true);
    }

    /**
     * A user enrolled in a course has the chat in it.
     */
    public function test_chat_is_available_to_an_enrolled_user(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->assertTrue(public_api::chat_available((int) $user->id, \context_course::instance($course->id)));
        $this->assertTrue(public_api::chat_available((int) $user->id, \context_system::instance()));
    }

    /**
     * Jedomi disabled in the Datacurso AI provider: no chat for anyone.
     */
    public function test_chat_is_not_available_when_jedomi_is_disabled(): void {
        plugin_settings::set_enabled('local_jedomi', false);

        $this->assertFalse(public_api::chat_available((int) get_admin()->id, \context_system::instance()));
    }

    /**
     * Guests, missing, deleted and suspended users never have the chat.
     */
    public function test_chat_is_not_available_to_guests_or_inactive_users(): void {
        $system = \context_system::instance();
        $deleted = $this->getDataGenerator()->create_user(['deleted' => 1]);
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);

        $this->assertFalse(public_api::chat_available((int) guest_user()->id, $system));
        $this->assertFalse(public_api::chat_available(0, $system));
        $this->assertFalse(public_api::chat_available(999999, $system));
        $this->assertFalse(public_api::chat_available((int) $deleted->id, $system));
        $this->assertFalse(public_api::chat_available((int) $suspended->id, $system));
    }

    /**
     * A course excluded in the course availability settings has no chat; the activity context of that course neither.
     */
    public function test_chat_is_not_available_in_an_excluded_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        set_config('course_mode', 'exclude', 'local_jedomi');
        set_config('course_list', (string) $course->id, 'local_jedomi');

        $this->assertFalse(public_api::chat_available((int) $user->id, \context_course::instance($course->id)));
        $this->assertFalse(public_api::chat_available((int) $user->id, \context_module::instance($page->cmid)));
    }

    /**
     * A user whose only roles are hidden from Jedomi has no chat.
     */
    public function test_chat_is_not_available_to_a_hidden_role(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $authenticated = (int) $DB->get_field('role', 'id', ['shortname' => 'user']);
        set_config('hidden_roles', $student . ',' . $authenticated, 'local_jedomi');

        $this->assertFalse(public_api::chat_available((int) $user->id, \context_course::instance($course->id)));
    }
}
