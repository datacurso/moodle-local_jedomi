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

use local_jedomi\local\user_language;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Monthly limit of outgoing WhatsApp messages of each user (setting whatsapp_monthly_cap; 0, the default, is no limit).
 *
 * Replies, notices and templates count (usage_meter::outgoing()). The message that would pass the limit is replaced
 * by a single notice, sent while the 24-hour window is open and counted itself, so it is never repeated: past the limit
 * nothing more is sent until the next month of the Moodle clock. The answers to the commands (AYUDA, BAJA, ALTA,
 * DESVINCULAR) are not limited, so a student can always stop the messages or unlink.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class monthly_cap {
    /**
     * Whether one more outgoing message may be sent to a user this month.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function allows(int $userid): bool {
        $cap = manager::get_monthly_cap();
        return $cap === 0 || usage_meter::outgoing($userid) < $cap;
    }

    /**
     * Send the notice of the limit to a link, once: only when the outgoing messages of the month are exactly at the
     * limit (the notice itself then passes it) and the window is open.
     *
     * @param \stdClass $link Link of the student.
     * @return bool Whether the notice was sent now.
     */
    public static function notify(\stdClass $link): bool {
        $userid = (int) $link->userid;
        $cap = manager::get_monthly_cap();
        if ($cap === 0 || usage_meter::outgoing($userid) !== $cap || !empty($link->optedout)) {
            return false;
        }
        if (!window::is_open($link, \core\di::get(\core\clock::class)->time())) {
            return false;
        }
        try {
            $to = recipient_ref::from_link($link->waid ?? null, (string) $link->bsuid);
            $text = user_language::run($userid, static fn(): string => get_string('whatsapp_msg_monthly_cap', 'local_jedomi'));
            cloud_api_client::instance()->send_text($to, $text);
        } catch (\Throwable $e) {
            \local_jedomi_log('WhatsApp monthly limit notice not sent', [
                'chat' => \local_jedomi_log_pseudonym('wa', (string) $link->bsuid),
                'error' => \local_jedomi\local\safe_error::describe($e),
            ]);
            return false;
        }
        usage_meter::add($userid, usage_meter::OUTSERVICE);
        \local_jedomi_log('WhatsApp monthly limit reached', ['chat' => \local_jedomi_log_pseudonym('wa', (string) $link->bsuid)]);
        return true;
    }
}
