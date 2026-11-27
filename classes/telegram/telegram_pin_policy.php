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
 * Telegram PIN policy.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\local\service\telegram_pin_service;
use local_jedomi\messaging\inbound_message;
use local_jedomi\messaging\pin_policy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * The Telegram PIN of administrators and managers (telegram_pin_service), kept with the Telegram link; its texts, the
 * link to the Telegram page of the profile where it is set, and the deletion of the message with the PIN (RISK-001).
 */
final class telegram_pin_policy implements pin_policy {
    #[\Override]
    public function available(): bool {
        return true;
    }

    #[\Override]
    public function is_required(int $userid): bool {
        return telegram_pin_service::is_required($userid);
    }

    #[\Override]
    public function is_pin(string $text): bool {
        return telegram_pin_service::is_valid_format($text);
    }

    #[\Override]
    public function in_window(int $userid): bool {
        return telegram_pin_service::in_window($userid);
    }

    #[\Override]
    public function has_pin(int $userid): bool {
        return telegram_pin_service::has_pin($userid);
    }

    #[\Override]
    public function locked_until(int $userid): int {
        return telegram_pin_service::locked_until($userid);
    }

    #[\Override]
    public function verify(int $userid, string $pin): array {
        $check = telegram_pin_service::verify($userid, $pin);
        $result = match ($check['result']) {
            telegram_pin_service::RESULT_OK => self::RESULT_OK,
            telegram_pin_service::RESULT_WRONG => self::RESULT_WRONG,
            telegram_pin_service::RESULT_LOCKED => self::RESULT_LOCKED,
            default => self::RESULT_NO_PIN,
        };
        return ['result' => $result, 'remaining' => (int) $check['remaining']];
    }

    /**
     * Delete the message with the PIN from the chat (RISK-001).
     *
     * @param inbound_message $message Message with the PIN.
     * @return void
     */
    #[\Override]
    public function hide(inbound_message $message): void {
        $messageid = (int) $message->messageid;
        if ($messageid > 0 && !api_client::instance()->delete_message($message->chatkey, $messageid)) {
            // The PIN is still checked: the user is not blocked by a message Telegram cannot delete.
            \local_jedomi_log('Telegram: the PIN message could not be deleted chat='
                . \local_jedomi_log_pseudonym('chat', $message->chatkey));
        }
    }

    #[\Override]
    public function missing_message(): string {
        $url = (new \moodle_url('/local/jedomi/telegram_profile.php'))->out(false);
        return get_string('telegram_pin_missing', 'local_jedomi', $url);
    }

    #[\Override]
    public function locked_message(int $lockeduntil): string {
        return get_string('telegram_pin_locked', 'local_jedomi', max(1, (int) ceil(($lockeduntil - time()) / MINSECS)));
    }

    #[\Override]
    public function wrong_message(int $remaining): string {
        return get_string('telegram_pin_wrong', 'local_jedomi', $remaining);
    }
}
