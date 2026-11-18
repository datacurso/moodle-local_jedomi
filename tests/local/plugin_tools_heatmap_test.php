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

namespace local_jedomi\local;

/**
 * The tools of local_datacurso_heatmap, the first plugin that registers tools in Jedomi, with the real hook manager.
 *
 * Skipped when Heatmap or its Jedomi tools are not installed.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\plugin_tools
 */
final class plugin_tools_heatmap_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\local_datacurso_heatmap\local\ai\jedomi_hook::class)) {
            $this->markTestSkipped('local_datacurso_heatmap with its Jedomi tools is not installed.');
        }
        $this->resetAfterTest();
    }

    /**
     * Names of the Heatmap tools offered to a user in a context.
     *
     * @param int $userid User.
     * @param \context $context Context of the chat.
     * @return string[]
     */
    private function heatmap_tools(int $userid, \context $context): array {
        $names = array_keys(plugin_tools::available($userid, $context, 'web'));
        return array_values(array_filter($names, static fn(string $name): bool => str_starts_with($name, 'heatmap_')));
    }

    /**
     * A teacher gets the course tools of Heatmap in their course once the administrator allows the AI analysis; a
     * student never does.
     */
    public function test_heatmap_course_tools_follow_the_capability_and_the_setting(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $context = \context_course::instance($course->id);

        set_config('aicourseenabled', 0, 'local_datacurso_heatmap');
        $this->assertSame([], $this->heatmap_tools((int) $teacher->id, $context));

        set_config('aicourseenabled', 1, 'local_datacurso_heatmap');
        $tools = $this->heatmap_tools((int) $teacher->id, $context);
        $this->assertContains('heatmap_get_course_map', $tools);
        $this->assertContains('heatmap_get_findings', $tools);
        $this->assertNotContains('heatmap_get_institutional_summary', $tools);
        $this->assertSame([], $this->heatmap_tools((int) $student->id, $context));
    }

    /**
     * The site administrator gets the institutional tool outside a course.
     */
    public function test_admin_gets_the_institutional_tool_on_the_site(): void {
        $tools = $this->heatmap_tools((int) get_admin()->id, \context_system::instance());

        $this->assertContains('heatmap_get_institutional_summary', $tools);
    }

    /**
     * Every Heatmap tool declares that it only reads, so Jedomi runs it without asking for confirmation.
     */
    public function test_heatmap_tools_only_read(): void {
        $course = $this->getDataGenerator()->create_course();
        $admin = (int) get_admin()->id;
        $tools = plugin_tools::available($admin, \context_course::instance($course->id), 'web')
            + plugin_tools::available($admin, \context_system::instance(), 'web');
        $heatmap = array_filter($tools, static fn(string $name): bool => str_starts_with($name, 'heatmap_'), ARRAY_FILTER_USE_KEY);

        $this->assertCount(7, $heatmap);
        foreach ($heatmap as $name => $tool) {
            $this->assertTrue(plugin_tools::is_read_only($tool), "$name must only read");
        }
    }

    /**
     * The course map of Heatmap, asked while its views are still being prepared, answers in the pending contract of
     * Jedomi (meta.pending true), so Jedomi runs it again before the AI reads it.
     */
    public function test_heatmap_answer_being_prepared_follows_the_pending_contract(): void {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        set_config('aicourseenabled', 1, 'local_datacurso_heatmap');
        $this->setUser($teacher);
        $tools = plugin_tools::available((int) $teacher->id, \context_course::instance($course->id), 'web');
        // An export request only serves views already built: a missing one is reported as being prepared.
        \local_datacurso_heatmap\local\snapshot::set_serveonly(true);

        try {
            $answer = plugin_tools::execute($tools['heatmap_get_course_map'], ['courseid' => (int) $course->id]);
        } finally {
            \local_datacurso_heatmap\local\snapshot::reset_request_state();
        }

        $this->assertTrue(json_decode($answer, true)['meta']['pending']);
        $this->assertTrue(plugin_tool_retry::is_pending($answer));
    }
}
