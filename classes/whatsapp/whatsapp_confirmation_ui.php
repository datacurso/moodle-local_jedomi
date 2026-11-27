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

namespace local_jedomi\whatsapp;

use local_jedomi\messaging\confirmation_card;
use local_jedomi\messaging\confirmation_reply;
use local_jedomi\messaging\confirmation_service;
use local_jedomi\messaging\confirmation_ui;
use local_jedomi\messaging\transport;

/**
 * Confirmation UI of the WhatsApp channel, text only: the card of a held action is a text message that asks to reply
 * SÍ or NO, or the confirmation word for a critical action. The data of the action is escaped, so it cannot format
 * the message.
 *
 * The button payloads confirm:<id> and cancel:<id> are read already, for the interactive reply buttons.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_confirmation_ui implements confirmation_ui {
    /** @var string Prefix of the payload of the confirm button of a held action. */
    public const PAYLOAD_CONFIRM = 'confirm:';

    /** @var string Prefix of the payload of the cancel button of a held action. */
    public const PAYLOAD_CANCEL = 'cancel:';

    /** @var string[] Replies that confirm a held action, as the confirm button does (upper case). */
    private const YES = ['SÍ', 'SI', 'YES'];

    /** @var string[] Replies that cancel a held action (upper case), besides the cancel button label. */
    private const NO = ['NO', 'CANCELAR', 'CANCEL'];

    /** @var transport Transport of the messages. */
    private transport $transport;

    /**
     * UI that sends through a transport.
     *
     * @param transport|null $transport Transport; the WhatsApp one when null.
     */
    public function __construct(?transport $transport = null) {
        $this->transport = $transport ?? new whatsapp_transport();
    }

    #[\Override]
    public function details_length(): int {
        return whatsapp_limits::DETAILS_LENGTH;
    }

    #[\Override]
    public function show(string $chatkey, confirmation_card $card): void {
        $this->transport->send_text($chatkey, $this->text($card));
    }

    #[\Override]
    public function notify(string $chatkey, string $message): void {
        $this->transport->send_text($chatkey, $message);
    }

    #[\Override]
    public function parse_reply(string $payload): ?confirmation_reply {
        if (str_starts_with($payload, self::PAYLOAD_CONFIRM)) {
            return new confirmation_reply(confirmation_service::MODE_BUTTON, substr($payload, strlen(self::PAYLOAD_CONFIRM)));
        }
        if (str_starts_with($payload, self::PAYLOAD_CANCEL)) {
            return new confirmation_reply(confirmation_service::MODE_CANCEL, substr($payload, strlen(self::PAYLOAD_CANCEL)));
        }
        return null;
    }

    /**
     * How a text answers a held action: SÍ (also SI and YES) as the confirm button, NO (also CANCELAR, CANCEL and the
     * cancel button label) as the cancel button.
     *
     * @param string $text Text of the student.
     * @return string|null confirmation_service::MODE_BUTTON, confirmation_service::MODE_CANCEL, or null for any other
     *     text.
     */
    public function text_reply_mode(string $text): ?string {
        $word = \core_text::strtoupper(trim($text));
        if ($word === '') {
            return null;
        }
        if (in_array($word, self::YES, true)) {
            return confirmation_service::MODE_BUTTON;
        }
        $no = array_merge(self::NO, [\core_text::strtoupper(get_string('confirm_btn_no', 'local_jedomi'))]);
        return in_array($word, $no, true) ? confirmation_service::MODE_CANCEL : null;
    }

    #[\Override]
    public function keyword_prompt(): string {
        return get_string('whatsapp_confirm_keyword', 'local_jedomi', self::keyword());
    }

    #[\Override]
    public function pin_prompt(): string {
        return get_string('whatsapp_pin_unavailable', 'local_jedomi');
    }

    /**
     * Message of a held action: its description and data, as the web card shows them, and how to answer it.
     *
     * @param confirmation_card $card Card of the action.
     * @return string WhatsApp text.
     */
    private function text(confirmation_card $card): string {
        $data = $card->card;
        $text = '*' . formatter::escape((string) $data['description']) . '*';
        if ($card->tool === 'send_messages') {
            $text = '*' . formatter::escape(str_replace(
                '{count}',
                (string) $data['count'],
                get_string('confirm_send_bulk', 'local_jedomi')
            )) . '*';
            if ((string) $data['preview'] !== '') {
                $text .= "\n_" . formatter::escape((string) $data['preview']) . '_';
            }
        }
        if ((string) $data['details'] !== '') {
            $text .= "\n" . formatter::escape((string) $data['details']);
        }
        $message = get_string('whatsapp_confirm_action', 'local_jedomi', $text);
        if ($card->askpin) {
            return $message . "\n\n" . $this->pin_prompt();
        }
        if ($card->is_critical()) {
            return $message . "\n\n" . $this->keyword_prompt();
        }
        return $message . "\n\n" . get_string('whatsapp_confirm_reply', 'local_jedomi');
    }

    /**
     * Confirmation word of critical actions: the confirm button label in capitals, as in the web chat and Telegram.
     *
     * @return string
     */
    private static function keyword(): string {
        return \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi'));
    }
}
