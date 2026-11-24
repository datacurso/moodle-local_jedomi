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

namespace local_jedomi\external;

use local_jedomi\local\site_config_policy;

/**
 * Tests that local_jedomi_set_site_config writes only the settings of its allowlist (JEDOMI-SEC-011).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\set_site_config
 * @covers     \local_jedomi\local\site_config_policy
 */
final class set_site_config_allowlist_test extends \advanced_testcase {
    /** @var string Secret of the site used by the tests. */
    private const SECRET = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2';

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the web services check the plugin state through it.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('hmac_secret', self::SECRET, 'local_jedomi');
    }

    /**
     * Call the web service with a valid signature.
     *
     * @param string $name Setting name.
     * @param string $value Value.
     * @param string $plugin Plugin, empty for core.
     * @return array Result of the web service.
     */
    private function call(string $name, string $value, string $plugin): array {
        $params = ['name' => $name, 'value' => $value, 'plugin' => $plugin];
        ksort($params);
        $hash = hash('sha256', json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $now = time();
        $signature = hash_hmac('sha256', "local_jedomi_set_site_config:{$now}:{$hash}", self::SECRET);
        return set_site_config::execute($name, $value, $plugin, $signature, $now, $hash);
    }

    /**
     * Settings outside the allowlist: endpoints of the AI providers of Moodle, security settings and unknown keys.
     *
     * @return array
     */
    public static function not_allowed_provider(): array {
        return [
            'Azure AI provider endpoint' => ['endpoint', 'aiprovider_azureai', 'https://attacker.example/openai'],
            'OpenAI provider text endpoint' => ['action_generate_text_endpoint', 'aiprovider_openai',
                'https://attacker.example/v1/chat/completions'],
            'OpenAI provider image endpoint' => ['action_generate_image_endpoint', 'aiprovider_openai',
                'https://attacker.example/v1/images'],
            'Datacurso AI provider setting not listed as secret' => ['servicesurl', 'aiprovider_datacurso',
                'https://attacker.example/'],
            'core security setting' => ['forcelogin', '', '0'],
            'core security setting in upper case' => ['FORCELOGIN', '', '0'],
            'unknown core key' => ['jedomi_no_such_setting', '', 'value'],
            'unknown key of this plugin' => ['jedomi_signature_probe', 'local_jedomi', 'value'],
            'setting of this plugin' => ['course_mode', 'local_jedomi', 'all'],
            'allowed name under another plugin' => ['fullname', 'local_jedomi', 'Renamed'],
        ];
    }

    /**
     * A setting outside the allowlist is refused with a message that lists the allowed settings, and nothing is written
     * to the settings nor to the config log.
     *
     * @dataProvider not_allowed_provider
     * @param string $name Setting name.
     * @param string $plugin Plugin, empty for core.
     * @param string $value Value.
     * @return void
     */
    public function test_setting_outside_the_allowlist_is_refused(string $name, string $plugin, string $value): void {
        global $DB;
        $component = $plugin === '' ? 'core' : $plugin;
        $before = get_config($component, \core_text::strtolower($name));
        $logs = $DB->count_records('config_log');

        $result = $this->call($name, $value, $plugin);

        $this->assertSame('error', $result['status']);
        $this->assertSame(
            get_string('config_key_not_allowed_msg', 'local_jedomi', site_config_policy::allowed_keys_list()),
            $result['message']
        );
        $this->assertStringContainsString('moodlecourse/numsections', $result['message']);
        $this->assertSame($before, get_config($component, \core_text::strtolower($name)), 'The setting is not changed');
        $this->assertSame($logs, $DB->count_records('config_log'), 'Nothing is written to the config log');
    }

    /**
     * Settings of the allowlist with a valid value.
     *
     * @return array
     */
    public static function allowed_provider(): array {
        return [
            'site short name' => ['shortname', '', 'NEWSITE', 'shortname'],
            'default timezone' => ['timezone', '', 'Europe/Madrid', 'timezone'],
            'default country' => ['country', '', 'CO', 'country'],
            'default city' => ['defaultcity', '', 'Bogotá', 'defaultcity'],
            'first day of the week' => ['calendar_startwday', '', '0', 'calendar_startwday'],
            'default language' => ['lang', '', 'en', 'lang'],
            'course default sections' => ['numsections', 'moodlecourse', '7', 'numsections'],
            'course default format' => ['format', 'moodlecourse', 'weeks', 'format'],
            'course default visibility' => ['visible', 'moodlecourse', '0', 'visible'],
            'site full name in mixed case' => ['FullName', '', 'Renamed site', 'fullname'],
            'course default sections in upper case' => ['NUMSECTIONS', 'moodlecourse', '3', 'numsections'],
        ];
    }

    /**
     * A component sent in another case is never stored as a new component (REL-001).
     *
     * @return void
     */
    public function test_component_in_upper_case_is_never_stored_under_another_component(): void {
        global $DB;
        try {
            $this->call('numsections', '4', 'MoodleCourse');
        } catch (\invalid_parameter_exception $e) {
            // The component is refused by the parameter type: nothing is written either.
            $this->assertNotEmpty($e->getMessage());
        }

        $components = $DB->get_fieldset_select('config_plugins', 'plugin', 'name = :name', ['name' => 'numsections']);
        $this->assertNotContains('MoodleCourse', $components);
    }

    /**
     * A setting of the allowlist is written under its own name and recorded in the config log with its old and new
     * value.
     *
     * @dataProvider allowed_provider
     * @param string $name Setting name sent.
     * @param string $plugin Plugin, empty for core.
     * @param string $value Value.
     * @param string $stored Name the setting is stored under.
     * @return void
     */
    public function test_allowed_setting_is_written_and_logged(string $name, string $plugin, string $value, string $stored): void {
        global $DB;
        $component = $plugin === '' ? 'core' : $plugin;
        set_config($stored, 'previous', $plugin === '' ? null : $plugin);
        $lastlog = (int) $DB->get_field_sql('SELECT MAX(id) FROM {config_log}');

        $result = $this->call($name, $value, $plugin);

        $this->assertSame('success', $result['status'], $result['message'] ?? '');
        $this->assertSame($value, get_config($component, $stored));
        $logs = $DB->get_records_select('config_log', 'id > :lastlog', ['lastlog' => $lastlog]);
        $this->assertCount(1, $logs);
        $log = reset($logs);
        $this->assertSame($stored, $log->name, 'Stored under its own name, whatever the case it was sent in');
        $this->assertSame($plugin === '' ? null : $plugin, $log->plugin);
        $this->assertSame('previous', $log->oldvalue);
        $this->assertSame($value, $log->value);
        $this->assertSame((int) get_admin()->id, (int) $log->userid);
    }

    /**
     * Settings of the allowlist with a value of the wrong type or out of range.
     *
     * @return array
     */
    public static function invalid_value_provider(): array {
        return [
            'unknown timezone' => ['timezone', '', 'Mars/Olympus'],
            'unknown country' => ['country', '', 'XX'],
            'language not installed' => ['lang', '', 'xx_no_such'],
            'first day of the week out of range' => ['calendar_startwday', '', '7'],
            'sections out of range' => ['numsections', 'moodlecourse', '999'],
            'sections not a number' => ['numsections', 'moodlecourse', 'five'],
            'unknown course format' => ['format', 'moodlecourse', 'no_such_format'],
            'visibility not a switch' => ['visible', 'moodlecourse', '2'],
            'site name with markup' => ['fullname', '', '<script>alert(1)</script>'],
            'empty site name' => ['fullname', '', ''],
        ];
    }

    /**
     * A setting of the allowlist with an invalid value is refused and nothing is written.
     *
     * @dataProvider invalid_value_provider
     * @param string $name Setting name.
     * @param string $plugin Plugin, empty for core.
     * @param string $value Value.
     * @return void
     */
    public function test_invalid_value_is_refused(string $name, string $plugin, string $value): void {
        global $DB;
        $component = $plugin === '' ? 'core' : $plugin;
        $before = get_config($component, $name);
        $logs = $DB->count_records('config_log');

        $result = $this->call($name, $value, $plugin);

        $this->assertSame('error', $result['status']);
        $this->assertSame(get_string('config_value_invalid_msg', 'local_jedomi', $name), $result['message']);
        $this->assertSame($before, get_config($component, $name), 'The setting is not changed');
        $this->assertSame($logs, $DB->count_records('config_log'), 'Nothing is written to the config log');
    }

    /**
     * No component of an AI provider of Moodle is ever allowed, whatever the setting.
     *
     * @return void
     */
    public function test_ai_provider_components_are_never_allowed(): void {
        $this->assertFalse(site_config_policy::is_allowed('aiprovider_azureai', 'endpoint'));
        $this->assertFalse(site_config_policy::is_allowed('aiprovider_openai', 'action_generate_text_endpoint'));
        $this->assertFalse(site_config_policy::is_allowed('aiprovider_datacurso', 'fullname'));
        $this->assertTrue(site_config_policy::is_allowed('core', 'FullName'));
        $this->assertTrue(site_config_policy::is_allowed('moodlecourse', 'numsections'));
    }
}
