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
 * Channel-neutral confirmations: hold, find, resolve and audit the actions that change data.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_jedomi\local\service\pending_action_service;

/**
 * Actions of a messaging channel (Telegram, WhatsApp) that change data and wait until the user confirms them.
 *
 * A tool call that changes data is held in the table of the channel (same shape as
 * local_jedomi_telegram_pending_actions) and shown with the confirmation_ui of the channel. A medium or high action is
 * confirmed with the confirm button or the confirmation word; a critical one only with the word. An administrator or
 * manager who needs a PIN (see pin_policy) confirms only with it, except in the 15 minutes after a right PIN. Only a
 * channel whose PIN policy is available can ask for the PIN; any other channel (no_pin_policy) refuses the writes of a
 * user who needs a PIN before holding them and never runs them. The PIN texts come from the policy. An action runs
 * once, only for the user still linked to the chat and still served by the channel, never after it expires, through
 * the tool runner of the channel; confirmations and cancellations are recorded as events with the channel name.
 */
class confirmation_service {
    /** @var string A held action is cancelled. */
    public const MODE_CANCEL = 'cancel';

    /** @var string A held action is confirmed with the confirm button. */
    public const MODE_BUTTON = 'button';

    /** @var string A held action is confirmed with the confirmation word typed in the chat. */
    public const MODE_KEYWORD = 'keyword';

    /** @var string A held action is confirmed with the right PIN typed in the chat. */
    public const MODE_PIN = 'pin';

    /** @var string Start of the id of a held action while a request claims it (see claim()). */
    private const CLAIMED_PREFIX = 'claimed:';

    /**
     * Constructor.
     *
     * @param string $table Table of the held actions of the channel.
     * @param string $label Channel name shown to the user and in the log, e.g. Telegram.
     * @param confirmation_ui $ui How the channel shows the actions.
     * @param access_guard $guard Access rules of the channel.
     * @param tool_runner $tools Tool runner of the channel; its channel name is recorded with each answer.
     * @param \Closure $linkeduser Id of the user linked to a chat key: fn(string $chatkey): ?int.
     * @param pin_policy $pins PIN of the channel: who needs one, whether the channel can ask for it, and its texts.
     */
    public function __construct(
        /** @var string Table of the held actions. */
        private readonly string $table,
        /** @var string Channel name shown to the user. */
        private readonly string $label,
        /** @var confirmation_ui How the channel shows the actions. */
        private readonly confirmation_ui $ui,
        /** @var access_guard Access rules of the channel. */
        private readonly access_guard $guard,
        /** @var tool_runner Tool runner of the channel. */
        private readonly tool_runner $tools,
        /** @var \Closure Id of the user linked to a chat key. */
        private readonly \Closure $linkeduser,
        /** @var pin_policy PIN of the channel. */
        private readonly pin_policy $pins,
    ) {
    }

    /**
     * Confirmation UI of the channel.
     *
     * @return confirmation_ui
     */
    public function ui(): confirmation_ui {
        return $this->ui;
    }

    /**
     * PIN policy of the channel.
     *
     * @return pin_policy
     */
    public function pins(): pin_policy {
        return $this->pins;
    }

    /**
     * Table of the held actions of the channel.
     *
     * @return string
     */
    public function table(): string {
        return $this->table;
    }

