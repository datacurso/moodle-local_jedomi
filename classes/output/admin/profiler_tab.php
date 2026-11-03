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
 * Profiler tab of the Jedomi settings: statistics of the queries run by agents.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profiler_tab implements \renderable, \templatable, \core\output\named_templatable {
    /** @var int Queries that take longer than this, in milliseconds, are highlighted. */
    private const SLOW_MS = 1000;

    /** @var \stdClass Aggregate statistics from query_profiler::get_aggregate(). */
    protected \stdClass $aggregate;
    /** @var array Recent queries from admin_settings_service::get_recent_profiler_entries(). */
    protected array $recent;
    /** @var int Current minimum duration filter, in milliseconds; 0 for none. */
    protected int $minms;
    /** @var string Current sort: "duration_ms" or "timecreated". */
    protected string $sort;

    /**
     * Store the statistics and the current filters.
     *
     * @param \stdClass $aggregate Aggregate statistics from query_profiler::get_aggregate().
     * @param array $recent Recent queries from admin_settings_service::get_recent_profiler_entries().
     * @param int $minms Current minimum duration filter, in milliseconds; 0 for none.
     * @param string $sort Current sort: "duration_ms" or "timecreated".
     */
    public function __construct(\stdClass $aggregate, array $recent, int $minms, string $sort) {
        $this->aggregate = $aggregate;
        $this->recent = $recent;
        $this->minms = $minms;
        $this->sort = $sort;
    }

    /**
     * Export the statistics, the slowest queries and the recent queries for the template.
     *
     * @param \renderer_base $output Renderer used to render the tab.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $slowest = [];
        foreach ($this->aggregate->slowest as $query) {
            $slowest[] = [
                'query' => mb_substr((string) $query->query, 0, 120),
                'avg' => round((float) $query->avg_duration, 2),
                'max' => round((float) $query->max_duration, 2),
                'count' => (int) $query->exec_count,
            ];
        }
        $recent = [];
        foreach ($this->recent as $entry) {
            $url = new \moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'agents', 'edit_agent' => $entry['agentid']]);
            $recent[] = [
                'agentid' => $entry['agentid'],
                'agenturl' => $url->out(false),
                'userfullname' => $entry['userfullname'],
                'query' => mb_substr($entry['query'], 0, 100),
                'duration' => round($entry['duration_ms'], 2),
                'slow' => $entry['duration_ms'] > self::SLOW_MS,
                'rowcount' => $entry['rowcount'],
                'timecreated' => $entry['timecreated'],
            ];
        }
        return [
            'total' => (int) $this->aggregate->total_queries,
            'slow' => (int) $this->aggregate->slow_queries,
            'avg' => $this->aggregate->avg_duration_ms,
            'max' => $this->aggregate->max_duration_ms,
            'actionurl' => (new \moodle_url('/local/jedomi/admin_settings.php'))->out(false),
            'minms' => $this->minms ?: '',
            'sortduration' => $this->sort === 'duration_ms',
            'sorttime' => $this->sort === 'timecreated',
            'hasslowest' => !empty($slowest),
            'slowest' => $slowest,
            'hasrecent' => !empty($recent),
            'recent' => $recent,
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
