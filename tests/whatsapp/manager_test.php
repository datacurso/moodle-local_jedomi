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

namespace local_jedomi\whatsapp;

use local_jedomi\local\secret_store;

/**
 * Tests for the settings of the WhatsApp channel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\manager
 */
final class manager_test extends \advanced_testcase {
    /**
     * Save every required setting.
     */
    private static function configure_all(): void {
        manager::set_phone_number_id('106540352242922');
        manager::set_waba_id('102290129340398');
        manager::set_access_token('EAAGtoken-plain-value');
        manager::set_app_secret('app-secret-plain-value');
        manager::set_verify_token('verify-token-plain-value');
    }

    /**
     * Secret settings: setter, getter, config name and value.
     *
     * @return array
     */
    public static function secret_setting_provider(): array {
        return [
            'phone number id' => ['set_phone_number_id', 'get_phone_number_id', 'whatsapp_phone_number_id', '106540352242922'],
            'waba id' => ['set_waba_id', 'get_waba_id', 'whatsapp_waba_id', '102290129340398'],
            'access token' => ['set_access_token', 'get_access_token', 'whatsapp_access_token', 'EAAGtoken-plain-value'],
            'app secret' => ['set_app_secret', 'get_app_secret', 'whatsapp_app_secret', 'app-secret-plain-value'],
            'verify token' => ['set_verify_token', 'get_verify_token', 'whatsapp_verify_token', 'verify-token-plain-value'],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider secret_setting_provider
     * @param string $setter Setter.
     * @param string $getter Getter.
     * @param string $name Config name.
     * @param string $value Value.
     */
    public function test_secret_setting_round_trips_and_is_encrypted_at_rest(
        string $setter,
        string $getter,
        string $name,
        string $value
    ): void {
        $this->resetAfterTest();

        manager::$setter($value);

        $this->assertSame($value, manager::$getter());
        $raw = (string) get_config('local_jedomi', $name);
        $this->assertNotSame($value, $raw);
        $this->assertStringNotContainsString($value, $raw);
        $this->assertTrue(secret_store::is_encrypted($raw));
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider secret_setting_provider
     * @param string $setter Setter.
     * @param string $getter Getter.
     * @param string $name Config name.
     * @param string $value Value.
     */
    public function test_secret_setting_is_empty_by_default_and_can_be_cleared(
        string $setter,
        string $getter,
        string $name,
        string $value
    ): void {
        $this->resetAfterTest();

        $this->assertSame('', manager::$getter());
        manager::$setter($value);
        manager::$setter('');
        $this->assertSame('', manager::$getter());
        $this->assertSame('', (string) get_config('local_jedomi', $name));
    }

    public function test_values_are_trimmed(): void {
        $this->resetAfterTest();

        manager::set_access_token("  EAAGtoken \n");
        manager::set_phone_number_id(' 106540352242922 ');

        $this->assertSame('EAAGtoken', manager::get_access_token());
        $this->assertSame('106540352242922', manager::get_phone_number_id());
    }

    /**
     * Identifiers of Meta that are not numeric.
     *
     * @return array
     */
    public static function invalid_id_provider(): array {
        return [
            'path traversal' => ['../me'],
            'letters' => ['abc123'],
            'slash' => ['123/messages'],
            'query' => ['123?fields=x'],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider invalid_id_provider
     * @param string $id Identifier.
     */
    public function test_non_numeric_phone_number_id_is_rejected(string $id): void {
        $this->resetAfterTest();

        try {
            manager::set_phone_number_id($id);
            $this->fail('A non-numeric phone number ID was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('config_value_invalid_msg', $e->errorcode);
        }
        $this->assertSame('', manager::get_phone_number_id());
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider invalid_id_provider
     * @param string $id Identifier.
     */
    public function test_non_numeric_waba_id_is_rejected(string $id): void {
        $this->resetAfterTest();

        $this->expectException(\moodle_exception::class);
        manager::set_waba_id($id);
    }

    public function test_graph_api_version_defaults_to_v25(): void {
        $this->resetAfterTest();

        $this->assertSame('v25.0', manager::DEFAULT_GRAPH_API_VERSION);
        $this->assertSame('v25.0', manager::get_graph_api_version());
    }

    public function test_graph_api_version_round_trips_in_plain_config(): void {
        $this->resetAfterTest();

        manager::set_graph_api_version(' v26.1 ');

        $this->assertSame('v26.1', manager::get_graph_api_version());
        $this->assertSame('v26.1', get_config('local_jedomi', 'whatsapp_graph_api_version'));
    }

    public function test_empty_graph_api_version_restores_the_default(): void {
        $this->resetAfterTest();
        manager::set_graph_api_version('v26.0');

        manager::set_graph_api_version('');

        $this->assertSame('v25.0', manager::get_graph_api_version());
    }

    /**
     * Versions that do not have the form vN.N.
     *
     * @return array
     */
    public static function invalid_version_provider(): array {
        return [
            'no v' => ['25.0'],
            'no minor' => ['v25'],
            'uppercase' => ['V25.0'],
            'path' => ['v25.0/../me'],
            'suffix' => ['v25.0a'],
            'newline' => ["v25.0\nx"],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider invalid_version_provider
     * @param string $version Version.
     */
    public function test_invalid_graph_api_version_is_rejected(string $version): void {
        $this->resetAfterTest();

        $this->assertFalse(manager::is_valid_graph_api_version($version));
        try {
            manager::set_graph_api_version($version);
            $this->fail('An invalid Graph API version was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('config_value_invalid_msg', $e->errorcode);
        }
        $this->assertSame('v25.0', manager::get_graph_api_version());
    }

    public function test_invalid_stored_graph_api_version_falls_back_to_the_default(): void {
        $this->resetAfterTest();

        set_config('whatsapp_graph_api_version', 'v25.0/../../me', 'local_jedomi');

        $this->assertSame('v25.0', manager::get_graph_api_version());
    }

    public function test_is_configured_needs_every_required_setting(): void {
        $this->resetAfterTest();

        $this->assertFalse(manager::is_configured());
        self::configure_all();
        $this->assertTrue(manager::is_configured());

        foreach (['set_phone_number_id', 'set_waba_id', 'set_access_token', 'set_app_secret', 'set_verify_token'] as $setter) {
            self::configure_all();
            manager::$setter('');
            $this->assertFalse(manager::is_configured(), $setter . ' cleared');
        }
    }

    public function test_is_configured_is_false_when_a_secret_cannot_be_decrypted(): void {
        $this->resetAfterTest();
        self::configure_all();

        set_config('whatsapp_access_token', 'sodium:AAAA', 'local_jedomi');

        $this->assertSame('', manager::get_access_token());
        $this->assertFalse(manager::is_configured());
    }

    public function test_enabled_flag(): void {
        $this->resetAfterTest();

        $this->assertFalse(manager::is_enabled());
        manager::set_enabled(true);
        $this->assertTrue(manager::is_enabled());
        $this->assertSame('1', get_config('local_jedomi', 'whatsapp_enabled'));
        manager::set_enabled(false);
        $this->assertFalse(manager::is_enabled());
    }

    public function test_secrets_are_never_logged(): void {
        global $CFG;
        $this->resetAfterTest();
        $logfile = $CFG->dataroot . '/local/jedomi/jedomi.log';
        if (file_exists($logfile)) {
            unlink($logfile);
        }

        self::configure_all();
        manager::get_access_token();
        set_config('whatsapp_app_secret', 'sodium:AAAA', 'local_jedomi');
        manager::get_app_secret();

        $log = file_exists($logfile) ? (string) file_get_contents($logfile) : '';
        $this->assertStringNotContainsString('EAAGtoken-plain-value', $log);
        $this->assertStringNotContainsString('app-secret-plain-value', $log);
        $this->assertStringNotContainsString('verify-token-plain-value', $log);
    }

    public function test_the_client_has_a_single_entry_point(): void {
        // The client is obtained only through cloud_api_client::instance(), as the Telegram client.
        $this->assertFalse(method_exists(manager::class, 'client'));
    }
}
