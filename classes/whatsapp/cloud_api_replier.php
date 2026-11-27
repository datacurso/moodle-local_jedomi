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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Replier over the WhatsApp Cloud API client.
 *
 * A failure is logged with the HTTP status and the error code of Meta only: never the text of Meta, the recipient or
 * the reply.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cloud_api_replier implements replier {
    #[\Override]
    public function send(recipient_ref $to, string $text): bool {
        try {
            cloud_api_client::instance()->send_text($to, $text);
            return true;
        } catch (cloud_api_exception $e) {
            \local_jedomi_log('WhatsApp reply not sent', ['http' => $e->get_http_code(), 'code' => $e->get_meta_code()]);
        } catch (\moodle_exception $e) {
            \local_jedomi_log('WhatsApp reply not sent', ['error' => \local_jedomi\local\safe_error::describe($e)]);
        }
        return false;
    }
}
