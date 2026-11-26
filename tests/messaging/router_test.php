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

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\provider_absence_probe;
use local_jedomi\telegram\api_client;
use local_jedomi\telegram\fake_telegram_api_client;
use local_jedomi\telegram\telegram_channel;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_continuation.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_transport.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_media_source.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_channel.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');

/**
 * Tests for the channel-neutral router of an inbound message, run with the Telegram channel and a recording transport.
 * The order of its steps is covered through the Telegram webhook (webhook_routing_test).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\router
 */
final class router_test extends \advanced_testcase {
    /** @var fake_channel Channel of the tests. */
    private fake_channel $channel;

    /** @var fake_telegram_api_client Telegram double, for what the channel commands send. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The router never asks the Datacurso AI provider, which the CI does not install.
        provider_absence_probe::start();
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $this->telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($this->telegram);
        $this->channel = new fake_channel(new telegram_channel());
        $this->channel->mediasource = new fake_media_source();
    }

    #[\Override]
    protected function assertPostConditions(): void {
        parent::assertPostConditions();
        $this->assertSame([], provider_absence_probe::touched(), 'The test reached the Datacurso AI provider.');
    }

    #[\Override]
    protected function tearDown(): void {
        api_client::set_instance_for_testing(null);
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * A typed message.
     *
     * @param string $chatkey Chat.
     * @param string $text Text.
     * @param bool $isprivate Whether the chat is private.
     * @return inbound_message
     */
    private function message(string $chatkey, string $text, bool $isprivate = true): inbound_message {
        return new inbound_message('telegram', $chatkey, '5', $text, null, null, '', $isprivate, 'someone', '5');
    }

    /**
     * Link a chat to a user.
     *
     * @param int $userid User.
     * @param string $chatkey Chat.
     * @return void
     */
    private function link(int $userid, string $chatkey): void {
        global $DB;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $userid, 'chatid' => $chatkey,
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => time(), 'lastused' => time(),
            'lastproactivesent' => 0]);
    }

    /**
     * Help is answered by the commands of the channel.
     */
    public function test_help_is_answered_by_the_channel_commands(): void {
        (new router($this->channel))->route($this->message('794000001', '/help'));

        $this->assertSame([], $this->channel->transport->sent);
        $this->assertStringContainsString(get_string('telegram_help_heading', 'local_jedomi'), $this->telegram->sent[0]['text']);
    }

    /**
     * A chat that is not private gets the private-only message of the channel.
     */
    public function test_chat_that_is_not_private_is_refused(): void {
        (new router($this->channel))->route($this->message('-100794000002', 'hello', false));

        $this->assertSame([channel::MESSAGE_PRIVATE_ONLY], $this->channel->transport->texts());
    }

    /**
     * A linked account that can no longer be served gets the inactive account message.
     */
    public function test_inactive_account_is_refused(): void {
        $user = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->link((int) $user->id, '794000003');

        (new router($this->channel))->route($this->message('794000003', 'hello'));

        $this->assertSame([channel::MESSAGE_ACCOUNT_INACTIVE], $this->channel->transport->texts());
    }

    /**
     * A linked active account reaches the media step: a message without text asks for text, and the AI is not called.
     */
    public function test_active_account_reaches_the_media_step(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->link((int) $user->id, '794000004');
        $ai = new fake_ai_client();
        client::set_instance_for_testing($ai);

        (new router($this->channel))->route($this->message('794000004', ''));

        $this->assertSame([channel::MESSAGE_TEXT_ONLY], $this->channel->transport->texts());
        $this->assertSame([], $ai->calls);
    }
}
