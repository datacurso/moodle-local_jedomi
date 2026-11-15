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

/**
 * Static facade for managing scheduled AI agent definitions.
 */
class agent_manager {
    /** @var array<string, int> Privilege rank of each Jedomi role, from the least to the most privileged. */
    private const ROLE_RANK = ['student' => 0, 'teacher' => 1, 'manager' => 2, 'admin' => 3];

    /** @var int Consecutive failures after which an agent is disabled. */
    public const MAX_FAILURES = 5;

    /** @var int Seconds before the first retry of a failed agent; doubled on each consecutive failure. */
    public const FAIL_BACKOFF_BASE = 300;

    /** @var int Longest wait, in seconds, before retrying a failed agent. */
    public const FAIL_BACKOFF_MAX = DAYSECS;

    /** @var string[] Context types an agent can run in. */
    public const CONTEXT_TYPES = ['site', 'course', 'category', 'user'];

    /** @var array<string, int> Moodle context level that the context id of each context type must have. */
    private const CONTEXT_LEVELS = ['course' => CONTEXT_COURSE, 'category' => CONTEXT_COURSECAT, 'user' => CONTEXT_USER];

    /** @var int[][] Lowest and highest value of each field of a schedule: minute, hour, day, month, weekday. */
    private const CRON_RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    // ... ── Validation ───────────────────────────────────────────────────────────