    /**
     * Hold a tool call that changes data until the user confirms it with the buttons of the chat, the confirmation word
     * or, for an administrator or manager who needs a PIN and has not typed it in the last 15 minutes, the PIN.
     *
     * @param string $chatkey Chat key of the channel.
     * @param int $userid Linked Moodle user.
     * @param string $name Tool name.
     * @param array $args Tool arguments.
     * @return string|null Result for the AI when the call is held or refused, or null for a tool that changes nothing.
     */
    public function hold(string $chatkey, int $userid, string $name, array $args): ?string {
        global $DB;

        $severity = pending_action_service::severity($name, $args);
        if ($severity === null) {
            return null;
        }
        $askpin = false;
        if ($this->pins->is_required($userid)) {
            $refusal = $this->pin_refusal($chatkey, $userid);
            if ($refusal !== null) {
                return $refusal;
            }
            // A right PIN in the last 15 minutes only skips asking it again: the write is still held and shown with its
            // card, and the user confirms it with the button or the confirmation word (JEDOMI-SEC-016).
            $askpin = !$this->pins->in_window($userid);
        }
        $card = pending_action_service::card($userid, $name, $args, $severity, $this->ui->details_length());
        if ($card['truncated']) {
            // The user could not see in the channel everything the action does, so it is not held here (JEDOMI-SEC-013).
            return json_encode(['error' => 'action_too_long_for_' . $this->tools->channel(),
                'note' => 'The data of this action is too long to show in ' . $this->label . '. Tell the user to ask for '
                    . 'it in the Jedomi web chat. Do not call it again.']);
        }
        $actionid = random_string(16);
        $DB->insert_record($this->table, (object) [
            'callbackid' => $actionid,
            'chatid' => $chatkey,
            'actionjson' => json_encode(['name' => $name, 'params' => $args, 'userid' => $userid], JSON_UNESCAPED_UNICODE),
            // Course the role is computed in when it is confirmed, decided here from the server's checks (unit 7b).
            'courseid' => pending_action_service::course_of_args($userid, $args),
            'timecreated' => time(),
        ]);

        $this->ui->show($chatkey, new confirmation_card($actionid, $name, $severity, $askpin, $card));
        if ($askpin) {
            return json_encode(['status' => 'awaiting_confirmation',
                'note' => 'The action waits for the user to type their PIN in the chat. Do not call it again.']);
        }
        $note = $severity === pending_action_service::SEVERITY_CRITICAL
            ? 'The action waits for the user to type the confirmation word in the chat. Do not call it again.'
            : 'The action waits for the user to confirm it with the buttons of the chat. Do not call it again.';
        return json_encode(['status' => 'awaiting_confirmation', 'note' => $note]);
    }

    /**
     * Id of the newest action of a chat that is still waiting for confirmation.
     *
     * @param string $chatkey Chat key of the channel.
     * @return string|null
     */
    public function waiting(string $chatkey): ?string {
        global $DB;
        [$select, $params] = $this->waiting_select($chatkey);
        $records = $DB->get_records_select(
            $this->table,
            $select,
            $params,
            'timecreated DESC, id DESC',
            'id, callbackid',
            0,
            1
        );
        $record = reset($records);
        return $record ? (string) $record->callbackid : null;
    }

    /**
     * Id of the newest critical action of a chat that is still waiting for the confirmation word.
     *
     * @param string $chatkey Chat key of the channel.
     * @return string|null
     */
    public function waiting_critical(string $chatkey): ?string {
        global $DB;
        [$select, $params] = $this->waiting_select($chatkey);
        $records = $DB->get_records_select($this->table, $select, $params, 'timecreated DESC, id DESC');
        foreach ($records as $record) {
            if (self::is_critical(json_decode((string) $record->actionjson, true))) {
                return (string) $record->callbackid;
            }
        }
        return null;
    }

