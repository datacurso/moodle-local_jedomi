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
 * Port of the WhatsApp conversation: a message of a linked student who receives messages, other than a command.
 *
 * The chat_processor gets it from the container, \core\di::get(conversation_handler::class), when one is registered,
 * and uses whatsapp_conversation_handler otherwise; a test registers a double with \core\di::set().
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface conversation_handler {
    /**
     * Handle one message of the conversation (also NUEVO, which starts a new conversation).
     *
     * @param inbound $message Message.
     * @param \stdClass $link Link of the sender, already updated with this message.
     * @param \stdClass $user Account of the link, active and with a role the channel serves.
     */
    public function handle(inbound $message, \stdClass $link, \stdClass $user): void;
}
