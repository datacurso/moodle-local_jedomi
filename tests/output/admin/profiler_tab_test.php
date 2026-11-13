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
     * Build a tab with its indicators and the HTML of its two reports.
     *
     * @return profiler_tab
     */
    private function tab(): profiler_tab {
        $aggregate = (object) ['total_queries' => 12, 'slow_queries' => 2, 'avg_duration_ms' => 3.5, 'max_duration_ms' => 1500];
        return new profiler_tab($aggregate, '<div id="recent-report"></div>', '<div id="slowest-report"></div>');
    }

    /**
     * MDL-UNIT-100: the tab exports the indicators and the two reports.
     *
     * @return void
     */
    public function test_export_for_template(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));

        $data = $this->tab()->export_for_template($PAGE->get_renderer('local_jedomi'));

        $this->assertSame(12, $data['total']);
        $this->assertSame(2, $data['slow']);
        $this->assertSame(get_string('query_profiler_ms', 'local_jedomi', '3.50'), $data['avg']);
        $this->assertSame(get_string('query_profiler_ms', 'local_jedomi', '1500.00'), $data['max']);
        $this->assertSame('<div id="recent-report"></div>', $data['recentreport']);
        $this->assertSame('<div id="slowest-report"></div>', $data['slowestreport']);
    }

    /**
     * MDL-UNIT-100: the rendered tab shows the indicators for the profiler module and both reports, without the
     * hand-written filter form and paging bar.
     *
     * @return void
     */
    public function test_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));

        $html = $PAGE->get_renderer('local_jedomi')->render($this->tab());

        foreach (['total', 'slow', 'avg', 'max'] as $indicator) {
            $this->assertStringContainsString('data-indicator="' . $indicator . '"', $html);
        }
        $this->assertStringContainsString('data-region="local_jedomi/admin/profiler/recent"', $html);
        $this->assertStringContainsString('<div id="recent-report"></div>', $html);
        $this->assertStringContainsString('<div id="slowest-report"></div>', $html);
        $this->assertStringNotContainsString('qp_min_ms', $html);
        $this->assertStringNotContainsString('qp_sort', $html);
    }
}
