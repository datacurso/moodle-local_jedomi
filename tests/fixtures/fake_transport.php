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
 * In-memory transport used by the AI backend tests.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

use local_jedomi\ai\transport;

/**
 * Records every POST and replays a queue of canned responses.
 */
final class fake_transport implements transport {
    /** @var array<int,array{url:string,headers:array,body:string,timeout:int}> Recorded calls. */
    public array $calls = [];

    /** @var array<int,array> Queue of responses; the last one is repeated once the queue drains. */
    private array $responses;

    /**
     * Constructor.
     *
     * @param array<int,array> $responses Partial responses; missing keys default to a 200 with empty body.
     */
    public function __construct(array $responses = []) {
        $this->responses = array_map([self::class, 'normalise'], $responses);
        if (empty($this->responses)) {
            $this->responses[] = self::normalise([]);
        }
    }

    /**
     * Build a complete transport response from a partial one.
     *
     * @param array $response
     * @return array{code:int,body:string,errno:int,error:string}
     */
    private static function normalise(array $response): array {
        return [
            'code' => (int) ($response['code'] ?? 200),
            'body' => (string) ($response['body'] ?? ''),
            'errno' => (int) ($response['errno'] ?? 0),
            'error' => (string) ($response['error'] ?? ''),
        ];
    }

    /**
     * Build an SSE body out of decoded chunks, terminated by [DONE].
     *
     * @param array<int,array|string> $chunks Arrays are JSON-encoded; strings are emitted verbatim after "data: ".
     * @param bool $done Whether to append the [DONE] sentinel.
     * @return string
     */
    public static function sse(array $chunks, bool $done = true): string {
        $lines = [];
        foreach ($chunks as $chunk) {
            $lines[] = 'data: ' . (is_array($chunk) ? json_encode($chunk, JSON_UNESCAPED_UNICODE) : $chunk);
            $lines[] = '';
        }
        if ($done) {
            $lines[] = 'data: [DONE]';
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    /**
     * Build a single OpenAI-style delta chunk with plain text content.
     *
     * @param string $content
     * @param string|null $finishreason
     * @return array
     */
    public static function text_chunk(string $content, ?string $finishreason = null): array {
        return ['choices' => [['index' => 0, 'delta' => ['content' => $content], 'finish_reason' => $finishreason]]];
    }

    /**
     * Record the call and return the next canned response.
     *
     * @param string $url
     * @param array $headers
     * @param string $body
     * @param int $timeout
     * @return array{code:int,body:string,errno:int,error:string}
     */
    public function post(string $url, array $headers, string $body, int $timeout): array {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        if (count($this->responses) > 1) {
            return array_shift($this->responses);
        }
        return $this->responses[0];
    }

    /**
     * Last recorded call, or null when nothing was posted.
     *
     * @return array|null
     */
    public function last_call(): ?array {
        return empty($this->calls) ? null : $this->calls[count($this->calls) - 1];
    }

    /**
     * JSON-decoded body of the last call.
     *
     * @return array
     */
    public function last_payload(): array {
        $call = $this->last_call();
        return $call === null ? [] : (json_decode($call['body'], true) ?? []);
    }
}
