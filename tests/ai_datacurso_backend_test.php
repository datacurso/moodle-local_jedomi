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
 * Unit tests for the Datacurso backend.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

use local_jedomi\ai\backend;
use local_jedomi\ai\client;
use local_jedomi\ai\datacurso_backend;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Test cases for datacurso_backend.
 *
 * @covers \local_jedomi\ai\datacurso_backend
 */
final class ai_datacurso_backend_test extends \advanced_testcase {
    /** @var string Injected base URL (the real one is resolved via a network call). */
    private const BASE_URL = 'http://ai.example.test:8002/';

    /** @var string License key injected through the constructor. */
    private const LICENSE = 'LIC-123';

    /**
     * Setup test environment; skipped when the provider is not installed.
     *
     * The license key is injected rather than written to config: modifying the
     * provider's pre-existing licensekey row makes the PHPUnit reset re-import
     * the whole config_plugins table (~9s per test), so only the tests that
     * exercise the config fallback pay that cost.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\aiprovider_datacurso\httpclient\datacurso_api_base')) {
            $this->markTestSkipped('aiprovider_datacurso is not installed');
        }
        $this->resetAfterTest();
        set_config('site_uuid', 'site-uuid-test', 'aiprovider_datacurso');
    }

    /**
     * Build a backend with a canned text response.
     *
     * @param fake_transport|null $transport
     * @param int|null $userid
     * @return array{0:datacurso_backend,1:fake_transport}
     */
    private function make(?fake_transport $transport = null, ?int $userid = null): array {
        $transport ??= new fake_transport([['body' => fake_transport::sse([fake_transport::text_chunk('ok')])]]);
        return [new datacurso_backend(self::BASE_URL, self::LICENSE, $transport, $userid), $transport];
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
     * Key and constants.
     */
    public function test_key_and_constants(): void {
        [$backend] = $this->make();
        $this->assertSame(client::BACKEND_DATACURSO, $backend->get_key());
        $this->assertSame('local_jedomi', datacurso_backend::SERVICE_ID);
        $this->assertSame('/jedomi/chat/completions', datacurso_backend::ENDPOINT_PATH);
        $this->assertSame(2026101400, datacurso_backend::MIN_PROVIDER_VERSION);
    }

    /**
     * URL is base + endpoint path; headers carry the license key and never a bearer token.
     */
    public function test_url_and_headers(): void {
        [$backend, $transport] = $this->make();

        $backend->complete('ignored', [['role' => 'user', 'content' => 'u']]);

        $call = $transport->last_call();
        $this->assertSame('http://ai.example.test:8002/jedomi/chat/completions', $call['url']);
        $this->assertContains('Content-Type: application/json', $call['headers']);
        $this->assertContains('License-Key: LIC-123', $call['headers']);
        foreach ($call['headers'] as $header) {
            $this->assertStringStartsNotWith('Authorization:', $header);
            $this->assertStringStartsNotWith('X-Service-Id:', $header);
            $this->assertStringStartsNotWith('X-RateLimit', $header, 'No rate limit headers when disabled');
        }
    }

    /**
     * Without an explicit license key the provider configuration is used.
     */
    public function test_license_key_from_provider_config(): void {
        set_config('licensekey', 'CFG-KEY', 'aiprovider_datacurso');
        $transport = new fake_transport([['body' => fake_transport::sse([fake_transport::text_chunk('ok')])]]);
        $backend = new datacurso_backend(self::BASE_URL, null, $transport);

        $backend->complete('', [['role' => 'user', 'content' => 'u']]);

        $this->assertContains('License-Key: CFG-KEY', $transport->last_call()['headers']);
    }

    /**
     * Rate limit headers from the provider are appended when enabled for local_jedomi.
     */
    public function test_rate_limit_headers(): void {
        set_config('ratelimit_local_jedomi_enable', 1, 'aiprovider_datacurso');
        set_config('ratelimit_local_jedomi_limit', 40, 'aiprovider_datacurso');
        set_config('ratelimit_local_jedomi_window', json_encode(['value' => 2, 'unit' => 'minutes']), 'aiprovider_datacurso');
        [$backend, $transport] = $this->make();

        $backend->complete('', [['role' => 'user', 'content' => 'u']]);

        $headers = $transport->last_call()['headers'];
        $this->assertContains('X-RateLimit-Enable: 1', $headers);
        $this->assertContains('X-RateLimit-Limit: 40', $headers);
        $this->assertContains('X-RateLimit-WindowSeconds: 120', $headers);
        $this->assertNotEmpty(preg_grep('/^X-RateLimit-MaxPerAction: \d+$/', $headers));
    }

    /**
     * Payload adds the Datacurso internal fields, forces usage reporting and drops extra.
     */
    public function test_payload_internal_fields(): void {
        $user = $this->getDataGenerator()->create_user();
        [$backend, $transport] = $this->make(null, (int) $user->id);
        $tools = [['type' => 'function', 'function' => ['name' => 'ws_search', 'parameters' => ['type' => 'object']]]];

        $backend->complete('', [['role' => 'user', 'content' => 'u']], $tools, [
            'max_tokens' => 1024,
            'temperature' => 0.2,
            'extra' => ['spt' => ['role' => 'admin']],
        ]);

        $payload = $transport->last_payload();
        // The token-manager model declares userid/site_id as strings and rejects integers with 422.
        $this->assertSame((string) $user->id, $payload['userid']);
        $this->assertSame('site-uuid-test', $payload['site_id']);
        $this->assertSame(['include_usage' => true], $payload['stream_options']);
        $this->assertTrue($payload['stream']);
        $this->assertSame(1024, $payload['max_tokens']);
        $this->assertSame(0.2, $payload['temperature']);
        $this->assertSame($tools, $payload['tools']);
        $this->assertSame('auto', $payload['tool_choice']);
        $this->assertArrayNotHasKey('spt', $payload, 'extra must not be forwarded to Datacurso');
    }

    /**
     * Without an explicit user id the current user is used.
     */
    public function test_payload_userid_defaults_to_current_user(): void {
        $this->setAdminUser();
        [$backend, $transport] = $this->make();

        $backend->complete('', [['role' => 'user', 'content' => 'u']]);

        $userid = $transport->last_payload()['userid'];
        $this->assertIsString($userid);
        $this->assertSame((string) get_admin()->id, $userid);
    }

    /**
     * The gateway model name is never forwarded; only datacurso_model when configured.
     */
    public function test_model_only_from_datacurso_model_config(): void {
        [$backend, $transport] = $this->make();

        $backend->complete('jedomi-e4b', [['role' => 'user', 'content' => 'u']]);
        $this->assertArrayNotHasKey('model', $transport->last_payload());

        set_config('datacurso_model', 'gemini-3-flash-preview', 'local_jedomi');
        $backend->complete('jedomi-e4b', [['role' => 'user', 'content' => 'u']]);
        $this->assertSame('gemini-3-flash-preview', $transport->last_payload()['model']);
    }

    /**
     * Happy path returns the normalised text shape.
     */
    public function test_complete_returns_text(): void {
        [$backend] = $this->make();

        $result = $backend->complete('', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(['type' => backend::TYPE_TEXT, 'content' => 'ok'], $result);
    }

    /**
     * Data provider for the HTTP error to notice mapping.
     *
     * @return array<string,array{0:int,1:string,2:string}>
     */
    public static function notice_provider(): array {
        return [
            '402 tokens' => [402, json_encode(['detail' => 'tokens_not_sufficient']), 'notenoughtokens'],
            '403 tokens' => [403, json_encode(['detail' => 'tokens_not_sufficient']), 'notenoughtokens'],
            '403 license' => [403, json_encode(['detail' => 'license_not_allowed']), 'license_not_allowed'],
            '403 nested detail' => [
                403,
                json_encode(['detail' => ['detail' => 'license_not_allowed', 'message' => 'x']]),
                'license_not_allowed',
            ],
            '401' => [401, json_encode(['detail' => 'Invalid license']), 'invalidlicensekey'],
            '401 empty body' => [401, '', 'invalidlicensekey'],
        ];
    }

    /**
     * Budget and license errors become user-facing notices with plain-text messages.
     *
     * @dataProvider notice_provider
     * @param int $code
     * @param string $body
     * @param string $stringkey
     */
    public function test_map_http_error_notices(int $code, string $body, string $stringkey): void {
        [$backend] = $this->make(new fake_transport([['code' => $code, 'body' => $body]]));

        $result = $backend->complete('', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(backend::TYPE_NOTICE, $result['type']);
        $this->assertSame(html_to_text(get_string($stringkey, 'aiprovider_datacurso'), 0, false), $result['message']);
        $this->assertStringNotContainsString('<a ', $result['message']);
    }

    /**
     * Rate limit exceeded includes the localised retry time.
     */
    public function test_map_http_error_rate_limit(): void {
        $resetat = time() + 600;
        [$backend] = $this->make(new fake_transport([
            ['code' => 403, 'body' => json_encode(['detail' => 'rate_limit_exceeded', 'reset_at' => $resetat])],
        ]));

        $result = $backend->complete('', [['role' => 'user', 'content' => 'u']]);

        $retryat = userdate($resetat, get_string('strftimedatetime', 'langconfig'));
        $expected = html_to_text(get_string('error_ratelimit_exceeded', 'aiprovider_datacurso', $retryat), 0, false);
        $this->assertSame(['type' => backend::TYPE_NOTICE, 'message' => $expected], $result);
        $this->assertStringContainsString('AI_API_RATE_LIMITED', $this->read_log());
    }

    /**
     * Unmapped HTTP errors fall through to the generic error shape.
     */
    public function test_unmapped_http_error(): void {
        [$backend] = $this->make(new fake_transport([
            ['code' => 403, 'body' => json_encode(['detail' => 'something_else'])],
        ]));

        $result = $backend->complete('', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(backend::TYPE_ERROR, $result['type']);
        $this->assertSame(403, $result['http_code']);

        [$backend] = $this->make(new fake_transport([
            ['code' => 502, 'body' => json_encode(['detail' => 'upstream_error', 'message' => 'boom'])],
        ]));
        $result = $backend->complete('', [['role' => 'user', 'content' => 'u']]);
        $this->assertSame(backend::TYPE_ERROR, $result['type']);
        $this->assertSame(502, $result['http_code']);
    }

    /**
     * A provider failure while resolving the base URL is a normalised error, not an uncaught exception.
     */
    public function test_base_url_resolution_failure_is_backend_unavailable(): void {
        $transport = new fake_transport([['body' => fake_transport::sse([fake_transport::text_chunk('ok')])]]);
        $backend = new class (null, self::LICENSE, $transport) extends datacurso_backend {
            /**
             * Simulates the provider rejecting the license during construction.
             *
             * @return object
             */
            protected function create_services_api(): object {
                throw new \moodle_exception('invalidlicensekey', 'aiprovider_datacurso');
            }
        };

        $result = $backend->complete('', [['role' => 'user', 'content' => 'u']]);

        $this->assertSame(backend::TYPE_ERROR, $result['type']);
        $this->assertSame('backend_unavailable', $result['error']);
        $this->assertSame(0, $result['http_code']);
        $this->assertSame(0, $result['curl_errno']);
        $this->assertStringStartsWith('Datacurso base URL resolution failed: ', $result['curl_error']);
        $this->assertStringContainsString(
            get_string('invalidlicensekey', 'aiprovider_datacurso'),
            $result['curl_error']
        );
        $this->assertSame([], $transport->calls, 'No request must be sent when the base URL cannot be resolved');
    }

    /**
     * Availability is a cheap check: class present and a non-empty license key.
     */
    public function test_is_available(): void {
        set_config('licensekey', self::LICENSE, 'aiprovider_datacurso');
        $this->assertTrue(datacurso_backend::is_available());

        set_config('licensekey', '', 'aiprovider_datacurso');
        $this->assertFalse(datacurso_backend::is_available());

        unset_config('licensekey', 'aiprovider_datacurso');
        $this->assertFalse(datacurso_backend::is_available());
    }

    /**
     * Provider version is read from the plugin manager and compared to the minimum.
     */
    public function test_provider_version(): void {
        $version = datacurso_backend::get_provider_version();

        $this->assertIsInt($version);
        $this->assertGreaterThan(2020000000, $version);
        $this->assertSame($version >= datacurso_backend::MIN_PROVIDER_VERSION, datacurso_backend::provider_version_ok());
    }
}
