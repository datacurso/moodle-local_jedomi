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
 * Event observer implementations for the proactive notifications and the link revocations.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\event;

use local_jedomi\local\service\notification_dispatcher;
use local_jedomi\local\service\student_data_service;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\task\send_whatsapp_grade_notification;
use local_jedomi\telegram\api_client;
use local_jedomi\whatsapp\consent;

/**
 * Event observers that send proactive notifications to linked users (Telegram and WhatsApp; the failing task alert of
 * the administrator only on Telegram) and revoke the links of accounts that can no longer use them.
 */
class observers {
    /**
     * User preference with the assignments whose grade was notified on each channel, with the time and the grade
     * notified.
     */
    private const GRADE_PREFERENCE = 'local_jedomi_grade_notifications';

    /** Most scheduled tasks listed in the alert of the administrator. */
    private const MAX_ALERT_TASKS = 10;

    /** Most assignments remembered per user with the grade last notified, so the preference stays small. */
    private const MAX_REMEMBERED_GRADES = 20;

    /**
     * Notify a student on Telegram and WhatsApp when their assignment is graded.
     *
     * Only a student who may get notifications on a channel gets it there (see
     * notification_dispatcher::grade_recipients()), only when the student can see the grade and only when the grade
     * differs from the one last notified on that channel for that assignment. Telegram is sent in this request;
     * WhatsApp sends the grade_posted template from an ad hoc task (send_whatsapp_grade_notification), so the grading
     * request never waits for Meta.
     *
     * @param \mod_assign\event\submission_graded $event
     */
    public static function submission_graded(\mod_assign\event\submission_graded $event): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');

        // Submission_graded: userid = teacher who graded, relateduserid = student.
        $studentid = (int) $event->relateduserid;
        if (!$studentid) {
            return;
        }

        $recipients = notification_dispatcher::grade_recipients($studentid);
        if (!$recipients) {
            return;
        }

