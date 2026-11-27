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
 * PIN port of a messaging channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * The PIN with which an administrator or manager confirms the actions that change data, as a channel (Telegram,
 * WhatsApp) offers it to the confirmation service and the router.
 *
 * Whether a user needs a PIN depends on the user (role and settings), never on the channel: is_required() gives that
 * rule in every channel. available() tells whether this channel can ask for the PIN. A channel that cannot (see
 * no_pin_policy) never reads a text as a PIN, never confirms one, and its writes of a user who needs a PIN are refused
 * before they are held (fail closed). The texts are only asked for where available() is true.
 */
interface pin_policy {
    /** @var string The PIN is right: the window of confirmations without the PIN opens. */
    public const RESULT_OK = 'ok';

    /** @var string The PIN is wrong; the attempts left come with it. */
    public const RESULT_WRONG = 'wrong';

    /** @var string The writes are locked after too many wrong PINs. */
    public const RESULT_LOCKED = 'locked';

    /** @var string The user has no PIN to check. */
    public const RESULT_NO_PIN = 'nopin';

    /**
     * Whether this channel can ask for the PIN and check it.
     *
     * @return bool
     */
    public function available(): bool;

    /**
     * Whether the actions of the user that change data need a PIN. The same rule in every channel.
     *
     * @param int $userid Moodle user.
     * @return bool
     */
    public function is_required(int $userid): bool;

    /**
     * Whether a text has the format of a PIN. Always false where the PIN is not available.
     *
     * @param string $text Trimmed text of the message.
     * @return bool
     */
    public function is_pin(string $text): bool;

    /**
     * Whether the user typed the right PIN recently enough that actions are confirmed as without a PIN.
     *
     * @param int $userid Moodle user.
     * @return bool
     */
    public function in_window(int $userid): bool;

    /**
     * Whether the user has set a PIN.
     *
     * @param int $userid Moodle user.
     * @return bool
     */
    public function has_pin(int $userid): bool;

    /**
     * Time the lock of the writes after too many wrong PINs ends, or 0 when they are not locked.
     *
     * @param int $userid Moodle user.
     * @return int
     */
    public function locked_until(int $userid): int;

    /**
     * Check a typed PIN. The PIN is never logged.
     *
     * @param int $userid Moodle user.
     * @param string $pin Typed PIN.
     * @return array{result: string, remaining: int} One of the RESULT_* constants and the attempts left.
     */
    public function verify(int $userid, string $pin): array;

    /**
     * Remove a message with a PIN from the chat, where the channel allows it. The PIN is never logged.
     *
     * @param inbound_message $message Message with the PIN.
     * @return void
     */
    public function hide(inbound_message $message): void;

    /**
     * Message for a user who needs a PIN and has not set one, with where to set it.
     *
     * @return string
     */
    public function missing_message(): string;

    /**
     * Message for writes locked until a time, with the minutes left.
     *
     * @param int $lockeduntil Time the lock ends.
     * @return string
     */
    public function locked_message(int $lockeduntil): string;

    /**
     * Message for a wrong PIN, with the attempts left.
     *
     * @param int $remaining Attempts left before the lock.
     * @return string
     */
    public function wrong_message(int $remaining): string;
}
