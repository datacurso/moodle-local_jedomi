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

namespace local_jedomi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the configuration handed to the browser sidebar.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_jedomi_get_sidebar_config
 */
final class sidebar_config_test extends \advanced_testcase {
    /**
     * M9: the browser never receives a gateway URL or an API key, even on a site with legacy settings.
     */
    public function test_sidebar_config_has_no_gateway_url_or_api_key(): void {
        global $PAGE;
        $this->resetAfterTest();
        set_config('gateway_url', 'http://legacy-gateway.invalid', 'local_jedomi');
        set_config('api_key', 'legacy-key-value', 'local_jedomi');
        $this->setUser($this->getDataGenerator()->create_user());
        $PAGE->set_url('/local/jedomi/ajax/sidebar_config.php');

        $config = local_jedomi_get_sidebar_config();

        $this->assertArrayNotHasKey('gateway_url', $config);
        $this->assertArrayNotHasKey('api_key', $config);
        $encoded = json_encode($config);
        $this->assertStringNotContainsString('legacy-gateway.invalid', $encoded);
        $this->assertStringNotContainsString('legacy-key-value', $encoded);
        $this->assertStringEndsWith('/local/jedomi/ajax/feedback.php', $config['feedback_url']);
        $this->assertStringEndsWith('/local/jedomi/chatproxy.php', $config['chatproxy_url']);
    }
}
