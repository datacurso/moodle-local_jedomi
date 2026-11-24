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

/**
 * Tests for the Telegram enable switch web service.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\set_telegram_enabled
 */
final class set_telegram_enabled_test extends \advanced_testcase {
    /**
     * Call the web service and clean its result like the AJAX layer does.
     *
     * @param bool $enabled Requested state.
     * @return array
     */
    private function call(bool $enabled): array {
        return external_api::clean_returnvalue(
            set_telegram_enabled::execute_returns(),
            set_telegram_enabled::execute($enabled)
        );
    }

    /**
     * MDL-INT-012: an administrator turns the integration on.
     * Enabling the switch stores 1 and returns the enabled state.
     */
    public function test_admin_can_enable_telegram(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('telegram_enabled', 0, 'local_jedomi');

        $result = $this->call(true);

        $this->assertTrue($result['enabled']);
        $this->assertSame('1', get_config('local_jedomi', 'telegram_enabled'));
    }

    /**
     * MDL-INT-012: an administrator turns the integration off.
     * Disabling the switch stores 0 and returns the disabled state.
     */
    public function test_admin_can_disable_telegram(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('telegram_enabled', 1, 'local_jedomi');

        $result = $this->call(false);

        $this->assertFalse($result['enabled']);
        $this->assertSame('0', get_config('local_jedomi', 'telegram_enabled'));
    }

    /**
     * MDL-INT-012: the stored state is returned.
     * The returned state is the stored one, read back after saving.
     */
    public function test_returns_the_stored_state(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertTrue($this->call(true)['enabled']);
        $this->assertTrue($this->call(true)['enabled']);
        $this->assertFalse($this->call(false)['enabled']);
        $this->assertSame('0', get_config('local_jedomi', 'telegram_enabled'));
    }

    /**
     * MDL-INT-012: users without the site configuration permission cannot change it.
     * Users without moodle/site:config cannot change the setting.
     */
    public function test_requires_site_config_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        set_config('telegram_enabled', 0, 'local_jedomi');

        try {
            set_telegram_enabled::execute(true);
            $this->fail('required_capability_exception expected');
        } catch (\required_capability_exception $e) {
            $this->assertSame('0', get_config('local_jedomi', 'telegram_enabled'));
        }
    }

    /**
     * MDL-INT-012: the service is available to the browser.
     * The function is declared for AJAX and restricted to site administrators.
     */
    public function test_service_is_declared_for_ajax(): void {
        $functions = [];
        require(__DIR__ . '/../../db/services.php');

        $this->assertArrayHasKey('local_jedomi_set_telegram_enabled', $functions);
        $declaration = $functions['local_jedomi_set_telegram_enabled'];
        $this->assertSame('local_jedomi\external\set_telegram_enabled', $declaration['classname']);
        $this->assertSame('write', $declaration['type']);
        $this->assertTrue($declaration['ajax']);
        $this->assertSame('moodle/site:config', $declaration['capabilities']);
    }
}
