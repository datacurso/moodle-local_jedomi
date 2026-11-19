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

/**
 * Bot availability and account links shown on the Telegram profile page.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_link_service {
    /** @var int Seconds without use after which a link expires (Decision 7). */
    public const EXPIRY = 30 * DAYSECS;

    /**
     * Whether users can link their account, and the bot names.
     *
     * @return array{available: bool, username: string, firstname: string} The bot is available when Telegram is
     *     enabled and a verified bot has a username; the first name defaults to "Jedomi".
     */
    public static function get_bot_status(): array {
        $username = (string) (get_config('local_jedomi', 'telegram_bot_username') ?: '');
        return [
            'available' => \local_jedomi\telegram\manager::is_enabled()
                && (bool) get_config('local_jedomi', 'telegram_bot_verified') && $username !== '',
            'username' => $username,
            'firstname' => (string) (get_config('local_jedomi', 'telegram_bot_firstname') ?: 'Jedomi'),
        ];
    }

    /**
     * Confirmed Telegram link of a user.
     *
     * @param int $userid User id.
     * @return \stdClass|null The link record, or null when the user has no confirmed link.
     */
    public static function get_confirmed_link(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_jedomi_telegram_links', ['userid' => $userid, 'confirmed' => 1]) ?: null;
    }

    /**
     * Remove every Telegram link of a user and all the data of the bot for those chats: the conversation, the task
     * in progress, the message limit and the actions waiting for confirmation.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function unlink(int $userid): void {
        global $DB;
        $chatids = $DB->get_fieldset_select('local_jedomi_telegram_links', 'chatid', 'userid = ?', [$userid]);
        $transaction = $DB->start_delegated_transaction();
        foreach ($chatids as $chatid) {
            $DB->delete_records_list('local_jedomi_telegram_sessions', 'chatid', [$chatid, 'tool_loop_' . $chatid]);
            $DB->delete_records('local_jedomi_telegram_rate_limit', ['chatid' => $chatid]);
            $DB->delete_records('local_jedomi_telegram_pending_actions', ['chatid' => $chatid]);
        }
        $DB->delete_records('local_jedomi_telegram_links', ['userid' => $userid]);
        $transaction->allow_commit();
        if ($chatids) {
            \local_jedomi\event\telegram_unlinked::create([
                'context' => \context_system::instance(),
                'relateduserid' => $userid,
            ])->trigger();
        }
    }

    /**
     * Whether a link went unused for more than EXPIRY: since its last use, or since it was created when never used.
     *
     * @param \stdClass $link Link record.
     * @param int|null $now Current time; time() when null.
     * @return bool
     */
    public static function is_expired(\stdClass $link, ?int $now = null): bool {
        $lastuse = max((int) ($link->lastused ?? 0), (int) ($link->timecreated ?? 0));
        return $lastuse < ($now ?? time()) - self::EXPIRY;
    }

    /**
     * Record that the chat of a link was used now.
     *
     * @param \stdClass $link Link record.
     * @return void
     */
    public static function touch(\stdClass $link): void {
        global $DB;
        $DB->set_field('local_jedomi_telegram_links', 'lastused', time(), ['id' => $link->id]);
    }

    /**
     * Remove every link unused for more than EXPIRY, with the data of its chat.
     *
     * @return int Number of users whose link was removed.
     */
    public static function unlink_expired(): int {
        global $DB;
        $cutoff = time() - self::EXPIRY;
        $userids = $DB->get_fieldset_select(
            'local_jedomi_telegram_links',
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
