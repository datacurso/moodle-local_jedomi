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
 * Channel-neutral tool runner: the tools a messaging channel offers to the AI and their execution.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_jedomi\local\anonymizer;
use local_jedomi\local\service\pending_action_service;
use local_jedomi\schema\table_indexer;
use local_jedomi\schema\ws_indexer;

/**
 * Tools of a messaging channel (Telegram, WhatsApp): the course context of the user, the signed permission token
 * (SPT) with the tools of the channel, and the execution of a tool.
 *
 * The channel gives its name (the channel of the tool definitions and of the database query limits, see
 * local_jedomi_get_tool_definitions() and query_runner), the label named when a tool is not supported, and the closed
 * list of the tools it can run; any other tool is never offered to the AI in the channel. The role used for
 * authorization is always derived from the user id, never taken from the caller.
 */
class tool_runner {
    /**
     * Constructor.
     *
     * @param string $channel Channel name, e.g. telegram.
     * @param string $label Channel name shown to the user, e.g. Telegram.
     * @param string[] $supportedtools Tools the channel can run.
     */
    public function __construct(
        /** @var string Channel name. */
        private readonly string $channel,
        /** @var string Channel name shown to the user. */
        private readonly string $label,
        /** @var string[] Tools the channel can run. */
        private readonly array $supportedtools,
    ) {
    }

    /**
     * Channel name of the runner.
     *
     * @return string
     */
    public function channel(): string {
        return $this->channel;
    }

    /**
     * Tools of the role offered in the channel: the tool definitions of the role for the channel, limited to the
     * tools the channel can run.
     *
     * @param string $role Jedomi role (admin|manager|teacher|student).
     * @param int $userid Moodle user id the tools are offered to.
     * @return array Tool definitions in function-calling format.
     */
    public function tool_definitions(string $role, int $userid): array {
        $supported = $this->supportedtools;
        return array_values(array_filter(
            \local_jedomi_get_tool_definitions($role, $userid, $this->channel),
            static fn(array $tool): bool => in_array($tool['function']['name'] ?? '', $supported, true)
        ));
    }

    /**
     * Current role of the user and their courses grouped by the role held in each one.
     *
     * The role is computed as in the web chat: only site administrators and system user managers are
     * administrators and managers, so a role or permission in a course never makes the user a manager.
     * The courses are those with an active enrolment or a role assigned in the course, without the site
     * home and the courses excluded from Jedomi, and each one is grouped by the highest role the user holds in it.
     *
     * @param  int   $userid
     * @return array  'role', 'course_ids_owned', 'course_ids_enrolled', 'courses_by_role'
     */
    public function user_context(int $userid): array {
        global $DB;

        $courses = [];
        foreach (enrol_get_users_courses($userid, true, ['id', 'fullname']) as $course) {
            $courses[(int) $course->id] = $course;
        }
        // Courses where the user has a role without an enrolment.
        $rows = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.fullname
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :level
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ra.userid = :userid",
            ['level' => CONTEXT_COURSE, 'userid' => $userid]
        );
        foreach ($rows as $row) {
            $courses[(int) $row->id] = $courses[(int) $row->id] ?? $row;
        }
        unset($courses[SITEID]);
        // Excluded courses are outside Jedomi: never listed, signed or named to the AI.
        $courses = array_filter(
            $courses,
            static fn(int $courseid): bool => \local_jedomi\course_access::is_course_in_jedomi($courseid),
            ARRAY_FILTER_USE_KEY
        );

        $rank = ['student' => 1, 'teacher' => 2, 'manager' => 3];
        $courseidsowned = [];
        $courseidsenrolled = [];
        $coursesbyrole = [];
        foreach ($courses as $courseid => $course) {
            $context = \context_course::instance($courseid);
            $group = null;
            foreach (get_user_roles($context, $userid, false) as $assignment) {
                $archetype = $DB->get_field('role', 'archetype', ['id' => $assignment->roleid]) ?: $assignment->shortname;
                $candidate = match ($archetype) {
                    'manager' => 'manager',
                    'editingteacher', 'teacher' => 'teacher',
                    'student' => 'student',
                    default => null,
                };
                if ($candidate !== null && ($group === null || $rank[$candidate] > $rank[$group])) {
                    $group = $candidate;
                }
            }
            if ($group === null) {
                // No role of a known kind in this course: it gives no access to report.
                continue;
            }
            $coursesbyrole[$group][] = $course->fullname;
            if (has_capability('moodle/course:manageactivities', $context, $userid)) {
                $courseidsowned[] = $courseid;
            } else {
                $courseidsenrolled[] = $courseid;
            }
        }

