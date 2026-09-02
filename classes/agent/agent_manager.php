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
 * Agent manager — CRUD, cache, stats, and purge for scheduled AI agents.
 *
 * All methods are static, following the static facade pattern consistent
 * with other local_jedomi classes (e.g. telegram\session).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent;

defined('MOODLE_INTERNAL') || die();

/**
 * Static facade for managing scheduled AI agent definitions.
 */
class agent_manager {
    // ── CRUD: Read ───────────────────────────────────────────────────────────

    /**
     * Get a single agent by ID.
     *
     * @param  int $id
     * @return \stdClass|null
     */
    public static function get_agent(int $id): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_jedomi_agents', ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Get agents with optional filtering and pagination.
     *
     * Supported filters:
     *  - role_filter (string): match role shortname in the role_filter JSON.
     *  - status ('enabled'|'disabled'): filter by enabled flag.
     *  - context_type (string): filter by context_type value.
     *  - search (string): LIKE match on the prompt field.
     *  - page (int, default 1): current page.
     *  - perpage (int, default 20): items per page.
     *
     * @param  array $filters
     * @return array ['items' => \stdClass[], 'total' => int]
     */
    public static function get_agents(array $filters = []): array {
        global $DB;

        $params = [];
        $wheres = [];

        // Status filter.
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'enabled') {
                $wheres[] = 'enabled = 1';
            } else if ($filters['status'] === 'disabled') {
                $wheres[] = 'enabled = 0';
            }
        }

        // Context type filter.
        if (!empty($filters['context_type'])) {
            $wheres[] = 'context_type = :contexttype';
            $params['contexttype'] = $filters['context_type'];
        }

        // Search filter on prompt.
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $likesearch = $DB->sql_like('prompt', ':search', false, false);
            $wheres[] = $likesearch;
            $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
        }

        // Role filter — match role shortname inside role_filter JSON.
        // Uses LIKE with JSON fragment to find the role shortname.
        if (!empty($filters['role_filter'])) {
            $role = $filters['role_filter'];
            $wheres[] = $DB->sql_like('role_filter', ':rolefilter', false, false);
            $params['rolefilter'] = '%"' . $DB->sql_like_escape($role) . '"%';
        }

        $wheresql = '';
        if (!empty($wheres)) {
            $wheresql = 'WHERE ' . implode(' AND ', $wheres);
        }

        // Count total matching records.
        $countsql = "SELECT COUNT(1) FROM {local_jedomi_agents} {$wheresql}";
        $total = $DB->count_records_sql($countsql, $params);

        // Pagination.
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perpage = max(1, min(100, (int) ($filters['perpage'] ?? 20)));
        $offset = ($page - 1) * $perpage;

        $selectsql = "SELECT * FROM {local_jedomi_agents} {$wheresql} ORDER BY id ASC";
        $items = $DB->get_records_sql($selectsql, $params, $offset, $perpage);

        return [
            'items' => array_values($items),
            'total' => (int) $total,
        ];
    }

    // ── CRUD: Create ─────────────────────────────────────────────────────────

    /**
     * Create a new agent record.
     *
     * @param  \stdClass $data Fields: userid, prompt, schedule_cron, context_type,
     *                         context_id, role_filter, enabled.
     * @return int New record id.
     */
    public static function create_agent(\stdClass $data): int {
        global $DB;

        $now = time();
        $record = new \stdClass();
        $record->userid = (int) ($data->userid ?? 0);
        $record->name = !empty(trim($data->name ?? '')) ? trim($data->name) : self::generate_name_from_prompt($data->prompt ?? '');
        $record->prompt = $data->prompt ?? '';
        $record->schedule_cron = $data->schedule_cron ?? '* * * * *';
        $record->context_type = $data->context_type ?? 'site';
        $record->context_id = isset($data->context_id) ? (int) $data->context_id : null;
        $record->role_filter = $data->role_filter ?? null;
        $record->enabled = isset($data->enabled) ? (int) $data->enabled : 1;
        $record->lastrun = null;
        $record->nextrun = isset($data->nextrun) ? (int) $data->nextrun : 0;
        $record->failcount = 0;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return $DB->insert_record('local_jedomi_agents', $record);
    }

    /**
     * Auto-generate a SHORT name from a prompt text.
     *
     * Takes the first 3-5 meaningful words (max 35 chars) to keep names short.
     *
     * @param  string $prompt The agent prompt.
     * @return string         Auto-generated name (never empty).
     */
    public static function generate_name_from_prompt(string $prompt): string {
        $name = trim(strip_tags($prompt));
        // Take up to first sentence-ending punctuation.
        $end = mb_strlen($name);
        foreach (['.', '!', '?', "\n"] as $sep) {
            $pos = mb_strpos($name, $sep);
            if ($pos !== false && $pos < $end) {
                $end = $pos;
            }
        }
        $name = trim(mb_substr($name, 0, $end));
        // Take first 3-5 words (max 35 chars).
        $words = preg_split('/\s+/', $name);
        $name = '';
        $maxwords = min(5, count($words));
        for ($i = 0; $i < $maxwords; $i++) {
            $candidate = trim($name . ' ' . $words[$i]);
            if (mb_strlen($candidate) > 35) {
                break;
            }
            $name = $candidate;
        }
        return trim($name) ?: 'Agent';
    }

    // ── CRUD: Update ─────────────────────────────────────────────────────────

    /**
     * Update an existing agent record.
     *
     * Only the fields present in $data are updated. The timemodified field
     * is always set to the current timestamp.
     *
     * @param  int       $id
     * @param  \stdClass $data Fields to update.
     */
    public static function update_agent(int $id, \stdClass $data): void {
        global $DB;

        $record = new \stdClass();
        $record->id = $id;

        $allowed = ['userid', 'name', 'prompt', 'schedule_cron', 'context_type', 'context_id',
            'role_filter', 'enabled'];
        foreach ($allowed as $field) {
            if (property_exists($data, $field)) {
                $record->$field = $data->$field;
            }
        }

        $record->timemodified = time();
        $DB->update_record('local_jedomi_agents', $record);
    }

    // ── CRUD: Delete ─────────────────────────────────────────────────────────

    /**
     * Delete an agent record and its associated cache entries.
     *
     * @param  int $id
     */
    public static function delete_agent(int $id): void {
        global $DB;
        self::clear_agent_cache($id);
        $DB->delete_records('local_jedomi_agents', ['id' => $id]);
    }

    // ── CRUD: Queue for immediate run ────────────────────────────────────────

    /**
     * Queue an agent for immediate execution by setting nextrun to now.
     * The agent_runner scheduled task (every 1 min) picks it up on the next tick.
     *
     * @param  int $id Agent id.
     * @return bool    True if the agent exists and was queued.
     */
    public static function queue_for_run(int $id): bool {
        global $DB;

        $agent = $DB->get_record('local_jedomi_agents', ['id' => $id]);
        if (!$agent) {
            return false;
        }

        $DB->update_record('local_jedomi_agents', (object) [
            'id' => $id,
            'nextrun' => time(),
            'timemodified' => time(),
        ]);

        return true;
    }

    // ── CRUD: Due agents ─────────────────────────────────────────────────────

    /**
     * Get enabled agents whose nextrun has elapsed, ordered by nextrun ASC.
     *
     * @return \stdClass[]
     */
    public static function get_due_agents(): array {
        global $DB;

        $sql = "SELECT * FROM {local_jedomi_agents}
                WHERE enabled = 1 AND nextrun <= :now
                ORDER BY nextrun ASC";
        $records = $DB->get_records_sql($sql, ['now' => time()]);
        return array_values($records);
    }

    // ── CRUD: Mark run ───────────────────────────────────────────────────────

    /**
     * Record an execution result for an agent.
     *
     * On success: updates lastrun, calculates nextrun, resets failcount.
     * On failure: increments failcount, does not change lastrun.
     * Both cases update timemodified.
     *
     * @param int  $id
     * @param bool $success
     */
    public static function mark_run(int $id, bool $success): void {
        global $DB;

        $agent = self::get_agent($id);
        if (!$agent) {
            return;
        }

        $now = time();
        $update = new \stdClass();
        $update->id = $id;
        $update->timemodified = $now;

        if ($success) {
            $update->lastrun = $now;
            $update->nextrun = self::calculate_next_run($agent->schedule_cron, $now);
            $update->failcount = 0;
        } else {
            $update->failcount = (int) $agent->failcount + 1;
        }

        $DB->update_record('local_jedomi_agents', $update);
    }

    // ── Cron parser ──────────────────────────────────────────────────────────

    /**
     * Calculate the next run timestamp from a cron expression.
     *
     * Supports standard cron fields: minute, hour, day-of-month, month, day-of-week.
     * Handles star, star/N, single N, N-M, and comma-separated lists.
     *
     * Falls back to $from + 300 seconds if the expression cannot be parsed.
     *
     * @param  string   $cronexpr Five-field cron expression (e.g. every-5-minutes).
     * @param  int|null $from      Base timestamp (defaults to time()).
     * @return int                 Next run timestamp.
     */
    public static function calculate_next_run(string $cronexpr, ?int $from = null): int {
        $from = $from ?? time();
        $parts = preg_split('/\s+/', trim($cronexpr));

        if (count($parts) !== 5) {
            return $from + 300;
        }

        try {
            // Field ranges: minute, hour, day-of-month, month, day-of-week.
            $ranges = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];
            $fields = [];
            foreach ($parts as $i => $part) {
                $fields[$i] = self::parse_cron_field($part, $ranges[$i][0], $ranges[$i][1]);
            }

            // Normalise day-of-week: 7 (some cron dialects) → 0 (Sunday).
            if (in_array(7, $fields[4])) {
                $fields[4] = array_values(array_unique(array_merge(
                    $fields[4],
                    [0]
                )));
                $fields[4] = array_values(array_filter($fields[4], function (int $v): bool {
                    return $v !== 7;
                }));
            }

            // Start searching from the next full minute.
            $ts = $from - ($from % 60) + 60;
            $limit = $from + 366 * 86400; // Search up to ~1 year ahead.

            while ($ts <= $limit) {
                // Use UTC-based DateTime so the algorithm is timezone-independent.
                $utcdate = new \DateTime('@' . $ts);
                $date = [
                    'minutes' => (int) $utcdate->format('i'),
                    'hours'   => (int) $utcdate->format('G'),
                    'mday'    => (int) $utcdate->format('j'),
                    'wday'    => (int) $utcdate->format('w'),
                    'mon'     => (int) $utcdate->format('n'),
                ];
                if (
                    in_array($date['minutes'], $fields[0]) &&
                    in_array($date['hours'], $fields[1]) &&
                    in_array($date['mday'], $fields[2]) &&
                    in_array($date['mon'], $fields[3]) &&
                    in_array($date['wday'], $fields[4])
                ) {
                    return $ts;
                }
                $ts += 60;
            }

            return $from + 300;
        } catch (\Exception $e) {
            return $from + 300;
        }
    }

    /**
     * Parse a single cron field expression into an array of matching values.
     *
     * @param  string $expr The field expression (e.g. star/5, 1-5, 1,3,5).
     * @param  int    $min  Minimum valid value.
     * @param  int    $max  Maximum valid value.
     * @return int[]
     */
    private static function parse_cron_field(string $expr, int $min, int $max): array {
        $expr = trim($expr);

        // Comma-separated list: "1,3,5".
        if (strpos($expr, ',') !== false) {
            $result = [];
            foreach (explode(',', $expr) as $part) {
                $result = array_merge($result, self::parse_cron_field(trim($part), $min, $max));
            }
            return array_values(array_unique($result));
        }

        // Step value: "*/5".
        if (strpos($expr, '*/') === 0) {
            $step = (int) substr($expr, 2);
            if ($step <= 0) {
                return range($min, $max);
            }
            $result = [];
            for ($i = $min; $i <= $max; $i += $step) {
                $result[] = $i;
            }
            return $result;
        }

        // Range: "1-5".
        if (strpos($expr, '-') !== false) {
            $parts = explode('-', $expr, 2);
            return range((int) $parts[0], (int) $parts[1]);
        }

        // Wildcard: "*".
        if ($expr === '*') {
            return range($min, $max);
        }

        // Single value.
        return [(int) $expr];
    }

    // ── Cache: Read ──────────────────────────────────────────────────────────

    /**
     * Get the cached result for a specific agent context.
     *
     * @param  int    $agentid
     * @param  string $contexthash SHA256 of the context inputs.
     * @return string|null The result_json value, or null if not cached.
     */
    public static function get_cached_result(int $agentid, string $contexthash): ?string {
        global $DB;

        $record = $DB->get_record('local_jedomi_agent_cache', [
            'agentid' => $agentid,
            'context_hash' => $contexthash,
        ], 'result_json');

        return $record ? $record->result_json : null;
    }

    // ── Cache: Write ─────────────────────────────────────────────────────────

    /**
     * Store or update a cached result for a specific agent context.
     *
     * If a row with the same (agentid, context_hash) already exists, its
     * result_json and timecreated are updated.
     *
     * @param int    $agentid
     * @param string $contexthash SHA256 of the context inputs.
     * @param string $result       The JSON result string to cache.
     */
    public static function set_cached_result(int $agentid, string $contexthash, string $result): void {
        global $DB;

        $existing = $DB->get_record('local_jedomi_agent_cache', [
            'agentid' => $agentid,
            'context_hash' => $contexthash,
        ], 'id');

        if ($existing) {
            $DB->update_record('local_jedomi_agent_cache', (object) [
                'id' => $existing->id,
                'result_json' => $result,
                'timecreated' => time(),
            ]);
        } else {
            $DB->insert_record('local_jedomi_agent_cache', (object) [
                'agentid' => $agentid,
                'context_hash' => $contexthash,
                'result_json' => $result,
                'timecreated' => time(),
            ]);
        }
    }

    /**
     * Clear all cached results for a given agent.
     *
     * @param int $agentid
     */
    public static function clear_agent_cache(int $agentid): void {
        global $DB;
        $DB->delete_records('local_jedomi_agent_cache', ['agentid' => $agentid]);
    }

    // ── Cache: Preview ─────────────────────────────────────────────────────────

    /**
     * Delete a single preview cache entry by context_hash.
     *
     * Preview entries have agentid = 0 (they are not attached to any agent).
     *
     * @param string $contexthash The md5 hash of the preview prompt.
     * @return bool True if a row was deleted.
     */
    public static function clear_preview_cache(string $contexthash): bool {
        global $DB;
        $deleted = $DB->delete_records('local_jedomi_agent_cache', [
            'agentid' => 0,
            'context_hash' => $contexthash,
        ]);
        return $deleted > 0;
    }

    // ── Cache: Semantic Plan ──────────────────────────────────────────────────

    /**
     * Store an execution plan alongside the answer for semantic cache replay.
     *
     * If a row with the same (agentid, context_hash) already exists, its
     * plan_json, result_json, and timecreated are updated.
     *
     * @param int    $agentid
     * @param string $contexthash
     * @param array  $plan   Ordered tool calls: [['tool' => 'x', 'arguments' => '{}'], ...].
     * @param string $answer Final answer text for reformulation context.
     */
    public static function set_cached_plan(int $agentid, string $contexthash, array $plan, string $answer): void {
        global $DB;

        $existing = $DB->get_record('local_jedomi_agent_cache', [
            'agentid' => $agentid,
            'context_hash' => $contexthash,
        ], 'id');

        $record = (object) [
            'result_json' => $answer,
            'plan_json' => json_encode($plan, JSON_UNESCAPED_UNICODE),
            'timecreated' => time(),
        ];

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_jedomi_agent_cache', $record);
        } else {
            $record->agentid = $agentid;
            $record->context_hash = $contexthash;
            $DB->insert_record('local_jedomi_agent_cache', $record);
        }
    }

    /**
     * Get the cached plan and answer for a specific agent context.
     *
     * @param  int    $agentid
     * @param  string $contexthash
     * @return array|null ['plan' => [...], 'answer' => '...'] or null if no plan.
     */
    public static function get_cached_plan(int $agentid, string $contexthash): ?array {
        global $DB;

        $record = $DB->get_record('local_jedomi_agent_cache', [
            'agentid' => $agentid,
            'context_hash' => $contexthash,
        ], 'plan_json, result_json');

        if (!$record || empty($record->plan_json)) {
            return null;
        }

        $plan = json_decode($record->plan_json, true);
        if (!is_array($plan)) {
            return null;
        }

        return [
            'plan' => $plan,
            'answer' => $record->result_json ?? '',
        ];
    }

    /**
     * Check whether a tool name is read-only (safe to replay in semantic cache).
     *
     * Write tools like user creation, enrolment, etc. MUST NOT be replayed
     * because they would create duplicate data.
     *
     * @param  string $toolname
     * @return bool
     */
    public static function is_tool_readonly(string $toolname): bool {
        // Known read-only tools.
        $readonly = [
            'execute_sql',
            'search_tables',
            'describe_table',
            'get_site_config',
            'ws_search',
            'ws_describe',
            'admin_search',
            'admin_describe',
        ];
        if (in_array($toolname, $readonly, true)) {
            return true;
        }

        // ws_execute / call_webservice: check the actual WS being called.
        // This is handled at plan-recording time by inspecting arguments,
        // not by tool name alone — classify at call site.
        if ($toolname === 'ws_execute' || $toolname === 'call_webservice') {
            return false; // Conservatively assume write — caller must verify.
        }

        // Unknown tools default to NOT readonly (safe fallback).
        return false;
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    /**
     * Get aggregate statistics for agents matching the given filters.
     *
     * The returned object contains:
     *  - charts->by_role:    array of role shortname → count
     *  - charts->by_status:  object {enabled: N, disabled: N}
     *  - charts->executions_30d: array of {day: string, count: int}
     *  - rows:               paginated agent records (same as get_agents()).
     *  - total:              total matching count.
     *  - page:               current page number.
     *
     * @param  array $filters Same filter options as get_agents().
     * @return \stdClass
     */
    public static function get_stats(array $filters): \stdClass {
        global $DB;

        // ── By role: decode role_filter JSON and count per shortname. ────────
        $allagents = $DB->get_records('local_jedomi_agents', null, '', 'id, role_filter');
        $byrole = [];
        foreach ($allagents as $a) {
            if (!empty($a->role_filter)) {
                $roles = json_decode($a->role_filter, true);
                if (is_array($roles)) {
                    foreach ($roles as $role) {
                        $role = trim($role);
                        if (!isset($byrole[$role])) {
                            $byrole[$role] = 0;
                        }
                        $byrole[$role]++;
                    }
                }
            }
        }
        arsort($byrole);

        // ── By status: aggregate enabled vs disabled. ────────────────────────
        $enabled = $DB->count_records('local_jedomi_agents', ['enabled' => 1]);
        $disabled = $DB->count_records('local_jedomi_agents', ['enabled' => 0]);
        $bystatus = (object) [
            'enabled' => (int) $enabled,
            'disabled' => (int) $disabled,
        ];

        // ── Executions per day (last 30 days, DB-agnostic). ──────────────────
        $thirtydaysago = time() - (30 * 86400);
        $runs = $DB->get_records_select(
            'local_jedomi_agents',
            'lastrun IS NOT NULL AND lastrun >= :since',
            ['since' => $thirtydaysago],
            '',
            'id, lastrun'
        );

        $perday = [];
        foreach ($runs as $run) {
            $day = date('Y-m-d', $run->lastrun);
            $perday[$day] = ($perday[$day] ?? 0) + 1;
        }

        // Build a zero-filled array for all 30 days.
        $executions30d = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = date('Y-m-d', time() - ($i * 86400));
            $executions30d[] = (object) [
                'day' => $day,
                'count' => (int) ($perday[$day] ?? 0),
            ];
        }

        // ── Paginated rows (same as get_agents). ────────────────────────────
        $result = self::get_agents($filters);
        $page = max(1, (int) ($filters['page'] ?? 1));

        return (object) [
            'charts' => (object) [
                'by_role' => $byrole,
                'by_status' => $bystatus,
                'executions_30d' => $executions30d,
            ],
            'rows' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
        ];
    }

    // ── Purge ─────────────────────────────────────────────────────────────────

    /**
     * Delete agents matching a context type and optional context id.
     *
     * Associated cache rows are cascade-deleted via the delete_agent() method.
     *
     * @param  string   $type Context type (site|course|category|user).
     * @param  int|null $id   Context instance id (null for site context).
     * @return int            Number of deleted agents.
     */
    public static function purge_by_context(string $type, ?int $id): int {
        global $DB;

        $agents = $DB->get_records('local_jedomi_agents', [
            'context_type' => $type,
            'context_id' => $id,
        ], '', 'id');

        $count = 0;
        foreach ($agents as $agent) {
            self::delete_agent($agent->id);
            $count++;
        }

        return $count;
    }

    /**
     * Delete all agents whose role_filter JSON contains the given role shortname.
     *
     * @param  string $role Role shortname (e.g. "manager", "teacher").
     * @return int          Number of deleted agents.
     */
    public static function purge_by_role(string $role): int {
        global $DB;

        $like = $DB->sql_like('role_filter', ':role');
        $params = ['role' => '%"' . $DB->sql_like_escape($role) . '"%'];
        $agents = $DB->get_records_select('local_jedomi_agents', $like, $params, '', 'id');

        $count = 0;
        foreach ($agents as $agent) {
            self::delete_agent($agent->id);
            $count++;
        }

        return $count;
    }

    /**
     * Delete ALL agent records and their associated cache entries.
     *
     * @return int Number of deleted agents.
     */
    public static function purge_all(): int {
        global $DB;

        $count = $DB->count_records('local_jedomi_agents');

        // Delete cache first (cascade not enforced by DB engine).
        $DB->delete_records('local_jedomi_agent_cache', []);
        $DB->delete_records('local_jedomi_agents', []);

        return (int) $count;
    }
}
