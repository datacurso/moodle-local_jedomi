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
 * Profiler tab of the Jedomi settings: indicators of the queries of every channel (agents, previews, web chat,
 * Telegram and companion), and the recent and slowest queries reports.
 *
 * The indicators follow the filters of the recent queries report; the local_jedomi/admin/profiler module refreshes
 * them when those filters change.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profiler_tab implements \core\output\named_templatable, \renderable, \templatable {
    /** @var \stdClass Indicators from query_profiler::get_aggregate(). */
    protected \stdClass $aggregate;
    /** @var string HTML of the recent queries report. */
    protected string $recentreport;
    /** @var string HTML of the slowest queries report. */
    protected string $slowestreport;

    /**
     * Store the indicators and the reports.
     *
     * @param \stdClass $aggregate Indicators from query_profiler::get_aggregate().
     * @param string $recentreport HTML of the recent queries report.
     * @param string $slowestreport HTML of the slowest queries report.
     */
    public function __construct(\stdClass $aggregate, string $recentreport, string $slowestreport) {
        $this->aggregate = $aggregate;
        $this->recentreport = $recentreport;
        $this->slowestreport = $slowestreport;
    }

    /**
     * Indicators as shown in the tab and sent by ajax/profiler_stats.php.
     *
     * @param \stdClass $aggregate Indicators from query_profiler::get_aggregate().
     * @return array total, slow, avg and max.
     */
    public static function export_indicators(\stdClass $aggregate): array {
        return [
            'total' => (int) $aggregate->total_queries,
            'slow' => (int) $aggregate->slow_queries,
            'avg' => get_string('query_profiler_ms', 'local_jedomi', format_float((float) $aggregate->avg_duration_ms, 2)),
            'max' => get_string('query_profiler_ms', 'local_jedomi', format_float((float) $aggregate->max_duration_ms, 2)),
        ];
    }

    /**
     * Export the indicators and the reports for the template.
     *
     * @param \renderer_base $output Renderer used to render the tab.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        return self::export_indicators($this->aggregate) + [
            'recentreport' => $this->recentreport,
            'slowestreport' => $this->slowestreport,
        ];
    }

    /**
     * Template of the tab.
     *
     * @param \renderer_base $renderer Renderer used to render the tab.
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'local_jedomi/admin/profiler_tab';
    }
}
