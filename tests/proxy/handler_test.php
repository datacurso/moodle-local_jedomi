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

namespace local_jedomi\proxy;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\ai\fake_curl;
use local_jedomi\ai\provider_api;
use local_jedomi\ai\testable_provider_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for the web chat proxy handler over the AI client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\proxy\handler
 */
final class handler_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Run a callable and return everything it echoed, including flushed output.
     *
     * @param callable $fn Code to run.
     * @return string
     */
    private function capture(callable $fn): string {
        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;
            return '';
        });
        try {
            $fn();
        } finally {
            ob_end_flush();
        }
        return $captured;
    }

    /**
     * Decode the SSE data lines of a captured stream.
     *
     * @param string $output Captured output.
     * @return array<int, array|string> Decoded payloads; "[DONE]" is kept as a string.
     */
    private function events(string $output): array {
        $events = [];
        foreach (explode("\n", $output) as $line) {
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }
            $raw = substr($line, 6);
            $events[] = $raw === '[DONE]' ? $raw : json_decode($raw, true);
        }
        return $events;
    }

    /**
     * M3: the browser gets the tool events, the answer in SSE chunks and [DONE].
     */
    public function test_run_streams_tool_events_text_chunks_and_done(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'no_such_tool', 'arguments' => '{"a":1}']],
        ]);
        $answer = 'This is the final answer, long enough to be split into several chunks.';
        $fake->enqueue_text($answer);
        client::set_instance_for_testing($fake);

        $output = $this->capture(fn() => handler::run(
            [['role' => 'user', 'content' => 'hi']],
            'admin',
            (int) get_admin()->id
        ));

        $events = $this->events($output);
        $this->assertSame(['tool' => 'no_such_tool', 'args' => ['a' => 1]], $events[0]['_jedomi_tool_executing']);
        $this->assertSame('no_such_tool', $events[1]['_jedomi_tool_result']['tool']);
        $chunks = array_filter($events, static fn($e) => is_array($e) && isset($e['choices']));
        $this->assertGreaterThan(1, count($chunks));
        $text = implode('', array_map(static fn($e) => $e['choices'][0]['delta']['content'], $chunks));
        $this->assertSame($answer, $text);
        $this->assertSame('[DONE]', end($events));

        // The second call carries the assistant tool call followed by its tool result.
        $this->assertCount(2, $fake->calls);
        $second = $fake->calls[1]['messages'];
        $this->assertSame('assistant', $second[1]['role']);
        $this->assertSame('call_1', $second[1]['tool_calls'][0]['id']);
        $this->assertSame('tool', $second[2]['role']);
        $this->assertSame('call_1', $second[2]['tool_call_id']);
        $this->assertSame((int) get_admin()->id, $fake->calls[0]['userid']);
    }

    /**
     * M3: an AI error reaches the browser as an {"error":{code,message}} event with the translated message.
     */
    public function test_run_sends_error_event_with_translated_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_exception(new \moodle_exception('notenoughtokens', 'aiprovider_datacurso'));
        client::set_instance_for_testing($fake);

        $output = $this->capture(fn() => handler::run(
            [['role' => 'user', 'content' => 'hi']],
            'admin',
            (int) get_admin()->id
        ));

        $events = $this->events($output);
        $this->assertCount(1, $events);
        $this->assertSame('no_credit', $events[0]['error']['code']);
        $this->assertSame(get_string('ai_error_nocredit', 'local_jedomi'), $events[0]['error']['message']);
    }

    /**
     * M4: the model sent by the browser is ignored; the configured model is used.
     */
    public function test_run_from_request_ignores_browser_model(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        set_config('model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]]]));
        client::set_instance_for_testing(
            new client(static fn(int $timeout): provider_api => new testable_provider_api($timeout, $curl))
        );

        $input = [
            'model' => 'jedomi/expensive/unpriced-model',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'context' => [],
        ];
        $this->capture(fn() => handler::run_from_request($input, 'admin', ['course_id' => 0], (int) get_admin()->id));

        $body = $curl->last_body();
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', $body['model']);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'hi'], $body['messages'][1]);
    }
}
