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
 * Telegram system prompt builder service.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

/**
 * Builds the system prompt sent to the AI for Telegram conversations: the channel-neutral prompt with the Telegram
 * format rules (see telegram_limits).
 *
 * Kept for tests; production uses \local_jedomi\messaging\prompt_builder (with the limits of the channel), to which
 * this thin delegate passes the Telegram limits.
 */
final class prompt_builder {
    /**
     * Build the system prompt for a Telegram conversation.
     *
     * @see \local_jedomi\messaging\prompt_builder::build()
     * @param  string $role            Resolved role of the linked user (admin/manager/teacher/student).
     * @param  string $userlang       Language the model must respond in.
     * @param  array  $toolnames      Names of the tools exposed to the model.
     * @param  array  $coursesbyrole Courses grouped by the user's role in them.
     * @return string The complete system prompt.
     */
    public static function build(
        string $role,
        string $userlang,
        array $toolnames,
        array $coursesbyrole
    ): string {
        $limits = manager::channel_limits();
        return \local_jedomi\messaging\prompt_builder::build($role, $userlang, $toolnames, $coursesbyrole, $limits);
    }
}
