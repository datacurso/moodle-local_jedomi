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
 * Test double for the commands of a channel that is not Telegram.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Commands of a channel without slash commands nor link codes: the links come from the channel, "help" asks for the
 * help, and each step records what it was asked for.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class neutral_chat_commands implements chat_commands {
    /** @var string[] Steps run, as "step:chatkey", in order. */
    public array $calls = [];

    /**
     * Constructor.
     *
     * @param \Closure $linkeduser User linked to a chat key: fn(string $chatkey): ?int.
     */
    public function __construct(
        /** @var \Closure User linked to a chat key. */
        private readonly \Closure $linkeduser,
    ) {
    }

    #[\Override]
    public function is_help(string $text): bool {
        return $text === 'help';
    }

    #[\Override]
    public function help(string $chatkey): void {
        $this->calls[] = 'help:' . $chatkey;
    }

    #[\Override]
    public function find_link(string $chatkey): ?\stdClass {
        $userid = ($this->linkeduser)($chatkey);
        return $userid === null ? null : (object) ['userid' => $userid];
    }

    #[\Override]
    public function expire_or_touch(\stdClass $link): bool {
        return false;
    }

    #[\Override]
    public function link_expired(string $chatkey): void {
        $this->calls[] = 'link_expired:' . $chatkey;
    }

    #[\Override]
    public function link_first(string $chatkey): void {
        $this->calls[] = 'link_first:' . $chatkey;
    }

    #[\Override]
    public function is_link_command(string $text): bool {
        return false;
    }

    #[\Override]
    public function link_request(string $text): ?string {
        return null;
    }

    #[\Override]
    public function link(inbound_message $message, string $request): void {
        $this->calls[] = 'link:' . $message->chatkey;
    }

    #[\Override]
    public function is_unlink(string $text): bool {
        return false;
    }

    #[\Override]
    public function unlink(string $chatkey, \stdClass $link): void {
        $this->calls[] = 'unlink:' . $chatkey;
    }

    #[\Override]
    public function is_new_conversation(string $text): bool {
        return false;
    }

    #[\Override]
    public function new_conversation(string $chatkey, int $userid): string {
        $this->calls[] = 'new_conversation:' . $chatkey;
        return 'hello';
    }
}
