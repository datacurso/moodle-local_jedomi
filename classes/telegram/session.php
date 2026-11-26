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
 * Telegram session store — persists conversational context per chat_id.
 *
 * Messages are stored as a JSON array (max turns configured by admin, default 10).
 * TTL enforced by cleanup task: rows with lastactivity < now - 30min are deleted.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\messaging\conversation_store;

/**
 * Persists conversational context per Telegram chat id as a JSON message history.
 *
 * The persistence lives in the channel-neutral {@see conversation_store} over local_jedomi_telegram_sessions.
 */
class session {
    /**
     * Placeholder system message of a new Telegram conversation.
     *
     * webhook_handler always replaces it with {@see prompt_builder::build()} before any AI call, so the rules the AI
     * gets (untrusted data, no disclosure, language...) live only there and are never copied here (READ-001).
     */
    public const ANTI_HALLUCINATION_PROMPT = 'You are Jedomi, an educational assistant built into Moodle LMS. '
        . "Follow these rules:\n"
        . "- Do not make up data.\n"
        . "- When you have enough data, answer and do NOT keep calling functions.\n"
        . "- If a function fails, try once more. If it fails again, say so and stop.\n"
        . "- Respect permissions and privacy.\n"
        . "- Answer in the language of the user; in Spanish, address the user formally (usted).\n"
        . "- If you cannot do something, say why and suggest the best next step.";

    /** @var string Session table of the Telegram channel. */
    private const TABLE = 'local_jedomi_telegram_sessions';

    /** @var string local_jedomi setting with the max turns kept in a Telegram conversation. */
    private const MAX_TURNS_SETTING = 'telegram_max_turns';

    /**
     * Conversation store of the Telegram channel.
     *
     * @return conversation_store
     */
    public static function store(): conversation_store {
        return new conversation_store(self::TABLE, self::MAX_TURNS_SETTING);
    }

    /**
     * Load session messages for a chat_id.
     *
     * @param string $chatid
     * @return array Array of message arrays ['role' => ..., 'content' => ...]
     */
    public static function get(string $chatid): array {
        return self::store()->get($chatid);
    }

    /**
     * Append a message to the session and trim to configured max_turns.
     *
     * @param string $chatid
     * @param string $role     'user' | 'assistant' | 'system'
     * @param string $content
     */
    public static function append(string $chatid, string $role, string $content): void {
        self::store()->append($chatid, $role, $content);
    }

    /**
     * Load cached user context for a chat_id.
     *
     * @param string $chatid
     * @return array|null Decoded context or null if not cached
     */
    public static function get_context(string $chatid): ?array {
        return self::store()->get_context($chatid);
    }

    /**
     * Store user context in the session cache for a chat_id.
     *
     * Stale writes (an iteration not newer than the saved one) are skipped; see conversation_store::set_context().
     *
     * @param string $chatid
     * @param array $context
     */
    public static function set_context(string $chatid, array $context): void {
        self::store()->set_context($chatid, $context);
    }

    /**
     * Remove cached user context for a chat_id.
     *
     * @param string $chatid
     */
    public static function clear_context(string $chatid): void {
        self::store()->clear_context($chatid);
    }

    /**
     * Clear the session for a chat_id.
     *
     * @param string $chatid
     */
    public static function clear(string $chatid): void {
        self::store()->clear($chatid);
    }

    /**
     * Get the full messages array formatted for the Gateway API
     * (system instruction + context + current user message).
     *
     * @param string $chatid
     * @param string $currentmessage
     * @return array
     */
    public static function build_messages_for_gateway(string $chatid, string $currentmessage): array {
        $history = self::get($chatid);
        $messages = [['role' => 'system', 'content' => self::ANTI_HALLUCINATION_PROMPT]];

        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $currentmessage];

        return $messages;
    }
}
