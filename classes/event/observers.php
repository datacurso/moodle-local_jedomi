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
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\event;

use local_jedomi\local\service\student_data_service;
use local_jedomi\telegram\api_client;

/**
 * Event observers that send proactive Telegram notifications to linked users.
 */
class observers {
    /** Minimum seconds between two proactive notifications to the same chat. */
    private const COOLDOWN = 300;

    /** User preference with the assignments whose grade was notified, with the time and the grade notified. */
    private const GRADE_PREFERENCE = 'local_jedomi_grade_notifications';

    /** Most scheduled tasks listed in the alert of the administrator. */
    private const MAX_ALERT_TASKS = 10;

    /** Most assignments remembered per user with the grade last notified, so the preference stays small. */
    private const MAX_REMEMBERED_GRADES = 20;

    /**
     * Notify student via Telegram when their assignment is graded.
     *
     * Only a linked student who may use the bot gets it (active account, role allowed for Telegram), only when the
     * student can see the grade and only when the grade differs from the one last notified for that assignment.
     *
     * @param \mod_assign\event\submission_graded $event
     */
    public static function submission_graded(\mod_assign\event\submission_graded $event): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');

        // Submission_graded: userid = teacher who graded, relateduserid = student.
        $studentid = (int) $event->relateduserid;
        if (!$studentid) {
            return;
        }

        $link = \local_jedomi_telegram_recipient($studentid);
        if (!$link) {
            return;
        }

