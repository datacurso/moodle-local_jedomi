<?php
/**
 * Telegram session store — persists conversational context per chat_id.
 *
 * Messages are stored as a JSON array (max 10 turns).
 * TTL enforced by cleanup task: rows with lastactivity < now - 30min are deleted.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

class session {

    /** Maximum conversation turns to retain. */
    private const MAX_TURNS = 10;

    /** Anti-hallucination system prompt in Spanish. */
    public const ANTI_HALLUCINATION_PROMPT = 'Eres Jedomi, un asistente educativo para Moodle LMS. '
        . "Sigue estas reglas anti-alucinación:\n"
        . "- Cita tus fuentes cuando sea posible.\n"
        . "- Si no sabes la respuesta, DILO explícitamente — no inventes información.\n"
        . "- Marca el nivel de confianza de tu respuesta (alta/media/baja).\n"
        . "- No especules sobre información que no tienes.\n"
        . "- Basate únicamente en el contexto proporcionado.\n"
        . 'Responde en el idioma del usuario.';

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
     * Append a message to the session and trim to MAX_TURNS.
     *
     * @param string $chat_id
     * @param string $role     'user' | 'assistant' | 'system'
     * @param string $content
     */
    public static function append(string $chat_id, string $role, string $content): void {
        global $DB;
        $messages = self::get($chat_id);
        $messages[] = ['role' => $role, 'content' => $content];

        // Trim to last MAX_TURNS
        if (count($messages) > self::MAX_TURNS) {
            $messages = array_slice($messages, -self::MAX_TURNS);
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
    }

    /**
     * Clear the session for a chat_id.
     *
     * @param string $chat_id
     */
    public static function clear(string $chat_id): void {
        global $DB;
        $DB->delete_records('local_jedomi_telegram_sessions', ['chatid' => $chat_id]);
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
