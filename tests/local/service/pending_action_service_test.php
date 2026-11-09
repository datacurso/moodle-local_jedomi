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

namespace local_jedomi\local\service;

/**
 * Tests for the actions the web chat holds until the user confirms them.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\pending_action_service
 */
final class pending_action_service_test extends \advanced_testcase {
    /**
     * Tools and their expected severity; null means the tool runs without confirmation.
     *
     * @return array
     */
    public static function tool_severity_provider(): array {
        return [
            'delete_agent' => ['delete_agent', ['id' => 3], 'critical'],
            'delete_agents alias' => ['delete_agents', ['id' => 3], 'critical'],
            'admin_execute' => ['admin_execute', ['name' => 'run_scheduled_task'], 'high'],
            'create_agent' => ['create_agent', ['prompt' => 'p', 'schedule_cron' => '0 8 * * *'], 'medium'],
            'create_agents alias' => ['create_agents', ['prompt' => 'p', 'schedule_cron' => '0 8 * * *'], 'medium'],
            'send_messages' => ['send_messages', ['user_ids' => [2], 'message' => 'Hi'], 'medium'],
            'execute_sql' => ['execute_sql', ['sql' => 'SELECT 1'], null],
            'search_tables' => ['search_tables', ['query' => 'user'], null],
            'describe_table' => ['describe_table', ['tablename' => 'user'], null],
            'ws_search' => ['ws_search', ['query' => 'users'], null],
            'ws_describe' => ['ws_describe', ['wsname' => 'core_user_delete_users'], null],
            'admin_search' => ['admin_search', ['query' => 'backup'], null],
            'admin_describe' => ['admin_describe', ['name' => 'backup_course'], null],
            'list_agents' => ['list_agents', [], null],
            'get_site_config' => ['get_site_config', ['keys' => ['theme']], null],
            'document_read' => ['document_read', ['cmid' => 5], null],
            'unknown tool' => ['no_such_tool', [], null],
        ];
    }

    /**
     * MDL-UNIT-022: severity of each action that changes data; reading tools get none.
     * MDL-UNIT-021: search, describe and query tools run without confirmation.
     *
     * @dataProvider tool_severity_provider
     * @param string $tool Tool name.
     * @param array $args Tool arguments.
     * @param string|null $expected Expected severity.
     */
    public function test_severity_of_each_tool(string $tool, array $args, ?string $expected): void {
        $this->assertSame($expected, pending_action_service::severity($tool, $args));
    }

    /**
     * Web service functions and their expected severity.
     *
     * @return array
     */
    public static function ws_severity_provider(): array {
        return [
            'write that deletes' => ['core_user_delete_users', 'critical'],
            'write that unenrols' => ['enrol_manual_unenrol_users', 'critical'],
            'write that removes' => ['core_course_remove_content_item_from_user_favourites', 'critical'],
            'other write' => ['core_course_create_courses', 'high'],
            'read' => ['core_course_get_courses', null],
            'unknown function' => ['core_no_such_function', null],
        ];
    }

    /**
     * MDL-UNIT-022: ws_execute takes its severity from the function type and name (delete, remove, unenrol are critical).
     * MDL-UNIT-021: web service execution is classified by the type of the invoked function.
     *
     * @dataProvider ws_severity_provider
     * @param string $wsname Web service function.
     * @param string|null $expected Expected severity.
     */
    public function test_severity_of_ws_execute_depends_on_the_function(string $wsname, ?string $expected): void {
        $this->resetAfterTest();
        $this->assertSame($expected, pending_action_service::severity('ws_execute', ['wsname' => $wsname, 'params' => '{}']));
        $this->assertSame($expected, pending_action_service::severity('ws_execute', ['ws_name' => $wsname, 'params' => '{}']));
    }

    /**
     * Web service functions registered without a declared type and their expected severity.
     *
     * @return array
     */
    public static function untyped_ws_severity_provider(): array {
        return [
            'untyped function' => ['local_jedomi_untyped_update_things', 'high'],
            'untyped function that deletes' => ['local_jedomi_untyped_delete_things', 'critical'],
        ];
    }

    /**
     * MDL-UNIT-022: a web service function without a declared type is held for confirmation.
     * MDL-UNIT-021: an existing function without a declared read type is treated as a write.
     *
     * @dataProvider untyped_ws_severity_provider
     * @param string $wsname Web service function registered without a type in db/services.php.
     * @param string $expected Expected severity.
     */
    public function test_severity_of_ws_execute_holds_a_function_without_declared_type(string $wsname, string $expected): void {
        global $DB;
        $this->resetAfterTest();
        // Registered in the database but absent from db/services.php, so Moodle sets no type for it.
        $DB->insert_record('external_functions', (object) [
            'name' => $wsname,
            'classname' => \local_jedomi\external\resolve_action::class,
            'methodname' => 'execute',
            'classpath' => null,
            'component' => 'local_jedomi',
            'capabilities' => '',
        ]);
        $info = \core_external\external_api::external_function_info($wsname);
        $this->assertFalse(isset($info->type));

        $this->assertSame($expected, pending_action_service::severity('ws_execute', ['wsname' => $wsname, 'params' => '{}']));
    }

