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

/**
 * Tests for the reader of the tool calls a model writes as text.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\tool_call_text_parser
 */
final class tool_call_text_parser_test extends \basic_testcase {
    /**
     * Text formats of tool calls and what is read from each.
     *
     * @return array[] [text, expected [prefix, name, arguments][], expected remaining text]
     */
    public static function formats(): array {
        return [
            'TOOL_CALL block' => [
                "Let me check.\nTOOL_CALL: get_x\n{\"a\":1}\nEND_TOOL_CALL",
                [['text_', 'get_x', '{"a":1}']],
                'Let me check.',
            ],
            'call block with text around the JSON' => [
                "call: get_x\nargs: {\"a\":1} please\nEND_TOOL_CALL (done)\nAfter.",
                [['text_', 'get_x', '{"a":1}']],
                'After.',
            ],
            'block with an unreadable call next to a readable one' => [
                "TOOL_CALL: bad\nnope\nEND_TOOL_CALL\nTOOL_CALL: get_y\n[1]\nEND_TOOL_CALL",
                [['text_', 'get_y', '[1]']],
                '',
            ],
            'inline TOOL_CALL' => [
                'Before. TOOL_CALL: get_x {"a":2} END_TOOL_CALL',
                [['text_', 'get_x', '{"a":2}']],
                'Before.',
            ],
            'special token with name and JSON lines' => [
                "Sure.<|tool_call|>get_x\n{\"a\":3}<|/get_x\n{\"a\":3}|>",
                [['text_', 'get_x', '{"a":3}']],
                'Sure.',
            ],
            'special token with a name only' => [
                '<|tool_call|>get_x<|/get_x|>',
                [['text_', 'get_x', '{}']],
                '',
            ],
            'special token with a JSON object' => [
                '<|python_tag|>{"name":"get_x","arguments":{"a":"é"}}<|/{"name":"get_x","arguments":{"a":"é"}}|>',
                [['text_', 'get_x', '{"a":"é"}']],
                '',
            ],
            'ultra-broad fallback after an unreadable inline call' => [
                'call: get_x junk END_TOOL_CALL call: get_y {"y":2} END_TOOL_CALL',
                [['ultra_', 'get_y', '{"y":2}']],
                '',
            ],
        ];
    }

    /**
     * Each format gives its tool calls, in the OpenAI format with a fresh id, and the text without them.
     *
     * @dataProvider formats
     * @param string $content Text the model answered.
     * @param array $expected [prefix, name, arguments] of each call.
     * @param string $remaining Text without the calls.
     */
    public function test_parse_reads_the_format(string $content, array $expected, string $remaining): void {
        $parsed = tool_call_text_parser::parse($content);

        $this->assertSame($remaining, $parsed['remaining']);
        $this->assertCount(count($expected), $parsed['tool_calls']);
        foreach ($expected as $i => [$prefix, $name, $arguments]) {
            $call = $parsed['tool_calls'][$i];
            $this->assertMatchesRegularExpression('/^' . $prefix . '[0-9a-f]{32}$/', $call['id']);
            $this->assertSame(
                ['id' => $call['id'], 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $arguments]],
                $call
            );
        }
    }

    /**
     * Two calls never share an id.
     */
    public function test_each_call_gets_its_own_id(): void {
        $parsed = tool_call_text_parser::parse("TOOL_CALL: a\n{}\nEND_TOOL_CALL\nTOOL_CALL: b\n{}\nEND_TOOL_CALL");

        $this->assertCount(2, $parsed['tool_calls']);
        $this->assertNotSame($parsed['tool_calls'][0]['id'], $parsed['tool_calls'][1]['id']);
    }

    /**
     * A text without a readable call gives no calls and keeps its text; whether it looks like one is told apart.
     */
    public function test_text_without_a_readable_call(): void {
        $this->assertSame(['tool_calls' => [], 'remaining' => 'Call me later.'], tool_call_text_parser::parse('Call me later.'));
        $this->assertFalse(tool_call_text_parser::looks_like_tool_call('Call me later.'));

        $unreadable = "TOOL_CALL: get_x\nnot json\nEND_TOOL_CALL";
        $this->assertSame(['tool_calls' => [], 'remaining' => $unreadable], tool_call_text_parser::parse($unreadable));
        $this->assertTrue(tool_call_text_parser::looks_like_tool_call($unreadable));
    }
}
