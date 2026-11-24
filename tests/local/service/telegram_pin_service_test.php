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
 * Tests for the Telegram PIN of administrators and managers (Decision 7, SEC-JD-049).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\telegram_pin_service
 */
final class telegram_pin_service_test extends \advanced_testcase {
    /**
     * Link a chat to a user.
     *
     * @param int $userid User.
     * @param string $chatid Chat id.
     * @return void
     */
    private function link(int $userid, string $chatid): void {
        global $DB;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $userid, 'chatid' => $chatid,
            'username' => 'ada', 'confirmed' => 1, 'timecreated' => time()]);
    }

    /**
     * The PIN is required only with the site setting on and only of administrators and managers.
     *
     * @return void
     */
    public function test_pin_is_required_only_of_administrators_and_managers_with_the_setting_on(): void {
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_system::instance()->id);
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertFalse(telegram_pin_service::is_required($admin), 'Off by default');
        $this->assertFalse(telegram_pin_service::is_required((int) $manager->id), 'Off by default');

        set_config('telegram_require_pin', 1, 'local_jedomi');
        $this->assertTrue(telegram_pin_service::is_required($admin));
        $this->assertTrue(telegram_pin_service::is_required((int) $manager->id));
        $this->assertFalse(telegram_pin_service::is_required((int) $teacher->id));
        $this->assertFalse(telegram_pin_service::is_required((int) $student->id));
        $this->assertFalse(telegram_pin_service::is_required(0));
    }

    /**
     * The PIN has 6 to 8 digits and is stored only as a password hash.
     *
     * @return void
     */
    public function test_pin_format_and_hash(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(telegram_pin_service::set_pin((int) $user->id, '135790'), 'Not linked: nowhere to store it');
        $this->link((int) $user->id, '783100001');
        foreach (['12345', '123456789', '12a456', ' 123456', '', '１２３４５６'] as $bad) {
            $this->assertFalse(telegram_pin_service::is_valid_format($bad), $bad);
            $this->assertFalse(telegram_pin_service::set_pin((int) $user->id, $bad), $bad);
        }
        $this->assertFalse(telegram_pin_service::has_pin((int) $user->id));
        $this->assertTrue(telegram_pin_service::is_valid_format('12345678'));

        $this->assertTrue(telegram_pin_service::set_pin((int) $user->id, '135790'));

        $hash = (string) $DB->get_field('local_jedomi_telegram_links', 'pinhash', ['userid' => $user->id]);
        $this->assertTrue(telegram_pin_service::has_pin((int) $user->id));
        $this->assertStringNotContainsString('135790', $hash);
        $this->assertTrue(password_verify('135790', $hash));
    }

    /**
     * Three wrong PINs lock the writes for the lock time; a right PIN opens the 15-minute window and resets the count.
     *
     * @return void
     */
    public function test_verify_locks_after_three_failures_and_opens_the_window(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $this->link($userid, '783100002');

        $this->assertSame(telegram_pin_service::RESULT_NO_PIN, telegram_pin_service::verify($userid, '135790')['result']);
        telegram_pin_service::set_pin($userid, '135790');

        $this->assertSame(
            ['result' => telegram_pin_service::RESULT_WRONG, 'remaining' => 2],
            telegram_pin_service::verify($userid, '000000')
        );
        $this->assertSame(
            ['result' => telegram_pin_service::RESULT_WRONG, 'remaining' => 1],
            telegram_pin_service::verify($userid, '000001')
        );
        $locked = telegram_pin_service::verify($userid, '000002');
        $this->assertSame(telegram_pin_service::RESULT_LOCKED, $locked['result']);
        $this->assertGreaterThanOrEqual(time() + telegram_pin_service::LOCK_TIME - 5, telegram_pin_service::locked_until($userid));
        $this->assertSame(
            telegram_pin_service::RESULT_LOCKED,
            telegram_pin_service::verify($userid, '135790')['result'],
            'The right PIN is refused while locked'
        );
        $this->assertFalse(telegram_pin_service::in_window($userid));

        // The lock ends.
        $DB->set_field('local_jedomi_telegram_links', 'pinlockeduntil', time() - 1, ['userid' => $userid]);
        $this->assertSame(0, telegram_pin_service::locked_until($userid));
        $this->assertSame(telegram_pin_service::RESULT_OK, telegram_pin_service::verify($userid, '135790')['result']);
        $this->assertTrue(telegram_pin_service::in_window($userid));
        $this->assertSame(0, (int) $DB->get_field('local_jedomi_telegram_links', 'pinfailures', ['userid' => $userid]));

        // The window lasts 15 minutes.
        $this->assertSame(15 * MINSECS, telegram_pin_service::WINDOW);
        $DB->set_field(
            'local_jedomi_telegram_links',
            'pinverifiedat',
            time() - telegram_pin_service::WINDOW - 1,
            ['userid' => $userid]
        );
        $this->assertFalse(telegram_pin_service::in_window($userid));
    }

    /**
     * Setting a new PIN clears the failures, the lock and the window.
     *
     * @return void
     */
    public function test_new_pin_resets_the_state(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $this->link($userid, '783100003');
        telegram_pin_service::set_pin($userid, '135790');
        telegram_pin_service::verify($userid, '135790');
        telegram_pin_service::verify($userid, '000000');

        telegram_pin_service::set_pin($userid, '24681357');

        $link = $DB->get_record('local_jedomi_telegram_links', ['userid' => $userid]);
        $this->assertSame([0, 0, 0], [(int) $link->pinfailures, (int) $link->pinlockeduntil, (int) $link->pinverifiedat]);
        $this->assertTrue(password_verify('24681357', $link->pinhash));
    }

    /**
     * REL-005: a wrong PIN is counted in the database (pinfailures = pinfailures + 1), so failures counted by other
     * requests since the link was read are kept, and the limit locks the writes.
     */
    public function test_failures_are_counted_in_the_database(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->link((int) $user->id, '784100099');
        telegram_pin_service::set_pin((int) $user->id, '135790');
        $linkid = (int) $DB->get_field('local_jedomi_telegram_links', 'id', ['userid' => $user->id]);

        $this->assertSame(
            ['result' => telegram_pin_service::RESULT_WRONG, 'remaining' => 2],
            telegram_pin_service::record_failure($linkid)
        );
        // Another request counted a failure meanwhile: the next one starts from the database value.
        $DB->execute('UPDATE {local_jedomi_telegram_links} SET pinfailures = pinfailures + 1 WHERE id = ?', [$linkid]);

        $this->assertSame(
            ['result' => telegram_pin_service::RESULT_LOCKED, 'remaining' => 0],
            telegram_pin_service::record_failure($linkid)
        );
        $this->assertGreaterThan(time(), telegram_pin_service::locked_until((int) $user->id));
        $this->assertSame(0, (int) $DB->get_field('local_jedomi_telegram_links', 'pinfailures', ['id' => $linkid]));
    }
}
