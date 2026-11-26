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
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_channel.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/neutral_channel.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');

/**
 * Tests for the channel-neutral conversation engine: the AI and tools loop of a chat, run through the pieces of its
 * channel.
 *
 * The channel is the Telegram channel with a recording transport and continuation (fake_channel), so every message,
 * activity status and next leg the engine produces is seen here. The tests named "neutral" run with neutral_channel
 * instead, a channel built only from the channel-neutral core, without any piece of Telegram. The AI service is
 * fake_ai_client.
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

    /** @var int Time of the frozen Moodle clock, far from the real time, so a time() call gives another value. */
    private const FROZEN = 1000000000;

    /** @var fake_channel Channel of the tests. */
    private fake_channel $channel;

    /** @var fake_ai_client AI double. */
    private fake_ai_client $ai;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Jedomi is enabled through the plugin state seam: the engine never asks the Datacurso AI provider, which the
        // CI does not install.
        provider_absence_probe::start();
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $GLOBALS['CFG']->forced_plugin_settings['local_jedomi']['telegram_gateway_timeout'] = 50;
        set_config('telegram_enabled', 1, 'local_jedomi');
        // Nothing reaches Telegram, even from a piece the fake channel takes from the Telegram channel.
        api_client::set_instance_for_testing(new fake_telegram_api_client());
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
        $this->channel = new fake_channel(new telegram_channel());
    }

    #[\Override]
    protected function assertPostConditions(): void {
        parent::assertPostConditions();
        $this->assertSame([], provider_absence_probe::touched(), 'The test reached the Datacurso AI provider.');
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        api_client::set_instance_for_testing(null);
        neutral_channel::drop_tables();
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
     * @param channel|null $channel Channel of the chat; null for the channel of the tests.
     */
    private function seed_loop(string $chatkey, int $userid, array $extrastate = [], ?channel $channel = null): void {
        $now = \core\di::get(\core\clock::class)->time();
        ($channel ?? $this->channel)->store()->set_context('tool_loop_' . $chatkey, array_merge([
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
     * A neutral channel with a chat linked to a new active user.
     *
     * @param string $chatkey Chat key.
     * @return array [neutral_channel, int user id]
     */
    private function neutral_linked(string $chatkey): array {
        $channel = new neutral_channel();
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $channel->links[$chatkey] = $userid;
        return [$channel, $userid];
    }

    /**
     * Keep the loop state saved in the store when the AI is called the given time.
     *
     * @param channel $channel Channel of the chat.
     * @param string $chatkey Chat key.
     * @param int $call Number of the AI call, from 1.
     * @param array|null $saved Receives the state.
     */
    private function capture_state_on_call(channel $channel, string $chatkey, int $call, ?array &$saved): void {
        $this->ai->oncall = static function (int $number) use ($channel, $chatkey, $call, &$saved): void {
            if ($number === $call) {
                $saved = $channel->store()->get_context('tool_loop_' . $chatkey);
            }
        };
    }

    /**
     * Messages of a role sent to the AI in a call.
     *
     * @param int $call Index of the AI call, from 0.
     * @param string $role Role.
     * @return array[]
     */
    private function messages_of_role(int $call, string $role): array {
        return array_values(array_filter(
            $this->ai->calls[$call]['messages'],
            static fn(array $m): bool => $m['role'] === $role
        ));
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
     * The transport of the channel decides whether a formatted answer is blank: a blank one is sent as the text the AI
     * wrote, through send_text, instead of the formatted answer.
     */
    public function test_answer_the_transport_finds_blank_is_sent_as_written(): void {
        $chatkey = '782000011';
        $userid = $this->linked_user($chatkey);
        $this->ai->enqueue_text('a < b > c');
        $this->channel->transport->blank = true;

        $this->with_time_limit(fn() => (new conversation_engine($this->channel))->run($userid, $chatkey, 'pregunta'));

        $this->assertSame(['a < b > c'], $this->channel->transport->blankchecks);
        $this->assertSame(
            [['kind' => 'text', 'chatkey' => $chatkey, 'text' => 'a < b > c']],
            $this->channel->transport->sent
        );
    }

    /**
     * An answer the transport of the channel does not find blank is sent as the answer, even when it would be empty
     * as HTML: the engine does not judge the format of the channel.
     */
    public function test_answer_the_transport_does_not_find_blank_is_sent_as_the_answer(): void {
        $chatkey = '782000012';
        $userid = $this->linked_user($chatkey);
        $this->ai->enqueue_text('<b></b>');
        $this->channel->transport->blank = false;

        $this->with_time_limit(fn() => (new conversation_engine($this->channel))->run($userid, $chatkey, 'pregunta'));

        $this->assertSame(['<b></b>'], $this->channel->transport->blankchecks);
        $this->assertSame(
            [['kind' => 'answer', 'chatkey' => $chatkey, 'text' => '<b></b>']],
            $this->channel->transport->sent
        );
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

    /**
     * A channel that is not Telegram answers through its own pieces: its transport, its store and its name in the event.
     */
    public function test_neutral_channel_answers_through_its_own_pieces(): void {
        global $DB;
        $channel = new neutral_channel();
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $channel->links['nc100'] = $userid;
        $this->ai->enqueue_text('Hello.');
        $sink = $this->redirectEvents();

        $this->with_time_limit(fn() => (new conversation_engine($channel))->run($userid, 'nc100', 'question'));

        $this->assertSame([['kind' => 'answer', 'chatkey' => 'nc100', 'text' => 'Hello.']], $channel->transport->sent);
        $this->assertSame([
            ['role' => 'user', 'content' => 'question'],
            ['role' => 'assistant', 'content' => 'Hello.'],
        ], $channel->store()->get('nc100'));
        $this->assertTrue($DB->record_exists(neutral_channel::SESSIONS_TABLE, ['chatid' => 'nc100']));
        $this->assertSame(0, $DB->count_records('local_jedomi_telegram_sessions'));
        $answered = array_values(array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof \local_jedomi\event\chat_answered
        ));
        $this->assertSame(neutral_channel::NAME, $answered[0]->other['channel']);
    }

    /**
     * The messages that end a question (time limit, a second answer without text, the same tool error repeated) are
     * texts of the channel, with their parameter.
     */
    public function test_neutral_channel_gives_the_messages_that_end_a_question(): void {
        $channel = new class extends neutral_channel {
            /** @var array[] Messages asked for, as [key, parameter]. */
            public array $asked = [];

            #[\Override]
            public function message(string $key, mixed $a = null): string {
                $this->asked[] = [$key, $a];
                return $key;
            }
        };
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        foreach (['nc101', 'nc102', 'nc103'] as $chatkey) {
            $channel->links[$chatkey] = $userid;
        }

        $this->seed_loop('nc101', $userid, ['started_at' => time() - conversation_engine::QUESTION_TIME_LIMIT - 1], $channel);
        $this->with_time_limit(fn() => (new conversation_engine($channel))->resume('nc101', 0));

        $this->ai->enqueue_empty('stop');
        $this->ai->enqueue_empty('stop');
        $this->seed_loop('nc102', $userid, [], $channel);
        $this->with_time_limit(fn() => (new conversation_engine($channel))->resume('nc102', 0));

        for ($i = 1; $i <= 5; $i++) {
            $this->ai->enqueue_tool_calls([['id' => 'call_' . $i, 'type' => 'function',
                'function' => ['name' => 'no_such_tool', 'arguments' => '{"a":1}']]]);
        }
        $this->seed_loop('nc103', $userid, [], $channel);
        $this->with_time_limit(fn() => (new conversation_engine($channel))->resume('nc103', 0));

        $minutes = (int) ceil(conversation_engine::QUESTION_TIME_LIMIT / 60);
        $this->assertContains([channel::MESSAGE_QUESTION_TIME_LIMIT, $minutes], $channel->asked);
        $this->assertContains([channel::MESSAGE_EMPTY_ANSWER, null], $channel->asked);
        $this->assertContains([channel::MESSAGE_TOOL_REPEATED, 'no_such_tool'], $channel->asked);
        $this->assertSame(
            [channel::MESSAGE_QUESTION_TIME_LIMIT, channel::MESSAGE_EMPTY_ANSWER, channel::MESSAGE_TOOL_REPEATED],
            array_values(array_intersect($channel->transport->texts(), [channel::MESSAGE_QUESTION_TIME_LIMIT,
                channel::MESSAGE_EMPTY_ANSWER, channel::MESSAGE_TOOL_REPEATED]))
        );
    }

    /**
     * A new loop locks the chat at the time of the Moodle clock: the saved state carries it, so the lock age of the
     * next message and of a newer worker is measured with the same clock.
     */
    public function test_new_loop_locks_the_chat_at_the_moodle_clock_time(): void {
        $this->mock_clock_with_frozen(self::FROZEN);
        [$channel, $userid] = $this->neutral_linked('nc301');
        $this->enqueue_unknown_tool('call_1');
        $this->ai->enqueue_text('Done.');
        $saved = null;
        $this->capture_state_on_call($channel, 'nc301', 2, $saved);

        $this->with_time_limit(fn() => (new conversation_engine($channel))->run($userid, 'nc301', 'question'));

        $this->assertSame(1, $saved['iteration']);
        $this->assertTrue($saved['is_locked']);
        $this->assertSame(self::FROZEN, $saved['locked_at']);
        $this->assertSame(self::FROZEN, $saved['started_at']);
    }

    /**
     * A worker that resumes a loop locks the chat again at the time of the Moodle clock, not at the time of the
     * worker that saved it.
     */
    public function test_resumed_loop_locks_the_chat_again_at_the_moodle_clock_time(): void {
        $this->mock_clock_with_frozen(self::FROZEN);
        [$channel, $userid] = $this->neutral_linked('nc302');
        $this->seed_loop('nc302', $userid, ['locked_at' => self::FROZEN - 100], $channel);
        $this->enqueue_unknown_tool('call_1');
        $this->ai->enqueue_text('Done.');
        $saved = null;
        $this->capture_state_on_call($channel, 'nc302', 2, $saved);

        $this->with_time_limit(fn() => (new conversation_engine($channel))->resume('nc302', 0));

        $this->assertSame(2, $saved['iteration']);
        $this->assertSame(self::FROZEN, $saved['locked_at']);
    }

    /**
     * After the AI asks for tools, a newer state saved by another worker whose lock is younger than the lock TTL, by
     * the Moodle clock, is taken: the tools are not run again and the loop goes on from that state.
     */
    public function test_newer_state_of_a_live_worker_is_reloaded_instead_of_running_the_tools(): void {
        $this->mock_clock_with_frozen(self::FROZEN);
        [$channel, $userid] = $this->neutral_linked('nc303');
        $this->seed_loop('nc303', $userid, [], $channel);
        $this->save_newer_state_during_the_first_call($channel, 'nc303', $userid, self::FROZEN - conversation_engine::LOCK_TTL + 1);

        $this->with_time_limit(fn() => (new conversation_engine($channel))->resume('nc303', 0));

        $this->assertCount(2, $this->ai->calls);
        $this->assertSame([], $this->messages_of_role(1, 'tool'));
        $this->assertSame(
            [['role' => 'assistant', 'content' => 'From the newer worker.']],
            $this->messages_of_role(1, 'assistant')
        );
        $this->assertSame(['kind' => 'answer', 'chatkey' => 'nc303', 'text' => 'Done.'], end($channel->transport->sent));
    }

    /**
     * A newer state whose lock is as old as the lock TTL, by the Moodle clock, belongs to a dead worker: it is
     * ignored and the tools of this worker run.
     */
    public function test_newer_state_of_a_dead_worker_is_ignored(): void {
        $this->mock_clock_with_frozen(self::FROZEN);
        [$channel, $userid] = $this->neutral_linked('nc304');
        $this->seed_loop('nc304', $userid, [], $channel);
        $this->save_newer_state_during_the_first_call($channel, 'nc304', $userid, self::FROZEN - conversation_engine::LOCK_TTL);

        $this->with_time_limit(fn() => (new conversation_engine($channel))->resume('nc304', 0));

        $this->assertCount(2, $this->ai->calls);
        $toolmessages = $this->messages_of_role(1, 'tool');
        $this->assertCount(1, $toolmessages);
        $this->assertStringContainsString('permission_denied', $toolmessages[0]['content']);
        $this->assertStringNotContainsString('From the newer worker.', json_encode($this->ai->calls[1]['messages']));
    }

    /**
     * An answer the format of the channel leaves blank, and with no text of its own once the untrusted data markers are
     * removed, is answered with the "done" message of the channel, which is kept in the history.
     */
    public function test_answer_left_without_text_gets_the_done_message(): void {
        [$channel, $userid] = $this->neutral_linked('nc305');
        $this->ai->enqueue_text("<<<UNTRUSTED_DATA tool x>>>\n<<<END_UNTRUSTED_DATA>>>\n");

        $this->with_time_limit(fn() => (new conversation_engine($channel))->run($userid, 'nc305', 'question'));

        $this->assertSame([['kind' => 'text', 'chatkey' => 'nc305', 'text' => channel::MESSAGE_DONE]], $channel->transport->sent);
        $this->assertSame([
            ['role' => 'user', 'content' => 'question'],
            ['role' => 'assistant', 'content' => channel::MESSAGE_DONE],
        ], $channel->store()->get('nc305'));
        $this->assertSame([], $channel->store()->get_context('tool_loop_nc305'));
    }

    /**
     * A first answer without text is asked again once, with the retry message; the second answer reaches the user.
     */
    public function test_empty_answer_is_asked_again_once(): void {
        [$channel, $userid] = $this->neutral_linked('nc306');
        $this->ai->enqueue_empty('stop');
        $this->ai->enqueue_text('Here it is.');

        $this->with_time_limit(fn() => (new conversation_engine($channel))->run($userid, 'nc306', 'question'));

        $this->assertCount(2, $this->ai->calls);
        $this->assertSame(
            \local_jedomi\ai\empty_answer::retry_message('stop'),
            end($this->ai->calls[1]['messages'])
        );
        $this->assertSame([['kind' => 'answer', 'chatkey' => 'nc306', 'text' => 'Here it is.']], $channel->transport->sent);
    }

    /**
     * When the state of the loop cannot be saved after the tools ran, the chat is freed and the user gets the "state
     * save failed" message of the channel.
     */
    public function test_state_that_cannot_be_saved_frees_the_chat_with_a_message(): void {
        $channel = new class extends neutral_channel {
            /** @var bool Whether saving a loop state fails. */
            public bool $failsaves = false;

            #[\Override]
            public function store(): conversation_store {
                return new class (self::SESSIONS_TABLE, self::NAME . '_max_turns', $this->failsaves) extends conversation_store {
                    /**
                     * Constructor.
                     *
                     * @param string $table Session table.
                     * @param string $setting Max turns setting.
                     * @param bool $failsaves Whether saving a loop state fails.
                     */
                    public function __construct(
                        string $table,
                        string $setting,
                        /** @var bool Whether saving a loop state fails. */
                        private readonly bool $failsaves,
                    ) {
                        parent::__construct($table, $setting);
                    }

                    #[\Override]
                    public function set_context(string $chatkey, array $context): void {
                        if ($this->failsaves && $context !== []) {
                            throw new \dml_write_exception('State too big');
                        }
                        parent::set_context($chatkey, $context);
                    }
                };
            }
        };
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $channel->links['nc307'] = $userid;
        $this->seed_loop('nc307', $userid, [], $channel);
        $channel->failsaves = true;
        $this->enqueue_unknown_tool('call_1');

        $this->with_time_limit(fn() => (new conversation_engine($channel))->resume('nc307', 0));

        $this->assertCount(1, $this->ai->calls);
        $this->assertSame(channel::MESSAGE_STATE_SAVE_FAILED, end($channel->transport->sent)['text']);
        $this->assertSame([], $channel->store()->get_context('tool_loop_nc307'));
    }

    /**
     * When the chat is unlinked while the AI answers, the loop stops: the tools it asked for do not run, nothing is
     * sent and the state of the loop is released.
     */
    public function test_access_lost_while_the_ai_answers_stops_the_loop(): void {
        [$channel, $userid] = $this->neutral_linked('nc308');
        $this->seed_loop('nc308', $userid, [], $channel);
        $this->enqueue_unknown_tool('call_1');
        $this->ai->oncall = static function () use ($channel): void {
            unset($channel->links['nc308']);
        };

        $this->with_time_limit(fn() => (new conversation_engine($channel))->resume('nc308', 0));

        $this->assertCount(1, $this->ai->calls);
        $this->assertSame([], $channel->transport->sent);
        $this->assertSame([], $channel->store()->get_context('tool_loop_nc308'));
    }

    /**
     * Ask for an unknown tool in the first AI call and, while it answers, save a newer state of the loop as another
     * worker would; the second AI call answers with text.
     *
     * @param neutral_channel $channel Channel of the chat.
     * @param string $chatkey Chat key.
     * @param int $userid User of the loop.
     * @param int $lockedat Lock time of the newer state.
     */
    private function save_newer_state_during_the_first_call(
        neutral_channel $channel,
        string $chatkey,
        int $userid,
        int $lockedat
    ): void {
        $this->enqueue_unknown_tool('call_1');
        $this->ai->enqueue_text('Done.');
        $this->ai->oncall = static function (int $call) use ($channel, $chatkey, $userid, $lockedat): void {
            if ($call !== 1) {
                return;
            }
            $channel->store()->set_context('tool_loop_' . $chatkey, [
                'session_gen' => self::SESSION_GEN,
                'started_at'  => self::FROZEN,
                'is_locked'   => true,
                'locked_at'   => $lockedat,
                'iteration'   => 3,
                'messages'    => [
                    ['role' => 'system', 'content' => 'placeholder'],
                    ['role' => 'user', 'content' => 'hola'],
                    ['role' => 'assistant', 'content' => 'From the newer worker.'],
                ],
                'userid'      => $userid,
            ]);
        };
    }
}
