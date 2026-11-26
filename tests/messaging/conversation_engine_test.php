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

namespace local_jedomi\messaging;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
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
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_channel.php');

/**
 * Tests for the channel-neutral conversation engine: the AI and tools loop of a chat, run through the pieces of its
 * channel.
 *
 * The channel is the Telegram channel with a recording transport and continuation (fake_channel), so every message,
 * activity status and next leg the engine produces is seen here. The AI service is fake_ai_client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\conversation_engine
 */
final class conversation_engine_test extends \advanced_testcase {
    /** @var int Session generation of the seeded loops. */
    private const SESSION_GEN = 5150;

    /** @var fake_channel Channel of the tests. */
    private fake_channel $channel;

    /** @var fake_ai_client AI double. */
    private fake_ai_client $ai;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the engine asks it whether Jedomi is enabled.');
        }
        $this->resetAfterTest();
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        $GLOBALS['CFG']->forced_plugin_settings['local_jedomi']['telegram_gateway_timeout'] = 50;
        set_config('telegram_enabled', 1, 'local_jedomi');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        // Nothing reaches Telegram, even from a piece the fake channel takes from the Telegram channel.
        api_client::set_instance_for_testing(new fake_telegram_api_client());
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
        $this->channel = new fake_channel(new telegram_channel());
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * A user linked to a chat of the channel.
     *
     * @param string $chatkey Chat key.
     * @return int User id.
     */
    private function linked_user(string $chatkey): int {
        global $DB;
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $userid, 'chatid' => $chatkey,
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        return $userid;
    }

    /**
     * Save a loop in progress for a chat, as a previous worker leaves it.
     *
     * @param string $chatkey Chat key.
     * @param int $userid User of the loop.
     * @param array $extrastate State fields that replace the defaults.
     */
    private function seed_loop(string $chatkey, int $userid, array $extrastate = []): void {
        $now = \core\di::get(\core\clock::class)->time();
        $this->channel->store()->set_context('tool_loop_' . $chatkey, array_merge([
            'session_gen' => self::SESSION_GEN,
            'started_at'  => $now,
            'is_locked'   => true,
            'locked_at'   => $now,
            'iteration'   => 1,
            'messages'    => [
                ['role' => 'system', 'content' => 'placeholder'],
                ['role' => 'user', 'content' => 'hola'],
            ],
            'userid'      => $userid,
        ], $extrastate));
    }

    /**
     * Queue a call of a tool no role is offered.
     *
     * @param string $id Call id.
     */
    private function enqueue_unknown_tool(string $id): void {
        $this->ai->enqueue_tool_calls([['id' => $id, 'type' => 'function',
            'function' => ['name' => 'no_such_tool', 'arguments' => '{"id":"' . $id . '"}']]]);
    }

    /**
     * Run the engine restoring the PHP time limit the loop raises.
     *
     * @param callable $action Engine call.
     */
    private function with_time_limit(callable $action): void {
        $limit = (int) ini_get('max_execution_time');
        $action();
        set_time_limit($limit);
    }

    /**
     * A message is answered through the transport of the channel, after its activity status; the turn is kept in the
     * store of the channel, the chat is freed and the answer is recorded for the channel.
     */
    public function test_run_answers_through_the_channel(): void {
        $chatkey = '782000001';
        $userid = $this->linked_user($chatkey);
        $this->ai->enqueue_text('Hola, ¿en qué le ayudo?');
        $sink = $this->redirectEvents();

        $this->with_time_limit(fn() => (new conversation_engine($this->channel))->run($userid, $chatkey, 'pregunta'));

        $this->assertSame([['chatkey' => $chatkey, 'timeout' => null]], $this->channel->transport->activities);
        $this->assertSame(
            [['kind' => 'answer', 'chatkey' => $chatkey, 'text' => 'Hola, ¿en qué le ayudo?']],
            $this->channel->transport->sent
        );
        $this->assertSame([
            ['role' => 'user', 'content' => 'pregunta'],
            ['role' => 'assistant', 'content' => 'Hola, ¿en qué le ayudo?'],
        ], $this->channel->store()->get($chatkey));
        $this->assertSame([], $this->channel->store()->get_context('tool_loop_' . $chatkey));
        $this->assertSame('pregunta', end($this->ai->calls[0]['messages'])['content']);
        $answered = array_values(array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof \local_jedomi\event\chat_answered
        ));
        $this->assertCount(1, $answered);
        $this->assertSame('telegram', $answered[0]->other['channel']);
        $this->assertSame([], $this->channel->continuation->calls);
    }

    /**
     * While another worker holds the chat, a new message only gets the "still processing" message of the channel.
     */
    public function test_run_waits_while_the_chat_is_locked(): void {
        $chatkey = '782000002';
        $userid = $this->linked_user($chatkey);
        $this->seed_loop($chatkey, $userid);

        $this->with_time_limit(fn() => (new conversation_engine($this->channel))->run($userid, $chatkey, 'otra'));

        $this->assertSame([], $this->ai->calls);
        $this->assertSame([channel::MESSAGE_STILL_PROCESSING], $this->channel->transport->texts());
    }

    /**
     * After the hard guard the loop is saved and handed to the continuation of the channel with its session
     * generation.
     */
    public function test_resume_hands_over_to_the_continuation_of_the_channel(): void {
        $clock = $this->mock_clock_with_frozen();
        $chatkey = '782000003';
        $userid = $this->linked_user($chatkey);
        $this->seed_loop($chatkey, $userid);
        $this->enqueue_unknown_tool('call_1');
        $this->ai->oncall = static function () use ($clock): void {
            $clock->bump(conversation_engine::HARD_GUARD);
        };
        $saved = [];
        $this->channel->continuation->onschedule = function (string $key) use (&$saved): void {
            $saved = $this->channel->store()->get_context('tool_loop_' . $key);
        };

        $this->with_time_limit(fn() => (new conversation_engine($this->channel))->resume($chatkey, self::SESSION_GEN));

        $this->assertSame([['chatkey' => $chatkey, 'sessiongen' => self::SESSION_GEN]], $this->channel->continuation->calls);
        $this->assertSame(3, $saved['iteration']);
        $this->assertTrue($saved['is_locked']);
        $this->assertCount(1, $this->ai->calls);
    }

    /**
     * A tool the AI was not offered is refused, and the refusal reaches the AI as the result of the call.
     */
    public function test_tool_not_offered_is_refused(): void {
        $chatkey = '782000004';
        $userid = $this->linked_user($chatkey);
        $this->seed_loop($chatkey, $userid);
        $this->enqueue_unknown_tool('call_1');
        $this->ai->enqueue_text('Listo.');

        $this->with_time_limit(fn() => (new conversation_engine($this->channel))->resume($chatkey, 0));

        $this->assertCount(2, $this->ai->calls);
        $toolmessage = end($this->ai->calls[1]['messages']);
        $this->assertSame('tool', $toolmessage['role']);
        $this->assertStringContainsString('permission_denied', $toolmessage['content']);
        $this->assertSame('Listo.', end($this->channel->transport->sent)['text']);
    }

    /**
     * A leg scheduled for an older session of the chat (before /new) is dropped without touching the loop.
     */
    public function test_resume_drops_a_leg_of_an_older_session(): void {
        $chatkey = '782000005';
        $userid = $this->linked_user($chatkey);
        $this->seed_loop($chatkey, $userid);
        $before = $this->channel->store()->get_context('tool_loop_' . $chatkey);

        $this->with_time_limit(fn() => (new conversation_engine($this->channel))->resume($chatkey, self::SESSION_GEN + 1));

        $this->assertSame([], $this->ai->calls);
        $this->assertSame($before, $this->channel->store()->get_context('tool_loop_' . $chatkey));
    }

    /**
     * A loop whose chat is no longer linked stops and its state is cleared, without any AI call or message.
     */
    public function test_resume_stops_when_the_chat_is_no_longer_linked(): void {
        $chatkey = '782000006';
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $this->seed_loop($chatkey, $userid);

        $this->with_time_limit(fn() => (new conversation_engine($this->channel))->resume($chatkey, 0));

        $this->assertSame([], $this->ai->calls);
        $this->assertSame([], $this->channel->transport->sent);
        $this->assertSame([], $this->channel->store()->get_context('tool_loop_' . $chatkey));
    }

    /**
     * The loop ends with the "max iterations" message of the channel after its last allowed round.
     */
    public function test_loop_ends_after_the_last_iteration(): void {
        $chatkey = '782000007';
        $userid = $this->linked_user($chatkey);
        $this->seed_loop($chatkey, $userid, ['iteration' => conversation_engine::MAX_ITERATIONS - 1]);
        $this->enqueue_unknown_tool('call_1');

        $this->with_time_limit(fn() => (new conversation_engine($this->channel))->resume($chatkey, 0));

        $this->assertCount(1, $this->ai->calls);
        $this->assertSame(channel::MESSAGE_MAX_ITERATIONS, end($this->channel->transport->sent)['text']);
        $this->assertSame([], $this->channel->store()->get_context('tool_loop_' . $chatkey));
    }
}
