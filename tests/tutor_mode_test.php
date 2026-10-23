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

use local_jedomi\hook\activity_context;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Precedence of the tutor mode and the context modules add through the activity_context hook.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(activity_context::class)]
#[CoversFunction('local_jedomi_resolve_mode')]
#[CoversFunction('local_jedomi_activity_hook')]
#[CoversFunction('local_jedomi_activity_context')]
final class tutor_mode_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Activity. */
    private \stdClass $activity;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        set_config('stem_enabled', 1, 'local_jedomi');
        set_config('stem_tutor_mode', 'socratic', 'local_jedomi');
        $this->course = $this->getDataGenerator()->create_course();
        $this->activity = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id,
            'intro' => 'A page about bikes.']);
    }

    #[\Override]
    protected function tearDown(): void {
        \core\di::get(\core\hook\manager::class)->phpunit_stop_redirections();
        parent::tearDown();
    }

    /**
     * The activity's module answers the hook with a mode and context.
     *
     * @param string|null $mode Mode the module sets, or null for none.
     * @param string $context Context line the module adds.
     */
    private function module_answers(?string $mode, string $context = ''): void {
        \core\di::get(\core\hook\manager::class)->phpunit_redirect_hook(
            activity_context::class,
            function (activity_context $hook) use ($mode, $context): void {
                if ($mode !== null) {
                    $hook->set_mode($mode);
                }
                $hook->add_context($context);
            }
        );
    }

    /**
     * A row of local_jedomi_stem_cfg.
     *
     * @param int $cmid Activity, or 0 for the course row.
     * @param int $enabled Whether the tutor is on.
     * @param string $mode Tutor mode.
     */
    private function cfg(int $cmid, int $enabled, string $mode): void {
        global $DB;
        $DB->insert_record('local_jedomi_stem_cfg', (object) ['courseid' => $this->course->id, 'cmid' => $cmid,
            'enabled' => $enabled, 'tutor_mode' => $mode, 'timemodified' => time()]);
    }

    /**
     * Resolved mode of a student in the activity.
     *
     * @return string
     */
    private function mode(): string {
        return local_jedomi_resolve_mode((int) $this->course->id, (int) $this->activity->cmid, 'student');
    }

    /**
     * The site switch applies first, and only students get a tutor.
     */
    public function test_site_switch_and_role(): void {
        $this->module_answers('guided');
        $this->assertSame('guided', $this->mode());
        $this->assertSame('assistant', local_jedomi_resolve_mode(
            (int) $this->course->id,
            (int) $this->activity->cmid,
            'teacher'
        ));
        set_config('stem_enabled', 0, 'local_jedomi');
        $this->assertSame('assistant', $this->mode());
    }

    /**
     * A row of the activity wins over the module's mode.
     */
    public function test_activity_row_wins(): void {
        $this->module_answers('socratic');
        $this->cfg((int) $this->activity->cmid, 1, 'guided');
        $this->assertSame('guided', $this->mode());
    }

    /**
     * A disabled row of the activity turns the tutor off even when the module sets a mode.
     */
    public function test_disabled_activity_row(): void {
        $this->module_answers('guided');
        $this->cfg((int) $this->activity->cmid, 0, 'guided');
        $this->assertSame('assistant', $this->mode());
    }

    /**
     * The module's "inherit" means the site default, and the course row is not consulted.
     */
    public function test_module_inherit_uses_site_default(): void {
        $this->module_answers('inherit');
        $this->cfg(0, 0, 'guided');
        $this->assertSame('socratic', $this->mode());
    }

    /**
     * A module that sets no mode leaves the decision to the course row, then to nothing.
     */
    public function test_without_module_mode_the_course_row_decides(): void {
        $this->module_answers(null);
        $this->assertSame('assistant', $this->mode());
        $this->cfg(0, 1, 'guided');
        $this->assertSame('guided', $this->mode());
    }

    /**
     * The activity context carries the description and then what the module adds; unknown modes are refused.
     */
    public function test_context_and_mode_validation(): void {
        $this->module_answers(null, 'Parts: wheel, saddle.');
        $this->assertSame(
            get_string('prompt_tutor_activity_desc', 'local_jedomi') . " A page about bikes.\nParts: wheel, saddle.",
            local_jedomi_activity_context((int) $this->course->id, (int) $this->activity->cmid)
        );
        $this->assertNull(local_jedomi_activity_hook((int) $this->course->id, 0));

        $hook = new activity_context(get_fast_modinfo($this->course)->get_cm($this->activity->cmid), 2);
        $this->expectException(\coding_exception::class);
        $hook->set_mode('assistant');
    }
}
