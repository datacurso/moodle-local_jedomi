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
 * Limits and format of the Telegram channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\messaging\channel_limits;

/**
 * Telegram shows the HTML subset of the Bot API (see formatter), never Markdown.
 */
class telegram_limits implements channel_limits {
    #[\Override]
    public function format_rules(string $userlang): string {
        return "⚡ TELEGRAM RULES:\n"
            . "- The user is WAITING. Don't do extra iterations.\n"
            . "- Use ONLY Telegram HTML: <b>bold</b>, <i>italic</i>, <code>code</code>.\n"
            . "- NO markdown. NEVER use **bold** or *italic* — use <b> and <i>.\n"
            . "- Be concise, direct, respond in {$userlang} only.\n\n";
    }
}
