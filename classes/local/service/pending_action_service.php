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

namespace local_jedomi\local\service;

use local_jedomi\proxy\handler;

/**
 * Actions of the web chat that change data and wait for the user's confirmation.
 *
 * Jedomi, not the model, decides which tool needs confirmation: the action is stored here
 * with its arguments, the browser only gets its id and a description, and the action runs
 * when its owner confirms it within {@see EXPIRY} seconds, with the role the user has then.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pending_action_service {
    /** @var string Table name. */
    public const TABLE = 'local_jedomi_pending_actions';

    /** @var int Seconds an action can wait for confirmation. */
    public const EXPIRY = 900;

    /** @var int Seconds an action held by a scheduled agent waits for its creator, who is told by a notification. */
    public const AGENT_EXPIRY = 7 * DAYSECS;

    /** @var string[] Tool names the models use for the same tool, and the name Jedomi uses. */
    private const TOOL_ALIASES = [
        'create_agents' => 'create_agent',
        'delete_agents' => 'delete_agent',
        'list_agent' => 'list_agents',
        'call_webservice' => 'ws_execute',
    ];

    /** @var string Severity: the user must type the confirmation keyword. */
    public const SEVERITY_CRITICAL = 'critical';

    /** @var string Severity: changes site data or configuration. */
    public const SEVERITY_HIGH = 'high';

    /** @var string Severity: creates something or contacts users. */
    public const SEVERITY_MEDIUM = 'medium';

    /** @var string Status: the action ran. */
    public const STATUS_EXECUTED = 'executed';

    /** @var string Status: the user cancelled the action. */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var string Status: the action ran but did not succeed, or was not allowed. */
    public const STATUS_FAILED = 'failed';

    /**
     * @var int Characters of the data (or of the message of send_messages) shown in the web card. Every argument is shown
     *     whole (JEDOMI-SEC-013); only data longer than this is cut, and then the card says so and cannot be confirmed.
     */
    public const DETAILS_LENGTH = 20000;

    /** @var int Characters of the data shown in a Telegram card, which must fit in one Telegram message (4096). */
    public const TELEGRAM_DETAILS_LENGTH = 3000;

    /** @var string[] Words in a web service function name that mark it as destructive. */
    private const DESTRUCTIVE_WORDS = ['delete', 'remove', 'unenrol'];

    /**
     * @var string[] Words of a web service function name, after its component, that mark it as a write even when it is
     *     declared as reading (SEC-JD-039): a function wrongly declared as reading still needs confirmation.
     */
    private const WRITE_WORDS = ['add', 'archive', 'assign', 'change', 'create', 'delete', 'duplicate', 'edit', 'enrol',
        'import', 'insert', 'lock', 'mark', 'move', 'purge', 'remove', 'replace', 'reset', 'restore', 'revert', 'save',
        'send', 'set', 'submit', 'toggle', 'unassign', 'unblock', 'unenrol', 'unlock', 'update', 'upload', 'write'];

    /**
     * Severity of a tool call, or null when it runs without confirmation.
     *
     * @param string $tool Tool name as the model called it.
     * @param array $args Tool arguments.
     * @return string|null One of the SEVERITY_* constants, or null for reading tools.
     */
    public static function severity(string $tool, array $args): ?string {
        [$tool, $args] = self::normalise($tool, $args);
        switch ($tool) {
            case 'delete_agent':
            case 'delete_agents':
                return self::SEVERITY_CRITICAL;
            case 'admin_execute':
                return self::SEVERITY_HIGH;
            case 'create_agent':
            case 'create_agents':
            case 'send_messages':
                return self::SEVERITY_MEDIUM;
            case 'ws_execute':
                return self::ws_severity((string) ($args['wsname'] ?? $args['ws_name'] ?? ''));
            default:
                return null;
        }
    }

    /**
     * Canonical name and arguments of a tool call: plural or alternative names become the tool name Jedomi uses
     * (call_webservice is ws_execute), and the web service function, sent as "function", "ws_name" or "wsname", is
     * always in "wsname". Every check (offered tools, severity, approval, scope) works on this form.
     *
     * @param string $tool Tool name as the model called it.
     * @param array $args Tool arguments.
     * @return array Tool name and arguments, in that order.
     */
    public static function normalise(string $tool, array $args): array {
        $tool = self::TOOL_ALIASES[$tool] ?? $tool;
        if ($tool === 'ws_execute') {
            $wsname = $args['wsname'] ?? $args['ws_name'] ?? $args['function'] ?? '';
            unset($args['wsname'], $args['ws_name'], $args['function']);
            $args['wsname'] = is_string($wsname) ? trim($wsname) : '';
        }
        return [$tool, $args];
    }

    /**
     * Severity of a web service function: only functions declared as 'read' whose name has no write word run without
     * confirmation.
     *
     * Moodle sets the type only when db/services.php declares it, so a function with a missing or
     * unexpected type is treated as writing. A function declared as reading whose name, after its component, has a write
     * word (see WRITE_WORDS, whole words only) is treated as writing too (SEC-JD-039).
     *
     * @param string $wsname Web service function name.
     * @return string|null
     */
    private static function ws_severity(string $wsname): ?string {
        try {
            $info = \core_external\external_api::external_function_info($wsname, IGNORE_MISSING);
        } catch (\Throwable $e) {
            // The function is declared but cannot be described: be safe and ask.
            return self::SEVERITY_HIGH;
        }
        if (!$info) {
            // An unknown function fails in ws_execute anyway.
            return null;
        }
        if (($info->type ?? '') === 'read' && !self::has_write_word($wsname, (string) ($info->component ?? ''))) {
            return null;
        }
        foreach (self::DESTRUCTIVE_WORDS as $word) {
            if (stripos($wsname, $word) !== false) {
                return self::SEVERITY_CRITICAL;
            }
        }
        return self::SEVERITY_HIGH;
    }

    /**
     * Whether the name of a web service function, without its component, has a write word as a whole word: "add" in
     * mod_forum_can_add_discussion, but not "updates" in core_course_get_updates_since.
     *
     * @param string $wsname Function name.
     * @param string $component Its component ("moodle" for the core functions).
     * @return bool
     */
    private static function has_write_word(string $wsname, string $component): bool {
        $name = \core_text::strtolower($wsname);
        if ($component !== '' && str_starts_with($name, $component . '_')) {
            $name = substr($name, strlen($component) + 1);
        } else if (preg_match('/^core_[a-z0-9]+_(.*)$/', $name, $match)) {
            $name = $match[1];
        }
        return !empty(array_intersect(explode('_', $name), self::WRITE_WORDS));
    }

    /**
     * Hold a tool call that needs confirmation.
     *
     * The action is stored in its canonical form (see normalise()), so it runs through the same tool when it is
     * confirmed. A scheduled agent never holds the same action twice for the same user (REL-002): the action it already
     * holds is returned instead.
     *
     * @param int $userid User who must confirm it.
     * @param string $tool Tool name.
     * @param array $args Tool arguments.
     * @param int $agentid Scheduled agent that proposed it (its creator confirms it in the web chat); 0 for the chat.
     * @param int $courseid Course the role of the user was computed in when it was held (the signed page course);
     *                      0 outside a course. Kept server side: the confirmation computes the role in it again.
     * @return array|null Data for the confirmation card (id, tool, severity, description, details, and
     *                    count and preview for send_messages), or null when the tool needs no confirmation.
     */
    public static function hold(int $userid, string $tool, array $args, int $agentid = 0, int $courseid = 0): ?array {
        global $DB;

        [$tool, $args] = self::normalise($tool, $args);
        $severity = self::severity($tool, $args);
        if ($severity === null) {
            return null;
        }

        $argsjson = json_encode($args, JSON_UNESCAPED_UNICODE);
        $id = $agentid > 0 ? self::held_by_agent($userid, $agentid, $tool, $args) : 0;
        if ($id === 0) {
            $id = (int) $DB->insert_record(self::TABLE, (object) [
                'userid' => $userid,
                'agentid' => $agentid,
                'courseid' => $courseid > SITEID ? $courseid : 0,
                'tool' => \core_text::substr($tool, 0, 64),
                'argsjson' => $argsjson,
                'severity' => $severity,
                'timecreated' => time(),
            ]);
        }

        return ['id' => $id] + self::card($userid, $tool, $args, $severity);
    }

    /**
     * Id of the same action, still valid, that an agent already holds for a user, or 0 (REL-002).
     *
     * @param int $userid User who must confirm it.
     * @param int $agentid Agent that holds it.
     * @param string $tool Canonical tool name.
     * @param array $args Canonical arguments.
     * @return int
     */
    private static function held_by_agent(int $userid, int $agentid, string $tool, array $args): int {
        global $DB;
        $fingerprint = self::fingerprint($tool, $args);
        $records = $DB->get_records(self::TABLE, ['userid' => $userid, 'agentid' => $agentid, 'tool' => $tool], 'id ASC');
        foreach ($records as $record) {
            $stored = json_decode((string) $record->argsjson, true);
            if (!self::is_expired($record) && is_array($stored) && self::fingerprint($tool, $stored) === $fingerprint) {
                return (int) $record->id;
            }
        }
        return 0;
    }

    /**
     * Fingerprint of an action: its tool and its arguments with the keys of their objects sorted, the parameters of a
     * web service or administration operation decoded when they come as JSON.
     *
     * @param string $tool Canonical tool name.
     * @param array $args Canonical arguments.
     * @return string
     */
    private static function fingerprint(string $tool, array $args): string {
        if (isset($args['params']) && is_string($args['params'])) {
            $decoded = json_decode($args['params'], true);
            $args['params'] = is_array($decoded) ? $decoded : $args['params'];
        }
        $sorted = static function ($value) use (&$sorted) {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
            return array_map($sorted, $value);
        };
        return hash('sha256', $tool . '|' . json_encode($sorted($args), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Data of the confirmation card of an action, in readable language: what it does, all its data as "field: value"
     * lines and, for send_messages, the recipients that will really get the message and the message.
     *
     * Every argument is shown whole (JEDOMI-SEC-013). Only data longer than the limit is cut; then truncated is true and
     * the action can only be cancelled.
     *
     * @param int $userid User who would run the action.
     * @param string $tool Tool name.
     * @param array $args Tool arguments.
     * @param string $severity One of the SEVERITY_* constants.
     * @param int $limit Characters of the data shown: DETAILS_LENGTH (web) or TELEGRAM_DETAILS_LENGTH.
     * @return array Keys tool, severity, description, details and truncated, plus count and preview for send_messages.
     */
    public static function card(
        int $userid,
        string $tool,
        array $args,
        string $severity,
        int $limit = self::DETAILS_LENGTH
    ): array {
        $details = self::readable_details($tool, $args);
        $card = [
            'tool' => $tool,
            'severity' => $severity,
            'description' => self::describe($userid, $tool, $args),
            'details' => \core_text::substr($details, 0, $limit),
            'truncated' => \core_text::strlen($details) > $limit,
        ];
        if ($tool === 'send_messages') {
            $message = trim((string) ($args['message'] ?? ''));
            $card['count'] = count(message_service::contactable($userid, $args['user_ids'] ?? []));
            $card['preview'] = \core_text::substr($message, 0, $limit);
            $card['details'] = '';
            $card['truncated'] = \core_text::strlen($message) > $limit;
        }
        return $card;
    }

    /**
     * Cards of the actions a user still has to confirm in the web chat, oldest first, so the panel shows them again
     * after a page reload. Expired actions are left out.
     *
     * @param int $userid User id.
     * @return array[] Cards as hold() returns them.
     */
    public static function cards_for_user(int $userid): array {
        global $DB;
        $records = array_filter(self::get_for_user($userid), static fn(\stdClass $record): bool => !self::is_expired($record));
        $agentids = array_unique(array_filter(array_map(static fn(\stdClass $record): int => (int) $record->agentid, $records)));
        // The names of the agents are read in one query, not one per action (RES-004).
        $names = empty($agentids) ? [] : $DB->get_records_list('local_jedomi_agents', 'id', $agentids, '', 'id, name');
        $cards = [];
        foreach ($records as $record) {
            $agentid = (int) $record->agentid;
            if ($agentid > 0 && !isset($names[$agentid])) {
                // The agent was deleted: its writes can no longer be approved (the cleanup removes them).
                continue;
            }
            $args = json_decode((string) $record->argsjson, true);
            $card = ['id' => (int) $record->id]
                + self::card($userid, (string) $record->tool, is_array($args) ? $args : [], (string) $record->severity);
            if ($agentid > 0) {
                // The creator is told which of their agents proposed the action.
                $name = trim((string) $names[$agentid]->name);
                $card['description'] = get_string('pending_from_agent', 'local_jedomi', [
                    'agent' => $name !== '' ? $name : '#' . $agentid,
                    'description' => $card['description'],
                ]);
            }
            $cards[] = $card;
        }
        return $cards;
    }

    /**
     * Whether a held action can no longer be confirmed: actions of the chat wait EXPIRY seconds, actions held by a
     * scheduled agent wait AGENT_EXPIRY seconds.
     *
     * @param \stdClass $record Pending action record.
     * @return bool
     */
    public static function is_expired(\stdClass $record): bool {
        $expiry = empty($record->agentid) ? self::EXPIRY : self::AGENT_EXPIRY;
        return (int) $record->timecreated < time() - $expiry;
    }

    /**
     * Data of an action as readable "field: value" lines: the parameters of a web service or administration
     * operation, or the arguments of the other tools, without JSON. Lists are numbered; every value is shown whole
     * (JEDOMI-SEC-013), card() cuts the text only when it is longer than its limit.
     *
     * @param string $tool Tool name.
     * @param array $args Tool arguments.
     * @return string Lines separated by new lines; empty when there is nothing to show.
     */
    public static function readable_details(string $tool, array $args): string {
        $values = $args;
        if ($tool === 'ws_execute' || $tool === 'admin_execute') {
            // The function or operation name is already in the description: only its parameters are listed.
            $params = $args['params'] ?? [];
            if (is_string($params)) {
                $decoded = json_decode($params, true);
                $params = is_array($decoded) ? $decoded : ($params === '' ? [] : ['params' => $params]);
            }
            $values = is_array($params) ? $params : [];
        }
        $lines = [];
        self::detail_lines($values, '', $lines);
        return implode("\n", $lines);
    }

    /**
     * Add the "field: value" lines of a value to a list, walking into nested lists and objects.
     *
     * @param mixed $value Value to describe.
     * @param string $label Label of the value; empty at the top level.
     * @param string[] $lines Lines collected so far.
     * @return void
     */
    private static function detail_lines($value, string $label, array &$lines): void {
        if (is_array($value)) {
            $islist = array_is_list($value);
            foreach ($value as $key => $item) {
                $name = $islist ? '#' . ((int) $key + 1) : ucfirst(str_replace('_', ' ', (string) $key));
                self::detail_lines($item, $label === '' ? $name : $label . ' › ' . $name, $lines);
            }
            return;
        }
        if (is_bool($value)) {
            $text = get_string($value ? 'yes' : 'no');
        } else if ($value === null) {
            $text = '-';
        } else {
            $text = trim((string) $value);
        }
        $lines[] = $label === '' ? $text : $label . ': ' . $text;
    }

    /**
     * Short description of a held action, in the user's language.
     *
     * @param int $userid User who would run the action.
     * @param string $tool Tool name.
     * @param array $args Tool arguments.
     * @return string
     */
    private static function describe(int $userid, string $tool, array $args): string {
        switch ($tool) {
            case 'delete_agent':
            case 'delete_agents':
                return get_string('pending_desc_delete_agent', 'local_jedomi', (int) ($args['id'] ?? 0));
            case 'create_agent':
            case 'create_agents':
                $label = trim((string) ($args['name'] ?? '')) ?: trim((string) ($args['prompt'] ?? ''));
                return get_string('pending_desc_create_agent', 'local_jedomi', shorten_text($label, 80));
            case 'admin_execute':
                return get_string('pending_desc_admin_execute', 'local_jedomi', (string) ($args['name'] ?? ''));
            case 'send_messages':
                return get_string(
                    'pending_desc_send_messages',
                    'local_jedomi',
                    count(message_service::contactable($userid, $args['user_ids'] ?? []))
                );
            default:
                return get_string(
                    'pending_desc_ws_execute',
                    'local_jedomi',
                    (string) ($args['wsname'] ?? $args['ws_name'] ?? $tool)
                );
        }
    }

    /**
     * Confirm or cancel a held action of a user.
     *
     * The action is removed before it runs, so it can run only once. It runs with the role
     * the user has now: a user who lost the permission gets a "denied" result.
     *
     * A critical action runs only when the confirmation keyword is received; without it the action
     * is rejected and kept, so the user can still confirm it with the keyword.
     *
     * A write held by a scheduled agent is approved only by the creator of the agent (RISK-003): the user it was held for,
     * while the agent still exists and still belongs to them. Nobody approves it on their behalf, whatever their rank;
     * a site administrator may only cancel it. There is no delegation of this approval.
     *
     * An action whose data is too long to be shown whole in the card is never confirmed, only cancelled (JEDOMI-SEC-013).
     *
     * @param int $id Pending action id.
     * @param int $userid User resolving it (must be its owner; a site administrator may also cancel a write held by an
     *     agent).
     * @param bool $confirm True to run it, false to cancel it.
     * @param string $keyword Confirmation keyword typed by the user (critical actions).
     * @return array{status: string, message: string, sent: int, failed: int}
     * @throws \moodle_exception pending_action_not_found, pending_action_not_creator, pending_action_expired,
     *     pending_action_too_long or confirm_keyword_required.
     */
    public static function resolve(int $id, int $userid, bool $confirm, string $keyword = ''): array {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        $owner = $record && (int) $record->userid === $userid;
        $admincancel = $record && !$confirm && !empty($record->agentid) && is_siteadmin($userid);
        if (!$owner && !$admincancel) {
            throw new \moodle_exception('pending_action_not_found', 'local_jedomi');
        }
        if ($confirm && !empty($record->agentid)) {
            $creatorid = $DB->get_field('local_jedomi_agents', 'userid', ['id' => (int) $record->agentid]);
            if ($creatorid === false || (int) $creatorid !== $userid) {
                // The agent was deleted or now belongs to someone else: its former creator cannot approve its writes.
                throw new \moodle_exception('pending_action_not_creator', 'local_jedomi');
            }
        }

        if (self::is_expired($record)) {
            $DB->delete_records(self::TABLE, ['id' => $record->id]);
            throw new \moodle_exception('pending_action_expired', 'local_jedomi');
        }
        if ($confirm) {
            $stored = json_decode((string) $record->argsjson, true);
            $card = self::card($userid, (string) $record->tool, is_array($stored) ? $stored : [], (string) $record->severity);
            if ($card['truncated']) {
                // The user could not see everything the action does.
                throw new \moodle_exception('pending_action_too_long', 'local_jedomi');
            }
        }
        if ($confirm && $record->severity === self::SEVERITY_CRITICAL && !self::is_confirm_keyword($keyword)) {
            throw new \moodle_exception('confirm_keyword_required', 'local_jedomi');
        }
        $DB->delete_records(self::TABLE, ['id' => $record->id]);
        self::action_event($confirm, (string) $record->tool, 'web', $userid);
        if (!$confirm) {
            return self::result(self::STATUS_CANCELLED, get_string('confirm_cancelled', 'local_jedomi'));
        }

        $args = json_decode($record->argsjson, true);
        // The role is computed again in the course stored when the action was held, never in one sent with the
        // confirmation, so a teacher of that course without an enrolment keeps the role (unit 7b).
        $role = self::role_in_course($userid, (int) ($record->courseid ?? 0));
        try {
            $decoded = json_decode(handler::run_tool($record->tool, is_array($args) ? $args : [], $role), true);
        } catch (\Throwable $e) {
            $decoded = ['error' => 'tool_failed'];
        }
        return self::summarise($record->tool, is_array($decoded) ? $decoded : ['error' => 'invalid_result']);
    }

    /**
     * Jedomi role of a user in the course a held action belongs to, as it was computed when it was held.
     *
     * A course excluded from Jedomi gives no role: the role is then the one outside a course.
     *
     * @param int $userid User.
     * @param int $courseid Course stored with the held action; 0 or the site course outside a course.
     * @return string 'admin', 'manager', 'teacher' or 'student'.
     */
    public static function role_in_course(int $userid, int $courseid): string {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        if ($courseid > SITEID && !\local_jedomi\course_access::is_course_in_jedomi($courseid)) {
            $courseid = 0;
        }
        return \local_jedomi_user_role($userid, $courseid);
    }

    /**
     * Course of a tool call of Telegram, where there is no page: the courseid of its arguments when the user can access
     * that course and it is in Jedomi, or 0.
     *
     * @param int $userid User.
     * @param array $args Tool arguments.
     * @return int
     */
    public static function course_of_args(int $userid, array $args): int {
        global $DB;
        $courseid = isset($args['courseid']) && is_numeric($args['courseid']) ? (int) $args['courseid'] : 0;
        if ($courseid <= SITEID || !$DB->record_exists('course', ['id' => $courseid])
                || !\local_jedomi\course_access::is_course_in_jedomi($courseid)) {
            return 0;
        }
        $context = \context_course::instance($courseid);
        return is_enrolled($context, $userid, '', true) || has_capability('moodle/course:view', $context, $userid)
            || has_capability('moodle/course:manageactivities', $context, $userid) ? $courseid : 0;
    }

    /**
     * Trigger the event of a confirmed or cancelled action; its arguments are never part of the event.
     *
     * @param bool $confirm True when the action was confirmed, false when it was cancelled.
     * @param string $tool Name of the action (tool).
     * @param string $channel Channel of the conversation: web or telegram.
     * @param int $userid User who resolved the action.
     * @return void
     */
    public static function action_event(bool $confirm, string $tool, string $channel, int $userid): void {
        $class = $confirm ? \local_jedomi\event\action_confirmed::class : \local_jedomi\event\action_cancelled::class;
        $class::create([
            'context' => \context_system::instance(),
            'userid' => $userid,
            'other' => ['action' => $tool, 'channel' => $channel],
        ])->trigger();
    }

    /**
     * Whether a typed text is the confirmation keyword of critical actions (the confirm button label, in capitals).
     *
     * @param string $keyword Text typed by the user.
     * @return bool
     */
    public static function is_confirm_keyword(string $keyword): bool {
        $expected = \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi'));
        return $expected !== '' && \core_text::strtoupper(trim($keyword)) === $expected;
    }

    /**
     * Short result of a tool run for the user; internal errors are never shown.
     *
     * @param string $tool Tool name.
     * @param array $decoded Decoded tool result.
     * @return array{status: string, message: string, sent: int, failed: int}
     */
    private static function summarise(string $tool, array $decoded): array {
        if (isset($decoded['error'])) {
            $denied = is_string($decoded['error']) && str_starts_with($decoded['error'], 'permission_denied');
            return self::result(
                self::STATUS_FAILED,
                get_string($denied ? 'pending_action_denied' : 'pending_action_failed', 'local_jedomi')
            );
        }
        if ($tool !== 'send_messages') {
            return self::result(self::STATUS_EXECUTED, get_string('confirm_done', 'local_jedomi'));
        }

        $sent = (int) ($decoded['sent'] ?? 0);
        $failed = (int) ($decoded['failed'] ?? 0);
        if ($sent === 0) {
            return self::result(self::STATUS_FAILED, get_string('no_messages_sent', 'local_jedomi'), 0, $failed);
        }
        $message = str_replace('{sent}', (string) $sent, get_string('messages_sent', 'local_jedomi'));
        if ($failed > 0) {
            $message .= ' ' . str_replace('{failed}', (string) $failed, get_string('messages_sent_fail', 'local_jedomi'));
        }
        return self::result(self::STATUS_EXECUTED, $message, $sent, $failed);
    }

    /**
     * Build a result.
     *
     * @param string $status One of the STATUS_* constants.
     * @param string $message Message for the user.
     * @param int $sent Messages sent (send_messages only).
     * @param int $failed Messages not sent (send_messages only).
     * @return array{status: string, message: string, sent: int, failed: int}
     */
    private static function result(string $status, string $message, int $sent = 0, int $failed = 0): array {
        return ['status' => $status, 'message' => $message, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Remove actions that were not resolved in time, and the writes held by agents that no longer exist (REL-002).
     *
     * @return int Number of actions removed.
     */
    public static function purge_expired(): int {
        global $DB;
        $where = '(agentid = 0 AND timecreated < :cutoff) OR (agentid > 0 AND timecreated < :agentcutoff)'
            . ' OR (agentid > 0 AND NOT EXISTS (SELECT 1 FROM {local_jedomi_agents} a WHERE a.id = agentid))';
        $params = ['cutoff' => time() - self::EXPIRY, 'agentcutoff' => time() - self::AGENT_EXPIRY];
        $count = $DB->count_records_select(self::TABLE, $where, $params);
        $DB->delete_records_select(self::TABLE, $where, $params);
        return $count;
    }

    /**
     * Whether a user has held actions.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function user_has_actions(int $userid): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, ['userid' => $userid]);
    }

    /**
     * Held actions of a user, oldest first.
     *
     * @param int $userid User id.
     * @return \stdClass[]
     */
    public static function get_for_user(int $userid): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated ASC, id ASC'));
    }

    /**
     * Delete the writes an agent held for its creator, when the agent is deleted (REL-002).
     *
     * @param int $agentid Agent id.
     * @return void
     */
    public static function delete_for_agent(int $agentid): void {
        global $DB;
        if ($agentid > 0) {
            $DB->delete_records(self::TABLE, ['agentid' => $agentid]);
        }
    }

    /**
     * Delete the held actions of some users.
     *
     * @param int[] $userids User ids.
     */
    public static function delete_for_users(array $userids): void {
        global $DB;
        if (empty($userids)) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::TABLE, "userid {$insql}", $params);
    }
}
