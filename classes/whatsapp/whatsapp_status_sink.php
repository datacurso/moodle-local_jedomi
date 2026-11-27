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

use local_jedomi\event\whatsapp_opted_out;
use local_jedomi\local\service\whatsapp_link_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Default status sink of the WhatsApp channel.
 *
 * Sent, delivered and read statuses change nothing. A failed status is logged with its error codes and a pseudonym of
 * the recipient (never the BSUID, the phone or the message id), and when one of its codes means that the user stopped
 * the messages (manager::get_opt_out_error_codes()) the link of the recipient is marked as opted out, as BAJA does,
 * once. The usage counters are not changed: they count the messages sent, delivered or not, which is what the monthly
 * limit applies to.
 *
 * Meta can deliver the same status more than once, so each status is handled once per message id and state: the pair
 * is remembered in the whatsapp_statuses cache, for as long as Meta retries a webhook.
 *
 * The link is found by the BSUID of the recipient and, when Meta omitted it (a message sent to a phone), by the phone.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class whatsapp_status_sink implements status_sink {
    #[\Override]
    public function record(delivery_status $status): void {
        if (!$status->is_failed() || !self::first_time($status)) {
            return;
        }
        $link = self::find_link($status);
        $chat = $status->recipientbsuid !== '' ? $status->recipientbsuid : (string) $status->recipientwaid;
        \local_jedomi_log('WhatsApp: message not delivered', [
            'chat' => \local_jedomi_log_pseudonym('wa', $chat),
            'codes' => $status->errorcodes,
        ]);
        if ($link === null || !empty($link->optedout)) {
            return;
        }
        if (array_intersect($status->errorcodes, manager::get_opt_out_error_codes()) === []) {
            return;
        }
        global $DB;
        $DB->update_record(whatsapp_link_service::LINKS_TABLE, (object) ['id' => $link->id, 'optedout' => 1,
            'optoutat' => \core\di::get(\core\clock::class)->time()]);
        whatsapp_opted_out::create_for_user((int) $link->userid)->trigger();
        \local_jedomi_log('WhatsApp: messages stopped after a failed status', [
            'chat' => \local_jedomi_log_pseudonym('wa', (string) $link->bsuid),
        ]);
    }

    /**
     * Whether the status (message id and state) is seen for the first time; it is remembered from now on.
     *
     * @param delivery_status $status Status.
     * @return bool
     */
    private static function first_time(delivery_status $status): bool {
        $cache = \cache::make('local_jedomi', 'whatsapp_statuses');
        // The message id is not personal data, but it is long and has free characters: the key is its hash.
        $key = sha1($status->wamid . '|' . $status->status);
        if ($cache->get($key) !== false) {
            return false;
        }
        $cache->set($key, 1);
        return true;
    }

    /**
     * Link of the recipient of a status, by BSUID and then by phone; null when none.
     *
     * @param delivery_status $status Status.
     * @return \stdClass|null
     */
    private static function find_link(delivery_status $status): ?\stdClass {
        global $DB;
        if ($status->recipientbsuid !== '') {
            $link = $DB->get_record(whatsapp_link_service::LINKS_TABLE, ['bsuid' => $status->recipientbsuid]);
            if ($link) {
                return $link;
            }
        }
        if ($status->recipientwaid !== null && $status->recipientwaid !== '') {
            $links = $DB->get_records(whatsapp_link_service::LINKS_TABLE, ['waid' => $status->recipientwaid], 'id ASC', '*', 0, 1);
            if ($links) {
                return reset($links);
            }
        }
        return null;
    }
}
