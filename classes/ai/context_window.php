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

namespace local_jedomi\ai;

/**
 * Keeps a chat request of the custom AI service within the context window of its chat model.
 *
 * The tokens are estimated, not counted: one token per {@see self::CHARS_PER_TOKEN} characters of each message encoded
 * as JSON (as it is sent), plus {@see self::MESSAGE_OVERHEAD} tokens per message for the chat template. That is the
 * usual rule of thumb for English and Spanish text with the tokenizers of current models; it errs on the generous side
 * for plain text and is never exact, so the window should keep some margin.
 *
 * When the estimate goes over the budget (the context window minus the tokens reserved for the answer and minus
 * the tool definitions; the reservation is the max tokens, but never more than half the window, see
 * {@see self::answer_reservation()}), the oldest messages of the history are dropped first. The system messages and the latest
 * user turn (the latest user message and everything after it: the tool calls and results of the current answer) are
 * never dropped. An assistant message with tool calls is dropped together with its tool results, never split, and the
 * kept history starts with a user message, because many chat templates require the user to speak first. If the
 * request is still too large, it is sent as small as it can be and the service decides.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context_window {
    /** @var int Characters per estimated token. */
    public const CHARS_PER_TOKEN = 4;

    /** @var int Estimated tokens the chat template adds to each message (role and separators). */
    public const MESSAGE_OVERHEAD = 4;

    /** @var bool Whether the clamping of the answer reservation was logged in this request. */
    private static bool $clamplogged = false;

    /**
     * Estimated tokens of messages and tool definitions.
     *
     * @param array $messages Messages in OpenAI chat format.
     * @param array $tools OpenAI tool definitions; empty for none.
     * @return int
     */
    public static function estimate_tokens(array $messages, array $tools = []): int {
        $tokens = 0;
        foreach ($messages as $message) {
            $tokens += self::estimate_message($message);
        }
        if (!empty($tools)) {
            $tokens += self::estimate_text(self::encode($tools));
        }
        return $tokens;
    }

    /**
     * Drop the oldest messages until the estimate fits in a budget.
     *
     * @param array $messages Messages in OpenAI chat format.
     * @param int $budget Estimated tokens the messages may use.
     * @return array{messages: array, dropped: int} The kept messages, in their order, and how many were dropped.
     */
    public static function fit(array $messages, int $budget): array {
        $messages = array_values($messages);
        $latestuser = null;
        foreach ($messages as $index => $message) {
            if (($message['role'] ?? '') === 'user') {
                $latestuser = $index;
            }
        }
        if ($latestuser === null || self::estimate_tokens($messages) <= $budget) {
            return ['messages' => $messages, 'dropped' => 0];
        }

        // Droppable units: the messages before the latest user turn, other than the system messages, where an
        // assistant message with tool calls and the tool results that follow it form one unit.
        $units = [];
        $kept = [];
        foreach (array_slice($messages, 0, $latestuser) as $index => $message) {
            $role = $message['role'] ?? '';
            if ($role === 'system') {
                $kept[$index] = true;
            } else if ($role === 'tool' && !empty($units)) {
                $units[count($units) - 1][] = $index;
            } else {
                $units[] = [$index];
            }
        }
        $total = self::estimate_tokens($messages);
        $dropped = [];
        foreach ($units as $unit) {
            $first = $messages[$unit[0]]['role'] ?? '';
            if ($total <= $budget && $first === 'user') {
                break;
            }
            foreach ($unit as $index) {
                $total -= self::estimate_message($messages[$index]);
                $dropped[$index] = true;
            }
        }

        $result = [];
        foreach ($messages as $index => $message) {
            if (!isset($dropped[$index])) {
                $result[] = $message;
            }
        }
        return ['messages' => $result, 'dropped' => count($dropped)];
    }

    /**
     * Fit the messages of a chat call in the context window of the custom service, when it is in use and known.
     *
     * The budget is the window minus the max tokens of the answer and minus the tool definitions. A trimming is written
     * to the technical log with counts only, never content.
     *
     * @param array $messages Messages in OpenAI chat format.
     * @param array $tools OpenAI tool definitions of the call.
     * @return array The messages to send.
     */
    public static function fit_for_custom_service(array $messages, array $tools): array {
        if (!ai_service::is_custom()) {
            return $messages;
        }
        $window = ai_service::get_custom_context_window();
        if ($window <= 0) {
            return $messages;
        }
        $maxtokens = model_settings::get_max_tokens();
        $reserve = self::answer_reservation($window, $maxtokens);
        if ($reserve < $maxtokens && !self::$clamplogged) {
            require_once(__DIR__ . '/../../lib.php');
            \local_jedomi_log('CONTEXT_RESERVE_CLAMPED', ['window' => $window, 'max_tokens' => $maxtokens, 'reserve' => $reserve]);
            self::$clamplogged = true;
        }
        $budget = $window - $reserve - self::estimate_tokens([], $tools);
        $result = self::fit($messages, $budget);
        if ($result['dropped'] > 0) {
            require_once(__DIR__ . '/../../lib.php');
            \local_jedomi_log('CONTEXT_TRIMMED', [
                'window' => $window,
                'budget' => $budget,
                'messages_before' => count($messages),
                'messages_dropped' => $result['dropped'],
                'estimated_before' => self::estimate_tokens($messages),
                'estimated_after' => self::estimate_tokens($result['messages']),
            ]);
        }
        return $result['messages'];
    }

    /**
     * Tokens kept free for the answer in a context window: the max tokens, but never more than half the window, so a
     * max tokens as large as the window (such as the default 16384 with a model of 16384 or 8192 tokens) never leaves
     * the conversation without room.
     *
     * @param int $window Context window, in tokens.
     * @param int|null $maxtokens Max tokens of the answer; null for those of the service in use.
     * @return int
     */
    public static function answer_reservation(int $window, ?int $maxtokens = null): int {
        return min($maxtokens ?? model_settings::get_max_tokens(), intdiv(max(0, $window), 2));
    }

    /**
     * Estimated tokens of one message.
     *
     * @param mixed $message Message.
     * @return int
     */
    private static function estimate_message($message): int {
        return self::estimate_text(self::encode($message)) + self::MESSAGE_OVERHEAD;
    }

    /**
     * Estimated tokens of a text.
     *
     * @param string $text Text.
     * @return int
     */
    private static function estimate_text(string $text): int {
        return (int) ceil(\core_text::strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * JSON of a value as it is sent, with its characters unescaped.
     *
     * @param mixed $value Value.
     * @return string
     */
    private static function encode($value): string {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
