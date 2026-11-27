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

namespace local_jedomi\messaging;

use local_jedomi\telegram\manager;
use local_jedomi\telegram\telegram_limits;

/**
 * Tests for the channel-neutral system prompt builder: the format rules come from the limits of the channel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\prompt_builder
 * @covers     \local_jedomi\telegram\telegram_limits
 * @covers     \local_jedomi\telegram\prompt_builder
 * @covers     \local_jedomi\telegram\manager
 */
final class prompt_builder_test extends \advanced_testcase {
    /**
     * Limits of a channel whose format rules name the language they were asked for.
     *
     * @return channel_limits
     */
    private static function test_limits(): channel_limits {
        return new class implements channel_limits {
            #[\Override]
            public function format_rules(string $userlang): string {
                return "⚡ TEST CHANNEL RULES:\n- Plain text only, in {$userlang}.\n\n";
            }

            #[\Override]
            public function progress_messages(): bool {
                return true;
            }
        };
    }

    /**
     * The format rules of the channel go between the parallel tools block and the iterations block, once.
     */
    public function test_format_rules_come_from_the_channel_limits(): void {
        $prompt = prompt_builder::build('student', 'es', ['my_courses'], [], self::test_limits());

        $this->assertStringContainsString(
            "  This is MUCH faster than doing them one by one.\n\n"
                . "⚡ TEST CHANNEL RULES:\n- Plain text only, in es.\n\n"
                . "⚡ YOU HAVE MANY ITERATIONS — DO NOT GIVE UP:\n",
            $prompt
        );
        $this->assertStringNotContainsString('TELEGRAM', $prompt);
        $this->assertSame(1, substr_count($prompt, 'TEST CHANNEL RULES'));
    }

    /**
     * The rest of the prompt does not depend on the channel: two channels differ only in their format rules.
     */
    public function test_only_the_format_rules_differ_between_channels(): void {
        $this->resetAfterTest();
        $telegram = new telegram_limits();

        $test = prompt_builder::build('admin', 'en', ['ws_search', 'ws_execute'], [], self::test_limits());
        $tg = prompt_builder::build('admin', 'en', ['ws_search', 'ws_execute'], [], $telegram);

        $this->assertSame(
            str_replace(self::test_limits()->format_rules('en'), '', $test),
            str_replace($telegram->format_rules('en'), '', $tg)
        );
    }

    /**
     * The Telegram limits ask for the Telegram HTML subset in the language of the user.
     */
    public function test_telegram_format_rules(): void {
        $this->assertSame(
            "⚡ TELEGRAM RULES:\n"
                . "- The user is WAITING. Don't do extra iterations.\n"
                . "- Use ONLY Telegram HTML: <b>bold</b>, <i>italic</i>, <code>code</code>.\n"
                . "- NO markdown. NEVER use **bold** or *italic* — use <b> and <i>.\n"
                . "- Be concise, direct, respond in de only.\n\n",
            (new telegram_limits())->format_rules('de')
        );
        $this->assertInstanceOf(telegram_limits::class, manager::channel_limits());
    }

    /**
     * The Telegram prompt builder is the channel-neutral builder with the Telegram limits.
     */
    public function test_telegram_builder_delegates_with_the_telegram_limits(): void {
        $this->resetAfterTest();
        // No courses: their block carries a random marker id.
        $courses = [];

        $this->assertSame(
            prompt_builder::build('teacher', 'es', ['my_courses', 'ws_search'], $courses, new telegram_limits()),
            \local_jedomi\telegram\prompt_builder::build('teacher', 'es', ['my_courses', 'ws_search'], $courses)
        );
    }
}
