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
 * Tests for the detection of final answers without text.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\empty_answer
 */
final class empty_answer_test extends \advanced_testcase {
    /**
     * Answers that are, or are not, final answers without text.
     *
     * @return array
     */
    public static function answers_provider(): array {
        return [
            'stop with thinking text is shown' => [
                ['content' => '', 'tool_calls' => [], 'finish_reason' => 'stop', 'reasoning' => 'The answer is 4.'], false,
            ],
            'stop without any text' => [['content' => '', 'tool_calls' => [], 'finish_reason' => 'stop'], true],
            'out of tokens without thinking text' => [
                ['content' => '', 'tool_calls' => [], 'finish_reason' => 'length', 'reasoning' => ''], true,
            ],
            'out of tokens with thinking text' => [
                ['content' => '', 'tool_calls' => [], 'finish_reason' => 'length', 'reasoning' => 'Let me think'], true,
            ],
            'tool calls only' => [
                ['content' => '', 'tool_calls' => [['id' => 'c1']], 'finish_reason' => 'tool_calls'], false,
            ],
            'whitespace only' => [['content' => "  \n\t ", 'tool_calls' => [], 'finish_reason' => 'stop'], true],
            'text' => [['content' => 'Hello', 'tool_calls' => [], 'finish_reason' => 'length'], false],
        ];
    }

    /**
     * REL-001: only a final answer with no text, no tool call and (unless out of tokens) no thinking text is empty.
     *
     * @dataProvider answers_provider
     * @param array $answer Normalised answer.
     * @param bool $expected Whether it is empty.
     */
    public function test_is_empty(array $answer, bool $expected): void {
        $this->assertSame($expected, empty_answer::is_empty($answer));
    }

    /**
     * REL-001: the retry asks for a short answer after running out of tokens, and for any text after an empty stop.
     */
    public function test_retry_message_depends_on_the_finish_reason(): void {
        $length = empty_answer::retry_message('length');
        $stop = empty_answer::retry_message('stop');

        $this->assertSame('user', $length['role']);
        $this->assertStringContainsString('ran out of space', $length['content']);
        $this->assertSame('user', $stop['role']);
        $this->assertStringContainsString('was empty', $stop['content']);
    }
}
