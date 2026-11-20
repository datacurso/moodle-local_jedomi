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

namespace local_jedomi\local;

/**
 * Applies the anonymisation level of the Privacy tab to the data the plugin stores or sends to the AI.
 *
 * - Basic: names, e-mails and identifier columns (user name, ID number, phones, address, IP, messaging ids, web
 *   page) are replaced.
 * - Strict: free text and codes mixing letters and digits inside texts are replaced as well.
 * - Feedback only: no conversation text is stored; what is sent to the AI is anonymised as with Basic.
 *
 * Every replaced value gets a stable placeholder within one instance, so the answer of the AI can be turned back
 * into real data before showing it to the user ({@see self::restore()}).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class anonymizer {
    /** @var string Level: names, e-mails and identifier columns. */
    public const LEVEL_BASIC = 'basic';

    /** @var string Level: names, e-mails, identifiers and free text. */
    public const LEVEL_STRICT = 'strict';

    /** @var string Level: only ratings are stored. */
    public const LEVEL_FEEDBACK = 'feedback';

    /** @var string[] Columns that hold names of people. */
    private const NAME_COLUMNS = ['firstname', 'lastname', 'middlename', 'alternatename', 'firstnamephonetic',
        'lastnamephonetic', 'userfullname', 'username_full', 'studentname', 'teachername', 'authorname'];

    /** @var string[] Columns that identify people (every level, Decision 6). */
    private const IDENTIFIER_COLUMNS = ['username', 'idnumber', 'phone1', 'phone2', 'address', 'lastip', 'ip',
        'skype', 'icq', 'msn', 'aim', 'yahoo', 'url'];

    /** @var string[] Columns that hold free text (Strict level). */
    private const FREE_TEXT_COLUMNS = ['description', 'message', 'fullmessage', 'smallmessage', 'text', 'content',
        'comment', 'comments', 'feedback', 'feedbacktext', 'onlinetext', 'intro', 'summary', 'answer', 'response',
        'messagetext', 'post', 'body', 'subject'];

    /** @var int Strings longer than this with spaces are free text at the Strict level. */
    private const FREE_TEXT_LENGTH = 80;

    /** @var int Most capitalised words looked up as possible names in one text. */
    private const MAX_NAME_CANDIDATES = 200;

    /**
     * @var string Pattern of a placeholder as the AI may write it back: another case, a space or a hyphen instead of the
     *      underscore, spaces inside the brackets or Markdown escapes ("[Person 1]", "[person-1]", "\[person\_1\]").
     */
    private const LOOSE_PLACEHOLDER_PATTERN = '/\\\\?\[\s*([a-z]+)\s*(?:\\\\?[_\- ])?\s*(\d+)\s*\\\\?\]/i';

    /**
     * @var string Pattern of a placeholder exactly as this anonymiser writes it, the only form restored in a query, after
     *      no backslash (group 1 is empty, group 2 the placeholder).
     */
    private const SQL_PLACEHOLDER_PATTERN = '/(?<!\\\\)()(\[[a-z]+_\d+\])/';

    /**
     * @var string The same inside a string of the MySQL family, where it may follow backslashes that pair up as escapes
     *      (group 1); after an odd number the last backslash escapes the bracket and nothing matches.
     */
    private const SQL_ESCAPED_PLACEHOLDER_PATTERN = '/(?<!\\\\)((?:\\\\\\\\)*)(\[[a-z]+_\d+\])/';

    /**
     * @var string The same inside a quoted identifier, or a string of a database that reads no backslash escapes.
     */
    private const SQL_LITERAL_PLACEHOLDER_PATTERN = '/()(\[[a-z]+_\d+\])/';

    /**
     * @var string Pattern of a web address in a text (group 1): http or https with any slashes, two slashes or backslashes
     *      (read by browsers as another host), or a domain name followed by a path, which Telegram makes a link too. It
     *      runs to the next space, "<" or ">", so a placeholder written in the address is always inside it.
     */
    private const URL_PATTERN = '~((?:https?:|[/\\\\]{2})[^\s<>]+|(?<![\w./@\\\\-])(?:[a-z0-9-]+\.)+[a-z]{2,}(?::\d+)?/[^\s<>]*)~i';

    /** @var int Shortest real value of the map replaced in the texts of a conversation (anonymize_history_text()). */
    private const MIN_KNOWN_VALUE_LENGTH = 3;

    /** @var string Applied level. */
    private string $level;

    /** @var array<string, string> Placeholder => real value. */
    private array $originals = [];

    /** @var array<string, string> Kind and real value => placeholder. */
    private array $placeholders = [];

    /** @var array<string, int> Placeholders created per kind. */
    private array $counters = [];

    /**
     * Create an anonymiser for a level.
     *
     * @param string|null $level Level; null reads the one saved in the Privacy tab (Basic when unset).
     */
    public function __construct(?string $level = null) {
        $this->level = $level ?? self::configured_level();
    }

    /**
     * Level saved in the Privacy tab, with the older value of "Feedback only" mapped to the current one.
     *
     * @return string One of the LEVEL_* constants.
     */
    public static function configured_level(): string {
        $level = (string) get_config('local_jedomi', 'anonymization_level');
        if ($level === 'feedback_only') {
            return self::LEVEL_FEEDBACK;
        }
        return in_array($level, [self::LEVEL_STRICT, self::LEVEL_FEEDBACK], true) ? $level : self::LEVEL_BASIC;
    }

    /**
     * Applied level.
     *
     * @return string
     */
    public function get_level(): string {
        return $this->level;
    }

    /**
     * Anonymise a free text that the plugin stores.
     *
     * @param string $text Text.
     * @return string Text with names and e-mails replaced; at the Strict level the whole free text is replaced;
     *                at the Feedback only level nothing is kept.
     */
    public function anonymize_stored_text(string $text): string {
        if ($this->level === self::LEVEL_FEEDBACK) {
            return '';
        }
        if ($this->level === self::LEVEL_STRICT && trim($text) !== '') {
            return $this->placeholder('text', $text);
        }
        return $this->anonymize_string($text);
    }

    /**
     * Anonymise a text that leaves the site as it is, such as an answer read aloud.
     *
     * Unlike {@see self::anonymize_stored_text()} the text is kept readable at every level: e-mails and names of
     * site users are replaced and, at the Strict level, codes mixing letters and digits too. The Feedback only
     * level replaces what the Basic level does, as for the data sent to the AI.
     *
     * @param string $text Text.
     * @return string Text with placeholders such as "[person_1]" in place of the personal data.
     */
    public function anonymize_text(string $text): string {
        return $this->anonymize_string($text);
    }

    /**
     * Anonymise the text of the user and assistant messages of a conversation before the AI receives them.
     *
     * The text of each message, and each text part of a message with several parts, is kept readable as with
     * {@see self::anonymize_text()}: e-mails and names of site users are replaced and, at the Strict level, codes
     * mixing letters and digits too. The placeholders are the ones of this instance, so the arguments the AI proposes
     * and its answer can be turned back into real data with {@see self::restore()}. The system message, tool messages,
     * image parts and any other part are kept as they are.
     *
     * @param array $messages Chat messages, each with a role and a content.
     * @return array The same messages, in the same order.
     */
    public function anonymize_messages(array $messages): array {
        // Texts written by people (an attached document, a pasted export) can already hold placeholder-shaped tokens.
        // They are escaped in every message before any placeholder is created, so none of them can be taken for a
        // placeholder created later in this call and be "restored" into a real value it never stood for.
        $messages = $this->map_message_texts($messages, fn(string $text): string => $this->escape_foreign_placeholders($text));
        return $this->map_message_texts($messages, fn(string $text): string => $this->anonymize_history_text($text));
    }

    /**
     * Anonymise a text of the conversation, such as a previous answer, before the AI receives it again.
     *
     * Besides what {@see self::anonymize_text()} replaces, every real value already in the map of this anonymiser (an
     * identifier of a tool result shown in a previous answer, at any level) gets its placeholder back, so no identifier
     * the user was shown leaves the site in a later turn. Only values already in the map are replaced: no new pattern
     * is looked for.
     *
     * @param string $text Text.
     * @return string
     */
    public function anonymize_history_text(string $text): string {
        return $this->anonymize_string($this->replace_known_values($text));
    }

    /**
     * Replace the real values already in the map by their placeholders, as whole words, longest first.
     *
     * @param string $text Text.
     * @return string
     */
    private function replace_known_values(string $text): string {
        $values = [];
        foreach ($this->originals as $placeholder => $value) {
            // A literal stands for a placeholder-shaped token, already escaped; very short values would match any text.
            if (strpos($placeholder, '[literal_') === 0 || \core_text::strlen($value) < self::MIN_KNOWN_VALUE_LENGTH) {
                continue;
            }
            if (strpos($text, $value) !== false) {
                $values[$value] = $placeholder;
            }
        }
        if (empty($values)) {
            return $text;
        }
        uksort($values, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $alternatives = implode('|', array_map(static fn(string $v): string => preg_quote($v, '/'), array_keys($values)));
        // One pass, so a placeholder written in place of a value is never matched by another value.
        $replaced = preg_replace_callback(
            '/(?<![\p{L}\d_\[])(?:' . $alternatives . ')(?![\p{L}\d_\]])/u',
            static fn(array $m): string => $values[$m[0]],
            $text
        );
        return $replaced ?? $text;
    }

    /**
     * Apply a function to the text of the user and assistant messages and to each text part of their contents.
     *
     * @param array $messages Chat messages.
     * @param callable $map Function from text to text.
     * @return array
     */
    private function map_message_texts(array $messages, callable $map): array {
        foreach ($messages as $index => $message) {
            if (!is_array($message) || !in_array($message['role'] ?? '', ['user', 'assistant'], true)) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (is_string($content)) {
                $messages[$index]['content'] = $map($content);
                continue;
            }
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $key => $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                    $messages[$index]['content'][$key]['text'] = $map($part['text']);
                }
            }
        }
        return $messages;
    }

    /**
     * Anonymise the result of a tool before the AI receives it.
     *
     * JSON results are walked value by value, using the column names to recognise names, identifiers and free
     * text (nested JSON strings, such as database rows, included); any other result is treated as text.
     *
     * @param string $result Tool result.
     * @return string Anonymised result.
     */
    public function anonymize_tool_result(string $result): string {
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return $this->anonymize_string($result);
        }
        return (string) json_encode($this->anonymize_value($decoded, ''), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Put the real values back in a text written from anonymised data (the answer shown to the user).
     *
     * A web address of another site keeps its placeholders (JEDOMI-SEC-005): content the AI read may ask it for a link
     * that carries data of the conversation, and the real values must not leave the site when the link is opened.
     * Addresses of the site and the rest of the text get the real values. The whole destination of a Markdown link or an
     * href attribute counts as the address, and any scheme other than http or https (mailto:, tg:...) counts as external.
     *
     * @param string $text Text with placeholders.
     * @return string
     */
    public function restore(string $text): string {
        if (empty($this->originals) || strpos($text, '[') === false) {
            return $text;
        }
        // The whole destination of a Markdown link (to the closing ")", across spaces, "<" and new lines) and of an
        // href attribute is one address, as are the web addresses of the text.
        $pattern = '~\]\(\K(?:\([^)]*\)|[^)])*|\bhref\s*=\s*"\K[^"]*|\bhref\s*=\s*\'\K[^\']*|'
            . substr(self::URL_PATTERN, 1, -2) . '~i';
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            // Not split: nothing is restored rather than risking a real value in an external address.
            return $text;
        }
        $same = static fn(string $value): string => $value;
        $restored = '';
        $offset = 0;
        foreach ($matches[0] as [$address, $start]) {
            $restored .= $this->replace_placeholders(substr($text, $offset, $start - $offset), $same);
            $restored .= self::address_leaves_site($address) ? $address : $this->replace_placeholders($address, $same);
            $offset = $start + strlen($address);
        }
        return $restored . $this->replace_placeholders(substr($text, $offset), $same);
    }

    /**
     * Whether an address found in a text leaves the site. A web address written in a link text runs into the
     * destination ("[https://site/x](https://attacker.example/?n=...)"), so every destination inside it counts too.
     *
     * @param string $address Address found by restore().
     * @return bool
     */
    private static function address_leaves_site(string $address): bool {
        foreach (explode('](', $address) as $address) {
            if (external_link::leaves_site($address)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replace the placeholders of this anonymiser in a text by their real values passed through a function.
     *
     * One pass, so a restored value that looks like a placeholder is never replaced again. A placeholder the AI wrote
     * slightly differently ("[Person 1]") still gets its real value; a token that matches no placeholder of this
     * anonymiser is kept as written.
     *
     * @param string $text Text with placeholders.
     * @param callable $format Function from the real value to the text written in place of the placeholder.
     * @return string
     */
    private function replace_placeholders(string $text, callable $format): string {
        return (string) preg_replace_callback(
            self::LOOSE_PLACEHOLDER_PATTERN,
            function (array $m) use ($format): string {
                $placeholder = self::canonical_placeholder($m[1], $m[2]);
                return isset($this->originals[$placeholder]) ? $format($this->originals[$placeholder]) : $m[0];
            },
            $text
        );
    }

    /**
     * Put the real values back in the arguments of a tool call proposed by the AI, before the tool runs or is held.
     *
     * The query of the SQL tool (its sql or query argument) gets the values escaped for the place of each placeholder
     * ({@see self::restore_sql()}); every other argument, passed to the tool as a structured value, gets the raw value
     * ({@see self::restore_value()}). Shared by every channel that runs tools: web chat, Telegram, agents and preview.
     *
     * @param string $tool Tool name.
     * @param mixed $args Arguments as proposed by the AI (an array; anything else gets the raw restore).
     * @param string|null $family Database family; null for the one of the site.
     * @return mixed Arguments with the real values.
     * @throws unrestored_placeholder_exception When the query of the SQL tool holds a placeholder that cannot be put back:
     *     the channel sends its tool_result() to the AI as the result of the call and does not run the tool (RISK-001).
     */
    public function restore_tool_args(string $tool, $args, ?string $family = null) {
        if (!is_array($args)) {
            return $this->restore_value($args);
        }
        $sqlkeys = $tool === 'execute_sql' ? ['sql', 'query'] : [];
        foreach ($args as $key => $value) {
            $args[$key] = in_array($key, $sqlkeys, true) && is_string($value)
                ? $this->restore_sql($value, $family)
                : $this->restore_value($value);
        }
        return $args;
    }

    /**
     * Put the real values back in a query written by the AI, escaped so that no value can change the query (REL-004).
     *
     * The query is split as sql_guard reads it. A placeholder inside a quoted string or identifier gets the value with
     * that quote doubled, and with backslashes doubled when the database reads them as escapes (the MySQL family);
     * outside, it gets the value as a quoted string ('...'), so "WHERE address = [id_1]" also works. Placeholders inside
     * comments are left as they are. Only placeholders written exactly as this anonymiser writes them are restored, never
     * after a backslash outside a string, and inside a string of the MySQL family only after backslashes that pair up,
     * so a value never takes the second backslash of an escape pair; a restored query that does not split into the same
     * strings is returned unchanged. When a quote or a comment start is left without its pair, the query is returned
     * unchanged, since where its strings end cannot be known. Values the guard of the query refuses anyway (a "?" that
     * would shift the bound parameters, a backslash outside MySQL) make the query be refused, never run differently.
     *
     * A placeholder of this anonymiser that cannot be put back (another case, spaces or a hyphen, Markdown escapes, an odd
     * backslash before it in a MySQL string, or anywhere in a query returned unchanged) makes the query be refused with
     * {@see unrestored_placeholder_exception} (RISK-001): run with the text of the token, the query would give a false
     * result. Tokens that are not placeholders of this anonymiser are text and stay as written.
     *
     * @param string $sql Query with placeholders.
     * @param string|null $family Database family, as moodle_database::get_dbfamily() returns it; null for the site one.
     * @return string Query with the real values.
     * @throws unrestored_placeholder_exception When a placeholder of this anonymiser is left in the code or a string.
     */
    public function restore_sql(string $sql, ?string $family = null): string {
        global $DB;
        if (empty($this->originals) || strpos($sql, '[') === false) {
            return $sql;
        }
        $family = $family ?? $DB->get_dbfamily();
        $backslash = $family === 'mysql';
        $pattern = sql_guard::quoted_parts_pattern($family);
        $parts = preg_split($pattern, $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $this->unchanged_sql($sql);
        }
        $expected = count($parts);
        // Placeholders of this anonymiser in any form outside comments, and those put back.
        $written = [];
        $restored = 0;
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                if (strpbrk($part, "'\"\x60") !== false || strpos($part, '/*') !== false) {
                    return $this->unchanged_sql($sql);
                }
                $written = array_merge($written, $this->placeholders_written_in($part));
                $parts[$index] = $this->replace_sql_placeholders(
                    self::SQL_PLACEHOLDER_PATTERN,
                    $part,
                    static fn(string $value): string => "'" . self::escape_sql_text($value, "'", $backslash) . "'",
                    $count
                );
                $restored += $count;
                // Each value written outside a string adds a string and the code after it.
                $expected += 2 * $count;
                continue;
            }
            $quote = $part[0];
            if (!in_array($quote, ["'", '"', "\x60"], true)) {
                continue;
            }
            $written = array_merge($written, $this->placeholders_written_in($part));
            $escapes = $backslash && $quote !== "\x60";
            $parts[$index] = $quote . $this->replace_sql_placeholders(
                $escapes ? self::SQL_ESCAPED_PLACEHOLDER_PATTERN : self::SQL_LITERAL_PLACEHOLDER_PATTERN,
                substr($part, 1, -1),
                static fn(string $value): string => self::escape_sql_text($value, $quote, $escapes),
                $count
            ) . $quote;
            $restored += $count;
        }
        if ($restored !== count($written)) {
            throw new unrestored_placeholder_exception($written);
        }
        $query = implode('', $parts);
        // Defence in depth: the restored query must split into the same strings and code, or it is not run with values.
        $check = preg_split($pattern, $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        return $check !== false && count($check) === $expected ? $query : $this->unchanged_sql($sql);
    }

    /**
     * A query whose placeholders cannot be put back, returned unchanged when it holds no placeholder of this anonymiser.
     *
     * @param string $sql Query.
     * @return string The query as written.
     * @throws unrestored_placeholder_exception When it holds a placeholder of this anonymiser in any form.
     */
    private function unchanged_sql(string $sql): string {
        $written = $this->placeholders_written_in($sql);
        if (!empty($written)) {
            throw new unrestored_placeholder_exception($written);
        }
        return $sql;
    }

    /**
     * Placeholders of this anonymiser written in a text in any form the AI may use ("[id_1]", "[ID 1]", "\[id\_1\]").
     *
     * @param string $text Text.
     * @return string[] Each one as this anonymiser writes it, once per occurrence.
     */
    private function placeholders_written_in(string $text): array {
        $found = [];
        if (preg_match_all(self::LOOSE_PLACEHOLDER_PATTERN, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $placeholder = self::canonical_placeholder($m[1], $m[2]);
                if (isset($this->originals[$placeholder])) {
                    $found[] = $placeholder;
                }
            }
        }
        return $found;
    }

    /**
     * Replace the exact placeholders of this anonymiser in a part of a query by their real values passed through a
     * function, in one pass, so a restored value that looks like a placeholder is never replaced again.
     *
     * @param string $pattern One of the SQL_*PLACEHOLDER_PATTERN constants.
     * @param string $text Part of a query.
     * @param callable $format Function from the real value to the text written in place of the placeholder.
     * @param int|null $count Set to the number of placeholders replaced.
     * @return string
     */
    private function replace_sql_placeholders(string $pattern, string $text, callable $format, ?int &$count): string {
        $count = 0;
        return (string) preg_replace_callback(
            $pattern,
            function (array $m) use ($format, &$count): string {
                if (!isset($this->originals[$m[2]])) {
                    return $m[0];
                }
                $count++;
                return $m[1] . $format($this->originals[$m[2]]);
            },
            $text
        );
    }

    /**
     * Escape a value for the inside of a quoted SQL string or identifier, with its line breaks written as "\n".
     *
     * @param string $value Value.
     * @param string $quote Quote character of the string or identifier.
     * @param bool $backslash Whether the database reads backslashes as escapes inside it.
     * @return string
     */
    private static function escape_sql_text(string $value, string $quote, bool $backslash): string {
        // A value with a carriage return (an address typed on Windows) would make the guard refuse a correct query.
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if ($backslash) {
            $value = str_replace('\\', '\\\\', $value);
        }
        return str_replace($quote, $quote . $quote, $value);
    }

    /**
     * Placeholder written as this anonymiser writes it, from its kind and number in any form.
     *
     * @param string $kind Kind as written (any case).
     * @param string $number Number.
     * @return string Placeholder such as "[person_1]".
     */
    private static function canonical_placeholder(string $kind, string $number): string {
        return '[' . strtolower($kind) . '_' . (int) $number . ']';
    }

    /**
     * Put the real values back in tool arguments proposed by the AI from anonymised data.
     *
     * @param mixed $value Arguments (array, string or scalar).
     * @return mixed
     */
    public function restore_value($value) {
        if (is_array($value)) {
            return array_map(fn($item) => $this->restore_value($item), $value);
        }
        // Tool arguments run on the site, so a web address in them gets its real values too (unlike the answer).
        if (!is_string($value) || empty($this->originals) || strpos($value, '[') === false) {
            return $value;
        }
        return $this->replace_placeholders($value, static fn(string $v): string => $v);
    }

    /**
     * Anonymise the columns of one database row, recognising names, identifiers and free text by column name.
     *
     * @param array $row Row keyed by column name.
     * @return array Row with the same keys and anonymised values.
     */
    public function anonymize_row(array $row): array {
        return $this->anonymize_value($row, '');
    }

    /**
     * Placeholders created so far with their real values, to keep them across requests of one conversation.
     *
     * @return array<string, string> Placeholder => real value.
     */
    public function export_map(): array {
        return $this->originals;
    }

    /**
     * Anonymiser that continues the placeholders of an earlier request of the same conversation.
     *
     * @param array $map Placeholder => real value, as returned by {@see self::export_map()}.
     * @param string|null $level Level; null reads the one saved in the Privacy tab.
     * @return self
     */
    public static function from_map(array $map, ?string $level = null): self {
        $anonymizer = new self($level);
        foreach ($map as $placeholder => $value) {
            if (!is_string($placeholder) || !preg_match('/^\[([a-z]+)_(\d+)\]$/', $placeholder, $matches)) {
                continue;
            }
            [, $kind, $number] = $matches;
            $anonymizer->originals[$placeholder] = (string) $value;
            $anonymizer->placeholders[$kind . "\0" . $value] = $placeholder;
            $anonymizer->counters[$kind] = max($anonymizer->counters[$kind] ?? 0, (int) $number);
        }
        return $anonymizer;
    }

    /**
     * Anonymise one decoded JSON value.
     *
     * @param mixed $value Value.
     * @param string $column Column or key the value belongs to ('' when unknown).
     * @return mixed
     */
    private function anonymize_value($value, string $column) {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->anonymize_value($item, is_string($key) ? strtolower($key) : $column);
            }
            return $result;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (isset($this->originals[$value])) {
            // Already a placeholder of this anonymiser (data anonymised twice): replacing it again would show the
            // placeholder to the user instead of the real value.
            return $value;
        }
        $trimmed = ltrim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $nested = json_decode($value, true);
            if (is_array($nested)) {
                return (string) json_encode($this->anonymize_value($nested, $column), JSON_UNESCAPED_UNICODE);
            }
        }
        if (in_array($column, self::NAME_COLUMNS, true)) {
            return $this->placeholder('person', $value);
        }
        if ($column === 'email' || substr($column, -5) === 'email') {
            return $this->placeholder('email', $value);
        }
        if (in_array($column, self::IDENTIFIER_COLUMNS, true)) {
            return $this->placeholder('id', $value);
        }
        if ($this->level === self::LEVEL_STRICT) {
            $isfreetext = in_array($column, self::FREE_TEXT_COLUMNS, true)
                || (\core_text::strlen($value) > self::FREE_TEXT_LENGTH && preg_match('/\s/u', $value));
            if ($isfreetext) {
                return $this->placeholder('text', $value);
            }
        }
        return $this->anonymize_string($value);
    }

    /**
     * Replace e-mails, names of site users and, at the Strict level, identifiers inside a text.
     *
     * @param string $text Text.
     * @return string
     */
    private function anonymize_string(string $text): string {
        // The untrusted data markers keep their random id (REL-001): only the text around and between them is
        // anonymised, so the Strict level never turns the id of a marker into a placeholder.
        return strpos($text, '<<<') === false ? $this->anonymize_plain($text) : $this->anonymize_around_markers($text);
    }

    /**
     * Anonymise a text holding untrusted data markers: the markers are kept, the texts between them are anonymised.
     *
     * @param string $text Text.
     * @return string
     */
    private function anonymize_around_markers(string $text): string {
        preg_match_all(untrusted::MARKER_PATTERN, $text, $markers);
        $texts = preg_split(untrusted::MARKER_PATTERN, $text) ?: [$text];
        $result = '';
        foreach ($texts as $index => $part) {
            $result .= $this->anonymize_plain($part) . ($markers[0][$index] ?? '');
        }
        return $result;
    }

    /**
     * Replace e-mails, names of site users and, at the Strict level, identifiers inside a text without markers.
     *
     * @param string $text Text.
     * @return string
     */
    private function anonymize_plain(string $text): string {
        $text = $this->escape_foreign_placeholders($text);
        $text = (string) preg_replace_callback(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            fn(array $m): string => $this->placeholder('email', $m[0]),
            $text
        );

        $names = $this->user_names_in($text);
        if (!empty($names)) {
            $text = (string) preg_replace_callback(
                '/(?<![\p{L}\[_])\p{Lu}[\p{L}\'\-]+/u',
                fn(array $m): string => isset($names[\core_text::strtolower($m[0])]) ? $this->placeholder('person', $m[0])
                : $m[0],
                $text
            );
        }

        if ($this->level === self::LEVEL_STRICT) {
            // Codes mixing letters and digits (student numbers, document ids...); plain numbers are kept.
            $text = (string) preg_replace_callback(
                '/(?<![\p{L}\d_\[])(?=[\p{L}\d\-]*\d)(?=[\d\-]*\p{L})[\p{L}\d][\p{L}\d\-]{2,}/u',
                fn(array $m): string => $this->placeholder('id', $m[0]),
                $text
            );
        }
        return $text;
    }

    /**
     * Replace the placeholder-shaped tokens of an incoming text that this anonymiser did not create.
     *
     * A document or a record can contain a token such as "[person_1]" (an export that was anonymised before). Left as
     * it is, the AI could not tell it from a placeholder of this anonymiser, and the answer would show the real value of
     * that placeholder where the text had the token. Each such token gets its own "[literal_N]" placeholder, so the AI
     * sees a distinct token and the answer gets the original token back. Placeholders created by this anonymiser (or
     * loaded with {@see self::from_map()}) are kept, so anonymising a text twice does not hide them.
     *
     * @param string $text Text.
     * @return string
     */
    private function escape_foreign_placeholders(string $text): string {
        if (strpos($text, '[') === false) {
            return $text;
        }
        // Also the loose forms ("[Person 1]"), which restore() would otherwise turn into the real value of a placeholder.
        return (string) preg_replace_callback(
            self::LOOSE_PLACEHOLDER_PATTERN,
            fn(array $m): string => isset($this->originals[$m[0]]) ? $m[0] : $this->placeholder('literal', $m[0]),
            $text
        );
    }

    /**
     * Capitalised words of a text that are the first or last name of a site user, lowercased, as keys.
     *
     * @param string $text Text.
     * @return array<string, true>
     */
    private function user_names_in(string $text): array {
        global $DB;
        if (!preg_match_all('/(?<![\p{L}\[_])\p{Lu}[\p{L}\'\-]+/u', $text, $matches)) {
            return [];
        }
        $candidates = array_slice(array_values(array_unique($matches[0])), 0, self::MAX_NAME_CANDIDATES);
        [$firstsql, $firstparams] = $DB->get_in_or_equal($candidates, SQL_PARAMS_NAMED, 'fn');
        [$lastsql, $lastparams] = $DB->get_in_or_equal($candidates, SQL_PARAMS_NAMED, 'ln');
        $records = $DB->get_records_sql(
            "SELECT id, firstname, lastname
                                           FROM {user}
                                          WHERE deleted = 0 AND (firstname {$firstsql} OR lastname {$lastsql})",
            $firstparams + $lastparams,
            0,
            500
        );
        $names = [];
        foreach ($records as $record) {
            foreach ([$record->firstname, $record->lastname] as $name) {
                foreach (preg_split('/\s+/u', (string) $name) as $part) {
                    if ($part !== '') {
                        $names[\core_text::strtolower($part)] = true;
                    }
                }
            }
        }
        return $names;
    }

    /**
     * Stable placeholder of a real value.
     *
     * @param string $kind Kind of data (person, email, id, text).
     * @param string $value Real value.
     * @return string Placeholder such as "[person_1]".
     */
    private function placeholder(string $kind, string $value): string {
        $key = $kind . "\0" . $value;
        if (!isset($this->placeholders[$key])) {
            $this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;
            $placeholder = '[' . $kind . '_' . $this->counters[$kind] . ']';
            $this->placeholders[$key] = $placeholder;
            $this->originals[$placeholder] = $value;
        }
        return $this->placeholders[$key];
    }
}
