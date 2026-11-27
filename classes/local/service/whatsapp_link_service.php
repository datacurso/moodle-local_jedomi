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
     * @param int $userid User id.
     * @return void
     */
    public static function unlink(int $userid): void {
        global $DB;
        $bsuids = $DB->get_fieldset_select(self::LINKS_TABLE, 'bsuid', 'userid = ?', [$userid]);
        $transaction = $DB->start_delegated_transaction();
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
