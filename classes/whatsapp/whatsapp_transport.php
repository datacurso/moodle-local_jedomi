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

namespace local_jedomi\whatsapp;

use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\transport;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Transport of the WhatsApp channel, over the Cloud API client.
 *
 * The chat key (wa_ plus the BSUID) is resolved to the link of the student, which is addressed by its phone ("to")
 * when Meta shared it and by its BSUID ("recipient") otherwise; only one of them is sent. A user at the monthly limit
 * gets its single notice and nothing else (monthly_cap). Free-form messages are sent only while the 24-hour window of
 * the student is open; outside it nothing is sent and the closed window handler is given the text. Texts are split by
 * paragraph into parts of whatsapp_limits::MESSAGE_LENGTH characters, at most whatsapp_limits::MAX_PARTS messages, the
 * last one a link to the web chat when the answer is longer. The activity marks the latest message of the student as
 * read and shows the typing indicator.
 *
 * An error of Meta is logged with its HTTP status and error code only, and never shown to the student.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_transport implements transport {
    /** @var string Page of the web chat offered when an answer does not fit in WhatsApp. */
    public const WEB_CHAT_PATH = '/my/';

    /** @var whatsapp_limits Limits of the channel. */
    private whatsapp_limits $limits;

    /**
     * Transport with the limits of the channel.
     *
     * @param whatsapp_limits|null $limits Limits; the default ones when null.
     */
    public function __construct(?whatsapp_limits $limits = null) {
        $this->limits = $limits ?? new whatsapp_limits();
    }

    #[\Override]
    public function send_text(string $chatkey, string $text): bool {
        return $this->deliver($chatkey, $text);
    }

    #[\Override]
    public function send_activity(string $chatkey, ?int $timeout = null): bool {
        global $DB;
        $link = self::link($chatkey);
        if ($link === null) {
            return false;
        }
        $rows = $DB->get_records_select(
            whatsapp_link_service::INBOX_TABLE,
            'bsuid = :bsuid AND status <> :failed',
            ['bsuid' => $link->bsuid, 'failed' => inbox_processor::STATUS_FAILED],
            'timecreated DESC, id DESC',
            'id, wamid',
            0,
            1
        );
        $row = reset($rows);
        if (!$row) {
            return false;
        }
        try {
            cloud_api_client::instance()->mark_read_typing((string) $row->wamid, $timeout);
            return true;
        } catch (\Throwable $e) {
            self::log_failure('typing', $e);
            return false;
        }
    }

    #[\Override]
    public function format_answer(string $markdown): string {
        return formatter::to_whatsapp($markdown);
    }

    #[\Override]
    public function is_blank(string $formatted): bool {
        // Format characters alone show nothing.
        return trim((string) preg_replace('/[\s*_~\x60]+/u', '', $formatted)) === '';
    }

    #[\Override]
    public function send_answer(string $chatkey, string $formatted): bool {
        return $this->deliver($chatkey, $formatted);
    }

    /**
     * Send a text in parts to the link of a chat, while its window is open.
     *
     * @param string $chatkey Chat key.
     * @param string $text WhatsApp text.
     * @return bool Whether every part was sent.
     */
    private function deliver(string $chatkey, string $text): bool {
        $link = self::link($chatkey);
        if ($link === null) {
            return false;
        }
        if (!monthly_cap::allows((int) $link->userid)) {
            monthly_cap::notify($link);
            return false;
        }
        if (!window::is_open($link, \core\di::get(\core\clock::class)->time())) {
            self::closed_window_handler()->reply_held($link, $text);
            return false;
        }
        try {
            $to = recipient_ref::from_link($link->waid, $link->bsuid);
        } catch (\invalid_parameter_exception $e) {
            return false;
        }
        $client = cloud_api_client::instance();
        foreach ($this->parts($text) as $index => $part) {
            if ($index > 0 && !monthly_cap::allows((int) $link->userid)) {
                // The limit is reached within a long answer: the notice replaces the rest.
                monthly_cap::notify($link);
                return false;
            }
            try {
                $client->send_text($to, $part);
            } catch (\Throwable $e) {
                self::log_failure('text', $e);
                return false;
            }
            usage_meter::add((int) $link->userid, usage_meter::OUTSERVICE);
        }
        return true;
    }

    /**
     * Parts of a text: at most MAX_PARTS; a longer text keeps one part less and ends with a link to the web chat.
     *
     * @param string $text Text.
     * @return string[]
     */
    private function parts(string $text): array {
        $parts = formatter::chunk($text, $this->limits->message_length());
        $max = $this->limits->max_parts();
        if (count($parts) <= $max) {
            return $parts;
        }
        $parts = array_slice($parts, 0, $max - 1);
        $parts[] = get_string(
            'whatsapp_msg_continue_on_web',
            'local_jedomi',
            (new \moodle_url(self::WEB_CHAT_PATH))->out(false)
        );
        return $parts;
    }

    /**
     * Link of a chat key that receives messages, or null.
     *
     * @param string $chatkey Chat key (wa_ plus the BSUID).
     * @return \stdClass|null
     */
    private static function link(string $chatkey): ?\stdClass {
        global $DB;
        $bsuid = whatsapp_channel::bsuid_of($chatkey);
        if ($bsuid === null) {
            return null;
        }
        $link = $DB->get_record(whatsapp_link_service::LINKS_TABLE, ['bsuid' => $bsuid, 'confirmed' => 1]);
        return $link && empty($link->optedout) ? $link : null;
    }

    /**
     * Closed window handler registered in the container, or the one that holds the reply and sends reply_ready.
     *
     * @return closed_window_handler
     */
    private static function closed_window_handler(): closed_window_handler {
        $container = \core\di::get_container();
        return $container->has(closed_window_handler::class)
            ? $container->get(closed_window_handler::class)
            : new whatsapp_closed_window_handler();
    }

    /**
     * Log a failed request with the HTTP status and the error code of Meta only.
     *
     * @param string $kind What was sent.
     * @param \Throwable $e Error.
     */
    private static function log_failure(string $kind, \Throwable $e): void {
        if ($e instanceof cloud_api_exception) {
            \local_jedomi_log('WhatsApp ' . $kind . ' not sent', ['http' => $e->get_http_code(), 'code' => $e->get_meta_code()]);
            return;
        }
        \local_jedomi_log('WhatsApp ' . $kind . ' not sent', ['error' => \local_jedomi\local\safe_error::describe($e)]);
    }
}
