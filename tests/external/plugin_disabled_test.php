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

use aiprovider_datacurso\local\plugin_settings;

/**
 * Tests for the web services of Jedomi when the plugin is disabled in the Datacurso AI provider.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\save_feedback
 * @covers     \local_jedomi\external\resolve_action
 * @covers     \local_jedomi\external\execute_sql
 * @covers     \local_jedomi\external\run_task
 * @covers     \local_jedomi\external\purge_caches
 * @covers     \local_jedomi\external\extract_schema
 * @covers     \local_jedomi\external\validate_password
 * @covers     \local_jedomi\external\get_site_config
 * @covers     \local_jedomi\external\read_file
 * @covers     \local_jedomi\external\set_site_config
 * @covers     \local_jedomi\external\ws_execute
 */
final class plugin_disabled_test extends \advanced_testcase {
    /**
     * Companion and agent-tool web services, each with a call that has a wrong HMAC signature.
     *
     * @return array
     */
    public static function companion_call_provider(): array {
        return [
            'execute_sql' => [fn() => execute_sql::execute('SELECT 1', [], 'bad', time(), 'bad')],
            'run_task' => [fn() => run_task::execute('\core\task\cache_cron_task', false, 'bad', time(), 'bad')],
            'purge_caches' => [fn() => purge_caches::execute('bad', time(), 'bad')],
            'extract_schema' => [fn() => extract_schema::execute(false, [], 'bad', time(), 'bad')],
            'validate_password' => [fn() => validate_password::execute('Secret-123', 'admin', 'bad', time(), 'bad')],
            'get_site_config' => [fn() => get_site_config::execute(['fullname'], 'bad', time(), 'bad')],
            'read_file' => [fn() => read_file::execute('version.php', 1, 5, 'bad', time(), 'bad')],
            'set_site_config' => [fn() => set_site_config::execute('fullname', 'Changed', '', 'bad', time(), 'bad')],
            'ws_execute' => [fn() => ws_execute::execute('core_webservice_get_site_info', '{}', 'bad', time(), 'bad')],
        ];
    }

    /**
     * Run a call and return the error code of the moodle_exception it throws.
     *
     * @param callable $call The web service call.
     * @return string
     */
    private function error_code_of(callable $call): string {
        try {
            $call();
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
        $this->fail('Expected a moodle_exception.');
    }

    /**
     * Disabled, a companion web service stops with the provider message before anything else.
     *
     * @dataProvider companion_call_provider
     * @param callable $call The web service call.
     */
    public function test_companion_service_is_refused_when_disabled(callable $call): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        plugin_settings::set_enabled('local_jedomi', false);

        $this->assertSame('plugindisabled_admin', $this->error_code_of($call));
    }

    /**
     * Enabled, a companion web service passes the guard and reaches its own checks.
     *
     * @dataProvider companion_call_provider
     * @param callable $call The web service call.
     */
    public function test_companion_service_passes_the_guard_when_enabled(callable $call): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        plugin_settings::set_enabled('local_jedomi', true);

        $this->assertNotContains($this->error_code_of($call), ['plugindisabled', 'plugindisabled_admin']);
    }

    /**
     * Disabled, no feedback is stored and the user gets the provider message.
     */
    public function test_save_feedback_is_refused_when_disabled(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        plugin_settings::set_enabled('local_jedomi', false);

        $code = $this->error_code_of(fn() => save_feedback::execute('good', 'sess_1', 'x'));

        $this->assertSame('plugindisabled', $code);
        $this->assertSame(0, $DB->count_records('local_jedomi_feedback'));
    }

    /**
     * Disabled, a held action is neither run nor removed.
     */
    public function test_resolve_action_is_refused_when_disabled(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $pending = \local_jedomi\local\service\pending_action_service::hold((int) $user->id, 'delete_agent', ['id' => 1]);
        plugin_settings::set_enabled('local_jedomi', false);

        $code = $this->error_code_of(fn() => resolve_action::execute($pending['id'], false));

        $this->assertSame('plugindisabled', $code);
        $this->assertTrue($DB->record_exists(
            \local_jedomi\local\service\pending_action_service::TABLE,
            ['id' => $pending['id']]
        ));
    }

    /**
     * Enabled, cancelling a held action still works.
     */
    public function test_resolve_action_works_when_enabled(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $pending = \local_jedomi\local\service\pending_action_service::hold((int) $user->id, 'delete_agent', ['id' => 1]);
        plugin_settings::set_enabled('local_jedomi', true);

        $result = resolve_action::execute($pending['id'], false);

        $this->assertSame('cancelled', $result['status']);
    }
}
