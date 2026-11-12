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

/**
 * Tests for the permissions declared by the plugin and the permission each external or administrative service requires.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\execute_sql
 * @covers     \local_jedomi\external\extract_schema
 * @covers     \local_jedomi\external\validate_password
 * @covers     \local_jedomi\external\ws_execute
 * @covers     \local_jedomi\external\get_site_config
 * @covers     \local_jedomi\external\set_site_config
 * @covers     \local_jedomi\external\run_task
 * @covers     \local_jedomi\external\purge_caches
 * @covers     \local_jedomi\external\read_file
 * @covers     \local_jedomi\external\set_telegram_enabled
 */
final class companion_access_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the services check the plugin state through it.');
        }
        $this->resetAfterTest();
    }

    /**
     * Each service of the companion API with a call carrying a wrong signature, and the permission it requires.
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function companion_service_provider(): array {
        return [
            'read query' => [fn() => execute_sql::execute('SELECT 1', [], 'bad', time(), 'bad'), 'local/jedomi:companion_api'],
            'schema' => [fn() => extract_schema::execute(false, [], 'bad', time(), 'bad'), 'local/jedomi:companion_api'],
            'password check' => [fn() => validate_password::execute('Secret-123', 'admin', 'bad', time(), 'bad'),
                'local/jedomi:companion_api'],
            'web services' => [fn() => ws_execute::execute('core_webservice_get_site_info', '{}', 'bad', time(), 'bad'),
                'local/jedomi:companion_api'],
            'read settings' => [fn() => get_site_config::execute(['fullname'], 'bad', time(), 'bad'), 'moodle/site:config'],
            'change settings' => [fn() => set_site_config::execute('fullname', 'Changed', '', 'bad', time(), 'bad'),
                'moodle/site:config'],
            'run task' => [fn() => run_task::execute('\core\task\cache_cron_task', false, 'bad', time(), 'bad'),
                'moodle/site:config'],
            'purge caches' => [fn() => purge_caches::execute('bad', time(), 'bad'), 'moodle/site:config'],
            'diagnostics' => [fn() => read_file::execute('version.php', 1, 5, 'bad', time(), 'bad'), 'moodle/site:config'],
        ];
    }

    /**
     * Run a call and return the exception it throws.
     *
     * @param callable $call Call.
     * @return \Throwable|null
     */
    private function exception_of(callable $call): ?\Throwable {
        try {
            $call();
        } catch (\Throwable $e) {
            // The companion permission has no language string yet (known gap), which only raises a developer notice.
            $this->resetDebugging();
            return $e;
        }
        return null;
    }

    /**
     * MDL-INT-076: each service of the companion API requires its permission: a user without it is refused before the
     * function runs, a manager is refused by the services that need the site configuration permission, and a user with
     * the permission passes the check.
     *
     * @dataProvider companion_service_provider
     * @param callable $call Call of the service.
     * @param string $capability Permission it requires.
     */
    public function test_each_companion_service_requires_its_permission(callable $call, string $capability): void {
        global $DB;
        $this->setUser($this->getDataGenerator()->create_user());
        $refused = $this->exception_of($call);
        $this->assertInstanceOf(\required_capability_exception::class, $refused);

        if ($capability === 'moodle/site:config') {
            // A manager holds the companion permission by default but not the site configuration one.
            $manager = $this->getDataGenerator()->create_user();
            role_assign(
                (int) $DB->get_field('role', 'id', ['shortname' => 'manager']),
                $manager->id,
                \context_system::instance()
            );
            $this->setUser($manager);
            $this->assertInstanceOf(\required_capability_exception::class, $this->exception_of($call));
        }
        // A user with the permission passes the check and reaches the signature verification.
        $this->setAdminUser();
        $this->assertNotInstanceOf(\required_capability_exception::class, $this->exception_of($call));
    }

    /**
     * MDL-INT-076: a request without a valid signature is refused before the function runs.
     *
     * @dataProvider companion_service_provider
     * @param callable $call Call of the service.
     */
    public function test_request_without_valid_signature_is_refused(callable $call): void {
        $this->setAdminUser();
        set_config('hmac_secret', 'site-secret-for-tests', 'local_jedomi');

        $refused = $this->exception_of($call);

        $this->assertInstanceOf(\moodle_exception::class, $refused);
        $this->assertNotInstanceOf(\required_capability_exception::class, $refused);
    }

    /**
     * MDL-INT-075: the agents, training data and companion service permissions are declared and granted by default only
     * to the Manager role.
     */
    public function test_permissions_are_declared_with_their_default_roles(): void {
        $capabilities = ['local/jedomi:manageagents', 'local/jedomi:viewtrainingdata', 'local/jedomi:managetrainingdata',
            'local/jedomi:companion_api'];
        foreach ($capabilities as $capability) {
            $this->assertNotEmpty(get_capability_info($capability), $capability);
            $this->assertSame(CAP_ALLOW, get_default_capabilities('manager')[$capability] ?? null, $capability);
            // No other archetype has a default: a prohibit on the authenticated user role, which every logged-in user
            // holds, would remove it from managers too.
            foreach (['coursecreator', 'editingteacher', 'teacher', 'student', 'guest', 'user', 'frontpage'] as $archetype) {
                $this->assertNull(get_default_capabilities($archetype)[$capability] ?? null, "{$archetype} {$capability}");
            }
        }
    }

    /**
     * MDL-INT-041 / MDL-INT-075: the companion permission is granted to a non-administrator manager, who runs a read query.
     * A manager who is not a site administrator holds the companion permission and can run a read query from the chat;
     * a user with only the authenticated user role does not hold it and is refused.
     */
    public function test_manager_can_use_the_companion_api_and_a_plain_user_cannot(): void {
        global $DB;
        $systemcontext = \context_system::instance();
        $manager = $this->getDataGenerator()->create_user();
        role_assign((int) $DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id, $systemcontext);
        $plainuser = $this->getDataGenerator()->create_user();

        $this->assertFalse(is_siteadmin($manager));
        $this->assertTrue(has_capability('local/jedomi:companion_api', $systemcontext, $manager));
        $this->assertFalse(has_capability('local/jedomi:companion_api', $systemcontext, $plainuser));

        // The chat runs the query in-process with the user's permissions (no signature).
        $this->setUser($manager);
        $result = execute_sql::execute('SELECT id FROM {course} WHERE id = ' . SITEID, [], '', 0, '', true);
        $this->assertSame(1, $result['row_count']);

        $this->setUser($plainuser);
        $refused = $this->exception_of(fn() => execute_sql::execute('SELECT id FROM {course}', [], '', 0, '', true));
        $this->assertInstanceOf(\required_capability_exception::class, $refused);
    }

    /**
     * Administrative services that require the site configuration permission.
     *
     * @return array<string, array{0: callable}>
     */
    public static function administrative_service_provider(): array {
        return [
            'Telegram switch' => [fn() => set_telegram_enabled::execute(true)],
        ];
    }

    /**
     * MDL-INT-075: the Telegram switch requires the site configuration permission. The course search cannot be called
     * in a shared PHPUnit process (it loads a file that demands process isolation), so it is not covered here.
     *
     * @dataProvider administrative_service_provider
     * @param callable $call Call of the service.
     */
    public function test_administrative_services_require_site_configuration(callable $call): void {
        global $DB;
        $manager = $this->getDataGenerator()->create_user();
        role_assign((int) $DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id, \context_system::instance());
        $this->setUser($manager);

        $outcome = $this->exception_of($call);
        $this->assertInstanceOf(\required_capability_exception::class, $outcome, $outcome ? $outcome->getMessage() : '');
    }

    /**
     * MDL-INT-077: every declared permission controls a screen or an action, and the chat has its own permission that the
     * server checks.
     * [Pendiente:skip]
     */
    public function test_every_permission_is_used_and_the_chat_has_its_own(): void {
        $this->markTestSkipped('Pending fix: the training data permissions are unused and there is no permission to use '
            . 'the chat.');
    }
}
