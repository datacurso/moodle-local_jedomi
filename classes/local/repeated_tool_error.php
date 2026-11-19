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

namespace local_jedomi\local;

/**
 * Counts the same tool error of a question, to stop a model that repeats a call that keeps failing.
 *
 * A call is the key of the loop detector (tool name and normalised arguments); the same call with the same error
 * {@see LIMIT} times in one question stops it, before the loop detector (more than 5 identical calls) would. Only
 * hashes are kept, so the counts can be saved in the Telegram state without personal data.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class repeated_tool_error {
    /** @var int Times the same call may fail with the same error; the last one stops the question. */
    public const LIMIT = 3;

    /** @var array<string, int> Hash of call and error => times seen. */
    private array $counts;

    /**
     * Constructor.
     *
     * @param array $counts Counts saved by {@see export()}; empty for a new question.
     */
    public function __construct(array $counts = []) {
        $this->counts = array_filter($counts, 'is_int');
    }

    /**
     * Count the result of a call.
     *
     * @param string $callkey Key of the call from the loop detector (tool and normalised arguments).
     * @param string $result Result of the tool.
     * @return bool True when the same call has now failed with the same error {@see LIMIT} times.
     */
    public function record(string $callkey, string $result): bool {
        $error = self::error_of($result);
        if ($error === null) {
            return false;
        }
        $key = hash('sha256', $callkey . "\n" . $error);
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        return $this->counts[$key] >= self::LIMIT;
    }

    /**
     * Counts to save with the state of the question.
     *
     * @return array<string, int>
     */
    public function export(): array {
        return $this->counts;
    }

    /**
     * Signature of the arguments of a call, the same whatever the order of their keys: part of the key of a call in the
     * web chat and Telegram.
     *
     * @param array $args Tool arguments.
     * @return string SHA-256 of the arguments with the keys sorted at every level (list items keep their order).
     */
    public static function args_signature(array $args): string {
        return hash('sha256', json_encode(self::sort_keys($args), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
    }

    /**
     * Sort the keys of the associative arrays of a value, at every level.
     *
     * @param mixed $value Value to normalise.
     * @return mixed
     */
    private static function sort_keys(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(static fn($item) => self::sort_keys($item), $value);
    }

    /**
     * Error of a tool result: the "error" of a JSON result, or a text result of a failed run.
     *
     * @param string $result Result of the tool.
     * @return string|null Error, or null when the tool did not fail.
     */
    public static function error_of(string $result): ?string {
        $decoded = json_decode($result, true);
        if (is_array($decoded) && !empty($decoded['error'])) {
            return is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']);
        }
        return str_starts_with($result, 'Error executing ') ? $result : null;
    }
}
