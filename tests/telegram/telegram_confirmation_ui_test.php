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

use local_jedomi\local\service\pending_action_service;
use local_jedomi\messaging\confirmation_card;
use local_jedomi\messaging\confirmation_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for the Telegram confirmation UI: the HTML card, the inline buttons and the reading of the button data.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\telegram_confirmation_ui
 */
final class telegram_confirmation_ui_test extends \advanced_testcase {
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
     * Confirmation word as the user types it.
     *
     * @return string
     */
    private function keyword(): string {
        return \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi'));
    }

    /**
     * A medium action is shown as escaped HTML with its data, and has the confirm and cancel buttons.
     */
    public function test_medium_card_has_both_buttons(): void {
        $card = new confirmation_card(
            'abc123',
            'create_agent',
            pending_action_service::SEVERITY_MEDIUM,
            false,
            ['description' => 'Create <agent>', 'details' => 'Name: A & B']
        );

        (new telegram_confirmation_ui())->show('5001', $card);

        $this->assertSame([[
            'chatid' => '5001',
            'text' => get_string('telegram_confirm_action', 'local_jedomi', "<b>Create &lt;agent&gt;</b>\nName: A &amp; B"),
            'replymarkup' => ['inline_keyboard' => [[
                ['text' => get_string('confirm_btn_yes', 'local_jedomi'), 'callback_data' => 'confirm:abc123'],
                ['text' => get_string('confirm_btn_no', 'local_jedomi'), 'callback_data' => 'cancel:abc123'],
            ]]],
        ]], $this->telegram->sent);
    }

    /**
     * A critical action asks for the confirmation word and has only the cancel button; without details no data line.
     */
    public function test_critical_card_asks_for_the_word(): void {
        $card = new confirmation_card(
            'abc123',
            'delete_agent',
            pending_action_service::SEVERITY_CRITICAL,
            false,
            ['description' => 'Delete agent', 'details' => '']
        );

        (new telegram_confirmation_ui())->show('5001', $card);

        $sent = $this->telegram->sent[0];
        $this->assertSame(
            get_string('telegram_confirm_action', 'local_jedomi', '<b>Delete agent</b>') . "\n\n"
                . get_string('telegram_confirm_keyword', 'local_jedomi', s($this->keyword())),
            $sent['text']
        );
        $this->assertSame(['inline_keyboard' => [[
            ['text' => get_string('confirm_btn_no', 'local_jedomi'), 'callback_data' => 'cancel:abc123'],
        ]]], $sent['replymarkup']);
    }

    /**
     * An action confirmed with the PIN asks for it, not for the word, and has only the cancel button.
     */
    public function test_pin_card_asks_for_the_pin(): void {
        $card = new confirmation_card(
            'abc123',
            'delete_agent',
            pending_action_service::SEVERITY_CRITICAL,
            true,
            ['description' => 'Delete agent', 'details' => 'Id: 4']
        );

        (new telegram_confirmation_ui())->show('5001', $card);

        $sent = $this->telegram->sent[0];
        $this->assertSame(
            get_string('telegram_confirm_action', 'local_jedomi', "<b>Delete agent</b>\nId: 4") . "\n\n"
                . get_string('telegram_pin_prompt', 'local_jedomi'),
            $sent['text']
        );
        $this->assertCount(1, $sent['replymarkup']['inline_keyboard'][0]);
    }

    /**
     * Sending messages shows the number of recipients and the message in italics instead of the description.
     */
    public function test_send_messages_card_shows_recipients_and_message(): void {
        $card = new confirmation_card(
            'm1',
            'send_messages',
            pending_action_service::SEVERITY_HIGH,
            false,
            ['description' => 'Send', 'details' => '', 'count' => 3, 'preview' => 'Hi <all>']
        );

        (new telegram_confirmation_ui())->show('5001', $card);

        $bulk = s(str_replace('{count}', '3', get_string('confirm_send_bulk', 'local_jedomi')));
        $this->assertSame(
            get_string('telegram_confirm_action', 'local_jedomi', "<b>{$bulk}</b>\n<i>Hi &lt;all&gt;</i>"),
            $this->telegram->sent[0]['text']
        );
        $this->assertCount(2, $this->telegram->sent[0]['replymarkup']['inline_keyboard'][0]);
    }

    /**
     * Button data is read as a confirmation or a cancellation of an action; any other data is not an answer.
     */
    public function test_parse_reply_reads_the_buttons(): void {
        $ui = new telegram_confirmation_ui();

        $confirm = $ui->parse_reply('confirm:abc123');
        $this->assertSame([confirmation_service::MODE_BUTTON, 'abc123'], [$confirm->mode, $confirm->actionid]);
        $cancel = $ui->parse_reply('cancel:xyz');
        $this->assertSame([confirmation_service::MODE_CANCEL, 'xyz'], [$cancel->mode, $cancel->actionid]);
        $this->assertNull($ui->parse_reply('cmd:new'));
        $this->assertNull($ui->parse_reply('other:abc'));
        $this->assertNull($ui->parse_reply(''));
    }

    /**
     * Prompts, the plain notice and the data length of a Telegram card.
     */
    public function test_prompts_notice_and_length(): void {
        $ui = new telegram_confirmation_ui();

        $this->assertSame(get_string('telegram_confirm_keyword', 'local_jedomi', s($this->keyword())), $ui->keyword_prompt());
        $this->assertSame(get_string('telegram_pin_prompt', 'local_jedomi'), $ui->pin_prompt());
        $this->assertSame(pending_action_service::TELEGRAM_DETAILS_LENGTH, $ui->details_length());

        $ui->notify('5001', 'Plain notice');
        $this->assertSame([['chatid' => '5001', 'text' => 'Plain notice', 'replymarkup' => null]], $this->telegram->sent);
    }

    /**
     * The Telegram confirmation service holds actions in the Telegram table with this UI.
     */
    public function test_manager_builds_the_telegram_confirmation_service(): void {
        $service = manager::confirmation_service();

        $this->assertInstanceOf(confirmation_service::class, $service);
        $this->assertInstanceOf(telegram_confirmation_ui::class, $service->ui());
        $this->assertSame('local_jedomi_telegram_pending_actions', $service->table());
    }
}
