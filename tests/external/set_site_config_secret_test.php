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

/**
 * Tests that jedomi_set_site_config never writes a secret setting.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\set_site_config
 */
final class set_site_config_secret_test extends \advanced_testcase {
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
        $signature = hash_hmac('sha256', "jedomi_set_site_config:{$now}:{$hash}", self::SECRET);
        return set_site_config::execute($name, $value, $plugin, $signature, $now, $hash);
    }

    /**
     * Secret settings of local_jedomi, of core and settings shown as passwords.
     *
     * @return array
     */
    public static function secret_provider(): array {
        return [
            'AI API key' => ['custom_api_key', 'local_jedomi'],
            'Telegram bot token' => ['telegram_bot_token', 'local_jedomi'],
            'Telegram webhook secret' => ['telegram_webhook_secret', 'local_jedomi'],
            'chat token secret' => ['spt_secret', 'local_jedomi'],
            'SMTP password' => ['smtppass', ''],
            'proxy password' => ['proxypassword', ''],
            'password setting not listed by name' => ['cronremotepassword', ''],
        ];
    }

    /**
     * A secret setting is refused, and nothing is written to the settings nor to the config log.
     *
     * @dataProvider secret_provider
     * @param string $name Setting name.
     * @param string $plugin Plugin, empty for core.
     * @return void
     */
    public function test_secret_setting_is_refused(string $name, string $plugin): void {
        global $DB;
        $component = $plugin === '' ? 'core' : $plugin;
        $before = get_config($component, $name);
        $logs = $DB->count_records('config_log');

        $result = $this->call($name, 'leaked-secret-value', $plugin);

        $this->assertSame('error', $result['status']);
        $this->assertSame(get_string('config_key_secret_msg', 'local_jedomi'), $result['message']);
        $this->assertSame($before, get_config($component, $name), 'The setting is not changed');
        $this->assertSame($logs, $DB->count_records('config_log'), 'Nothing is written to the config log');
        $this->assertFalse($DB->record_exists_select('config_log', $DB->sql_like('value', ':v'), ['v' => '%leaked-secret%']));
    }

    /**
     * An ordinary setting is still written.
     *
     * @return void
     */
    public function test_ordinary_setting_is_written(): void {
        $result = $this->call('fullname', 'Renamed site', '');

        $this->assertSame('success', $result['status']);
        $this->assertSame('Renamed site', get_config('core', 'fullname'));
    }
}
