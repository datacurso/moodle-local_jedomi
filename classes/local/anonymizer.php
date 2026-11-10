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
 * - Basic: names and e-mails are replaced.
 * - Strict: identifiers and free text are replaced as well.
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
    /** @var string Level: names and e-mails. */
    public const LEVEL_BASIC = 'basic';

    /** @var string Level: names, e-mails, identifiers and free text. */
    public const LEVEL_STRICT = 'strict';

    /** @var string Level: only ratings are stored. */
    public const LEVEL_FEEDBACK = 'feedback';

    /** @var string[] Columns that hold names of people. */
    private const NAME_COLUMNS = ['firstname', 'lastname', 'middlename', 'alternatename', 'firstnamephonetic',
        'lastnamephonetic', 'userfullname', 'username_full', 'studentname', 'teachername', 'authorname'];

    /** @var string[] Columns that identify people (Strict level). */
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
     * @param string $text Text with placeholders.
     * @return string
     */
    public function restore(string $text): string {
        if (empty($this->originals)) {
            return $text;
        }
        return strtr($text, $this->originals);
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
        return is_string($value) ? $this->restore($value) : $value;
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
        if ($this->level === self::LEVEL_STRICT) {
            if (in_array($column, self::IDENTIFIER_COLUMNS, true)) {
                return $this->placeholder('id', $value);
            }
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
        $text = (string) preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            fn(array $m): string => $this->placeholder('email', $m[0]), $text);

        $names = $this->user_names_in($text);
        if (!empty($names)) {
            $text = (string) preg_replace_callback('/(?<![\p{L}\[_])\p{Lu}[\p{L}\'\-]+/u',
                fn(array $m): string => isset($names[\core_text::strtolower($m[0])]) ? $this->placeholder('person', $m[0])
                    : $m[0], $text);
        }

        if ($this->level === self::LEVEL_STRICT) {
            // Codes mixing letters and digits (student numbers, document ids...); plain numbers are kept.
            $text = (string) preg_replace_callback('/(?<![\p{L}\d_\[])(?=[\p{L}\d\-]*\d)(?=[\d\-]*\p{L})[\p{L}\d][\p{L}\d\-]{2,}/u',
                fn(array $m): string => $this->placeholder('id', $m[0]), $text);
        }
        return $text;
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
        $records = $DB->get_records_sql("SELECT id, firstname, lastname
                                           FROM {user}
                                          WHERE deleted = 0 AND (firstname {$firstsql} OR lastname {$lastsql})",
            $firstparams + $lastparams, 0, 500);
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
