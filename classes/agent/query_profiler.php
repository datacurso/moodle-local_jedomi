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

/**
 * Query profiler — logs and retrieves SQL query execution metrics.
 *
 * Each query of every channel records: channel (agent, preview, web, telegram or companion), agent ID, user,
 * query text, duration, row count and timestamp. The admin UI filters and sorts them to find slow queries.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent;

/**
 * Records SQL tool executions and exposes paginated logs, aggregate
 * statistics, and purge maintenance for the profiler dashboard.
 */
class query_profiler {
    /** @var string[] Channels whose queries are recorded. */
    public const CHANNELS = ['agent', 'preview', 'web', 'telegram', 'companion'];

    /** @var int Default slow query threshold, in milliseconds. */
    public const DEFAULT_SLOW_MS = 1000;

    /** @var int Default number of days the records are kept. */
    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Queries that take at least this long, in milliseconds, count as slow (setting profiler_slow_ms).
     *
     * @return int
     */
    public static function slow_threshold_ms(): int {
        $value = (int) get_config('local_jedomi', 'profiler_slow_ms');
        return $value > 0 ? $value : self::DEFAULT_SLOW_MS;
    }

    /**
     * Days the profiler records are kept before the cleanup task removes them (setting profiler_retention_days).
     *
     * @return int
     */
    public static function retention_days(): int {
        $value = (int) get_config('local_jedomi', 'profiler_retention_days');
        return $value > 0 ? $value : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Log a SQL query execution.
     *
     * @param int    $agentid Agent of the query; 0 for a chat channel or the preview of a new agent.
     * @param string $query Query text.
     * @param float  $durationms Duration in milliseconds.
     * @param int    $rowcount Rows returned.
     * @param string $channel agent, preview, web, telegram or companion.
     * @param int    $userid User the query ran for; 0 when unknown.
     */
    public static function log_query(
        int $agentid,
        string $query,
        float $durationms,
        int $rowcount,
        string $channel = 'agent',
        int $userid = 0
    ): void {
        global $DB;

        $record = (object) [
            'agentid' => $agentid,
            'channel' => in_array($channel, self::CHANNELS, true) ? $channel : 'agent',
            'userid' => $userid,
            'query' => $query,
            'duration_ms' => round($durationms, 2),
            'rowcount' => $rowcount,
            'timecreated' => time(),
        ];

        $DB->insert_record('local_jedomi_query_log', $record);
    }

    /**
     * Record a query without ever failing the request that ran it: the profiler is a diagnostic aid.
     *
     * @param string $channel web, telegram or companion.
     * @param int $userid User the query ran for.
     * @param string $query Query text.
     * @param float $durationms Duration in milliseconds.
     * @param int $rowcount Rows returned.
     * @return void
     */
    public static function log_channel_query(string $channel, int $userid, string $query, float $durationms, int $rowcount): void {
        try {
            self::log_query(0, $query, $durationms, $rowcount, $channel, $userid);
            // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
        } catch (\Throwable $ignored) {
            // A failed record never hides the answer of the query.
        }
    }

    /**
     * WHERE clause and parameters of the filters shared by the list and the indicators.
     *
     * @param array $filters Supported keys: min_duration_ms, channel, agentid, search.
     * @return array{0: string, 1: array} SQL condition (1 = 1 when there is no filter) and its parameters.
     */
    private static function filter_where(array $filters): array {
        global $DB;
        $params = [];
        $wheres = ['1 = 1'];

        if (!empty($filters['min_duration_ms'])) {
            $wheres[] = 'duration_ms >= :mindur';
            $params['mindur'] = (float) $filters['min_duration_ms'];
        }

        if (!empty($filters['channel']) && in_array($filters['channel'], self::CHANNELS, true)) {
            $wheres[] = 'channel = :channel';
            $params['channel'] = $filters['channel'];
        }

        if (!empty($filters['agentid'])) {
            $wheres[] = 'agentid = :agentid';
            $params['agentid'] = (int) $filters['agentid'];
        }

        if (!empty($filters['search'])) {
            $wheres[] = $DB->sql_like('query', ':search', false, false);
            $params['search'] = '%' . $DB->sql_like_escape($filters['search']) . '%';
        }

        return [implode(' AND ', $wheres), $params];
    }

    /**
     * Get paginated query log entries.
     *
     * @param  array $filters Supported keys: min_duration_ms, channel, agentid, search, sort (field), dir (ASC|DESC),
     *                        page (from 1), perpage.
     * @return array ['entries' => \stdClass[], 'total' => int]
     */
    public static function get_log(array $filters = []): array {
        global $DB;

        [$where, $params] = self::filter_where($filters);

        // Pagination.
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perpage = max(1, min(200, (int) ($filters['perpage'] ?? 50)));
        $offset = ($page - 1) * $perpage;

        // Sort.
        $sort = in_array($filters['sort'] ?? '', ['duration_ms', 'timecreated', 'rowcount', 'query'])
            ? $filters['sort'] : 'duration_ms';
        $dir = strtoupper($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $total = $DB->count_records_select('local_jedomi_query_log', $where, $params);

        $entries = $DB->get_records_sql(
            "SELECT * FROM {local_jedomi_query_log} WHERE {$where} ORDER BY {$sort} {$dir}, id {$dir}",
            $params,
            $offset,
            $perpage
        );

        return [
            'entries' => array_values($entries),
            'total' => (int) $total,
        ];
    }

    /**
     * Get aggregate stats for the profiler dashboard, over the queries that match the filters.
     *
     * @param  int|null $mindurationms Minimum duration to consider "slow"; null for the slow threshold setting.
     * @param  array $filters Filters of the list (min_duration_ms, channel, agentid, search), so the indicators match it.
     * @return \stdClass
     */
    public static function get_aggregate(?int $mindurationms = null, array $filters = []): \stdClass {
        global $DB;

        $mindurationms = $mindurationms ?? self::slow_threshold_ms();
        [$where, $params] = self::filter_where($filters);

        $stats = $DB->get_record_sql(
            "SELECT COUNT(1) AS total, AVG(duration_ms) AS avgduration, MAX(duration_ms) AS maxduration
               FROM {local_jedomi_query_log}
              WHERE {$where}",
            $params
        );
        $slow = $DB->count_records_select(
            'local_jedomi_query_log',
            "{$where} AND duration_ms >= :slowmin",
            $params + ['slowmin' => $mindurationms]
        );

        // Top 10 slowest queries (aggregated by query text).
        $slowest = $DB->get_records_sql(
            "SELECT query, COUNT(1) AS exec_count,
                    ROUND(AVG(duration_ms), 2) AS avg_duration,
                    ROUND(MAX(duration_ms), 2) AS max_duration
               FROM {local_jedomi_query_log}
              WHERE {$where}
              GROUP BY query
              ORDER BY avg_duration DESC",
            $params,
            0,
            10
        );

        return (object) [
            'total_queries' => (int) ($stats->total ?? 0),
            'slow_queries' => (int) $slow,
            'avg_duration_ms' => round((float) ($stats->avgduration ?? 0), 2),
            'max_duration_ms' => round((float) ($stats->maxduration ?? 0), 2),
            'slowest' => array_values($slowest ?: []),
        ];
    }

    /**
     * Purge old log entries (older than given days).
     *
     * @param  int|null $days Days to keep; null for the retention setting.
     * @return int Number of deleted rows.
     */
    public static function purge(?int $days = null): int {
        global $DB;
        $days = $days ?? self::retention_days();
        $cutoff = time() - ($days * DAYSECS);
        $select = 'timecreated < :cutoff';
        $count = $DB->count_records_select('local_jedomi_query_log', $select, ['cutoff' => $cutoff]);
        $DB->delete_records_select('local_jedomi_query_log', $select, ['cutoff' => $cutoff]);
        return $count;
    }
}
