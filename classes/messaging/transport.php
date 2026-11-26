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
 * Sending port of a messaging channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * How a channel (Telegram, WhatsApp) sends to a chat: plain messages, the activity status while the AI works, and the
 * final answer of the AI in the format of the channel. Held actions are shown by the confirmation_ui of the channel.
 */
interface transport {
    /**
     * Send a message already written for the channel (a string of the plugin, a progress line), split if too long.
     *
     * @param string $chatkey Chat key of the channel.
     * @param string $text Text in the format of the channel.
     * @return bool Whether the channel accepted every part.
     */
    public function send_text(string $chatkey, string $text): bool;

    /**
     * Show that the assistant is working (Telegram: "typing").
     *
     * @param string $chatkey Chat key of the channel.
     * @param int|null $timeout Seconds the request may take; null for the usual timeout of the channel.
     * @return bool Whether the channel accepted it.
     */
    public function send_activity(string $chatkey, ?int $timeout = null): bool;

    /**
     * Turn an answer of the AI (Markdown or HTML) into the format of the channel.
     *
     * @param string $markdown Answer of the AI.
     * @return string Text in the format of the channel; it may be empty when nothing could be kept.
     */
    public function format_answer(string $markdown): string;

    /**
     * Send the final answer of the AI, already formatted, as the channel shows answers best (Telegram: a text file for
     * a long or tabular answer).
     *
     * @param string $chatkey Chat key of the channel.
     * @param string $formatted Answer returned by format_answer().
     * @return bool Whether the channel accepted it.
     */
    public function send_answer(string $chatkey, string $formatted): bool;
}
