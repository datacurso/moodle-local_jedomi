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

/**
 * Unit tests for the OpenAI-compatible SSE parser.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

use local_jedomi\ai\sse_parser;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Test cases for sse_parser::parse.
 *
 * @covers \local_jedomi\ai\sse_parser
 */
final class ai_sse_parser_test extends \basic_testcase {
    /**
     * Text deltas are concatenated and finish_reason is the last one seen.
     */
    public function test_parse_accumulates_text_content(): void {
        $buffer = fake_transport::sse([
            fake_transport::text_chunk('Hola'),
            fake_transport::text_chunk(', mundo'),
            fake_transport::text_chunk('', 'stop'),
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertSame('Hola, mundo', $result['text']);
        $this->assertSame('', $result['reasoning']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame('stop', $result['finish_reason']);
        $this->assertTrue($result['had_data']);
        $this->assertNull($result['error']);
    }

    /**
     * Reasoning tokens are collected separately from the visible content.
     */
    public function test_parse_collects_reasoning_content(): void {
        $buffer = fake_transport::sse([
            ['choices' => [['delta' => ['reasoning_content' => 'thinking '], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['reasoning_content' => 'hard'], 'finish_reason' => null]]],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertSame('', $result['text']);
        $this->assertSame('thinking hard', $result['reasoning']);
    }

    /**
     * Tool calls streamed across chunks are merged by index with concatenated arguments.
     */
    public function test_parse_accumulates_tool_calls_by_index(): void {
        $buffer = fake_transport::sse([
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 1, 'id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'second', 'arguments' => '']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'first', 'arguments' => '{"q":']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '"x"}']],
                ['index' => 1, 'function' => ['arguments' => '{}']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertSame('tool_calls', $result['finish_reason']);
        $this->assertCount(2, $result['tool_calls']);
        $this->assertSame(
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'first', 'arguments' => '{"q":"x"}']],
            $result['tool_calls'][0],
            'Tool calls must be ordered by their stream index'
        );
        $this->assertSame(
            ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'second', 'arguments' => '{}']],
            $result['tool_calls'][1]
        );
    }

    /**
     * Gemini's OpenAI-compatible endpoint streams parallel tool calls WITHOUT "index": each entry is
     * complete (own id, name, full arguments) and several may share one chunk. They must stay separate.
     */
    public function test_parse_keeps_parallel_tool_calls_without_index_in_one_chunk(): void {
        $buffer = fake_transport::sse([
            ['choices' => [['delta' => ['role' => 'assistant', 'tool_calls' => [
                [
                    'extra_content' => ['google' => ['thought_signature' => 'sig-a']],
                    'function' => ['arguments' => '{"query":"get course by shortname"}', 'name' => 'ws_search'],
                    'id' => 'call_5581',
                    'type' => 'function',
                ],
                [
                    'function' => ['arguments' => '{"query":"get user by username"}', 'name' => 'ws_search'],
                    'id' => 'call_5585',
                    'type' => 'function',
                ],
            ]], 'index' => 0]]],
            ['choices' => [['delta' => ['role' => 'assistant'], 'finish_reason' => 'stop', 'index' => 0]]],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertCount(2, $result['tool_calls']);
        $this->assertSame(
            [
                'id' => 'call_5581',
                'type' => 'function',
                'function' => ['name' => 'ws_search', 'arguments' => '{"query":"get course by shortname"}'],
                'extra_content' => ['google' => ['thought_signature' => 'sig-a']],
            ],
            $result['tool_calls'][0]
        );
        $this->assertSame(
            [
                'id' => 'call_5585',
                'type' => 'function',
                'function' => ['name' => 'ws_search', 'arguments' => '{"query":"get user by username"}'],
            ],
            $result['tool_calls'][1],
            'The second call must not inherit the first call\'s extra_content'
        );
    }

    /**
     * Gemini shape captured live (tmp_eval/results/v2/datacurso/_bug_parallel_toolcalls/_raw_A3postpatch_turn1.json):
     * four parallel calls, one per chunk, none with "index", only the first with extra_content.
     */
    public function test_parse_keeps_parallel_tool_calls_without_index_across_chunks(): void {
        $chunk = static function (array $tc): array {
            return [
                'choices' => [['delta' => ['role' => 'assistant', 'tool_calls' => [$tc]], 'index' => 0]],
                'model' => 'gemini-3-flash-preview',
                'object' => 'chat.completion.chunk',
            ];
        };
        $buffer = fake_transport::sse([
            $chunk([
                'extra_content' => ['google' => ['thought_signature' => 'EusJCugJARFNMg8o7bXENC4Q']],
                'function' => ['arguments' => '{"query":"get course by shortname"}', 'name' => 'ws_search'],
                'id' => 'call_5581',
                'type' => 'function',
            ]),
            $chunk(['function' => ['arguments' => '{"query":"get user by username"}', 'name' => 'ws_search'],
                'id' => 'call_5585', 'type' => 'function']),
            $chunk(['function' => ['arguments' => '{"query":"get roles"}', 'name' => 'ws_search'],
                'id' => 'call_5589', 'type' => 'function']),
            $chunk(['function' => ['arguments' => '{"query":"manual enrol users"}', 'name' => 'ws_search'],
                'id' => 'call_5593', 'type' => 'function']),
            ['choices' => [['delta' => ['role' => 'assistant'], 'finish_reason' => 'stop', 'index' => 0]]],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertSame('stop', $result['finish_reason']);
        $this->assertSame(
            ['call_5581', 'call_5585', 'call_5589', 'call_5593'],
            array_column($result['tool_calls'], 'id'),
            'Every parallel call must keep its own id, in arrival order'
        );
        $this->assertSame(
            [
                '{"query":"get course by shortname"}',
                '{"query":"get user by username"}',
                '{"query":"get roles"}',
                '{"query":"manual enrol users"}',
            ],
            array_map(static fn(array $tc): string => $tc['function']['arguments'], $result['tool_calls']),
            'Arguments must not be concatenated across calls'
        );
        $this->assertSame('EusJCugJARFNMg8o7bXENC4Q', $result['tool_calls'][0]['extra_content']['google']['thought_signature']);
        $this->assertArrayNotHasKey('extra_content', $result['tool_calls'][3]);
    }

    /**
     * A fragment with neither "index" nor "id" is a continuation of the most recent call.
     */
    public function test_parse_appends_continuation_without_index_or_id_to_last_call(): void {
        $buffer = fake_transport::sse([
            ['choices' => [['delta' => ['tool_calls' => [
                ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'first', 'arguments' => '{"a":1}']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [
                ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'second', 'arguments' => '{"q":']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [
                ['function' => ['arguments' => '"x"}']],
            ]], 'finish_reason' => 'tool_calls']]],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertCount(2, $result['tool_calls']);
        $this->assertSame('{"a":1}', $result['tool_calls'][0]['function']['arguments']);
        $this->assertSame('call_b', $result['tool_calls'][1]['id']);
        $this->assertSame('{"q":"x"}', $result['tool_calls'][1]['function']['arguments']);
    }

    /**
     * A later fragment without "index" that repeats an already seen id merges into that call.
     */
    public function test_parse_merges_repeated_id_without_index_instead_of_duplicating(): void {
        $buffer = fake_transport::sse([
            ['choices' => [['delta' => ['tool_calls' => [
                ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'first', 'arguments' => '{"a":']],
                ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'second', 'arguments' => '{}']],
            ]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [
                ['id' => 'call_a', 'function' => ['arguments' => '1}'], 'extra_content' => ['google' => ['x' => 1]]],
            ]], 'finish_reason' => 'tool_calls']]],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertCount(2, $result['tool_calls']);
        $this->assertSame(
            [
                'id' => 'call_a',
                'type' => 'function',
                'function' => ['name' => 'first', 'arguments' => '{"a":1}'],
                'extra_content' => ['google' => ['x' => 1]],
            ],
            $result['tool_calls'][0]
        );
        $this->assertSame('call_b', $result['tool_calls'][1]['id']);
        $this->assertSame('{}', $result['tool_calls'][1]['function']['arguments']);
    }

    /**
     * Vendor keys on a tool_call (e.g. Gemini's extra_content.google.thought_signature) are kept
     * and merged across deltas, both at the top level and inside "function".
     */
    public function test_parse_preserves_extra_tool_call_keys(): void {
        $buffer = fake_transport::sse([
            ['choices' => [['delta' => ['tool_calls' => [
                [
                    'index' => 0,
                    'id' => 'call_g',
                    'type' => 'function',
                    'function' => ['name' => 'get_time', 'arguments' => '{"tz":', 'vendor_flag' => true],
                    'extra_content' => ['google' => ['thought_signature' => 'abc']],
                ],
            ]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [
                [
                    'index' => 0,
                    'function' => ['arguments' => '"UTC"}'],
                    'extra_content' => ['google' => ['other' => 1]],
                ],
            ]], 'finish_reason' => 'tool_calls']]],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertSame(
            [
                'id' => 'call_g',
                'type' => 'function',
                'function' => ['name' => 'get_time', 'arguments' => '{"tz":"UTC"}', 'vendor_flag' => true],
                'extra_content' => ['google' => ['thought_signature' => 'abc', 'other' => 1]],
            ],
            $result['tool_calls'][0]
        );
    }

    /**
     * A later delta overrides a scalar extra key instead of appending to it.
     */
    public function test_parse_extra_scalar_key_is_overridden_by_later_delta(): void {
        $buffer = fake_transport::sse([
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'c', 'function' => ['name' => 'f', 'arguments' => '{}'], 'vendor_id' => 'v1'],
            ]], 'finish_reason' => null]]],
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'vendor_id' => 'v2'],
            ]], 'finish_reason' => null]]],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertSame('v2', $result['tool_calls'][0]['vendor_id']);
        $this->assertArrayNotHasKey('extra_content', $result['tool_calls'][0]);
    }