        return [
            'role'              => \local_jedomi_get_user_role_for_user($userid),
            'course_ids_owned'  => $courseidsowned,
            'course_ids_enrolled' => $courseidsenrolled,
            'courses_by_role'   => $coursesbyrole,
        ];
    }

    /**
     * Generate a signed permission token (SPT) for a specific user
     * (not the global $USER), with the tools of the channel.
     *
     * @param  int    $userid The user ID to generate the SPT for.
     * @param  string $role   The Jedomi role shortname (admin|manager|teacher|student).
     * @param  array  $courseidsowned The course ids the user manages.
     * @param  array  $courseidsenrolled The course ids the user is enrolled in.
     * @return array          The signed SPT payload, or empty array if user not found/deleted.
     */
    public function signed_permissions(
        int $userid,
        string $role,
        array $courseidsowned = [],
        array $courseidsenrolled = []
    ): array {
        global $CFG, $DB;

        $sysctx = \context_system::instance();
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, username, firstname, lastname');
        if (!$user) {
            return [];
        }

        // Capabilities for this specific user (not global $USER).
        $capabilities = [
            'can_view_all_grades'   => has_capability('moodle/grade:viewall', $sysctx, $userid),
            'can_manage_users'      => has_capability('moodle/user:update', $sysctx, $userid),
            'can_send_messages'     => has_capability('moodle/site:sendmessage', $sysctx, $userid),
            'can_manage_courses'    => has_capability('moodle/course:update', $sysctx, $userid),
            'can_view_reports'      => has_capability('moodle/course:viewhiddenactivities', $sysctx, $userid),
            'can_enrol_users'       => has_capability('enrol/manual:enrol', $sysctx, $userid),
            'can_access_all_groups' => has_capability('moodle/site:accessallgroups', $sysctx, $userid),
        ];

        // Course lists — use pre-computed if provided, otherwise compute.
        // This avoids redundant calls when the caller already has the user context.
        if (empty($courseidsowned) && empty($courseidsenrolled) && $role !== 'admin') {
            if ($role === 'teacher' || $role === 'manager') {
                $courses = enrol_get_users_courses($userid, true, ['id']);
                foreach ($courses as $c) {
                    $ctx = \context_course::instance($c->id);
                    if (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
                        $courseidsowned[] = (int)$c->id;
                    }
                }
            } else if ($role === 'student') {
                $courses = enrol_get_users_courses($userid, true, ['id']);
                foreach ($courses as $c) {
                    $courseidsenrolled[] = (int)$c->id;
                }
            }
        }
        // Admin: no course list (full access).
        // Excluded courses are outside Jedomi: they are never signed as courses of the user.
        $courseidsowned = \local_jedomi\course_access::filter_course_ids($courseidsowned);
        $courseidsenrolled = \local_jedomi\course_access::filter_course_ids($courseidsenrolled);

        // Only the tools of the role that the channel can really run are offered.
        $tools = $this->tool_definitions($role, $userid);

        $payload = [
            'userid'              => $userid,
            'username'            => $user->username,
            'role'                => $role,
            'course_id'           => 0,
            'course_ids_owned'    => $courseidsowned,
            'course_ids_enrolled' => $courseidsenrolled,
            'capabilities'        => $capabilities,
            'tools'               => $tools,
            'moodle_url'          => $CFG->wwwroot,
            'expires'             => time() + 1800,
        ];

        // Same HMAC signing as the existing generate_spt function.
        $staticsecret = \local_jedomi_get_spt_secret();

        ksort($payload);
        $signature = hash_hmac('sha256', \local_jedomi_spt_signing_string($payload), $staticsecret);

        $payload['signature'] = $signature;
        return $payload;
    }

    /**
     * Execute a tool by name with given arguments.
     * Respects role-based access — only safe read operations.
     *
     * SECURITY: the caller-supplied $role is NEVER trusted for authorization.
     * It is immediately overwritten with the role re-derived from $userid
     * (the only value taken from the authenticated chat link), so a spoofed
     * role string cannot bypass role-gated tools. $userid <= 0 always
     * derives to '' (denies every role-gated tool).
     *
     * @param string $name Tool name to execute
     * @param array $args Tool arguments
     * @param int $userid Moodle user id executing the tool
     * @param string $role Ignored for authorization; kept for signature compatibility.
     * @param array $courseidsowned Course ids the user manages
     * @param anonymizer|null $anonymizer Anonymiser of the conversation; the rows of a database query are anonymised
     *                                    by column name with it before they are formatted.
     * @param int $courseid Course stored with a confirmed held action: the role is computed in it; 0 outside a course.
     * @return string Tool execution result, in the HTML subset of the Telegram messages
     */
    public function execute(
        string $name,
        array $args,
        int $userid = 0,
        string $role = '',
        array $courseidsowned = [],
        ?anonymizer $anonymizer = null,
        int $courseid = 0
    ): string {
        global $DB, $CFG;

        // Re-derive the authoritative role from $userid — never trust the
        // caller-supplied $role string for authorization decisions.
        $role = self::authoritative_role($userid, $courseid);

        switch ($name) {
            case 'describe_table':
                // Only the roles that can query the database see table structures; sensitive tables are never described.
                if (!\local_jedomi\local\query_runner::role_can_query($role)) {
                    return get_string('telegram_permission_denied', 'local_jedomi');
                }
                $tablename = $args['tablename'] ?? '';
                if (empty($tablename)) {
                    return get_string('telegram_table_name_required', 'local_jedomi');
                }
                if (\local_jedomi\local\sql_guard::is_sensitive_table(preg_replace('/^\{(\w+)\}$/', '$1', $tablename))) {
                    return get_string('telegram_permission_denied', 'local_jedomi');
                }
                $columns = $DB->get_columns($tablename);
                if (!$columns) {
                    return get_string('table_not_found', 'local_jedomi', s($tablename));
                }
                $result = [];
                foreach ($columns as $col) {
                    $result[] = "• {$col->name} ({$col->type})" . ($col->meta_type ? " [{$col->meta_type}]" : '');
                }
                return "📋 <b>" . s($tablename) . "</b> (" . count($result) . " columns):\n" . implode("\n", $result);

            case 'search_tables':
                // Table names, like table structures, only for the roles that can query the database.
                if (!\local_jedomi\local\query_runner::role_can_query($role)) {
                    return get_string('telegram_permission_denied', 'local_jedomi');
                }
                $query = $args['query'] ?? '';
                $limit = min((int)($args['limit'] ?? 10), 20);
                if (empty($query)) {
                    return get_string('telegram_search_query_required', 'local_jedomi');
                }
                $entries = table_indexer::search($query, $limit);
                $result = [];
                foreach ($entries as $entry) {
                    $line = "• <b>" . s($entry['name']) . "</b>";
                    if (!empty($entry['comment'])) {
                        $line .= " — " . s($entry['comment']);
                    }
                    $result[] = $line;
                }
                if (empty($result)) {
                    return get_string('telegram_no_tables_found', 'local_jedomi', s($query));
                }
                return "📚 <b>Tables</b> (" . count($result) . "):\n" . implode("\n", $result);

            case 'execute_sql':
                // SQL is ONLY SELECT — safe read-only.
                $sql = $args['sql'] ?? $args['query'] ?? '';
                if (empty($sql)) {
                    return get_string('telegram_sql_query_required', 'local_jedomi');
                }
                $sqlupper = strtoupper(trim($sql));
                if (!preg_match('/^SELECT\s/i', $sqlupper)) {
                    return '❌ ' . get_string('non_select_rejected', 'local_jedomi');
                }

                // ... ── Role gate shared with the web chat — admin/manager/teacher only ──
                if (!\local_jedomi\local\query_runner::role_can_query($role)) {
                    return get_string('telegram_permission_denied', 'local_jedomi');
                }

                // Normalize Moodle table placeholders (FROM course → FROM {course}).
                $sql = preg_replace_callback(
                    '/\b(FROM|JOIN)\s+([a-z][a-z0-9_]*)\b/i',
                    static function (array $matches): string {
                        return $matches[1] . ' {' . $matches[2] . '}';
                    },
                    $sql
                );

                $params = $args['params'] ?? [];
                if (!is_array($params)) {
                    $params = [$params];
                }

                // ... ── Guard, timeout, row cap and sensitive columns shared with the web chat ──
                $maxrows = \local_jedomi\local\query_runner::row_limit($this->channel);
                try {
                    $result = \local_jedomi\local\query_runner::run($sql, $params, $userid, $maxrows, $this->channel);
                } catch (\moodle_exception $e) {
                    // JEDOMI-SEC-009: the first line of the database error, never the query or its parameters.
                    return '❌ ' . \local_jedomi\local\safe_error::for_model($e);
                }

                // ... ── Date/time column detection ──
                $datecolumns = [
                    'timecreated', 'timemodified', 'time', 'timeend', 'timestart',
                    'lastaccess', 'lastlogin', 'firstaccess', 'timeaccessed',
                    'timecompleted', 'timedue', 'timesent', 'timeopen', 'timeclose',
                    'deadline', 'date', 'created', 'modified', 'updated', 'deleted',
                    'startdate', 'enddate',
                ];
                $datekeys = [];
                $dateformat = get_string('strftimedatetime', 'core_langconfig');

                // Fetch user for timezone-aware date formatting.
                $user = null;
                if ($userid > 0) {
                    $user = \core_user::get_user($userid);
                }

                $rows = [];
                foreach ($result['rows'] as $row) {
                    // Detect timestamp-like columns from the first row's keys.
                    if (empty($datekeys)) {
                        foreach ((array)$row as $key => $val) {
                            if (in_array($key, $datecolumns, true)) {
                                $datekeys[] = $key;
                            }
                        }
                    }

                    $fields = [];
                    if ($anonymizer !== null) {
                        $row = $anonymizer->anonymize_row($row);
                    }
                    foreach ($row as $key => $val) {
                        // Format timestamp columns as human-readable dates.
                        $istimestamp = in_array($key, $datekeys, true)
                            && (is_int($val) || (is_string($val) && ctype_digit($val)))
                            && (int)$val > 0;
                        if ($istimestamp) {
                            $val = userdate($val, $dateformat, $user);
                        } else {
                            $val = is_string($val) ? shorten_text($val, 60) : $val;
                        }
                        $fields[] = s($key) . ': ' . s($val);
                    }
                    $rows[] = "• " . implode(', ', $fields);
                }
                if ($result['truncated']) {
                    $rows[] = '⚠️ More than ' . $maxrows . ' rows returned. Showing first ' . $maxrows . '.';
                }

                if (empty($rows)) {
                    return "Query returned no results.";
                }
                return "📊 <b>Results</b> (" . count($rows) . " row(s)):\n" . implode("\n", $rows);

            case 'get_site_config':
                // ... ── Role gate: admin only (matches lib.php:498) ──
                if ($role !== 'admin') {
                    return json_encode(['error' => 'permission_denied']);
                }
                $keys = $args['keys'] ?? [];
                if (empty($keys) || !is_array($keys)) {
                    return "❌ Key list required.";
                }
                // Allowlist matching web version (external/get_site_config.php:37-51).
                $allowlist = [
                    'sitename', 'siteurl', 'wwwroot', 'lang', 'country', 'timezone',
                    'maxbytes', 'passwordpolicy', 'minpasswordlength',
                    'passwordminlength', 'passwordmaxlength',
                    'enrol_plugins_enabled', 'auth_plugins_enabled', 'smtphosts',
                ];
                $allowset = array_flip($allowlist);
                $output = [];
                foreach ($keys as $k) {
                    // Silently skip non-allowed keys (matching web version behaviour).
                    if (!isset($allowset[$k])) {
                        continue;
                    }
                    $v = get_config('core', $k);
                    // Fallback: model often asks for "siteurl" but Moodle stores it as "wwwroot".
                    if ($v === false && $k === 'siteurl') {
                        $v = get_config('core', 'wwwroot');
                    }
                    if ($v === false) {
                        $output[] = "• <b>" . s($k) . "</b> = (not found)";
                    } else {
                        $output[] = "• <b>" . s($k) . "</b> = " . s($v);
                    }
                }
                if (empty($output)) {
                    return "⚙️ <b>Site Config</b>: No matching keys found.";
                }
                return "⚙️ <b>Site Config</b>:\n" . implode("\n", $output);

            case 'site_overview':
                // Admin and manager only, with the role derived above; aggregates only, no personal data.
                return \local_jedomi\agent\tool\site_overview_tool::run($args, $role);

            case 'ws_search':
                $query = $args['query'] ?? '';
                $limit = min((int)($args['limit'] ?? 10), 20);
                if (empty($query)) {
                    return get_string('telegram_search_query_required', 'local_jedomi');
                }
                // Only the functions the role may call: its list, the course rule and the declared capabilities.
                $allowed = \local_jedomi\local\ws_runner::searchable_functions($role, $userid);
                if ($allowed === []) {
                    return get_string('note_ws_none_configured', 'local_jedomi');
                }
                $entries = ws_indexer::search($query, $allowed ?? [], $limit);
                $results = [];
                foreach ($entries as $entry) {
                    $line = "• <b>" . s($entry['name']) . "</b> (" . s($entry['component']) . ")"
                        . " — " . s($entry['description']);
                    // Append param signature like the web version does.
                    if (!empty($entry['params']) && $entry['params'] !== '(none)') {
                        $line .= "\n   Params: " . s($entry['params']);
                    }
                    if (!empty($entry['returns']) && $entry['returns'] !== 'void') {
                        $line .= "\n   Returns: " . s($entry['returns']);
                    }
                    $results[] = $line;
                }
                if (empty($results)) {
                    return "No web services found matching: " . s($query);
                }
                return "🔌 <b>Web Services</b> (" . count($results) . "):\n" . implode("\n", $results);

            case 'ws_describe':
                $wsname = $args['wsname'] ?? '';
                if (empty($wsname)) {
                    return "Web service name required.";
                }
                if (!\local_jedomi\local\ws_runner::describable($role, (string) $wsname, $userid)) {
                    return json_encode(['error' => 'permission_denied: function not in allowlist']);
                }
                $info = ws_indexer::describe($wsname);
                if (isset($info['error'])) {
                    return "Web service not found: " . s($wsname);
                }

                $result = "🔌 <b>" . s($info['name']) . "</b>\n";
                if (!empty($info['description'])) {
                    $result .= s($info['description']) . "\n";
                }
                $result .= "Component: " . s($info['component']) . " | Type: " . s($info['type']) . "\n";
                if (!empty($info['params']) && $info['params'] !== '(none)') {
                    $result .= "Parameters: " . s($info['params']) . "\n";
                }
                if (!empty($info['returns']) && $info['returns'] !== 'void') {
                    $result .= "Returns: " . s($info['returns']);
                }
                return $result;

            case 'ws_execute':
                $wsname = $args['wsname'] ?? $args['ws_name'] ?? '';
                $raw    = $args['params'] ?? '{}';
                $params = is_array($raw) ? json_encode($raw) : $raw;

                if (empty($wsname)) {
                    return json_encode(['error' => 'Web service name required.']);
                }

                // ... ── Gate 1: role list shared with the web chat and the companion web service ──
                if (!\local_jedomi\local\ws_runner::role_allows($role, $wsname)) {
                    return json_encode(['error' => 'WS not in allowlist for role: ' . $role]);
                }

                // ... ── Gate 2: Resolve target WS method ──────────────────────────────
                try {
                    $method = \local_jedomi\local\ws_runner::resolve($wsname);
                } catch (\moodle_exception $e) {
                    return json_encode(['error' => 'WS not found: ' . $wsname]);
                }
                $decoded = json_decode($params, true);
                if (!is_array($decoded)) {
                    $decoded = [];
                }
                $missing = \local_jedomi\local\ws_runner::missing_parameter($method, $decoded);
                if ($missing !== null) {
                    $got = implode(', ', array_keys($decoded));
                    return json_encode([
                        'error' => 'ws_execute_param_mismatch',
                        'ws' => $wsname,
                        'expected_params' => array_map(
                            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
                            $method->getParameters()
                        ),
                        'provided_params' => array_keys($decoded),
                        'hint' => "WS expects parameter '{$missing}'. Provided: [{$got}]. "
                            . "Use ws_describe('{$wsname}') to see the exact structure.",
                    ]);
                }

                // ... ── Gate 3: call the WS method (native capabilities inside) ──────
                // The call runs in a delegated transaction, committed only when it succeeds, because the webhook does
                // not go through external_api::call_external_function() (its validate_context() needs a web page).
                try {
                    $result = \local_jedomi\local\ws_runner::call($method, $decoded, true);
                } catch (\Throwable $e) {
                    return json_encode(['error' => 'Error in ' . $wsname . ': ' . \local_jedomi\local\safe_error::for_model($e)]);
                }
                $json = json_encode($result);
                if (mb_strlen($json) > 2048) {
                    $json = mb_substr($json, 0, 2048) . "\n... (truncated)";
                }
                return $json;

            case 'admin_search':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $query   = trim($args['query'] ?? '');
                $limit   = min((int)($args['limit'] ?? 8), 20);
                $results = \local_jedomi\schema\cli_registry::search($query, $limit);
                return json_encode(['results' => $results, 'count' => count($results)]);

            case 'admin_describe':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $name  = trim($args['name'] ?? '');
                $entry = \local_jedomi\schema\cli_registry::describe($name);
                return json_encode($entry);

            case 'admin_execute':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $name   = trim($args['name'] ?? '');
                $params = $args['params'] ?? [];
                $result = \local_jedomi\schema\cli_registry::execute($name, $params, $userid);
                return json_encode($result);

            case 'create_agents':   // Model sometimes hallucinates plural form.
            case 'create_agent':
                // The role and the capability of the agents page are both required, as in the web chat.
                if (
                    !in_array($role, ['admin', 'manager'], true)
                        || !has_capability('local/jedomi:manageagents', \context_system::instance(), $userid)
                ) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $prompt = trim($args['prompt'] ?? '');
                $schedule = trim($args['schedule_cron'] ?? '');
                if (empty($prompt) || empty($schedule)) {
                    return "❌ Both prompt and schedule_cron are required.";
                }
                $data = new \stdClass();
                $data->userid = $userid;
                $data->name = trim($args['name'] ?? '');
                $data->prompt = $prompt;
                $data->schedule_cron = $schedule;
                $data->context_type = $args['context_type'] ?? 'site';
                $data->context_id = isset($args['context_id']) ? (int) $args['context_id'] : null;
                $data->enabled = isset($args['enabled']) ? (int) $args['enabled'] : 1;
                // The schedule and the context (a Moodle context id of the chosen type) are validated as in the form.
                $invalid = \local_jedomi\agent\agent_manager::validation_error($data);
                if ($invalid !== null) {
                    return '❌ ' . s($invalid);
                }
                $id = \local_jedomi\agent\agent_manager::create_agent($data);
                $agent = \local_jedomi\agent\agent_manager::get_agent($id);
                $nextrun = $agent ? userdate($agent->nextrun) : '?';
                $label = (!empty($agent->name)) ? $agent->name : "Agent #{$id}";
                return "✅ <b>{$label}</b> created\n"
                    . "📝 " . s(mb_substr($prompt, 0, 100)) . (mb_strlen($prompt) > 100 ? '…' : '') . "\n"
                    . "⏱ {$schedule}\n"
                    . "📍 {$data->context_type}" . ($data->context_id ? " #{$data->context_id}" : '') . "\n"
                    . "📅 Next run: {$nextrun}";

            case 'list_agent':     // Model sometimes hallucinates singular form.
            case 'list_agents':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $filters = [];
                if (!empty($args['status'])) {
                    $filters['status'] = $args['status'];
                }
                $result = \local_jedomi\agent\agent_manager::get_agents($filters);
                $agents = $result['items'] ?? [];
                $total = $result['total'] ?? 0;
                if (empty($agents)) {
                    return "📭 No agents found" . (!empty($filters['status']) ? " ({$filters['status']})" : '') . ".";
                }
                $lines = [];
                $lines[] = "🤖 <b>Agents</b> ({$total}):";
                foreach ($agents as $a) {
                    // The instruction is given only to the users who may read it.
                    $a = \local_jedomi\agent\agent_manager::agent_for_actor($a, (int) $userid);
                    $statusicon = $a->enabled ? '🟢' : '⚪';
                    $next = $a->nextrun ? userdate($a->nextrun) : '?';
                    // Always show agent ID so the model can use delete_agent(id).
                    $name = !empty($a->name) ? s($a->name) : "Agent #{$a->id}";
                    // Truncate long prompts to keep the tool result manageable; hidden ones are never given.
                    $excerpt = $a->prompthidden ? get_string('agent_prompt_hidden', 'local_jedomi')
                        : s(mb_substr($a->prompt, 0, 60));
                    $lines[] = "{$statusicon} <b>[ID {$a->id}] {$name}</b>"
                        . " — {$excerpt}… —— {$a->schedule_cron} — next: {$next}";
                }
                return implode("\n", $lines);

            case 'delete_agents':   // Model sometimes hallucinates plural form.
            case 'delete_agent':
                if (!in_array($role, ['admin', 'manager'], true)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $id = (int) ($args['id'] ?? 0);
                if ($id <= 0) {
                    return "❌ Valid agent ID required.";
                }
                $agent = \local_jedomi\agent\agent_manager::get_agent($id);
                if (!$agent) {
                    return "❌ Agent #{$id} not found.";
                }
                if (!\local_jedomi\agent\agent_manager::can_manage_agent($agent, (int) $userid)) {
                    return json_encode(['error' => 'permission_denied']);
                }
                $label = !empty($agent->name) ? $agent->name : "Agent #{$id}";
                \local_jedomi\agent\agent_manager::delete_agent($id);
                return "🗑️ <b>{$label} deleted</b> — " . s(mb_substr($agent->prompt, 0, 60));

            case 'my_courses':
            case 'my_grades':
            case 'my_assignments':
                // Always the data of the linked user, whatever the arguments say; no linked user reads nothing.
                return \local_jedomi\agent\tool\own_data_tool::run($name, $args, $userid);

            default:
                return "❌ Tool '" . s($name) . "' is not supported via " . $this->label . ". Try the web interface.";
        }
    }

    /**
     * Canonical name of a tool, mapping the plural or singular forms models sometimes use.
     *
     * @param string $name Tool name as the model called it.
     * @return string
     */
    public static function canonical_tool_name(string $name): string {
        return match ($name) {
            'create_agents' => 'create_agent',
            'delete_agents' => 'delete_agent',
            'list_agent' => 'list_agents',
            default => $name,
        };
    }

    /**
     * Re-derive the authoritative Jedomi role for a userid, bypassing any caller-supplied role string.
     *
     * It is computed on every call, as in the web chat, so a role removed from the user stops applying at once.
     *
     * @param  int $userid Moodle user id. <= 0 always denies (returns '').
     * @param  int $courseid Course stored server side with a held action; 0 outside a course.
     * @return string The authoritative role ('admin'|'manager'|'teacher'|'student'|'').
     */
    public static function authoritative_role(int $userid, int $courseid = 0): string {
        if ($userid <= 0) {
            return '';
        }
        return pending_action_service::role_in_course($userid, $courseid);
    }
}
