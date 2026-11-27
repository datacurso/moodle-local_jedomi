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

namespace local_jedomi\task;

use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\whatsapp\held_replies;
use local_jedomi\whatsapp\usage_meter;
use local_jedomi\whatsapp\whatsapp_media_source;

/**
 * Scheduled task that cleans up the WhatsApp tables.
 *
 * Removes idle conversations, held replies nobody came back for, expired held actions, message limit records no
 * longer needed, inbox rows past the deduplication time whatever their state, links unused for 30 days with their data
 * and the usage counters of past months of users without a link. Also removes the temporary media files a process
 * killed mid-media left behind.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_whatsapp extends \core\task\scheduled_task {
    /** @var int Seconds of inactivity after which a conversation is removed. */
    public const SESSION_TTL = 30 * MINSECS;

    /** @var int Seconds a held action stays valid; claimed rows are removed too (confirmation_service relies on it). */
    public const ACTION_TTL = 5 * MINSECS;

    /** @var int Seconds after its window starts from which an unblocked message limit record is no longer needed. */
    public const RATE_LIMIT_TTL = HOURSECS;

    /**
     * @var int Seconds an inbox row is kept, whatever its state. It rejects the retries of Meta for the same message id,
     * which last from 24-36 hours up to 7 days depending on the source, so it is never shorter than 7 days. A row still
     * pending or processing after it (sender not linked, BSUID changed by Meta, worker lost) is removed too: its media
     * id has expired at Meta (valid 7 days) and a reply would come too late, and keeping it would leave personal data
     * that no user can reach through the privacy requests.
     */
    public const INBOX_TTL = 7 * DAYSECS;

    /**
     * @var int Seconds after its last change from which a temporary media file is removed. A file is always removed by
     * the process that downloaded it, so an older one was left by a process killed mid-media. It is longer than the
     * longest media phase ({@see whatsapp_media_source::time_limit()}, at most 740 s), so a download in progress is
     * never removed.
     */
    public const MEDIA_FILE_TTL = HOURSECS;

    /**
     * Return the localised name of this scheduled task.
     *
     * @return string Task name from the language pack.
     */
    public function get_name(): string {
        return get_string('cleanup_whatsapp', 'local_jedomi');
    }

    /**
     * Delete the expired WhatsApp data. Each count is read before deleting, so the report gives the real amounts.
     */
    public function execute(): void {
        $now = \core\di::get(\core\clock::class)->time();

        global $DB;
        $held = $DB->sql_like('chatid', ':held', true, true, false);
        $notheld = $DB->sql_like('chatid', ':held', true, true, true);
        $heldprefix = $DB->sql_like_escape(whatsapp_link_service::HELD_PREFIX) . '%';

        $count = self::delete_counted(
            whatsapp_link_service::SESSIONS_TABLE,
            "lastactivity < :before AND {$notheld}",
            ['before' => $now - self::SESSION_TTL, 'held' => $heldprefix]
        );
        mtrace("Deleted {$count} expired WhatsApp session(s).");

        $count = self::delete_counted(
            whatsapp_link_service::SESSIONS_TABLE,
            "lastactivity < :before AND {$held}",
            ['before' => $now - held_replies::TTL, 'held' => $heldprefix]
        );
        mtrace("Deleted {$count} expired held WhatsApp reply row(s).");

        $count = self::delete_counted(whatsapp_link_service::PENDING_TABLE, 'timecreated < ?', [$now - self::ACTION_TTL]);
        mtrace("Deleted {$count} expired WhatsApp held action(s).");

        $count = self::delete_counted(
            whatsapp_link_service::RATE_TABLE,
            'windowstart < ? AND blockeduntil < ?',
            [$now - self::RATE_LIMIT_TTL, $now]
        );
        mtrace("Deleted {$count} expired WhatsApp message limit record(s).");

        $count = self::delete_counted(
            whatsapp_link_service::INBOX_TABLE,
            'timecreated < ?',
            [$now - self::INBOX_TTL]
        );
        mtrace("Deleted {$count} old WhatsApp inbox row(s).");

        $count = whatsapp_link_service::unlink_expired();
        mtrace("Removed {$count} expired WhatsApp link(s).");

        // Unlinking keeps the usage so a new link does not reset the monthly limit; past months are no longer needed.
        $count = self::delete_counted(
            whatsapp_link_service::USAGE_TABLE,
            'period < :period AND userid NOT IN (SELECT userid FROM {' . whatsapp_link_service::LINKS_TABLE . '})',
            ['period' => usage_meter::period()]
        );
        mtrace("Deleted {$count} old WhatsApp usage row(s).");

        $count = self::delete_old_media_files($now - self::MEDIA_FILE_TTL);
        mtrace("Deleted {$count} old WhatsApp media temporary file(s).");
    }

    /**
     * Delete the temporary media files changed before a time; subdirectories and links are left alone.
     *
     * @param int $before Time before which a file is removed.
     * @return int Number of deleted files.
     */
    private static function delete_old_media_files(int $before): int {
        global $CFG;
        $dir = $CFG->tempdir . '/' . whatsapp_media_source::TEMP_DIR;
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isDot() || $file->isLink() || !$file->isFile() || $file->getMTime() >= $before) {
                continue;
            }
            if (@unlink($file->getPathname())) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Delete the records of a table that match a condition and return how many there were.
     *
     * @param string $table Table name.
     * @param string $select SQL condition.
     * @param array $params Parameters of the condition.
     * @return int Number of deleted records.
     */
    private static function delete_counted(string $table, string $select, array $params): int {
        global $DB;
        $count = $DB->count_records_select($table, $select, $params);
        if ($count > 0) {
            $DB->delete_records_select($table, $select, $params);
        }
        return $count;
    }
}
