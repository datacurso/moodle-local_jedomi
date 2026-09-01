<?php
/**
 * Telegram session store — persists conversational context per chat_id.
 *
 * Messages are stored as a JSON array (max turns configured by admin, default 10).
 * TTL enforced by cleanup task: rows with lastactivity < now - 30min are deleted.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

class session {

    /** System prompt base — replaced in proxy_to_gateway with full context. */
    public const ANTI_HALLUCINATION_PROMPT = 'Eres Jedomi, un asistente educativo integrado en Moodle LMS. '
        . 'Seguí estas reglas:\n'
        . "- No inventes datos.\n"
        . "- Si tenés datos suficientes, respondé y NO sigas llamando funciones.\n"
        . "- Si una función falla, intentá una vez más. Si vuelve a fallar, informá y detenete.\n"
        . "- Respetá permisos y privacidad.\n"
        . "- Respondé en el idioma del usuario.\n"
        . "- Si no podés hacer algo, decí por qué y proponé el mejor paso siguiente.";

    /**
     * Load session messages for a chat_id.
     *
     * @param string $chat_id
     * @return array Array of message arrays ['role' => ..., 'content' => ...]
     */
    public static function get(string $chat_id): array {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chat_id]);
        if (!$record || empty($record->messages)) {
            return [];
        }
        $messages = json_decode($record->messages, true);
        return is_array($messages) ? $messages : [];
    }

    /**
     * Append a message to the session and trim to configured max_turns.
     *
     * @param string $chat_id
     * @param string $role     'user' | 'assistant' | 'system'
     * @param string $content
     */
    public static function append(string $chat_id, string $role, string $content): void {
        global $DB;

        // Get max turns from config, with fallback to 10 and clamp 1-100.
        // A configured '0' must reach the clamp below, so only fall back when unset.
        $configured = get_config('local_jedomi', 'telegram_max_turns');
        $max_turns = ($configured === false || $configured === '') ? 10 : (int) $configured;
        if ($max_turns < 1) {
            $max_turns = 1;
        }
        if ($max_turns > 100) {
            $max_turns = 100;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $messages = self::get($chat_id);
            $messages[] = ['role' => $role, 'content' => $content];

            // Trim to last max_turns
            if (count($messages) > $max_turns) {
                $messages = array_slice($messages, -$max_turns);
            }

            $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chat_id]);
            $now = time();
            if ($record) {
                $record->messages = json_encode($messages, JSON_UNESCAPED_UNICODE);
                $record->lastactivity = $now;
                $DB->update_record('local_jedomi_telegram_sessions', $record);
            } else {
                $DB->insert_record('local_jedomi_telegram_sessions', (object)[
                    'chatid'       => $chat_id,
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
     * @param string $chat_id
     * @return array|null Decoded context or null if not cached
     */
    public static function get_context(string $chat_id): ?array {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chat_id], 'usercontext');
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
     * @param string $chat_id
     * @param array $context
     */
    public static function set_context(string $chat_id, array $context): void {
        global $DB;

        $newctx_json = json_encode($context, JSON_UNESCAPED_UNICODE);
        $newiter = (int)($context['iteration'] ?? 0);

        $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chat_id]);
        if ($record) {
            // ── Optimistic concurrency guard ──────────────────────────────
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
            $record->usercontext = $newctx_json;
            $record->iteration = $newiter;
            $record->lastactivity = time();
            $DB->update_record('local_jedomi_telegram_sessions', $record);
        } else {
            $DB->insert_record('local_jedomi_telegram_sessions', (object)[
                'chatid'       => $chat_id,
                'usercontext'  => $newctx_json,
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
     * @param string $chat_id
     */
    public static function clear_context(string $chat_id): void {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chat_id], 'id');
        if ($record) {
            $DB->set_field('local_jedomi_telegram_sessions', 'usercontext', null, ['id' => $record->id]);
        }
    }

    /**
     * Clear the session for a chat_id.
     *
     * @param string $chat_id
     */
    public static function clear(string $chat_id): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('local_jedomi_telegram_sessions', ['chatid' => $chat_id]);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Get the full messages array formatted for the Gateway API
     * (system instruction + context + current user message).
     *
     * @param string $chat_id
     * @param string $current_message
     * @return array
     */
    public static function build_messages_for_gateway(string $chat_id, string $current_message): array {
        $history = self::get($chat_id);
        $messages = [['role' => 'system', 'content' => self::ANTI_HALLUCINATION_PROMPT]];

        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $current_message];

        return $messages;
    }
}
