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
 * Default closed window handler: the reply is held for the next message of the student and, when it is the first one
 * held and the student accepted the chat category, the reply_ready template tells the student to write any message to
 * see it. Further replies held before the student writes again send no other template.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class whatsapp_closed_window_handler implements closed_window_handler {
    #[\Override]
    public function reply_held(\stdClass $link, string $text): void {
        if (!held_replies::hold($link, $text)) {
            return;
        }
        \local_jedomi_log('WhatsApp: window closed, the reply waits for the next message', [
            'chat' => \local_jedomi_log_pseudonym('wa', (string) ($link->bsuid ?? '')),
        ]);
        if (consent::accepted($link, consent::CHAT)) {
            template_sender::send($link, templates::REPLY_READY, []);
        }
    }
}
