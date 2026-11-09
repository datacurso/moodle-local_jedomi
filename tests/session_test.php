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

/**
 * Unit tests for telegram session MAX_TURNS config propagation.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

/**
 * Test cases for session MAX_TURNS config propagation and message trimming.
 *
 * @covers \local_jedomi\telegram\session
 */
final class session_test extends \advanced_testcase {
    /**
     * Setup test environment — clean DB state between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // ... ========================
    // MAX_TURNS config propagation
    // ========================

    /**
     * MDL-UNIT-006: the configured telegram_max_turns reaches the bot conversation.
     *
     * With max_turns=3, appending 5 user messages should trim history to 3,
     * so build_messages_for_gateway returns: system + 3 history + current = 5 total.
     */
    public function test_max_turns_config_propagation(): void {
        set_config('telegram_max_turns', 3, 'local_jedomi');

        // Append 5 user messages. With max_turns=3, history should be trimmed to 3.
        \local_jedomi\telegram\session::append('test_chat_999', 'user', 'msg1');
        \local_jedomi\telegram\session::append('test_chat_999', 'user', 'msg2');
        \local_jedomi\telegram\session::append('test_chat_999', 'user', 'msg3');
        \local_jedomi\telegram\session::append('test_chat_999', 'user', 'msg4');
        \local_jedomi\telegram\session::append('test_chat_999', 'user', 'msg5');

        // Build messages for gateway.
        $messages = \local_jedomi\telegram\session::build_messages_for_gateway('test_chat_999', 'current');

        // System prompt + 3 history messages + 1 current = 5 total.
        $this->assertCount(5, $messages, 'Should have system + 3 history + 1 current = 5 messages');

        // First message should be the system prompt.
        $this->assertEquals('system', $messages[0]['role']);
        $this->assertStringContainsString('Jedomi', $messages[0]['content']);

        // History messages should only include the last 3 (msg3, msg4, msg5).
        $this->assertEquals('msg3', $messages[1]['content']);
        $this->assertEquals('msg4', $messages[2]['content']);
        $this->assertEquals('msg5', $messages[3]['content']);
        $this->assertEquals('user', $messages[3]['role']);

        // Last message should be the current message.
        $this->assertEquals('user', $messages[4]['role']);
        $this->assertEquals('current', $messages[4]['content']);

        // Count user messages: 3 history + 1 current = 4.
        $usermsgs = array_filter($messages, fn($m) => $m['role'] === 'user');
        $this->assertCount(4, $usermsgs);
    }

    /**
     * MDL-UNIT-006: max turns default to 10 when the setting is not saved.
     */
    public function test_max_turns_default(): void {
        // Ensure no config is set.
        unset_config('telegram_max_turns', 'local_jedomi');

        // Append 11 messages — with default 10, the 11th should trigger trim.
        for ($i = 1; $i <= 11; $i++) {
            \local_jedomi\telegram\session::append('test_chat_default', 'user', "msg{$i}");
        }

        $messages = \local_jedomi\telegram\session::build_messages_for_gateway('test_chat_default', 'final');
        $usermsgs = array_filter($messages, fn($m) => $m['role'] === 'user');

        // Default max_turns is 10, so only 10 user messages should be in history,
        // plus the current "final" message = 11 total... wait, let me count.
        // With max_turns=10, trim keeps last 10 of 11. So history has 10 user messages.
        // build_messages_for_gateway returns: system + 10 history + current = 12 total.
        // User messages: 10 history (all user) + current = 11.
        $this->assertCount(12, $messages, 'Should have system + 10 history + 1 current = 12');
        $this->assertCount(11, $usermsgs, 'Should have 10 history + 1 current = 11 user messages');

        // The earliest kept message should be msg2 (msg1 was trimmed).
        $this->assertEquals('msg2', $messages[1]['content']);
        $this->assertEquals('msg11', $messages[10]['content']);
    }

    /**
     * MDL-UNIT-006: a value below the minimum is clamped to 1 turn instead of crashing.
     */
    public function test_max_turns_clamp_low(): void {
        set_config('telegram_max_turns', 0, 'local_jedomi');

        // Append 3 messages — with max_turns clamped to 1, history should keep only 1.
        \local_jedomi\telegram\session::append('test_clamp_low', 'user', 'first');
        \local_jedomi\telegram\session::append('test_clamp_low', 'user', 'second');
        \local_jedomi\telegram\session::append('test_clamp_low', 'user', 'third');

        $messages = \local_jedomi\telegram\session::build_messages_for_gateway('test_clamp_low', 'current');
        $usermsgs = array_filter($messages, fn($m) => $m['role'] === 'user');

        // Max_turns clamped to 1, so history has 1 message + current = 2 user messages.
        // Total: system + 1 history + current = 3.
        $this->assertCount(3, $messages, 'Should have system + 1 history + 1 current = 3');
        $this->assertCount(2, $usermsgs);
        $this->assertEquals(
            'third',
            $messages[1]['content'],
            'Only the last message should be kept with max_turns=1'
        );
    }

    /**
     * MDL-UNIT-006: values above the maximum are clamped to 100 turns.
     */
    public function test_max_turns_clamp_high(): void {
        set_config('telegram_max_turns', 999, 'local_jedomi');

        // Append 101 messages — with max_turns clamped to 100, should keep 100.
        for ($i = 1; $i <= 101; $i++) {
            \local_jedomi\telegram\session::append('test_clamp_high', 'user', "msg{$i}");
        }

        $messages = \local_jedomi\telegram\session::build_messages_for_gateway('test_clamp_high', 'current');
        $usermsgs = array_filter($messages, fn($m) => $m['role'] === 'user');

        // Max_turns=100: 100 history msgs + current = 101 user msgs + system = 102 total.
        $this->assertCount(102, $messages);
        $this->assertCount(101, $usermsgs);
    }

    // ... ========================
    // Session get/clear
    // ========================

    /**
     * Test that session::get returns empty array for unknown chat.
     */
    public function test_get_unknown_chat(): void {
        $messages = \local_jedomi\telegram\session::get('nonexistent_chat_xyz');
        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }

    /**
     * Test that session::clear removes all messages for a chat.
     */
    public function test_clear_removes_messages(): void {
        \local_jedomi\telegram\session::append('test_clear_chat', 'user', 'hello');
        \local_jedomi\telegram\session::append('test_clear_chat', 'assistant', 'hi');

        // Verify messages exist.
        $before = \local_jedomi\telegram\session::get('test_clear_chat');
        $this->assertCount(2, $before);

        // Clear and verify.
        \local_jedomi\telegram\session::clear('test_clear_chat');
        $after = \local_jedomi\telegram\session::get('test_clear_chat');
        $this->assertEmpty($after);
    }

    /**
     * Test that build_messages_for_gateway includes assistant messages from history.
     */
    public function test_build_messages_includes_assistant_history(): void {
        \local_jedomi\telegram\session::append('test_assistant', 'user', 'question');
        \local_jedomi\telegram\session::append('test_assistant', 'assistant', 'answer');

        $messages = \local_jedomi\telegram\session::build_messages_for_gateway('test_assistant', 'followup');

        // System + user msg + assistant msg + current = 4.
        $this->assertCount(4, $messages);
        $this->assertEquals('assistant', $messages[2]['role']);
        $this->assertEquals('answer', $messages[2]['content']);
    }
}
