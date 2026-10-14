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
 * Unit tests for the AI backend factory.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

use local_jedomi\ai\client;
use local_jedomi\ai\datacurso_backend;
use local_jedomi\ai\gateway_backend;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_transport.php');

/**
 * Test cases for client.
 *
 * @covers \local_jedomi\ai\client
 */
final class ai_client_test extends \advanced_testcase {
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
     * Public constants used by the call sites.
     */
    public function test_constants(): void {
        $this->assertSame('gateway', client::BACKEND_GATEWAY);
        $this->assertSame('datacurso', client::BACKEND_DATACURSO);
        $this->assertSame('jedomi-e4b', client::DEFAULT_MODEL);
        $this->assertSame(16384, client::DEFAULT_MAX_TOKENS);
        $this->assertSame(256, client::MIN_MAX_TOKENS);
    }

    /**
     * Data provider for resolve_backend_key.
     *
     * @return array<string,array{0:string,1:bool,2:string}>
     */
    public static function resolve_provider(): array {
        return [
            'empty' => ['', true, client::BACKEND_GATEWAY],
            'gateway' => ['gateway', true, client::BACKEND_GATEWAY],
            'unknown' => ['openai', true, client::BACKEND_GATEWAY],
            'datacurso available' => ['datacurso', true, client::BACKEND_DATACURSO],
            'datacurso unavailable' => ['datacurso', false, client::BACKEND_GATEWAY],
            'case and spaces' => [' Datacurso ', true, client::BACKEND_DATACURSO],
        ];
    }

    /**
     * resolve_backend_key is a pure function of the configured value and availability.
     *
     * @dataProvider resolve_provider
     * @param string $configured
     * @param bool $available
     * @param string $expected
     */
    public function test_resolve_backend_key(string $configured, bool $available, string $expected): void {
        $this->assertSame($expected, client::resolve_backend_key($configured, $available));
    }

    /**
     * Default configuration resolves to the gateway and creates a gateway backend.
     */
    public function test_default_backend_is_gateway(): void {
        $this->assertSame(client::BACKEND_GATEWAY, client::backend_key());
        $this->assertInstanceOf(gateway_backend::class, client::create(new fake_transport()));
        $this->assertStringNotContainsString('AI_BACKEND_FALLBACK', $this->read_log());
    }

    /**
     * When datacurso is configured but unavailable the factory falls back and logs once.
     */
    public function test_fallback_logged_once(): void {
        set_config('ai_backend', 'datacurso', 'local_jedomi');
        if (class_exists('\aiprovider_datacurso\httpclient\ai_services_api')) {
            unset_config('licensekey', 'aiprovider_datacurso');
        }

        $this->assertSame(client::BACKEND_GATEWAY, client::backend_key());
        $this->assertInstanceOf(gateway_backend::class, client::create(new fake_transport()));
        $this->assertSame(client::BACKEND_GATEWAY, client::backend_key());

        $this->assertSame(1, substr_count($this->read_log(), 'AI_BACKEND_FALLBACK'));
    }

    /**
     * When datacurso is configured and available the factory returns the Datacurso backend.
     */
    public function test_create_datacurso_when_available(): void {
        if (!class_exists('\aiprovider_datacurso\httpclient\ai_services_api')) {
            $this->markTestSkipped('aiprovider_datacurso is not installed');
        }
        set_config('ai_backend', 'datacurso', 'local_jedomi');
        set_config('licensekey', 'LIC', 'aiprovider_datacurso');

        $this->assertSame(client::BACKEND_DATACURSO, client::backend_key());
        $backend = client::create(new fake_transport());
        $this->assertInstanceOf(datacurso_backend::class, $backend);
        $this->assertSame(client::BACKEND_DATACURSO, $backend->get_key());
        $this->assertStringNotContainsString('AI_BACKEND_FALLBACK', $this->read_log());
    }

    /**
     * Model comes from config with the historical default.
     */
    public function test_get_model(): void {
        $this->assertSame(client::DEFAULT_MODEL, client::get_model());
        set_config('model', 'custom-model', 'local_jedomi');
        $this->assertSame('custom-model', client::get_model());
        set_config('model', '', 'local_jedomi');
        $this->assertSame(client::DEFAULT_MODEL, client::get_model());
    }

    /**
     * max_tokens comes from config, defaults when unset and is clamped to the minimum.
     */
    public function test_get_max_tokens(): void {
        $this->assertSame(client::DEFAULT_MAX_TOKENS, client::get_max_tokens());
        set_config('max_tokens', 4096, 'local_jedomi');
        $this->assertSame(4096, client::get_max_tokens());
        set_config('max_tokens', 10, 'local_jedomi');
        $this->assertSame(client::MIN_MAX_TOKENS, client::get_max_tokens());
        set_config('max_tokens', 0, 'local_jedomi');
        $this->assertSame(client::DEFAULT_MAX_TOKENS, client::get_max_tokens());
    }

    /**
     * default_options carries the configured max_tokens and the standard timeout.
     */
    public function test_default_options(): void {
        set_config('max_tokens', 2048, 'local_jedomi');
        $options = client::default_options();
        $this->assertSame(2048, $options['max_tokens']);
        $this->assertSame(180, $options['timeout']);
        $this->assertArrayNotHasKey('temperature', $options);
    }
}
