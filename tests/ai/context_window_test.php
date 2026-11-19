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

namespace local_jedomi\ai;

/**
 * Tests for the context window of the custom AI service: the token estimate and the trimming of the oldest messages.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\context_window
 */
final class context_window_test extends \advanced_testcase {
    /**
     * A message whose estimate is exactly a number of tokens is not needed: a message of a known length is enough.
     *
     * @param string $role Role.
     * @param int $chars Characters of its content.
     * @param string $mark Text at the start of the content, to find the message again.
     * @return array
     */
    private static function message(string $role, int $chars, string $mark): array {
        return ['role' => $role, 'content' => $mark . str_repeat('x', max(0, $chars - strlen($mark)))];
    }

    /**
     * Marks of the kept messages.
     *
     * @param array $messages Messages.
     * @return string[]
     */
    private static function marks(array $messages): array {
        return array_map(static fn(array $m): string => (string) strtok((string) ($m['content'] ?? ''), 'x'), $messages);
    }

    /**
     * The estimate is about one token per four characters of the encoded message, so it grows with the text and counts
     * the tool definitions too.
     *
     * @return void
     */
    public function test_estimate_is_about_four_characters_per_token(): void {
        $short = context_window::estimate_tokens([['role' => 'user', 'content' => 'Hi']]);
        $long = context_window::estimate_tokens([['role' => 'user', 'content' => str_repeat('a', 4000)]]);

        $this->assertGreaterThan(0, $short);
        $this->assertGreaterThanOrEqual(1000, $long);
        $this->assertLessThan(1100, $long);
        $tools = [['type' => 'function', 'function' => ['name' => 'x', 'description' => str_repeat('d', 400)]]];
        $this->assertGreaterThanOrEqual(100, context_window::estimate_tokens([], $tools));
        // Multibyte characters count as characters, not bytes.
        $this->assertSame(
            context_window::estimate_tokens([['role' => 'user', 'content' => str_repeat('a', 400)]]),
            context_window::estimate_tokens([['role' => 'user', 'content' => str_repeat('é', 400)]])
        );
    }

    /**
     * A conversation inside the budget is sent as it is.
     *
     * @return void
     */
    public function test_conversation_within_the_budget_is_unchanged(): void {
        $messages = [self::message('system', 400, 'S'), self::message('user', 400, 'U1')];

        $result = context_window::fit($messages, 10000);

        $this->assertSame($messages, $result['messages']);
        $this->assertSame(0, $result['dropped']);
    }

    /**
     * The oldest messages are dropped first; the system message and the latest user turn always stay.
     *
     * @return void
     */
    public function test_oldest_messages_are_dropped_first(): void {
        $messages = [
            self::message('system', 400, 'S'),
            self::message('user', 4000, 'U1'),
            self::message('assistant', 4000, 'A1'),
            self::message('user', 4000, 'U2'),
            self::message('assistant', 4000, 'A2'),
            self::message('user', 400, 'U3'),
        ];

        $result = context_window::fit($messages, 2500);

        $this->assertSame(['S', 'U2', 'A2', 'U3'], self::marks($result['messages']));
        $this->assertSame(2, $result['dropped']);
        $this->assertLessThanOrEqual(2500, context_window::estimate_tokens($result['messages']));
    }

