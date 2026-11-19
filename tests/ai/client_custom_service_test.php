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
 * Tests for the choice of destination of the Jedomi AI client (the Datacurso or the custom service) and the log of its
 * failures.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\client
 * @covers     \local_jedomi\ai\error_message
 * @covers     \local_jedomi\local\service\speech_service
 */
final class client_custom_service_test extends \advanced_testcase {
    /** @var fake_curl Transport double of the custom service. */
    private fake_curl $curl;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $this->curl = new fake_curl();
        custom_api::set_curl_factory_for_testing(fn(bool $bypass): \curl => $this->curl);
        set_config('ai_service', ai_service::CUSTOM, 'local_jedomi');
        set_config('custom_gateway_url', 'https://api.example.com', 'local_jedomi');
        set_config('custom_api_key', 'sk-test', 'local_jedomi');
        set_config('custom_model', 'meta-llama/Llama-3.3-70B-Instruct', 'local_jedomi');
        set_config('custom_max_tokens', '1024', 'local_jedomi');
    }

    #[\Override]
    protected function tearDown(): void {
        custom_api::set_curl_factory_for_testing(null);
        parent::tearDown();
    }

    /**
     * A client whose Datacurso transport fails the test if it is ever used.
     *
     * @return client
     */
    private function make_client(): client {
        return new client(function (): void {
            $this->fail('The Datacurso provider must not be called with the custom service');
        });
    }

    /**
     * With the custom service the chat goes to {url}/v1/chat/completions with the custom model and max tokens.
     *
     * @return void
     */
    public function test_custom_service_receives_the_chat(): void {
        $this->curl->enqueue(json_encode(['choices' => [[
            'message' => ['content' => 'Hello', 'tool_calls' => [['function' => ['name' => 'x', 'arguments' => '{}']]]],
            'finish_reason' => 'tool_calls',
        ]]]));
        $tools = [['type' => 'function', 'function' => ['name' => 'x']]];

        $answer = $this->make_client()->complete([['role' => 'user', 'content' => 'Hi']], 5, $tools, 30, 0.2);

        $this->assertSame('Hello', $answer['content']);
        $this->assertSame('x', $answer['tool_calls'][0]['function']['name']);
        $this->assertSame('https://api.example.com/v1/chat/completions', $this->curl->calls[0]['url']);
        $body = json_decode($this->curl->calls[0]['params'], true);
        $this->assertSame('meta-llama/Llama-3.3-70B-Instruct', $body['model']);
        $this->assertSame(1024, $body['max_tokens']);
        $this->assertSame($tools, $body['tools']);
        $this->assertSame('auto', $body['tool_choice']);
        $this->assertSame(0.2, $body['temperature']);
        $this->assertArrayNotHasKey('userid', $body, 'The Moodle user id never leaves the site');
    }

    /**
     * The max tokens sent never takes more than half the known context window.
     *
     * @return void
     */
    public function test_sent_max_tokens_fit_the_context_window(): void {
        set_config('custom_max_tokens', '16384', 'local_jedomi');
        set_config('custom_context_window', '8192', 'local_jedomi');
        $this->curl->enqueue(json_encode(['choices' => [['message' => ['content' => 'Hello'], 'finish_reason' => 'stop']]]));

        $this->make_client()->complete([['role' => 'user', 'content' => 'Hi']], 5, [], 30);

        $this->assertSame(4096, $this->curl->last_body()['max_tokens']);
    }

    /**
     * A model without tool calling gives a clear error.
     *
     * @return void
     */
    public function test_model_without_tools(): void {
        $this->curl->enqueue('{"error":{"message":"\"auto\" tool choice requires --enable-auto-tool-choice"}}', 400);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('ai_error_tools_unsupported', 'local_jedomi'));
        $this->make_client()->complete([['role' => 'user', 'content' => 'Hi']], 5, [['type' => 'function']]);
    }

    /**
     * Failures of the custom service get the same user messages as the Datacurso ones.
     *
     * @dataProvider error_provider
     * @param string $code Error code.
     * @param mixed $a Error parameter.
     * @param string $expectedcode Expected user error code.
     * @param string $expectedkey Expected message string.
     * @return void
     */
    public function test_error_messages(string $code, $a, string $expectedcode, string $expectedkey): void {
        $result = error_message::from_exception(new \moodle_exception($code, 'local_jedomi', '', $a));

        $this->assertSame($expectedcode, $result['code']);
        $this->assertSame(get_string($expectedkey, 'local_jedomi'), $result['message']);
    }

    /**
     * Custom service errors and their user messages.
     *
     * @return array
     */
    public static function error_provider(): array {
        return [
            'timeout' => ['custom_error_unreachable', CURLE_OPERATION_TIMEDOUT, error_message::CODE_TIMEOUT, 'ai_error_timeout'],
            'unreachable' => ['custom_error_unreachable', 7, error_message::CODE_UNAVAILABLE, 'ai_error_unavailable'],
            'server error' => ['custom_error_http', 502, error_message::CODE_UNAVAILABLE, 'ai_error_unavailable'],
            'bad key' => ['custom_error_http', 401, error_message::CODE_UNAVAILABLE, 'ai_error_unavailable'],
            'rate limited' => ['custom_error_http', 429, error_message::CODE_RATE_LIMITED, 'ai_error_ratelimit_noretry'],
            'bad request' => ['custom_error_http', 400, error_message::CODE_GENERIC, 'ai_error_generic'],
            'address' => ['custom_error_url', 'blocked_host', error_message::CODE_UNAVAILABLE, 'ai_error_unavailable'],
            'tools' => ['ai_error_tools_unsupported', null, error_message::CODE_TOOLS_UNSUPPORTED,
                'ai_error_tools_unsupported'],
        ];
    }

    /**
     * A timed-out request of the custom service is not resent either.
     *
     * @return void
     */
    public function test_timeout_is_detected(): void {
        $this->assertTrue(error_message::is_timeout(
            new \moodle_exception('custom_error_unreachable', 'local_jedomi', '', CURLE_OPERATION_TIMEDOUT)
        ));
        $this->assertFalse(error_message::is_timeout(new \moodle_exception('custom_error_unreachable', 'local_jedomi', '', 7)));
    }

    /**
     * Read the Jedomi log written since it was emptied, as one string.
     *
     * @return string
     */
    private function read_log(): string {
        global $CFG;
        $path = $CFG->dataroot . '/local/jedomi/jedomi.log';
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Empty the Jedomi log.
     *
     * @return void
     */
    private function empty_log(): void {
        global $CFG;
        @unlink($CFG->dataroot . '/local/jedomi/jedomi.log');
    }

    /**
     * RES-001: a failed chat with the custom service logs its HTTP status, cURL error number or refusal reason, never
     * the key or the answer, and the user still gets the same message.
     *
     * @dataProvider failure_provider
     * @param array $response Body, HTTP status, cURL error text and number of the answer.
     * @param string $config Service URL, when it differs from the valid one.
     * @param string $code Expected error code in the log.
     * @param string $detail Expected detail in the log.
     * @param string $userkey Expected message string of the user.
     * @return void
     */
    public function test_chat_failure_logs_its_detail(
        array $response,
        string $config,
        string $code,
        string $detail,
        string $userkey
    ): void {
        if ($config !== '') {
            set_config('custom_gateway_url', $config, 'local_jedomi');
        }
        $this->curl->enqueue(...$response);
        $this->empty_log();

        try {
            $this->make_client()->complete([['role' => 'user', 'content' => 'Hi']], 5);
            $this->fail('The failure must reach the caller');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string($userkey, 'local_jedomi'), error_message::from_exception($e)['message']);
        }

        $log = $this->read_log();
        $this->assertMatchesRegularExpression('/AI_ERROR \| \{"code":"' . $code . '"[^\n]*"detail":"' . $detail . '"/', $log);
        $this->assertStringNotContainsString('sk-test', $log, 'The key is never logged');
        $this->assertStringNotContainsString('Body marker', $log, 'The answer is never logged');
    }

    /**
     * Failures of the custom service and what the log keeps of them.
     *
     * @return array
     */
    public static function failure_provider(): array {
        return [
            'key refused' => [['{"error":"Body marker"}', 401], '', 'custom_error_http', '401', 'ai_error_unavailable'],
            'server error' => [['Body marker', 503], '', 'custom_error_http', '503', 'ai_error_unavailable'],
            'no answer' => [['', 0, 'Body marker connection refused', 7], '', 'custom_error_unreachable', '7',
                'ai_error_unavailable'],
            'address refused' => [['Body marker'], 'http://api.example.com', 'custom_error_url', 'http_not_allowed',
                'ai_error_unavailable'],
        ];
    }

    /**
     * RES-001: a failed spoken answer with the custom service logs its HTTP status, never the key or the answer.
     *
     * @return void
     */
    public function test_speech_failure_logs_its_detail(): void {
        set_config('custom_speech_model', 'tts-1', 'local_jedomi');
        set_config('custom_speech_voice', 'alloy', 'local_jedomi');
        $this->curl->enqueue('{"error":"Body marker"}', 403);
        $this->empty_log();

        try {
            \local_jedomi\local\service\speech_service::synthesise((int) get_admin()->id, 'Hello');
            $this->fail('The failure must reach the caller');
        } catch (\moodle_exception $e) {
            $this->assertSame('custom_error_http', $e->errorcode);
        }

        $log = $this->read_log();
        $this->assertMatchesRegularExpression('/SPEECH_ERROR \| \{"code":"custom_error_http"[^\n]*"detail":"403"/', $log);
        $this->assertStringNotContainsString('sk-test', $log);
        $this->assertStringNotContainsString('Body marker', $log);
    }

    /**
     * RES-001: only the failures of the custom service add a detail to the log, so the parameter of a Datacurso
     * provider error is never written.
     *
     * @return void
     */
    public function test_log_detail_is_only_for_custom_service_failures(): void {
        $this->assertSame(['detail' => '401'], error_message::log_detail(
            new \moodle_exception('custom_error_http', 'local_jedomi', '', 401)
        ));
        $this->assertSame([], error_message::log_detail(new \moodle_exception('ai_error_generic', 'local_jedomi', '', 'x')));
        $this->assertSame([], error_message::log_detail(
            new \moodle_exception('custom_error_http', 'aiprovider_datacurso', '', 'Provider text')
        ));
    }

    /**
     * Without a timeout of its caller (web chat, CLI), a request waits the AI request timeout of the settings, for the
     * custom service and for the Datacurso service; a caller with its own timeout (Telegram, agents) keeps it.
     *
     * @return void
     */
    public function test_requests_use_the_timeout_setting(): void {
        $answer = json_encode(['choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']]]);
        $messages = [['role' => 'user', 'content' => 'Hi']];

        $this->curl->enqueue($answer);
        $this->make_client()->complete($messages, 5);
        $this->assertSame(client::DEFAULT_TIMEOUT, $this->curl->calls[0]['options']['CURLOPT_TIMEOUT']);

        set_config('ai_timeout', '95', 'local_jedomi');
        $this->curl->enqueue($answer);
        $this->make_client()->complete($messages, 5);
        $this->assertSame(95, $this->curl->calls[1]['options']['CURLOPT_TIMEOUT']);

        $this->curl->enqueue($answer);
        $this->make_client()->complete($messages, 5, [], 30);
        $this->assertSame(30, $this->curl->calls[2]['options']['CURLOPT_TIMEOUT'], 'A timeout of the caller wins');

        set_config('ai_service', ai_service::DATACURSO, 'local_jedomi');
        $timeouts = [];
        $client = new client(function (int $timeout) use (&$timeouts) {
            $timeouts[] = $timeout;
            throw new \moodle_exception('ai_error_invalidanswer', 'local_jedomi');
        });
        try {
            $client->complete($messages, 5);
        } catch (\moodle_exception $e) {
            $this->assertSame('ai_error_invalidanswer', $e->errorcode);
        }
        $this->assertSame([95], $timeouts);
    }

    /**
     * The saved timeout is kept within 10 to 600 seconds; an empty or invalid value is the default (180).
     *
     * @dataProvider timeout_provider
     * @param string|null $saved Saved value; null for none.
     * @param int $expected Timeout used.
     * @return void
     */
    public function test_timeout_setting_is_kept_in_its_range(?string $saved, int $expected): void {
        if ($saved === null) {
            unset_config('ai_timeout', 'local_jedomi');
        } else {
            set_config('ai_timeout', $saved, 'local_jedomi');
        }

        $this->assertSame($expected, client::configured_timeout());
    }

    /**
     * Saved timeouts and the timeout used.
     *
     * @return array
     */
    public static function timeout_provider(): array {
        return [
            'none' => [null, 180],
            'empty' => ['', 180],
            'text' => ['abc', 180],
            'too small' => ['5', 10],
            'too large' => ['9999', 600],
            'valid' => ['240', 240],
        ];
    }
}
