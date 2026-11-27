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
 * Test double for the transport of a messaging channel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Transport that records what is sent instead of reaching any channel. Answers keep their text as is.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_transport implements transport {
    /** @var array<int, array{kind: string, chatkey: string, text: string}> Texts and answers sent, in order. */
    public array $sent = [];

    /** @var array<int, array{chatkey: string, timeout: ?int}> Activity statuses sent, in order. */
    public array $activities = [];

    /** @var bool|null Whether every formatted answer is blank; null to judge it as HTML, as Telegram does. */
    public ?bool $blank = null;

    /** @var string[] Formatted answers the engine asked about, in order. */
    public array $blankchecks = [];

    #[\Override]
    public function send_text(string $chatkey, string $text): bool {
        $this->sent[] = ['kind' => 'text', 'chatkey' => $chatkey, 'text' => $text];
        return true;
    }

    #[\Override]
    public function send_activity(string $chatkey, ?int $timeout = null): bool {
        $this->activities[] = ['chatkey' => $chatkey, 'timeout' => $timeout];
        return true;
    }

    #[\Override]
    public function format_answer(string $markdown): string {
        return $markdown;
    }

    #[\Override]
    public function is_blank(string $formatted): bool {
        $this->blankchecks[] = $formatted;
        return $this->blank ?? trim(strip_tags($formatted)) === '';
    }

    #[\Override]
    public function send_answer(string $chatkey, string $formatted): bool {
        $this->sent[] = ['kind' => 'answer', 'chatkey' => $chatkey, 'text' => $formatted];
        return true;
    }

    /**
     * Texts and answers sent, in order.
     *
     * @return string[]
     */
    public function texts(): array {
        return array_column($this->sent, 'text');
    }
}
