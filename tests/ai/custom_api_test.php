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

namespace local_jedomi\ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_curl.php');

/**
 * Tests for the HTTP client of the custom AI service.
 *
 * Needs only core: the custom service never goes through the Datacurso provider.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\custom_api
 */
final class custom_api_test extends \advanced_testcase {
    /** @var fake_curl Transport double. */
    private fake_curl $curl;

    /** @var bool[] Whether each created transport bypassed the site cURL security. */
    private array $bypassed = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // A public address: no blocked hosts, so nothing is resolved in the tests.
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $this->curl = new fake_curl();
        custom_api::set_curl_factory_for_testing(function (bool $bypass): \curl {
            $this->bypassed[] = $bypass;
            return $this->curl;
        });
    }

    #[\Override]
    protected function tearDown(): void {
        custom_api::set_curl_factory_for_testing(null);
        parent::tearDown();
    }

    /**
     * Header lines sent with the last request.
     *
     * @return string[]
     */
    private function last_headers(): array {
        $last = end($this->curl->calls);
        return $last['options']['CURLOPT_HTTPHEADER'] ?? [];
    }

    /**
     * Chat calls go to {url}/v1/chat/completions with the key as a Bearer token and without the Moodle user id.
     *
     * @return void
     */
    public function test_chat_posts_to_the_custom_route(): void {
        $this->curl->enqueue(json_encode(['choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']]]));
        $api = new custom_api('https://api.example.com/v1/', 'sk-test', false);

        $answer = $api->chat(['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'Hello']], 'userid' => 7], 30);

        $this->assertSame('Hi', $answer['choices'][0]['message']['content']);
        $call = $this->curl->calls[0];
        $this->assertSame('https://api.example.com/v1/chat/completions', $call['url']);
        $this->assertArrayNotHasKey('userid', json_decode($call['params'], true));
        $this->assertContains('Authorization: Bearer sk-test', $this->last_headers());
        $this->assertContains('Content-Type: application/json', $this->last_headers());
        $this->assertFalse($call['options']['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(30, $call['options']['CURLOPT_TIMEOUT']);
        $this->assertSame([false], $this->bypassed, 'A public host keeps the site cURL security');
    }

    /**
     * Without a key no Authorization header is sent (local models usually need none).
     *
     * @return void
     */
    public function test_no_key_sends_no_authorization(): void {
        $this->curl->enqueue(json_encode(['object' => 'list', 'data' => [['id' => 'llama3']]]));
        $api = new custom_api('https://api.example.com', '', false);

        $this->assertSame(['llama3'], $api->list_models(5));
        $this->assertSame('GET', $this->curl->calls[0]['method']);
        $this->assertSame('https://api.example.com/v1/models', $this->curl->calls[0]['url']);
        foreach ($this->last_headers() as $header) {
            $this->assertStringStartsNotWith('Authorization', $header);
        }
    }

    /**
     * Every request goes to the base address as the provider documents it, with /v1 added only when it has no path.
     *
     * @dataProvider base_url_provider
     * @param string $base Service URL typed by the administrator.
     * @param bool $allowhttp Whether HTTP for local models is on: only for a local IP address, never resolved.
     * @param string $root Root of the API the routes are added to.
     * @return void
     */
    public function test_requests_follow_the_base_address(string $base, bool $allowhttp, string $root): void {
        $path = make_request_directory() . '/voice.webm';
        file_put_contents($path, 'audio');
        $this->curl->enqueue(json_encode(['object' => 'list', 'data' => [['id' => 'm']]]));
        $this->curl->enqueue(json_encode(['choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']]]));
        $this->curl->enqueue(json_encode(['text' => 'hola']));
        $this->curl->enqueue("ID3\x03audio");
        $api = new custom_api($base, 'sk-test', $allowhttp);

        $api->list_models(5);
        $api->chat(['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'Hello']]], 5);
        $api->transcribe($path, 'voice.webm', ['model' => 'whisper'], 5);
        $api->speech(['model' => 'tts', 'input' => 'Hola', 'voice' => 'af'], 5);

        $this->assertSame(
            [$root . '/models', $root . '/chat/completions', $root . '/audio/transcriptions', $root . '/audio/speech'],
            array_column($this->curl->calls, 'url')
        );
        $this->assertSame(array_fill(0, 4, $allowhttp), $this->bypassed, 'Only the local address skips the cURL security');
    }

    /**
     * Service URLs of the documented providers and the root of their API.
     *
     * @return array
     */
    public static function base_url_provider(): array {
        return [
            'openai without path' => ['https://api.openai.com', false, 'https://api.openai.com/v1'],
            'openai with v1' => ['https://api.openai.com/v1', false, 'https://api.openai.com/v1'],
            'deepinfra' => ['https://api.deepinfra.com/v1/openai', false, 'https://api.deepinfra.com/v1/openai'],
            'groq' => ['https://api.groq.com/openai/v1', false, 'https://api.groq.com/openai/v1'],
            'openrouter' => ['https://openrouter.ai/api/v1', false, 'https://openrouter.ai/api/v1'],
            'ollama without path' => ['http://127.0.0.1:11434', true, 'http://127.0.0.1:11434/v1'],
            'ollama with v1' => ['http://127.0.0.1:11434/v1', true, 'http://127.0.0.1:11434/v1'],
            'custom path' => ['https://gw.example.com/custom/path/', false, 'https://gw.example.com/custom/path'],
        ];
    }

    /**
     * A local host allowed by the checkbox bypasses the blocked hosts, pinned to its checked address.
     *
     * @return void
     */
    public function test_local_host_bypasses_curl_security_only_when_allowed(): void {
        set_config('curlsecurityblockedhosts', "127.0.0.0/8\nlocalhost");
        $this->curl->enqueue(json_encode(['object' => 'list', 'data' => [['id' => 'llama3']]]));

        (new custom_api('http://127.0.0.1:11434', '', true))->list_models(5);

        $this->assertSame([true], $this->bypassed);
        $this->assertSame(['127.0.0.1:11434:127.0.0.1'], $this->curl->calls[0]['options']['CURLOPT_RESOLVE']);

        try {
            (new custom_api('https://127.0.0.1:11434', '', false))->list_models(5);
            $this->fail('A blocked host must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('custom_error_url', $e->errorcode);
            $this->assertSame(custom_endpoint::ERROR_BLOCKED_HOST, $e->a);
        }
        $this->assertCount(1, $this->curl->calls, 'Nothing is sent to a refused address');
    }

    /**
     * Error answers: HTTP errors, redirects, no answer and timeouts.
     *
     * @return void
     */
    public function test_errors(): void {
        $api = new custom_api('https://api.example.com', 'sk-test', false);

        $this->curl->enqueue('{"error":"bad key"}', 401);
        $this->assert_error(fn() => $api->list_models(5), 'custom_error_http', 401);

        $this->curl->enqueue('', 302);
        $this->assert_error(fn() => $api->list_models(5), 'custom_error_http', 302);

        $this->curl->enqueue('', 0, 'Could not resolve host', 6);
        $this->assert_error(fn() => $api->list_models(5), 'custom_error_unreachable', 6);

        $this->curl->enqueue('', 0, 'Operation timed out', CURLE_OPERATION_TIMEDOUT);
        $this->assert_error(
            fn() => $api->chat(['model' => 'm', 'messages' => []], 5),
            'custom_error_unreachable',
            CURLE_OPERATION_TIMEDOUT
        );

        $this->curl->enqueue('not json');
        $this->assert_error(fn() => $api->chat(['model' => 'm', 'messages' => []], 5), 'ai_error_invalidanswer', null);
    }

    /**
     * A model that rejects tools gives a clear error instead of a generic one.
     *
     * @return void
     */
    public function test_tools_rejected_by_the_model(): void {
        $api = new custom_api('https://api.example.com', 'sk-test', false);
        $payload = ['model' => 'm', 'messages' => [], 'tools' => [['type' => 'function']], 'tool_choice' => 'auto'];

        $this->curl->enqueue('{"error":{"message":"registry.ollama.ai/library/gemma does not support tools"}}', 400);
        $this->assert_error(fn() => $api->chat($payload, 5), 'ai_error_tools_unsupported', null);

        // The same answer without tools in the request is an ordinary error.
        unset($payload['tools'], $payload['tool_choice']);
        $this->curl->enqueue('{"error":{"message":"does not support tools"}}', 400);
        $this->assert_error(fn() => $api->chat($payload, 5), 'custom_error_http', 400);
    }

    /**
     * Not OpenAI-compatible model lists are refused.
     *
     * @dataProvider not_openai_provider
     * @param string $body Answer body.
     * @return void
     */
    public function test_list_models_rejects_answers_that_are_not_openai(string $body): void {
        $this->curl->enqueue($body);
        $api = new custom_api('https://api.example.com', 'sk-test', false);

        $this->assert_error(fn() => $api->list_models(5), 'ai_error_invalidanswer', null);
    }

    /**
     * Answers of GET /v1/models that are not an OpenAI model list.
     *
     * @return array
     */
    public static function not_openai_provider(): array {
        return [
            'html' => ['<html>It works</html>'],
            'other object' => ['{"object":"model","data":[{"id":"x"}]}'],
            'no data' => ['{"object":"list"}'],
            'empty list' => ['{"object":"list","data":[]}'],
            'entries without id' => ['{"object":"list","data":[{"name":"x"}]}'],
        ];
    }

    /**
     * Transcriptions are sent as multipart form data with the file, the model and the language.
     *
     * @return void
     */
    public function test_transcribe(): void {
        $path = make_request_directory() . '/voice.webm';
        file_put_contents($path, str_repeat('a', 4096));
        $this->curl->enqueue(json_encode(['text' => 'hola']));
        $api = new custom_api('https://api.example.com', 'sk-test', false);

        $answer = $api->transcribe($path, 'voice.webm', ['model' => 'whisper', 'language' => 'es'], 30);

        $this->assertSame('hola', $answer['text']);
        $call = $this->curl->calls[0];
        $this->assertSame('https://api.example.com/v1/audio/transcriptions', $call['url']);
        $this->assertInstanceOf(\CURLFile::class, $call['params']['file']);
        $this->assertSame('whisper', $call['params']['model']);
        $this->assertSame('es', $call['params']['language']);
        $this->assertContains('Authorization: Bearer sk-test', $this->last_headers());
    }

    /**
     * Speech answers audio; a JSON answer is not audio.
     *
     * @return void
     */
    public function test_speech(): void {
        $api = new custom_api('https://api.example.com', 'sk-test', false);
        $this->curl->enqueue("ID3\x03audio");

        $audio = $api->speech(['model' => 'tts', 'input' => 'Hola', 'voice' => 'af'], 30);

        $this->assertSame("ID3\x03audio", $audio);
        $this->assertSame('https://api.example.com/v1/audio/speech', $this->curl->calls[0]['url']);
        $this->assertSame('mp3', json_decode($this->curl->calls[0]['params'], true)['response_format']);

        $this->curl->enqueue('{"text":"no audio"}');
        $this->assert_error(
            fn() => $api->speech(['model' => 'tts', 'input' => 'x', 'voice' => 'af'], 30),
            'ai_error_invalidanswer',
            null
        );
    }

    /**
     * The key is never written to the Jedomi log or a debugging message.
     *
     * @return void
     */
    public function test_key_never_reaches_the_debugging_output(): void {
        set_config('debug_mode', 1, 'local_jedomi');
        $this->curl->enqueue('{"error":"sk-secret-key is invalid"}', 401);
        $api = new custom_api('https://api.example.com', 'sk-secret-key', false);

        ob_start();
        try {
            $api->list_models(5);
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString('sk-secret-key', $e->getMessage() . ' ' . $e->debuginfo);
        }
        $output = ob_get_clean();

        $this->assertStringNotContainsString('sk-secret-key', $output);
        foreach ($this->getDebuggingMessages() as $message) {
            $this->assertStringNotContainsString('sk-secret-key', $message->message);
        }
        $this->resetDebugging();
    }

    /**
     * Assert that a call raises a local_jedomi error with a code and parameter.
     *
     * @param callable $call Call.
     * @param string $code Error code.
     * @param mixed $a Expected parameter; null to skip the check.
     * @return void
     */
    private function assert_error(callable $call, string $code, $a): void {
        try {
            $call();
            $this->fail("Expected {$code}");
        } catch (\moodle_exception $e) {
            $this->assertSame('local_jedomi', $e->module);
            $this->assertSame($code, $e->errorcode);
            if ($a !== null) {
                $this->assertSame($a, $e->a);
            }
        }
        $this->resetDebugging();
    }

    /**
     * The model list also gives the context length of each model when the service tells it: max_model_len (vLLM),
     * context_length (OpenRouter, LM Studio), context_window (Groq) or max_context_length. Other values are ignored.
     *
     * @return void
     */
    public function test_model_list_gives_the_context_lengths(): void {
        $this->curl->enqueue(json_encode(['object' => 'list', 'data' => [
            ['id' => 'vllm', 'max_model_len' => 32768],
            ['id' => 'openrouter', 'context_length' => 131072],
            ['id' => 'groq', 'context_window' => 8192],
            ['id' => 'lmstudio', 'max_context_length' => '4096'],
            ['id' => 'none'],
            ['id' => 'negative', 'max_model_len' => -1],
            ['id' => 'text', 'context_length' => 'large'],
            ['id' => 'float', 'context_length' => 1.5],
        ]]));
        $api = new custom_api('https://api.example.com/v1', 'sk-test', false);

        $result = $api->list_models_with_context(5);

        $this->assertSame(['vllm', 'openrouter', 'groq', 'lmstudio', 'none', 'negative', 'text', 'float'], $result['ids']);
        $this->assertSame(['vllm' => 32768, 'openrouter' => 131072, 'groq' => 8192, 'lmstudio' => 4096], $result['context']);
    }
}
