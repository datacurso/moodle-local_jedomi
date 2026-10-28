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

/**
 * Test double for the Telegram Bot API client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

/**
 * Telegram client that records sent messages instead of calling the Bot API.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_telegram_api_client extends api_client {
    /** @var array<int, array{chatid: int|string, text: string}> Messages sent, in order. */
    public array $sent = [];

    /**
     * Use a dummy token so no real bot configuration is needed.
     */
    public function __construct() {
        parent::__construct('test-token');
    }

    #[\Override]
    public function send_message(int|string $chatid, string $text, ?array $replymarkup = null, bool $truncate = false): bool {
        $this->sent[] = ['chatid' => $chatid, 'text' => $text];
        return true;
    }
}
