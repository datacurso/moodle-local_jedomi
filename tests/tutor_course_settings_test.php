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

use local_jedomi\form\tutor_settings_form;
use local_jedomi\tutor\course_settings;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Course tutor settings, and the retirement of the AR viewer inside assignments.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_settings::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(tutor_settings_form::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_jedomi_activity_context')]
final class tutor_course_settings_test extends \advanced_testcase {
    /**
     * Seeding stores the missing site defaults and never overwrites a saved value.
     */
    public function test_seed_site_defaults(): void {
        $this->resetAfterTest(true);
        foreach (array_keys(course_settings::site_defaults()) as $name) {
            unset_config($name, 'local_jedomi');
        }
        set_config('stem_max_questions', 7, 'local_jedomi');

        course_settings::seed_site_defaults();

        $this->assertSame('socratic', get_config('local_jedomi', 'stem_tutor_mode'));
        $this->assertSame('0', get_config('local_jedomi', 'stem_enabled'));
        $this->assertSame('7', get_config('local_jedomi', 'stem_max_questions'));
        $this->assertSame(get_string('stem_tutor_prompt_default', 'local_jedomi'), get_config('local_jedomi', 'stem_tutor_prompt'));
    }

    /**
     * A course without saved settings gets the defaults.
     */
    public function test_load_defaults(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $this->assertSame(
            ['enabled' => 0, 'tutor_mode' => 'inherit', 'max_questions' => 0, 'tutor_prompt' => ''],
            course_settings::load((int) $course->id)
        );
    }

    /**
     * Saving creates the course row and the values persist in the database.
     */
    public function test_save_creates_course_row(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $id = course_settings::save((int) $course->id, (object) [
            'enabled' => 1, 'tutor_mode' => 'guided', 'max_questions' => 4, 'tutor_prompt' => 'Teach <b>Ohm</b> law.',
        ], (int) $user->id);

        $row = $DB->get_record('local_jedomi_stem_cfg', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->cmid);
        $this->assertSame(1, (int) $row->enabled);
        $this->assertSame('guided', $row->tutor_mode);
        $this->assertSame(4, (int) $row->max_questions);
        $this->assertSame('Teach Ohm law.', $row->tutor_prompt);
        $this->assertSame((int) $user->id, (int) $row->usermodified);
    }

    /**
     * Saving again updates the same row and leaves activity rows and other columns untouched.
     */
    public function test_save_updates_only_course_row(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $now = time();
        $courserowid = $DB->insert_record('local_jedomi_stem_cfg', (object) [
            'courseid' => $course->id, 'cmid' => 0, 'enabled' => 0, 'tutor_mode' => 'inherit', 'max_questions' => 0,
            'tutor_prompt' => '', 'usermodified' => 0, 'timemodified' => $now,
        ]);
        $activityrowid = $DB->insert_record('local_jedomi_stem_cfg', (object) [
            'courseid' => $course->id, 'cmid' => 99, 'enabled' => 1, 'tutor_mode' => 'socratic', 'max_questions' => 2,
            'tutor_prompt' => 'activity', 'usermodified' => 0, 'timemodified' => $now,
        ]);

        $id = course_settings::save((int) $course->id, (object) [
            'enabled' => 1, 'tutor_mode' => 'socratic', 'max_questions' => 5, 'tutor_prompt' => 'Course',
        ], 2);

        $this->assertSame((int) $courserowid, $id);
        $this->assertSame(2, $DB->count_records('local_jedomi_stem_cfg', ['courseid' => $course->id]));
        $courserow = $DB->get_record('local_jedomi_stem_cfg', ['id' => $courserowid], '*', MUST_EXIST);
        $this->assertSame('socratic', $courserow->tutor_mode);
        $this->assertSame(2, (int) $courserow->usermodified);
        $activityrow = $DB->get_record('local_jedomi_stem_cfg', ['id' => $activityrowid], '*', MUST_EXIST);
        $this->assertSame('activity', $activityrow->tutor_prompt);
        $this->assertSame(2, (int) $activityrow->max_questions);
    }

    /**
     * Out-of-range and invalid values are normalised before storage.
     */
    public function test_save_normalises_values(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();

        $id = course_settings::save((int) $course->id, (object) [
            'enabled' => 0, 'tutor_mode' => 'evil', 'max_questions' => 99,
            'tutor_prompt' => str_repeat('a', course_settings::PROMPT_MAX + 50),
        ], 2);

        $row = $DB->get_record('local_jedomi_stem_cfg', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('inherit', $row->tutor_mode);
        $this->assertSame(course_settings::MAXQ_MAX, (int) $row->max_questions);
        $this->assertSame(course_settings::PROMPT_MAX, \core_text::strlen($row->tutor_prompt));
        $this->assertSame(['enabled' => 0, 'tutor_mode' => 'inherit', 'max_questions' => 10,
            'tutor_prompt' => str_repeat('a', course_settings::PROMPT_MAX)], course_settings::load((int) $course->id));
    }

    /**
     * The form rejects a number of questions out of range and an unknown mode.
     */
    public function test_form_validation(): void {
        $this->resetAfterTest(true);
        $form = new tutor_settings_form(null, ['template' => ['mode' => 'socratic', 'maxq' => 3]]);
        $errors = $form->validation(['tutor_mode' => 'evil', 'max_questions' => 11], []);
        $this->assertArrayHasKey('max_questions', $errors);
        $this->assertArrayHasKey('tutor_mode', $errors);
        $this->assertSame([], $form->validation(['tutor_mode' => 'guided', 'max_questions' => 10], []));
    }

    /**
     * The retired file areas (stem_model, stem_evidence) are gone: the plugin serves no files any more.
     */
    public function test_no_pluginfile_callback(): void {
        $this->resetAfterTest(true);
        $this->assertFalse(function_exists('local_jedomi_pluginfile'));
    }

    /**
     * An assignment gets its description in the tutor context and nothing of the 3D activities.
     */
    public function test_activity_context_ignores_assignments(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'intro' => 'Build a circuit.']);
        $context = local_jedomi_activity_context((int) $course->id, (int) $assign->cmid);
        $this->assertSame(get_string('prompt_tutor_activity_desc', 'local_jedomi') . ' Build a circuit.', $context);
    }
}
