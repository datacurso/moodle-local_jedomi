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

namespace local_jedomi\telegram;

use local_jedomi\local\service\telegram_pin_service;
use local_jedomi\messaging\inbound_message;
use local_jedomi\messaging\pin_policy;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for the Telegram PIN policy: the Telegram PIN of administrators and managers, its texts and the link to set it.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\telegram_pin_policy
 */
final class telegram_pin_policy_test extends \advanced_testcase {
    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($this->telegram);
    }

    #[\Override]
    protected function tearDown(): void {
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * The policy answers as telegram_pin_service for the user of the Telegram link.
     */
    public function test_follows_the_telegram_pin_service(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $adminid = (int) get_admin()->id;
        $student = (int) $this->getDataGenerator()->create_user()->id;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $adminid, 'chatid' => '7001',
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        $policy = new telegram_pin_policy();

        $this->assertTrue($policy->available());
        $this->assertTrue($policy->is_required($adminid));
        $this->assertFalse($policy->is_required($student));
        $this->assertTrue($policy->is_pin('246810'));
        $this->assertFalse($policy->is_pin('hello'));
        $this->assertFalse($policy->has_pin($adminid));
        $this->assertSame(['result' => pin_policy::RESULT_NO_PIN, 'remaining' => 0], $policy->verify($adminid, '246810'));

        telegram_pin_service::set_pin($adminid, '246810');
        $this->assertTrue($policy->has_pin($adminid));
        $this->assertFalse($policy->in_window($adminid));
        $this->assertSame(['result' => pin_policy::RESULT_WRONG, 'remaining' => 2], $policy->verify($adminid, '000000'));
        $this->assertSame(
            ['result' => pin_policy::RESULT_OK, 'remaining' => telegram_pin_service::MAX_ATTEMPTS],
            $policy->verify($adminid, '246810')
        );
        $this->assertTrue($policy->in_window($adminid));
        $this->assertSame(0, $policy->locked_until($adminid));
        $policy->verify($adminid, '000000');
        $policy->verify($adminid, '000000');
        $this->assertSame(['result' => pin_policy::RESULT_LOCKED, 'remaining' => 0], $policy->verify($adminid, '000000'));
        $this->assertSame(telegram_pin_service::locked_until($adminid), $policy->locked_until($adminid));
        $this->assertGreaterThan(time(), $policy->locked_until($adminid));
    }

    /**
     * The texts are the Telegram PIN strings, with the link to the Telegram page of the profile.
     */
    public function test_texts_are_the_telegram_pin_strings(): void {
        $policy = new telegram_pin_policy();
        $url = (new \moodle_url('/local/jedomi/telegram_profile.php'))->out(false);

        $this->assertSame(get_string('telegram_pin_missing', 'local_jedomi', $url), $policy->missing_message());
        $this->assertSame(get_string('telegram_pin_locked', 'local_jedomi', 3), $policy->locked_message(time() + 3 * MINSECS));
        $this->assertSame(get_string('telegram_pin_locked', 'local_jedomi', 1), $policy->locked_message(time() - 1));
        $this->assertSame(get_string('telegram_pin_wrong', 'local_jedomi', 2), $policy->wrong_message(2));
    }

    /**
     * The message with the PIN is deleted from the chat; one without an id is left as is.
     */
    public function test_hide_deletes_the_message_with_the_pin(): void {
        $policy = new telegram_pin_policy();

        $policy->hide(new inbound_message('telegram', '7001', '1', '246810', null, null, '', true, 'someone', '42'));
        $policy->hide(new inbound_message('telegram', '7001', '1', '246810', null, null, '', true, 'someone', ''));

        $this->assertSame([['chatid' => '7001', 'messageid' => 42]], $this->telegram->deleted);
    }

    /**
     * The Telegram confirmation service asks for the PIN with this policy: a write of an administrator without a PIN is
     * refused with the Telegram texts and link.
     */
    public function test_manager_builds_the_confirmation_service_with_the_telegram_pin(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $adminid = (int) get_admin()->id;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $adminid, 'chatid' => '7001',
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        $service = manager::confirmation_service();

        $this->assertInstanceOf(telegram_pin_policy::class, $service->pins());
        $expected = ['status' => 'refused', 'reason' => 'pin_required',
            'note' => 'The user must set a Telegram PIN in Moodle before any change. Do not call it again.'];
        $this->assertSame($expected, json_decode((string) $service->hold('7001', $adminid, 'delete_agent', ['id' => 1]), true));
        $url = (new \moodle_url('/local/jedomi/telegram_profile.php'))->out(false);
        $this->assertSame([get_string('telegram_pin_missing', 'local_jedomi', $url)], array_column($this->telegram->sent, 'text'));
    }
}
