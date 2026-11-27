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

namespace local_jedomi\whatsapp;

/**
 * Formatter of the WhatsApp channel.
 *
 * Markdown and HTML of the answers become WhatsApp formatting: *bold*, _italic_, ~strikethrough~ and ```monospace```;
 * headings become bold lines, list items start with a bullet and links are written as plain addresses. Format
 * characters that are not formatting (a "5 * 3" of the text, or the content of a user) become look-alike characters,
 * so they never format the message; inside a word (snake_case, 2*3), in addresses and in code they are kept. Long
 * texts are split by paragraph, then by line, then by word.
 *
 * WhatsApp-style formatting written by the AI (*bold*, _italic_, ~strikethrough~) is kept, as the prompt of the channel
 * asks for it.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class formatter {
    /** @var string Placeholder of a bold marker while the text is converted. */
    private const BOLD = "\u{E000}";

    /** @var string Placeholder of an italic marker while the text is converted. */
    private const ITALIC = "\u{E001}";

    /** @var string Placeholder of a strikethrough marker while the text is converted. */
    private const STRIKE = "\u{E002}";

    /** @var string Start of the placeholder of a protected segment (code or address). */
    private const OPEN = "\u{E010}";

    /** @var string End of the placeholder of a protected segment. */
    private const CLOSE = "\u{E011}";

    /** @var string Private use characters of the placeholders, removed from any input. */
    private const PRIVATE_USE = '/[\x{E000}-\x{E011}]/u';

    /** @var string Web address. */
    private const URL = '~https?://[^\s<>"\x{E000}-\x{E011}]+~iu';

    /** @var array<string, string> Look-alike of each format character, which WhatsApp does not read as formatting. */
    private const LOOKALIKES = ['*' => '∗', '_' => '＿', '~' => '∼', "\u{60}" => 'ˋ'];

    /** @var string Monospace marker of WhatsApp: three backticks. */
    public const MONOSPACE = "\u{60}\u{60}\u{60}";

    /** @var string[] Separators of the split of a long text, from the widest: paragraph, line, word. */
    private const SEPARATORS = ["\n\n", "\n", ' '];

    /**
     * Convert an answer written in Markdown or HTML into WhatsApp formatting.
     *
     * @param string $text Answer.
     * @return string WhatsApp text.
     */
    public static function to_whatsapp(string $text): string {
        $protected = [];
        $text = self::clean_input($text);

        // Code first: its content is never converted nor escaped.
        $text = self::replace(
            '/\x60{3}(?:[A-Za-z0-9_+.#-]*\n)?(.*?)\x60{3}/su',
            $text,
            static function (array $m) use (&$protected): string {
                return self::code($m[1], $protected);
            }
        );
        $text = self::replace(
            '~<pre\b[^>]*>(.*?)</pre>~isu',
            $text,
            static function (array $m) use (&$protected): string {
                return self::code(self::html_text($m[1]), $protected);
            }
        );
        $text = self::replace(
            '~<code\b[^>]*>(.*?)</code>~isu',
            $text,
            static function (array $m) use (&$protected): string {
                return self::code(self::html_text($m[1]), $protected);
            }
        );
        $text = self::replace(
            '/\x60([^\x60\n]+)\x60/u',
            $text,
            static function (array $m) use (&$protected): string {
                return self::code($m[1], $protected);
            }
        );

        // Links become their text and their plain address.
        $text = self::replace(
            '~<a\s[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~isu',
            $text,
            static function (array $m) use (&$protected): string {
                $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return self::link(self::strip_tags($m[3]), $url, $protected);
            }
        );
        $text = self::replace(
            '/\[([^\]\n]+)\]\(([^\s()]+(?:\([^\s()]*\))?[^\s()]*)\)/u',
            $text,
            static function (array $m) use (&$protected): string {
                return self::link($m[1], $m[2], $protected);
            }
        );
        $text = self::protect_urls($text, $protected);

        // HTML structure and formatting.
        $text = self::replace(
            '~<h[1-6]\b[^>]*>(.*?)</h[1-6]>~isu',
            $text,
            static fn(array $m): string => self::BOLD . trim($m[1]) . self::BOLD . "\n"
        );
        $text = preg_replace('~<br\s*/?>~iu', "\n", $text);
        $text = preg_replace('~</(?:p|div)\s*>~iu', "\n\n", $text);
        $text = preg_replace('~</(?:ul|ol|tr|table)\s*>~iu', "\n", $text);
        $text = preg_replace('~<li\b[^>]*>~iu', "\n• ", $text);
        $text = preg_replace('~</?(?:b|strong)\b[^>]*>~iu', self::BOLD, $text);
        $text = preg_replace('~</?(?:i|em)\b[^>]*>~iu', self::ITALIC, $text);
        $text = preg_replace('~</?(?:s|del|strike)\b[^>]*>~iu', self::STRIKE, $text);
        $text = self::strip_tags($text);
        // Entities of the placeholder characters are dropped before decoding, so no input can forge a placeholder.
        $text = preg_replace('/&#(?:x0*e0[01][0-9a-f]|0*(?:5734[4-9]|5735\d|5736[01]));/iu', '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Markdown blocks.
        $text = preg_replace('/^[ \t]*#{1,6}[ \t]+(.+?)[ \t]*#*[ \t]*$/mu', self::BOLD . '$1' . self::BOLD, $text);
        $text = preg_replace('/^[ \t]*([-*_])(?:[ \t]*\1){2,}[ \t]*$/mu', '', $text);
        $text = preg_replace('/^([ \t]*)[-*+][ \t]+/mu', '$1• ', $text);

        // Markdown and WhatsApp inline formatting.
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/su', self::BOLD . '$1' . self::BOLD, $text);
        $text = preg_replace(
            '/(?<![\p{L}\p{N}_])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\p{N}_])/su',
            self::BOLD . '$1' . self::BOLD,
            $text
        );
        $text = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/su', self::STRIKE . '$1' . self::STRIKE, $text);
        foreach (['*' => self::BOLD, '_' => self::ITALIC, '~' => self::STRIKE] as $marker => $placeholder) {
            $m = preg_quote($marker, '/');
            $text = preg_replace(
                '/(?<![\p{L}\p{N}' . $m . '])' . $m . '(?![\s' . $m . '])([^' . $m . '\n]+?)(?<![\s' . $m . '])' . $m
                    . '(?![\p{L}\p{N}' . $m . '])/u',
                $placeholder . '$1' . $placeholder,
                $text
            );
        }

        $text = self::escape_markers($text);
        $text = strtr($text, [self::BOLD => '*', self::ITALIC => '_', self::STRIKE => '~']);
        $text = self::restore($text, $protected);

        $text = preg_replace('/[ \t]+$/mu', '', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }

    /**
     * Neutralise the format characters of a text written by a user, so it cannot format the message it is put in.
     * Characters inside a word and in web addresses are kept.
     *
     * @param string $text Text of a user.
     * @return string
     */
    public static function escape(string $text): string {
        $protected = [];
        $text = self::protect_urls(self::clean_input($text), $protected);
        return self::restore(self::escape_markers($text), $protected);
    }

    /**
     * Split a text into parts of at most $limit characters: by paragraph, then by line, then by word, and by character
     * only for a word longer than the limit. Paragraphs that fit together share a part.
     *
     * @param string $text Text.
     * @param int $limit Characters of a part.
     * @return string[] Parts; none for a blank text.
     */
    public static function chunk(string $text, int $limit): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        return self::split($text, max(1, $limit), 0);
    }

    /**
     * Split a text at one separator level, packing the pieces into parts within the limit.
     *
     * @param string $text Text.
     * @param int $limit Characters of a part.
     * @param int $level Index in SEPARATORS; past the last one, the text is cut by character.
     * @return string[]
     */
    private static function split(string $text, int $limit, int $level): array {
        if (\core_text::strlen($text) <= $limit) {
            return [$text];
        }
        if ($level >= count(self::SEPARATORS)) {
            $parts = [];
            for ($start = 0, $length = \core_text::strlen($text); $start < $length; $start += $limit) {
                $parts[] = \core_text::substr($text, $start, $limit);
            }
            return $parts;
        }
        $separator = self::SEPARATORS[$level];
        $segments = $level === 0 ? preg_split('/\n{2,}/u', $text) : explode($separator, $text);
        $parts = [];
        $current = '';
        foreach ($segments as $segment) {
            $segment = $level < 2 ? trim($segment) : $segment;
            if ($segment === '') {
                continue;
            }
            foreach (self::split($segment, $limit, $level + 1) as $piece) {
                $candidate = $current === '' ? $piece : $current . $separator . $piece;
                if (\core_text::strlen($candidate) <= $limit) {
                    $current = $candidate;
                    continue;
                }
                $parts[] = $current;
                $current = $piece;
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    /**
     * Run a replacement callback; the text is kept when the pattern fails.
     *
     * @param string $pattern Pattern.
     * @param string $text Text.
     * @param callable $callback Callback.
     * @return string
     */
    private static function replace(string $pattern, string $text, callable $callback): string {
        return preg_replace_callback($pattern, $callback, $text) ?? $text;
    }

    /**
     * Valid UTF-8 with Unix line breaks and without the private use characters of the placeholders.
     *
     * @param string $text Text.
     * @return string
     */
    private static function clean_input(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", fix_utf8($text));
        return preg_replace(self::PRIVATE_USE, '', $text) ?? '';
    }

    /**
     * Remove the HTML tags of a text; a "<" that does not start a tag is kept.
     *
     * @param string $text Text.
     * @return string
     */
    private static function strip_tags(string $text): string {
        $text = preg_replace('/<!--.*?-->/su', '', $text) ?? $text;
        return preg_replace('~</?[a-z][a-z0-9]*(?:\s[^<>]*)?/?>~iu', '', $text) ?? $text;
    }

    /**
     * Text of an HTML fragment: without tags and with its entities decoded.
     *
     * @param string $html HTML.
     * @return string
     */
    private static function html_text(string $html): string {
        return html_entity_decode(self::strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Placeholder of a monospace block.
     *
     * @param string $code Code.
     * @param string[] $protected Protected segments; the block is added.
     * @return string
     */
    private static function code(string $code, array &$protected): string {
        $code = trim(self::clean_input($code), "\n");
        if (trim($code) === '') {
            return '';
        }
        return self::protect(self::MONOSPACE . str_replace(self::MONOSPACE, '', $code) . self::MONOSPACE, $protected);
    }

    /**
     * A link as its text and plain address; only web addresses are kept.
     *
     * @param string $text Text of the link.
     * @param string $url Address.
     * @param string[] $protected Protected segments; the address is added.
     * @return string
     */
    private static function link(string $text, string $url, array &$protected): string {
        $text = trim($text);
        $url = trim($url);
        if (preg_match('~^https?://[^\s<>"]+$~iu', $url) !== 1) {
            return $text;
        }
        if ($text === '' || $text === $url) {
            return self::protect($url, $protected);
        }
        return $text . ' (' . self::protect($url, $protected) . ')';
    }

    /**
     * Protect the web addresses of a text, without the punctuation that ends a sentence after them.
     *
     * @param string $text Text.
     * @param string[] $protected Protected segments; the addresses are added.
     * @return string
     */
    private static function protect_urls(string $text, array &$protected): string {
        return self::replace(self::URL, $text, static function (array $m) use (&$protected): string {
            $url = $m[0];
            $trailing = '';
            while ($url !== '') {
                $last = substr($url, -1);
                $unbalanced = $last === ')' && substr_count($url, '(') < substr_count($url, ')');
                if (strpos('.,;:!?', $last) === false && !$unbalanced) {
                    break;
                }
                $trailing = $last . $trailing;
                $url = substr($url, 0, -1);
            }
            return self::protect($url, $protected) . $trailing;
        });
    }

    /**
     * Placeholder of a protected segment.
     *
     * @param string $segment Segment kept as it is.
     * @param string[] $protected Protected segments; the segment is added.
     * @return string
     */
    private static function protect(string $segment, array &$protected): string {
        $protected[] = $segment;
        return self::OPEN . (count($protected) - 1) . self::CLOSE;
    }

    /**
     * Put the protected segments back.
     *
     * @param string $text Text with placeholders.
     * @param string[] $protected Protected segments.
     * @return string
     */
    private static function restore(string $text, array $protected): string {
        return self::replace(
            '/' . self::OPEN . '(\d+)' . self::CLOSE . '/u',
            $text,
            static fn(array $m): string => $protected[(int) $m[1]] ?? ''
        );
    }

    /**
     * Replace the format characters that could format the message with look-alikes; a character between two letters
     * or digits cannot, and is kept.
     *
     * @param string $text Text.
     * @return string
     */
    private static function escape_markers(string $text): string {
        return self::replace(
            '/(?<![\p{L}\p{N}])[*_~\x60]|[*_~\x60](?![\p{L}\p{N}])/u',
            $text,
            static fn(array $m): string => self::LOOKALIKES[$m[0]]
        );
    }
}
