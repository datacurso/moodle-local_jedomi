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

namespace local_jedomi\external;

use core_external\external_api;
use local_jedomi\ai\ai_service;
use local_jedomi\ai\custom_api;
use local_jedomi\ai\fake_curl;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_curl.php');

/**
 * Tests for the verification of the custom AI service (local_jedomi_verify_custom_service).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\verify_custom_service
 */
final class verify_custom_service_test extends \advanced_testcase {
    /** @var fake_curl Transport double. */
    private fake_curl $curl;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', "127.0.0.0/8\n10.0.0.0/8\nlocalhost\n169.254.169.254");
        set_config('curlsecurityallowedport', "443\n80");
        $this->curl = new fake_curl();
        custom_api::set_curl_factory_for_testing(fn(bool $bypass): \curl => $this->curl);
        $this->setAdminUser();
    }

    #[\Override]
    protected function tearDown(): void {
        custom_api::set_curl_factory_for_testing(null);
        parent::tearDown();
    }

    /**
     * Call the web service and clean its result like the AJAX layer does.
     *
     * @param string $url Address.
     * @param string $key API key.
     * @param bool $allowhttp Allow HTTP for local models.
     * @return array
     */
    private function call(string $url, string $key = 'sk-test', bool $allowhttp = false): array {
        return external_api::clean_returnvalue(
            verify_custom_service::execute_returns(),
            verify_custom_service::execute($url, $key, $allowhttp)
        );
    }

    /**
     * An OpenAI-compatible answer is valid and the address and key are remembered as verified.
     *
     * @return void
     */
    public function test_valid_openai_service(): void {
        $this->curl->enqueue(json_encode(['object' => 'list', 'data' => [['id' => 'a'], ['id' => 'b']]]));

        $result = $this->call('https://203.0.113.10/v1/');

        $this->assertTrue($result['valid']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame(2, $result['modelcount']);
        $this->assertSame(get_string('custom_verify_ok', 'local_jedomi', 2), $result['message']);
        $this->assertSame('https://203.0.113.10/v1/models', $this->curl->calls[0]['url']);
        $this->assertTrue(ai_service::is_verified('https://203.0.113.10', 'sk-test'));
        $this->assertStringNotContainsString('sk-test', json_encode($result));
    }

    /**
     * Each failure has its own reason and message, and nothing is remembered.
     *
     * @dataProvider failure_provider
     * @param string $url Address.
     * @param bool $allowhttp Allow HTTP for local models.
     * @param array|null $answer Queued answer [body, status, error, errno]; null when nothing must be sent.
     * @param string $reason Expected reason.
     * @return void
     */
    public function test_failures(string $url, bool $allowhttp, ?array $answer, string $reason): void {
        if ($answer !== null) {
            $this->curl->enqueue(...$answer);
        }

        $result = $this->call($url, 'sk-test', $allowhttp);

        $this->assertFalse($result['valid']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame(get_string('custom_verify_' . $reason, 'local_jedomi', $answer[1] ?? null), $result['message']);
        $this->assertFalse(ai_service::is_verified($url, 'sk-test'));
        if ($answer === null) {
            $this->assertSame([], $this->curl->calls, 'Nothing is sent to a refused address');
        }
    }

    /**
     * Failures and their reasons.
     *
     * @return array
     */
    public static function failure_provider(): array {
        return [
            'invalid url' => ['notaurl', false, null, 'invalid_url'],
            'http not allowed' => ['http://203.0.113.10', false, null, 'http_not_allowed'],
            'blocked host' => ['https://10.0.0.5', false, null, 'blocked_host'],
            'metadata host' => ['https://169.254.169.254', true, null, 'blocked_host'],
            'no response' => ['https://203.0.113.10', false, ['', 0, 'Connection refused', 7], 'no_response'],
            'timeout' => ['https://203.0.113.10', false, ['', 0, 'Timed out', 28], 'no_response'],
            'unauthorized' => ['https://203.0.113.10', false, ['{"error":"x"}', 401], 'unauthorized'],
            'forbidden' => ['https://203.0.113.10', false, ['{"error":"x"}', 403], 'unauthorized'],
            'server error' => ['https://203.0.113.10', false, ['oops', 500], 'http_error'],
            'redirect' => ['https://203.0.113.10', false, ['', 301], 'http_error'],
            'not openai' => ['https://203.0.113.10', false, ['{"models":["a"]}', 200], 'not_openai'],
        ];
    }

    /**
     * A local model over HTTP is verified when HTTP for local models is allowed.
     *
     * @return void
     */
    public function test_local_model_over_http(): void {
        $this->curl->enqueue(json_encode(['object' => 'list', 'data' => [['id' => 'llama3']]]));

        $result = $this->call('http://127.0.0.1:11434', '', true);

        $this->assertTrue($result['valid']);
        $this->assertTrue(ai_service::is_verified('http://127.0.0.1:11434', ''));
    }

    /**
     * An empty key uses the saved key, but only for the saved address: the saved key never goes to another host.
     *
     * @return void
     */
    public function test_saved_key_only_goes_to_the_saved_address(): void {
        set_config('custom_gateway_url', 'https://203.0.113.10', 'local_jedomi');
        set_config('custom_api_key', 'sk-saved', 'local_jedomi');
        $this->curl->enqueue(json_encode(['object' => 'list', 'data' => [['id' => 'a']]]));

        $result = $this->call('https://203.0.113.10/v1', '');

        $this->assertTrue($result['valid']);
        $this->assertContains('Authorization: Bearer sk-saved', $this->curl->calls[0]['options']['CURLOPT_HTTPHEADER']);
        $this->assertTrue(ai_service::is_verified('https://203.0.113.10', 'sk-saved'));

        $result = $this->call('https://198.51.100.20', '');

        $this->assertFalse($result['valid']);
        $this->assertSame('key_required', $result['reason']);
        $this->assertCount(1, $this->curl->calls);
    }

    /**
     * An administrator can verify only a few times per minute, so the check cannot scan the network.
     *
     * @return void
     */
    public function test_rate_limit(): void {
        for ($i = 0; $i < verify_custom_service::RATE_LIMIT_PER_MINUTE; $i++) {
            $this->assertSame('invalid_url', $this->call('notaurl')['reason']);
        }

        $result = $this->call('https://203.0.113.10');

        $this->assertFalse($result['valid']);
        $this->assertSame('rate_limited', $result['reason']);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * Only administrators who can change the site configuration may verify.
     *
     * @return void
     */
    public function test_requires_site_config(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        $this->call('https://203.0.113.10');
    }

    /**
     * The function is declared for AJAX, with the capability, and checks the session key there.
     *
     * @return void
     */
    public function test_declaration(): void {
        $function = external_api::external_function_info('local_jedomi_verify_custom_service');

        $this->assertTrue((bool) $function->allowed_from_ajax);
        $this->assertSame('moodle/site:config', $function->capabilities);
        $this->assertTrue((bool) $function->loginrequired);
    }
}
