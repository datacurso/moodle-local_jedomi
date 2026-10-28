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

/**
 * Test double for the Jedomi AI client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

/**
 * AI client that hands out queued answers or exceptions and records every call.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_ai_client extends client {
    /** @var array<int, array|\Throwable> Queued answers (normalised message arrays) or exceptions. */
    public array $queue = [];

    /** @var array<int, array{messages: array, userid: int, tools: array, timeout: ?int, temperature: ?float}> */
    public array $calls = [];

    /**
     * Queue a plain text answer.
     *
     * @param string $content Assistant text.
     */
    public function enqueue_text(string $content): void {
        $this->queue[] = ['content' => $content, 'tool_calls' => [], 'finish_reason' => 'stop'];
    }

    /**
     * Queue an answer that asks for tool calls.
     *
     * @param array $toolcalls OpenAI-format tool calls.
     */
    public function enqueue_tool_calls(array $toolcalls): void {
        $this->queue[] = ['content' => '', 'tool_calls' => $toolcalls, 'finish_reason' => 'tool_calls'];
    }

    /**
     * Queue an exception to be thrown by the next call.
     *
     * @param \Throwable $e Exception to throw.
     */
    public function enqueue_exception(\Throwable $e): void {
        $this->queue[] = $e;
    }

    #[\Override]
    public function complete(
        array $messages,
        int $userid,
        array $tools = [],
        ?int $timeout = null,
        ?float $temperature = null
    ): array {
        $this->calls[] = [
            'messages' => $messages,
            'userid' => $userid,
            'tools' => $tools,
            'timeout' => $timeout,
            'temperature' => $temperature,
        ];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \coding_exception('fake_ai_client: no queued answer');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}
