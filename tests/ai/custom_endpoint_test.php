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

/**
 * Tests for the address policy of the custom AI service.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\custom_endpoint
 */
final class custom_endpoint_test extends \advanced_testcase {
    /**
     * Block the private networks and allow only the standard ports, as a default Moodle site does.
     *
     * @return void
     */
    private function use_default_curl_security(): void {
        set_config('curlsecurityblockedhosts', "127.0.0.0/8\n192.168.0.0/16\n10.0.0.0/8\n172.16.0.0/12\nlocalhost\n"
            . "169.254.169.254\n0000::1");
        set_config('curlsecurityallowedport', "443\n80");
    }

    /**
     * The base address becomes the root of the API: without a path /v1 is added, with a path it is kept as given.
     *
     * @dataProvider normalise_provider
     * @param string $url Address typed by the administrator.
     * @param string $expected Root of the API.
     * @return void
     */
    public function test_normalise(string $url, string $expected): void {
        $this->assertSame($expected, custom_endpoint::normalise($url));
        $this->assertSame($expected, custom_endpoint::normalise($expected), 'Normalising twice changes nothing');
    }

    /**
     * Addresses and the root of their API.
     *
     * @return array
     */
    public static function normalise_provider(): array {
        return [
            'no path' => ['https://api.openai.com', 'https://api.openai.com/v1'],
            'only a slash' => ['https://api.openai.com/', 'https://api.openai.com/v1'],
            'local without path' => ['http://localhost:11434', 'http://localhost:11434/v1'],
            'local without path and slashes' => [' http://localhost:11434// ', 'http://localhost:11434/v1'],
            'openai with v1' => ['https://api.openai.com/v1', 'https://api.openai.com/v1'],
            'spaces and slash' => ['  https://api.openai.com/v1/  ', 'https://api.openai.com/v1'],
            'deepinfra' => ['https://api.deepinfra.com/v1/openai', 'https://api.deepinfra.com/v1/openai'],
            'groq' => ['https://api.groq.com/openai/v1', 'https://api.groq.com/openai/v1'],
            'openrouter' => ['https://openrouter.ai/api/v1', 'https://openrouter.ai/api/v1'],
            'local with v1' => ['http://localhost:11434/v1', 'http://localhost:11434/v1'],
            'custom path' => ['https://gw.example.com/custom/path/', 'https://gw.example.com/custom/path'],
            'upper case V1 is kept' => ['https://api.example.com/V1', 'https://api.example.com/V1'],
            'query is left for the check' => ['https://api.example.com/?a=1', 'https://api.example.com/?a=1'],
            'fragment is left for the check' => ['https://api.example.com#x', 'https://api.example.com#x'],
            'empty' => ['  ', ''],
        ];
    }

    /**
     * Routes are added to the root of the API.
     *
     * @dataProvider url_provider
     * @param string $base Address typed by the administrator.
     * @param string $route Route of the OpenAI API.
     * @param string $expected Full address of the request.
     * @return void
     */
    public function test_url(string $base, string $route, string $expected): void {
        $this->assertSame($expected, custom_endpoint::url($base, $route));
    }

    /**
     * Addresses of the documented providers and the full address of each route.
     *
     * @return array
     */
    public static function url_provider(): array {
        return [
            'openai without path' => ['https://api.openai.com', '/models', 'https://api.openai.com/v1/models'],
            'openai with v1' => ['https://api.openai.com/v1', '/chat/completions', 'https://api.openai.com/v1/chat/completions'],
            'ollama without path' => ['http://localhost:11434', '/chat/completions', 'http://localhost:11434/v1/chat/completions'],
            'ollama with v1' => ['http://localhost:11434/v1', '/models', 'http://localhost:11434/v1/models'],
            'deepinfra models' => ['https://api.deepinfra.com/v1/openai', '/models', 'https://api.deepinfra.com/v1/openai/models'],
            'deepinfra transcriptions' => [
                'https://api.deepinfra.com/v1/openai',
                '/audio/transcriptions',
                'https://api.deepinfra.com/v1/openai/audio/transcriptions',
            ],
            'groq' => ['https://api.groq.com/openai/v1', '/audio/speech', 'https://api.groq.com/openai/v1/audio/speech'],
            'openrouter' => ['https://openrouter.ai/api/v1', '/chat/completions', 'https://openrouter.ai/api/v1/chat/completions'],
            'custom path' => ['https://gw.example.com/custom/path/', '/models', 'https://gw.example.com/custom/path/models'],
        ];
    }

    /**
     * Malformed addresses, other schemes, credentials, queries and fragments are refused.
     *
     * @dataProvider invalid_url_provider
     * @param string $url Address.
     * @return void
     */
    public function test_check_rejects_invalid_urls(string $url): void {
        $this->resetAfterTest();
        $this->assertSame(custom_endpoint::ERROR_INVALID_URL, custom_endpoint::check($url, true));
        $this->assertSame(
            custom_endpoint::ERROR_INVALID_URL,
            custom_endpoint::check(custom_endpoint::normalise($url), true),
            'Normalising never makes an invalid address valid'
        );
    }

