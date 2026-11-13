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
 * Tests for the choice of destination of the Jedomi AI client: Datacurso, the custom service or none.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\client
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
}
