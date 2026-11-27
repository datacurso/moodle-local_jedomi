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
 * Port called when a free-form message cannot be sent because the 24-hour window of the student is closed (for
 * example a continuation delayed by the rescue task). The reply is already kept in the history of the conversation, so
 * the next message of the student shows it.
 *
 * The transport gets it from the container, \core\di::get(closed_window_handler::class), when one is registered, and
 * uses null_closed_window_handler otherwise. The notification templates register the one that sends the reply_ready
 * template to students who accepted the chat category.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface closed_window_handler {
    /**
     * A reply for the link waits in the history because its window is closed.
     *
     * @param \stdClass $link Link of the student.
     */
    public function reply_held(\stdClass $link): void;
}
