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
 * Tests for the pages where the Jedomi panel is injected.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the plugin state is not available.');
        }
    }

    /**
     * Create a course with a quiz and a student enrolled in it, logged in.
     *
     * @return array{0: \stdClass, 1: \stdClass} Course and quiz.
     */
    private function student_in_course_with_quiz(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        return [$course, $quiz];
    }

    /**
     * Run the footer hook on a page and tell whether the panel was injected.
     *
     * @param \moodle_url $url Page URL.
     * @param string $pagetype Page type.
     * @param string $layout Page layout.
     * @param \stdClass $course Course of the page.
     * @param \stdClass|null $quiz Quiz of the page, if it is a quiz page.
     * @return bool
     */
    private function panel_injected(
        \moodle_url $url,
        string $pagetype,
        string $layout,
        \stdClass $course,
        ?\stdClass $quiz = null
    ): bool {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_url($url);
        if ($quiz) {
            $PAGE->set_cm(get_coursemodule_from_id('quiz', $quiz->cmid), $course);
        } else {
            $PAGE->set_course($course);
        }
        $PAGE->set_pagetype($pagetype);
        $PAGE->set_pagelayout($layout);

        hook_callbacks::before_footer(new before_footer_html_generation($PAGE->get_renderer('core')));

        return str_contains($PAGE->requires->get_end_code(), 'local_jedomi/sidebar');
    }

    /**
     * MDL-UNIT-082: the panel is not shown on quiz attempt pages, including the safe browser layout.
     *
     * @return void
     */
    public function test_panel_is_not_shown_during_a_quiz_attempt(): void {
        [$course, $quiz] = $this->student_in_course_with_quiz();
        $url = new \moodle_url('/mod/quiz/attempt.php', ['attempt' => 1, 'cmid' => $quiz->cmid]);

        $this->assertFalse(
            $this->panel_injected($url, 'mod-quiz-attempt', 'incourse', $course, $quiz),
            'The panel must not be available during a quiz attempt'
        );
        $this->assertFalse(
            $this->panel_injected($url, 'mod-quiz-attempt', 'secure', $course, $quiz),
            'The panel must not be available during a quiz attempt in safe browser mode'
        );
        $summary = new \moodle_url('/mod/quiz/summary.php', ['attempt' => 1, 'cmid' => $quiz->cmid]);
        $this->assertFalse(
            $this->panel_injected($summary, 'mod-quiz-summary', 'incourse', $course, $quiz),
            'The panel must not be available on the attempt summary'
        );
    }

    /**
     * MDL-UNIT-082: the panel is not shown on pop-up, embedded or secure pages.
     *
     * @return void
     */
    public function test_panel_is_not_shown_in_popup_embedded_or_secure_pages(): void {
        [$course] = $this->student_in_course_with_quiz();
        $url = new \moodle_url('/course/view.php', ['id' => $course->id]);

        foreach (['popup', 'embedded', 'secure'] as $layout) {
            $this->assertFalse(
                $this->panel_injected($url, 'course-view-topics', $layout, $course),
                "The panel must not be available with the {$layout} layout"
            );
        }
    }

    /**
     * MDL-UNIT-082: the panel is shown on the quiz review and on the other pages of the course.
     *
     * @return void
     */
    public function test_panel_is_shown_on_quiz_review_and_course_pages(): void {
        [$course, $quiz] = $this->student_in_course_with_quiz();

        $review = new \moodle_url('/mod/quiz/review.php', ['attempt' => 1, 'cmid' => $quiz->cmid]);
        $this->assertTrue($this->panel_injected($review, 'mod-quiz-review', 'incourse', $course, $quiz));
        $view = new \moodle_url('/mod/quiz/view.php', ['id' => $quiz->cmid]);
        $this->assertTrue($this->panel_injected($view, 'mod-quiz-view', 'incourse', $course, $quiz));
        $courseurl = new \moodle_url('/course/view.php', ['id' => $course->id]);
        $this->assertTrue($this->panel_injected($courseurl, 'course-view-topics', 'course', $course));
    }

    /**
     * MDL-UNIT-086: guests and users not logged in never see the panel, the "Hide from guest users" option without
     * effect is gone, and the role visibility help says that guests never see it.
     *
     * @return void
     */
    public function test_guests_never_see_the_panel_and_no_option_without_effect(): void {
        [$course] = $this->student_in_course_with_quiz();
        $url = new \moodle_url('/course/view.php', ['id' => $course->id]);

        $this->setGuestUser();
        $this->assertFalse($this->panel_injected($url, 'course-view-topics', 'course', $course), 'A guest never sees the panel');
        $this->setUser(null);
        $this->assertFalse(
            $this->panel_injected($url, 'course-view-topics', 'course', $course),
            'A user not logged in never sees the panel'
        );

        $this->assertFalse(get_string_manager()->string_exists('hide_from_guests', 'local_jedomi'));
        $this->assertStringContainsStringIgnoringCase('guests', get_string('role_visibility_help', 'local_jedomi'));
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
     * MDL-INT-038: the panel is injected for a logged-in user who is not a guest, with Jedomi enabled, the course available
     * and a visible role, and the old activation setting of the plugin is ignored.
     */
    public function test_panel_is_injected_when_every_condition_is_met(): void {
        [$course] = $this->student_in_course_with_quiz();
        set_config('enabled', '0', 'local_jedomi');
        set_config('plugin_enabled_local_jedomi', '1', 'aiprovider_datacurso');
        $url = new \moodle_url('/course/view.php', ['id' => $course->id]);

        $this->assertTrue($this->panel_injected($url, 'course-view-topics', 'course', $course));
    }

    /**
     * MDL-INT-038: the panel is not injected when any of the access conditions is missing.
     */
    public function test_panel_is_not_injected_when_a_condition_is_missing(): void {
        [$course] = $this->student_in_course_with_quiz();
        $url = new \moodle_url('/course/view.php', ['id' => $course->id]);

        set_config('plugin_enabled_local_jedomi', '0', 'aiprovider_datacurso');
        $this->assertFalse($this->panel_injected($url, 'course-view-topics', 'course', $course), 'Jedomi disabled');
        set_config('plugin_enabled_local_jedomi', '1', 'aiprovider_datacurso');

        set_config('course_mode', 'exclude', 'local_jedomi');
        set_config('course_list', (string) $course->id, 'local_jedomi');
        $this->assertFalse($this->panel_injected($url, 'course-view-topics', 'course', $course), 'Course not available');
        set_config('course_mode', 'all', 'local_jedomi');

        $hiddenuser = $this->getDataGenerator()->create_user();
        role_assign($this->role_id('coursecreator'), $hiddenuser->id, \context_system::instance());
        set_config('hidden_roles', (string) $this->role_id('coursecreator'), 'local_jedomi');
        $this->setUser($hiddenuser);
        $this->assertFalse($this->panel_injected($url, 'course-view-topics', 'course', $course), 'Every role hidden');
        set_config('hidden_roles', '', 'local_jedomi');

        $this->setGuestUser();
        $this->assertFalse($this->panel_injected($url, 'course-view-topics', 'course', $course), 'Guest');
        $this->setUser(null);
        $this->assertFalse($this->panel_injected($url, 'course-view-topics', 'course', $course), 'Not logged in');
    }

    /**
     * MDL-INT-010: a student whose role is hidden does not see the panel in the course nor inside its activities.
     */
    public function test_student_with_the_role_hidden_sees_no_panel_in_course_or_activity(): void {
        [$course, $quiz] = $this->student_in_course_with_quiz();
        set_config('hidden_roles', (string) $this->role_id('student'), 'local_jedomi');

        $courseurl = new \moodle_url('/course/view.php', ['id' => $course->id]);
        $this->assertFalse(
            $this->panel_injected($courseurl, 'course-view-topics', 'course', $course),
            'A student with the role hidden must not see the panel in the course'
        );
        $view = new \moodle_url('/mod/quiz/view.php', ['id' => $quiz->cmid]);
        $this->assertFalse(
            $this->panel_injected($view, 'mod-quiz-view', 'incourse', $course, $quiz),
            'A student with the role hidden must not see the panel inside an activity'
        );
    }

    /**
     * MDL-INT-010: the roles assigned in categories count, and hiding the authenticated user role has effect.
     */
    public function test_category_roles_and_the_authenticated_user_role_are_considered(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $url = new \moodle_url('/course/view.php', ['id' => $course->id]);

        $categorymanager = $this->getDataGenerator()->create_user();
        role_assign($this->role_id('manager'), $categorymanager->id, \context_coursecat::instance($category->id));
        set_config('hidden_roles', implode(',', [$this->role_id('manager'), $this->role_id('user')]), 'local_jedomi');
        $this->setUser($categorymanager);
        $this->assertFalse(
            $this->panel_injected($url, 'course-view-topics', 'course', $course),
            'A user whose only roles (category manager and authenticated user) are hidden must not see the panel'
        );

        set_config('hidden_roles', (string) $this->role_id('user'), 'local_jedomi');
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(
            $this->panel_injected($url, 'course-view-topics', 'course', $course),
            'Hiding the authenticated user role must hide the panel from users without other roles'
        );
    }
}