    /**
     * Whether a schedule is a valid five-field cron expression with every value in range.
     *
     * Each field is a list of "*", "*\/step", a number, or a range "a-b" with an optional "/step", where every number is
     * within the range of the field and a range does not go backwards.
     *
     * @param string $cronexpr Schedule.
     * @return bool
     */
    public static function is_valid_cron(string $cronexpr): bool {
        $parts = preg_split('/\s+/', trim($cronexpr));
        if (count($parts) !== 5) {
            return false;
        }
        try {
            foreach ($parts as $i => $part) {
                self::parse_cron_field($part, self::CRON_RANGES[$i][0], self::CRON_RANGES[$i][1]);
            }
        } catch (\invalid_parameter_exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Reason why an agent cannot be saved, or null when it can.
     *
     * The instruction must have text, the schedule must be valid, the context type must be known and, except for the
     * site, the context id must be the id of an existing Moodle context of that type (a course, category or user
     * context id, not the id of the course, category or user).
     *
     * The approved writes must be writes the owner may approve, see approvable_writes().
     *
     * @param \stdClass $agent Agent fields as they will be stored (prompt, schedule_cron, context_type, context_id, userid,
     *     approvedwrites).
     * @return string|null Translated reason, or null when the agent is valid.
     */
    public static function validation_error(\stdClass $agent): ?string {
        if (trim((string) ($agent->prompt ?? '')) === '') {
            return get_string('agent_invalid_prompt', 'local_jedomi');
        }
        if (!self::is_valid_cron((string) ($agent->schedule_cron ?? ''))) {
            return get_string('agent_invalid_cron', 'local_jedomi');
        }
        $type = (string) ($agent->context_type ?? 'site');
        if (!in_array($type, self::CONTEXT_TYPES, true)) {
            return get_string('agent_invalid_context_type', 'local_jedomi');
        }
        $approved = self::approved_writes($agent);
        if (!empty($approved)) {
            $approvable = self::approvable_writes((int) ($agent->userid ?? 0));
            foreach ($approved as $function) {
                if (!in_array($function, $approvable, true)) {
                    return get_string('agent_invalid_approved_write', 'local_jedomi', $function);
                }
            }
        }
        if ($type === 'site') {
            return null;
        }
        $contextid = (int) ($agent->context_id ?? 0);
        $context = $contextid > 0 ? \context::instance_by_id($contextid, IGNORE_MISSING) : false;
        if (!$context || (int) $context->contextlevel !== self::CONTEXT_LEVELS[$type]) {
            $typename = get_string('agent_context_type_' . $type, 'local_jedomi');
            return get_string('agent_invalid_context_id', 'local_jedomi', $typename);
        }
        return null;
    }

    /**
     * Throw the reason why an agent cannot be saved, if any.
     *
     * @param \stdClass $agent Agent fields as they will be stored.
     * @return void
     * @throws \moodle_exception When the agent is not valid; its message is the translated reason.
     */
    private static function require_valid(\stdClass $agent): void {
        $error = self::validation_error($agent);
        if ($error !== null) {
            throw new \moodle_exception('agent_invalid', 'local_jedomi', '', $error);
        }
    }

    // ... ── Approved writes ──────────────────────────────────────────────────────

    /**
     * Web service functions that an agent may run without confirmation, as approved by its creator in the agent form.
     *
     * @param \stdClass $agent Agent record or fields (approvedwrites: JSON list, list, or empty).
     * @return string[] Function names; empty when none is approved.
     */
    public static function approved_writes(\stdClass $agent): array {
        $value = $agent->approvedwrites ?? null;
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return [];
        }
        $functions = array_filter(array_map('strval', $value), static fn(string $name): bool => $name !== '');
        return array_values(array_unique($functions));
    }

    /**
     * Web service functions that change data and that the agents of a user may be approved to run without confirmation:
     * the functions of the web service list saved for the Jedomi role of the user that are not declared as reading.
     *
     * An administrator without a saved list gets none: agents never get the "every function" access.
     *
     * @param int $userid Owner of the agent.
     * @return string[] Function names, sorted.
     */
    public static function approvable_writes(int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        if ($userid <= 0) {
            return [];
        }
        $role = \local_jedomi_get_user_role_for_user($userid);
        if (\local_jedomi_role_has_full_ws_access($role)) {
            return [];
        }
        $functions = [];
        foreach (\local_jedomi_get_role_allowlist($role) as $function) {
            // An unknown function or one declared as reading has no severity.
            $args = ['wsname' => (string) $function];
            $severity = \local_jedomi\local\service\pending_action_service::severity('ws_execute', $args);
            if (is_string($function) && $severity !== null) {
                $functions[] = $function;
            }
        }
        $functions = array_values(array_unique($functions));
        sort($functions);
        return $functions;
    }

    /**
     * Stored form of the approved writes: a JSON list, or null for none.
     *
     * @param mixed $value List of function names, its JSON, or empty.
     * @return string|null
     */
    private static function encode_approved_writes($value): ?string {
        $functions = self::approved_writes((object) ['approvedwrites' => $value]);
        return empty($functions) ? null : json_encode($functions);
    }

    // ... ── Permissions ──────────────────────────────────────────────────────────

    /**
     * Whether a user is the owner of an agent, a site administrator or has a strictly higher rank than the owner.
     *
     * An agent runs with the identity, permissions and credits of its owner, so a user of the same rank as the owner
     * must neither read nor change what the agent does.
     *
     * @param \stdClass $agent Agent record (needs ->userid).
     * @param int $actorid User that acts on the agent.
     * @return bool
     */
    private static function is_owner_or_above(\stdClass $agent, int $actorid): bool {
        if ($actorid <= 0) {
            return false;
        }
        if ((int) $agent->userid === $actorid || is_siteadmin($actorid)) {
            return true;
        }
        return self::role_rank((int) $agent->userid) < self::role_rank($actorid);
    }

    /**
     * Whether a user may edit, enable, run, delete or purge an agent.
     *
     * Only the owner, site administrators and users of a strictly higher rank than the owner may, so nobody can change
     * an agent that runs with the permissions and credits of a user of their own rank or higher.
     *
     * @param \stdClass $agent Agent record (needs ->userid).
     * @param int $actorid User that acts on the agent.
     * @return bool
     */
    public static function can_manage_agent(\stdClass $agent, int $actorid): bool {
        return self::is_owner_or_above($agent, $actorid);
    }

    /**
     * Whether a user may read the instruction (prompt) of an agent.
     *
     * Only the owner, site administrators and users of a strictly higher rank than the owner may.
     *
     * @param \stdClass $agent Agent record (needs ->userid).
     * @param int $actorid User that reads the agent.
     * @return bool
     */
    public static function can_view_instruction(\stdClass $agent, int $actorid): bool {
        return self::is_owner_or_above($agent, $actorid);
    }

    /**
     * Copy of an agent as a user may see it: without its instruction when they may not read it, and saying whether the
     * instruction is hidden and whether they may manage the agent.
     *
     * A name generated from the first words of a hidden instruction is hidden too, since it would reveal them.
     *
     * @param \stdClass $agent Agent record (needs ->userid, ->prompt and ->name).
     * @param int $actorid User that reads the agent.
     * @return \stdClass Copy with ->prompt (null when hidden), ->name, ->prompthidden and ->canmanage; when hidden,
     *     ->lastresult is null and ->lasterror only says that the last run failed.
     */
    public static function agent_for_actor(\stdClass $agent, int $actorid): \stdClass {
        $copy = clone $agent;
        $copy->prompthidden = !self::can_view_instruction($agent, $actorid);
        $copy->canmanage = self::can_manage_agent($agent, $actorid);
        if ($copy->prompthidden) {
            if (isset($agent->name) && $agent->name === self::generate_name_from_prompt((string) ($agent->prompt ?? ''))) {
                $copy->name = '';
            }
            $copy->prompt = null;
            // The answer of the agent reveals what its instruction asks for, and so can the reason of a failed run:
            // only the fact that it failed is kept, with the failure count.
            $copy->lastresult = null;
            if (!empty($agent->lasterror)) {
                $copy->lasterror = get_string('agent_lasterror_hidden', 'local_jedomi');
            }
        }
        return $copy;
    }

    /**
     * Owners of agents whose instruction a user may read.
     *
     * @param int $actorid User that reads the agents.
     * @return int[] Owner user ids.
     */
    public static function owners_with_visible_instruction(int $actorid): array {
        global $DB;
        $owners = [];
        foreach ($DB->get_fieldset_sql('SELECT DISTINCT userid FROM {local_jedomi_agents}') as $ownerid) {
            if (self::can_view_instruction((object) ['userid' => (int) $ownerid], $actorid)) {
                $owners[] = (int) $ownerid;
            }
        }
        return $owners;
    }

    /**
     * Load an agent that a user may manage.
     *
     * @param int $id Agent id.
     * @param int $actorid User that acts on the agent.
     * @return \stdClass Agent record.
     * @throws \moodle_exception When the agent does not exist or the user may not manage it.
     */
    public static function require_manageable_agent(int $id, int $actorid): \stdClass {
        $agent = self::get_agent($id);
        if (!$agent) {
            throw new \moodle_exception('agent_not_found', 'local_jedomi');
        }
        if (!self::can_manage_agent($agent, $actorid)) {
            throw new \moodle_exception('agent_error_nopermission', 'local_jedomi');
        }
        return $agent;
    }

    /**
     * Whether a user may create an agent owned by a given user: only for themselves, or for anyone as site admin.
     *
     * @param int $ownerid Owner of the new agent, whose permissions the agent will run with.
     * @param int $actorid User that creates the agent.
     * @return bool
     */
    public static function can_create_for(int $ownerid, int $actorid): bool {
        return $actorid > 0 && ($ownerid === $actorid || is_siteadmin($actorid));
    }

    /**
     * Privilege rank of the Jedomi role of a user.
     *
     * @param int $userid User id.
     * @return int
     */
    private static function role_rank(int $userid): int {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        return self::ROLE_RANK[\local_jedomi_get_user_role_for_user($userid)] ?? 0;
    }

    // ... ── CRUD: Read ───────────────────────────────────────────────────────────

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
     *  - role (int): id of a role, matched like role_filter with its shortname (the Agents tab sends it).
     *  - status ('enabled'|'disabled', or 1|0): filter by enabled flag.
     *  - context_type (string): filter by context_type value.
     *  - search (string): LIKE match on the prompt field.
     *  - actorid (int): user that searches; the search then only matches the agents whose instruction they may read.
     *  - idsql (array): [string $sql, array $params] of a query that selects the ids of the agents to keep, such as the
     *    agents that match the filters of the agents report (see filtered_ids::sql()).
     *  - page (int, default 1): current page.
     *  - perpage (int, default 20): items per page.
     *
     * @param  array $filters
     * @return array ['items' => \stdClass[], 'total' => int]
     */
    public static function get_agents(array $filters = []): array {
        global $DB;

        [$wheresql, $params] = self::filter_where($filters);

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

    /**
     * WHERE clause of the agents that match the filters of get_agents().
     *
     * @param array $filters Filters, see get_agents().
     * @return array [string $wheresql (empty or starting with WHERE), array $params]
     */
    private static function filter_where(array $filters): array {
        global $DB;

        $params = [];
        $wheres = [];

        // Status filter: the Agents tab may send it as enabled/disabled or as 1/0.
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'enabled' || $status === '1') {
            $wheres[] = 'enabled = 1';
        } else if ($status === 'disabled' || $status === '0') {
            $wheres[] = 'enabled = 0';
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
            // A hidden instruction must not be guessed by searching for its words.
            if (!empty($filters['actorid'])) {
                $owners = self::owners_with_visible_instruction((int) $filters['actorid']);
                if (empty($owners)) {
                    $wheres[] = '1 = 0';
                } else {
                    [$insql, $inparams] = $DB->get_in_or_equal($owners, SQL_PARAMS_NAMED, 'searchowner');
                    $wheres[] = "userid {$insql}";
                    $params = array_merge($params, $inparams);
                }
            }
        }

        // Agents selected by a query, such as the rows of the agents report with its filters.
        if (!empty($filters['idsql'])) {
            [$idsql, $idparams] = $filters['idsql'];
            $wheres[] = "id IN ({$idsql})";
            $params = array_merge($params, $idparams);
        }

        // Role filter — match role shortname inside role_filter JSON.
        // Uses LIKE with JSON fragment to find the role shortname.
        $role = (string) ($filters['role_filter'] ?? '');
        if ($role === '' && !empty($filters['role'])) {
            $role = (string) $DB->get_field('role', 'shortname', ['id' => (int) $filters['role']]);
            if ($role === '') {
                $wheres[] = '1 = 0';
            }
        }
        if ($role !== '') {
            $wheres[] = $DB->sql_like('role_filter', ':rolefilter', false, false);
            $params['rolefilter'] = '%"' . $DB->sql_like_escape($role) . '"%';
        }

        $wheresql = empty($wheres) ? '' : 'WHERE ' . implode(' AND ', $wheres);
        return [$wheresql, $params];
    }

    // ... ── CRUD: Create ─────────────────────────────────────────────────────────

    /**
     * Create a new agent record.
     *
     * @param  \stdClass $data Fields: userid, prompt, schedule_cron, context_type,
     *                         context_id (Moodle context id), role_filter, enabled, approvedwrites (list of web service
     *                         functions; none when missing).
     * @return int New record id.
     * @throws \moodle_exception When the agent is not valid, see validation_error().
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
        // The context id is the id of the Moodle context (course, category or user context); the site needs none.
        $record->context_id = ($record->context_type !== 'site' && !empty($data->context_id)) ? (int) $data->context_id : null;
        $record->role_filter = $data->role_filter ?? null;
        $record->enabled = isset($data->enabled) ? (int) $data->enabled : 1;
        // Only the agent form approves writes; an agent created by the chat, Telegram or another agent has none.
        $record->approvedwrites = self::encode_approved_writes($data->approvedwrites ?? null);
        self::require_valid($record);
        $record->lastrun = null;
        // A new agent waits for its scheduled hour, in the time zone of its creator.
        $record->nextrun = isset($data->nextrun) ? (int) $data->nextrun
            : self::calculate_next_run($record->schedule_cron, $now, self::creator_timezone($record->userid));
        $record->failcount = 0;
        $record->timecreated = $now;
        $record->timemodified = $now;

        $id = (int) $DB->insert_record('local_jedomi_agents', $record);
        self::agent_event(\local_jedomi\event\agent_created::class, $id, (int) $record->userid);
        return $id;
    }

    /**
     * Trigger an agent event (created, updated, deleted); the prompt and the results are never part of it.
     *
     * @param string $class Event class.
     * @param int $id Agent id.
     * @param int $ownerid Creator of the agent, stored as the related user.
     * @param array $other Extra event data.
     * @return void
     */
    private static function agent_event(string $class, int $id, int $ownerid, array $other = []): void {
        $data = ['context' => \context_system::instance(), 'objectid' => $id, 'other' => $other];
        if ($ownerid > 0) {
            $data['relateduserid'] = $ownerid;
        }
        $class::create($data)->trigger();
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

    // ... ── CRUD: Update ─────────────────────────────────────────────────────────

    /**
     * Update an existing agent record.
     *
     * Only the fields present in $data are updated. The timemodified field
     * is always set to the current timestamp.
     *
     * @param  int       $id
     * @param  \stdClass $data Fields to update.
     * @return void
     * @throws \moodle_exception When the agent would not be valid, see validation_error().
     */
    public static function update_agent(int $id, \stdClass $data): void {
        global $DB;

        $record = new \stdClass();
        $record->id = $id;

        $allowed = ['userid', 'name', 'prompt', 'schedule_cron', 'context_type', 'context_id',
            'role_filter', 'enabled', 'approvedwrites'];
        foreach ($allowed as $field) {
            if (property_exists($data, $field)) {
                $record->$field = $data->$field;
            }
        }
        if (property_exists($record, 'approvedwrites')) {
            $record->approvedwrites = self::encode_approved_writes($record->approvedwrites);
        }

        if (property_exists($record, 'context_type') && $record->context_type === 'site') {
            $record->context_id = null;
        }
        if (property_exists($record, 'name')) {
            $record->name = trim((string) $record->name);
        }

        // Enabling an agent or changing its schedule or owner moves its next run to its next scheduled hour.
        $current = self::get_agent($id);
        if ($current) {
            self::require_valid((object) array_merge((array) $current, (array) $record));
            if (property_exists($record, 'name') && $record->name === '') {
                $record->name = self::generate_name_from_prompt((string) ($record->prompt ?? $current->prompt));
            }
            $enabling = property_exists($record, 'enabled') && (int) $record->enabled === 1 && (int) $current->enabled === 0;
            $rescheduled = property_exists($record, 'schedule_cron') && $record->schedule_cron !== $current->schedule_cron;
            $newowner = property_exists($record, 'userid') && (int) $record->userid !== (int) $current->userid;
            if ($newowner && !property_exists($record, 'approvedwrites')) {
                // The writes were approved for the permissions of the previous owner.
                $record->approvedwrites = null;
            }
            if ($enabling) {
                // A re-enabled agent starts again without the failures that disabled it.
                $record->failcount = 0;
                $record->lasterror = null;
            }
            if ($enabling || $rescheduled || $newowner) {
                $record->nextrun = self::calculate_next_run(
                    $record->schedule_cron ?? $current->schedule_cron,
                    time(),
                    self::creator_timezone((int) ($record->userid ?? $current->userid))
                );
            }
        }

        $record->timemodified = time();
        $DB->update_record('local_jedomi_agents', $record);
        $ownerid = (int) ($record->userid ?? ($current->userid ?? 0));
        self::agent_event(\local_jedomi\event\agent_updated::class, $id, $ownerid);
    }

    // ... ── CRUD: Delete ─────────────────────────────────────────────────────────

    /**
     * Delete an agent record and its associated cache entries.
     *
     * @param  int $id
     */
    public static function delete_agent(int $id): void {
        global $DB;
        $ownerid = (int) $DB->get_field('local_jedomi_agents', 'userid', ['id' => $id]);
        self::clear_agent_cache($id);
        $DB->delete_records('local_jedomi_agents', ['id' => $id]);
        if ($ownerid > 0) {
            self::agent_event(\local_jedomi\event\agent_deleted::class, $id, $ownerid);
        }
    }

    // ... ── CRUD: Queue for immediate run ────────────────────────────────────────

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

    // ... ── CRUD: Due agents ─────────────────────────────────────────────────────

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

    // ... ── CRUD: Mark run ───────────────────────────────────────────────────────

    /**
     * Record an execution result for an agent.
     *
     * On success: updates lastrun, keeps the answer as the last result, calculates nextrun and resets failcount.
     * On failure: increments failcount, keeps the reason as the last error and moves nextrun with an exponential backoff
     * (FAIL_BACKOFF_BASE seconds doubled on each consecutive failure, at most FAIL_BACKOFF_MAX), so a failing agent does
     * not call the AI service every minute. After MAX_FAILURES consecutive failures the agent is disabled.
     * Both cases update timemodified.
     *
     * @param int $id Agent id.
     * @param bool $success Whether the run produced an answer.
     * @param string $detail Answer of the run on success, reason of the failure otherwise.
     * @return bool True when this failure disabled the agent.
     */
    public static function mark_run(int $id, bool $success, string $detail = ''): bool {
        global $DB;

        $agent = self::get_agent($id);
        if (!$agent) {
            return false;
        }

        $now = time();
        $update = new \stdClass();
        $update->id = $id;
        $update->timemodified = $now;
        $disabled = false;

        if ($success) {
            $update->lastrun = $now;
            $update->nextrun = self::calculate_next_run($agent->schedule_cron, $now, self::creator_timezone((int) $agent->userid));
            $update->failcount = 0;
            $update->lastresult = $detail;
            $update->lasterror = null;
        } else {
            $update->failcount = (int) $agent->failcount + 1;
            $update->nextrun = $now + self::failure_backoff($update->failcount);
            $update->lasterror = \core_text::substr($detail, 0, 1000);
            if ($update->failcount >= self::MAX_FAILURES && (int) $agent->enabled === 1) {
                $update->enabled = 0;
                $disabled = true;
            }
        }

        $DB->update_record('local_jedomi_agents', $update);
        if ((int) $agent->userid > 0) {
            self::agent_event(\local_jedomi\event\agent_run::class, $id, (int) $agent->userid, ['success' => $success]);
        }
        return $disabled;
    }

    /**
     * Seconds to wait before retrying an agent after a number of consecutive failures.
     *
     * @param int $failcount Consecutive failures, at least 1.
     * @return int
     */
    public static function failure_backoff(int $failcount): int {
        $exponent = min(max($failcount, 1) - 1, 20);
        return (int) min(self::FAIL_BACKOFF_BASE * (2 ** $exponent), self::FAIL_BACKOFF_MAX);
    }

    /**
     * Time zone of the creator of an agent, in which its schedule is applied.
     *
     * @param int $userid Agent creator.
     * @return string Time zone name; the server time zone when the user does not exist.
     */
    public static function creator_timezone(int $userid): string {
        $user = $userid > 0 ? \core_user::get_user($userid) : false;
        return $user ? \core_date::get_user_timezone($user) : \core_date::get_server_timezone();
    }

    // ... ── Cron parser ──────────────────────────────────────────────────────────

    /**
     * Calculate the next run timestamp from a cron expression.
     *
     * Supports standard cron fields: minute, hour, day-of-month, month, day-of-week.
     * Handles star, star/N, single N, N-M, and comma-separated lists.
     *
     * Falls back to $from + 300 seconds if the expression cannot be parsed. New and edited agents are validated with
     * is_valid_cron() and validation_error(), so only schedules stored before that validation can fall back.
     *
     * @param  string   $cronexpr Five-field cron expression (e.g. every-5-minutes).
     * @param  int|null $from      Base timestamp (defaults to time()).
     * @param  string|null $timezone Time zone the hours of the expression are in; null for UTC.
     * @return int                 Next run timestamp.
     */
    public static function calculate_next_run(string $cronexpr, ?int $from = null, ?string $timezone = null): int {
        $from = $from ?? time();
        $parts = preg_split('/\s+/', trim($cronexpr));

        if (count($parts) !== 5) {
            return $from + 300;
        }

        try {
            // Field ranges: minute, hour, day-of-month, month, day-of-week.
            $fields = [];
            foreach ($parts as $i => $part) {
                $fields[$i] = self::parse_cron_field($part, self::CRON_RANGES[$i][0], self::CRON_RANGES[$i][1]);
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

            $tz = new \DateTimeZone($timezone ?? 'UTC');
            while ($ts <= $limit) {
                // The fields of the expression are compared in the given time zone.
                $utcdate = (new \DateTime('@' . $ts))->setTimezone($tz);
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
     * Accepts a list of "*", "*\/step", a number, "a-b" and "a-b/step". Every number must be within the range of the
     * field, a range must not go backwards and a step must be positive.
     *
     * @param  string $expr The field expression (e.g. star/5, 1-5, 1,3,5).
     * @param  int    $min  Minimum valid value.
     * @param  int    $max  Maximum valid value.
     * @return int[]
     * @throws \invalid_parameter_exception When the expression is not valid for the field.
     */
    private static function parse_cron_field(string $expr, int $min, int $max): array {
        $result = [];
        foreach (explode(',', trim($expr)) as $part) {
            if (!preg_match('~^(\*|(\d+)(?:-(\d+))?)(?:/(\d+))?$~', $part, $match)) {
                throw new \invalid_parameter_exception("Invalid cron field '{$expr}'");
            }
            if ($match[1] === '*') {
                [$from, $to] = [$min, $max];
            } else {
                $from = (int) $match[2];
                $to = isset($match[3]) && $match[3] !== '' ? (int) $match[3] : $from;
            }
            $step = isset($match[4]) ? (int) $match[4] : 1;
            $single = $match[1] !== '*' && (!isset($match[3]) || $match[3] === '');
            if ($from < $min || $to > $max || $from > $to || $step < 1 || ($single && isset($match[4]))) {
                throw new \invalid_parameter_exception("Cron field '{$expr}' out of range {$min}-{$max}");
            }
            $result = array_merge($result, range($from, $to, $step));
        }
        return array_values(array_unique($result));
    }

    // ... ── Cache: Read ──────────────────────────────────────────────────────────

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

    // ... ── Cache: Write ─────────────────────────────────────────────────────────

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

    // ... ── Cache: Preview ─────────────────────────────────────────────────────────

    /**
     * Delete a single preview cache entry by context_hash.
     *
     * Preview entries have agentid = 0 (they are not attached to any agent).
     *
     * @param string $contexthash The cache key of the preview.
     * @param int|null $userid When given, the entry is deleted only if it is a preview of this user.
     * @return bool True if a row was deleted.
     */
    public static function clear_preview_cache(string $contexthash, ?int $userid = null): bool {
        global $DB;
        $conditions = ['agentid' => 0, 'context_hash' => $contexthash];
        if ($userid !== null) {
            $cached = $DB->get_record('local_jedomi_agent_cache', $conditions, 'id, result_json');
            $owner = $cached ? (json_decode((string) $cached->result_json, true)['userid'] ?? null) : null;
            if ($owner === null || (int) $owner !== $userid) {
                return false;
            }
        }
        if (!$DB->record_exists('local_jedomi_agent_cache', $conditions)) {
            return false;
        }
        $DB->delete_records('local_jedomi_agent_cache', $conditions);
        return true;
    }

    // ... ── Cache: Semantic Plan ──────────────────────────────────────────────────

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
            'site_overview',
            'ws_search',
            'ws_describe',
            'admin_search',
            'admin_describe',
        ];
        if (in_array($toolname, $readonly, true)) {
            return true;
        }

        // Ws_execute / call_webservice: check the actual WS being called.
        // This is handled at plan-recording time by inspecting arguments,
        // not by tool name alone — classify at call site.
        if ($toolname === 'ws_execute' || $toolname === 'call_webservice') {
            return false; // Conservatively assume write — caller must verify.
        }

        // Unknown tools default to NOT readonly (safe fallback).
        return false;
    }

    // ... ── Readable labels ─────────────────────────────────────────────────────────

    /** @var array<string, string> Schedules of the presets of the form described by their own string. */
    public const SCHEDULE_PRESETS = [
        '*/5 * * * *' => 'agent_every_5min',
        '*/15 * * * *' => 'agent_every_15min',
        '*/30 * * * *' => 'agent_every_30min',
        '0 * * * *' => 'agent_every_1h',
        '0 */6 * * *' => 'agent_every_6h',
    ];

    /**
     * Human description of a schedule, in the language of the current user: "Every day at 08:00", "Every Monday at
     * 07:30", "Every month on day 1 at 06:00", "Every hour at minute 15", the name of a preset, or the expression.
     *
     * @param string $cron Cron expression of the agent.
     * @return string
     */
    public static function describe_schedule(string $cron): string {
        $cron = trim(preg_replace('/\s+/', ' ', $cron));
        if ($cron === '') {
            return '-';
        }
        if (isset(self::SCHEDULE_PRESETS[$cron])) {
            return get_string(self::SCHEDULE_PRESETS[$cron], 'local_jedomi');
        }
        $parts = explode(' ', $cron);
        if (count($parts) === 5 && ctype_digit($parts[0]) && (int) $parts[0] <= 59) {
            [$minute, $hour, $day, $month, $weekday] = $parts;
            if ($hour === '*' && $day === '*' && $month === '*' && $weekday === '*') {
                return get_string('agent_cron_hourly_at', 'local_jedomi', (int) $minute);
            }
            if (ctype_digit($hour) && (int) $hour <= 23 && $month === '*') {
                $time = sprintf('%02d:%02d', (int) $hour, (int) $minute);
                if ($day === '*' && $weekday === '*') {
                    return get_string('agent_cron_daily_at', 'local_jedomi', $time);
                }
                $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                if ($day === '*' && ctype_digit($weekday) && (int) $weekday <= 7) {
                    $a = ['day' => get_string($days[(int) $weekday], 'calendar'), 'time' => $time];
                    return get_string('agent_cron_weekly_at', 'local_jedomi', $a);
                }
                if ($weekday === '*' && ctype_digit($day) && (int) $day >= 1 && (int) $day <= 31) {
                    return get_string('agent_cron_monthly_at', 'local_jedomi', ['day' => (int) $day, 'time' => $time]);
                }
            }
        }
        return get_string('agent_cron_custom', 'local_jedomi', $cron);
    }

    /**
     * Readable context of an agent: its type and the name of the course, category or user, in the current language.
     *
     * @param string $type Context type (site, course, category or user).
     * @param int|null $contextid Moodle context id of the agent.
     * @return string
     */
    public static function describe_context(string $type, ?int $contextid): string {
        $type = in_array($type, self::CONTEXT_TYPES, true) ? $type : 'site';
        $typelabel = get_string('agent_context_type_' . $type, 'local_jedomi');
        if ($type === 'site' || empty($contextid)) {
            return $typelabel;
        }
        $context = \context::instance_by_id((int) $contextid, IGNORE_MISSING);
        $name = $context ? $context->get_context_name(false) : '';
        if ($name === '') {
            return get_string('agent_context_missing', 'local_jedomi', ['type' => $typelabel, 'id' => (int) $contextid]);
        }
        return get_string('agent_context_label', 'local_jedomi', ['type' => $typelabel, 'name' => $name]);
    }

    /**
     * Localised names of roles given by their short names (custom names of the site included).
     *
     * @param string[] $shortnames Role short names.
     * @return array<string, string> Name by short name; an unknown short name keeps itself as name.
     */
    public static function role_labels(array $shortnames): array {
        global $DB;
        $labels = [];
        $roles = empty($shortnames) ? [] : $DB->get_records_list('role', 'shortname', array_values($shortnames));
        foreach ($roles as $role) {
            $labels[$role->shortname] = role_get_name($role, \context_system::instance(), ROLENAME_ALIAS);
        }
        foreach ($shortnames as $shortname) {
            $labels[$shortname] = $labels[$shortname] ?? $shortname;
        }
        return $labels;
    }

    // ... ── Stats ─────────────────────────────────────────────────────────────────

    /**
     * Data of the charts of the Agents tab, for the agents that match the given filters.
     *
     * The returned object contains:
     *  - by_role:        array of role shortname → count
     *  - by_status:      object {enabled: N, disabled: N}
     *  - executions_30d: array of {day: string, count: int}
     *  - role_labels:    readable name of each role shortname of by_role
     *  - status_labels:  readable name of each status of by_status
     *
     * @param  array $filters Same filter options as get_agents(), usually idsql with the filters of the agents report.
     * @return \stdClass
     */
    public static function get_chart_stats(array $filters): \stdClass {
        global $DB;

        // Every chart counts the agents that match the filters.
        [$wheresql, $params] = self::filter_where($filters);
        $matching = $DB->get_records_sql(
            "SELECT id, role_filter, enabled, lastrun FROM {local_jedomi_agents} {$wheresql}",
            $params
        );

        // ... ── By role: decode role_filter JSON and count per shortname. ────────
        $byrole = [];
        foreach ($matching as $a) {
            $roles = !empty($a->role_filter) ? json_decode($a->role_filter, true) : null;
            foreach (is_array($roles) ? $roles : [] as $role) {
                $role = trim((string) $role);
                $byrole[$role] = ($byrole[$role] ?? 0) + 1;
            }
        }
        arsort($byrole);

        // ... ── By status: aggregate enabled vs disabled. ────────────────────────
        $enabled = count(array_filter($matching, fn(\stdClass $a): bool => (int) $a->enabled === 1));
        $bystatus = (object) [
            'enabled' => $enabled,
            'disabled' => count($matching) - $enabled,
        ];

        // ... ── Executions per day (last 30 days, DB-agnostic). ──────────────────
        $thirtydaysago = time() - (30 * DAYSECS);
        $perday = self::runs_per_day_from_log(array_keys($matching), $thirtydaysago);
        if ($perday === null) {
            // Without the standard log only the last run of each agent is stored: each agent counts once, on that day.
            $perday = [];
            foreach ($matching as $run) {
                if (!empty($run->lastrun) && (int) $run->lastrun >= $thirtydaysago) {
                    $day = date('Y-m-d', (int) $run->lastrun);
                    $perday[$day] = ($perday[$day] ?? 0) + 1;
                }
            }
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

        return (object) [
            'by_role' => $byrole,
            'by_status' => $bystatus,
            'executions_30d' => $executions30d,
            // Readable names of the chart keys, in the language of the user.
            'role_labels' => (object) self::role_labels(array_keys($byrole)),
            'status_labels' => (object) [
                'enabled' => get_string('agent_enabled', 'local_jedomi'),
                'disabled' => get_string('agent_disabled', 'local_jedomi'),
            ],
        ];
    }

    /**
     * Copy of an agent as a user may see it in the details of the Agents tab: see agent_for_actor(), with the name of
     * its creator and its readable schedule and context.
     *
     * @param \stdClass $agent Agent record.
     * @param int $actorid User that reads the agent.
     * @return \stdClass Copy with ->user_fullname, ->schedule_label, ->context_label and ->approved_writes added.
     */
    public static function agent_details_for_actor(\stdClass $agent, int $actorid): \stdClass {
        $copy = self::agent_for_actor($agent, $actorid);
        $user = \core_user::get_user((int) $agent->userid);
        $copy->user_fullname = $user ? fullname($user) : '';
        $copy->schedule_label = self::describe_schedule((string) $agent->schedule_cron);
        $copy->context_label = self::describe_context(
            (string) ($agent->context_type ?? 'site'),
            empty($agent->context_id) ? null : (int) $agent->context_id
        );
        $copy->approved_writes = self::approved_writes($agent);
        return $copy;
    }

    /**
     * Runs per day of some agents since a moment, counted from the agent_run events of the standard log.
     *
     * @param int[] $agentids Agents to count.
     * @param int $since Earliest moment counted.
     * @return array<string, int>|null Runs keyed by day (Y-m-d); null when the standard log is not enabled.
     */
    public static function runs_per_day_from_log(array $agentids, int $since): ?array {
        global $DB;

        $stores = explode(',', (string) get_config('tool_log', 'enabled_stores'));
        if (!in_array('logstore_standard', $stores, true)) {
            return null;
        }
        $perday = [];
        foreach (array_chunk($agentids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'agent');
            $params['eventname'] = '\\' . \local_jedomi\event\agent_run::class;
            $params['since'] = $since;
            $runs = $DB->get_recordset_select(
                'logstore_standard_log',
                "eventname = :eventname AND timecreated >= :since AND objectid {$insql}",
                $params,
                '',
                'id, timecreated'
            );
            foreach ($runs as $run) {
                $day = date('Y-m-d', (int) $run->timecreated);
                $perday[$day] = ($perday[$day] ?? 0) + 1;
            }
            $runs->close();
        }
        return $perday;
    }

    // ... ── Purge ─────────────────────────────────────────────────────────────────

    /**
     * Delete the agents of a context: every agent of the site context, or the agents of one course, category or user.
     *
     * Associated cache rows are deleted via the delete_agent() method.
     *
     * @param  string   $type    Context type (site|course|category|user).
     * @param  int|null $id      Context instance id (ignored for the site context).
     * @param  int      $actorid User that purges: only the agents they may manage are deleted; 0 deletes every match.
     * @return int               Number of deleted agents.
     */
    public static function purge_by_context(string $type, ?int $id, int $actorid = 0): int {
        global $DB;

        $conditions = ['context_type' => $type];
        if ($type !== 'site') {
            $conditions['context_id'] = $id;
        }
        $agents = $DB->get_records('local_jedomi_agents', $conditions, '', 'id, userid');

        return self::delete_manageable($agents, $actorid);
    }

    /**
     * Delete all agents whose role_filter JSON contains the given role shortname.
     *
     * @param  string $role    Role shortname (e.g. "manager", "teacher").
     * @param  int    $actorid User that purges: only the agents they may manage are deleted; 0 deletes every match.
     * @return int             Number of deleted agents.
     */
    public static function purge_by_role(string $role, int $actorid = 0): int {
        global $DB;

        $like = $DB->sql_like('role_filter', ':role');
        $params = ['role' => '%"' . $DB->sql_like_escape($role) . '"%'];
        $agents = $DB->get_records_select('local_jedomi_agents', $like, $params, '', 'id, userid');

        return self::delete_manageable($agents, $actorid);
    }

    /**
     * Delete the given agents that a user may manage.
     *
     * @param \stdClass[] $agents Agents (need ->id and ->userid).
     * @param int $actorid User that deletes; 0 deletes every agent given.
     * @return int Number of deleted agents.
     */
    private static function delete_manageable(array $agents, int $actorid): int {
        $count = 0;
        foreach ($agents as $agent) {
            if ($actorid > 0 && !self::can_manage_agent($agent, $actorid)) {
                continue;
            }
            self::delete_agent((int) $agent->id);
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
