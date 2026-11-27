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

use local_jedomi\telegram\api_client;
use local_jedomi\whatsapp\consent;
use local_jedomi\whatsapp\template_sender;
use local_jedomi\whatsapp\templates;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../lib.php');

/**
 * Sends the proactive notifications of Jedomi to every messaging channel of a user.
 *
 * Each channel has its recipient: local_jedomi_telegram_recipient() and local_jedomi_whatsapp_recipient() (with the
 * consent category of the notification). Telegram gets a text; WhatsApp always gets an approved template, inside or
 * outside the 24-hour window. Each link keeps the time of its last proactive message (lastproactivesent), and a
 * channel gets no second notification within COOLDOWN seconds, except about an assignment not notified during the
 * interval, so the interval never drops another assignment.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class notification_dispatcher {
    /** @var int Minimum seconds between two proactive notifications to the same link. */
    public const COOLDOWN = 300;

    /** @var string Telegram channel. */
    public const TELEGRAM = 'telegram';

    /** @var string WhatsApp channel. */
    public const WHATSAPP = 'whatsapp';

    /** @var array<string, string> Table of the links of each channel. */
    private const TABLES = [
        self::TELEGRAM => 'local_jedomi_telegram_links',
        self::WHATSAPP => whatsapp_link_service::LINKS_TABLE,
    ];

    /**
     * Links of the channels that may get a grade notification for a user, keyed by channel.
     *
     * @param int $userid User.
     * @return array<string, \stdClass>
     */
    public static function grade_recipients(int $userid): array {
        return array_filter([
            self::TELEGRAM => \local_jedomi_telegram_recipient($userid),
            self::WHATSAPP => \local_jedomi_whatsapp_recipient($userid, consent::GRADES),
        ]);
    }

    /**
     * Send a grade notification to the recipients of a user.
     *
     * @param array<string, \stdClass> $recipients Links keyed by channel, see grade_recipients().
     * @param int $cmid Course module of the graded assignment.
     * @param array $notified Assignments notified to the user, keyed by course module id, each with its time.
     * @param array{courseid: int, course: string, activity: string, grade: string, lang: string} $data Course id,
     *     course and assignment names as stored, grade as shown to the student and language of the user.
     * @return bool Whether at least one channel sent it.
     */
    public static function grade_posted(array $recipients, int $cmid, array $notified, array $data): bool {
        $sent = false;
        foreach ($recipients as $channel => $link) {
            if (!self::grade_interval_allows($link, $cmid, $notified) || !self::send_grade($channel, $link, $data)) {
                continue;
            }
            self::mark_sent($channel, $link);
            $sent = true;
        }
        return $sent;
    }

    /**
     * Send a grade notification on one channel.
     *
     * @param string $channel Channel.
     * @param \stdClass $link Link of the channel.
     * @param array $data Data of the notification, see grade_posted().
     * @return bool Whether it was sent.
     */
    private static function send_grade(string $channel, \stdClass $link, array $data): bool {
        if ($channel === self::WHATSAPP) {
            // Template parameters are plain text: the names are formatted without HTML.
            $context = \context_course::instance((int) $data['courseid']);
            return template_sender::send($link, templates::GRADE_POSTED, [
                'course' => self::plain($data['course'], $context),
                'activity' => self::plain($data['activity'], $context),
                'grade' => $data['grade'],
            ]);
        }
        // Telegram reads HTML, so the names are escaped.
        $text = get_string_manager()->get_string('telegram_notify_graded', 'local_jedomi', [
            'assignment' => s($data['activity']),
            'course' => s($data['course']),
        ], $data['lang']);
        api_client::instance()->send_message((string) $link->chatid, $text);
        return true;
    }

    /**
     * A stored name as plain text.
     *
     * @param string $name Name.
     * @param \context $context Context of the name.
     * @return string
     */
    private static function plain(string $name, \context $context): string {
        return html_entity_decode(
            strip_tags(format_string($name, true, ['context' => $context, 'escape' => false])),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * Whether the minimum interval since the last proactive message of a link has passed.
     *
     * @param \stdClass $link Link with a lastproactivesent field.
     * @return bool
     */
    public static function interval_passed(\stdClass $link): bool {
        return ((int) $link->lastproactivesent + self::COOLDOWN) <= self::now();
    }

    /**
     * Record now as the last proactive message of a link. Only that field is written, so the PIN state and the last
     * use written meanwhile by the bot are kept (RISK-002).
     *
     * @param string $channel Channel of the link.
     * @param \stdClass $link Link.
     */
    public static function mark_sent(string $channel, \stdClass $link): void {
        global $DB;
        $link->lastproactivesent = self::now();
        $DB->set_field(self::TABLES[$channel], 'lastproactivesent', $link->lastproactivesent, ['id' => $link->id]);
    }

    /**
     * Whether a grade notification may be sent to a link: after the minimum interval, or within it when it is about
     * an assignment not notified during the interval.
     *
     * @param \stdClass $link Link.
     * @param int $cmid Course module of the graded assignment.
     * @param array $notified Notified assignments, keyed by course module id, each with its time.
     * @return bool
     */
    private static function grade_interval_allows(\stdClass $link, int $cmid, array $notified): bool {
        if (self::interval_passed($link)) {
            return true;
        }
        $since = self::now() - self::COOLDOWN;
        $recent = array_filter($notified, static fn(array $entry): bool => $entry['time'] > $since);
        return !empty($recent) && !isset($recent[$cmid]);
    }

    /**
     * Current time of the Moodle clock.
     *
     * @return int
     */
    private static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