        // Retrieve names via standard DB API (get_assign/get_course are not event methods).
        $cmid = (int) $event->contextinstanceid;
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || !student_data_service::assign_grade_is_visible((int) $cm->instance, (int) $cm->course, $studentid)) {
            // The student cannot see the grade yet: it is hidden, not released or anonymous.
            return;
        }

        // A regrade that leaves the same grade is not notified again.
        $grade = self::grade_value((int) $event->objectid);
        $notified = self::grade_notifications($studentid);
        if (isset($notified[$cmid]) && $notified[$cmid]['grade'] === $grade) {
            return;
        }

        if (!self::check_grade_cooldown($link, $cmid, $notified)) {
            return;
        }

        $coursename = $DB->get_field('course', 'fullname', ['id' => $cm->course]) ?: '';
        $text = get_string_manager()->get_string('telegram_notify_graded', 'local_jedomi', [
            'assignment' => s($cm->name),
            'course' => s($coursename),
        ], self::user_language($studentid));

        api_client::instance()->send_message((string) $link->chatid, $text);

        self::update_cooldown($link);
        self::record_grade_notification($studentid, $cmid, $grade, $notified);
    }

    /**
     * Grade of an assignment grade record, normalised so equal grades compare equal; empty when not graded.
     *
     * @param int $gradeid Id of the assign_grades record.
     * @return string
     */
    private static function grade_value(int $gradeid): string {
        global $DB;
        $grade = $DB->get_field('assign_grades', 'grade', ['id' => $gradeid]);
        if ($grade === false || $grade === null || (float) $grade < 0) {
            return '';
        }
        return format_float((float) $grade, 5, false);
    }

    /**
     * Whether a grade notification may be sent: after the minimum interval of the chat, or within it when it is
     * about an assignment not notified during the interval, so the interval never drops another assignment.
     *
     * @param \stdClass $link Telegram link record.
     * @param int $cmid Course module of the graded assignment.
     * @param array $notified Notified assignments, see grade_notifications().
     * @return bool
     */
    private static function check_grade_cooldown(\stdClass $link, int $cmid, array $notified): bool {
        if (self::check_cooldown($link)) {
            return true;
        }
        $since = time() - self::COOLDOWN;
        $recent = array_filter($notified, static fn(array $entry): bool => $entry['time'] > $since);
        return !empty($recent) && !isset($recent[$cmid]);
    }

    /**
     * Assignments whose grade was notified to a user, with the time and the grade of the last notification.
     *
     * @param int $userid Student.
     * @return array<int, array{time: int, grade: ?string}> Keyed by course module id.
     */
    private static function grade_notifications(int $userid): array {
        $stored = json_decode((string) get_user_preferences(self::GRADE_PREFERENCE, '', $userid), true);
        if (!is_array($stored)) {
            return [];
        }
        $notified = [];
        foreach ($stored as $cmid => $entry) {
            if (is_array($entry)) {
                $notified[(int) $cmid] = [
                    'time' => (int) ($entry[0] ?? 0),
                    'grade' => isset($entry[1]) ? (string) $entry[1] : null,
                ];
            } else {
                // Format of earlier versions: only the time.
                $notified[(int) $cmid] = ['time' => (int) $entry, 'grade' => null];
            }
        }
        return $notified;
    }

    /**
     * Remember the grade of an assignment notified to a user, keeping only the most recent assignments.
     *
     * @param int $userid Student.
     * @param int $cmid Course module of the assignment.
     * @param string $grade Grade notified.
     * @param array $notified Notified assignments, see grade_notifications().
     * @return void
     */
    private static function record_grade_notification(int $userid, int $cmid, string $grade, array $notified): void {
        $notified[$cmid] = ['time' => time(), 'grade' => $grade];
        uasort($notified, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
        $stored = [];
        foreach (array_slice($notified, 0, self::MAX_REMEMBERED_GRADES, true) as $id => $entry) {
            $stored[$id] = [$entry['time'], $entry['grade']];
        }
        set_user_preference(self::GRADE_PREFERENCE, json_encode($stored), $userid);
    }

    /**
     * Language of a user for a notification, or the site language when the user's one is not installed.
     *
     * @param int $userid User.
     * @return string
     */
    private static function user_language(int $userid): string {
        return \local_jedomi\local\user_language::for_user($userid);
    }

    /**
     * Tell a linked administrator on login about the scheduled tasks that are failing.
     *
     * Tasks disabled on purpose are not listed, and the alert points to the scheduled tasks page.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');

        $userid = (int) $event->userid;
        if (!is_siteadmin($userid)) {
            return; // Only notify admins.
        }

        $link = \local_jedomi_telegram_recipient($userid);
        if (!$link || !self::check_cooldown($link)) {
            return;
        }

        $failing = $DB->get_records_select(
            'task_scheduled',
            'faildelay > 0 AND disabled = 0',
            null,
            'faildelay DESC, id ASC',
            'id, classname, faildelay',
            0,
            self::MAX_ALERT_TASKS
        );
        if (empty($failing)) {
            return; // All good.
        }

        $lang = self::user_language($userid);
        $strings = get_string_manager();
        $lines = [$strings->get_string('telegram_task_alert_heading', 'local_jedomi', null, $lang)];
        foreach ($failing as $task) {
            $lines[] = '• ' . $strings->get_string('telegram_task_alert_failing', 'local_jedomi', [
                'name' => s(basename(str_replace('\\', '/', $task->classname))),
                'delay' => (int) $task->faildelay,
            ], $lang);
        }
        $url = (new \moodle_url('/admin/tool/task/scheduledtasks.php'))->out(false);
        $lines[] = "\n" . $strings->get_string('telegram_task_alert_footer', 'local_jedomi', $url, $lang);

        api_client::instance()->send_message((string) $link->chatid, implode("\n", $lines));

        self::update_cooldown($link);
    }

    /**
     * Revoke the Telegram link of a user who was suspended (Decision 7).
     *
     * @param \core\event\user_updated $event
     * @return void
     */
    public static function user_updated(\core\event\user_updated $event): void {
        global $DB;
        $userid = (int) $event->objectid;
        if (
            $DB->record_exists('local_jedomi_telegram_links', ['userid' => $userid])
                && $DB->record_exists('user', ['id' => $userid, 'suspended' => 1])
        ) {
            \local_jedomi\local\service\telegram_link_service::unlink($userid);
        }
    }

    /**
     * Revoke the Telegram link of a deleted user (Decision 7).
     *
     * @param \core\event\user_deleted $event
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        \local_jedomi\local\service\telegram_link_service::unlink((int) $event->objectid);
    }

    /**
     * Revoke the Telegram link of a user whose password changed (Decision 7): whoever had the old password cannot keep
     * acting through the chat.
     *
     * @param \core\event\user_password_updated $event
     * @return void
     */
    public static function user_password_updated(\core\event\user_password_updated $event): void {
        \local_jedomi\local\service\telegram_link_service::unlink((int) $event->relateduserid);
    }

    // ... ── Cooldown helpers ─────────────────────────────────────────────────────

    /**
     * Check whether enough time has passed since the last proactive message to this link.
     *
     * @param \stdClass $link Telegram link record with a lastproactivesent field.
     * @return bool True if the 5-minute cooldown has elapsed and a message may be sent.
     */
    private static function check_cooldown(\stdClass $link): bool {
        return ($link->lastproactivesent + self::COOLDOWN) <= time();
    }

    /**
     * Record the current time as the last proactive send for this link. Only that field is written, so the PIN state
     * and the last use written meanwhile by the bot are kept (RISK-002).
     *
     * @param \stdClass $link Telegram link record to update.
     */
    private static function update_cooldown(\stdClass $link): void {
        global $DB;
        $link->lastproactivesent = time();
        $DB->set_field('local_jedomi_telegram_links', 'lastproactivesent', $link->lastproactivesent, ['id' => $link->id]);
    }
}
