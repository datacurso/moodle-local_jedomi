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

namespace local_jedomi\local\service;

/**
 * Bot availability and account links shown on the Telegram profile page.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_link_service {
    /**
     * Whether users can link their account, and the bot names.
     *
     * @return array{available: bool, username: string, firstname: string} The bot is available when Telegram is
     *     enabled and a verified bot has a username; the first name defaults to "Jedomi".
     */
    public static function get_bot_status(): array {
        $username = (string) (get_config('local_jedomi', 'telegram_bot_username') ?: '');
        return [
            'available' => \local_jedomi\telegram\manager::is_enabled()
                && (bool) get_config('local_jedomi', 'telegram_bot_verified') && $username !== '',
            'username' => $username,
            'firstname' => (string) (get_config('local_jedomi', 'telegram_bot_firstname') ?: 'Jedomi'),
        ];
    }

    /**
     * Confirmed Telegram link of a user.
     *
     * @param int $userid User id.
     * @return \stdClass|null The link record, or null when the user has no confirmed link.
     */
    public static function get_confirmed_link(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record('local_jedomi_telegram_links', ['userid' => $userid, 'confirmed' => 1]) ?: null;
    }

    /**
     * Remove every Telegram link of a user.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function unlink(int $userid): void {
        global $DB;
        $DB->delete_records('local_jedomi_telegram_links', ['userid' => $userid]);
    }
}
