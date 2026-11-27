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
 * Channel-neutral conversation store: message history and tool loop state per chat key.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Persists the conversation of a messaging channel (Telegram, WhatsApp) in that channel's session table.
 *
 * Every channel table has the shape of local_jedomi_telegram_sessions: chatid (unique), messages (JSON history),
 * usercontext (JSON tool loop state), iteration and lastactivity. The store gets the table name and the name of the
 * local_jedomi setting that holds the max turns kept in the history. Rows older than 30 minutes are removed by the
 * channel's cleanup task.
 */
class conversation_store {
    /** @var int Turns kept when the max turns setting is not saved. */
    private const DEFAULT_MAX_TURNS = 10;

    /** @var int Lowest max turns accepted. */
    private const MIN_MAX_TURNS = 1;

    /** @var int Highest max turns accepted. */
    private const MAX_MAX_TURNS = 100;

    /**
     * Constructor.
     *
     * @param string $table Session table of the channel, e.g. local_jedomi_telegram_sessions.
     * @param string $maxturnssetting local_jedomi setting with the max turns kept, e.g. telegram_max_turns.
     */
    public function __construct(
        /** @var string Session table of the channel. */
        private readonly string $table,
        /** @var string local_jedomi setting with the max turns kept. */
        private readonly string $maxturnssetting,
    ) {
    }

    /**
     * Load the message history of a chat.
     *
     * @param string $chatkey
     * @return array Array of message arrays ['role' => ..., 'content' => ...]
     */
    public function get(string $chatkey): array {
        global $DB;
        $record = $DB->get_record($this->table, ['chatid' => $chatkey]);
        if (!$record || empty($record->messages)) {
            return [];
        }
        $messages = json_decode($record->messages, true);
        return is_array($messages) ? $messages : [];
    }

    /**
     * Append a message to the history and trim it to the configured max turns.
     *
     * @param string $chatkey
     * @param string $role 'user' | 'assistant' | 'system'
     * @param string $content
     */
    public function append(string $chatkey, string $role, string $content): void {
        global $DB;

        $maxturns = $this->max_turns();

        $transaction = $DB->start_delegated_transaction();
        try {
            $messages = $this->get($chatkey);
            $messages[] = ['role' => $role, 'content' => $content];

            // Trim to last max_turns.
            if (count($messages) > $maxturns) {
                $messages = array_slice($messages, -$maxturns);
            }

            $record = $DB->get_record($this->table, ['chatid' => $chatkey]);
            $now = time();
            if ($record) {
                $record->messages = json_encode($messages, JSON_UNESCAPED_UNICODE);
                $record->lastactivity = $now;
                $DB->update_record($this->table, $record);
            } else {
                $DB->insert_record($this->table, (object) [
                    'chatid' => $chatkey,
                    'messages' => json_encode($messages, JSON_UNESCAPED_UNICODE),
                    'lastactivity' => $now,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Load the cached tool loop state of a chat.
     *
     * @param string $chatkey
     * @return array|null Decoded context or null if not cached
     */
    public function get_context(string $chatkey): ?array {
        global $DB;
        $record = $DB->get_record($this->table, ['chatid' => $chatkey], 'usercontext');
        if (!$record || empty($record->usercontext)) {
            return null;
        }
        $ctx = json_decode($record->usercontext, true);
        return is_array($ctx) ? $ctx : null;
    }

    /**
     * Store the tool loop state of a chat.
     *
     * Uses optimistic concurrency: if another worker already saved a state with a higher or equal iteration, the
     * write is silently skipped, so a stale continuation never overwrites the latest worker's progress. Iteration 0
     * (resets, stale lock release, /new) always overwrites.
     *
     * @param string $chatkey
     * @param array $context
     */
    public function set_context(string $chatkey, array $context): void {
        global $DB;

        $newctxjson = json_encode($context, JSON_UNESCAPED_UNICODE);
        $newiter = (int) ($context['iteration'] ?? 0);

        $record = $DB->get_record($this->table, ['chatid' => $chatkey]);
        if ($record) {
            // There is still a tiny race window between get_record and update_record, but workers are seconds
            // apart, not microseconds.
            $olditer = (int) ($record->iteration ?? -1);
            if ($newiter > 0 && $newiter <= $olditer) {
                return;
            }
            $record->usercontext = $newctxjson;
            $record->iteration = $newiter;
            $record->lastactivity = time();
            $DB->update_record($this->table, $record);
        } else {
            $DB->insert_record($this->table, (object) [
                'chatid' => $chatkey,
                'usercontext' => $newctxjson,
                'iteration' => $newiter,
                'messages' => '[]',
                'lastactivity' => time(),
            ]);
        }
    }

    /**
     * Whether anything (history or tool loop state) is saved for a chat key.
     *
     * @param string $chatkey
     * @return bool
     */
    public function exists(string $chatkey): bool {
        global $DB;
        return $DB->record_exists($this->table, ['chatid' => $chatkey]);
    }

    /**
     * Remove the cached tool loop state of a chat, keeping its history.
     *
     * @param string $chatkey
     */
    public function clear_context(string $chatkey): void {
        global $DB;
        $record = $DB->get_record($this->table, ['chatid' => $chatkey], 'id');
        if ($record) {
            $DB->set_field($this->table, 'usercontext', null, ['id' => $record->id]);
        }
    }

    /**
     * Remove the conversation of a chat (history and state).
     *
     * @param string $chatkey
     */
    public function clear(string $chatkey): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records($this->table, ['chatid' => $chatkey]);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Max turns kept in the history: the saved setting clamped to 1..100, or 10 when it is not saved.
     *
     * A saved '0' must reach the clamp, so only an unset or empty setting falls back to the default.
     *
     * @return int
     */
    private function max_turns(): int {
        $configured = get_config('local_jedomi', $this->maxturnssetting);
        $maxturns = ($configured === false || $configured === '') ? self::DEFAULT_MAX_TURNS : (int) $configured;
        return max(self::MIN_MAX_TURNS, min(self::MAX_MAX_TURNS, $maxturns));
    }
}
