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
}
