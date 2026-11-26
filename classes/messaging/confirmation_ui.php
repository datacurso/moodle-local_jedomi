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

/**
 * Presentation port of the confirmations of a messaging channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * How a channel (Telegram, WhatsApp) shows a held action and reads the answer of the user. The rules (what is held,
 * which answer confirms what, the PIN, the audit) belong to confirmation_service; the channel only formats and sends.
 */
interface confirmation_ui {
    /**
     * Characters of the data of an action the channel shows whole in one message; a longer action is not held.
     *
     * @return int
     */
    public function details_length(): int;

    /**
     * Show the card of a held action with its buttons.
     *
     * @param string $chatkey Chat key of the channel.
     * @param confirmation_card $card Card of the action.
     * @return void
     */
    public function show(string $chatkey, confirmation_card $card): void;

    /**
     * Send a plain message about a held action (e.g. why a change is refused).
     *
     * @param string $chatkey Chat key of the channel.
     * @param string $message Message for the user.
     * @return void
     */
    public function notify(string $chatkey, string $message): void;

    /**
     * Read the payload of a button pressed by the user as an answer to a held action.
     *
     * @param string $payload Button payload (Telegram callback data).
     * @return confirmation_reply|null The answer, or null when the payload is not an answer to a held action.
     */
    public function parse_reply(string $payload): ?confirmation_reply;

    /**
     * Message asking the user to type the confirmation word of a critical action.
     *
     * @return string
     */
    public function keyword_prompt(): string;

    /**
     * Message asking the user to type their PIN.
     *
     * @return string
     */
    public function pin_prompt(): string;
}
