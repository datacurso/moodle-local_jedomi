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
 * PIN policy of a channel that cannot ask for a PIN.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * A channel without PIN (e.g. WhatsApp v1). The rule of who needs a PIN is still the rule of the user, given by the
 * channel when it is built (it is required, so a channel cannot forget it); with it the confirmation service refuses
 * the writes of a user who needs a PIN before holding them (reason pin_unsupported) and never runs them. No text is
 * ever a PIN here, no PIN is ever confirmed, and there are no PIN texts: asking for one is a coding error.
 *
 * The rule of who needs a PIN still lives in \local_jedomi\local\service\telegram_pin_service::is_required(), with
 * the site setting telegram_require_pin (both names are historical: the rule is the same for every channel). A channel
 * must pass exactly that rule as $required, so a user who needs a PIN on Telegram cannot write without one elsewhere.
 */
final class no_pin_policy implements pin_policy {
    /**
     * Constructor.
     *
     * @param \Closure $required Whether the writes of a user need a PIN: fn(int $userid): bool.
     */
    public function __construct(
        /** @var \Closure Whether the writes of a user need a PIN. */
        private readonly \Closure $required,
    ) {
    }

    #[\Override]
    public function available(): bool {
        return false;
    }

    #[\Override]
    public function is_required(int $userid): bool {
        return (bool) ($this->required)($userid);
    }

    #[\Override]
    public function is_pin(string $text): bool {
        return false;
    }

    #[\Override]
    public function in_window(int $userid): bool {
        return false;
    }

    #[\Override]
    public function has_pin(int $userid): bool {
        return false;
    }

    #[\Override]
    public function locked_until(int $userid): int {
        return 0;
    }

    #[\Override]
    public function verify(int $userid, string $pin): array {
        return ['result' => self::RESULT_NO_PIN, 'remaining' => 0];
    }

    #[\Override]
    public function hide(inbound_message $message): void {
    }

    #[\Override]
    public function missing_message(): string {
        throw new \coding_exception('This channel has no PIN');
    }

    #[\Override]
    public function locked_message(int $lockeduntil): string {
        throw new \coding_exception('This channel has no PIN');
    }

    #[\Override]
    public function wrong_message(int $remaining): string {
        throw new \coding_exception('This channel has no PIN');
    }
}
