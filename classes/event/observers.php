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
 * Event observer implementations for Telegram proactive notifications.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\event;

use local_jedomi\telegram\api_client;
use local_jedomi\telegram\manager;

/**
 * Event observers that send proactive Telegram notifications to linked users.
 */
class observers {
    /**
     * Notify student via Telegram when their assignment is graded.
     *
     * @param \mod_assign\event\submission_graded $event
     */
    public static function submission_graded(\mod_assign\event\submission_graded $event): void {
        global $DB;

        if (!manager::is_enabled()) {
            return;
        }

        // Submission_graded: userid = teacher who graded, relateduserid = student.
        $studentid = $event->relateduserid;
        if (!$studentid) {
            return;
        }

        $link = $DB->get_record('local_jedomi_telegram_links', ['userid' => $studentid, 'confirmed' => 1]);
        if (!$link) {
            return;
        }

        if (!self::check_cooldown($link)) {
            return;
        }

        // Retrieve names via standard DB API (get_assign/get_course are not event methods).
        $cmid    = $event->contextinstanceid;
        $cm       = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        $assignname = $cm ? $cm->name : 'Assignment';
        $coursename = $cm ? $DB->get_field('course', 'fullname', ['id' => $cm->course]) : 'Course';

        $text = "📝 Your assignment '<b>" . s($assignname) . "</b>' in <b>" . s($coursename ?? 'Course') . "</b> has been graded.";

        $client = new api_client();
        $client->send_message((string)$link->chatid, $text);

        self::update_cooldown($link);
    }

    /**
     * Check cron health on admin login and notify if issues found.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        global $DB;

        if (!manager::is_enabled()) {
            return;
        }

        $userid = $event->userid;
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user || !is_siteadmin($userid)) {
            return; // Only notify admins.
        }

        $link = $DB->get_record('local_jedomi_telegram_links', ['userid' => $userid, 'confirmed' => 1]);
        if (!$link) {
            return;
        }

        if (!self::check_cooldown($link)) {
            return;
        }

        // Check failing cron tasks.
        $failing = $DB->get_records_sql("SELECT classname, faildelay, disabled"
            . " FROM {task_scheduled} WHERE faildelay > 0 OR disabled = 1 LIMIT 10");
        if (empty($failing)) {
            return; // All good.
        }

        $lines = ["⚠️ <b>Cron Health Alert</b>"];
        foreach ($failing as $task) {
            $name = basename(str_replace('\\', '/', $task->classname));
            $status = $task->disabled ? 'Disabled' : 'Failing (delay: ' . $task->faildelay . 's)';
            $lines[] = "• " . s($name) . ": " . $status;
        }
        $lines[] = "\nReply with 'FIX' to attempt auto-repair.";

        $client = new api_client();
        $client->send_message((int)$link->chatid, implode("\n", $lines));

        self::update_cooldown($link);
    }

    // ... ── Cooldown helpers ─────────────────────────────────────────────────────

    /**
     * Check whether enough time has passed since the last proactive message to this link.
     *
     * @param \stdClass $link Telegram link record with a lastproactivesent field.
     * @return bool True if the 5-minute cooldown has elapsed and a message may be sent.
     */
    private static function check_cooldown(\stdClass $link): bool {
        $cooldownseconds = 5 * 60; // 5 minutes
        return ($link->lastproactivesent + $cooldownseconds) <= time();
    }

    /**
     * Record the current time as the last proactive send for this link.
     *
     * @param \stdClass $link Telegram link record to update.
     */
    private static function update_cooldown(\stdClass $link): void {
        global $DB;
        $link->lastproactivesent = time();
        $DB->update_record('local_jedomi_telegram_links', $link);
    }
}
