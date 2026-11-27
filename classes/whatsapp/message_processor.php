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
 * Port that handles a WhatsApp message taken from the inbox (linking and conversation).
 *
 * The inbox_processor gets it from the container, \core\di::get(message_processor::class), when one is registered,
 * and uses null_message_processor otherwise; a test registers a double with \core\di::set().
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface message_processor {
    /**
     * Handle one message. An exception leaves the row for the rescue task to retry.
     *
     * @param inbound $message Message.
     */
    public function process(inbound $message): void;
}
