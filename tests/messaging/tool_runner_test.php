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

namespace local_jedomi\messaging;

use local_jedomi\telegram\manager;
use local_jedomi\telegram\webhook_handler;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the channel-neutral tool runner: the tools of a channel, the signed permissions and the tool execution.
 *
 * Each channel gives the runner its name (the channel of the tool definitions and of the query limits), the label
 * shown when a tool is not supported, and the closed list of the tools it can run. The tests run with the Telegram
 * runner of telegram\manager and with runners of other channels built here, so no schema change is needed to prove
 * the runner is not tied to Telegram.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\tool_runner
 * @covers     \local_jedomi\telegram\manager
 * @covers     \local_jedomi\telegram\webhook_handler
 */
final class tool_runner_test extends \advanced_testcase {
    /**
     * Names of a list of tool definitions.
     *
     * @param array $tools Tool definitions in function-calling format.
     * @return string[]
     */
    private static function names(array $tools): array {
        return array_map(static fn(array $tool): string => $tool['function']['name'], $tools);
    }

    /**
     * The Telegram runner is the runner of the telegram channel.
     */
    public function test_telegram_runner_is_the_telegram_channel(): void {
        $this->assertSame('telegram', manager::tool_runner()->channel());
        $this->assertSame('testchannel', (new tool_runner('testchannel', 'Test', ['ws_search']))->channel());
    }

    /**
     * The Telegram tools are the tools of the role offered in the telegram channel, without the tools it cannot run
     * (the document reader and any tool outside its closed list).
     */
    public function test_telegram_tools_are_the_role_tools_it_can_run(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;

        $tools = manager::tool_runner()->tool_definitions('admin', $adminid);

        $expected = array_values(array_filter(
            \local_jedomi_get_tool_definitions('admin', $adminid, 'telegram'),
            static fn(array $tool): bool => $tool['function']['name'] !== 'document_read'
        ));
        $this->assertSame($expected, $tools);
        $this->assertContains('execute_sql', self::names($tools));
        $this->assertNotContains('document_read', self::names($tools));
    }

    /**
     * The tool list is per channel: a channel offers only the tools of its own list, and its definitions are those
     * of its own channel (the query tool tells the row limit of the channel).
     */
    public function test_tool_list_is_per_channel(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;

        $narrow = new tool_runner('testchannel', 'Test', ['ws_search', 'ws_describe']);
        $this->assertSame(['ws_search', 'ws_describe'], self::names($narrow->tool_definitions('admin', $adminid)));

        $web = new tool_runner('web', 'Web', ['execute_sql']);
        $telegram = new tool_runner('telegram', 'Telegram', ['execute_sql']);
        $this->assertSame(['execute_sql'], self::names($web->tool_definitions('admin', $adminid)));
        $webdefinitions = \local_jedomi_get_tool_definitions('admin', $adminid, 'web');
        $this->assertSame(
            $webdefinitions[array_search('execute_sql', self::names($webdefinitions), true)],
            $web->tool_definitions('admin', $adminid)[0]
        );
        $this->assertNotEquals(
            $web->tool_definitions('admin', $adminid),
            $telegram->tool_definitions('admin', $adminid),
            'The query tool tells the limits of its channel'
        );
    }

    /**
     * A tool of the channel list that the role is not offered is never in the list.
     */
    public function test_tool_list_keeps_the_role_rule(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();

        $runner = new tool_runner('testchannel', 'Test', ['execute_sql', 'ws_search']);

        $this->assertSame(['ws_search'], self::names($runner->tool_definitions('student', (int) $student->id)));
    }

    /**
     * The signed permissions carry the tools of the channel and a valid signature; an unknown user gets none.
     */
    public function test_signed_permissions_carry_the_channel_tools(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;
        $runner = new tool_runner('testchannel', 'Test', ['ws_search', 'get_site_config']);

        $spt = $runner->signed_permissions($adminid, 'admin');

        $this->assertSame(['get_site_config', 'ws_search'], self::names($spt['tools']));
        $this->assertSame($adminid, $spt['userid']);
        $this->assertSame('admin', $spt['role']);
        $this->assertSame(0, $spt['course_id']);
        $signature = $spt['signature'];
        unset($spt['signature']);
        $this->assertSame(
            hash_hmac('sha256', \local_jedomi_spt_signing_string($spt), \local_jedomi_get_spt_secret()),
            $signature
        );
        $this->assertSame([], $runner->signed_permissions(999999, 'student'));
    }

