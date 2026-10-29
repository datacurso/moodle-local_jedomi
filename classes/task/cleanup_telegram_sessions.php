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
 * Deletes session rows with lastactivity older than 30 minutes.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\task;

/**
 * Scheduled task that deletes expired Telegram sessions and stale pending actions.
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

    /**
     * Delete Telegram sessions idle for over 30 minutes, Telegram pending actions older than 5 minutes
     * and web chat pending actions older than 15 minutes.
     */
    public function execute(): void {
        global $DB;
        $cutoff = time() - (30 * 60); // 30 minutes
        $count = $DB->delete_records_select('local_jedomi_telegram_sessions', 'lastactivity < ?', [$cutoff]);
        mtrace("Deleted {$count} expired Telegram session(s).");

        // Also clean up stale pending actions older than 5 minutes.
        $actioncutoff = time() - (5 * 60);
        $actioncount = $DB->delete_records_select('local_jedomi_telegram_pending_actions', 'timecreated < ?', [$actioncutoff]);
        mtrace("Deleted {$actioncount} expired pending action(s).");

        // Web chat actions that were never confirmed.
        $chatcount = \local_jedomi\local\service\pending_action_service::purge_expired();
        mtrace("Deleted {$chatcount} expired web chat pending action(s).");
    }
}
