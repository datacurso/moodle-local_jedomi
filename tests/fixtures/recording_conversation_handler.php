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
 * Test double of the WhatsApp conversation: it records each message it receives.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_conversation_handler implements conversation_handler {
    /** @var array<int, array{message: inbound, link: \stdClass, user: \stdClass}> Messages received, in order. */
    public array $received = [];

    /**
     * Record the message.
     *
     * @param inbound $message Message.
     * @param \stdClass $link Link of the sender.
     * @param \stdClass $user Account of the link.
     */
    public function handle(inbound $message, \stdClass $link, \stdClass $user): void {
        $this->received[] = ['message' => $message, 'link' => $link, 'user' => $user];
    }
}
