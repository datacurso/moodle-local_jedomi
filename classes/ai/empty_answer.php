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
 * Final answers without text, and the request that asks for the answer again.
 *
 * Some models end without any text: out of tokens while thinking (finish_reason "length"), or with an empty "stop".
 * The web chat, Telegram and the agents ask once more with {@see retry_message()}; the request keeps the same
 * maximum tokens (custom_max_tokens with the custom service), it only asks for a short answer.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class empty_answer {
    /**
     * Whether a normalised answer of {@see client::complete()} is a final answer without text.
     *
     * An answer out of tokens counts as empty even with thinking text, which is cut and never an answer. After an empty
     * "stop", the thinking text is still shown as before.
     *
     * @param array $answer Normalised answer: content, tool_calls, finish_reason, reasoning.
     * @return bool
     */
    public static function is_empty(array $answer): bool {
        if (!empty($answer['tool_calls']) || trim((string) ($answer['content'] ?? '')) !== '') {
            return false;
        }
        return ($answer['finish_reason'] ?? '') === 'length' || trim((string) ($answer['reasoning'] ?? '')) === '';
    }

    /**
     * Message added to the conversation to ask for the answer again.
     *
     * @param string $finishreason Finish reason of the empty answer.
     * @return array User message for the AI.
     */
    public static function retry_message(string $finishreason): array {
        $content = $finishreason === 'length'
            ? 'Your previous answer ran out of space before it had any text. Answer now in a few short sentences, '
                . 'without thinking aloud and without repeating the data.'
            : 'Your previous answer was empty. Answer the last question of the user now, with text.';
        return ['role' => 'user', 'content' => $content];
    }
}
