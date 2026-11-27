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

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for the Telegram transport: plain messages, the "typing" status and the final answer as a message or a file.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\telegram_transport
 * @covers     \local_jedomi\telegram\manager
 */
final class telegram_transport_test extends \advanced_testcase {
    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($this->telegram);
    }

    #[\Override]
    protected function tearDown(): void {
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * The transport of the Telegram bot is the Telegram transport.
     */
    public function test_manager_gives_the_telegram_transport(): void {
        $this->assertInstanceOf(telegram_transport::class, manager::transport());
    }

    /**
     * A text is sent as written, without buttons, through the client of the moment.
     */
    public function test_send_text_sends_the_text_as_written(): void {
        $this->assertTrue((new telegram_transport())->send_text('5001', '<b>Hi</b> & bye'));

        $this->assertSame([['chatid' => '5001', 'text' => '<b>Hi</b> & bye', 'replymarkup' => null]], $this->telegram->sent);
    }

    /**
     * The activity of the chat is the "typing" status, with the timeout asked for or the usual one.
     */
    public function test_send_activity_is_the_typing_status(): void {
        $transport = new telegram_transport();

        $this->assertTrue($transport->send_activity('5001'));
        $this->assertTrue($transport->send_activity('5001', 2));
        $this->telegram->onchataction = static fn(): bool => false;
        $this->assertFalse($transport->send_activity('5001', 2));

        $this->assertSame(['typing', 'typing', 'typing'], $this->telegram->chatactions);
        $this->assertSame([null, 2, 2], $this->telegram->chatactiontimeouts);
    }

    /**
     * The answer of the AI is formatted as the Telegram HTML subset.
     */
    public function test_format_answer_is_the_telegram_html(): void {
        $answer = "**Grades** of *Ana* & <script>x</script>";

        $this->assertSame(formatter::to_html($answer), (new telegram_transport())->format_answer($answer));
    }

    /**
     * A formatted answer is blank when only tags and spaces are left of it once the HTML is removed.
     */
    public function test_answer_with_only_tags_and_spaces_is_blank(): void {
        $transport = new telegram_transport();

        $this->assertTrue($transport->is_blank(''));
        $this->assertTrue($transport->is_blank("  \n "));
        $this->assertTrue($transport->is_blank('<b> </b><i></i>'));
        $this->assertFalse($transport->is_blank('<b>Hi</b>'));
        $this->assertFalse($transport->is_blank('a &lt; b'));
    }

    /**
     * A formatted answer up to 3800 characters without a <pre> block is sent as a message.
     */
    public function test_short_answer_is_sent_as_a_message(): void {
        $answer = str_repeat('a', 3800);

        $this->assertTrue((new telegram_transport())->send_answer('5001', $answer));

        $this->assertSame([], $this->telegram->documents);
        $this->assertSame([['chatid' => '5001', 'text' => $answer, 'replymarkup' => null]], $this->telegram->sent);
    }

    /**
     * A longer answer, or one with a <pre> block, is sent as a text file described by its first line, cut at 200
     * characters; the temporary file is removed.
     */
    public function test_long_or_tabular_answer_is_sent_as_a_file(): void {
        global $CFG;
        $this->resetAfterTest();
        $transport = new telegram_transport();
        $long = "\n  <b>Summary &amp; grades</b>\n" . str_repeat('b', 3800);
        $table = 'Table<pre>a | b</pre>';
        $wide = str_repeat('w', 250) . '<pre>x</pre>';

        $this->assertTrue($transport->send_answer('5001', $long));
        $this->assertTrue($transport->send_answer('5002', $table));
        $this->assertTrue($transport->send_answer('5003', $wide));

        $this->assertSame([], $this->telegram->sent);
        $this->assertSame([
            ['chatid' => '5001', 'caption' => '📄 Summary & grades',
                'content' => "\n  Summary & grades\n" . str_repeat('b', 3800)],
            ['chatid' => '5002', 'caption' => '📄 Tablea | b', 'content' => 'Tablea | b'],
            ['chatid' => '5003', 'caption' => '📄 ' . str_repeat('w', 197) . '...', 'content' => str_repeat('w', 250) . 'x'],
        ], $this->telegram->documents);
        $this->assertSame([], glob($CFG->dataroot . '/jedomi/telegram/chat_*.txt'));
    }
}
