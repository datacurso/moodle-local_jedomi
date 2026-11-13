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
 * Scheduled task: cleanup expired Telegram sessions.
 *
 * Deletes session rows with lastactivity older than 30 minutes, expired pending actions and stale message limit records.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\task;

/**
 * Scheduled task that deletes expired Telegram sessions, stale pending actions and stale message limit records.
 */
class cleanup_telegram_sessions extends \core\task\scheduled_task {
    /**
     * Return the localised name of this scheduled task.
     *
     * @return string Task name from the language pack.
     */
    public function get_name(): string {
        return get_string('cleanup_telegram_sessions', 'local_jedomi');
    }

    /** @var int Seconds after its window starts from which an unblocked message limit record is no longer needed. */
    public const RATE_LIMIT_TTL = HOURSECS;

    /**
     * Delete Telegram sessions idle for over 30 minutes, Telegram pending actions older than 5 minutes, web chat
     * pending actions older than 15 minutes and message limit records whose window ended over an hour ago and that do
     * not block their chat. Each count is read before deleting, so the report gives the real amounts.
     */
    public function execute(): void {
        $now = time();

        $count = self::delete_counted('local_jedomi_telegram_sessions', 'lastactivity < ?', [$now - (30 * 60)]);
        mtrace("Deleted {$count} expired Telegram session(s).");

        // Also clean up stale pending actions older than 5 minutes.
        $actioncount = self::delete_counted('local_jedomi_telegram_pending_actions', 'timecreated < ?', [$now - (5 * 60)]);
        mtrace("Deleted {$actioncount} expired pending action(s).");

        // Web chat actions that were never confirmed.
        $chatcount = \local_jedomi\local\service\pending_action_service::purge_expired();
        mtrace("Deleted {$chatcount} expired web chat pending action(s).");

        // Message limit records of chats that wrote long ago and are not blocked: a new message creates them again.
        $limitcount = self::delete_counted(
            'local_jedomi_telegram_rate_limit',
            'windowstart < ? AND blockeduntil < ?',
            [$now - self::RATE_LIMIT_TTL, $now]
        );
        mtrace("Deleted {$limitcount} expired Telegram message limit record(s).");
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
