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
     * The base address is kept without trailing slashes and without a trailing /v1.
     *
     * @dataProvider normalise_provider
     * @param string $url Address typed by the administrator.
     * @param string $expected Normalised address.
     * @return void
     */
    public function test_normalise(string $url, string $expected): void {
        $this->assertSame($expected, custom_endpoint::normalise($url));
    }

    /**
     * Addresses and their normalised form.
     *
     * @return array
     */
    public static function normalise_provider(): array {
        return [
            'plain' => ['https://api.example.com', 'https://api.example.com'],
            'trailing slash' => ['https://api.example.com/', 'https://api.example.com'],
            'with v1' => ['https://api.example.com/v1', 'https://api.example.com'],
            'with v1 and slash' => ['  https://api.example.com/v1/  ', 'https://api.example.com'],
            'with path and v1' => ['https://api.example.com/openai/v1/', 'https://api.example.com/openai'],
            'upper case v1' => ['https://api.example.com/V1', 'https://api.example.com'],
            'v1 inside a word is kept' => ['https://api.example.com/apiv1', 'https://api.example.com/apiv1'],
        ];
    }

    /**
     * Routes are built under /v1 of the normalised base address.
     *
     * @return void
     */
    public function test_url_adds_the_v1_route(): void {
        $this->assertSame('https://api.example.com/v1/models', custom_endpoint::url('https://api.example.com/v1/', '/models'));
        $this->assertSame(
            'http://localhost:11434/v1/chat/completions',
            custom_endpoint::url('http://localhost:11434', '/chat/completions')
        );
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
