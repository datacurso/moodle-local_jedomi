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

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Conversion of an AI answer (Markdown or HTML) to the HTML subset Telegram accepts.
 *
 * Every <, > and & that is not part of an allowed tag is escaped and the allowed tags are balanced,
 * so Telegram accepts the message on the first attempt.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class formatter {
    /** Tags Telegram accepts in HTML mode (tg-spoiler and tg-emoji included). */
    private const ALLOWED = ['b', 'i', 'u', 's', 'a', 'code', 'pre', 'blockquote', 'tg-spoiler', 'tg-emoji'];

    /**
     * Convert an AI answer to Telegram HTML.
     *
     * @param string $content Answer of the AI.
     * @return string Telegram HTML with special characters escaped and tags balanced.
     */
    public static function to_html(string $content): string {
        // HTML tables are not supported by Telegram: they become pipe-delimited <pre> blocks.
        $content = \local_jedomi_sanitize_html_tables($content);

        // Inline and display math delimiters \[...\] and \(...\) become dollar signs.
        $content = preg_replace('/\\\\\[(.*?)\\\\\x5d/s', '$$$$1$$', $content) ?? $content;
        $content = preg_replace('/\\\\\((.*?)\\\\\x29/s', '$$$$1$$', $content) ?? $content;

        // Markdown links.
        $content = preg_replace('/\[([^\]\n]+)\]\(([^)\s]+)\)/', '<a href="$2">$1</a>', $content) ?? $content;

        // Code first, so the formatting marks inside it are kept.
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
        $content = preg_replace('/```(\w*)\s*\n?(.*?)```/s', '<pre>$2</pre>', $content) ?? $content;
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
        $content = preg_replace('/``([^`]+)``/', '<pre>$1</pre>', $content) ?? $content;
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
        $content = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $content) ?? $content;
        $content = preg_replace_callback('/<(code|pre)\b[^>]*>.*?<\/\1>/is', static function (array $m): string {
            return str_replace(['*', '_', '~'], ['&#42;', '&#95;', '&#126;'], $m[0]);
        }, $content) ?? $content;

        // List items marked with an asterisk, a dash or a plus sign keep a bullet instead of becoming italics.
        $content = preg_replace('/^([ \t]*)[*+-][ \t]+/m', '$1• ', $content) ?? $content;

        // Inline formatting never spans several lines.
        $content = preg_replace('/~~([^~\n]+)~~/', '<s>$1</s>', $content) ?? $content;
        $content = preg_replace('/__([^_\n]+)__/', '<u>$1</u>', $content) ?? $content;
        $content = preg_replace('/\*\*([^*\n]+)\*\*/', '<b>$1</b>', $content) ?? $content;
        $content = preg_replace('/(?<![\w*])\*([^*\s][^*\n]*?)\*(?![\w*])/', '<i>$1</i>', $content) ?? $content;

        // HTML variants of the allowed formatting and simple block tags.
        $content = preg_replace([
            '/<strong\b[^>]*>/i', '/<\/strong>/i', '/<em\b[^>]*>/i', '/<\/em>/i', '/<ins\b[^>]*>/i', '/<\/ins>/i',
            '/<(?:strike|del)\b[^>]*>/i', '/<\/(?:strike|del)>/i', '/<br\s*\/?>/i', '/<li\b[^>]*>/i',
            '/<\/(?:p|div|li|h[1-6])>/i',
        ], ['<b>', '</b>', '<i>', '</i>', '<u>', '</u>', '<s>', '</s>', "\n", '• ', "\n"], $content) ?? $content;

        return self::escape_and_balance($content);
    }

    /**
     * Keep the allowed tags (with their allowed attributes only), drop other HTML tags, escape every other
     * <, > and & and close or drop unbalanced tags.
     *
     * @param string $html Text with HTML tags.
     * @return string
     */
    private static function escape_and_balance(string $html): string {
        $parts = preg_split('/(<\/?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>]*)?\/?>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $output = '';
        $open = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $output .= self::escape_text($part);
                continue;
            }
            preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9-]*)/', $part, $m);
            $closing = $m[1] === '/';
            $tag = strtolower($m[2]);
            if ($tag === 'span' && !$closing && preg_match('/class=["\']tg-spoiler["\']/i', $part)) {
                $tag = 'tg-spoiler';
            } else if ($tag === 'span' && $closing && end($open) === 'tg-spoiler') {
                $tag = 'tg-spoiler';
            }
            if (!in_array($tag, self::ALLOWED, true)) {
                // Other HTML tags are removed, their text is kept.
                continue;
            }
            if ($closing) {
                $position = array_search($tag, $open, true);
                if ($position === false) {
                    continue;
                }
                // Close the tags opened inside it, then the tag itself.
                while (count($open) > $position) {
                    $output .= '</' . array_pop($open) . '>';
                }
                continue;
            }
            $opening = self::opening_tag($tag, $part);
            if ($opening === null) {
                continue;
            }
            $output .= $opening;
            $open[] = $tag;
        }
        while ($open) {
            $output .= '</' . array_pop($open) . '>';
        }
        return $output;
    }

    /**
     * Opening tag with only the attributes Telegram accepts.
     *
     * @param string $tag Allowed tag name.
     * @param string $source Original tag.
     * @return string|null The tag, or null when a required attribute is missing.
     */
    private static function opening_tag(string $tag, string $source): ?string {
        switch ($tag) {
            case 'a':
                if (!preg_match('/href\s*=\s*(["\'])(.*?)\1/i', $source, $m)) {
                    return null;
                }
                return '<a href="' . s(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '">';
            case 'code':
                if (preg_match('/class\s*=\s*["\']language-([a-zA-Z0-9_+-]+)["\']/i', $source, $m)) {
                    return '<code class="language-' . $m[1] . '">';
                }
                return '<code>';
            case 'tg-emoji':
                if (!preg_match('/emoji-id\s*=\s*["\'](\d+)["\']/i', $source, $m)) {
                    return null;
                }
                return '<tg-emoji emoji-id="' . $m[1] . '">';
            default:
                return '<' . $tag . '>';
        }
    }

    /**
     * Escape <, > and & in text. Entities already written are decoded first, because Telegram only knows a few
     * named entities.
     *
     * @param string $text Text without tags.
     * @return string
     */
    private static function escape_text(string $text): string {
        return htmlspecialchars(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_NOQUOTES, 'UTF-8');
    }
}
