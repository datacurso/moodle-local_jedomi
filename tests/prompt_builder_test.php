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
 * Unit tests for the Telegram system prompt builder service.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

use local_jedomi\telegram\prompt_builder;

/**
 * Test cases for prompt_builder::build.
 *
 * @covers \local_jedomi\telegram\prompt_builder
 */
final class prompt_builder_test extends \advanced_testcase {
    /**
     * Setup test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test the built system prompt instructs the model to use Telegram HTML.
     *
     * Asserts the format instruction in a wording-tolerant way: mention of
     * Telegram HTML, the supported tags, and a prohibition on markdown.
     */
    public function test_prompt_has_telegram_format(): void {
        $prompt = prompt_builder::build(
            'admin',
            'es',
            ['ws_search', 'execute_sql'],
            [],
            (int) get_admin()->id
        );

        $this->assertIsString($prompt);
        $this->assertMatchesRegularExpression(
            '/telegram\s+html/i',
            $prompt,
            'System prompt should tell the model to use Telegram HTML'
        );
        $this->assertStringContainsString(
            '<b>',
            $prompt,
            'System prompt should mention the supported <b> tag'
        );
        $this->assertStringContainsString(
            '<i>',
            $prompt,
            'System prompt should mention the supported <i> tag'
        );
        $this->assertStringContainsString(
            '<code>',
            $prompt,
            'System prompt should mention the supported <code> tag'
        );
        $this->assertMatchesRegularExpression(
            '/no\s+(uses\s+)?markdown/i',
            $prompt,
            'System prompt should forbid markdown'
        );
    }

    /**
     * Test the prompt embeds the caller context (role, user, language, tools).
     */
    public function test_prompt_embeds_context(): void {
        $prompt = prompt_builder::build(
            'student',
            'en',
            ['ws_search'],
            ['student' => ['Course X']],
            42
        );

        $this->assertStringContainsString('Role: student', $prompt);
        $this->assertStringContainsString('User: 42', $prompt);
        $this->assertStringContainsString('Lang: en', $prompt);
        $this->assertStringContainsString('ws_search', $prompt);
        $this->assertStringContainsString('Course X', $prompt);
        $this->assertStringContainsString('Admin: NO', $prompt);
    }
}
