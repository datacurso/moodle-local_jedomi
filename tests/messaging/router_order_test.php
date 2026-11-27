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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/neutral_channel.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');

/**
 * Order of the steps of the router, whatever the channel, run with neutral_channel (no Telegram, no Datacurso AI
 * provider): help, message limit, private chat, link, link commands, access, new conversation, media and conversation.
 * Each test sends a message two steps disagree on and checks which one answers. The same orders are checked through
 * the Telegram webhook in webhook_routing_test.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\router
 */
final class router_order_test extends \advanced_testcase {
    /** @var neutral_channel Channel of the tests. */
    private neutral_channel $channel;

    /** @var fake_ai_client AI double. */
    private fake_ai_client $ai;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider_absence_probe::start();
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
        $this->channel = new neutral_channel();
    }

    #[\Override]
    protected function assertPostConditions(): void {
        parent::assertPostConditions();
        $this->assertSame([], provider_absence_probe::touched(), 'The test reached the Datacurso AI provider.');
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        neutral_channel::drop_tables();
        parent::tearDown();
    }

    /**
     * Route a message of the channel, restoring the PHP time limit the AI loop raises.
     *
     * @param string $chatkey Chat.
     * @param string $text Text.
     * @param bool $isprivate Whether the chat is private.
     * @param media_ref|null $media File of the message.
     * @return void
     */
    private function route(string $chatkey, string $text, bool $isprivate = true, ?media_ref $media = null): void {
        $message = new inbound_message(neutral_channel::NAME, $chatkey, '5', $text, null, $media, '', $isprivate, 'someone', '5');
        $limit = (int) ini_get('max_execution_time');
        (new router($this->channel))->route($message);
        set_time_limit($limit);
    }

    /**
     * Link a chat to a new user.
     *
     * @param string $chatkey Chat.
     * @param bool $suspended Whether the account is suspended.
     * @return int User id.
     */
    private function linked_user(string $chatkey, bool $suspended = false): int {
        $userid = (int) $this->getDataGenerator()->create_user(['suspended' => $suspended ? 1 : 0])->id;
        $this->channel->links[$chatkey] = $userid;
        return $userid;
    }

    /**
     * Help comes before the private chat check: a group gets the help.
     */
    public function test_help_is_answered_in_a_group(): void {
        $this->route('nc201', 'help', false);

        $this->assertSame(['help:nc201'], $this->channel->commands->calls);
        $this->assertSame([], $this->channel->transport->sent);
    }

    /**
     * The message limit comes before the private chat check: a group over the limit gets the limit notice.
     */
    public function test_message_limit_comes_before_the_private_chat_check(): void {
        set_config(neutral_channel::NAME . '_rate_limit', 1, 'local_jedomi');
        set_config(neutral_channel::NAME . '_cooldown', 5, 'local_jedomi');

        $this->route('nc202', 'hello', false);
        $this->route('nc202', 'hello', false);

        $this->assertSame([channel::MESSAGE_PRIVATE_ONLY, channel::MESSAGE_RATE_LIMITED], $this->channel->transport->texts());
    }

    /**
     * The private chat check comes before the link: a link request sent from a group links nothing.
     */
    public function test_link_request_sent_from_a_group_links_nothing(): void {
        $this->channel->commands->linkrequests['link 123456'] = '123456';

        $this->route('nc203', 'link 123456', false);

        $this->assertSame([channel::MESSAGE_PRIVATE_ONLY], $this->channel->transport->texts());
        $this->assertSame([], $this->channel->commands->calls);
    }

    /**
     * Help comes before the link: the help of a chat whose link expired does not expire it.
     */
    public function test_help_does_not_expire_the_link(): void {
        $this->linked_user('nc204');
        $this->channel->commands->expired = true;

        $this->route('nc204', 'help');

        $this->assertSame(['help:nc204'], $this->channel->commands->calls);
    }

    /**
     * An expired link is told to link again; a link request in the same message links the chat at once.
     */
    public function test_expired_link_is_told_to_link_again_unless_the_message_links(): void {
        $this->linked_user('nc205');
        $this->channel->commands->expired = true;
        $this->channel->commands->linkrequests['link 123456'] = '123456';

        $this->route('nc205', 'hello');
        $this->route('nc205', 'link 123456');

        $this->assertSame(['link_expired:nc205', 'link:nc205'], $this->channel->commands->calls);
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * A chat that is not linked can only link itself: anything else asks to link first.
     */
    public function test_chat_that_is_not_linked_is_asked_to_link_first(): void {
        $this->route('nc206', 'hello');

        $this->assertSame(['link_first:nc206'], $this->channel->commands->calls);
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * Unlinking comes before the access check: a suspended account can still unlink its chat.
     */
    public function test_suspended_account_can_unlink(): void {
        $this->linked_user('nc207', true);
        $this->channel->commands->unlinkword = 'unlink';

        $this->route('nc207', 'unlink');

        $this->assertSame(['unlink:nc207'], $this->channel->commands->calls);
        $this->assertSame([], $this->channel->transport->sent);
    }

    /**
     * A link request comes before the access check: the chat of a suspended account can move to another account.
     */
    public function test_link_request_moves_the_chat_of_a_suspended_account(): void {
        $this->linked_user('nc208', true);
        $this->channel->commands->linkrequests['link 123456'] = '123456';

        $this->route('nc208', 'link 123456');

        $this->assertSame(['link:nc208'], $this->channel->commands->calls);
        $this->assertSame([], $this->channel->transport->sent);
    }

    /**
     * The access check comes before a new conversation: a suspended account gets the notice, not a greeting.
     */
    public function test_suspended_account_cannot_start_a_new_conversation(): void {
        $this->linked_user('nc209', true);
        $this->channel->commands->newword = 'new';

        $this->route('nc209', 'new');

        $this->assertSame([channel::MESSAGE_ACCOUNT_INACTIVE], $this->channel->transport->texts());
        $this->assertSame([], $this->channel->commands->calls);
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * The access check comes before the media: the file of a suspended account is not read.
     */
    public function test_file_of_a_suspended_account_is_not_read(): void {
        $this->linked_user('nc210', true);
        $this->channel->mediasource->files = ['photo-1' => 'jpeg bytes'];

        $this->route('nc210', '', true, new media_ref(media_ref::IMAGE, 'photo-1', 'photo.jpg', 'image/jpeg'));

        $this->assertSame([channel::MESSAGE_ACCOUNT_INACTIVE], $this->channel->transport->texts());
        $this->assertSame([], $this->channel->mediasource->requested);
    }

    /**
     * The new conversation command comes before the media: a message without text asks for text, while the new
     * conversation command reaches the AI as the greeting of the channel.
     */
    public function test_new_conversation_reaches_the_ai_as_a_greeting(): void {
        $this->linked_user('nc211');
        $this->channel->commands->newword = 'new';
        $this->ai->enqueue_text('Hello again.');

        $this->route('nc211', '');
        $this->route('nc211', 'new');

        $this->assertSame(channel::MESSAGE_TEXT_ONLY, $this->channel->transport->texts()[0]);
        $this->assertSame(['new_conversation:nc211'], $this->channel->commands->calls);
        $this->assertCount(1, $this->ai->calls);
        $this->assertSame('hello', end($this->ai->calls[0]['messages'])['content']);
    }
}
