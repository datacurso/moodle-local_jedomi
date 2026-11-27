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

/**
 * Port that sends the short replies of the WhatsApp channel (link, commands, instructions).
 *
 * The chat_processor gets it from the container, \core\di::get(replier::class), when one is registered, and uses
 * cloud_api_replier otherwise.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface replier {
    /**
     * Send a text. A failure is not thrown: a reply that cannot be sent never fails the message it answers.
     *
     * @param recipient_ref $to Recipient.
     * @param string $text Text.
     * @return bool Whether the text was sent.
     */
    public function send(recipient_ref $to, string $text): bool;
}
