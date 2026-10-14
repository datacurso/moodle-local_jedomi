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
 * Unit tests for the gateway backend and the shared OpenAI-compatible template.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

use local_jedomi\ai\backend;
use local_jedomi\ai\client;
use local_jedomi\ai\gateway_backend;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Test cases for gateway_backend::complete and the openai_compatible_backend template.
 *
 * @covers \local_jedomi\ai\gateway_backend
 * @covers \local_jedomi\ai\openai_compatible_backend
 */
final class ai_gateway_backend_test extends \advanced_testcase {
    /**
     * Setup test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Read the plugin log file written by local_jedomi_log().
     *
     * @return string
     */
    private function read_log(): string {
        global $CFG;
        $file = $CFG->dataroot . '/local/jedomi/jedomi.log';
        return file_exists($file) ? file_get_contents($file) : '';
    }

    /**
     * The backend key identifies the gateway.
     */
    public function test_get_key(): void {
        $this->assertSame(client::BACKEND_GATEWAY, (new gateway_backend(null, null, new fake_transport()))->get_key());
    }

    /**
     * Constructor arguments win over config; config wins over defaults.
     */
    public function test_endpoint_and_auth_resolution(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([fake_transport::text_chunk('hi')])]]);

        // Defaults when nothing is configured.
        (new gateway_backend(null, null, $transport))->complete('m', [['role' => 'user', 'content' => 'x']]);
        $call = $transport->last_call();
        $this->assertSame(gateway_backend::DEFAULT_URL . '/v1/chat/completions', $call['url']);
        $this->assertContains('Authorization: Bearer ' . gateway_backend::DEFAULT_API_KEY, $call['headers']);
        $this->assertContains('Content-Type: application/json', $call['headers']);

        // Config values, trailing slash trimmed.
        set_config('gateway_url', 'http://gw.example.test:9000/', 'local_jedomi');
        set_config('api_key', 'cfg-key', 'local_jedomi');
        (new gateway_backend(null, null, $transport))->complete('m', [['role' => 'user', 'content' => 'x']]);
        $call = $transport->last_call();
        $this->assertSame('http://gw.example.test:9000/v1/chat/completions', $call['url']);
        $this->assertContains('Authorization: Bearer cfg-key', $call['headers']);

        // Explicit constructor arguments.
        (new gateway_backend('http://explicit.test/', 'explicit-key', $transport))
            ->complete('m', [['role' => 'user', 'content' => 'x']]);
        $call = $transport->last_call();
        $this->assertSame('http://explicit.test/v1/chat/completions', $call['url']);
        $this->assertContains('Authorization: Bearer explicit-key', $call['headers']);
    }

    /**
     * The payload follows the OpenAI chat completions shape and merges the gateway extras.
     */
    public function test_payload_shape_with_tools_and_extra(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([fake_transport::text_chunk('hi')])]]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);
        $messages = [['role' => 'system', 'content' => 's'], ['role' => 'user', 'content' => 'u']];
        $tools = [['type' => 'function', 'function' => ['name' => 'ws_search', 'parameters' => ['type' => 'object']]]];

        $backend->complete('jedomi-e4b', $messages, $tools, [
            'max_tokens' => 512,
            'temperature' => 0.2,
            'timeout' => 33,
            'extra' => ['spt' => ['role' => 'admin']],
        ]);

        $payload = $transport->last_payload();
        $this->assertSame('jedomi-e4b', $payload['model']);
        $this->assertSame($messages, $payload['messages']);
        $this->assertTrue($payload['stream']);
        $this->assertSame(512, $payload['max_tokens']);
        $this->assertSame(0.2, $payload['temperature']);
        $this->assertSame($tools, $payload['tools']);
        $this->assertSame('auto', $payload['tool_choice']);
        $this->assertSame(['role' => 'admin'], $payload['spt'], 'extra must be merged into the gateway payload');
        $this->assertSame(33, $transport->last_call()['timeout']);
    }

    /**
     * Without tools, temperature or extra the optional keys are absent and defaults apply.
     */
    public function test_payload_defaults(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([fake_transport::text_chunk('hi')])]]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $backend->complete('', [['role' => 'user', 'content' => 'u']]);

        $payload = $transport->last_payload();
        $this->assertArrayNotHasKey('model', $payload, 'Empty model must be omitted');
        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertArrayNotHasKey('tool_choice', $payload);
        $this->assertArrayNotHasKey('temperature', $payload);
        $this->assertSame(client::DEFAULT_MAX_TOKENS, $payload['max_tokens']);
        $this->assertSame(180, $transport->last_call()['timeout']);
    }

    /**
     * A streamed text answer is normalised to the text shape.
     */
    public function test_complete_returns_text(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([
            fake_transport::text_chunk('Hola '),
            fake_transport::text_chunk('mundo', 'stop'),
        ])]]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(['type' => backend::TYPE_TEXT, 'content' => 'Hola mundo'], $result);
        $this->assertStringContainsString('AI_API_REQUEST', $this->read_log());
        $this->assertStringContainsString('AI_API_RESPONSE_TEXT', $this->read_log());
    }

    /**
     * When the model only produced reasoning tokens they are used as the answer.
     */
    public function test_complete_falls_back_to_reasoning(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([
            ['choices' => [['delta' => ['reasoning_content' => 'thought'], 'finish_reason' => 'stop']]],
        ])]]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(['type' => backend::TYPE_TEXT, 'content' => 'thought'], $result);
    }

    /**
     * A 200 stream without any content is still a text result with empty content.
     */
    public function test_complete_empty_stream_returns_empty_text(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([
            ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
        ])]]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(['type' => backend::TYPE_TEXT, 'content' => ''], $result);
        $this->assertStringContainsString('AI_API_RESPONSE_EMPTY', $this->read_log());
    }

    /**
     * Tool calls are returned ordered and every entry gets a synthesized id when missing.
     */
    public function test_complete_returns_tool_calls_with_ids(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['name' => 'ws_search', 'arguments' => '{"q":"a"}']],
                ['index' => 1, 'id' => 'call_given', 'function' => ['name' => 'execute_sql', 'arguments' => '{}']],
            ]], 'finish_reason' => 'tool_calls']]],
        ])]]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(backend::TYPE_TOOL_CALLS, $result['type']);
        $this->assertCount(2, $result['tool_calls']);
        $this->assertStringStartsWith('call_', $result['tool_calls'][0]['id']);
        $this->assertNotSame('call_', $result['tool_calls'][0]['id']);
        $this->assertSame('ws_search', $result['tool_calls'][0]['function']['name']);
        $this->assertSame('call_given', $result['tool_calls'][1]['id']);
        $this->assertSame('function', $result['tool_calls'][1]['type']);
        foreach ($result['tool_calls'] as $tc) {
            $this->assertSame(['id', 'type', 'function'], array_keys($tc), 'No vendor keys must be invented');
        }
        $this->assertStringContainsString('AI_API_RESPONSE_TOOL_CALLS', $this->read_log());
    }

    /**
     * Vendor keys such as Gemini's extra_content survive normalisation so they can be echoed back.
     */
    public function test_complete_keeps_extra_content_on_tool_calls(): void {
        $extra = ['google' => ['thought_signature' => 'abc']];
        $transport = new fake_transport([['body' => fake_transport::sse([
            ['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['name' => 'get_time', 'arguments' => '{}'], 'extra_content' => $extra],
            ]], 'finish_reason' => 'tool_calls']]],
        ])]]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(backend::TYPE_TOOL_CALLS, $result['type']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertStringStartsWith('call_', $result['tool_calls'][0]['id']);
        $this->assertSame($extra, $result['tool_calls'][0]['extra_content']);
        $this->assertSame('{}', $result['tool_calls'][0]['function']['arguments']);
    }

    /**
     * Non-200 responses become a normalised error carrying the HTTP details.
     */
    public function test_complete_http_error(): void {
        $transport = new fake_transport([['code' => 502, 'body' => '<html>bad gateway</html>']]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(backend::TYPE_ERROR, $result['type']);
        $this->assertSame('ai_api_error', $result['error']);
        $this->assertSame(502, $result['http_code']);
        $this->assertSame(0, $result['curl_errno']);
        $this->assertSame('', $result['curl_error']);
        $this->assertSame('<html>bad gateway</html>', $result['preview']);
        $this->assertStringContainsString('AI_API_ERROR', $this->read_log());
    }

    /**
     * Transport failures carry the cURL errno so callers can tell a timeout apart.
     */
    public function test_complete_curl_error(): void {
        $transport = new fake_transport([
            ['code' => 0, 'body' => '', 'errno' => CURLE_OPERATION_TIMEDOUT, 'error' => 'Operation timed out'],
        ]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(backend::TYPE_ERROR, $result['type']);
        $this->assertSame(CURLE_OPERATION_TIMEDOUT, $result['curl_errno']);
        $this->assertSame('Operation timed out', $result['curl_error']);
        $this->assertSame(0, $result['http_code']);
    }

    /**
     * A 200 with an empty body is an error, not an empty answer.
     */
    public function test_complete_empty_body_is_error(): void {
        $transport = new fake_transport([['code' => 200, 'body' => '']]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(backend::TYPE_ERROR, $result['type']);
        $this->assertSame(200, $result['http_code']);
    }

    /**
     * An in-stream error chunk is reported as an error instead of empty text.
     */
    public function test_complete_stream_error_chunk(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([
            ['error' => ['code' => 'upstream_error', 'message' => 'Upstream exploded']],
        ])]]);
        $backend = new gateway_backend('http://gw.test', 'k', $transport);

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(backend::TYPE_ERROR, $result['type']);
        $this->assertSame('ai_stream_error', $result['error']);
        $this->assertSame('Upstream exploded', $result['message']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame(0, $result['curl_errno']);
    }

    /**
     * A failure while resolving the endpoint or headers is a normalised error and nothing is sent.
     */
    public function test_complete_endpoint_resolution_failure_is_backend_unavailable(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([fake_transport::text_chunk('hi')])]]);
        $backend = new class ($transport) extends \local_jedomi\ai\openai_compatible_backend {
            /**
             * Backend key.
             *
             * @return string
             */
            public function get_key(): string {
                return 'throwing';
            }

            /**
             * Simulates a failing endpoint resolution.
             *
             * @return string
             */
            protected function get_endpoint_url(): string {
                throw new \RuntimeException('boom');
            }

            /**
             * Minimal headers.
             *
             * @return string[]
             */
            protected function get_headers(): array {
                return ['Content-Type: application/json'];
            }

            /**
             * Payload passthrough.
             *
             * @param array $payload
             * @param array $options
             * @return array
             */
            protected function extend_payload(array $payload, array $options): array {
                return $payload;
            }

            /**
             * No notice mapping.
             *
             * @param int $code
             * @param string $body
             * @return array|null
             */
            protected function map_http_error(int $code, string $body): ?array {
                return null;
            }
        };

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame(backend::TYPE_ERROR, $result['type']);
        $this->assertSame('backend_unavailable', $result['error']);
        $this->assertSame(0, $result['http_code']);
        $this->assertSame(0, $result['curl_errno']);
        $this->assertSame('boom', $result['curl_error']);
        $this->assertSame('', $result['preview']);
        $this->assertSame([], $transport->calls, 'No request must be sent when the endpoint cannot be resolved');
        $this->assertStringContainsString('AI_BACKEND_UNAVAILABLE', $this->read_log());
        $this->assertStringNotContainsString('AI_API_REQUEST', $this->read_log());
    }

    /**
     * The real default transport fails fast against an unroutable port and maps to an error.
     */
    public function test_default_transport_connection_refused(): void {
        $backend = new gateway_backend('http://127.0.0.1:1', 'k');

        $result = $backend->complete('m', [['role' => 'user', 'content' => 'u']], [], ['timeout' => 5]);

        $this->assertSame(backend::TYPE_ERROR, $result['type']);
        $this->assertNotSame(0, $result['curl_errno']);
    }
}
