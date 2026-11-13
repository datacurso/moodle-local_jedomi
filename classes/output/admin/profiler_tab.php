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
 * Profiler tab of the Jedomi settings: statistics of the queries of every channel (agents, previews, web chat,
 * Telegram and companion), filtered and paginated.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profiler_tab implements \core\output\named_templatable, \renderable, \templatable {
    /** @var \stdClass Aggregate statistics from query_profiler::get_aggregate(). */
    protected \stdClass $aggregate;
    /** @var array Recent queries from admin_settings_service::get_recent_profiler_entries(). */
    protected array $recent;
    /** @var int Current minimum duration filter, in milliseconds; 0 for none. */
    protected int $minms;
    /** @var string Current sort: "duration_ms" or "timecreated". */
    protected string $sort;
    /** @var string Current channel filter; empty for every channel. */
    protected string $channel;
    /** @var int Number of queries that match the filters. */
    protected int $total;
    /** @var int Current page, from 1. */
    protected int $page;
    /** @var int Queries per page. */
    protected int $perpage;

    /**
     * Store the statistics and the current filters.
     *
     * @param \stdClass $aggregate Aggregate statistics from query_profiler::get_aggregate().
     * @param array $recent Recent queries from admin_settings_service::get_recent_profiler_entries().
     * @param int $minms Current minimum duration filter, in milliseconds; 0 for none.
     * @param string $sort Current sort: "duration_ms" or "timecreated".
     * @param string $channel Current channel filter; empty for every channel.
     * @param int $total Number of queries that match the filters; 0 when the list is not paginated.
     * @param int $page Current page, from 1.
     * @param int $perpage Queries per page.
     */
    public function __construct(
        \stdClass $aggregate,
        array $recent,
        int $minms,
        string $sort,
        string $channel = '',
        int $total = 0,
        int $page = 1,
        int $perpage = 20
    ) {
        $this->aggregate = $aggregate;
        $this->recent = $recent;
        $this->minms = $minms;
        $this->sort = $sort;
        $this->channel = $channel;
        $this->total = $total;
        $this->page = max(1, $page);
        $this->perpage = max(1, $perpage);
    }

    /**
     * Readable origin of a query and the link to its agent, if any.
     *
     * @param array $entry Entry of admin_settings_service::get_profiler_page().
     * @return array{0: string, 1: string} Label and URL (empty when there is no agent to open).
     */
    public static function origin(array $entry): array {
        $agentid = (int) $entry['agentid'];
        $channel = (string) ($entry['channel'] ?? 'agent');
        $url = $agentid > 0
            ? (new \moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'agents', 'edit_agent' => $agentid]))->out(false)
            : '';
        switch ($channel) {
            case 'preview':
                return $agentid > 0
                    ? [get_string('query_profiler_origin_preview_agent', 'local_jedomi', $agentid), $url]
                    : [get_string('query_profiler_origin_preview', 'local_jedomi'), ''];
            case 'web':
            case 'telegram':
            case 'companion':
                return [get_string('query_profiler_origin_' . $channel, 'local_jedomi'), ''];
            default:
                return [get_string('agent_number', 'local_jedomi', $agentid), $url];
        }
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
        // The list highlights the same queries that the slow indicator counts (at least the threshold).
        $slowms = \local_jedomi\agent\query_profiler::slow_threshold_ms();
        foreach ($this->recent as $entry) {
            [$origin, $url] = self::origin($entry);
            $recent[] = [
                'agentid' => $entry['agentid'],
                'origin' => $origin,
                'agenturl' => $url,
                'userfullname' => $entry['userfullname'],
                'query' => mb_substr($entry['query'], 0, 100),
                'duration' => round($entry['duration_ms'], 2),
                'slow' => $entry['duration_ms'] >= $slowms,
                'rowcount' => $entry['rowcount'],
                'timecreated' => $entry['timecreated'],
            ];
        }
        $channels = [['value' => '', 'label' => get_string('query_profiler_channel_all', 'local_jedomi'),
            'selected' => $this->channel === '']];
        foreach (\local_jedomi\agent\query_profiler::CHANNELS as $channel) {
            $label = $channel === 'agent' ? get_string('query_profiler_origin_agent', 'local_jedomi')
                : get_string('query_profiler_origin_' . $channel, 'local_jedomi');
            $channels[] = ['value' => $channel, 'label' => $label, 'selected' => $this->channel === $channel];
        }
        $pagingbar = '';
        if ($this->total > $this->perpage) {
            $baseurl = new \moodle_url('/local/jedomi/admin_settings.php', array_filter([
                'tab' => 'profiler',
                'qp_min_ms' => $this->minms ?: null,
                'qp_sort' => $this->sort,
                'qp_channel' => $this->channel ?: null,
            ]));
            // The paging bar and its qp_page parameter count pages from 0; the tab counts them from 1.
            $bar = new \paging_bar($this->total, $this->page - 1, $this->perpage, $baseurl, 'qp_page');
            $pagingbar = $output->render($bar);
        }
        return [
            'channels' => $channels,
            'pagingbar' => $pagingbar,
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
