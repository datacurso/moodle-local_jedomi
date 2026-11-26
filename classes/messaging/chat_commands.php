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
 * Commands and link port of a messaging channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * What the router asks a channel (Telegram, WhatsApp) at the steps whose words, buttons or records belong to the
 * channel: the help, the link of a chat and its codes, unlinking, the PIN and a new conversation. The order of the
 * steps belongs to the router.
 */
interface chat_commands {
    /**
     * Whether a text asks for the help.
     *
     * @param string $text Trimmed text of the message.
     * @return bool
     */
    public function is_help(string $text): bool;

    /**
     * Send the help to a chat.
     *
     * @param string $chatkey Chat key of the channel.
     * @return void
     */
    public function help(string $chatkey): void;

    /**
     * Confirmed link of a chat.
     *
     * @param string $chatkey Chat key of the channel.
     * @return \stdClass|null The link record, with its userid, or null when the chat is not linked.
     */
    public function find_link(string $chatkey): ?\stdClass;

    /**
     * Remove a link unused for too long, with the data of its chat, or record that it is used now.
     *
     * @param \stdClass $link Link of the chat.
     * @return bool True when the link expired and was removed.
     */
    public function expire_or_touch(\stdClass $link): bool;

    /**
     * Tell a chat that its link expired and it must be linked again.
     *
     * @param string $chatkey Chat key of the channel.
     * @return void
     */
    public function link_expired(string $chatkey): void;

    /**
     * Ask a chat that is not linked to link the account first.
     *
     * @param string $chatkey Chat key of the channel.
     * @return void
     */
    public function link_first(string $chatkey): void;

    /**
     * Whether a text is a link command with a code, which a linked chat or one whose link expired may send.
     *
     * @param string $text Trimmed text of the message.
     * @return bool
     */
    public function is_link_command(string $text): bool;

    /**
     * Link request a message of a chat that is not linked stands for: a link command, with or without its code, or a
     * bare link code.
     *
     * @param string $text Trimmed text of the message.
     * @return string|null The link request for link(), or null when the message is not one.
     */
    public function link_request(string $text): ?string;

    /**
     * Link the chat of a message with the code of a link request, and tell the user how it went.
     *
     * @param inbound_message $message Message that sent the request.
     * @param string $request Link request (see is_link_command() and link_request()).
     * @return void
     */
    public function link(inbound_message $message, string $request): void;

    /**
     * Whether a text asks to unlink the chat.
     *
     * @param string $text Trimmed text of the message.
     * @return bool
     */
    public function is_unlink(string $text): bool;

    /**
     * Unlink a chat, removing the data of its conversation, and tell the user.
     *
     * @param string $chatkey Chat key of the channel.
     * @param \stdClass $link Link of the chat.
     * @return void
     */
    public function unlink(string $chatkey, \stdClass $link): void;

    /**
     * Whether the user confirms the actions that change data with a PIN in this channel.
     *
     * @param int $userid Linked user.
     * @return bool
     */
    public function pin_required(int $userid): bool;

    /**
     * Whether a text has the format of a PIN.
     *
     * @param string $text Trimmed text of the message.
     * @return bool
     */
    public function is_pin(string $text): bool;

    /**
     * Whether the user typed the right PIN recently enough that actions are confirmed as without a PIN.
     *
     * @param int $userid Linked user.
     * @return bool
     */
    public function pin_window_open(int $userid): bool;

    /**
     * Remove a message with a PIN from the chat, where the channel allows it. The PIN is never logged.
     *
     * @param inbound_message $message Message with the PIN.
     * @return void
     */
    public function hide_pin(inbound_message $message): void;

    /**
     * Whether a text asks for a new conversation.
     *
     * @param string $text Trimmed text of the message.
     * @return bool
     */
    public function is_new_conversation(string $text): bool;

    /**
     * Start a new conversation in a chat: its history, context and task in progress are reset.
     *
     * @param string $chatkey Chat key of the channel.
     * @param int $userid Linked user.
     * @return string Greeting the AI answers to start the new conversation.
     */
    public function new_conversation(string $chatkey, int $userid): string;
}
