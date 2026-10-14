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
 * Parser for buffered OpenAI-compatible SSE chat completion streams.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

/**
 * Reassembles the deltas of a fully buffered "text/event-stream" response.
 *
 * Tolerates usage-only chunks (empty choices), comment/event lines and
 * undecodable payloads; stops at the [DONE] sentinel; and surfaces an
 * in-stream {"error": ...} chunk so callers can report it instead of
 * treating the stream as an empty answer.
 */
final class sse_parser {
    /**
     * Parse a complete SSE buffer.
     *
     * @param string $buffer Raw response body.
     * @return array{text:string,reasoning:string,tool_calls:array,finish_reason:string,had_data:bool,error:?array}
     *         tool_calls is a list ordered by stream index (arrival order when the server omits
     *         "index", as Gemini does for parallel calls), each entry shaped as
     *         ['id' => string, 'type' => string, 'function' => ['name' => string, 'arguments' => string]]
     *         plus any vendor keys the server streamed (e.g. Gemini's extra_content), which are
     *         only present when the server sent them.
     *         error is null or ['message' => string] plus any other keys sent by the server (e.g. code).
     */
    public static function parse(string $buffer): array {
        $text = '';
        $reasoning = '';
        $toolcalls = [];
        $finishreason = '';
        $haddata = false;
        $error = null;

        foreach (explode("\n", $buffer) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $raw = trim(substr($line, 5));
            if ($raw === '[DONE]') {
                break;
            }
            $json = json_decode($raw, true);
            if (!is_array($json) || empty($json)) {
                continue;
            }
            $haddata = true;

            if (isset($json['error']) && $error === null) {
                $error = self::normalise_error($json['error']);
            }

            $choice = $json['choices'][0] ?? [];
            $delta = $choice['delta'] ?? [];
            if (!empty($choice['finish_reason'])) {
                $finishreason = (string) $choice['finish_reason'];
            }

            if (isset($delta['content']) && is_string($delta['content'])) {
                $text .= $delta['content'];
            }
            if (isset($delta['reasoning_content']) && is_string($delta['reasoning_content'])) {
                $reasoning .= $delta['reasoning_content'];
            }
            if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                self::accumulate_tool_calls($toolcalls, $delta['tool_calls']);
            }
        }

        ksort($toolcalls);

        return [
            'text' => $text,
            'reasoning' => $reasoning,
            'tool_calls' => array_values($toolcalls),
            'finish_reason' => $finishreason,
            'had_data' => $haddata,
            'error' => $error,
        ];
    }

    /**
     * Merge one delta's tool_calls fragments into the accumulator.
     *
     * The slot of each fragment is resolved by {@see resolve_slot()}: OpenAI-style streams carry
     * "index"; Gemini's OpenAI-compatible stream omits it and sends complete entries keyed by "id".
     *
     * @param array $acc Accumulator (by reference).
     * @param array $fragments delta.tool_calls entries.
     */
    private static function accumulate_tool_calls(array &$acc, array $fragments): void {
        foreach ($fragments as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $idx = self::resolve_slot($acc, $tc);
            if (!isset($acc[$idx])) {
                $acc[$idx] = [
                    'id'       => (string) ($tc['id'] ?? ''),
                    'type'     => (string) ($tc['type'] ?? 'function'),
                    'function' => ['name' => '', 'arguments' => ''],
                ];
            }
            if (!empty($tc['id'])) {
                $acc[$idx]['id'] = (string) $tc['id'];
            }
            if (!empty($tc['function']['name'])) {
                $acc[$idx]['function']['name'] = (string) $tc['function']['name'];
            }
            if (isset($tc['function']['arguments'])) {
                $arg = $tc['function']['arguments'];
                // Some servers (e.g. Ollama) send arguments as a pre-decoded object.
                if (is_array($arg)) {
                    $acc[$idx]['function']['arguments'] = json_encode($arg, JSON_UNESCAPED_UNICODE);
                } else {
                    $acc[$idx]['function']['arguments'] .= (string) $arg;
                }
            }
            // Vendor keys (e.g. Gemini's extra_content.google.thought_signature) must survive so
            // callers can echo them back in the next turn; Gemini rejects tool loops without them.
            self::merge_extra_keys($acc[$idx], $tc, ['index', 'id', 'type', 'function']);
            if (is_array($tc['function'] ?? null)) {
                self::merge_extra_keys($acc[$idx]['function'], $tc['function'], ['name', 'arguments']);
            }
        }
    }

    /**
     * Decide which accumulator slot a tool_call fragment belongs to.
     *
     * - With "index" (OpenAI, Mistral via gateway): that index, as before.
     * - Without "index" but with an "id" (Gemini): the slot already holding that id, or a new slot
     *   after the last one, so parallel calls are never merged into slot 0.
     * - With neither: a continuation of the most recent slot.
     *
     * @param array $acc Accumulator.
     * @param array $tc Fragment.
     * @return int
     */
    private static function resolve_slot(array $acc, array $tc): int {
        if (isset($tc['index'])) {
            return (int) $tc['index'];
        }
        if (empty($acc)) {
            return 0;
        }
        if (!empty($tc['id'])) {
            foreach ($acc as $slot => $existing) {
                if ($existing['id'] === (string) $tc['id']) {
                    return (int) $slot;
                }
            }
            return (int) max(array_keys($acc)) + 1;
        }
        return (int) array_key_last($acc);
    }

    /**
     * Copy every key of $fragment not listed in $known onto $target.
     *
     * Arrays are merged recursively with later deltas winning; scalars are overridden.
     *
     * @param array $target Accumulated tool_call (or its function) by reference.
     * @param array $fragment Delta fragment.
     * @param string[] $known Keys handled explicitly by the accumulator.
     */
    private static function merge_extra_keys(array &$target, array $fragment, array $known): void {
        foreach ($fragment as $key => $value) {
            if (in_array($key, $known, true)) {
                continue;
            }
            if (is_array($value) && isset($target[$key]) && is_array($target[$key])) {
                $target[$key] = array_replace_recursive($target[$key], $value);
            } else {
                $target[$key] = $value;
            }
        }
    }

    /**
     * Normalise an error payload to an array with at least a message key.
     *
     * @param mixed $error Decoded error value from the chunk.
     * @return array
     */
    private static function normalise_error($error): array {
        if (is_array($error)) {
            if (!isset($error['message']) || !is_string($error['message'])) {
                $error['message'] = json_encode($error, JSON_UNESCAPED_UNICODE);
            }
            return $error;
        }
        return ['message' => is_scalar($error) ? (string) $error : json_encode($error, JSON_UNESCAPED_UNICODE)];
    }
}
