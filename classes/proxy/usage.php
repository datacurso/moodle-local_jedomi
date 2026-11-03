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

namespace local_jedomi\proxy;

/**
 * Tokens the AI API reports for each request, so jedomi.log accounts for what the chat spends.
 *
 * The proxy asks the OpenAI-compatible endpoint for usage in the stream (stream_options.include_usage); the last
 * chunk then carries prompt_tokens, completion_tokens and total_tokens. Each request is logged as AI_API_USAGE and
 * each run (one student message, with its tool calls) as RUN_USAGE.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage {
    /** @var string[] Token counts kept. */
    private const FIELDS = ['prompt_tokens', 'completion_tokens', 'total_tokens'];

    /**
     * Usage reported in a streamed response (the last chunk that carries it).
     *
     * @param string $buffer Raw SSE response.
     * @return array<string, int>|null Token counts, or null when the response reports none.
     */
    public static function from_sse(string $buffer): ?array {
        $usage = null;
        foreach (explode("\n", $buffer) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }
            $json = json_decode(trim(substr($line, 6)), true);
            if (is_array($json) && is_array($json['usage'] ?? null)) {
                $usage = $json['usage'];
            }
        }
        if ($usage === null) {
            return null;
        }
        $counts = [];
        foreach (self::FIELDS as $field) {
            $counts[$field] = max(0, (int) ($usage[$field] ?? 0));
        }
        return $counts;
    }

    /**
     * Adds the usage of one request to a running total.
     *
     * @param array<string, int> $total Total so far (requests and token counts).
     * @param array<string, int>|null $usage Usage of the request, or null when it reported none.
     * @return array<string, int> New total; requests without usage are counted apart.
     */
    public static function add(array $total, ?array $usage): array {
        $total['requests'] = ($total['requests'] ?? 0) + 1;
        if ($usage === null) {
            $total['without_usage'] = ($total['without_usage'] ?? 0) + 1;
            return $total;
        }
        foreach (self::FIELDS as $field) {
            $total[$field] = ($total[$field] ?? 0) + ($usage[$field] ?? 0);
        }
        return $total;
    }
}
