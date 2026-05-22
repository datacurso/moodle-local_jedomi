<?php
/**
 * Event observer implementations for Telegram proactive notifications.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\event;

defined('MOODLE_INTERNAL') || die();

use local_jedomi\telegram\api_client;
use local_jedomi\telegram\manager;

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

        // submission_graded: userid = teacher who graded, relateduserid = student
        $student_id = $event->relateduserid;
        if (!$student_id) {
            return;
        }

        $link = $DB->get_record('local_jedomi_telegram_links', ['userid' => $student_id, 'confirmed' => 1]);
        if (!$link) {
            return;
        }

        if (!self::check_cooldown($link)) {
            return;
        }

        // Retrieve names via standard DB API (get_assign/get_course are not event methods)
        $cm_id    = $event->contextinstanceid;
        $cm       = get_coursemodule_from_id('assign', $cm_id, 0, false, IGNORE_MISSING);
        $assign_name = $cm ? $cm->name : 'Assignment';
        $course_name = $cm ? $DB->get_field('course', 'fullname', ['id' => $cm->course]) : 'Course';

        $text = "📝 Your assignment '<b>" . s($assign_name) . "</b>' in <b>" . s($course_name ?? 'Course') . "</b> has been graded.";

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
            return; // Only notify admins
        }

        $link = $DB->get_record('local_jedomi_telegram_links', ['userid' => $userid, 'confirmed' => 1]);
        if (!$link) {
            return;
        }

        if (!self::check_cooldown($link)) {
            return;
        }

        // Check failing cron tasks
        $failing = $DB->get_records_sql("SELECT classname, faildelay FROM {task_scheduled} WHERE faildelay > 0 OR disabled = 1 LIMIT 10");
        if (empty($failing)) {
            return; // All good
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

    // ── Cooldown helpers ─────────────────────────────────────────────────────

    private static function check_cooldown(\stdClass $link): bool {
        $cooldown_seconds = 5 * 60; // 5 minutes
        return ($link->lastproactivesent + $cooldown_seconds) <= time();
    }

    private static function update_cooldown(\stdClass $link): void {
        global $DB;
        $link->lastproactivesent = time();
        $DB->update_record('local_jedomi_telegram_links', $link);
    }
}
