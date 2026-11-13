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

namespace local_jedomi\output\admin;

/**
 * Tests for the Profiler tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\output\admin\profiler_tab
 */
final class profiler_tab_test extends \advanced_testcase {
    /**
     * Build a tab with one slow query and one recent entry.
     *
     * @return profiler_tab
     */
    private function tab(): profiler_tab {
        $aggregate = (object) [
            'total_queries' => 12, 'slow_queries' => 2, 'avg_duration_ms' => 3.5, 'max_duration_ms' => 1500,
            'slowest' => [(object) ['query' => 'SELECT <b>1</b>', 'avg_duration' => 12.345, 'max_duration' => 20.1,
                'exec_count' => 4]],
        ];
        $recent = [['agentid' => 9, 'userfullname' => 'Ada Lovelace', 'query' => 'SELECT 2', 'duration_ms' => 1500.456,
            'rowcount' => 3, 'timecreated' => 1700000000]];
        return new profiler_tab($aggregate, $recent, 100, 'timecreated');
    }

    /**
     * MDL-UNIT-100: durations are rounded, slow queries are highlighted and the chosen order is marked.
     * The tab exports the totals, the slowest queries and the recent entries with a link to the agent.
     *
     * @return void
     */
    public function test_export_for_template(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));

        $data = $this->tab()->export_for_template($PAGE->get_renderer('local_jedomi'));

        $this->assertSame(12, $data['total']);
        $this->assertSame(12.35, $data['slowest'][0]['avg']);
        $this->assertTrue($data['recent'][0]['slow']);
        $this->assertSame(1500.46, $data['recent'][0]['duration']);
        $this->assertStringContainsString('edit_agent=9', $data['recent'][0]['agenturl']);
        $this->assertTrue($data['sorttime']);
        $this->assertSame(100, $data['minms']);
    }

    /**
     * MDL-UNIT-100: the rendered lists show the queries escaped and the creator of the agent.
     * The rendered tab escapes the queries.
     *
     * @return void
     */
    public function test_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));

        $html = $PAGE->get_renderer('local_jedomi')->render($this->tab());

        $this->assertStringContainsString('SELECT &lt;b&gt;1&lt;/b&gt;', $html);
        $this->assertStringContainsString('Ada Lovelace', $html);
    }

    /**
     * Export a tab built from the given slowest and recent queries.
     *
     * @param array $slowest Slowest queries.
     * @param array $recent Recent queries.
     * @param int $minms Minimum duration filter.
     * @param string $sort Chosen order.
     * @return array
     */
    private function export(array $slowest, array $recent, int $minms = 0, string $sort = 'timecreated'): array {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $aggregate = (object) ['total_queries' => count($recent), 'slow_queries' => 0, 'avg_duration_ms' => 0,
            'max_duration_ms' => 0, 'slowest' => $slowest];
        return (new profiler_tab($aggregate, $recent, $minms, $sort))->export_for_template($PAGE->get_renderer('local_jedomi'));
    }

    /**
     * A recent query entry.
     *
     * @param float $duration Duration in milliseconds.
     * @param string $query Query.
     * @param string $creator Creator of the agent.
     * @return array
     */
    private function entry(float $duration, string $query = 'SELECT 1', string $creator = 'Ada Lovelace'): array {
        return ['agentid' => 3, 'userfullname' => $creator, 'query' => $query, 'duration_ms' => $duration, 'rowcount' => 1,
            'timecreated' => 1700000000];
    }

    /**
     * MDL-UNIT-100: long queries are shortened in the slowest and recent lists, and short ones are shown complete.
     *
     * @return void
     */
    public function test_long_queries_are_shortened_in_both_lists(): void {
        $this->resetAfterTest();
        $long = 'SELECT ' . str_repeat('x', 500);

        $data = $this->export(
            [(object) ['query' => $long, 'avg_duration' => 1, 'max_duration' => 1, 'exec_count' => 1],
                (object) ['query' => 'SELECT 1', 'avg_duration' => 1, 'max_duration' => 1, 'exec_count' => 1]],
            [$this->entry(5, $long), $this->entry(5, 'SELECT 2')]
        );

        $this->assertLessThan(mb_strlen($long), mb_strlen($data['slowest'][0]['query']));
        $this->assertStringStartsWith('SELECT xxx', $data['slowest'][0]['query']);
        $this->assertSame('SELECT 1', $data['slowest'][1]['query']);
        $this->assertLessThan(mb_strlen($long), mb_strlen($data['recent'][0]['query']));
        $this->assertSame('SELECT 2', $data['recent'][1]['query']);
    }

    /**
     * MDL-UNIT-100: the duration is highlighted at or above the slow query threshold, never below it.
     *
     * Note: a query exactly at the threshold is highlighted on purpose, so the highlight matches the slow query counter
     * of the tab, which counts the queries at or above the threshold.
     *
     * @return void
     */
    public function test_duration_is_highlighted_at_or_above_the_threshold(): void {
        $this->resetAfterTest();

        $data = $this->export([], [$this->entry(999.99), $this->entry(1000), $this->entry(1000.01), $this->entry(25000)]);

        $this->assertSame([false, true, true, true], array_column($data['recent'], 'slow'));
    }

    /**
     * MDL-UNIT-100: durations are rounded and a query of an agent that no longer exists is shown without creator.
     *
     * @return void
     */
    public function test_durations_are_rounded_and_missing_creator_is_empty(): void {
        global $PAGE;
        $this->resetAfterTest();

        $data = $this->export([(object) ['query' => 'q', 'avg_duration' => 3.14159, 'max_duration' => 9.999,
            'exec_count' => 2]], [$this->entry(12.3456, 'SELECT 9', '')]);
        $html = $PAGE->get_renderer('local_jedomi')->render_from_template('local_jedomi/admin/profiler_tab', $data);

        $this->assertSame(3.14, $data['slowest'][0]['avg']);
        $this->assertSame(10.0, $data['slowest'][0]['max']);
        $this->assertSame(12.35, $data['recent'][0]['duration']);
        $this->assertSame('', $data['recent'][0]['userfullname']);
        $this->assertStringContainsString('SELECT 9', $html);
    }

    /**
     * MDL-UNIT-100: an empty or zero minimum duration is presented as no filter, and the chosen order is marked.
     *
     * @return void
     */
    public function test_filter_and_order_are_presented(): void {
        $this->resetAfterTest();

        $nofilter = $this->export([], [], 0, 'duration_ms');
        $filtered = $this->export([], [], 250, 'timecreated');

        $this->assertSame('', $nofilter['minms']);
        $this->assertTrue($nofilter['sortduration']);
        $this->assertFalse($nofilter['sorttime']);
        $this->assertSame(250, $filtered['minms']);
        $this->assertTrue($filtered['sorttime']);
        $this->assertFalse($filtered['sortduration']);
    }
}
