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

    /** @var int Characters of the message shown in the send_messages card. */
    private const PREVIEW_LENGTH = 120;

    /** @var int Characters of the arguments shown in the card. */
    private const DETAILS_LENGTH = 500;

    /** @var int Lines of the arguments shown in the card; the rest is replaced by an ellipsis. */
    private const DETAIL_LINES = 12;

    /** @var string[] Words in a web service function name that mark it as destructive. */
    private const DESTRUCTIVE_WORDS = ['delete', 'remove', 'unenrol'];

    /**
     * Severity of a tool call, or null when it runs without confirmation.
     *
     * @param string $tool Tool name as the model called it.
     * @param array $args Tool arguments.
     * @return string|null One of the SEVERITY_* constants, or null for reading tools.
     */
    public static function severity(string $tool, array $args): ?string {
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
     * Severity of a web service function: only functions declared as 'read' run without confirmation.
     *
     * Moodle sets the type only when db/services.php declares it, so a function with a missing or
     * unexpected type is treated as writing.
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
        if (($info->type ?? '') === 'read') {
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
     * Hold a tool call that needs confirmation.
     *
     * @param int $userid User who must confirm it.
     * @param string $tool Tool name.
     * @param array $args Tool arguments.
     * @return array|null Data for the confirmation card (id, tool, severity, description, details, and
     *                    count and preview for send_messages), or null when the tool needs no confirmation.
     */
    public static function hold(int $userid, string $tool, array $args): ?array {
        global $DB;

        $severity = self::severity($tool, $args);
        if ($severity === null) {
            return null;
        }

        $id = (int) $DB->insert_record(self::TABLE, (object) [
            'userid' => $userid,
            'tool' => \core_text::substr($tool, 0, 64),
            'argsjson' => json_encode($args, JSON_UNESCAPED_UNICODE),
            'severity' => $severity,
            'timecreated' => time(),
        ]);

        return ['id' => $id] + self::card($userid, $tool, $args, $severity);
    }

    /**
     * Data of the confirmation card of an action, in readable language: what it does, its data as "field: value"
     * lines and, for send_messages, the recipients that will really get the message and a preview of it.
     *
     * @param int $userid User who would run the action.
     * @param string $tool Tool name.
     * @param array $args Tool arguments.
     * @param string $severity One of the SEVERITY_* constants.
     * @return array Keys tool, severity, description and details, plus count and preview for send_messages.
     */
    public static function card(int $userid, string $tool, array $args, string $severity): array {
        $card = [
            'tool' => $tool,
            'severity' => $severity,
            'description' => self::describe($userid, $tool, $args),
            'details' => self::readable_details($tool, $args),
        ];
        if ($tool === 'send_messages') {
            $card['count'] = count(message_service::contactable($userid, $args['user_ids'] ?? []));
            $card['preview'] = \core_text::substr(trim((string) ($args['message'] ?? '')), 0, self::PREVIEW_LENGTH);
            $card['details'] = '';
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
        $cards = [];
        foreach (self::get_for_user($userid) as $record) {
            if ((int) $record->timecreated < time() - self::EXPIRY) {
                continue;
            }
            $args = json_decode((string) $record->argsjson, true);
            $cards[] = ['id' => (int) $record->id]
                + self::card($userid, (string) $record->tool, is_array($args) ? $args : [], (string) $record->severity);
        }
        return $cards;
    }

    /**
     * Data of an action as readable "field: value" lines: the parameters of a web service or administration
     * operation, or the arguments of the other tools, without JSON. Lists are numbered and long values shortened.
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
        if (count($lines) > self::DETAIL_LINES) {
            $lines = array_slice($lines, 0, self::DETAIL_LINES);
            $lines[] = '…';
        }
        return \core_text::substr(implode("\n", $lines), 0, self::DETAILS_LENGTH);
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
            $text = shorten_text(trim((string) $value), 80);
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
     * @param int $id Pending action id.
     * @param int $userid User resolving it (must be its owner).
     * @param bool $confirm True to run it, false to cancel it.
     * @param string $keyword Confirmation keyword typed by the user (critical actions).
     * @return array{status: string, message: string, sent: int, failed: int}
     * @throws \moodle_exception pending_action_not_found, pending_action_expired or confirm_keyword_required.
     */
    public static function resolve(int $id, int $userid, bool $confirm, string $keyword = ''): array {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $id, 'userid' => $userid]);
        if (!$record) {
            throw new \moodle_exception('pending_action_not_found', 'local_jedomi');
        }

        if ((int) $record->timecreated < time() - self::EXPIRY) {
            $DB->delete_records(self::TABLE, ['id' => $record->id]);
            throw new \moodle_exception('pending_action_expired', 'local_jedomi');
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
        $role = \local_jedomi_get_user_role_for_user($userid);
        try {
            $decoded = json_decode(handler::run_tool($record->tool, is_array($args) ? $args : [], $role), true);
        } catch (\Throwable $e) {
            $decoded = ['error' => 'tool_failed'];
        }
        return self::summarise($record->tool, is_array($decoded) ? $decoded : ['error' => 'invalid_result']);
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
     * Remove actions that were not resolved in time.
     *
     * @return int Number of actions removed.
     */
    public static function purge_expired(): int {
        global $DB;
        $where = 'timecreated < :cutoff';
        $params = ['cutoff' => time() - self::EXPIRY];
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
