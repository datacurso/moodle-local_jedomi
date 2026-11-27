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

namespace local_jedomi\local\service;

/**
 * Links between Moodle users and WhatsApp identities, keyed by the business-scoped user id (BSUID) of Meta.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class whatsapp_link_service {
    /** @var string Table of the links. */
    public const LINKS_TABLE = 'local_jedomi_whatsapp_links';

    /** @var string Table of the conversations. */
    public const SESSIONS_TABLE = 'local_jedomi_whatsapp_sessions';

    /** @var string Table of the actions waiting for confirmation. */
    public const PENDING_TABLE = 'local_jedomi_whatsapp_pending';

    /** @var string Table of the message limits. */
    public const RATE_TABLE = 'local_jedomi_whatsapp_rate';

    /** @var string Table of the received messages. */
    public const INBOX_TABLE = 'local_jedomi_whatsapp_inbox';

    /** @var string Table of the monthly usage counters. */
    public const USAGE_TABLE = 'local_jedomi_whatsapp_usage';

    /** @var string Prefix of the chat key of a BSUID. */
    private const CHATKEY_PREFIX = 'wa_';

    /** @var string Prefix of the session row that keeps the tool loop state of a chat (see conversation_engine). */
    public const TOOL_LOOP_PREFIX = 'tool_loop_';

    /** @var int Seconds a link can go unused before it expires. */
    public const EXPIRY = 30 * DAYSECS;

    /** @var int Seconds after the last message of the user in which Meta allows free-form replies (service window). */
    public const SERVICE_WINDOW = 24 * HOURSECS;

    /**
     * Chat key of a BSUID, used by the conversations, held actions and message limits.
     *
     * @param string $bsuid BSUID as Meta sends it.
     * @return string
     */
    public static function chatkey(string $bsuid): string {
        return self::CHATKEY_PREFIX . $bsuid;
    }

    /**
     * Remove the WhatsApp link of a user and all the data of the channel for it: the conversation, the task in
     * progress, the message limit, the actions waiting for confirmation, the received messages and the usage counters.
     *
     * Triggers whatsapp_unlinked once when the user had a link, whatever removed it (command, profile page, observers,
     * a new link that replaces it, expiry).
     *
     * @param int $userid User id.
     * @param int|null $actorid User who removed the link; the same user when null.
     * @return void
     */
    public static function unlink(int $userid, ?int $actorid = null): void {
        global $DB;
        $bsuids = $DB->get_fieldset_select(self::LINKS_TABLE, 'bsuid', 'userid = ?', [$userid]);
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($bsuids as $bsuid) {
                $chatkey = self::chatkey((string) $bsuid);
                $DB->delete_records_list(self::SESSIONS_TABLE, 'chatid', [$chatkey, self::TOOL_LOOP_PREFIX . $chatkey]);
                $DB->delete_records(self::RATE_TABLE, ['chatid' => $chatkey]);
                $DB->delete_records(self::PENDING_TABLE, ['chatid' => $chatkey]);
                $DB->delete_records(self::INBOX_TABLE, ['bsuid' => $bsuid]);
            }
            $DB->delete_records(self::USAGE_TABLE, ['userid' => $userid]);
            $DB->delete_records(self::LINKS_TABLE, ['userid' => $userid]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        if ($bsuids) {
            \local_jedomi\event\whatsapp_unlinked::create_for_user($userid, $actorid)->trigger();
        }
    }

    /**
     * Move the link of a BSUID that Meta replaced (system message user_changed_user_id or user_changed_number) to the
     * new BSUID, with the conversation, the task in progress, the message limit, the held actions and the received
     * messages of the student, in one transaction.
     *
     * When the new BSUID is already linked, nothing changes: a link is never overwritten. Chat rows of the new BSUID
     * that exist without a link win over the old ones, which are dropped instead of breaking the unique keys.
     *
     * @param string $previous BSUID Meta gave in system.previous_user_id.
     * @param string $new New BSUID of the user.
     * @param string|null $newwaid New phone (wa_id) of a number change; null when Meta did not share it.
     * @param bool $numberchanged Whether the phone changed: the old phone is then replaced, or cleared, so nothing is
     *     sent to a number the student no longer has.
     * @return bool Whether a link was moved.
     */
    public static function change_identity(
        string $previous,
        string $new,
        ?string $newwaid = null,
        bool $numberchanged = false
    ): bool {
        global $DB;
        if ($previous === '' || $new === '' || $previous === $new) {
            return false;
        }
        $link = $DB->get_record(self::LINKS_TABLE, ['bsuid' => $previous]);
        if (!$link || $DB->record_exists(self::LINKS_TABLE, ['bsuid' => $new])) {
            return false;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $update = (object) ['id' => $link->id, 'bsuid' => $new];
            if ($numberchanged) {
                $update->waid = $newwaid;
                $update->phone = null;
            } else if ($newwaid !== null && empty($link->waid)) {
                $update->waid = $newwaid;
            }
            $DB->update_record(self::LINKS_TABLE, $update);

            $oldkey = self::chatkey($previous);
            $newkey = self::chatkey($new);
            self::move_unique_chat(self::SESSIONS_TABLE, $oldkey, $newkey);
            self::move_unique_chat(self::SESSIONS_TABLE, self::TOOL_LOOP_PREFIX . $oldkey, self::TOOL_LOOP_PREFIX . $newkey);
            self::move_unique_chat(self::RATE_TABLE, $oldkey, $newkey);
            $DB->set_field(self::PENDING_TABLE, 'chatid', $newkey, ['chatid' => $oldkey]);
            $DB->set_field(self::INBOX_TABLE, 'bsuid', $new, ['bsuid' => $previous]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return true;
    }

    /**
     * Rename the chat key of a table with a unique chatid; when the new key is taken, the old row is dropped.
     *
     * @param string $table Table.
     * @param string $from Old chat key.
     * @param string $to New chat key.
     */
    private static function move_unique_chat(string $table, string $from, string $to): void {
        global $DB;
        if ($DB->record_exists($table, ['chatid' => $to])) {
            $DB->delete_records($table, ['chatid' => $from]);
            return;
        }
        $DB->set_field($table, 'chatid', $to, ['chatid' => $from]);
    }

    /**
     * Remove every link unused for more than EXPIRY (since its last use, or since it was created when never used),
     * with the data of its chat.
     *
     * @return int Number of users whose link was removed.
     */
    public static function unlink_expired(): int {
        global $DB;
        $cutoff = \core\di::get(\core\clock::class)->time() - self::EXPIRY;
        $userids = $DB->get_fieldset_select(
            self::LINKS_TABLE,
            'DISTINCT userid',
            'lastused < :lastused AND timecreated < :timecreated',
            ['lastused' => $cutoff, 'timecreated' => $cutoff]
        );
        foreach ($userids as $userid) {
            self::unlink((int) $userid);
        }
        return count($userids);
    }
}