    /**
     * An assistant message with tool calls and its tool results are dropped together, never split.
     *
     * @return void
     */
    public function test_tool_calls_and_their_results_are_dropped_together(): void {
        $call = ['role' => 'assistant', 'content' => 'T', 'tool_calls' => [
            ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{}']],
            ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'y', 'arguments' => '{}']],
        ]];
        $messages = [
            self::message('system', 40, 'S'),
            self::message('user', 400, 'U1'),
            $call,
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'R1' . str_repeat('x', 4000)],
            ['role' => 'tool', 'tool_call_id' => 'c2', 'content' => 'R2' . str_repeat('x', 4000)],
            self::message('assistant', 400, 'A1'),
            self::message('user', 400, 'U2'),
        ];

        $result = context_window::fit($messages, 600);

        $roles = array_column($result['messages'], 'role');
        $this->assertNotContains('tool', $roles, 'No tool result is left without its call');
        foreach ($result['messages'] as $message) {
            $this->assertArrayNotHasKey('tool_calls', $message, 'No call is left without its results');
        }
        $this->assertSame('S', self::marks($result['messages'])[0]);
        $this->assertSame('U2', self::marks($result['messages'])[count($result['messages']) - 1]);
    }

    /**
     * The tool calls and results of the current turn (after the latest user message) are part of that turn and stay,
     * even when the budget is exceeded: the request is then sent as small as it can be.
     *
     * @return void
     */
    public function test_current_turn_is_never_dropped(): void {
        $messages = [
            self::message('system', 400, 'S'),
            self::message('user', 4000, 'U1'),
            self::message('assistant', 4000, 'A1'),
            self::message('user', 400, 'U2'),
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => 'R1' . str_repeat('x', 40000)],
        ];

        $result = context_window::fit($messages, 100);

        $this->assertSame(['system', 'user', 'assistant', 'tool'], array_column($result['messages'], 'role'));
        $this->assertSame('U2', self::marks($result['messages'])[1]);
        $this->assertSame(2, $result['dropped']);
    }

    /**
     * The kept history starts with a user message: an assistant answer left first after the system message is dropped
     * too, because many chat templates require the user to speak first.
     *
     * @return void
     */
    public function test_kept_history_starts_with_a_user_message(): void {
        $messages = [
            self::message('system', 40, 'S'),
            self::message('user', 8000, 'U1'),
            self::message('assistant', 400, 'A1'),
            self::message('user', 400, 'U2'),
        ];

        $result = context_window::fit($messages, 300);

        $this->assertSame(['S', 'U2'], self::marks($result['messages']));
        $this->assertSame(2, $result['dropped']);
    }

    /**
     * Without a user message nothing can be told apart as the latest turn, so nothing is dropped; a zero or negative
     * budget keeps only what must stay.
     *
     * @return void
     */
    public function test_edge_cases(): void {
        $nouser = [self::message('system', 4000, 'S'), self::message('assistant', 4000, 'A1')];
        $this->assertSame($nouser, context_window::fit($nouser, 10)['messages']);

        $messages = [self::message('system', 40, 'S'), self::message('user', 400, 'U1'), self::message('user', 400, 'U2')];
        $this->assertSame(['S', 'U2'], self::marks(context_window::fit($messages, 0)['messages']));
        $this->assertSame(['S', 'U2'], self::marks(context_window::fit($messages, -50)['messages']));
        $this->assertSame([], context_window::fit([], 10)['messages']);
    }

    /**
     * The context window of the custom service: the saved value, else the length detected for the saved chat model on
     * verification, else none (no trimming).
     *
     * @return void
     */
    public function test_window_of_the_custom_service(): void {
        $this->resetAfterTest();
        set_config('custom_model', 'chat-model', 'local_jedomi');

        $this->assertSame(0, ai_service::get_custom_context_window());
        set_config('custom_context_lengths', json_encode(['chat-model' => 32768, 'other' => 4096]), 'local_jedomi');
        $this->assertSame(32768, ai_service::get_custom_context_window());
        set_config('custom_context_window', '20000', 'local_jedomi');
        $this->assertSame(20000, ai_service::get_custom_context_window());
        set_config('custom_context_window', '', 'local_jedomi');
        set_config('custom_model', 'unknown', 'local_jedomi');
        $this->assertSame(0, ai_service::get_custom_context_window());
    }

    /**
     * Only the custom service with a known window trims, reserving the answer and the tool definitions; the trimming is
     * logged without any content.
     *
     * @return void
     */
    public function test_fit_for_custom_service(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $messages = [
            ['role' => 'system', 'content' => 'System'],
            ['role' => 'user', 'content' => 'Secret old question ' . str_repeat('x', 40000)],
            ['role' => 'assistant', 'content' => str_repeat('y', 40000)],
            ['role' => 'user', 'content' => 'New question'],
        ];

        // The Datacurso service is never trimmed.
        $this->assertSame($messages, context_window::fit_for_custom_service($messages, []));

        set_config('ai_service', ai_service::CUSTOM, 'local_jedomi');
        set_config('custom_model', 'chat-model', 'local_jedomi');
        set_config('custom_max_tokens', '1024', 'local_jedomi');
        // No window known: nothing is trimmed.
        $this->assertSame($messages, context_window::fit_for_custom_service($messages, []));

        set_config('custom_context_window', '8192', 'local_jedomi');
        $fitted = context_window::fit_for_custom_service($messages, [['type' => 'function', 'function' => ['name' => 'x']]]);
        $this->assertSame(['system', 'user'], array_column($fitted, 'role'));
        $this->assertSame('New question', $fitted[1]['content']);

        // A window large enough for everything keeps everything.
        set_config('custom_context_window', '100000', 'local_jedomi');
        $this->assertSame($messages, context_window::fit_for_custom_service($messages, []));
    }

    /**
     * U3: an upgraded site keeps max tokens 16384 while the service tells a window of 16384 or 8192 tokens: the answer
     * reservation is cut to half the window, so the history that fits is kept instead of dropping all of it.
     *
     * @return void
     */
    public function test_answer_reservation_never_takes_the_whole_window(): void {
        $this->resetAfterTest();
        set_config('ai_service', ai_service::CUSTOM, 'local_jedomi');
        set_config('custom_model', 'chat-model', 'local_jedomi');
        set_config('custom_max_tokens', '16384', 'local_jedomi');
        $messages = [
            ['role' => 'system', 'content' => 'System'],
            ['role' => 'user', 'content' => 'Old question ' . str_repeat('q', 4000)],
            ['role' => 'assistant', 'content' => 'Old answer ' . str_repeat('a', 4000)],
            ['role' => 'user', 'content' => 'New question'],
        ];

        foreach ([16384, 8192] as $window) {
            set_config('custom_context_lengths', json_encode(['chat-model' => $window]), 'local_jedomi');
            $this->assertSame($messages, context_window::fit_for_custom_service($messages, []), "Window {$window}");
            $this->assertSame(intdiv($window, 2), context_window::answer_reservation($window));
        }
        $this->assertSame(1024, context_window::answer_reservation(100000, 1024));
    }

    /**
     * The max tokens sent with the custom service is the answer reservation of a known window, so a saved value larger
     * than half the window (saved before 1.6.3) is never sent; without a window, or with the Datacurso service, it is
     * the saved value.
     *
     * @return void
     */
    public function test_answer_tokens_fit_the_window(): void {
        $this->resetAfterTest();
        set_config('max_tokens', '8000', 'local_jedomi');
        $this->assertSame(8000, context_window::answer_tokens());

        set_config('ai_service', ai_service::CUSTOM, 'local_jedomi');
        set_config('custom_model', 'chat-model', 'local_jedomi');
        set_config('custom_max_tokens', '16384', 'local_jedomi');
        $this->assertSame(16384, context_window::answer_tokens(), 'No window: the saved value');

        set_config('custom_context_lengths', json_encode(['chat-model' => 8192]), 'local_jedomi');
        $this->assertSame(4096, context_window::answer_tokens());
        set_config('custom_context_window', '100000', 'local_jedomi');
        $this->assertSame(16384, context_window::answer_tokens());
    }
}