    /**
     * MDL-INT-045: the held action carries description, data and level for the card.
     * A1: holding a writing tool stores it for the user and describes it for the confirmation card.
     */
    public function test_hold_stores_the_action_and_returns_the_card_data(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $pending = pending_action_service::hold((int) $user->id, 'delete_agent', ['id' => 42]);

        $record = $DB->get_record(pending_action_service::TABLE, ['id' => $pending['id']], '*', MUST_EXIST);
        $this->assertSame((int) $user->id, (int) $record->userid);
        $this->assertSame('delete_agent', $record->tool);
        $this->assertSame(['id' => 42], json_decode($record->argsjson, true));
        $this->assertSame('critical', $record->severity);
        $this->assertSame('delete_agent', $pending['tool']);
        $this->assertSame('critical', $pending['severity']);
        $this->assertSame(get_string('pending_desc_delete_agent', 'local_jedomi', 42), $pending['description']);
    }

    /**
     * A1: a reading tool is not held.
     */
    public function test_hold_ignores_reading_tools(): void {
        global $DB;
        $this->resetAfterTest();

        $this->assertNull(pending_action_service::hold(2, 'execute_sql', ['sql' => 'SELECT 1']));
        $this->assertSame(0, $DB->count_records(pending_action_service::TABLE));
    }

    /**
     * A5: the card for send_messages carries the number of recipients and a preview of the message.
     */
    public function test_hold_send_messages_carries_count_and_preview(): void {
        $this->resetAfterTest();
        $message = str_repeat('Reminder: submit your essay. ', 20);

        $pending = pending_action_service::hold(2, 'send_messages', ['user_ids' => [5, '6', 5, 0, -1], 'message' => $message]);

        $this->assertSame('medium', $pending['severity']);
        $this->assertSame(2, $pending['count']);
        $this->assertSame(\core_text::substr(trim($message), 0, 120), $pending['preview']);
    }

    /**
     * MDL-UNIT-062: actions past their validity are purged and recent ones are kept.
     * Expired actions are purged; recent ones are kept.
     */
    public function test_purge_expired_removes_only_old_actions(): void {
        global $DB;
        $this->resetAfterTest();
        $old = pending_action_service::hold(2, 'delete_agent', ['id' => 1]);
        $new = pending_action_service::hold(2, 'delete_agent', ['id' => 2]);
        $DB->set_field(pending_action_service::TABLE, 'timecreated', time() - pending_action_service::EXPIRY - 1, ['id' => $old['id']]);

        $this->assertSame(1, pending_action_service::purge_expired());

        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $old['id']]));
        $this->assertTrue($DB->record_exists(pending_action_service::TABLE, ['id' => $new['id']]));
    }

    /**
     * MDL-UNIT-023: the send card names recipients by their real role and pending actions are described in readable language.
     * [Pendiente:skip]
     */
    public function test_pending_actions_are_described_in_readable_language(): void {
        $this->markTestSkipped('Pending fix: the send card always says students and other actions show technical details.');
    }

    /**
     * Wait for the start of a new second so a few database operations run within the same second.
     *
     * @return int The current time.
     */
    private function start_of_a_new_second(): int {
        $now = time();
        while (time() === $now) {
            usleep(10000);
        }
        return time();
    }

    /**
     * MDL-UNIT-062: an action is still valid exactly at the validity limit and expired one second after.
     *
     * @return void
     */
    public function test_action_validity_at_the_limit_and_just_after(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $atlimit = pending_action_service::hold((int) $user->id, 'delete_agent', ['id' => 1]);
        $justafter = pending_action_service::hold((int) $user->id, 'delete_agent', ['id' => 2]);
        $inside = pending_action_service::hold((int) $user->id, 'delete_agent', ['id' => 3]);

        $now = $this->start_of_a_new_second();
        $DB->set_field(pending_action_service::TABLE, 'timecreated', $now - pending_action_service::EXPIRY, ['id' => $atlimit['id']]);
        $DB->set_field(pending_action_service::TABLE, 'timecreated', $now - pending_action_service::EXPIRY - 1,
            ['id' => $justafter['id']]);
        $DB->set_field(pending_action_service::TABLE, 'timecreated', $now - pending_action_service::EXPIRY + 1,
            ['id' => $inside['id']]);

        $this->assertSame('cancelled', pending_action_service::resolve($atlimit['id'], (int) $user->id, false)['status']);
        $this->assertSame('cancelled', pending_action_service::resolve($inside['id'], (int) $user->id, false)['status']);
        try {
            pending_action_service::resolve($justafter['id'], (int) $user->id, false);
            $this->fail('An action one second past its validity must be expired');
        } catch (\moodle_exception $e) {
            $this->assertSame('pending_action_expired', $e->errorcode);
        }
    }

    /**
     * MDL-UNIT-062: purging keeps an action exactly at the validity limit and removes one a second past it.
     *
     * @return void
     */
    public function test_purge_keeps_the_action_at_the_limit_and_removes_the_one_just_after(): void {
        global $DB;
        $this->resetAfterTest();
        $atlimit = pending_action_service::hold(2, 'delete_agent', ['id' => 1]);
        $justafter = pending_action_service::hold(2, 'delete_agent', ['id' => 2]);

        $now = $this->start_of_a_new_second();
        $DB->set_field(pending_action_service::TABLE, 'timecreated', $now - pending_action_service::EXPIRY, ['id' => $atlimit['id']]);
        $DB->set_field(pending_action_service::TABLE, 'timecreated', $now - pending_action_service::EXPIRY - 1,
            ['id' => $justafter['id']]);

        $this->assertSame(1, pending_action_service::purge_expired());
        $this->assertTrue($DB->record_exists(pending_action_service::TABLE, ['id' => $atlimit['id']]));
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $justafter['id']]));
    }
}
