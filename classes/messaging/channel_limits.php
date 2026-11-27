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
 * Limits and format of a messaging channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * What a channel (Telegram, WhatsApp) can show, as the AI must know it.
 */
interface channel_limits {
    /**
     * Format rules of the channel for the system prompt, as a block that ends with a blank line.
     *
     * @param string $userlang Language the model must respond in.
     * @return string
     */
    public function format_rules(string $userlang): string;

    /**
     * Whether the conversation engine sends a progress line (icon and tool names) while the tools run. The notice that
     * the assistant is still working (channel::MESSAGE_KEEPALIVE) is sent in every channel.
     *
     * @return bool
     */
    public function progress_messages(): bool;
}
