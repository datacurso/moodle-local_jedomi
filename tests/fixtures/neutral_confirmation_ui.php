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
 * Test double for the confirmation UI of a channel that is not Telegram.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Confirmation UI that records the cards and notices instead of sending them; its prompts are fixed words.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class neutral_confirmation_ui implements confirmation_ui {
    /** @var array[] Cards shown, as [chatkey, card]. */
    public array $shown = [];

    /** @var array[] Notices sent, as [chatkey, message]. */
    public array $notified = [];

    #[\Override]
    public function details_length(): int {
        return 3000;
    }

    #[\Override]
    public function show(string $chatkey, confirmation_card $card): void {
        $this->shown[] = [$chatkey, $card];
    }

    #[\Override]
    public function notify(string $chatkey, string $message): void {
        $this->notified[] = [$chatkey, $message];
    }

    #[\Override]
    public function parse_reply(string $payload): ?confirmation_reply {
        return null;
    }

    #[\Override]
    public function keyword_prompt(): string {
        return 'keyword prompt';
    }

    #[\Override]
    public function pin_prompt(): string {
        return 'pin prompt';
    }
}