    /**
     * Some servers send arguments as an already decoded object; it must be re-encoded.
     */
    public function test_parse_encodes_predecoded_arguments(): void {
        $buffer = fake_transport::sse([
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'c1', 'function' => ['name' => 'search', 'arguments' => ['q' => 'niño']]],
            ]], 'finish_reason' => null]]],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertSame('{"q":"niño"}', $result['tool_calls'][0]['function']['arguments']);
        $this->assertSame('function', $result['tool_calls'][0]['type'], 'Missing type defaults to function');
    }

    /**
     * Anything after the [DONE] sentinel is ignored.
     */
    public function test_parse_stops_at_done(): void {
        $buffer = fake_transport::sse([fake_transport::text_chunk('before')])
            . "\n" . fake_transport::sse([fake_transport::text_chunk('after')]);

        $result = sse_parser::parse($buffer);

        $this->assertSame('before', $result['text']);
    }

    /**
     * Usage-only chunks (empty choices) and malformed lines are tolerated.
     */
    public function test_parse_tolerates_usage_only_and_garbage_chunks(): void {
        $buffer = fake_transport::sse([
            fake_transport::text_chunk('ok'),
            ['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]],
            'not json at all',
        ]) . "\n: comment line\nevent: ping\n";

        $result = sse_parser::parse($buffer);

        $this->assertSame('ok', $result['text']);
        $this->assertTrue($result['had_data']);
        $this->assertNull($result['error']);
    }

    /**
     * An empty or non-SSE buffer yields an empty result with had_data false.
     */
    public function test_parse_empty_buffer(): void {
        $result = sse_parser::parse('');

        $this->assertSame('', $result['text']);
        $this->assertSame('', $result['reasoning']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame('', $result['finish_reason']);
        $this->assertFalse($result['had_data']);
        $this->assertNull($result['error']);

        $this->assertFalse(sse_parser::parse("<html>502 Bad Gateway</html>")['had_data']);
    }

    /**
     * An in-stream error chunk is surfaced instead of being silently ignored.
     */
    public function test_parse_surfaces_error_chunk(): void {
        $buffer = fake_transport::sse([
            ['error' => ['code' => 'upstream_timeout', 'message' => 'Upstream timed out']],
        ]);

        $result = sse_parser::parse($buffer);

        $this->assertIsArray($result['error']);
        $this->assertSame('upstream_timeout', $result['error']['code']);
        $this->assertSame('Upstream timed out', $result['error']['message']);
        $this->assertTrue($result['had_data']);
    }

    /**
     * A bare string error is normalised into the same {message} shape.
     */
    public function test_parse_normalises_string_error(): void {
        $result = sse_parser::parse(fake_transport::sse([['error' => 'boom']]));

        $this->assertSame(['message' => 'boom'], $result['error']);
    }
}
