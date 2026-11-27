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
 * Telegram confirmation UI: HTML card and inline keyboard of a held action.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\local\service\pending_action_service;
use local_jedomi\messaging\confirmation_card;
use local_jedomi\messaging\confirmation_reply;
use local_jedomi\messaging\confirmation_service;
use local_jedomi\messaging\confirmation_ui;

/**
 * Held actions in Telegram: an HTML message with the same readable description and data as the web card, and inline
 * buttons whose callback data is confirm:<id> or cancel:<id>.
 */
class telegram_confirmation_ui implements confirmation_ui {
    /** Prefix of the callback data of the confirm button of a held action. */
    public const CALLBACK_CONFIRM = 'confirm:';

    /** Prefix of the callback data of the cancel button of a held action. */
    public const CALLBACK_CANCEL = 'cancel:';

    #[\Override]
    public function details_length(): int {
        return pending_action_service::TELEGRAM_DETAILS_LENGTH;
    }

    #[\Override]
    public function show(string $chatkey, confirmation_card $card): void {
        api_client::instance()->send_message(
            $chatkey,
            $this->text($card),
            ['inline_keyboard' => [$this->buttons($card)]]
        );
    }

    #[\Override]
    public function notify(string $chatkey, string $message): void {
        api_client::instance()->send_message($chatkey, $message);
    }

    #[\Override]
    public function parse_reply(string $payload): ?confirmation_reply {
        if (strpos($payload, self::CALLBACK_CONFIRM) === 0) {
            return new confirmation_reply(confirmation_service::MODE_BUTTON, substr($payload, strlen(self::CALLBACK_CONFIRM)));
        }
        if (strpos($payload, self::CALLBACK_CANCEL) === 0) {
            return new confirmation_reply(confirmation_service::MODE_CANCEL, substr($payload, strlen(self::CALLBACK_CANCEL)));
        }
        return null;
    }

    #[\Override]
    public function keyword_prompt(): string {
        return get_string('telegram_confirm_keyword', 'local_jedomi', s(self::keyword()));
    }

    #[\Override]
    public function pin_prompt(): string {
        return get_string('telegram_pin_prompt', 'local_jedomi');
    }

    /**
     * Confirmation message of a held action, with the same readable description and data as the web card; a critical
     * action asks for the confirmation word, as the web chat does, and an action confirmed with the PIN asks for it.
     *
     * @param confirmation_card $card Card of the action.
     * @return string HTML text for Telegram.
     */
    private function text(confirmation_card $card): string {
        $data = $card->card;
        $text = '<b>' . s($data['description']) . '</b>';
        if ($card->tool === 'send_messages') {
            $text = '<b>' . s(str_replace('{count}', (string) $data['count'], get_string('confirm_send_bulk', 'local_jedomi')))
                . '</b>';
            if ($data['preview'] !== '') {
                $text .= "\n<i>" . s($data['preview']) . '</i>';
            }
        }
        if ($data['details'] !== '') {
            $text .= "\n" . s($data['details']);
        }
        $message = get_string('telegram_confirm_action', 'local_jedomi', $text);
        if ($card->askpin) {
            $message .= "\n\n" . $this->pin_prompt();
        } else if ($card->is_critical()) {
            $message .= "\n\n" . $this->keyword_prompt();
        }
        return $message;
    }

    /**
     * Buttons of a held action: a critical action is confirmed only with the confirmation word and an action confirmed
     * with the PIN only with it, so both have only the cancel button.
     *
     * @param confirmation_card $card Card of the action.
     * @return array[] One row of inline keyboard buttons.
     */
    private function buttons(confirmation_card $card): array {
        $cancel = ['text' => get_string('confirm_btn_no', 'local_jedomi'),
            'callback_data' => self::CALLBACK_CANCEL . $card->actionid];
        if ($card->askpin || $card->is_critical()) {
            return [$cancel];
        }
        return [
            ['text' => get_string('confirm_btn_yes', 'local_jedomi'), 'callback_data' => self::CALLBACK_CONFIRM . $card->actionid],
            $cancel,
        ];
    }

    /**
     * Confirmation word of critical actions: the confirm button label in capitals, as in the web chat.
     *
     * @return string
     */
    private static function keyword(): string {
        return \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi'));
    }
}
