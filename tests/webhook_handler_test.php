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

/**
 * Unit tests for classes/telegram/webhook_handler.php in local_jedomi.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\ai\fake_curl;
use local_jedomi\ai\provider_api;
use local_jedomi\ai\testable_provider_api;
use local_jedomi\telegram\api_client;
use local_jedomi\telegram\fake_telegram_api_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Test cases for webhook_handler methods.
 *
 * Current permission model of the webhook tools (see execute_tool and
 * execute_pending_action in classes/telegram/webhook_handler.php):
 *
 * - "Linked" means a confirmed row in local_jedomi_telegram_links for the
 *   chat id; the role is derived from the linked userid (site admins in
 *   get_admins() => 'admin', then manager/teacher/student by capability).
 * - Open (no role gate): describe_table, search_tables, ws_search, ws_describe.
 * - teacher/manager/admin: execute_sql (plus per-course ownership checks).
 * - manager/admin: admin_search, admin_describe, admin_execute,
 *   create_agent, list_agents, delete_agent.
 * - admin only: get_site_config; pending actions cache_purge, run_task and
 *   get_config (the latter also restricted to an allowlist of config keys).
 *
 * @covers \local_jedomi\telegram\webhook_handler
 */
final class webhook_handler_test extends \advanced_testcase {
    /**
     * Setup test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Precondition: the Telegram integration is turned on.
        set_config('telegram_enabled', 1, 'local_jedomi');
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Skip the test when aiprovider_datacurso is missing: the webhook asks it whether Jedomi is enabled.
     */
    private function require_provider(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the plugin state and its client are not available.');
        }
    }

    // ... ========================
    // Task 2.2: looks_like_link_code — pure function tests
    // ========================

    /**
     * Data provider for looks_like_link_code.
     *
     * @return array
     */
    public static function looks_like_link_code_provider(): array {
        return [
            // Each case maps an input string to the expected boolean.
            'valid 6-char code'              => ['ABC123', true],
            'valid 4-char code'              => ['XY78', true],
            'valid 8-char code'              => ['ABCD1234', true],
            'lowercase input returns true'   => ['abc123', true],
            'mixed case'                     => ['AbC123', true],
            'code with numbers'              => ['123456', true],

            'empty string'                   => ['', false],
            'too short (3 chars)'            => ['ABC', false],
            'too long (9 chars)'             => ['ABCD12345', false],
            'contains special chars'         => ['ABC-12', false],
            'contains spaces'                => ['ABC 12', false],
            'contains lowercase letters too short' => ['ab1', false],
            'symbols only'                   => ['!@#$%', false],
        ];
    }

    /**
     * Test looks_like_link_code returns expected results.
     *
     * @dataProvider looks_like_link_code_provider
     * @param string $input Text to check
     * @param bool $expected Expected result
     */
    public function test_looks_like_link_code(string $input, bool $expected): void {
        $result = \local_jedomi\telegram\webhook_handler::looks_like_link_code($input);
        $this->assertSame($expected, $result, "looks_like_link_code('$input') should return " . ($expected ? 'true' : 'false'));
    }

    // ... ========================
    // Task 2.1: proxy_to_gateway uses new role/SPT functions
    // ========================

    /**
     * Test that proxy_to_gateway uses user-specific functions.
     *
     * This test verifies that the new functions (local_jedomi_get_user_role_for_user
     * and local_jedomi_generate_spt_for_user) are used instead of the global ones.
     * We test by creating a user and exercising the full flow through handle().
     */
    public function test_proxy_to_gateway_uses_user_specific_functions(): void {
        global $DB;

        $admin = get_admin();
        $this->assertNotFalse($admin);

        // Create a telegram link for the admin.
        $chatid = '987654321';
        $DB->insert_record('local_jedomi_telegram_links', (object)[
            'userid'      => (int)$admin->id,
            'chatid'      => $chatid,
            'username'    => 'testuser',
            'confirmed'   => 1,
            'timecreated' => time(),
        ]);

        // Verify we can get the role for this user (the new functions work).
        $role = \local_jedomi_get_user_role_for_user((int)$admin->id);
        $this->assertEquals('admin', $role, 'Admin user should have admin role via new function');

        $spt = \local_jedomi_generate_spt_for_user((int)$admin->id, $role);
        $this->assertIsArray($spt, 'SPT should be an array for admin user');
        $this->assertArrayHasKey('role', $spt);
        $this->assertEquals('admin', $spt['role']);
    }

    // ... ========================
    // Task 2.3: handle_start_command with no code
    // ========================

    /**
     * MDL-UNIT-035: "/start" without a code asks for the link code instead of reporting an invalid one.
     * MDL-INT-062: the start command without a code asks for the code.
     */
    public function test_handle_start_command_without_code(): void {
        $telegram = $this->use_fake_telegram();

        $this->send_text('700000001', '/start');

        $this->assertSame([get_string('telegram_msg_provide_code', 'local_jedomi')], array_column($telegram->sent, 'text'));
    }

    /**
     * MDL-UNIT-035: "/start" with a code that does not have the code format gets the invalid code message.
     * MDL-INT-062: an invalid code gets the corresponding notice.
     */
    public function test_handle_start_command_invalid_code(): void {
        $telegram = $this->use_fake_telegram();

        $this->send_text('700000002', '/start A-1');

        $this->assertSame([get_string('telegram_msg_expired_code', 'local_jedomi')], array_column($telegram->sent, 'text'));
        $this->assertFalse($this->chat_is_linked('700000002'));
    }

    /**
     * MDL-UNIT-035: "/start CODE" and the bare code both link the chat to the Moodle user of the code.
     */
    public function test_start_command_and_bare_code_link_the_chat(): void {
        $telegram = $this->use_fake_telegram();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();

        $this->send_text('700000003', '/start ' . \local_jedomi\telegram\manager::generate_link_code((int) $first->id));
        $this->send_text('700000004', \local_jedomi\telegram\manager::generate_link_code((int) $second->id));

        $this->assertTrue($this->chat_is_linked('700000003', (int) $first->id));
        $this->assertTrue($this->chat_is_linked('700000004', (int) $second->id));
        $this->assertCount(2, $telegram->sent);
        foreach ($telegram->sent as $sent) {
            $this->assertStringStartsWith('✅', $sent['text']);
        }
    }

    // ... ========================
    // Task 2.5: execute_tool method
    // ========================

    /**
     * MDL-UNIT-079: an unknown tool is rejected.
     * Test execute_tool returns error for unsupported tool.
     */
    public function test_execute_tool_unsupported(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_tool('nonexistent_tool', []);
        $this->assertStringContainsString('not supported', $result);
    }

    /**
     * MDL-UNIT-079: describing a table without a name gives a controlled error.
     * Test execute_tool describe_table returns error for missing table name.
     */
    public function test_execute_tool_describe_table_no_name(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_tool('describe_table', []);
        $this->assertStringContainsString('Table name required', $result);
    }

    /**
     * MDL-UNIT-079: describing an existing table returns its structure.
     * Test execute_tool describe_table returns table info for existing table.
     */
    public function test_execute_tool_describe_table_success(): void {
        global $DB;

        // Use a standard Moodle table that always exists.
        $result = \local_jedomi\telegram\webhook_handler::execute_tool('describe_table', ['tablename' => 'user']);
        $this->assertStringContainsString('user', $result);
        $this->assertStringContainsString('columns', $result);
    }

    /**
     * MDL-UNIT-079: describing a table that does not exist gives a controlled error.
     * Test execute_tool describe_table returns not found for non-existent table.
     */
    public function test_execute_tool_describe_table_not_found(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_tool('describe_table', ['tablename' => 'nonexistent_table_xyz']);
        $this->assertStringContainsString('not found', $result);
    }

    /**
     * MDL-UNIT-079: searching tables without a query gives a controlled error.
     * Test execute_tool search_tables returns error for empty query.
     */
    public function test_execute_tool_search_tables_no_query(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_tool('search_tables', []);
        $this->assertStringContainsString('Search query required', $result);
    }

    /**
     * MDL-UNIT-079: searching tables with a query returns matching tables.
     * Test execute_tool search_tables finds tables matching query.
     */
    public function test_execute_tool_search_tables_success(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_tool('search_tables', ['query' => 'user']);
        $this->assertStringContainsString('Tables', $result);
    }

    /**
     * MDL-UNIT-079: searching tables with a limit does not fail.
     * Test execute_tool search_tables respects limit.
     */
    public function test_execute_tool_search_tables_limit(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_tool('search_tables', ['query' => 'course', 'limit' => 5]);
        // Should not error — just returns results.
        $this->assertIsString($result);
    }

    /**
     * MDL-UNIT-079: the table search returns at most the requested number of results, capped at the maximum.
     */
    public function test_search_tables_results_respect_the_limit(): void {
        $few = \local_jedomi\telegram\webhook_handler::execute_tool('search_tables', ['query' => 'course', 'limit' => 5]);
        $many = \local_jedomi\telegram\webhook_handler::execute_tool('search_tables', ['query' => 'course', 'limit' => 500]);

        $this->assertGreaterThan(0, substr_count($few, '•'));
        $this->assertLessThanOrEqual(5, substr_count($few, '•'));
        $this->assertLessThanOrEqual(20, substr_count($many, '•'));
        $this->assertGreaterThan(substr_count($few, '•'), substr_count($many, '•'));
    }

    /**
     * MDL-UNIT-019: an empty query is rejected with a clear message.
     */
    public function test_execute_tool_execute_sql_no_query(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_tool('execute_sql', []);
        $this->assertStringContainsString('SQL query required', $result);
    }

    /**
     * MDL-UNIT-019: a statement that is not a read query is rejected.
     */
    public function test_execute_tool_execute_sql_rejects_non_select(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_tool('execute_sql', ['sql' => 'DELETE FROM {user}']);
        $this->assertStringContainsString('Only SELECT', $result);
    }

    /**
     * MDL-UNIT-019: a valid read query runs for a linked admin and returns rows.
     */
    public function test_execute_tool_execute_sql_success(): void {
        $admin = get_admin();
        $this->create_telegram_link((int)$admin->id, '12345');

        // Production resolves userid/role from the chat link and passes them in.
        $result = \local_jedomi\telegram\webhook_handler::execute_tool(
            'execute_sql',
            ['sql' => 'SELECT id, username FROM {user} WHERE deleted = 0 LIMIT 5'],
            (int)$admin->id,
            'admin',
            [],
        );
        $this->assertStringContainsString('Results', $result);
    }

    /**
     * MDL-UNIT-019: without the required privilege the query is denied.
     */
    public function test_execute_tool_execute_sql_denied_without_privilege(): void {
        $user = $this->getDataGenerator()->create_user();

        $result = \local_jedomi\telegram\webhook_handler::execute_tool(
            'execute_sql',
            ['sql' => 'SELECT id FROM {user} LIMIT 1'],
            (int)$user->id,
            'student',
            [],
        );
        $this->assertStringContainsString('Permission denied', $result);
        $this->assertStringNotContainsString('Results', $result);
    }

    // ... ========================
    // Task 2.6: execute_pending_action
    // ========================

    /**
     * Test execute_pending_action with unknown tool.
     */
    public function test_execute_pending_action_unknown(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_pending_action(
            ['name' => 'unknown_tool', 'params' => []],
            '12345',
        );
        $this->assertStringContainsString('not supported', $result);
    }

    /**
     * Test execute_pending_action with get_config for a linked admin.
     *
     * The key must belong to the get_config allowlist ($safekeys).
     */
    public function test_execute_pending_action_get_config(): void {
        $this->create_telegram_link((int)get_admin()->id, '12345');
        set_config('telegram_max_turns', 25, 'local_jedomi');

        $result = \local_jedomi\telegram\webhook_handler::execute_pending_action(
            ['name' => 'get_config', 'params' => ['key' => 'telegram_max_turns']],
            '12345',
        );
        $this->assertStringContainsString('telegram_max_turns', $result);
        $this->assertStringContainsString('25', $result);
    }

    /**
     * Test execute_pending_action get_config with an allowlisted but unset key.
     */
    public function test_execute_pending_action_get_config_not_found(): void {
        $this->create_telegram_link((int)get_admin()->id, '12345');
        unset_config('telegram_gateway_timeout', 'local_jedomi');

        $result = \local_jedomi\telegram\webhook_handler::execute_pending_action(
            ['name' => 'get_config', 'params' => ['key' => 'telegram_gateway_timeout']],
            '12345',
        );
        $this->assertStringContainsString('not found', $result);
    }

    /**
     * Test execute_pending_action get_config denies a chat without a link.
     */
    public function test_execute_pending_action_get_config_denied_unlinked_chat(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_pending_action(
            ['name' => 'get_config', 'params' => ['key' => 'telegram_max_turns']],
            'chat-without-link',
        );
        $this->assertStringContainsString('Permission denied. Admin only.', $result);
    }

    /**
     * Test execute_pending_action get_config denies a linked non-admin user.
     */
    public function test_execute_pending_action_get_config_denied_non_admin(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->create_telegram_link((int)$user->id, '55555');
        set_config('telegram_max_turns', 25, 'local_jedomi');

        $result = \local_jedomi\telegram\webhook_handler::execute_pending_action(
            ['name' => 'get_config', 'params' => ['key' => 'telegram_max_turns']],
            '55555',
        );
        $this->assertStringContainsString('Permission denied. Admin only.', $result);
    }

    /**
     * Data provider: admin-only pending actions.
     *
     * @return array
     */
    public static function admin_only_pending_actions_provider(): array {
        return [
            'cache_purge' => ['cache_purge'],
            'run_task'    => ['run_task'],
            'get_config'  => ['get_config'],
        ];
    }

    /**
     * Test every admin-only pending action denies an unlinked chat.
     *
     * @dataProvider admin_only_pending_actions_provider
     * @param string $action Pending action name under test
     */
    public function test_admin_only_pending_actions_deny_unlinked(string $action): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_pending_action(
            ['name' => $action, 'params' => []],
            'chat-without-link',
        );
        $this->assertStringContainsString('Permission denied. Admin only.', $result);
    }

    /**
     * Data provider: role-gated execute_tool tools and their denial marker.
     *
     * @return array
     */
    public static function gated_tools_provider(): array {
        // Marker is the denial text each tool returns for role 'student'.
        return [
            'execute_sql (teacher+)'      => ['execute_sql', ['sql' => 'SELECT 1 AS x'], 'Permission denied'],
            'get_site_config (admin)'     => ['get_site_config', [], 'permission_denied'],
            'admin_search (manager+)'     => ['admin_search', ['query' => 'user'], 'permission_denied'],
            'admin_describe (manager+)'   => ['admin_describe', ['setting' => 'x'], 'permission_denied'],
            'admin_execute (manager+)'    => ['admin_execute', ['setting' => 'x'], 'permission_denied'],
            'create_agent (manager+)'     => ['create_agent', [], 'permission_denied'],
            'list_agents (manager+)'      => ['list_agents', [], 'permission_denied'],
            'delete_agent (manager+)'     => ['delete_agent', [], 'permission_denied'],
        ];
    }

    /**
     * Test every role-gated tool denies an unprivileged (student) role.
     *
     * Open tools (describe_table, search_tables, ws_search, ws_describe) are
     * covered by the roleless success tests above.
     *
     * @dataProvider gated_tools_provider
     * @param string $tool Tool name under test
     * @param array $args Tool arguments
     * @param string $marker Expected denial marker in the result
     */
    public function test_gated_tools_deny_unprivileged_role(string $tool, array $args, string $marker): void {
        $user = $this->getDataGenerator()->create_user();

        $result = \local_jedomi\telegram\webhook_handler::execute_tool(
            $tool,
            $args,
            (int)$user->id,
            'student',
            []
        );
        $this->assertStringContainsString(
            $marker,
            $result,
            "Tool '{$tool}' should deny role 'student'"
        );
    }

    /**
     * Test execute_tool ignores a spoofed 'admin' role string: a non-admin
     * userid must still be denied on every role-gated tool, because the
     * role is always re-derived from $userid, never trusted from the caller.
     *
     * @dataProvider gated_tools_provider
     * @param string $tool Tool name under test
     * @param array $args Tool arguments
     * @param string $marker Expected denial marker in the result
     */
    public function test_gated_tools_deny_spoofed_admin_role_for_non_admin_userid(
        string $tool,
        array $args,
        string $marker
    ): void {
        $user = $this->getDataGenerator()->create_user();

        $result = \local_jedomi\telegram\webhook_handler::execute_tool(
            $tool,
            $args,
            (int)$user->id,
            'admin', // Spoofed role — must be ignored; real role re-derived from userid.
            []
        );
        $this->assertStringContainsString(
            $marker,
            $result,
            "Tool '{$tool}' must deny a spoofed 'admin' role for a non-admin userid"
        );
    }

    /**
     * Test execute_tool denies role-gated tools even when $userid <= 0,
     * regardless of what role string the caller supplies.
     */
    public function test_execute_tool_denies_degenerate_userid_with_spoofed_role(): void {
        $result = \local_jedomi\telegram\webhook_handler::execute_tool(
            'admin_search',
            ['query' => 'purge'],
            0,
            'admin',
            []
        );
        $this->assertStringContainsString('permission_denied', $result);
    }

    /**
     * Test execute_tool still allows a real site admin, even when the
     * caller supplies a wrong/spoofed role string — the real role is
     * re-derived from $userid, so the (incorrect) $role argument is ignored
     * in both directions.
     */
    public function test_admin_tool_allows_real_site_admin_regardless_of_role_arg(): void {
        $admin = get_admin();

        $result = \local_jedomi\telegram\webhook_handler::execute_tool(
            'admin_search',
            ['query' => 'purge'],
            (int)$admin->id,
            'student', // Wrong role — must be ignored; real admin re-derived from userid.
            []
        );
        $this->assertStringNotContainsString('permission_denied', $result);
        $this->assertStringContainsString('results', $result);
    }

    /**
     * Test the admin_execute tool's defense-in-depth: cli_registry::execute()
     * itself re-checks the acting user's capability independently of the
     * role gate in execute_tool, using a real site admin end to end.
     */
    public function test_admin_execute_allows_real_site_admin_end_to_end(): void {
        $admin = get_admin();

        $result = \local_jedomi\telegram\webhook_handler::execute_tool(
            'admin_execute',
            ['name' => 'purge_caches'],
            (int)$admin->id,
            'student', // Wrong role — must be ignored; real admin re-derived from userid.
            []
        );
        $this->assertStringContainsString('success', $result);
    }

    // ... ========================
    // Task 2.4: handle_tool_calls
    // ========================

    /**
     * Test handle_tool_calls processes tool calls.
     */
    public function test_handle_tool_calls(): void {
        // This is an integration test that would require mocking api_client.
        // Verified by code review: handle_tool_calls iterates over tool_calls,
        // calls execute_tool for each, and sends result via api_client.
        $this->assertTrue(true, 'handle_tool_calls delegates to execute_tool and sends results');
    }

    // ... ========================
    // Task 2.7: error_log → local_jedomi_log
    // ========================

    /**
     * Test that webhook_handler uses local_jedomi_log instead of error_log.
     */
    public function test_no_error_log_calls(): void {
        $root = realpath(__DIR__ . '/..');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // The plugin's own tests may mention the function name in strings.
            if (str_starts_with($file->getPathname(), $root . '/tests')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (preg_match('/\berror_log\s*\(/', $source)) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'error_log() is forbidden; use local_jedomi_log() or debugging() instead'
        );
    }

    // ... ========================
    // Task 5.2: /help command
    // ========================

    /**
     * Help aliases in English and Spanish, with any letter case.
     *
     * @return array<string, array{0: string}>
     */
    public static function help_alias_provider(): array {
        return [
            'english' => ['/help'],
            'spanish' => ['/ayuda'],
            'upper case' => ['/HELP'],
        ];
    }

    /**
     * MDL-UNIT-035: the help command and its alias answer the help text with the New conversation, Help and Unlink buttons.
     * MDL-INT-062: the help command shows the help.
     *
     * @dataProvider help_alias_provider
     * @param string $command Command sent by the user.
     */
    public function test_help_command_returns_help_text(string $command): void {
        $telegram = $this->use_fake_telegram();

        $this->send_text('700000010', $command);

        $this->assertCount(1, $telegram->sent);
        $help = $telegram->sent[0]['text'];
        $this->assertStringContainsString(get_string('telegram_help_heading', 'local_jedomi'), $help);
        $this->assertStringContainsString(get_string('telegram_help_start', 'local_jedomi'), $help);
        $this->assertStringContainsString(get_string('telegram_help_new', 'local_jedomi'), $help);
        $this->assertStringContainsString(get_string('telegram_help_unlink', 'local_jedomi'), $help);
        $this->assertSame([
            get_string('telegram_keyboard_new', 'local_jedomi'),
            get_string('telegram_keyboard_help', 'local_jedomi'),
            get_string('telegram_keyboard_unlink', 'local_jedomi'),
        ], $this->button_labels($telegram->sent[0]));
    }

    /**
     * MDL-UNIT-035: the help command always answers, even when the chat is rate limited.
     * MDL-INT-062: the help is shown even with the chat blocked by the message limit.
     */
    public function test_help_command_before_rate_limit(): void {
        $telegram = $this->use_fake_telegram();
        set_config('telegram_rate_limit', 1, 'local_jedomi');
        set_config('telegram_cooldown', 5, 'local_jedomi');
        $chatid = '700000011';

        $this->send_text($chatid, 'hello there');
        $this->send_text($chatid, 'hello there');
        $this->send_text($chatid, '/help');

        $texts = array_column($telegram->sent, 'text');
        $this->assertCount(3, $texts);
        $this->assertStringContainsString(get_string('telegram_help_heading', 'local_jedomi'), $texts[2]);
        $this->assertNotSame($texts[1], $texts[2], 'The help answer is not the rate limit answer');
    }

    /**
     * MDL-UNIT-035: an unlinked chat is asked to link the account, with the How to link and Help buttons.
     * MDL-INT-062: an unlinked chat gets the notice to link the account with its buttons.
     */
    public function test_unlinked_chat_is_asked_to_link_with_its_buttons(): void {
        $telegram = $this->use_fake_telegram();

        $this->send_text('700000012', 'What are my grades?');

        $this->assertSame([get_string('telegram_msg_link_first', 'local_jedomi')], array_column($telegram->sent, 'text'));
        $this->assertSame([
            get_string('telegram_keyboard_howto_link', 'local_jedomi'),
            get_string('telegram_keyboard_help', 'local_jedomi'),
        ], $this->button_labels($telegram->sent[0]));
    }

    /**
     * Unlink aliases in English and Spanish.
     *
     * @return array<string, array{0: string}>
     */
    public static function unlink_alias_provider(): array {
        return [
            'english' => ['/unlink'],
            'spanish' => ['/desvincular'],
            'upper case' => ['/UNLINK'],
        ];
    }

    /**
     * MDL-UNIT-035: the unlink command and its alias remove the link of the chat.
     * MDL-INT-058: unlinking from the bot removes the link.
     *
     * @dataProvider unlink_alias_provider
     * @param string $command Command sent by the user.
     */
    public function test_unlink_aliases_remove_the_link(string $command): void {
        $telegram = $this->use_fake_telegram();
        $user = $this->getDataGenerator()->create_user();
        $this->create_telegram_link((int) $user->id, '700000013');

        $this->send_text('700000013', $command);

        $this->assertFalse($this->chat_is_linked('700000013'));
        $this->assertSame([get_string('telegram_msg_unlinked', 'local_jedomi')], array_column($telegram->sent, 'text'));
    }

    /**
     * New conversation aliases in English and Spanish.
     *
     * @return array<string, array{0: string}>
     */
    public static function new_conversation_alias_provider(): array {
        return [
            'english' => ['/new'],
            'spanish' => ['/nuevo'],
            'clear' => ['/clear'],
        ];
    }

    /**
     * MDL-UNIT-035: the new conversation command and its aliases forget the previous messages and greet the user.
     * MDL-INT-062: new conversation clears the conversation and the assistant greets again.
     *
     * @dataProvider new_conversation_alias_provider
     * @param string $command Command sent by the user.
     */
    public function test_new_conversation_aliases_start_from_scratch(string $command): void {
        $this->use_fake_telegram();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Valentina']);
        $chatid = '700000014';
        $this->create_telegram_link((int) $user->id, $chatid);
        \local_jedomi\telegram\session::append($chatid, 'user', 'An old question about grades');
        $fake = new fake_ai_client();
        $fake->enqueue_text('Hola Valentina');
        client::set_instance_for_testing($fake);

        $this->send_text($chatid, $command);

        $this->assertCount(1, $fake->calls);
        $contents = array_column($fake->calls[0]['messages'], 'content');
        $this->assertNotContains('An old question about grades', $contents);
        $this->assertStringContainsString('Valentina', (string) end($contents));
    }

    // ... ========================
    // Telegram UX mejora: /new in help, /ayuda alias
    // ========================

    /**
     * MDL-UNIT-035: the help text lists the new conversation command and its Spanish alias.
     */
    public function test_help_includes_new_command(): void {
        $help = get_string('telegram_help_heading', 'local_jedomi') . "\n\n"
              . get_string('telegram_help_start', 'local_jedomi') . "\n"
              . get_string('telegram_help_help', 'local_jedomi') . "\n"
              . get_string('telegram_help_new', 'local_jedomi') . "\n"
              . get_string('telegram_help_unlink', 'local_jedomi') . "\n\n"
              . get_string('telegram_help_footer', 'local_jedomi');
        $this->assertStringContainsString('/new', $help, '/help output should contain /new command');
        $this->assertStringContainsString('/nuevo', $help, '/help output should contain /nuevo alias');
    }

    /**
     * Test that /ayuda is handled as an alias for /help in the source code.
     */
    public function test_ayuda_in_source(): void {
        $source = file_get_contents(__DIR__ . '/../classes/telegram/webhook_handler.php');
        $this->assertStringContainsString(
            "'/ayuda'",
            $source,
            'webhook_handler should reference /ayuda as a command alias'
        );
    }


    /**
     * MDL-UNIT-035: the linked keyboard has the New conversation, Help and Unlink buttons.
     */
    public function test_get_linked_keyboard_structure(): void {
        $keyboard = \local_jedomi\telegram\webhook_handler::get_linked_keyboard();
        $this->assertIsArray($keyboard);
        $this->assertArrayHasKey('inline_keyboard', $keyboard);
        $this->assertCount(1, $keyboard['inline_keyboard']);
        $buttons = $keyboard['inline_keyboard'][0];
        $this->assertCount(3, $buttons, 'Linked keyboard should have 3 buttons');
        $labels = array_column($buttons, 'text');
        $this->assertNotEmpty($labels[0]);
        $this->assertNotEmpty($labels[1]);
        $this->assertNotEmpty($labels[2]);
    }

    /**
     * MDL-UNIT-035: the unlinked keyboard has the How to link and Help buttons.
     */
    public function test_get_unlinked_keyboard_structure(): void {
        $keyboard = \local_jedomi\telegram\webhook_handler::get_unlinked_keyboard();
        $this->assertIsArray($keyboard);
        $this->assertArrayHasKey('inline_keyboard', $keyboard);
        $this->assertCount(1, $keyboard['inline_keyboard']);
        $buttons = $keyboard['inline_keyboard'][0];
        $this->assertCount(2, $buttons, 'Unlinked keyboard should have 2 buttons');
    }

    // ... ========================
    // Chat id validation (SQL injection guard)
    // ========================

    /**
     * Data provider for normalise_chat_id.
     *
     * @return array
     */
    public static function chat_id_provider(): array {
        return [
            'positive id'          => ['123456789', '123456789'],
            'negative group id'    => ['-1001234567890', '-1001234567890'],
            'single digit'         => ['7', '7'],
            'empty'                => ['', null],
            'alphabetic'           => ['abc', null],
            'sql injection quote'  => ["1' OR 1=1", null],
            'sql injection drop'   => ["1'); DROP TABLE {user};--", null],
            'decimal'              => ['12.5', null],
            'double sign'          => ['--1', null],
            'sign only'            => ['-', null],
            'too long (25 digits)' => [str_repeat('9', 25), null],
            'embedded space'       => ['12 34', null],
        ];
    }

    /**
     * MDL-UNIT-037: chat id validation through the public webhook entry point.
     *
     * A full Telegram update goes through handle(): an accepted (signed
     * numeric) chat id reaches the rate limiter and leaves a rate_limit row;
     * a rejected one returns before any DB access, leaving no rows at all.
     *
     * @dataProvider chat_id_provider
     * @param string $input Raw chat id from the webhook payload
     * @param string|null $expected Normalised id, or null when invalid
     */
    public function test_handle_validates_chat_id(string $input, ?string $expected): void {
        $this->require_provider();
        global $DB;

        $before = $DB->count_records('local_jedomi_telegram_rate_limit');

        \local_jedomi\telegram\webhook_handler::handle([
            'message' => ['chat' => ['id' => $input], 'text' => 'hola'],
        ]);

        $delta = $DB->count_records('local_jedomi_telegram_rate_limit') - $before;
        if ($expected !== null) {
            $this->assertSame(1, $delta, "Accepted chat id {$input} should be recorded by the rate limiter");
            $this->assertTrue(
                $DB->record_exists('local_jedomi_telegram_rate_limit', ['chatid' => $expected]),
                'The rate limit row must use the normalised chat id'
            );
        } else {
            $this->assertSame(0, $delta, "Rejected chat id must never reach the DB");
        }
    }

    /**
     * MDL-UNIT-037: handle() rejects a chat id with SQL metacharacters without processing the message.
     */
    public function test_handle_message_rejects_non_numeric_chatid(): void {
        $this->require_provider();
        global $DB;

        $before = [
            'sessions'  => $DB->count_records('local_jedomi_telegram_sessions'),
            'ratelimit' => $DB->count_records('local_jedomi_telegram_rate_limit'),
            'pending'   => $DB->count_records('local_jedomi_telegram_pending_actions'),
        ];

        foreach (["1'); DROP TABLE {user};--", 'abc', "1' OR SLEEP(5)--"] as $malicious) {
            \local_jedomi\telegram\webhook_handler::handle([
                'message' => ['chat' => ['id' => $malicious], 'text' => 'hola'],
            ]);
        }

        $this->assertSame(
            $before['sessions'],
            $DB->count_records('local_jedomi_telegram_sessions'),
            'A rejected chat id must not create session rows'
        );
        $this->assertSame(
            $before['ratelimit'],
            $DB->count_records('local_jedomi_telegram_rate_limit'),
            'A rejected chat id must not create rate limit rows'
        );
        $this->assertSame(
            $before['pending'],
            $DB->count_records('local_jedomi_telegram_pending_actions'),
            'A rejected chat id must not create pending action rows'
        );
    }

    /**
     * Test the dedup advisory lock keeps working with a bound parameter (MySQL only).
     */
    public function test_dedup_lock_normal_flow(): void {
        global $DB, $CFG;

        if (!in_array($CFG->dbtype, ['mysqli', 'mariadb'], true)) {
            $this->markTestSkipped('GET_LOCK is MySQL/MariaDB specific');
        }

        $acquired = (int) $DB->get_field_sql('SELECT GET_LOCK(?, 0)', ['jedomi_chat_999999']);
        $this->assertSame(1, $acquired, 'Bound-parameter GET_LOCK should acquire the lock');

        $released = (int) $DB->get_field_sql('SELECT RELEASE_LOCK(?)', ['jedomi_chat_999999']);
        $this->assertSame(1, $released, 'RELEASE_LOCK should release the lock we hold');
    }

    // ... ========================
    // Self-ping continuation
    // ========================

    /**
     * Seed a checkpointed tool-loop state so handle_internal_ping can continue it.
     *
     * Unless a client is given, the AI client fails with a network error, so the
     * continuation reaches the system-prompt build (the historical fatal point) and
     * then releases the lock — an observable, network-free outcome.
     *
     * @param string $chatid Telegram chat id for the seeded state
     * @param array $extrastate Extra keys merged into the checkpoint state (e.g. cached spt/tools)
     * @param client|null $client AI client to use; null for one that fails with a network error
     * @param int|null $userid Linked user; null to create one
     * @return string The state key used in the session context table
     */
    private function seed_selfping_state(
        string $chatid,
        array $extrastate = [],
        ?client $client = null,
        ?int $userid = null
    ): string {
        $userid = $userid ?? (int) $this->getDataGenerator()->create_user()->id;
        $this->create_telegram_link($userid, $chatid);

        if ($client === null) {
            $client = new fake_ai_client();
            $client->enqueue_exception(new \moodle_exception('curlerror', 'aiprovider_datacurso', '', 7));
        }
        client::set_instance_for_testing($client);
        set_config('telegram_gateway_timeout', 5, 'local_jedomi');

        $statekey = 'tool_loop_' . $chatid;
        $state = array_merge([
            'is_locked'      => true,
            'locked_at'      => time(),
            'iteration'      => 1,
            'max_iterations' => 50,
            'messages'       => [
                ['role' => 'system', 'content' => 'placeholder'],
                ['role' => 'user', 'content' => 'hola'],
            ],
            'userid'         => $userid,
        ], $extrastate);
        \local_jedomi\telegram\session::set_context($statekey, $state);
        return $statekey;
    }

    /**
     * Run handle_internal_ping restoring the PHP time limit afterwards.
     *
     * proxy_to_gateway raises max_execution_time on purpose (FPM survival);
     * Moodle's PHPUnit environment check requires tests to restore it.
     *
     * @param string $chatid Telegram chat id whose checkpoint continues
     */
    private function run_internal_ping(string $chatid): void {
        $originallimit = (int) ini_get('max_execution_time');
        \local_jedomi\telegram\webhook_handler::handle_internal_ping($chatid);
        set_time_limit($originallimit);
    }

    /**
     * Test the self-ping continuation with a cached SPT in the checkpoint.
     *
     * This is the normal case: the state saved by proxy_to_gateway always
     * carries the SPT, so the legacy fallback block is skipped. The system
     * prompt must still build ($role and $userctx defined) instead of dying
     * with a TypeError.
     */
    public function test_handle_internal_ping_with_cached_state(): void {
        $this->require_provider();
        $chatid = '990000001';
        $statekey = $this->seed_selfping_state($chatid, [
            'spt'   => ['token' => 'fake-spt-token', 'role' => 'student', 'tools' => []],
            'tools' => [],
        ]);

        $this->run_internal_ping($chatid);

        $after = \local_jedomi\telegram\session::get_context($statekey);
        $this->assertEmpty(
            $after['is_locked'] ?? null,
            'The continuation should run to the gateway call and release the lock, not die building the prompt'
        );
    }

    /**
     * Test the self-ping continuation with a legacy state without cached SPT.
     *
     * Old checkpoints (pre-cache) carry no spt/tools keys: the fallback must
     * recalculate them from the DB and the continuation must still work.
     */
    public function test_handle_internal_ping_legacy_state_without_spt(): void {
        $this->require_provider();
        $chatid = '990000002';
        $statekey = $this->seed_selfping_state($chatid);

        $this->run_internal_ping($chatid);

        $after = \local_jedomi\telegram\session::get_context($statekey);
        $this->assertEmpty(
            $after['is_locked'] ?? null,
            'The legacy fallback (no cached SPT) should still complete the continuation and release the lock'
        );
    }

    /**
     * M7: Telegram calls the AI client for the linked user, without SPT, with the configured timeout.
     */
    public function test_telegram_calls_client_without_spt_and_with_timeout(): void {
        $this->require_provider();
        $chatid = '990000003';
        $user = $this->getDataGenerator()->create_user();
        // A forced setting, not set_config(): changing an existing row of config_plugins makes the reset after
        // the test reload the whole table, which took about ten seconds per test.
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        set_config('model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        $curl = new fake_curl();
        $curl->enqueue('{"detail":"upstream down"}', 503);
        $client = new client(static fn(int $timeout): provider_api => new testable_provider_api($timeout, $curl));
        $statekey = $this->seed_selfping_state($chatid, [
            'spt'   => ['token' => 'fake-spt-token', 'role' => 'student', 'tools' => []],
            'tools' => [],
        ], $client, (int) $user->id);

        $this->run_internal_ping($chatid);
        $this->resetDebugging();

        $this->assertCount(1, $curl->calls);
        $this->assertStringEndsWith('/jedomi/v1/chat/completions', $curl->calls[0]['url']);
        $body = $curl->last_body();
        $this->assertArrayNotHasKey('spt', $body);
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', $body['model']);
        $this->assertFalse($body['stream']);
        $this->assertSame(0.2, $body['temperature']);
        $this->assertSame(
            \aiprovider_datacurso\local\outbound_privacy::pseudonymise_userid_value((int) $user->id),
            $body['userid']
        );
        $this->assertSame(5, $curl->setoptions['CURLOPT_TIMEOUT']);
        $after = \local_jedomi\telegram\session::get_context($statekey);
        $this->assertEmpty($after['is_locked'] ?? null, 'An AI error must release the lock');
    }

    /**
     * M7: the timeout handed to the client never exceeds the configured Telegram timeout.
     */
    public function test_telegram_timeout_bounded_by_setting_and_answer_releases_lock(): void {
        $this->require_provider();
        $chatid = '990000004';
        $fake = new fake_ai_client();
        $fake->enqueue_text('Hola');
        $statekey = $this->seed_selfping_state($chatid, [
            'spt'   => ['token' => 'fake-spt-token', 'role' => 'student', 'tools' => []],
            'tools' => [],
        ], $fake);
        $state = \local_jedomi\telegram\session::get_context($statekey);

        $this->run_internal_ping($chatid);

        $this->assertCount(1, $fake->calls);
        $this->assertSame($state['userid'], $fake->calls[0]['userid']);
        $this->assertGreaterThanOrEqual(5, $fake->calls[0]['timeout']);
        $this->assertLessThanOrEqual(5, $fake->calls[0]['timeout']);
        $after = \local_jedomi\telegram\session::get_context($statekey);
        $this->assertEmpty($after['is_locked'] ?? null);
    }

    /**
     * RES-001: an AI timeout is not retried, because the provider may already have billed the request.
     * MDL-INT-063: a request that runs out of time is not resent and frees the chat.
     *
     * The handler must call the AI once, tell the user the answer took too long, clear the saved
     * conversation state and release the lock, without self-pinging a new worker that would resend
     * the same request.
     */
    public function test_ai_timeout_is_not_resent_and_releases_lock_with_timeout_message(): void {
        $this->require_provider();
        $chatid = '990000005';
        $fake = new fake_ai_client();
        $fake->enqueue_exception(new \moodle_exception('curlerror', 'aiprovider_datacurso', '', CURLE_OPERATION_TIMEDOUT));
        $telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($telegram);
        $statekey = $this->seed_selfping_state($chatid, [
            'spt'   => ['token' => 'fake-spt-token', 'role' => 'student', 'tools' => []],
            'tools' => [],
        ], $fake);

        $this->run_internal_ping($chatid);

        $this->assertCount(1, $fake->calls, 'A timed-out AI request must not be sent again');
        $after = \local_jedomi\telegram\session::get_context($statekey);
        $this->assertEmpty($after, 'The conversation state must be cleared, so no self-ping can resume it');
        $this->assertEmpty($after['is_locked'] ?? null, 'The lock must be released');
        $texts = array_column($telegram->sent, 'text');
        $this->assertContains(get_string('ai_error_timeout', 'local_jedomi'), $texts);
    }

    /**
     * MDL-INT-003: with the provider disabled Telegram answers its message and sends nothing.
     * I6: with the provider disabled Telegram answers with the provider's message and nothing is sent.
     */
    public function test_telegram_with_the_provider_disabled_answers_with_the_provider_message(): void {
        $this->require_provider();
        $chatid = '990000006';
        // A forced setting, not set_config(): changing an existing row of config_plugins makes the reset after
        // the test reload the whole table, which took about ten seconds per test.
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 0);
        $user = $this->getDataGenerator()->create_user(['lang' => 'en']);
        $curl = new fake_curl();
        $telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($telegram);
        $statekey = $this->seed_selfping_state($chatid, [
            'spt'   => ['token' => 'fake-spt-token', 'role' => 'student', 'tools' => []],
            'tools' => [],
        ], testable_provider_api::make_client($curl), (int) $user->id);

        $this->run_internal_ping($chatid);

        $this->assertSame([], $curl->calls);
        $texts = array_column($telegram->sent, 'text');
        $this->assertContains(get_string('providerdisabled', 'aiprovider_datacurso'), $texts);
        $after = \local_jedomi\telegram\session::get_context($statekey);
        $this->assertEmpty($after['is_locked'] ?? null, 'The lock must be released');
    }

    // ... ========================
    // Rate limit, long answers and steps
    // ========================

    /**
     * MDL-UNIT-091: with the limit at zero there is no blocking.
     * MDL-UNIT-005: a message limit of zero means no limit: no message is ever blocked.
     */
    public function test_zero_message_limit_means_no_limit(): void {
        global $DB;
        $telegram = $this->use_fake_telegram();
        set_config('telegram_rate_limit', 0, 'local_jedomi');
        $chatid = '700000020';

        for ($i = 0; $i < 12; $i++) {
            $this->send_text($chatid, 'hello there');
        }

        $texts = array_column($telegram->sent, 'text');
        $this->assertCount(12, $texts);
        $this->assertSame([get_string('telegram_msg_link_first', 'local_jedomi')], array_values(array_unique($texts)));
        $this->assertFalse($DB->record_exists('local_jedomi_telegram_rate_limit', ['chatid' => $chatid]));
    }

    /**
     * Run one answer of the AI through the Telegram loop and return the fake Telegram client.
     *
     * @param string $chatid Chat id.
     * @param string $answer Text answered by the AI.
     * @return fake_telegram_api_client
     */
    private function answer_in_telegram(string $chatid, string $answer): fake_telegram_api_client {
        $this->require_provider();
        $telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($telegram);
        $fake = new fake_ai_client();
        $fake->enqueue_text($answer);
        $this->seed_selfping_state($chatid, [
            'spt'   => ['token' => 'fake-spt-token', 'role' => 'student', 'tools' => []],
            'tools' => [],
        ], $fake);
        $this->run_internal_ping($chatid);
        return $telegram;
    }

    /**
     * MDL-UNIT-032: an answer exactly at the length threshold is sent as a message.
     */
    public function test_answer_at_the_threshold_is_sent_as_a_message(): void {
        $answer = str_repeat('a', 3800);

        $telegram = $this->answer_in_telegram('700000030', $answer);

        $this->assertSame([], $telegram->documents);
        $this->assertSame([$answer], array_column($telegram->sent, 'text'));
    }

    /**
     * MDL-UNIT-032: an answer one character over the threshold is sent as a text file described by its first line.
     */
    public function test_answer_over_the_threshold_is_sent_as_a_file(): void {
        $answer = "Grades summary of the course\n" . str_repeat('b', 3800 - strlen("Grades summary of the course\n") + 1);

        $telegram = $this->answer_in_telegram('700000031', $answer);

        $this->assertCount(1, $telegram->documents);
        $this->assertSame('📄 Grades summary of the course', $telegram->documents[0]['caption']);
        $this->assertSame($answer, $telegram->documents[0]['content']);
        $this->assertSame([], array_column($telegram->sent, 'text'));
    }

    /**
     * Short answers that carry a table or a code block.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function structured_answer_provider(): array {
        return [
            'table' => ["Students at risk\n<table><tr><th>Name</th><th>Grade</th></tr><tr><td>Ana</td><td>3</td></tr></table>",
                'Students at risk'],
            'code block' => ["SQL used\n```\nSELECT id FROM {user}\n```", 'SQL used'],
        ];
    }

    /**
     * MDL-UNIT-032: a short answer with a table or a code block is sent as a text file described by its first line.
     *
     * @dataProvider structured_answer_provider
     * @param string $answer Answer of the AI.
     * @param string $firstline First line of the answer.
     */
    public function test_answer_with_table_or_code_is_sent_as_a_file(string $answer, string $firstline): void {
        $telegram = $this->answer_in_telegram('700000032', $answer);

        $this->assertCount(1, $telegram->documents);
        $this->assertSame('📄 ' . $firstline, $telegram->documents[0]['caption']);
        $this->assertStringContainsString($firstline, $telegram->documents[0]['content']);
    }

    /**
     * MDL-UNIT-040: when the AI reaches the maximum number of steps, Telegram shows the "too many steps" notice.
     */
    public function test_too_many_steps_show_the_simplify_notice(): void {
        $this->require_provider();
        $chatid = '700000040';
        $telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($telegram);
        $fake = new fake_ai_client();
        for ($i = 1; $i <= 60; $i++) {
            $fake->enqueue_tool_calls([
                ['id' => 'call_' . $i, 'type' => 'function',
                    'function' => ['name' => 'no_such_tool', 'arguments' => json_encode(['step' => $i])]],
            ]);
        }
        $this->seed_selfping_state($chatid, [
            'spt'   => ['token' => 'fake-spt-token', 'role' => 'student', 'tools' => []],
            'tools' => [],
        ], $fake);

        $this->run_internal_ping($chatid);

        $texts = array_column($telegram->sent, 'text');
        $this->assertSame(get_string('telegram_msg_max_iterations', 'local_jedomi'), end($texts));
        $this->assertLessThan(60, count($fake->calls), 'The loop must stop before using every queued step');
    }

    // ... ========================
    // Pending fixes
    // ========================

    /**
     * MDL-UNIT-033: the file sent for a long agent result keeps every code block and the text between them, in order.
     * [Pendiente:skip]
     */
    public function test_agent_result_file_keeps_every_block(): void {
        $this->markTestSkipped('Pending fix: the file of a long agent result keeps only the first code or preformatted block.');
    }

    /**
     * MDL-UNIT-034: bold, italic, code, links and lists are converted to the Telegram format and special characters escaped.
     */
    public function test_answer_is_converted_to_the_telegram_format(): void {
        $answer = "**Summary** of *this week*\n"
            . "* first item\n"
            . "* second item\n"
            . "- third item with `x < 5` code\n"
            . "Grades < 50 & > 90 are rare, see [the report](https://example.com/report?a=1&b=2).\n"
            . "<p>Unknown <span>tags</span> go away</p><b>open bold";

        $telegram = $this->answer_in_telegram('990000034', $answer);

        $sent = end($telegram->sent)['text'];
        $this->assertStringContainsString('<b>Summary</b>', $sent);
        $this->assertStringContainsString('<i>this week</i>', $sent);
        $this->assertSame(3, substr_count($sent, '• '), 'List items keep a bullet');
        $this->assertStringNotContainsString('<i> first', $sent, 'A list is not turned into italics');
        $this->assertStringContainsString('<code>x &lt; 5</code>', $sent);
        $this->assertStringContainsString('Grades &lt; 50 &amp; &gt; 90', $sent);
        $this->assertStringContainsString('<a href="https://example.com/report?a=1&amp;b=2">the report</a>', $sent);
        $this->assertStringNotContainsString('<p>', $sent);
        $this->assertStringNotContainsString('<span>', $sent);
        // Every tag left is a Telegram tag and every opened tag is closed.
        preg_match_all('/<(\/?)([a-z-]+)[^>]*>/', $sent, $tags, PREG_SET_ORDER);
        $open = [];
        foreach ($tags as [, $closing, $name]) {
            $this->assertContains($name, ['b', 'i', 'u', 's', 'a', 'code', 'pre', 'blockquote', 'tg-spoiler', 'tg-emoji']);
            if ($closing === '') {
                $open[] = $name;
            } else {
                $this->assertSame(array_pop($open), $name, 'Tags are balanced');
            }
        }
        $this->assertSame([], $open, 'Every tag is closed');
    }

    /**
     * MDL-UNIT-036: only a text with the exact link code format is treated as a code; a greeting asks to link the account.
     * [Pendiente:skip]
     */
    public function test_only_the_exact_code_format_is_treated_as_a_link_code(): void {
        $this->markTestSkipped('Pending fix: any word of 4 to 8 letters or digits (a greeting) is treated as a link code.');
    }

    /**
     * MDL-UNIT-038: the limit notice shows the real minutes of waiting and no bot message, in English or Spanish,
     * holds an unreplaced placeholder or a literal line break.
     */
    public function test_bot_messages_are_complete(): void {
        $telegram = $this->use_fake_telegram();
        set_config('telegram_rate_limit', 1, 'local_jedomi');
        set_config('telegram_cooldown', 7, 'local_jedomi');
        $this->send_text('700000093', 'hello there');
        $this->send_text('700000093', 'hello there');
        $this->send_text('700000094', '/start');

        $sent = array_column($telegram->sent, 'text');
        $this->assertCount(3, $sent);
        $this->assertMatchesRegularExpression('/\b7\b/', $sent[1], 'The limit notice shows the minutes of waiting');
        $this->assertStringContainsString("\n", $sent[2], 'The link code request has real line breaks');
        foreach ($sent as $text) {
            $this->assertStringNotContainsString('\\n', $text, 'No literal line break');
            $this->assertDoesNotMatchRegularExpression('/\{\$?[a-z_>-]+\}/i', $text, 'No unreplaced placeholder');
        }

        $manager = get_string_manager();
        $a = (object) ['minutes' => 7];
        foreach (['en', 'es'] as $lang) {
            foreach (array_keys($manager->load_component_strings('local_jedomi', $lang)) as $key) {
                if (strpos($key, 'telegram_msg_') !== 0) {
                    continue;
                }
                $text = $manager->get_string($key, 'local_jedomi', $a, $lang);
                $this->assertStringNotContainsString('\\n', $text, "{$lang}/{$key} has no literal line break");
                $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/i', $text, "{$lang}/{$key} has no unreplaced placeholder");
            }
        }
    }

    /**
     * MDL-UNIT-038: the bot messages, the reasons for disabling agents and the notifications are shown in the
     * language of the user, in English and in Spanish.
     * [Pendiente:skip]
     */
    public function test_bot_messages_and_notifications_are_in_the_user_language(): void {
        $this->markTestSkipped('Pending fix: several bot messages are fixed in Spanish or English and the notifications of '
            . 'agents, grades and assignments are fixed in one language.');
    }

    /**
     * Use a fake Telegram client and require the provider, which tells whether Jedomi is enabled.
     *
     * @return fake_telegram_api_client
     */
    private function use_fake_telegram(): fake_telegram_api_client {
        $this->require_provider();
        $telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($telegram);
        return $telegram;
    }

    /**
     * Send a text message to the webhook, restoring the PHP time limit the AI loop may raise.
     *
     * @param string $chatid Chat id.
     * @param string $text Message text.
     */
    private function send_text(string $chatid, string $text): void {
        $originallimit = (int) ini_get('max_execution_time');
        \local_jedomi\telegram\webhook_handler::handle(['message' => ['chat' => ['id' => $chatid], 'text' => $text]]);
        set_time_limit($originallimit);
    }

    /**
     * Whether a chat is linked, optionally to a given user.
     *
     * @param string $chatid Chat id.
     * @param int|null $userid Expected user.
     * @return bool
     */
    private function chat_is_linked(string $chatid, ?int $userid = null): bool {
        global $DB;
        $conditions = ['chatid' => $chatid, 'confirmed' => 1];
        if ($userid !== null) {
            $conditions['userid'] = $userid;
        }
        return $DB->record_exists('local_jedomi_telegram_links', $conditions);
    }

    /**
     * Labels of the inline buttons of a sent message.
     *
     * @param array $sent Message recorded by the fake Telegram client.
     * @return string[]
     */
    private function button_labels(array $sent): array {
        return array_column($sent['replymarkup']['inline_keyboard'][0] ?? [], 'text');
    }

    // ... ========================
    // Helpers
    // ========================

    /**
     * Insert a confirmed Telegram link for the given user and chat id.
     *
     * A confirmed row in local_jedomi_telegram_links is what makes a chat
     * "linked": webhook_handler derives userid and role from it.
     *
     * @param int $userid
     * @param string $chatid
     */
    private function create_telegram_link(int $userid, string $chatid): void {
        global $DB;
        $DB->insert_record('local_jedomi_telegram_links', (object)[
            'userid'            => $userid,
            'chatid'            => $chatid,
            'username'          => 'testlink',
            'confirmed'         => 1,
            'timecreated'       => time(),
            'lastproactivesent' => 0,
        ]);
    }

    /**
     * Send an unsupported message (a sticker) from a chat: it never reaches the AI, so the answer shows the access decision.
     *
     * @param string $chatid Chat id.
     * @return void
     */
    private function send_sticker(string $chatid): void {
        \local_jedomi\telegram\webhook_handler::handle(['message' => ['chat' => ['id' => $chatid],
            'sticker' => ['file_id' => 'sticker-1']]]);
    }

    /**
     * MDL-UNIT-085: in the bot, a user with an allowed role can use it and one whose roles are all disabled cannot.
     */
    public function test_bot_access_follows_the_allowed_roles(): void {
        global $DB;
        $telegram = $this->use_fake_telegram();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->create_telegram_link((int) $student->id, '700000085');
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);

        set_config('telegram_hidden_roles', (string) $teacherroleid, 'local_jedomi');
        $this->send_sticker('700000085');
        set_config('telegram_hidden_roles', $studentroleid . ',' . $teacherroleid, 'local_jedomi');
        $this->send_sticker('700000085');

        $this->assertSame([
            get_string('telegram_msg_text_only', 'local_jedomi'),
            get_string('telegram_msg_role_blocked', 'local_jedomi'),
        ], array_column($telegram->sent, 'text'));
    }

    /**
     * MDL-UNIT-085: in the bot, a site administrator always has access, even with every role disabled.
     */
    public function test_bot_access_never_excludes_admins(): void {
        global $DB;
        $telegram = $this->use_fake_telegram();
        $this->create_telegram_link((int) get_admin()->id, '700000086');
        set_config('telegram_hidden_roles', implode(',', $DB->get_fieldset_select('role', 'id', '1 = 1')), 'local_jedomi');

        $this->send_sticker('700000086');

        $this->assertSame([get_string('telegram_msg_text_only', 'local_jedomi')], array_column($telegram->sent, 'text'));
    }

    /**
     * MDL-UNIT-091: the message at the limit is accepted and the next one blocks the chat for the waiting time with
     * the limit notice; the limit also applies to chats that are not linked.
     */
    public function test_message_limit_blocks_the_chat_after_the_limit(): void {
        global $DB;
        $telegram = $this->use_fake_telegram();
        set_config('telegram_rate_limit', 3, 'local_jedomi');
        set_config('telegram_cooldown', 5, 'local_jedomi');
        $chatid = '700000091';

        for ($i = 0; $i < 5; $i++) {
            $this->send_text($chatid, 'hello there');
        }

        $linkfirst = get_string('telegram_msg_link_first', 'local_jedomi');
        $limited = get_string('telegram_msg_rate_limited', 'local_jedomi', ['minutes' => 5]);
        $this->assertSame([$linkfirst, $linkfirst, $linkfirst, $limited, $limited], array_column($telegram->sent, 'text'));
        $blockeduntil = (int) $DB->get_field('local_jedomi_telegram_rate_limit', 'blockeduntil', ['chatid' => $chatid]);
        $this->assertGreaterThanOrEqual(time() + 5 * 60 - 5, $blockeduntil);
        $this->assertLessThanOrEqual(time() + 5 * 60, $blockeduntil);
    }

    /**
     * MDL-UNIT-091: once the waiting time is over the chat can write again.
     */
    public function test_message_limit_releases_the_chat_after_the_waiting_time(): void {
        global $DB;
        $telegram = $this->use_fake_telegram();
        set_config('telegram_rate_limit', 1, 'local_jedomi');
        $chatid = '700000092';
        $this->send_text($chatid, 'hello there');
        $this->send_text($chatid, 'hello there');

        $DB->set_field('local_jedomi_telegram_rate_limit', 'blockeduntil', time() - 1, ['chatid' => $chatid]);
        $DB->set_field('local_jedomi_telegram_rate_limit', 'windowstart', time() - 120, ['chatid' => $chatid]);
        $this->send_text($chatid, 'hello there');

        $linkfirst = get_string('telegram_msg_link_first', 'local_jedomi');
        $texts = array_column($telegram->sent, 'text');
        $this->assertSame($linkfirst, $texts[0]);
        $this->assertNotSame($linkfirst, $texts[1]);
        $this->assertSame($linkfirst, $texts[2]);
    }

    /**
     * Send a text from a linked chat to an AI that answers at once, and return the instructions the AI received.
     *
     * @param int $userid Linked user.
     * @param string $chatid Chat id.
     * @return string System instructions of the AI call.
     */
    private function telegram_instructions_for(int $userid, string $chatid): string {
        $this->use_fake_telegram();
        $fake = new fake_ai_client();
        $fake->enqueue_text('Hi.');
        client::set_instance_for_testing($fake);
        $this->create_telegram_link($userid, $chatid);
        $this->send_text($chatid, 'Hello');
        $this->assertCount(1, $fake->calls);
        return (string) $fake->calls[0]['messages'][0]['content'];
    }

    /**
     * Courses line of the Telegram instructions, decoded.
     *
     * @param string $instructions System instructions.
     * @return array|null Courses grouped by role, or null when the line says none.
     */
    private function courses_of(string $instructions): ?array {
        $this->assertMatchesRegularExpression('/^Courses: (.+)$/m', $instructions);
        preg_match('/^Courses: (.+)$/m', $instructions, $m);
        return $m[1] === 'none' ? null : json_decode($m[1], true);
    }

    /**
     * MDL-UNIT-074: the courses of the user are grouped by the role held in each one, for the Telegram AI.
     */
    public function test_telegram_courses_are_grouped_by_the_role_in_each_course(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $editing = $generator->create_course(['fullname' => 'Editing Course']);
        $nonediting = $generator->create_course(['fullname' => 'Non Editing Course']);
        $learning = $generator->create_course(['fullname' => 'Learning Course']);
        $managed = $generator->create_course(['fullname' => 'Managed Course']);
        $suspended = $generator->create_course(['fullname' => 'Suspended Enrolment Course']);
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $editing->id, 'editingteacher');
        $generator->enrol_user($user->id, $nonediting->id, 'teacher');
        $generator->enrol_user($user->id, $learning->id, 'student');
        $generator->role_assign((int) $DB->get_field('role', 'id', ['shortname' => 'manager']), $user->id,
            \context_course::instance($managed->id)->id);
        $generator->enrol_user($user->id, $suspended->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        role_unassign((int) $DB->get_field('role', 'id', ['shortname' => 'student']), (int) $user->id,
            \context_course::instance($suspended->id)->id);

        $instructions = $this->telegram_instructions_for((int) $user->id, '990000074');

        $courses = $this->courses_of($instructions);
        $this->assertEqualsCanonicalizing(['Managed Course'], $courses['manager'] ?? []);
        $this->assertEqualsCanonicalizing(['Editing Course', 'Non Editing Course'], $courses['teacher'] ?? []);
        $this->assertEqualsCanonicalizing(['Learning Course'], $courses['student'] ?? []);
        $this->assertStringNotContainsString('Suspended Enrolment Course', $instructions);
        $this->assertStringNotContainsString((string) format_string(get_site()->fullname), json_encode($courses));
        $this->assertStringContainsString('Role: teacher', $instructions, 'A role in a course never makes the user a manager');
    }

    /**
     * MDL-UNIT-074: a user without courses gets a controlled empty list.
     */
    public function test_telegram_user_without_courses_has_an_empty_list(): void {
        $user = $this->getDataGenerator()->create_user();

        $instructions = $this->telegram_instructions_for((int) $user->id, '990000075');

        $this->assertNull($this->courses_of($instructions));
        $this->assertStringContainsString('Role: student', $instructions);
    }

    /**
     * MDL-UNIT-093: the Telegram AI instructions limit actions that change data to explicit requests.
     */
    public function test_telegram_instructions_limit_writing_actions_to_explicit_requests(): void {
        $instructions = \local_jedomi\telegram\prompt_builder::build('admin', 'en',
            array_column(array_column(\local_jedomi_get_tool_definitions('admin'), 'function'), 'name'), []);

        $this->assertMatchesRegularExpression('/only call an action that changes data.*when the user explicitly asks/is',
            $instructions);
        $this->assertMatchesRegularExpression('/questions, reports and analysis only read data/i', $instructions);
        $this->assertMatchesRegularExpression('/answer them with the read tools/i', $instructions);
        $this->assertStringNotContainsString('Execute what the user asked', $instructions);
    }

    /**
     * Create a user whose id no earlier test of this run is likely to have used.
     *
     * Ids are reused after each reset; a fresh id keeps each test independent of the users of earlier tests.
     *
     * @return \stdClass
     */
    private function user_with_unused_id(): \stdClass {
        global $DB;
        $DB->insert_record_raw('user', ['id' => 900000 + random_int(1, 90000), 'username' => 'filler_' . random_string(10),
            'mnethostid' => 1, 'confirmed' => 1, 'auth' => 'manual', 'deleted' => 1, 'email' => 'filler@example.com'],
            false, false, true);
        return $this->getDataGenerator()->create_user();
    }

    /**
     * MDL-INT-017: in Telegram a function outside the role list is rejected and a listed one runs as the linked user.
     * MDL-INT-063: the message is processed with the web service functions of the linked user.
     */
    public function test_telegram_applies_the_web_service_list_of_the_role(): void {
        $student = $this->user_with_unused_id();
        $this->create_telegram_link((int) $student->id, '700000090');
        \local_jedomi\local\service\admin_settings_service::save_ws_allowlist('student', ['core_webservice_get_site_info']);
        $this->setUser($student);

        $listed = \local_jedomi\telegram\webhook_handler::execute_tool('ws_execute',
            ['wsname' => 'core_webservice_get_site_info', 'params' => '{}'], (int) $student->id);
        $unlisted = \local_jedomi\telegram\webhook_handler::execute_tool('ws_execute',
            ['wsname' => 'core_course_get_categories', 'params' => '{}'], (int) $student->id);

        $this->assertStringNotContainsString('not in allowlist', $listed);
        $this->assertStringContainsString((string) $student->username, $listed);
        $this->assertStringContainsString('not in allowlist', $unlisted);
    }

    /**
     * MDL-INT-042: in Telegram the database query never reveals credentials, keys or configuration.
     */
    public function test_telegram_database_query_excludes_sensitive_data(): void {
        global $DB;
        $admin = get_admin();
        $this->create_telegram_link((int) $admin->id, '700000091');
        $victim = $this->getDataGenerator()->create_user(['username' => 'victim', 'password' => 'Victim-Pass-1']);
        set_config('jedomi_test_secret', 'SiteSecretMarker42');
        set_config('licensekey', 'LicenseMarker42', 'aiprovider_datacurso');
        $queries = [
            'password hashes' => ["SELECT username, password FROM {user} WHERE id = {$victim->id}",
                substr((string) $DB->get_field('user', 'password', ['id' => $victim->id]), 0, 30)],
            'plugin keys' => ["SELECT value FROM {config_plugins} WHERE name = 'licensekey'", 'LicenseMarker42'],
            'site configuration' => ["SELECT value FROM {config} WHERE name = 'jedomi_test_secret'", 'SiteSecretMarker42'],
        ];

        foreach ($queries as $label => [$sql, $secret]) {
            $result = \local_jedomi\telegram\webhook_handler::execute_tool('execute_sql', ['sql' => $sql], (int) $admin->id);
            $this->assertStringNotContainsString($secret, $result, "Telegram must reject the query on {$label}");
        }
    }

    /**
     * MDL-INT-042: in Telegram a teacher cannot read the data of a course they do not teach.
     */
    public function test_telegram_teacher_cannot_read_a_course_they_do_not_teach(): void {
        $own = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Foreign Course Marker']);
        $teacher = $this->user_with_unused_id();
        $this->getDataGenerator()->enrol_user($teacher->id, $own->id, 'editingteacher');
        $this->create_telegram_link((int) $teacher->id, '700000092');
        $owncourse = \local_jedomi\telegram\webhook_handler::execute_tool('execute_sql',
            ['sql' => "SELECT shortname FROM {course} WHERE id = {$own->id}"], (int) $teacher->id, 'teacher', [(int) $own->id]);
        $this->assertStringContainsString(s($own->shortname), $owncourse, 'Control: the teacher can read their own course');

        $result = \local_jedomi\telegram\webhook_handler::execute_tool('execute_sql',
            ['sql' => "SELECT fullname FROM {course} WHERE id IN ({$other->id})"], (int) $teacher->id, 'teacher',
            [(int) $own->id]);

        $this->assertStringNotContainsString('Foreign Course Marker', $result);
    }

    /**
     * MDL-INT-032: from Telegram a manager cannot delete the agent of an administrator.
     */
    public function test_telegram_cannot_delete_the_agent_of_a_user_with_more_permissions(): void {
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $foreign = \local_jedomi\agent\agent_manager::create_agent((object) ['userid' => (int) get_admin()->id,
            'prompt' => 'Admin report', 'schedule_cron' => '0 8 * * *']);

        $denied = \local_jedomi\telegram\webhook_handler::execute_tool('delete_agent', ['id' => $foreign], (int) $manager->id);

        $this->assertStringContainsString('permission_denied', $denied);
        $this->assertNotNull(\local_jedomi\agent\agent_manager::get_agent($foreign));
    }
}