    /**
     * Addresses that are never accepted.
     *
     * @return array
     */
    public static function invalid_url_provider(): array {
        return [
            'empty' => [''],
            'no scheme' => ['api.example.com'],
            'ftp' => ['ftp://api.example.com'],
            'file' => ['file:///etc/passwd'],
            'credentials' => ['https://user:secret@api.example.com'],
            'query' => ['https://api.example.com/?a=1'],
            'fragment' => ['https://api.example.com/#x'],
            'query without path' => ['https://api.example.com?a=1'],
            'query with path' => ['https://api.deepinfra.com/v1/openai?a=1'],
            'fragment without path' => ['https://api.example.com#x'],
            'no host' => ['https:///v1'],
            'spaces' => ['https://api example.com'],
        ];
    }

    /**
     * Plain HTTP is refused unless HTTP for local models is allowed and the host is local.
     *
     * @return void
     */
    public function test_check_http_rules(): void {
        $this->resetAfterTest();
        $this->use_default_curl_security();

        $this->assertSame(custom_endpoint::ERROR_HTTP_NOT_ALLOWED, custom_endpoint::check('http://localhost:11434', false));
        $this->assertSame(custom_endpoint::ERROR_HTTP_NOT_ALLOWED, custom_endpoint::check('http://203.0.113.10', true));
        $this->assertNull(custom_endpoint::check('http://localhost:11434', true));
        $this->assertNull(custom_endpoint::check('http://127.0.0.1:1234/v1', true));
        $this->assertNull(custom_endpoint::check('http://192.168.1.20:8000', true));
        $this->assertNull(custom_endpoint::check('http://[::1]:8000', true));
    }

    /**
     * The blocked hosts and allowed ports of the site apply, except to the local host allowed by the checkbox.
     *
     * @return void
     */
    public function test_check_respects_curl_security(): void {
        $this->resetAfterTest();
        $this->use_default_curl_security();

        $this->assertSame(custom_endpoint::ERROR_BLOCKED_HOST, custom_endpoint::check('https://127.0.0.1', false));
        $this->assertSame(custom_endpoint::ERROR_BLOCKED_HOST, custom_endpoint::check('https://10.1.2.3', false));
        $this->assertSame(custom_endpoint::ERROR_BLOCKED_HOST, custom_endpoint::check('https://203.0.113.10:8443', false));
        $this->assertNull(custom_endpoint::check('https://203.0.113.10', false));
        // The cloud metadata address is never a local model, even with the checkbox on.
        $this->assertSame(custom_endpoint::ERROR_BLOCKED_HOST, custom_endpoint::check('https://169.254.169.254', true));
        $this->assertSame(custom_endpoint::ERROR_HTTP_NOT_ALLOWED, custom_endpoint::check('http://169.254.169.254', true));
        // With the checkbox on, the local host is allowed over https too.
        $this->assertNull(custom_endpoint::check('https://10.1.2.3:8443', true));
    }

    /**
     * Local hosts: loopback and private networks, never link-local or public addresses.
     *
     * @dataProvider local_host_provider
     * @param string $host Host.
     * @param bool $expected Whether it is local.
     * @return void
     */
    public function test_is_local_host(string $host, bool $expected): void {
        $this->assertSame($expected, custom_endpoint::is_local_host($host));
    }

    /**
     * Hosts and whether they are local.
     *
     * @return array
     */
    public static function local_host_provider(): array {
        return [
            'localhost' => ['localhost', true],
            'loopback' => ['127.0.0.1', true],
            'private 10' => ['10.20.30.40', true],
            'private 172' => ['172.20.0.5', true],
            'private 192' => ['192.168.0.10', true],
            'ipv6 loopback' => ['[::1]', true],
            'ipv6 unique local' => ['fd00::1', true],
            'link local' => ['169.254.169.254', false],
            'public' => ['203.0.113.10', false],
            'public 172' => ['172.32.0.1', false],
            'unspecified' => ['0.0.0.0', false],
        ];
    }

    /**
     * Requests never follow redirects, have short connection timeouts and are pinned to the checked address of a
     * local host, so a DNS change between the check and the request cannot send them elsewhere.
     *
     * @return void
     */
    public function test_curl_options(): void {
        $this->resetAfterTest();
        $this->use_default_curl_security();

        $public = custom_endpoint::curl_options('https://203.0.113.10/v1/models', false, 10);
        $this->assertFalse($public['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $public['CURLOPT_MAXREDIRS']);
        $this->assertSame(10, $public['CURLOPT_TIMEOUT']);
        $this->assertLessThanOrEqual(10, $public['CURLOPT_CONNECTTIMEOUT']);
        $this->assertArrayNotHasKey('CURLOPT_RESOLVE', $public);
        $this->assertFalse(custom_endpoint::bypasses_curl_security('https://203.0.113.10/v1/models', true));

        $local = custom_endpoint::curl_options('http://localhost:11434/v1/models', true, 10);
        $this->assertSame(['localhost:11434:127.0.0.1'], $local['CURLOPT_RESOLVE']);
        $this->assertTrue(custom_endpoint::bypasses_curl_security('http://localhost:11434/v1/models', true));
        $this->assertFalse(custom_endpoint::bypasses_curl_security('http://localhost:11434/v1/models', false));
    }
}
