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

namespace local_jedomi\telegram;

/**
 * Tests for the conversion of AI answers to Telegram HTML.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\formatter
 */
final class formatter_test extends \advanced_testcase {
    /**
     * JEDOMI-SEC-005: a link to another site shows its host after the link text, so the reader sees where it goes.
     */
    public function test_external_link_shows_its_host(): void {
        $html = formatter::to_html('See [the feedback](https://Attacker.example:8443/r?a=1&b=2).');

        $this->assertSame(
            'See <a href="https://Attacker.example:8443/r?a=1&amp;b=2">the feedback</a> (↗ attacker.example).',
            $html
        );
    }

    /**
     * JEDOMI-SEC-005: an HTML link to another site gets its host too, also when the AI left it open; a host hidden
     * after a user name is the one shown.
     */
    public function test_external_html_link_shows_its_host(): void {
        global $CFG;
        $sitehost = parse_url($CFG->wwwroot, PHP_URL_HOST);

        $html = formatter::to_html('<a href="https://' . $sitehost . '@attacker.example/x">your grades</a> and '
            . '<a href="http://other.example/">open');

        $this->assertSame(
            '<a href="https://' . $sitehost . '@attacker.example/x">your grades</a> (↗ attacker.example) and '
                . '<a href="http://other.example/">open</a> (↗ other.example)',
            $html
        );
    }

    /**
     * JEDOMI-SEC-005: links to the site, whether absolute or relative, are shown as before.
     */
    public function test_site_links_are_unchanged(): void {
        global $CFG;

        $html = formatter::to_html("[Course]({$CFG->wwwroot}/course/view.php?id=2) and [profile](/user/profile.php)");

        $this->assertSame(
            '<a href="' . $CFG->wwwroot . '/course/view.php?id=2">Course</a> and <a href="/user/profile.php">profile</a>',
            $html
        );
    }

    /**
     * JEDOMI-SEC-005: a link of another scheme (tg:, mailto:) is shown as its text, never as a link.
     */
    public function test_links_of_other_schemes_are_plain_text(): void {
        $this->assertSame(
            'Open the chat and write to us',
            formatter::to_html('Open [the chat](tg://resolve?domain=x&text=[person_1]) and '
                . '<a href="mailto:a@attacker.example">write to us</a>')
        );
        $this->assertSame('call', formatter::to_html("<a href=\"java\tscript:alert(1)\">call</a>"));
    }
}
