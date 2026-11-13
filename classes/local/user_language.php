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

namespace local_jedomi\local;

/**
 * Runs code in the language of the user who receives its texts (Telegram answers, notices of the agents).
 *
 * The webhook and the scheduled tasks have no session of the recipient, so get_string() would answer in the site or
 * task language. The language of the user is used when the user is known and the language pack is installed; the site
 * language otherwise.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_language {
    /**
     * Language for the texts sent to a user.
     *
     * @param int|null $userid Recipient, or null when the user is not known yet.
     * @return string Language code.
     */
    public static function for_user(?int $userid): string {
        global $CFG;
        $sitelang = !empty($CFG->lang) ? (string) $CFG->lang : 'en';
        if (empty($userid)) {
            return $sitelang;
        }
        $user = \core_user::get_user($userid, 'id, lang');
        if (!$user || empty($user->lang) || !get_string_manager()->translation_exists($user->lang, false)) {
            return $sitelang;
        }
        return (string) $user->lang;
    }

    /**
     * Run a callback with the texts in the language of a user, restoring the previous language afterwards.
     *
     * @param int|null $userid Recipient, or null when the user is not known yet (site language).
     * @param callable $callback Code to run.
     * @return mixed What the callback returns.
     */
    public static function run(?int $userid, callable $callback) {
        $previous = force_current_language(self::for_user($userid));
        try {
            return $callback();
        } finally {
            force_current_language($previous);
        }
    }
}
