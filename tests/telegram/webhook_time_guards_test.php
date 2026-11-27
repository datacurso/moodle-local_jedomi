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

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\messaging\fake_continuation;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_continuation.php');

/**
 * Characterization of the time guards of the Telegram tool loop, driven by the Moodle clock.
 *
 * A worker hands the loop to the next one after 55 s (hard guard) or, after its first round, 40 s (medium guard); it
 * tells the user it is still working after 30 s; a question stops after 600 s over all its workers; a chat lock older
 * than 120 s is released. The AI service, the Telegram Bot API and the continuation are replaced by doubles, and the
 * time passes on a frozen clock moved by the AI double, so no test waits.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\webhook_handler
 */
final class webhook_time_guards_test extends \advanced_testcase {
    /** @var string Session generation of the seeded loop. */
    private const SESSION_GEN = 4242;

    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    /** @var fake_continuation Continuation double. */
    private fake_continuation $continuation;

    /** @var int Size of the technical log before the action under test. */
    private int $logoffset = 0;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the bot asks it whether Jedomi is enabled.');
        }
        $this->resetAfterTest();
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        set_config('telegram_enabled', 1, 'local_jedomi');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        // The longest AI timeout, so the adaptive timeout of a round shows the time left before the hard guard.
        $GLOBALS['CFG']->forced_plugin_settings['local_jedomi']['telegram_gateway_timeout'] = 50;
        $this->telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($this->telegram);
        $this->continuation = new fake_continuation();
        manager::set_continuation_for_testing($this->continuation);
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        api_client::set_instance_for_testing(null);
        manager::set_continuation_for_testing(null);
        parent::tearDown();
    }

    /**
     * Link a new user to a chat and save a loop in progress for it, as a previous worker leaves it.
     *
     * @param string $chatid Chat id.
     * @param int $now Current time of the clock.
     * @param array $extrastate State fields that replace the defaults.
     * @return int Linked user id.
     */
    private function seed_loop(string $chatid, int $now, array $extrastate = []): int {
        global $DB;
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $userid, 'chatid' => $chatid,
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => $now, 'lastproactivesent' => 0]);
        session::set_context('tool_loop_' . $chatid, array_merge([
            'session_gen'    => self::SESSION_GEN,
            'started_at'     => $now,
            'is_locked'      => true,
            'locked_at'      => $now,
            'iteration'      => 1,
            'max_iterations' => 50,
            'messages'       => [
                ['role' => 'system', 'content' => 'placeholder'],
                ['role' => 'user', 'content' => 'hola'],
            ],
            'userid'         => $userid,
        ], $extrastate));
        return $userid;
    }

    /**
     * AI double whose rounds take the given seconds on the clock; a round asks for a tool or answers with text.
     *
     * @param \core\clock $clock Frozen clock of the test.
     * @param array $rounds Each round: ['seconds' => int, 'tool' => bool].
     * @return fake_ai_client
     */
    private function ai_with_rounds(\core\clock $clock, array $rounds): fake_ai_client {
        $fake = new fake_ai_client();
        foreach ($rounds as $i => $round) {
            if ($round['tool']) {
                $fake->enqueue_tool_calls([['id' => 'call_' . $i, 'type' => 'function',
                    'function' => ['name' => 'no_such_tool', 'arguments' => '{"n":' . $i . '}']]]);
            } else {
                $fake->enqueue_text('Done.');
            }
        }
        $fake->oncall = static function (int $call) use ($clock, $rounds): void {
            $clock->bump($rounds[$call - 1]['seconds']);
        };
        client::set_instance_for_testing($fake);
        return $fake;
    }

    /**
     * Continue the saved loop of a chat as the next worker does, restoring the PHP time limit the loop raises.
     *
     * @param string $chatid Chat id.
     */
    private function continue_loop(string $chatid): void {
        $this->logoffset = (int) @filesize($this->log_file());
        $limit = (int) ini_get('max_execution_time');
        webhook_handler::handle_internal_ping($chatid);
        set_time_limit($limit);
    }

    /**
     * Path of the technical log.
     *
     * @return string
     */
    private function log_file(): string {
        global $CFG;
        return $CFG->dataroot . '/local/jedomi/jedomi.log';
    }

    /**
     * Lines written to the technical log by the action under test.
     *
     * @return string
     */
    private function new_log(): string {
        clearstatcache();
        $content = (string) @file_get_contents($this->log_file());
        return substr($content, $this->logoffset);
    }

    /**
     * Texts sent to the chat.
     *
     * @return string[]
     */
    private function sent_texts(): array {
        return array_column($this->telegram->sent, 'text');
    }

    /**
     * Data provider: seconds a tool round takes and the guard that hands the loop to the next worker.
     *
     * @return array
     */
    public static function hand_over_provider(): array {
        return [
            'hard guard at 55 s'   => [55, 'hard_guard'],
            'medium guard at 40 s' => [40, 'medium_guard'],
        ];
    }

    /**
     * After 40 s (medium guard, after the first round) or 55 s (hard guard) the worker saves the loop, still locked and
     * at the next iteration, and only then schedules the next leg for the chat with the session generation of the loop.
     *
     * @dataProvider hand_over_provider
     * @param int $seconds Seconds the tool round takes.
     * @param string $guard Guard logged.
     */
    public function test_worker_hands_the_loop_over_after_the_guard(int $seconds, string $guard): void {
        $clock = $this->mock_clock_with_frozen();
        $chatid = '781000001';
        $this->seed_loop($chatid, $clock->time());
        $fake = $this->ai_with_rounds($clock, [['seconds' => $seconds, 'tool' => true]]);
        $saved = [];
        $this->continuation->onschedule = static function (string $chatkey) use (&$saved): void {
            $saved = session::get_context('tool_loop_' . $chatkey);
        };

        $this->continue_loop($chatid);

        $this->assertCount(1, $fake->calls, 'No AI call after the guard');
        $this->assertSame([['chatkey' => $chatid, 'sessiongen' => self::SESSION_GEN]], $this->continuation->calls);
        $this->assertSame(3, $saved['iteration'], 'Saved before scheduling, at the iteration after the guard');
        $this->assertTrue($saved['is_locked']);
        $this->assertSame($clock->time(), $saved['locked_at']);
        $this->assertSame(self::SESSION_GEN, $saved['session_gen']);
        $this->assertSame('tool', end($saved['messages'])['role'], 'The tool result of the round is kept');
        $this->assertSame($saved, session::get_context('tool_loop_' . $chatid), 'The lock stays for the next worker');
        $this->assertStringContainsString('[GATELOOP] ' . $guard . ' chat=', $this->new_log());
        $this->assertNotContains(get_string('telegram_msg_keepalive', 'local_jedomi'), $this->sent_texts());
    }

    /**
     * Below 40 s nothing is handed over: the loop goes on in the same worker until the answer, and the lock is freed.
     */
    public function test_worker_keeps_the_loop_below_the_medium_guard(): void {
        $clock = $this->mock_clock_with_frozen();
        $chatid = '781000002';
        $this->seed_loop($chatid, $clock->time());
        $fake = $this->ai_with_rounds($clock, [['seconds' => 39, 'tool' => true], ['seconds' => 0, 'tool' => false]]);

        $this->continue_loop($chatid);

        $this->assertCount(2, $fake->calls);
        $this->assertSame([], $this->continuation->calls);
        $this->assertContains('Done.', $this->sent_texts());
        $this->assertEmpty(session::get_context('tool_loop_' . $chatid));
        $this->assertStringNotContainsString('_guard chat=', $this->new_log());
    }

    /**
     * Data provider: seconds of the first round and whether the keepalive message is sent.
     *
     * @return array
     */
    public static function keepalive_provider(): array {
        return [
            'at 30 s'    => [30, true],
            'before 30 s' => [29, false],
        ];
    }

    /**
     * After 30 s the user is told once that the bot is still working; before 30 s nothing is said.
     *
     * @dataProvider keepalive_provider
     * @param int $seconds Seconds the first tool round takes.
     * @param bool $sent Whether the keepalive message is sent.
     */
    public function test_keepalive_is_sent_once_after_30_seconds(int $seconds, bool $sent): void {
        $clock = $this->mock_clock_with_frozen();
        $chatid = '781000003';
        $this->seed_loop($chatid, $clock->time());
        $this->ai_with_rounds($clock, [
            ['seconds' => $seconds, 'tool' => true],
            ['seconds' => 0, 'tool' => true],
            ['seconds' => 0, 'tool' => false],
        ]);

        $this->continue_loop($chatid);

        $keepalive = get_string('telegram_msg_keepalive', 'local_jedomi');
        $count = count(array_keys($this->sent_texts(), $keepalive, true));
        $this->assertSame($sent ? 1 : 0, $count);
        $this->assertSame('Done.', end($this->telegram->sent)['text']);
    }

    /**
     * The timeout of each AI round is the time left before the hard guard, less a margin of 5 s.
     */
    public function test_round_timeout_shrinks_towards_the_hard_guard(): void {
        $clock = $this->mock_clock_with_frozen();
        $chatid = '781000004';
        $this->seed_loop($chatid, $clock->time());
        $fake = $this->ai_with_rounds($clock, [['seconds' => 20, 'tool' => true], ['seconds' => 0, 'tool' => false]]);

        $this->continue_loop($chatid);

        $this->assertSame(50, $fake->calls[0]['timeout']);
        $this->assertSame(30, $fake->calls[1]['timeout'], '55 - 20 - 5');
    }

    /**
     * A question that reaches 600 s over all its workers stops with a clear message, frees the chat and schedules
     * nothing.
     */
    public function test_question_stops_when_its_time_limit_passes_during_a_round(): void {
        $clock = $this->mock_clock_with_frozen();
        $chatid = '781000005';
        $this->seed_loop($chatid, $clock->time(), ['started_at' => $clock->time() - 590]);
        $fake = $this->ai_with_rounds($clock, [['seconds' => 10, 'tool' => true], ['seconds' => 0, 'tool' => false]]);

        $this->continue_loop($chatid);

        $this->assertCount(1, $fake->calls);
        $this->assertSame(10, $fake->calls[0]['timeout'], 'The round only gets the 10 s left of the question');
        $this->assertSame(
            get_string('error_question_time_limit', 'local_jedomi', 10),
            end($this->telegram->sent)['text']
        );
        $this->assertSame([], $this->continuation->calls);
        $this->assertEmpty(session::get_context('tool_loop_' . $chatid));
        $this->assertStringContainsString('[GATELOOP] question_time_limit chat=', $this->new_log());
    }

    /**
     * Data provider: age of the lock of the chat and whether a new message is still blocked by it.
     *
     * @return array
     */
    public static function lock_age_provider(): array {
        return [
            'lock of 119 s blocks' => [119, true],
            'lock of 120 s is released' => [120, false],
        ];
    }

    /**
     * A new message waits while another worker holds the chat for less than 120 s; an older lock is released and the
     * message is answered.
     *
     * @dataProvider lock_age_provider
     * @param int $age Seconds since the lock was taken.
     * @param bool $blocked Whether the message is blocked.
     */
    public function test_new_message_respects_the_lock_of_the_chat_by_its_age(int $age, bool $blocked): void {
        $clock = $this->mock_clock_with_frozen();
        $chatid = '781000006';
        $this->seed_loop($chatid, $clock->time(), ['locked_at' => $clock->time() - $age, 'iteration' => 0]);
        $fake = $this->ai_with_rounds($clock, [['seconds' => 0, 'tool' => false]]);

        $limit = (int) ini_get('max_execution_time');
        webhook_handler::handle(['message' => ['chat' => ['id' => $chatid], 'text' => 'otra pregunta']]);
        set_time_limit($limit);

        $busy = get_string('telegram_msg_still_processing', 'local_jedomi');
        if ($blocked) {
            $this->assertSame([], $fake->calls);
            $this->assertSame($busy, end($this->telegram->sent)['text']);
        } else {
            $this->assertCount(1, $fake->calls);
            $this->assertNotContains($busy, $this->sent_texts());
            $this->assertSame('Done.', end($this->telegram->sent)['text']);
        }
    }
}
