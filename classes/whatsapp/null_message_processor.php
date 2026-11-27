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

namespace local_jedomi\whatsapp;

/**
 * Default message processor: it does nothing user-visible and sends nothing.
 *
 * Linking and the conversation through the WhatsApp channel come in later work units; until then a message taken from
 * the inbox is only marked done.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class null_message_processor implements message_processor {
    /**
     * Do nothing.
     *
     * @param inbound $message Message.
     */
    public function process(inbound $message): void {
    }
}