    /**
     * Confirm or cancel a held action of a chat. The action runs once, only for the user who still has the chat linked
     * and is served by the channel, and never after it expires.
     *
     * A critical action runs only with the confirmation word typed in the chat; a confirm button for it (from an
     * older message) is rejected and the action is kept. An administrator or manager who needs a PIN confirms only
     * with it (Decision 7), except in the 15 minutes after a right PIN, when the button or the word confirms.
     *
     * @param string $chatkey Chat key of the channel.
     * @param string $actionid Id of the held action.
     * @param string $mode How the user answered: one of the MODE_* constants.
     * @return string Message for the user.
     */
    public function resolve(string $chatkey, string $actionid, string $mode): string {
        global $DB, $USER;

        // A claimed id is never answered, so a crafted or leaked token cannot claim the action again.
        $claimed = str_starts_with($actionid, self::CLAIMED_PREFIX);
        $pending = $claimed ? false : $DB->get_record($this->table, ['callbackid' => $actionid, 'chatid' => $chatkey]);
        if (!$pending) {
            return get_string('pending_action_not_found', 'local_jedomi');
        }
        if ((int) $pending->timecreated < time() - pending_action_service::EXPIRY) {
            $DB->delete_records($this->table, ['id' => $pending->id]);
            return get_string('pending_action_expired', 'local_jedomi');
        }
        $action = json_decode($pending->actionjson, true);
        $linkeduserid = ($this->linkeduser)($chatkey);
        $confirm = $mode !== self::MODE_CANCEL;
        if (
            in_array($mode, [self::MODE_BUTTON, self::MODE_KEYWORD], true) && $linkeduserid !== null
                && $this->pins->is_required($linkeduserid)
                && !$this->pins->in_window($linkeduserid)
        ) {
            // Outside the 15 minutes after a right PIN, only the PIN confirms (Decision 7, JEDOMI-SEC-016).
            return $this->ui->pin_prompt();
        }
        if ($mode === self::MODE_BUTTON && self::is_critical($action)) {
            return $this->ui->keyword_prompt();
        }
        // Claimed and removed before it runs, so it runs only once even when two answers read it at the same time.
        if (!$this->claim($pending)) {
            return get_string('pending_action_not_found', 'local_jedomi');
        }
        if (!$confirm) {
            if ($linkeduserid !== null) {
                $tool = (string) ($action['name'] ?? '');
                pending_action_service::action_event(false, $tool, $this->tools->channel(), $linkeduserid);
            }
            return get_string('confirm_cancelled', 'local_jedomi');
        }

        $user = $linkeduserid !== null ? $this->guard->active_user($linkeduserid) : null;
        if (
            !is_array($action) || !$user || (int) ($action['userid'] ?? 0) !== (int) $user->id
                || !$this->guard->role_allowed((int) $user->id)
        ) {
            return get_string('pending_action_denied', 'local_jedomi');
        }

        $USER = $user;
        pending_action_service::action_event(true, (string) ($action['name'] ?? ''), $this->tools->channel(), (int) $user->id);
        try {
            $result = $this->tools->execute(
                (string) ($action['name'] ?? ''),
                (array) ($action['params'] ?? []),
                (int) $user->id,
                '',
                [],
                null,
                (int) ($pending->courseid ?? 0)
            );
        } catch (\Throwable $e) {
            return get_string('pending_action_failed', 'local_jedomi');
        }
        $decoded = json_decode($result, true);
        if (is_array($decoded) && isset($decoded['error'])) {
            $denied = is_string($decoded['error']) && str_starts_with($decoded['error'], 'permission_denied');
            return get_string($denied ? 'pending_action_denied' : 'pending_action_failed', 'local_jedomi');
        }
        return get_string('confirm_done', 'local_jedomi');
    }

    /**
     * Check a PIN typed in the chat and run the held action with it. The PIN is never logged nor sent to the AI; the
     * channel removes the message with the PIN from the chat when it can. In a channel that cannot ask for the PIN no
     * PIN is ever checked and the action is not run.
     *
     * @param string $chatkey Chat key of the channel.
     * @param int $userid Linked Moodle user.
     * @param string $pin Typed PIN.
     * @param string $actionid Id of the action waiting in the chat.
     * @return string Message for the user.
     */
    public function confirm_with_pin(string $chatkey, int $userid, string $pin, string $actionid): string {
        if (!$this->pins->available()) {
            \local_jedomi_log($this->label . ': PIN refused, the channel has no PIN user=' . self::userref($userid));
            return get_string('pending_action_denied', 'local_jedomi');
        }
        $check = $this->pins->verify($userid, $pin);
        switch ($check['result']) {
            case pin_policy::RESULT_OK:
                return $this->resolve($chatkey, $actionid, self::MODE_PIN);
            case pin_policy::RESULT_WRONG:
                \local_jedomi_log($this->label . ': wrong PIN user=' . self::userref($userid));
                return $this->pins->wrong_message($check['remaining']);
            case pin_policy::RESULT_LOCKED:
                \local_jedomi_log($this->label . ': PIN lockout user=' . self::userref($userid));
                return $this->pins->locked_message($this->pins->locked_until($userid));
            default:
                return $this->pins->missing_message();
        }
    }

