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

namespace local_jedomi\telegram;

use local_jedomi\messaging\channel;
use local_jedomi\messaging\fake_continuation;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_continuation.php');

/**
 * Tests for the Telegram channel: the pieces of the Telegram bot the conversation engine works with.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\telegram_channel
 * @covers     \local_jedomi\telegram\manager
 */
final class telegram_channel_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        manager::set_continuation_for_testing(null);
        parent::tearDown();
    }

    /**
     * Link a chat to a user.
     *
     * @param int $userid User.
     * @param string $chatid Chat id.
     * @param int $confirmed Whether the link is confirmed.
     */
    private function link(int $userid, string $chatid, int $confirmed = 1): void {
        global $DB;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $userid, 'chatid' => $chatid,
            'username' => 'linked', 'confirmed' => $confirmed, 'timecreated' => time(), 'lastproactivesent' => 0]);
    }

    /**
     * The manager gives the Telegram channel, named after the Telegram settings and events.
     */
    public function test_manager_gives_the_telegram_channel(): void {
        $channel = manager::channel();

        $this->assertInstanceOf(channel::class, $channel);
        $this->assertInstanceOf(telegram_channel::class, $channel);
        $this->assertSame('telegram', $channel->name());
        $this->assertSame('Telegram', $channel->label());
    }

    /**
     * The pieces are those of the Telegram bot: its transport, its continuation (replaceable in tests) and its limits.
     */
    public function test_pieces_are_those_of_the_telegram_bot(): void {
        $channel = new telegram_channel();
        $this->assertInstanceOf(telegram_transport::class, $channel->transport());
        $this->assertInstanceOf(telegram_continuation::class, $channel->continuation());
        $this->assertInstanceOf(telegram_limits::class, $channel->limits());
        $this->assertEquals(manager::access_guard(), $channel->access_guard());
        $this->assertEquals(manager::tool_runner(), $channel->tool_runner());

        $fake = new fake_continuation();
        manager::set_continuation_for_testing($fake);
        $this->assertSame($fake, $channel->continuation());
    }

    /**
     * The store is the Telegram session table: what the channel saves is what the Telegram session reads.
     */
    public function test_store_is_the_telegram_session_store(): void {
        $this->resetAfterTest();
        $channel = new telegram_channel();

        $channel->store()->set_context('tool_loop_783100001', ['iteration' => 0, 'session_gen' => 9]);
        $channel->store()->append('783100001', 'user', 'hola');

        $this->assertSame(['iteration' => 0, 'session_gen' => 9], session::get_context('tool_loop_783100001'));
        $this->assertSame([['role' => 'user', 'content' => 'hola']], session::get('783100001'));
    }

    /**
     * The AI timeout follows the Telegram setting, between 5 s and the longest call a worker fits; "typing" is sent
     * again every 4 s with a timeout of 2 s.
     */
    public function test_timeouts_follow_the_telegram_setting(): void {
        $this->resetAfterTest();
        $channel = new telegram_channel();

        set_config('telegram_gateway_timeout', 20, 'local_jedomi');
        $this->assertSame(20, $channel->ai_timeout());
        set_config('telegram_gateway_timeout', 2, 'local_jedomi');
        $this->assertSame(webhook_handler::MAX_AI_TIMEOUT, $channel->ai_timeout());
        set_config('telegram_gateway_timeout', 500, 'local_jedomi');
        $this->assertSame(webhook_handler::MAX_AI_TIMEOUT, $channel->ai_timeout());
        $this->assertSame(webhook_handler::get_ai_timeout(), $channel->ai_timeout());

        $this->assertSame(webhook_handler::TYPING_INTERVAL, $channel->activity_interval());
        $this->assertSame(webhook_handler::TYPING_TIMEOUT, $channel->activity_timeout());
    }

    /**
     * Data provider: message key of the engine and Telegram string.
     *
     * @return array
     */
    public static function message_provider(): array {
        return [
            'still processing' => [channel::MESSAGE_STILL_PROCESSING, 'telegram_msg_still_processing'],
            'keepalive' => [channel::MESSAGE_KEEPALIVE, 'telegram_msg_keepalive'],
            'done' => [channel::MESSAGE_DONE, 'telegram_msg_done'],
            'state save failed' => [channel::MESSAGE_STATE_SAVE_FAILED, 'telegram_msg_state_save_failed'],
            'max iterations' => [channel::MESSAGE_MAX_ITERATIONS, 'telegram_msg_max_iterations'],
            'no answer' => [channel::MESSAGE_NO_ANSWER, 'error_telegram_e006'],
        ];
    }

    /**
     * Each message of the engine is the Telegram string of the plugin.
     *
     * @dataProvider message_provider
     * @param string $key Message key.
     * @param string $identifier Telegram string.
     */
    public function test_messages_are_the_telegram_strings(string $key, string $identifier): void {
        $this->assertSame(get_string($identifier, 'local_jedomi'), (new telegram_channel())->message($key));
    }

    /**
     * The messages that end a question are, in Telegram, the shared strings the engine sent before, with their
     * parameter.
     */
    public function test_messages_that_end_a_question_are_the_shared_strings(): void {
        $channel = new telegram_channel();

        $this->assertSame(
            get_string('error_question_time_limit', 'local_jedomi', 10),
            $channel->message(channel::MESSAGE_QUESTION_TIME_LIMIT, 10)
        );
        $this->assertSame(get_string('error_empty_answer', 'local_jedomi'), $channel->message(channel::MESSAGE_EMPTY_ANSWER));
        $this->assertSame(
            get_string('error_tool_repeated', 'local_jedomi', 'no_such_tool'),
            $channel->message(channel::MESSAGE_TOOL_REPEATED, 'no_such_tool')
        );
    }

    /**
     * An unknown message key is a coding error.
     */
    public function test_unknown_message_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        (new telegram_channel())->message('no_such_message');
    }

    /**
     * The linked user of a chat is the user of its confirmed link.
     */
    public function test_linked_user_is_that_of_the_confirmed_link(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->link((int) $user->id, '783100002');
        $this->link((int) $other->id, '783100003', 0);
        $channel = new telegram_channel();

        $this->assertSame((int) $user->id, $channel->linked_userid('783100002'));
        $this->assertNull($channel->linked_userid('783100003'), 'An unconfirmed link has no user');
        $this->assertNull($channel->linked_userid('783100004'));
    }

    /**
     * The chat is served only for the user of its link, while the Telegram integration is on.
     */
    public function test_serves_only_the_linked_user_while_enabled(): void {
        $this->resetAfterTest();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the bot asks it whether Jedomi is enabled.');
        }
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        set_config('telegram_enabled', 1, 'local_jedomi');
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->link((int) $user->id, '783100005');
        $channel = new telegram_channel();

        $this->assertTrue($channel->serves('783100005', (int) $user->id));
        $this->assertFalse($channel->serves('783100005', (int) $other->id));
        $this->assertFalse($channel->serves('783100006', (int) $user->id));

        set_config('telegram_enabled', 0, 'local_jedomi');
        $this->assertFalse($channel->serves('783100005', (int) $user->id));
    }
}
