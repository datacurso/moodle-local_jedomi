<?php
/**
 * Query profiler — logs and retrieves SQL query execution metrics.
 *
 * Each SQL tool execution records: agent ID, query text, duration, row count,
 * and timestamp. The admin UI can then filter/sort by duration to find slow
 * queries.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent;

defined('MOODLE_INTERNAL') || die();

class query_profiler {

    /**
     * Log a SQL query execution.
     *
     * @param int    $agentid
     * @param string $query
     * @param float  $durationMs
     * @param int    $rowCount
     */
    public static function log_query(int $agentid, string $query, float $durationMs, int $rowCount): void {
        global $DB;

        $record = (object) [
            'agentid' => $agentid,
            'query' => $query,
            'duration_ms' => round($durationMs, 2),
            'rowcount' => $rowCount,
            'timecreated' => time(),
        ];

        $DB->insert_record('local_jedomi_query_log', $record);
    }

    /**
     * Get paginated query log entries.
     *
     * @param  array $filters Supported keys: min_duration_ms, max_duration_ms,
     *                        agentid, sort (field), dir (ASC|DESC), page, perpage.
     * @return array ['entries' => \stdClass[], 'total' => int]
     */
    public static function get_log(array $filters = []): array {
        global $DB;

        $params = [];
        $wheres = [];

        if (!empty($filters['min_duration_ms'])) {
            $wheres[] = 'duration_ms >= :mindur';
            $params['mindur'] = (float) $filters['min_duration_ms'];
        }

        if (!empty($filters['agentid'])) {
            $wheres[] = 'agentid = :agentid';
            $params['agentid'] = (int) $filters['agentid'];
        }

        if (!empty($filters['search'])) {
            $like = $DB->sql_like('query', ':search', false, false);
            $wheres[] = $like;
            $params['search'] = '%' . $DB->sql_like_escape($filters['search']) . '%';
        }

        $wheresql = '';
        if (!empty($wheres)) {
            $wheresql = 'WHERE ' . implode(' AND ', $wheres);
        }

        // Pagination
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perpage = max(1, min(200, (int) ($filters['perpage'] ?? 50)));
        $offset = ($page - 1) * $perpage;

        // Sort
        $sort = in_array($filters['sort'] ?? '', ['duration_ms', 'timecreated', 'rowcount', 'query'])
            ? $filters['sort'] : 'duration_ms';
        $dir = strtoupper($filters['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $total = $DB->count_records_sql(
            "SELECT COUNT(1) FROM {local_jedomi_query_log} {$wheresql}", $params
        );

        $entries = $DB->get_records_sql(
            "SELECT * FROM {local_jedomi_query_log} {$wheresql} ORDER BY {$sort} {$dir}",
            $params, $offset, $perpage
        );

        return [
            'entries' => array_values($entries),
            'total' => (int) $total,
        ];
    }

    /**
     * Get aggregate stats for the profiler dashboard.
     *
     * @param  int $minDurationMs Minimum duration to consider "slow" (default: 1000ms).
     * @return \stdClass
     */
    public static function get_aggregate(int $minDurationMs = 1000): \stdClass {
        global $DB;

        $total = $DB->count_records('local_jedomi_query_log');
        $slow = $DB->count_records_select('local_jedomi_query_log', 'duration_ms >= :min', ['min' => $minDurationMs]);
        $avgDuration = $DB->get_field_sql("SELECT AVG(duration_ms) FROM {local_jedomi_query_log}") ?: 0;
        $maxDuration = $DB->get_field_sql("SELECT MAX(duration_ms) FROM {local_jedomi_query_log}") ?: 0;

        // Top 10 slowest queries (aggregated by query text).
        $slowest = $DB->get_records_sql(
            "SELECT query, COUNT(1) AS exec_count,
                    ROUND(AVG(duration_ms), 2) AS avg_duration,
                    ROUND(MAX(duration_ms), 2) AS max_duration
               FROM {local_jedomi_query_log}
              GROUP BY query
              ORDER BY avg_duration DESC
              LIMIT 10"
        );

        return (object) [
            'total_queries' => (int) $total,
            'slow_queries' => (int) $slow,
            'avg_duration_ms' => round((float) $avgDuration, 2),
            'max_duration_ms' => round((float) $maxDuration, 2),
            'slowest' => array_values($slowest ?: []),
        ];
    }

    /**
     * Purge old log entries (older than given days).
     *
     * @param  int $days Default: 30.
     * @return int Number of deleted rows.
     */
    public static function purge(int $days = 30): int {
        global $DB;
        $cutoff = time() - ($days * 86400);
        return $DB->delete_records_select('local_jedomi_query_log', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
    }
}
