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
        $max_turns = (int) (get_config('local_jedomi', 'telegram_max_turns') ?: 10);
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
     * @param string $chat_id
     * @param array $context
     */
    public static function set_context(string $chat_id, array $context): void {
        global $DB;
        $record = $DB->get_record('local_jedomi_telegram_sessions', ['chatid' => $chat_id]);
        if ($record) {
            $record->usercontext = json_encode($context, JSON_UNESCAPED_UNICODE);
            $DB->update_record('local_jedomi_telegram_sessions', $record);
        } else {
            $record = new \stdClass();
            $record->chatid = $chat_id;
            $record->usercontext = json_encode($context, JSON_UNESCAPED_UNICODE);
            $record->userid = 0;
            $record->messages = '[]';
            $DB->insert_record('local_jedomi_telegram_sessions', $record);
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
