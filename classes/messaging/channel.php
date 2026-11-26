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
 * Messaging channel aggregate.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * The pieces of a messaging channel (Telegram, WhatsApp) the router and the conversation engine run a chat with: where
 * the conversation is kept, who may be served, the message limit, the commands and the link, the tools and
 * confirmations, how it sends, downloads media and continues, and its texts.
 */
interface channel {
    /** @var string Message key: another worker still answers the previous message of the chat. */
    public const MESSAGE_STILL_PROCESSING = 'still_processing';

    /** @var string Message key: the answer takes a while, the assistant is still working. */
    public const MESSAGE_KEEPALIVE = 'keepalive';

    /** @var string Message key: the answer was done but nothing of its text could be shown. */
    public const MESSAGE_DONE = 'done';

    /** @var string Message key: the state of the loop could not be saved. */
    public const MESSAGE_STATE_SAVE_FAILED = 'state_save_failed';

    /** @var string Message key: the loop reached its last round, or kept calling the same tool. */
    public const MESSAGE_MAX_ITERATIONS = 'max_iterations';

    /** @var string Message key: the AI answered without text nor tool calls. */
    public const MESSAGE_NO_ANSWER = 'no_answer';

    /** @var string Message key: the message limit could not be checked, the message must be sent again. */
    public const MESSAGE_RATE_CHECK_FAILED = 'rate_check_failed';

    /** @var string Message key: the chat reached the message limit; its parameter is ['minutes' => waiting time]. */
    public const MESSAGE_RATE_LIMITED = 'rate_limited';

    /** @var string Message key: the bot only serves private chats. */
    public const MESSAGE_PRIVATE_ONLY = 'private_only';

    /** @var string Message key: the linked account is no longer active or cannot log in. */
    public const MESSAGE_ACCOUNT_INACTIVE = 'account_inactive';

    /** @var string Message key: the role of the linked user may not use the channel. */
    public const MESSAGE_ROLE_BLOCKED = 'role_blocked';

    /** @var string Message key: audio processing is turned off. */
    public const MESSAGE_AUDIO_DISABLED = 'audio_disabled';

    /** @var string Message key: what was understood of an audio; its parameter is the transcription. */
    public const MESSAGE_HEARD = 'heard';

    /** @var string Message key: file processing is turned off. */
    public const MESSAGE_FILES_DISABLED = 'files_disabled';

    /** @var string Message key: the file cannot be read. */
    public const MESSAGE_FILE_UNSUPPORTED = 'file_unsupported';

    /** @var string Message key: the message has no text nor an attachment that can be read. */
    public const MESSAGE_TEXT_ONLY = 'text_only';

    /**
     * Name of the channel, the prefix of its settings and the channel of its events, e.g. telegram.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Name of the channel for people and developer messages, e.g. Telegram.
     *
     * @return string
     */
    public function label(): string;

    /**
     * Conversation history and tool loop state of the chats of the channel.
     *
     * @return conversation_store
     */
    public function store(): conversation_store;

    /**
     * Access rules of the channel: integration enabled, active user, allowed role.
     *
     * @return access_guard
     */
    public function access_guard(): access_guard;

    /**
     * Message limit of the chats of the channel.
     *
     * @return rate_limiter
     */
    public function rate_limiter(): rate_limiter;

    /**
     * Commands, link and replies of the channel that the router runs.
     *
     * @return chat_commands
     */
    public function commands(): chat_commands;

    /**
     * Tools of the channel: signed permissions, user context and execution.
     *
     * @return tool_runner
     */
    public function tool_runner(): tool_runner;

    /**
     * Confirmation of the tool calls that change data.
     *
     * @return confirmation_service
     */
    public function confirmation_service(): confirmation_service;

    /**
     * How the channel sends to a chat.
     *
     * @return transport
     */
    public function transport(): transport;

    /**
     * How the channel downloads the attachments of a message.
     *
     * @return media_source
     */
    public function media_source(): media_source;

    /**
     * How the channel hands a long tool loop to the next worker.
     *
     * @return continuation
     */
    public function continuation(): continuation;

    /**
     * Limits and format of the channel, as the AI must know them.
     *
     * @return channel_limits
     */
    public function limits(): channel_limits;

    /**
     * Total timeout of each AI call, in seconds, from the settings of the channel.
     *
     * @return int
     */
    public function ai_timeout(): int;

    /**
     * Seconds between two activity statuses while an AI call waits.
     *
     * @return int
     */
    public function activity_interval(): int;

    /**
     * Seconds an activity status may take while the AI answers.
     *
     * @return int
     */
    public function activity_timeout(): int;

    /**
     * Text of the channel for a situation of the router or the loop, in the current language.
     *
     * @param string $key One of the MESSAGE_* constants.
     * @param mixed $a Parameter of the text, as get_string() takes it; null for none.
     * @return string
     * @throws \coding_exception For an unknown key.
     */
    public function message(string $key, mixed $a = null): string;

    /**
     * User of the confirmed link of a chat, or null when the chat is not linked.
     *
     * @param string $chatkey Chat key of the channel.
     * @return int|null
     */
    public function linked_userid(string $chatkey): ?int;

    /**
     * Whether a request in progress may go on: the channel is enabled and the chat is still linked to this user, who
     * may still be served.
     *
     * @param string $chatkey Chat key of the channel.
     * @param int $userid User the request runs for.
     * @return bool
     */
    public function serves(string $chatkey, int $userid): bool;
}
