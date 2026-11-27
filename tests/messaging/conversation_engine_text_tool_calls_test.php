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
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');

/**
 * Tool calls a model writes as text, for models without native function calling, run through the conversation engine.
 *
 * Each case pins the tool calls the engine sends back to the AI (name, arguments, id prefix) and the text kept next to
 * them, for every text format the engine reads.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\conversation_engine
 * @covers     \local_jedomi\messaging\tool_call_text_parser
 */
final class conversation_engine_text_tool_calls_test extends \advanced_testcase {
    /** @var fake_ai_client AI double. */
    private fake_ai_client $ai;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        provider_absence_probe::start();
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $GLOBALS['CFG']->forced_plugin_settings['local_jedomi']['telegram_gateway_timeout'] = 50;
        set_config('telegram_enabled', 1, 'local_jedomi');
        api_client::set_instance_for_testing(new fake_telegram_api_client());
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
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
        parent::tearDown();
    }

    /**
     * Text formats of tool calls and what the engine reads from each.
     *
     * @return array[] [answer text, expected [prefix, name, arguments][], expected text kept with the calls]
     */
    public static function text_formats(): array {
        return [
            'TOOL_CALL block' => [
                "Let me check.\nTOOL_CALL: no_such_tool\n{\"a\":1}\nEND_TOOL_CALL",
                [['text_', 'no_such_tool', '{"a":1}']],
                'Let me check.',
            ],
            'call block with text around the JSON' => [
                "call: no_such_tool\nargs: {\"a\":1} please\nEND_TOOL_CALL (done)\nAfter.",
                [['text_', 'no_such_tool', '{"a":1}']],
                'After.',
            ],
            'two TOOL_CALL blocks' => [
                "TOOL_CALL: no_such_tool\n{\"a\":1}\nEND_TOOL_CALL\nTOOL_CALL: other_tool\n{\"b\":2}\nEND_TOOL_CALL",
                [['text_', 'no_such_tool', '{"a":1}'], ['text_', 'other_tool', '{"b":2}']],
                '',
            ],
            'inline TOOL_CALL' => [
                "Before. TOOL_CALL: no_such_tool {\"a\":2} END_TOOL_CALL",
                [['text_', 'no_such_tool', '{"a":2}']],
                'Before.',
            ],
            'special token with name and JSON lines' => [
                "Sure.<|tool_call|>no_such_tool\n{\"a\":3}<|/no_such_tool\n{\"a\":3}|>",
                [['text_', 'no_such_tool', '{"a":3}']],
                'Sure.',
            ],
            'special token with a JSON object' => [
                '<|python_tag|>{"name":"no_such_tool","arguments":{"a":4}}<|/{"name":"no_such_tool","arguments":{"a":4}}|>',
                [['text_', 'no_such_tool', '{"a":4}']],
                '',
            ],
            'special token with JSON arguments as a string' => [
                '<|tool_call|>{"name":"no_such_tool","arguments":"{}"}<|/{"name":"no_such_tool","arguments":"{}"}|>',
                [['text_', 'no_such_tool', '{}']],
                '',
            ],
            'ultra-broad fallback after an unreadable inline call' => [
                "call: no_such_tool junk END_TOOL_CALL call: other_tool {\"y\":2} END_TOOL_CALL",
                [['ultra_', 'other_tool', '{"y":2}']],
                '',
            ],
        ];
    }

    /**
     * A tool call written as text runs as a tool call: the AI gets it back with its arguments, and the text around it.
     *
     * @dataProvider text_formats
     * @param string $answer Text the AI answers.
     * @param array $expected [prefix, name, arguments] of each tool call read.
     * @param string $kept Text kept with the tool calls.
     */
    public function test_tool_call_written_as_text_runs(string $answer, array $expected, string $kept): void {
        [$channel, $chatkey, $userid] = $this->linked_chat();
        $this->ai->enqueue_text($answer);
        $this->ai->enqueue_text('Done.');

        $this->run_engine($channel, $userid, $chatkey);

        $this->assertCount(2, $this->ai->calls);
        $assistant = array_values(array_filter(
            $this->ai->calls[1]['messages'],
            static fn(array $m): bool => $m['role'] === 'assistant' && !empty($m['tool_calls'])
        ));
        $this->assertCount(1, $assistant);
        $this->assertSame($kept, $assistant[0]['content']);
        $calls = $assistant[0]['tool_calls'];
        $this->assertCount(count($expected), $calls);
        foreach ($expected as $i => [$prefix, $name, $arguments]) {
            $this->assertMatchesRegularExpression('/^' . $prefix . '[0-9a-f]{32}$/', $calls[$i]['id']);
            $this->assertSame('function', $calls[$i]['type']);
            $this->assertSame(['name' => $name, 'arguments' => $arguments], $calls[$i]['function']);
            $this->assertSame(['id', 'type', 'function'], array_keys($calls[$i]));
        }
        $tools = array_values(array_filter($this->ai->calls[1]['messages'], static fn(array $m): bool => $m['role'] === 'tool'));
        $this->assertCount(count($expected), $tools);
        $this->assertSame('Done.', end($channel->transport->sent)['text']);
    }

    /**
     * A text that looks like a tool call but cannot be read is the answer, and a developer message says so.
     */
    public function test_unreadable_tool_call_is_the_answer(): void {
        [$channel, $chatkey, $userid] = $this->linked_chat();
        $this->ai->enqueue_text("TOOL_CALL: no_such_tool\nnot json\nEND_TOOL_CALL");

        $this->run_engine($channel, $userid, $chatkey);

        $this->assertDebuggingCalled();
        $this->assertCount(1, $this->ai->calls);
        $this->assertSame([], $channel->store()->get_context('tool_loop_' . $chatkey));
        $this->assertSame('answer', end($channel->transport->sent)['kind']);
    }

    /**
     * A plain answer without anything like a tool call is the answer.
     */
    public function test_plain_text_is_the_answer(): void {
        [$channel, $chatkey, $userid] = $this->linked_chat();
        $this->ai->enqueue_text('Call me later, please.');

        $this->run_engine($channel, $userid, $chatkey);

        $this->assertCount(1, $this->ai->calls);
        $this->assertSame(
            [['kind' => 'answer', 'chatkey' => $chatkey, 'text' => 'Call me later, please.']],
            $channel->transport->sent
        );
    }

    /**
     * A Telegram chat with a recording transport, linked to a new user.
     *
     * @return array [fake_channel, string chat key, int user id]
     */
    private function linked_chat(): array {
        global $DB;
        $chatkey = '783000001';
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $userid, 'chatid' => $chatkey,
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        return [new fake_channel(new telegram_channel()), $chatkey, $userid];
    }

    /**
     * Run a new question in the engine, restoring the PHP time limit the loop raises.
     *
     * @param fake_channel $channel Channel.
     * @param int $userid User.
     * @param string $chatkey Chat key.
     */
    private function run_engine(fake_channel $channel, int $userid, string $chatkey): void {
        $limit = (int) ini_get('max_execution_time');
        (new conversation_engine($channel))->run($userid, $chatkey, 'question');
        set_time_limit($limit);
    }
}
