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
 * Reader of the tool calls a model writes as text.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Reads the tool calls a model without native function calling writes in its text answer.
 *
 * The formats are tried in order and the first one that gives at least one call wins: a TOOL_CALL block (name on the
 * first line, JSON arguments on the next ones), the same block written inline, a <|tool_call|> or <|python_tag|>
 * special token, and an ultra-broad fallback that takes any JSON object between a call and END_TOOL_CALL.
 */
final class tool_call_text_parser {
    /** @var string TOOL_CALL (or call) block: name, newline, arguments, newline, END_TOOL_CALL. */
    private const BLOCK = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n(.+?)\nEND_TOOL_CALL[^\n]*/si';

    /** @var string The block with the newlines around the arguments optional. */
    private const INLINE = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n?(.+?)(?:\n\s*)?END_TOOL_CALL[^\n]*/si';

    /** @var string Llama 3 style special token. The closing tag repeats the captured content (backreference \1). */
    private const SPECIAL_TOKEN = '/<\|(?:tool_call|python_tag)\|>(.+?)<\|\\/\1\|>/si';

    /** @var string Anything between a call and END_TOOL_CALL, tried only when the text still looks like a call. */
    private const ULTRA = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n?(.+?)\s*END_TOOL_CALL\s*/si';

    /** @var string What a tool call written as text starts with. */
    private const MARKER = '/\b(?:TOOL_CALL|call):\s*\w+/i';

    /** @var string Id prefix of the calls read by every format except the ultra-broad fallback. */
    private const TEXT_PREFIX = 'text_';

    /** @var string Id prefix of the calls read by the ultra-broad fallback. */
    private const ULTRA_PREFIX = 'ultra_';

    /**
     * Read the tool calls of a text answer.
     *
     * @param string $content Text the model answered.
     * @return array ['tool_calls' => OpenAI-format tool calls (empty when none could be read),
     *                'remaining' => the text without the calls of the format that gave them]
     */
    public static function parse(string $content): array {
        foreach ([self::BLOCK, self::INLINE] as $pattern) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                $calls = [];
                foreach ($matches as $m) {
                    $arguments = self::json_arguments(trim($m[2]), false);
                    if ($arguments !== null) {
                        $calls[] = self::tool_call(self::TEXT_PREFIX, trim($m[1]), $arguments);
                    }
                }
                if (!empty($calls)) {
                    return ['tool_calls' => $calls, 'remaining' => trim(preg_replace($pattern, '', $content))];
                }
            }
        }

        if (preg_match_all(self::SPECIAL_TOKEN, $content, $matches, PREG_SET_ORDER)) {
            $calls = [];
            foreach ($matches as $m) {
                $inner = trim($m[1]);
                $decoded = json_decode($inner, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['name'])) {
                    $toolname = $decoded['name'];
                    $args = $decoded['arguments'] ?? '{}';
                    if (is_array($args)) {
                        $args = json_encode($args, JSON_UNESCAPED_UNICODE);
                    }
                } else {
                    $parts = explode("\n", $inner, 2);
                    $toolname = trim($parts[0]);
                    $args = $parts[1] ?? '{}';
                }
                if (self::is_json($args)) {
                    $calls[] = self::tool_call(self::TEXT_PREFIX, $toolname, $args);
                }
            }
            if (!empty($calls)) {
                return ['tool_calls' => $calls, 'remaining' => trim(preg_replace(self::SPECIAL_TOKEN, '', $content))];
            }
        }

        if (self::looks_like_tool_call($content) && preg_match_all(self::ULTRA, $content, $matches, PREG_SET_ORDER)) {
            $calls = [];
            foreach ($matches as $m) {
                $arguments = self::json_arguments(trim($m[2]), true);
                if ($arguments !== null) {
                    $calls[] = self::tool_call(self::ULTRA_PREFIX, trim($m[1]), $arguments);
                }
            }
            if (!empty($calls)) {
                return ['tool_calls' => $calls, 'remaining' => trim(preg_replace(self::ULTRA, '', $content))];
            }
        }

        return ['tool_calls' => [], 'remaining' => $content];
    }

    /**
     * Whether a text looks like it holds a tool call, read or not.
     *
     * @param string $content Text the model answered.
     * @return bool
     */
    public static function looks_like_tool_call(string $content): bool {
        return (bool) preg_match(self::MARKER, $content);
    }

    /**
     * JSON arguments of a call: the text itself when it is JSON, else the JSON object found in it.
     *
     * @param string $raw Text of the arguments.
     * @param bool $alwaysextract Take the JSON object found in the text even when the whole text is JSON.
     * @return string|null The arguments, or null when no JSON could be read.
     */
    private static function json_arguments(string $raw, bool $alwaysextract): ?string {
        if (($alwaysextract || !self::is_json($raw)) && preg_match('/\{.*\}/s', $raw, $jsonmatch)) {
            $raw = $jsonmatch[0];
        }
        return self::is_json($raw) ? $raw : null;
    }

    /**
     * Whether a value decodes as JSON.
     *
     * @param mixed $value Value, as json_decode() takes it.
     * @return bool
     */
    private static function is_json(mixed $value): bool {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * A tool call in the OpenAI format, with a new id.
     *
     * @param string $prefix Id prefix.
     * @param mixed $name Tool name.
     * @param mixed $arguments JSON arguments.
     * @return array
     */
    private static function tool_call(string $prefix, mixed $name, mixed $arguments): array {
        return [
            'id' => $prefix . md5(uniqid('', true)),
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ];
    }
}
