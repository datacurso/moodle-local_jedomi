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

use aiprovider_datacurso\local\outbound_privacy;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for the Jedomi AI client over the Datacurso provider.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\client
 * @covers     \local_jedomi\ai\provider_api
 */
final class client_test extends \advanced_testcase {
    /**
     * Build a client whose provider transport is the given fake.
     *
     * @param fake_curl $curl Transport double.
     * @return client
     */
    private function make_client(fake_curl $curl): client {
        return new client(static fn(int $timeout): provider_api => new testable_provider_api($timeout, $curl));
    }

    /**
     * Answer body in OpenAI chat completion format.
     *
     * @param array $message Assistant message.
     * @param string $finish Finish reason.
     * @return string
     */
    private function answer(array $message, string $finish = 'stop'): string {
        return json_encode(['choices' => [['message' => $message, 'finish_reason' => $finish]]]);
    }

    /**
     * M1: the request goes to the Jedomi route with the configured model, stream off, max_tokens and the given user.
     */
    public function test_complete_sends_configured_model_and_given_user_to_jedomi_route(): void {
        $this->resetAfterTest();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        set_config('model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        set_config('max_tokens', 2048, 'local_jedomi');
        $user = $this->getDataGenerator()->create_user();

        $curl = new fake_curl();
        $curl->enqueue($this->answer(['role' => 'assistant', 'content' => 'Hello there']));

        $result = $this->make_client($curl)->complete([['role' => 'user', 'content' => 'Hi']], (int) $user->id, [], 30);

        $this->assertCount(1, $curl->calls);
        $this->assertStringEndsWith('/jedomi/v1/chat/completions', $curl->calls[0]['url']);
        $body = $curl->last_body();
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', $body['model']);
        $this->assertFalse($body['stream']);
        $this->assertSame(2048, $body['max_tokens']);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $body['messages']);
        $this->assertSame(outbound_privacy::pseudonymise_userid_value((int) $user->id), $body['userid']);
        $this->assertArrayNotHasKey('spt', $body);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertSame(30, $curl->setoptions['CURLOPT_TIMEOUT']);
        $this->assertSame('Hello there', $result['content']);
        $this->assertSame([], $result['tool_calls']);
    }

    /**
     * M1: the user sent is the one given, not the logged-in user (cron and Telegram have no session user).
     */
    public function test_complete_uses_given_user_instead_of_session_user(): void {
        $this->resetAfterTest();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        $owner = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $curl = new fake_curl();
        $curl->enqueue($this->answer(['role' => 'assistant', 'content' => 'ok']));
        $this->make_client($curl)->complete([['role' => 'user', 'content' => 'x']], (int) $owner->id);

        $body = $curl->last_body();
        $this->assertSame(outbound_privacy::pseudonymise_userid_value((int) $owner->id), $body['userid']);
        $this->assertNotSame(outbound_privacy::pseudonymise_userid_value((int) get_admin()->id), $body['userid']);
    }

    /**
     * M1: tools are sent with automatic choice, and tool calls come back normalised with ids and string arguments.
     */
    public function test_complete_sends_tools_and_returns_normalised_tool_calls(): void {
        $this->resetAfterTest();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        $tools = [['type' => 'function', 'function' => ['name' => 'search_tables', 'parameters' => ['type' => 'object']]]];

        $curl = new fake_curl();
        $curl->enqueue($this->answer([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['type' => 'function', 'function' => ['name' => 'search_tables', 'arguments' => ['query' => 'user']]],
            ],
        ], 'tool_calls'));

        $result = $this->make_client($curl)->complete([['role' => 'user', 'content' => 'x']], 2, $tools);

        $body = $curl->last_body();
        $this->assertSame($tools, $body['tools']);
        $this->assertSame('auto', $body['tool_choice']);
        $this->assertSame(client::DEFAULT_TIMEOUT, $curl->setoptions['CURLOPT_TIMEOUT']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertNotEmpty($result['tool_calls'][0]['id']);
        $this->assertSame('search_tables', $result['tool_calls'][0]['function']['name']);
        $this->assertSame('{"query":"user"}', $result['tool_calls'][0]['function']['arguments']);
        $this->assertSame('', $result['content']);
    }

    /**
     * M1: a legacy model without the jedomi/ prefix is never sent; the default model is used instead.
     */
    public function test_complete_falls_back_to_default_model_for_legacy_value(): void {
        $this->resetAfterTest();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        set_config('model', 'jedomi-e4b', 'local_jedomi');
        unset_config('max_tokens', 'local_jedomi');

        $curl = new fake_curl();
        $curl->enqueue($this->answer(['role' => 'assistant', 'content' => 'ok']));
        $this->make_client($curl)->complete([['role' => 'user', 'content' => 'x']], 2, [], null, 0.2);

        $body = $curl->last_body();
        $this->assertSame(model_settings::DEFAULT_MODEL, $body['model']);
        $this->assertSame(model_settings::DEFAULT_MAX_TOKENS, $body['max_tokens']);
        $this->assertSame(0.2, $body['temperature']);
    }

    /**
     * M1: a provider error surfaces as the provider exception so callers can translate it.
     */
    public function test_complete_propagates_provider_errors(): void {
        $this->resetAfterTest();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');

        $curl = new fake_curl();
        $curl->enqueue('{"detail":"x"}', 503);

        try {
            $this->make_client($curl)->complete([['role' => 'user', 'content' => 'x']], 2);
            $this->fail('Expected a provider exception');
        } catch (\moodle_exception $e) {
            $this->resetDebugging();
            $this->assertSame('httperror', $e->errorcode);
            $this->assertSame('aiprovider_datacurso', $e->module);
        }
    }

    /**
     * M1: an answer without choices is an error, not an empty reply.
     */
    public function test_complete_rejects_answer_without_choices(): void {
        $this->resetAfterTest();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');

        $curl = new fake_curl();
        $curl->enqueue('{"object":"chat.completion"}');

        $this->expectException(\moodle_exception::class);
        $this->make_client($curl)->complete([['role' => 'user', 'content' => 'x']], 2);
    }
}
