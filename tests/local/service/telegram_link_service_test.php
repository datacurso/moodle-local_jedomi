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
 * Tests for the service behind the Telegram profile page.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\telegram_link_service
 */
final class telegram_link_service_test extends \advanced_testcase {
    /**
     * The bot is available only when Telegram is enabled and a verified bot has a username.
     *
     * @return void
     */
    public function test_get_bot_status(): void {
        $this->resetAfterTest();
        set_config('telegram_enabled', 1, 'local_jedomi');
        set_config('telegram_bot_verified', 1, 'local_jedomi');
        unset_config('telegram_bot_username', 'local_jedomi');
        unset_config('telegram_bot_firstname', 'local_jedomi');

        $this->assertSame(
            ['available' => false, 'username' => '', 'firstname' => 'Jedomi'],
            telegram_link_service::get_bot_status()
        );

        set_config('telegram_bot_username', 'jedomibot', 'local_jedomi');
        set_config('telegram_bot_firstname', 'Bot', 'local_jedomi');
        $this->assertSame(
            ['available' => true, 'username' => 'jedomibot', 'firstname' => 'Bot'],
            telegram_link_service::get_bot_status()
        );

        set_config('telegram_enabled', 0, 'local_jedomi');
        $this->assertFalse(telegram_link_service::get_bot_status()['available']);
    }

    /**
     * Only confirmed links are returned, and unlinking removes every link of the user.
     *
     * @return void
     */
    public function test_get_confirmed_link_and_unlink(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $user->id, 'chatid' => '100',
            'username' => 'ada', 'confirmed' => 0, 'timecreated' => time()]);

        $this->assertNull(telegram_link_service::get_confirmed_link((int) $user->id));

        $DB->set_field('local_jedomi_telegram_links', 'confirmed', 1, ['userid' => $user->id]);
        $this->assertSame('ada', telegram_link_service::get_confirmed_link((int) $user->id)->username);

        telegram_link_service::unlink((int) $user->id);
        $this->assertFalse($DB->record_exists('local_jedomi_telegram_links', ['userid' => $user->id]));
    }
}