    /**
     * The course context of a user without courses has empty lists.
     */
    public function test_user_context_without_courses(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $context = manager::tool_runner()->user_context((int) $user->id);

        $this->assertSame([], $context['course_ids_owned']);
        $this->assertSame([], $context['course_ids_enrolled']);
        $this->assertSame([], $context['courses_by_role']);
        $this->assertSame(\local_jedomi_get_user_role_for_user((int) $user->id), $context['role']);
    }

    /**
     * The plural or singular forms models sometimes use map to the real tool name.
     */
    public function test_canonical_tool_name(): void {
        $this->assertSame('create_agent', tool_runner::canonical_tool_name('create_agents'));
        $this->assertSame('delete_agent', tool_runner::canonical_tool_name('delete_agents'));
        $this->assertSame('list_agents', tool_runner::canonical_tool_name('list_agent'));
        $this->assertSame('execute_sql', tool_runner::canonical_tool_name('execute_sql'));
    }

    /**
     * The role is derived from the user, never from the caller; no user has no role.
     */
    public function test_authoritative_role(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame('', tool_runner::authoritative_role(0));
        $this->assertSame('', tool_runner::authoritative_role(-3));
        $this->assertSame('admin', tool_runner::authoritative_role((int) get_admin()->id));
        $this->assertSame('student', tool_runner::authoritative_role((int) $user->id));
    }

    /**
     * A tool the runner does not know names the channel of the runner; Telegram keeps its message.
     */
    public function test_unsupported_tool_names_the_channel(): void {
        $this->assertSame(
            "❌ Tool 'nope' is not supported via Telegram. Try the web interface.",
            manager::tool_runner()->execute('nope', [])
        );
        $this->assertSame(
            "❌ Tool 'nope' is not supported via Test. Try the web interface.",
            (new tool_runner('testchannel', 'Test', []))->execute('nope', [])
        );
    }

    /**
     * Existing tools give the same result through the runner and through the Telegram facade.
     */
    public function test_facade_delegates_to_the_telegram_runner(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;
        $runner = manager::tool_runner();

        $calls = [
            ['describe_table', ['tablename' => 'user']],
            ['describe_table', []],
            ['search_tables', []],
            ['execute_sql', ['sql' => 'DELETE FROM {user}']],
            ['get_site_config', ['keys' => ['sitename', 'notallowed']]],
            ['ws_describe', []],
            ['list_agent', []],
        ];
        foreach ($calls as [$name, $args]) {
            $this->assertSame(
                $runner->execute($name, $args, $adminid),
                webhook_handler::execute_tool($name, $args, $adminid),
                $name
            );
        }
        $this->assertStringContainsString('columns', $runner->execute('describe_table', ['tablename' => 'user'], $adminid));
    }

    /**
     * A spoofed role never opens a gated tool: the role is derived from the user id.
     */
    public function test_spoofed_role_is_ignored(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $runner = new tool_runner('testchannel', 'Test', []);

        $this->assertSame(
            json_encode(['error' => 'permission_denied']),
            $runner->execute('get_site_config', ['keys' => ['sitename']], (int) $user->id, 'admin')
        );
        $this->assertSame(
            get_string('telegram_permission_denied', 'local_jedomi'),
            $runner->execute('describe_table', ['tablename' => 'user'], 0, 'admin')
        );
    }

    /**
     * A database query is capped with the row limit of the channel of the runner.
     */
    public function test_query_row_limit_is_the_channel_limit(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;
        $args = ['sql' => 'SELECT id, name FROM {capabilities}'];

        $telegram = manager::tool_runner()->execute('execute_sql', $args, $adminid);
        $web = (new tool_runner('web', 'Web', []))->execute('execute_sql', $args, $adminid);

        $this->assertStringContainsString('More than 50 rows returned', $telegram);
        $this->assertStringContainsString('More than 100 rows returned', $web);
    }
}
