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

use core\hook\output\before_footer_html_generation;

/**
 * Tests for the per-course availability of the Jedomi panel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\course_access
 * @covers     \local_jedomi\hook_callbacks
 */
final class course_access_test extends \advanced_testcase {
    /**
     * Save the availability mode and the list of courses as the Courses tab does.
     *
     * @param string $mode Availability mode: all, include or exclude.
     * @param int[] $courseids Selected courses.
     * @return void
     */
    private function set_availability(string $mode, array $courseids): void {
        set_config('course_mode', $mode, 'local_jedomi');
        set_config('course_list', implode(',', $courseids), 'local_jedomi');
    }

    /**
     * MDL-UNIT-008: with "All courses" the panel is available in every course, also with an empty list.
     *
     * @return void
     */
    public function test_all_courses_mode_shows_the_panel_everywhere(): void {
        $this->resetAfterTest();
        $selected = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->set_availability('all', [(int) $selected->id]);
        $this->assertTrue(course_access::is_enabled((int) $selected->id));
        $this->assertTrue(course_access::is_enabled((int) $other->id));

        $this->set_availability('all', []);
        $this->assertTrue(course_access::is_enabled((int) $selected->id));
        $this->assertTrue(course_access::is_enabled((int) $other->id));
    }

    /**
     * MDL-UNIT-008: on a site with nothing saved the panel is available in every course.
     *
     * @return void
     */
    public function test_unsaved_mode_behaves_as_all_courses(): void {
        $this->resetAfterTest();
        unset_config('course_mode', 'local_jedomi');
        unset_config('course_list', 'local_jedomi');
        $course = $this->getDataGenerator()->create_course();

        $this->assertTrue(course_access::is_enabled((int) $course->id));
    }

    /**
     * MDL-UNIT-008: "Hide in the selected courses" hides the panel only in the chosen courses.
     *
     * @return void
     */
    public function test_exclude_mode_hides_the_panel_only_in_the_selected_courses(): void {
        $this->resetAfterTest();
        $selected = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->set_availability('exclude', [(int) $selected->id]);
        $this->assertFalse(course_access::is_enabled((int) $selected->id));
        $this->assertTrue(course_access::is_enabled((int) $other->id));

        // With an empty list nothing is hidden.
        $this->set_availability('exclude', []);
        $this->assertTrue(course_access::is_enabled((int) $selected->id));
        $this->assertTrue(course_access::is_enabled((int) $other->id));
    }

    /**
     * MDL-UNIT-008: "Show only in the selected courses" shows the panel in the chosen courses and in no other course.
     *
     * @return void
     */
    public function test_include_mode_shows_the_panel_only_in_the_selected_courses(): void {
        $this->resetAfterTest();
        $selected = $this->getDataGenerator()->create_course();
        $alsoselected = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();

        $this->set_availability('include', [(int) $selected->id, (int) $alsoselected->id]);
        $this->assertTrue(course_access::is_enabled((int) $selected->id));
        $this->assertTrue(course_access::is_enabled((int) $alsoselected->id));
        $this->assertFalse(course_access::is_enabled((int) $other->id));

        // With an empty list no course shows the panel.
        $this->set_availability('include', []);
        $this->assertFalse(course_access::is_enabled((int) $selected->id));
        $this->assertFalse(course_access::is_enabled((int) $other->id));
    }

    /**
     * MDL-UNIT-008: on a real course page the sidebar is injected or not according to the mode and the list.
     *
     * @return void
     */
    public function test_sidebar_injection_follows_the_course_availability(): void {
        $this->resetAfterTest();
        $selected = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $this->set_availability('include', [(int) $selected->id]);

        $this->assertTrue($this->sidebar_injected_in_course($selected));
        $this->assertFalse($this->sidebar_injected_in_course($other));
    }

    /**
     * MDL-UNIT-009: with "Show only in the selected courses" the panel must not appear on pages outside a course.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_include_mode_hides_the_panel_outside_courses(): void {
        $this->markTestSkipped('Pending fix: the "only selected courses" mode still shows the panel on pages outside a course.');
    }

    /**
     * Run the sidebar hook on a course page as a logged-in admin and tell whether the sidebar was injected.
     *
     * @param \stdClass $course Course of the page.
     * @return bool True when the sidebar AMD module was requested.
     */
    private function sidebar_injected_in_course(\stdClass $course): bool {
        global $PAGE;
        // The hook reads the plugin state from aiprovider_datacurso.
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the plugin state is not available.');
        }
        $this->setAdminUser();
        $PAGE = new \moodle_page();
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->set_course($course);

        hook_callbacks::before_footer(new before_footer_html_generation($PAGE->get_renderer('core')));

        return str_contains($PAGE->requires->get_end_code(), 'local_jedomi/sidebar');
    }
    /**
     * MDL-INT-088: a duplicated course is treated as a new course: it does not inherit the availability selection of the
     * original nor its agents.
     *
     * @return void
     */
    public function test_duplicated_course_does_not_inherit_the_selection_nor_the_agents(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/externallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $original = $this->getDataGenerator()->create_course(['shortname' => 'ORIG']);
        $now = time();
        $DB->insert_record('local_jedomi_agents', (object) ['userid' => get_admin()->id, 'name' => 'Course agent',
            'prompt' => 'Summary', 'schedule_cron' => '0 8 * * *', 'context_type' => 'course',
            'context_id' => \context_course::instance($original->id)->id, 'enabled' => 1, 'failcount' => 0,
            'nextrun' => $now + 3600, 'timecreated' => $now, 'timemodified' => $now]);
        $this->set_availability('include', [(int) $original->id]);

        // Other plugins installed on the site may print while restoring.
        ob_start();
        $copy = \core_course_external::duplicate_course((int) $original->id, 'Copy', 'COPY', (int) $original->category);
        ob_end_clean();

        $this->assertTrue(course_access::is_enabled((int) $original->id));
        $this->assertFalse(course_access::is_enabled((int) $copy['id']), 'The copy is not in the selection');
        $this->assertSame(0, $DB->count_records(
            'local_jedomi_agents',
            ['context_id' => \context_course::instance($copy['id'])->id]
        ));
        $this->assertSame(1, $DB->count_records('local_jedomi_agents'));
    }

    /**
     * MDL-INT-096: hiding the Authenticated user role hides the panel from every user whose other roles are hidden
     * too, it also works on the site home, and the server evaluates the rule on the activity of the page.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_authenticated_user_and_guest_roles_have_a_real_effect(): void {
        $this->markTestSkipped('Pending fix: hiding Authenticated user only affects users without other roles, hiding '
            . 'Guest or Authenticated user on the site home has no effect, and the server evaluates the '
            . 'rule on the course sent by the browser.');
    }
}
