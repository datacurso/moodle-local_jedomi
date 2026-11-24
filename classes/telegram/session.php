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

/**
 * Persists conversational context per Telegram chat id as a JSON message history.
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

    /**
     * Load session messages for a chat_id.
     *
     * @param string $chatid
     * @return array Array of message arrays ['role' => ..., 'content' => ...]
     */
    public static function get(string $chatid): array {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chatid]);
        if (!$record || empty($record->messages)) {
            return [];
        }
        $messages = json_decode($record->messages, true);
        return is_array($messages) ? $messages : [];
    }

    /**
     * Append a message to the session and trim to configured max_turns.
     *
     * @param string $chatid
     * @param string $role     'user' | 'assistant' | 'system'
     * @param string $content
     */
    public static function append(string $chatid, string $role, string $content): void {
        global $DB;

        // Get max turns from config, with fallback to 10 and clamp 1-100.
        // A configured '0' must reach the clamp below, so only fall back when unset.
        $configured = get_config('local_jedomi', 'telegram_max_turns');
        $maxturns = ($configured === false || $configured === '') ? 10 : (int) $configured;
        if ($maxturns < 1) {
            $maxturns = 1;
        }
        if ($maxturns > 100) {
            $maxturns = 100;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $messages = self::get($chatid);
            $messages[] = ['role' => $role, 'content' => $content];

            // Trim to last max_turns.
            if (count($messages) > $maxturns) {
                $messages = array_slice($messages, -$maxturns);
            }

            $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chatid]);
            $now = time();
            if ($record) {
                $record->messages = json_encode($messages, JSON_UNESCAPED_UNICODE);
                $record->lastactivity = $now;
                $DB->update_record('local_jedomi_telegram_sessions', $record);
            } else {
                $DB->insert_record('local_jedomi_telegram_sessions', (object)[
                    'chatid'       => $chatid,
                    'messages'     => json_encode($messages, JSON_UNESCAPED_UNICODE),
                    'lastactivity' => $now,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Load cached user context for a chat_id.
     *
     * @param string $chatid
     * @return array|null Decoded context or null if not cached
     */
    public static function get_context(string $chatid): ?array {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chatid], 'usercontext');
        if (!$record || empty($record->usercontext)) {
            return null;
        }
        $ctx = json_decode($record->usercontext, true);
        return is_array($ctx) ? $ctx : null;
    }

    /**
     * Store user context in the session cache for a chat_id.
     *
     * Uses optimistic concurrency: if another worker already saved a state
     * with a higher iteration, our write is silently skipped. This prevents
     * stale self-ping state from overwriting the latest worker's progress.
     *
     * @param string $chatid
     * @param array $context
     */
    public static function set_context(string $chatid, array $context): void {
        global $DB;

        $newctxjson = json_encode($context, JSON_UNESCAPED_UNICODE);
        $newiter = (int)($context['iteration'] ?? 0);

        $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chatid]);
        if ($record) {
            // ... ── Optimistic concurrency guard ──────────────────────────────
            // Only overwrite if our iteration is strictly newer. If another
            // worker (self-ping) already saved a state with a higher or equal
            // iteration, our write is stale — skip it silently.
            // Note: there is still a tiny race window between get_record and
            // update_record, but workers are seconds apart, not microseconds.
            $olditer = (int)($record->iteration ?? -1);
            // Allow writes at iteration 0 (resets, stale lock release, /new).
            // These are explicit clears that MUST overwrite the state even if
            // the old iteration is higher. Only block true stale writes where
            // a newer worker already progressed past us.
            if ($newiter > 0 && $newiter <= $olditer) {
                return;
            }
            $record->usercontext = $newctxjson;
            $record->iteration = $newiter;
            $record->lastactivity = time();
            $DB->update_record('local_jedomi_telegram_sessions', $record);
        } else {
            $DB->insert_record('local_jedomi_telegram_sessions', (object)[
                'chatid'       => $chatid,
                'usercontext'  => $newctxjson,
                'iteration'    => $newiter,
                'userid'       => 0,
                'messages'     => '[]',
                'lastactivity' => time(),
            ]);
        }
    }

    /**
     * Remove cached user context for a chat_id.
     *
     * @param string $chatid
     */
    public static function clear_context(string $chatid): void {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chatid], 'id');
        if ($record) {
            $DB->set_field('local_jedomi_telegram_sessions', 'usercontext', null, ['id' => $record->id]);
        }
    }

    /**
     * Clear the session for a chat_id.
     *
     * @param string $chatid
     */
    public static function clear(string $chatid): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('local_jedomi_telegram_sessions', ['chatid' => $chatid]);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
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