        // Retrieve names via standard DB API (get_assign/get_course are not event methods).
        $cmid = (int) $event->contextinstanceid;
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm || !student_data_service::assign_grade_is_visible((int) $cm->instance, (int) $cm->course, $studentid)) {
            // The student cannot see the grade yet: it is hidden, not released or anonymous.
            return;
        }

        $gradeid = (int) $event->objectid;
        if (isset($recipients[notification_dispatcher::TELEGRAM])) {
            $link = $recipients[notification_dispatcher::TELEGRAM];
            self::notify_grade(notification_dispatcher::TELEGRAM, $link, $studentid, $cm, $gradeid);
        }
        if (
            isset($recipients[notification_dispatcher::WHATSAPP])
                && !self::grade_already_notified(notification_dispatcher::WHATSAPP, $studentid, $cmid, $gradeid)
        ) {
            // A task already queued for this grade record is not queued twice: it sends the grade when it runs.
            \core\task\manager::queue_adhoc_task(
                send_whatsapp_grade_notification::for_grade($studentid, (int) $cm->course, $cmid, $gradeid),
                true
            );
        }
    }

    /**
     * Send the grade_posted WhatsApp template of a graded assignment, from send_whatsapp_grade_notification.
     *
     * Everything is checked again, as it may have changed since the grading: the recipient (channel enabled, link,
     * grades consent, opt-out, role), the assignment and its grade record, the visibility of the grade, the grade
     * already notified on WhatsApp and the minimum interval. The grade sent is the one stored now.
     *
     * @param int $userid Student.
     * @param int $courseid Course of the assignment.
     * @param int $cmid Course module of the assignment.
     * @param int $gradeid Id of the assign_grades record.
     */
    public static function send_whatsapp_grade(int $userid, int $courseid, int $cmid, int $gradeid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');

        $link = \local_jedomi_whatsapp_recipient($userid, consent::GRADES);
        if (!$link) {
            return;
        }
        $cm = get_coursemodule_from_id('assign', $cmid, $courseid, false, IGNORE_MISSING);
        if (
            !$cm || !$DB->record_exists('assign_grades', ['id' => $gradeid, 'assignment' => $cm->instance, 'userid' => $userid])
                || !student_data_service::assign_grade_is_visible((int) $cm->instance, (int) $cm->course, $userid)
        ) {
            return;
        }
        self::notify_grade(notification_dispatcher::WHATSAPP, $link, $userid, $cm, $gradeid);
    }

    /**
     * Send a grade notification on one channel, unless that grade was the last one notified there for the assignment,
     * and remember it for that channel when sent.
     *
     * @param string $channel Channel.
     * @param \stdClass $link Link of the channel.
     * @param int $userid Student.
     * @param \stdClass $cm Course module of the assignment.
     * @param int $gradeid Id of the assign_grades record.
     */
    private static function notify_grade(string $channel, \stdClass $link, int $userid, \stdClass $cm, int $gradeid): void {
        global $DB;
        $cmid = (int) $cm->id;
        if (self::grade_already_notified($channel, $userid, $cmid, $gradeid)) {
            return;
        }
        $grade = self::grade_value($gradeid);
        $notified = self::grade_notifications($userid, $channel);
        $sent = notification_dispatcher::grade_posted([$channel => $link], $cmid, $notified, [
            'courseid' => (int) $cm->course,
            'course' => (string) ($DB->get_field('course', 'fullname', ['id' => $cm->course]) ?: ''),
            'activity' => (string) $cm->name,
            'grade' => $channel === notification_dispatcher::WHATSAPP ? self::grade_display($cm, $grade) : '',
            'lang' => self::user_language($userid),
        ]);
        if ($sent) {
            self::record_grade_notification($userid, $channel, $cmid, $grade);
        }
    }

    /**
     * Whether the grade of a record is the one last notified on a channel for the assignment: a regrade that leaves
     * the same grade is not notified again.
     *
     * @param string $channel Channel.
     * @param int $userid Student.
     * @param int $cmid Course module of the assignment.
     * @param int $gradeid Id of the assign_grades record.
     * @return bool
     */
    private static function grade_already_notified(string $channel, int $userid, int $cmid, int $gradeid): bool {
        $notified = self::grade_notifications($userid, $channel);
        return isset($notified[$cmid]) && $notified[$cmid]['grade'] === self::grade_value($gradeid);
    }

    /**
     * A grade as the gradebook shows it (points, scale item or letter, by the settings of the grade item), as plain
     * text; empty when there is no grade.
     *
     * @param \stdClass $cm Course module of the assignment.
     * @param string $grade Grade, see grade_value().
     * @return string
     */
    private static function grade_display(\stdClass $cm, string $grade): string {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        if ($grade === '') {
            return '';
        }
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $cm->instance,
            'courseid' => $cm->course, 'itemnumber' => 0]);
        if (!$item) {
            return $grade;
        }
        return html_entity_decode(strip_tags(grade_format_gradevalue((float) $grade, $item)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
     * Assignments whose grade was notified to a user on a channel, with the time and the grade of the last
     * notification.
     *
     * @param int $userid Student.
     * @param string $channel Channel.
     * @return array<int, array{time: int, grade: ?string}> Keyed by course module id.
     */
    private static function grade_notifications(int $userid, string $channel): array {
        $stored = self::stored_grade_notifications($userid)[$channel] ?? [];
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
     * The stored notified assignments of a user, keyed by channel. A preference of earlier versions holds one list for
     * every channel, so it is the list of each channel.
     *
     * @param int $userid Student.
     * @return array<string, array>
     */
    private static function stored_grade_notifications(int $userid): array {
        $stored = json_decode((string) get_user_preferences(self::GRADE_PREFERENCE, '', $userid), true);
        if (!is_array($stored)) {
            return [];
        }
        $channels = [notification_dispatcher::TELEGRAM, notification_dispatcher::WHATSAPP];
        if (array_intersect(array_keys($stored), $channels) === []) {
            return array_fill_keys($channels, $stored);
        }
        return array_map(static fn($list): array => is_array($list) ? $list : [], $stored);
    }

    /**
     * Remember the grade of an assignment notified to a user on a channel, keeping only the most recent assignments
     * of each channel.
     *
     * @param int $userid Student.
     * @param string $channel Channel.
     * @param int $cmid Course module of the assignment.
     * @param string $grade Grade notified.
     * @return void
     */
    private static function record_grade_notification(int $userid, string $channel, int $cmid, string $grade): void {
        $notified = self::grade_notifications($userid, $channel);
        $notified[$cmid] = ['time' => \core\di::get(\core\clock::class)->time(), 'grade' => $grade];
        uasort($notified, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
        $list = [];
        foreach (array_slice($notified, 0, self::MAX_REMEMBERED_GRADES, true) as $id => $entry) {
            $list[$id] = [$entry['time'], $entry['grade']];
        }
        $stored = self::stored_grade_notifications($userid);
        $stored[$channel] = $list;
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
        if (!$link || !notification_dispatcher::interval_passed($link)) {
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

        notification_dispatcher::mark_sent(notification_dispatcher::TELEGRAM, $link);
    }

    /**
     * Revoke the Telegram and WhatsApp links of a user who was suspended (Decision 7).
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
        if (
            $DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['userid' => $userid])
                && $DB->record_exists('user', ['id' => $userid, 'suspended' => 1])
        ) {
            whatsapp_link_service::unlink($userid);
        }
    }

    /**
     * Revoke the Telegram and WhatsApp links of a deleted user (Decision 7).
     *
     * @param \core\event\user_deleted $event
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        \local_jedomi\local\service\telegram_link_service::unlink((int) $event->objectid);
        // The account is gone: its usage counters go too.
        whatsapp_link_service::unlink((int) $event->objectid, null, true);
    }

    /**
     * Revoke the Telegram and WhatsApp links of a user whose password changed (Decision 7): whoever had the old
     * password cannot keep acting through the chat.
     *
     * @param \core\event\user_password_updated $event
     * @return void
     */
    public static function user_password_updated(\core\event\user_password_updated $event): void {
        \local_jedomi\local\service\telegram_link_service::unlink((int) $event->relateduserid);
        whatsapp_link_service::unlink((int) $event->relateduserid);
    }
}
