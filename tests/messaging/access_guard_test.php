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

namespace local_jedomi\messaging;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');

/**
 * Tests for the channel-neutral access guard and the per-channel role rule.
 *
 * The plugin state comes from the container, so the tests fix it with fake_plugin_state and run without the Datacurso
 * AI provider. The channel settings are derived from the channel name: {channel}_enabled and {channel}_hidden_roles.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\access_guard
 * @covers     ::local_jedomi_channel_role_allowed_for
 * @covers     ::local_jedomi_telegram_role_allowed_for
 * @covers     ::local_jedomi_telegram_active_user
 */
final class access_guard_test extends \advanced_testcase {
    /**
     * Channels the guard is checked with: Telegram and a channel that has no code of its own.
     *
     * @return array
     */
    public static function channel_provider(): array {
        return [
            'telegram' => ['telegram'],
            'other channel' => ['testchannel'],
        ];
    }

    /**
     * Jedomi disabled in the provider comes first, with the provider's error; then the channel integration setting.
     *
     * @dataProvider channel_provider
     * @param string $channel
     */
    public function test_channel_denial_checks_the_plugin_then_the_integration(string $channel): void {
        $this->resetAfterTest();
        $guard = new access_guard($channel);

        \core\di::set(plugin_state::class, fake_plugin_state::disabled('Jedomi is off'));
        set_config($channel . '_enabled', 1, 'local_jedomi');
        $this->assertSame(['code' => 'plugindisabled', 'message' => 'Jedomi is off'], $guard->channel_denial());

        set_config($channel . '_enabled', 0, 'local_jedomi');
        $this->assertSame('plugindisabled', $guard->channel_denial()['code'], 'The plugin state is checked first');

        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $this->assertSame(
            ['code' => access_guard::INTEGRATION_DISABLED, 'message' => ''],
            $guard->channel_denial()
        );

        unset_config($channel . '_enabled', 'local_jedomi');
        $this->assertSame(access_guard::INTEGRATION_DISABLED, $guard->channel_denial()['code']);

        set_config($channel . '_enabled', 1, 'local_jedomi');
        $this->assertNull($guard->channel_denial());
    }

    /**
     * Each channel reads its own integration setting.
     */
    public function test_each_channel_reads_its_own_enabled_setting(): void {
        $this->resetAfterTest();
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        set_config('telegram_enabled', 1, 'local_jedomi');
        set_config('testchannel_enabled', 0, 'local_jedomi');

        $this->assertNull((new access_guard('telegram'))->channel_denial());
        $this->assertSame(access_guard::INTEGRATION_DISABLED, (new access_guard('testchannel'))->channel_denial()['code']);
    }

    /**
     * Only an existing, not suspended account that can log in and is not the guest is served.
     */
    public function test_active_user_rejects_accounts_that_cannot_be_served(): void {
        global $DB;
        $this->resetAfterTest();
        $guard = new access_guard('telegram');
        $active = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $nologin = $this->getDataGenerator()->create_user(['auth' => 'nologin']);
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($DB->get_record('user', ['id' => $deleted->id]));

        $this->assertSame((int) $active->id, (int) $guard->active_user((int) $active->id)->id);
        $this->assertNull($guard->active_user((int) $suspended->id));
        $this->assertNull($guard->active_user((int) $nologin->id));
        $this->assertNull($guard->active_user((int) $deleted->id));
        $this->assertNull($guard->active_user((int) guest_user()->id));
        $this->assertNull($guard->active_user(0));

        // The Telegram function applies the same rule.
        $this->assertSame((int) $active->id, (int) local_jedomi_telegram_active_user((int) $active->id)->id);
        $this->assertNull(local_jedomi_telegram_active_user((int) $suspended->id));
    }

    /**
     * The role rule of a channel reads that channel's hidden roles setting; admins are always allowed.
     *
     * @dataProvider channel_provider
     * @param string $channel
     */
    public function test_channel_role_rule_reads_the_channel_hidden_roles(string $channel): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $noroles = $this->getDataGenerator()->create_user();
        $studentrole = (int) $this->role_id('student');
        $managerrole = (int) $this->role_id('manager');
        $guard = new access_guard($channel);

        unset_config($channel . '_hidden_roles', 'local_jedomi');
        $this->assertTrue(local_jedomi_channel_role_allowed_for($channel, (int) $student->id), 'No restriction configured');

        set_config($channel . '_hidden_roles', (string) $studentrole, 'local_jedomi');
        $this->assertFalse(local_jedomi_channel_role_allowed_for($channel, (int) $student->id), 'Course role is hidden');
        $this->assertFalse($guard->role_allowed((int) $student->id));
        $this->assertTrue(local_jedomi_channel_role_allowed_for($channel, (int) $noroles->id), 'A user without roles');

        set_config($channel . '_hidden_roles', (string) $managerrole, 'local_jedomi');
        $this->assertTrue(local_jedomi_channel_role_allowed_for($channel, (int) $student->id));
        $this->assertTrue($guard->role_allowed((int) $student->id));

        set_config($channel . '_hidden_roles', $studentrole . ',' . $managerrole, 'local_jedomi');
        $this->assertTrue(local_jedomi_channel_role_allowed_for($channel, (int) get_admin()->id), 'Admins always');
        $this->assertFalse(local_jedomi_channel_role_allowed_for($channel, 0));
        $this->assertFalse($guard->role_allowed(0));
    }

    /**
     * The Telegram role function is the channel rule for 'telegram'; another channel's setting does not affect it.
     */
    public function test_telegram_role_rule_is_the_channel_rule_for_telegram(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $studentrole = (string) $this->role_id('student');

        set_config('testchannel_hidden_roles', $studentrole, 'local_jedomi');
        unset_config('telegram_hidden_roles', 'local_jedomi');
        $this->assertTrue(local_jedomi_telegram_role_allowed_for((int) $student->id));
        $this->assertFalse(local_jedomi_channel_role_allowed_for('testchannel', (int) $student->id));

        set_config('telegram_hidden_roles', $studentrole, 'local_jedomi');
        $this->assertFalse(local_jedomi_telegram_role_allowed_for((int) $student->id));
        $this->assertSame(
            local_jedomi_channel_role_allowed_for('telegram', (int) $student->id),
            local_jedomi_telegram_role_allowed_for((int) $student->id)
        );
    }

    /**
     * A served user has an active account and a role allowed by the channel.
     */
    public function test_served_user_needs_an_active_account_and_an_allowed_role(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $guard = new access_guard('testchannel');

        unset_config('testchannel_hidden_roles', 'local_jedomi');
        $this->assertSame((int) $student->id, (int) $guard->served_user((int) $student->id)->id);
        $this->assertNull($guard->served_user((int) $suspended->id), 'Inactive account');

        set_config('testchannel_hidden_roles', (string) $this->role_id('student'), 'local_jedomi');
        $this->assertNull($guard->served_user((int) $student->id), 'Hidden role');
    }

    /**
     * Id of a standard role.
     *
     * @param string $shortname
     * @return int
     */
    private function role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }
}
