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

namespace local_jedomi\local;

/**
 * Tests for telling links to other sites from links to the site.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\external_link
 */
final class external_link_test extends \advanced_testcase {
    /**
     * Addresses and whether each one opens another site.
     *
     * @return array
     */
    public static function address_provider(): array {
        return [
            'other site' => ['https://attacker.example/r?n=1', true],
            'other site, http' => ['http://attacker.example/', true],
            'protocol relative' => ['//attacker.example/x', true],
            'backslashes' => ['/\\attacker.example/x', true],
            'one slash' => ['https:/attacker.example/x', true],
            'user name before the host' => ['https://{host}@attacker.example/', true],
            'backslash ends the host' => ['https://attacker.example\\@{host}/', true],
            'site address in the query' => ['https://attacker.example/?u={wwwroot}', true],
            'subdomain of the site' => ['https://evil.{host}/', true],
            'site' => ['{wwwroot}/course/view.php?id=2', false],
            'site, upper case and port' => ['HTTPS://{HOST}:443/x', false],
            'relative path' => ['/user/profile.php', false],
            'mail' => ['mailto:someone@attacker.example', false],
        ];
    }

    /**
     * JEDOMI-SEC-005: only an address whose host is exactly the host of the site is internal.
     *
     * @dataProvider address_provider
     * @param string $url Address, with {wwwroot}, {host} and {HOST} replaced by the site address and host.
     * @param bool $external Whether it opens another site.
     * @return void
     */
    public function test_is_external(string $url, bool $external): void {
        global $CFG;
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST);
        $url = str_replace(['{wwwroot}', '{host}', '{HOST}'], [$CFG->wwwroot, $host, strtoupper($host)], $url);

        $this->assertSame($external, external_link::is_external($url), $url);
    }

    /**
     * The host is read in lower case, without user name or port.
     */
    public function test_host(): void {
        $this->assertSame('attacker.example', external_link::host('https://me:pw@Attacker.Example:8443/x'));
        $this->assertNull(external_link::host('/relative'));
        $this->assertNull(external_link::host('javascript:alert(1)'));
    }
}