    /**
     * Refuse a write of a user who needs a PIN and cannot confirm one now: the channel cannot ask for it, no PIN set
     * yet (with a link to set it), or the writes are locked after too many wrong PINs. The user is told in the chat,
     * except in a channel without the PIN, where the AI tells the user to use the web chat.
     *
     * @param string $chatkey Chat key of the channel.
     * @param int $userid Linked Moodle user.
     * @return string|null Result for the AI when the write is refused, or null when the user can confirm with the PIN.
     */
    private function pin_refusal(string $chatkey, int $userid): ?string {
        if (!$this->pins->available()) {
            \local_jedomi_log($this->label . ': write refused, PIN required but not available user=' . self::userref($userid));
            return json_encode(['status' => 'refused', 'reason' => 'pin_unsupported',
                'note' => 'Changes of this user need a PIN, which ' . $this->label . ' cannot ask for. Tell the user to '
                    . 'make the change in the Jedomi web chat. Do not call it again.']);
        }
        if (!$this->pins->has_pin($userid)) {
            \local_jedomi_log($this->label . ': write refused, PIN required but not set user=' . self::userref($userid));
            $this->ui->notify($chatkey, $this->pins->missing_message());
            return json_encode(['status' => 'refused', 'reason' => 'pin_required',
                'note' => 'The user must set a ' . $this->label . ' PIN in Moodle before any change. Do not call it again.']);
        }
        $lockeduntil = $this->pins->locked_until($userid);
        if ($lockeduntil > 0) {
            \local_jedomi_log($this->label . ': write refused, PIN lockout user=' . self::userref($userid));
            $this->ui->notify($chatkey, $this->pins->locked_message($lockeduntil));
            return json_encode(['status' => 'refused', 'reason' => 'pin_locked',
                'note' => 'Changes are locked after too many wrong PINs. Do not call it again.']);
        }
        return null;
    }

    /**
     * Condition for the actions of a chat still waiting: not expired and not claimed.
     *
     * @param string $chatkey Chat key of the channel.
     * @return array The SQL condition and its parameters.
     */
    private function waiting_select(string $chatkey): array {
        global $DB;
        $notclaimed = $DB->sql_like('callbackid', ':claimed', true, true, true);
        return [
            "chatid = :chatid AND timecreated >= :cutoff AND {$notclaimed}",
            [
                'chatid' => $chatkey,
                'cutoff' => time() - pending_action_service::EXPIRY,
                'claimed' => $DB->sql_like_escape(self::CLAIMED_PREFIX) . '%',
            ],
        ];
    }

    /**
     * Claim a held action for this request and remove it. Only one of several requests that read the action at the
     * same time claims it: each one swaps the id of the action for a random token of its own only while the id is
     * still there, which the database does for one request at a time, so only the request that finds its own token
     * afterwards has the action. The others get false, as if the action had already been answered.
     *
     * A row left claimed by a request that died is never offered again (waiting(), waiting_critical() and resolve()
     * skip it) and is deleted by the cleanup task of the channel (Telegram: \local_jedomi\task\cleanup_telegram_sessions)
     * with the other held actions older than 5 minutes, whatever its callbackid. A new channel must clean its table the
     * same way.
     *
     * @param \stdClass $pending Held action as the request read it.
     * @return bool Whether this request claimed the action.
     */
    private function claim(\stdClass $pending): bool {
        global $DB;
        $token = self::CLAIMED_PREFIX . random_string(32);
        $DB->set_field_select(
            $this->table,
            'callbackid',
            $token,
            'id = :id AND callbackid = :callbackid',
            ['id' => $pending->id, 'callbackid' => $pending->callbackid]
        );
        if (!$DB->record_exists($this->table, ['id' => $pending->id, 'callbackid' => $token])) {
            return false;
        }
        $DB->delete_records($this->table, ['id' => $pending->id, 'callbackid' => $token]);
        return true;
    }

    /**
     * Whether a decoded held action is critical.
     *
     * @param mixed $action Decoded actionjson.
     * @return bool
     */
    private static function is_critical($action): bool {
        return is_array($action) && pending_action_service::severity(
            (string) ($action['name'] ?? ''),
            (array) ($action['params'] ?? [])
        ) === pending_action_service::SEVERITY_CRITICAL;
    }

    /**
     * Pseudonym of a user for the log.
     *
     * @param int $userid Moodle user id.
     * @return string
     */
    private static function userref(int $userid): string {
        return \local_jedomi_log_pseudonym('user', $userid);
    }
}
