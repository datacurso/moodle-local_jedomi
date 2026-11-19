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

    /** Other HTML tags, which are removed keeping their text. Any other text between < and > is shown as written. */
    private const KNOWN_HTML = [
        'abbr', 'address', 'article', 'aside', 'big', 'body', 'br', 'caption', 'center', 'cite', 'col', 'colgroup', 'dd',
        'del', 'details', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'font', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'head', 'header', 'hr', 'html', 'img', 'ins', 'kbd', 'label', 'li', 'main', 'mark', 'nav', 'ol', 'p', 'q',
        'samp', 'section', 'small', 'span', 'strike', 'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot',
        'th', 'thead', 'time', 'title', 'tr', 'tt', 'ul', 'var',
    ];

    /** A tag: name and attributes written as name="value", so text such as "a <b and c>" is not a tag. */
    private const TAG_PATTERN = '<\/?[a-zA-Z][a-zA-Z0-9-]*'
        . '(?:\s+[a-zA-Z_:][-a-zA-Z0-9_:.]*\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>\x60]+))*\s*\/?>';

    /** Marker around the number of a code block kept apart until the end of the conversion. */
    private const MARK = "\x1A";

    /**
     * Convert an AI answer to Telegram HTML.
     *
     * Code blocks are converted first and kept apart, so their content, tags included, is shown as written.
     *
     * @param string $content Answer of the AI.
     * @return string Telegram HTML with special characters escaped and tags balanced.
     */
    public static function to_html(string $content): string {
        $blocks = [];
        $keep = static function (string $html) use (&$blocks): string {
            $blocks[] = $html;
            return self::MARK . (count($blocks) - 1) . self::MARK;
        };
        $content = str_replace(self::MARK, '', $content);

        // Markdown code: the content is literal text.
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
        $content = preg_replace_callback('/```([\w+-]*)[^\S\n]*\n?(.*?)```/s', static function (array $m) use ($keep): string {
            return $keep(self::code_block(htmlspecialchars($m[2], ENT_NOQUOTES, 'UTF-8'), $m[1]));
        }, $content) ?? $content;
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
        $content = preg_replace_callback('/``([^`]+)``/', static function (array $m) use ($keep): string {
            return $keep('<pre>' . htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8') . '</pre>');
        }, $content) ?? $content;
        $content = preg_replace_callback('/\x60([^\x60\n]+)\x60/', static function (array $m) use ($keep): string {
            return $keep('<code>' . htmlspecialchars($m[1], ENT_NOQUOTES, 'UTF-8') . '</code>');
        }, $content) ?? $content;

        // HTML tables are not supported by Telegram: they become pipe-delimited <pre> blocks.
        $content = \local_jedomi_sanitize_html_tables($content);

        // HTML code: entities are decoded and the tags inside are shown as text.
        $content = preg_replace_callback('/<pre\b[^>]*>(.*?)<\/pre>/is', static function (array $m) use ($keep): string {
            if (preg_match('/^\s*<code\b([^>]*)>(.*)<\/code>\s*$/is', $m[1], $code)) {
                $language = preg_match('/class\s*=\s*["\']language-([a-zA-Z0-9_+-]+)["\']/i', $code[1], $l) ? $l[1] : '';
                return $keep(self::code_block(self::escape_text($code[2]), $language));
            }
            return $keep('<pre>' . self::escape_text($m[1]) . '</pre>');
        }, $content) ?? $content;
        $content = preg_replace_callback('/<code\b([^>]*)>(.*?)<\/code>/is', static function (array $m) use ($keep): string {
            return $keep(self::opening_tag('code', '<code' . $m[1] . '>') . self::escape_text($m[2]) . '</code>');
        }, $content) ?? $content;

        // Inline and display math delimiters \[...\] and \(...\) become dollar signs.
        $content = preg_replace('/\\\\\[(.*?)\\\\\x5d/s', '$$$$1$$', $content) ?? $content;
        $content = preg_replace('/\\\\\((.*?)\\\\\x29/s', '$$$$1$$', $content) ?? $content;

        // Markdown links.
        $content = preg_replace('/\[([^\]\n]+)\]\(([^)\s]+)\)/', '<a href="$2">$1</a>', $content) ?? $content;

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

        $html = self::escape_and_balance($content);

        // The code blocks go back in their place.
        $restore = static fn(array $m): string => $blocks[(int) $m[1]] ?? '';
        return preg_replace_callback('/' . self::MARK . '(\d+)' . self::MARK . '/', $restore, $html) ?? $html;
    }

    /**
     * A code block, with the language when it is known.
     *
     * @param string $escaped Content of the block, already escaped.
     * @param string $language Language of the code, empty when unknown.
     * @return string
     */
    private static function code_block(string $escaped, string $language): string {
        if ($language !== '' && preg_match('/^[a-zA-Z0-9_+-]+$/', $language)) {
            return '<pre><code class="language-' . $language . '">' . $escaped . '</code></pre>';
        }
        return '<pre>' . $escaped . '</pre>';
    }

    /**
     * Keep the allowed tags (with their allowed attributes only), drop other HTML tags, escape every other
     * <, > and & and close or drop unbalanced tags. Text between < and > that is not an HTML tag is shown as written.
     *
     * A link to another site is followed by its host, such as " (↗ example.com)", so the reader sees where it goes
     * before opening it (JEDOMI-SEC-005).
     *
     * @param string $html Text with HTML tags.
     * @return string
     */
    private static function escape_and_balance(string $html): string {
        $parts = preg_split('/(' . self::TAG_PATTERN . ')/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $output = '';
        $open = [];
        // Text written after each open tag when it closes: the host of an external link, empty otherwise.
        $after = [];
        $close = static function () use (&$open, &$after): string {
            return '</' . array_pop($open) . '>' . array_pop($after);
        };
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
                // Other HTML tags are removed, their text is kept; anything else is text.
                if (!in_array($tag, self::KNOWN_HTML, true)) {
                    $output .= self::escape_text($part);
                }
                continue;
            }
            if ($closing) {
                $position = array_search($tag, $open, true);
                if ($position === false) {
                    continue;
                }
                // Close the tags opened inside it, then the tag itself.
                while (count($open) > $position) {
                    $output .= $close();
                }
                continue;
            }
            $opening = self::opening_tag($tag, $part);
            if ($opening === null) {
                continue;
            }
            $output .= $opening;
            $open[] = $tag;
            $after[] = $tag === 'a' ? self::external_host_note($opening) : '';
        }
        while ($open) {
            $output .= $close();
        }
        return $output;
    }

    /**
     * Note shown after a link to another site: its host.
     *
     * @param string $opening Opening tag of the link as written in the output, with its escaped href.
     * @return string " (↗ host)" escaped, or empty for a link to the site, a relative link or another scheme.
     */
    private static function external_host_note(string $opening): string {
        if (!preg_match('/href="([^"]*)"/', $opening, $m)) {
            return '';
        }
        $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $host = \local_jedomi\local\external_link::host($href);
        if (!\local_jedomi\local\external_link::is_external($href) || $host === '') {
            return '';
        }
        return ' (↗ ' . htmlspecialchars($host, ENT_NOQUOTES, 'UTF-8') . ')';
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
