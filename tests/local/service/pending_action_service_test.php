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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/plugin_tools/fake_tool_plugin.php');

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
            'site_overview' => ['site_overview', ['days' => 30], null],
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
            'read whose name only has a plural noun' => ['core_course_get_updates_since', null],
            'read whose name has a write verb' => ['mod_forum_can_add_discussion', 'high'],
            'read that sets favourites' => ['core_course_set_favourite_courses', 'high'],
            'read that creates evidence' => ['core_competency_create_user_evidence_competency', 'high'],
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
        $one = (int) $this->getDataGenerator()->create_user()->id;
        $two = (int) $this->getDataGenerator()->create_user()->id;

        $pending = pending_action_service::hold(2, 'send_messages', ['user_ids' => [$one, (string) $two, $one, 0, -1],
            'message' => $message]);

        $this->assertSame('medium', $pending['severity']);
        $this->assertSame(2, $pending['count']);
        $this->assertSame(trim($message), $pending['preview'], 'The whole message is shown (JEDOMI-SEC-013)');
        $this->assertFalse($pending['truncated']);
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
        $DB->set_field(
            pending_action_service::TABLE,
            'timecreated',
            time() - pending_action_service::EXPIRY - 1,
            ['id' => $old['id']]
        );

        $this->assertSame(1, pending_action_service::purge_expired());

        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $old['id']]));
        $this->assertTrue($DB->record_exists(pending_action_service::TABLE, ['id' => $new['id']]));
    }

    /**
     * MDL-UNIT-023: the send card names recipients by their real role and pending actions are described in readable language.
     *
     * @return void
     */
    public function test_pending_actions_are_described_in_readable_language(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $sender = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $reachable = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $deleted = $this->getDataGenerator()->create_and_enrol($course, 'student');
        delete_user($deleted);

        // Only the recipients that will get the message are counted: not the sender, a deleted or a missing user.
        $card = pending_action_service::hold((int) $sender->id, 'send_messages', ['user_ids' => [(int) $reachable->id,
            (int) $sender->id, (int) $deleted->id, 987654], 'message' => 'Class moved to room 3']);
        $this->assertSame(1, $card['count']);
        $this->assertSame(get_string('pending_desc_send_messages', 'local_jedomi', 1), $card['description']);
        $text = get_string_manager()->get_string('confirm_send_bulk', 'local_jedomi', null, 'en');
        $this->assertStringNotContainsString('student', $text, 'Recipients are not always students');

        // The data of the other actions is shown as readable lines, never as JSON.
        $card = pending_action_service::hold((int) $sender->id, 'ws_execute', ['wsname' => 'core_user_update_users',
            'params' => json_encode(['users' => [['id' => 5, 'firstname' => 'Ana', 'suspended' => true]]])]);
        $this->assertSame(
            "Users › #1 › Id: 5\nUsers › #1 › Firstname: Ana\nUsers › #1 › Suspended: " . get_string('yes'),
            $card['details']
        );
        $this->assertStringNotContainsString('{', $card['details']);

        $card = pending_action_service::hold((int) $sender->id, 'create_agent', ['name' => 'Digest', 'prompt' => 'Sum up',
            'schedule_cron' => '0 8 * * 1']);
        $this->assertSame("Name: Digest\nPrompt: Sum up\nSchedule cron: 0 8 * * 1", $card['details']);

        // The cards are the same when the panel loads them again after a reload.
        $reloaded = pending_action_service::cards_for_user((int) $sender->id);
        $this->assertCount(3, $reloaded);
        $this->assertSame($card, end($reloaded));
        $this->assertSame(3, $DB->count_records(pending_action_service::TABLE, ['userid' => $sender->id]));
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
        $DB->set_field(
            pending_action_service::TABLE,
            'timecreated',
            $now - pending_action_service::EXPIRY,
            ['id' => $atlimit['id']]
        );
        $DB->set_field(
            pending_action_service::TABLE,
            'timecreated',
            $now - pending_action_service::EXPIRY - 1,
            ['id' => $justafter['id']]
        );
        $DB->set_field(
            pending_action_service::TABLE,
            'timecreated',
            $now - pending_action_service::EXPIRY + 1,
            ['id' => $inside['id']]
        );

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
        $DB->set_field(
            pending_action_service::TABLE,
            'timecreated',
            $now - pending_action_service::EXPIRY,
            ['id' => $atlimit['id']]
        );
        $DB->set_field(
            pending_action_service::TABLE,
            'timecreated',
            $now - pending_action_service::EXPIRY - 1,
            ['id' => $justafter['id']]
        );

        $this->assertSame(1, pending_action_service::purge_expired());
        $this->assertTrue($DB->record_exists(pending_action_service::TABLE, ['id' => $atlimit['id']]));
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $justafter['id']]));
    }

    /**
     * Agent preview bug: call_webservice with "function" is the same write as ws_execute with "wsname".
     */
    public function test_call_webservice_with_function_is_classified_like_ws_execute(): void {
        $this->assertSame(
            'high',
            pending_action_service::severity('call_webservice', ['function' => 'core_course_create_categories'])
        );
        $this->assertSame(
            'high',
            pending_action_service::severity('ws_execute', ['function' => 'core_course_create_categories'])
        );
        $this->assertNull(pending_action_service::severity('call_webservice', ['function' => 'core_course_get_categories']));
        $this->assertSame(
            ['ws_execute', ['params' => '{}', 'wsname' => 'core_course_create_categories']],
            pending_action_service::normalise('call_webservice', ['function' => 'core_course_create_categories', 'params' => '{}'])
        );
        $this->assertSame(['delete_agent', ['id' => 3]], pending_action_service::normalise('delete_agents', ['id' => 3]));
    }

    /**
     * JEDOMI-SEC-001: an action held by a scheduled agent is stored normalised, names its agent and waits for days, not
     * minutes, for its creator.
     */
    public function test_action_held_by_an_agent_waits_for_its_creator(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $agentid = (int) $DB->insert_record('local_jedomi_agents', (object) ['userid' => $creator->id, 'name' => 'Night watch',
            'prompt' => 'p', 'schedule_cron' => '0 8 * * *', 'timecreated' => time(), 'timemodified' => time()]);
        $chat = pending_action_service::hold((int) $creator->id, 'delete_agent', ['id' => 1]);
        $held = pending_action_service::hold(
            (int) $creator->id,
            'call_webservice',
            ['function' => 'core_course_create_categories', 'params' => '{}'],
            $agentid
        );
        $DB->set_field(pending_action_service::TABLE, 'timecreated', time() - pending_action_service::EXPIRY - 1, []);

        $record = $DB->get_record(pending_action_service::TABLE, ['id' => $held['id']]);
        $this->assertSame('ws_execute', $record->tool);
        $this->assertSame($agentid, (int) $record->agentid);
        $this->assertSame('core_course_create_categories', json_decode($record->argsjson, true)['wsname']);

        $cards = pending_action_service::cards_for_user((int) $creator->id);
        $this->assertSame([$held['id']], array_column($cards, 'id'), 'Only the agent action is still valid');
        $this->assertStringContainsString('Night watch', $cards[0]['description']);

        $this->assertSame(1, pending_action_service::purge_expired());
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $chat['id']]));
        $this->assertTrue($DB->record_exists(pending_action_service::TABLE, ['id' => $held['id']]));

        $DB->set_field(pending_action_service::TABLE, 'timecreated', time() - pending_action_service::AGENT_EXPIRY - 1, []);
        $this->assertSame(1, pending_action_service::purge_expired());
    }

    /**
     * Hold a write of a scheduled agent for its creator.
     *
     * @param int $creatorid Creator of the agent.
     * @param int $agentid Agent id.
     * @return int Pending action id.
     */
    private function hold_for_agent(int $creatorid, int $agentid): int {
        return (int) pending_action_service::hold(
            $creatorid,
            'ws_execute',
            ['wsname' => 'core_course_create_categories', 'params' => '{"categories":[{"name":"x"}]}'],
            $agentid
        )['id'];
    }

    /**
     * Assert that resolving an action fails with an error code and keeps the action.
     *
     * @param int $id Pending action id.
     * @param int $actorid User resolving it.
     * @param bool $confirm Whether the user confirms it.
     * @param string $errorcode Expected error code.
     * @return void
     */
    private function assert_refused(int $id, int $actorid, bool $confirm, string $errorcode): void {
        global $DB;
        try {
            pending_action_service::resolve($id, $actorid, $confirm);
            $this->fail('The action must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertTrue($DB->record_exists(pending_action_service::TABLE, ['id' => $id]), 'The action is kept');
    }

    /**
     * RISK-003: only the creator of an agent approves the writes it held. A site administrator may cancel them but never
     * approve them on behalf of the creator, and any other user can do neither.
     */
    public function test_only_the_agent_creator_approves_its_held_writes(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $agentid = (int) $DB->insert_record('local_jedomi_agents', (object) ['userid' => $creator->id, 'name' => 'Night watch',
            'prompt' => 'p', 'schedule_cron' => '0 8 * * *', 'timecreated' => time(), 'timemodified' => time()]);
        $id = $this->hold_for_agent((int) $creator->id, $agentid);

        $this->assert_refused($id, (int) get_admin()->id, true, 'pending_action_not_found');
        $this->assert_refused($id, (int) $other->id, true, 'pending_action_not_found');
        $this->assert_refused($id, (int) $other->id, false, 'pending_action_not_found');

        $result = pending_action_service::resolve($id, (int) get_admin()->id, false);
        $this->assertSame(pending_action_service::STATUS_CANCELLED, $result['status']);
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $id]));
        $this->assertFalse($DB->record_exists('course_categories', ['name' => 'x']));
    }

    /**
     * RISK-003: a write held by an agent that now belongs to another user, or that was deleted, is not approved by its
     * former creator, who may still cancel it.
     */
    public function test_held_write_of_a_reassigned_or_deleted_agent_is_not_approved(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $newowner = $this->getDataGenerator()->create_user();
        $agentid = (int) $DB->insert_record('local_jedomi_agents', (object) ['userid' => $creator->id, 'name' => 'Night watch',
            'prompt' => 'p', 'schedule_cron' => '0 8 * * *', 'timecreated' => time(), 'timemodified' => time()]);
        $reassigned = $this->hold_for_agent((int) $creator->id, $agentid);
        $deleted = $this->hold_for_agent((int) $creator->id, $agentid + 1000);
        $DB->set_field('local_jedomi_agents', 'userid', $newowner->id, ['id' => $agentid]);

        $this->assert_refused($reassigned, (int) $creator->id, true, 'pending_action_not_creator');
        $this->assert_refused($reassigned, (int) $newowner->id, true, 'pending_action_not_found');
        $this->assert_refused($deleted, (int) $creator->id, true, 'pending_action_not_creator');

        $result = pending_action_service::resolve($reassigned, (int) $creator->id, false);
        $this->assertSame(pending_action_service::STATUS_CANCELLED, $result['status']);
    }

    /**
     * Make the test plugin the only one that answers the tool registry hook, with these tools.
     *
     * @param \local_jedomi\agent\tool\agent_tool[] $tools Tools the test plugin registers.
     */
    private function register_plugin_tools(array $tools): void {
        \local_jedomi\fixtures\fake_tool_plugin::reset();
        \local_jedomi\fixtures\fake_tool_plugin::$tools = $tools;
        \core\di::set(\core\hook\manager::class, \core\hook\manager::phpunit_get_instance([
            'fake_toolplugin' => __DIR__ . '/../../fixtures/plugin_tools/hooks.php',
        ]));
    }

    /**
     * A held plugin tool runs only once the user confirms it, as that user, in the context it was held in; the context
     * is never shown on the card.
     */
    public function test_confirmed_plugin_tool_runs_as_the_user_in_its_context(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $context = \context_course::instance($course->id);
        $seen = [];
        $tool = new \local_jedomi\fixtures\fake_plugin_tool('demo_writer', function (int $userid, \context $c) use (&$seen) {
            $seen[] = $c->id;
            return true;
        }, null, null, false);
        $this->register_plugin_tools([$tool]);

        $pending = pending_action_service::hold_plugin_tool((int) $teacher->id, 'demo_writer', ['q' => 'go'], $context);

        $this->assertSame('high', $pending['severity']);
        $this->assertStringNotContainsString((string) $context->id, $pending['details']);
        $this->assertSame(get_string('pending_desc_plugin_tool', 'local_jedomi', 'demo_writer'), $pending['description']);
        $this->assertSame([], \local_jedomi\fixtures\fake_plugin_tool::$runs);

        $result = pending_action_service::resolve($pending['id'], (int) $teacher->id, true);

        $this->assertSame(pending_action_service::STATUS_EXECUTED, $result['status']);
        $this->assertSame(['demo_writer'], \local_jedomi\fixtures\fake_plugin_tool::$runs);
        $this->assertSame([$context->id], $seen);
    }

    /**
     * A cancelled plugin tool never runs.
     */
    public function test_cancelled_plugin_tool_does_not_run(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->register_plugin_tools([new \local_jedomi\fixtures\fake_plugin_tool('demo_writer', true, null, null, false)]);
        $pending = pending_action_service::hold_plugin_tool((int) $user->id, 'demo_writer', [], \context_system::instance());

        $result = pending_action_service::resolve($pending['id'], (int) $user->id, false);

        $this->assertSame(pending_action_service::STATUS_CANCELLED, $result['status']);
        $this->assertSame([], \local_jedomi\fixtures\fake_plugin_tool::$runs);
    }

    /**
     * A plugin tool no longer offered to the user when it is confirmed does not run.
     */
    public function test_confirmed_plugin_tool_no_longer_offered_does_not_run(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->register_plugin_tools([new \local_jedomi\fixtures\fake_plugin_tool('demo_writer', false, null, null, false)]);
        $pending = pending_action_service::hold_plugin_tool((int) $user->id, 'demo_writer', [], \context_system::instance());

        $result = pending_action_service::resolve($pending['id'], (int) $user->id, true);

        $this->assertSame(pending_action_service::STATUS_FAILED, $result['status']);
        $this->assertSame([], \local_jedomi\fixtures\fake_plugin_tool::$runs);
    }

    /**
     * The AI cannot pass a plugin context to a Jedomi action: the key is dropped when a Jedomi action is held.
     */
    public function test_jedomi_action_never_keeps_a_plugin_context_from_the_ai(): void {
        global $DB;
        $this->resetAfterTest();
        $args = ['id' => 4, pending_action_service::PLUGIN_CONTEXT_KEY => 1];

        $pending = pending_action_service::hold(2, 'delete_agent', $args);

        $record = $DB->get_record(pending_action_service::TABLE, ['id' => $pending['id']]);
        $this->assertSame(['id' => 4], json_decode($record->argsjson, true));
    }

    /**
     * JEDOMI-SEC-013 (RE-POC-5), SEC-JD-043, RISK-002: the card of a held write shows every argument, also the ones after
     * the twelfth line, with their whole values, and is not marked as cut.
     */
    public function test_card_shows_every_argument_of_the_write(): void {
        $this->resetAfterTest();
        $assignments = [];
        for ($i = 1; $i <= 4; $i++) {
            $assignments[] = ['roleid' => 5, 'userid' => 900000 + $i, 'contextid' => 1];
        }
        $assignments[] = ['roleid' => 1, 'userid' => 900005, 'contextid' => 1];
        $long = str_repeat('Long text of the description. ', 10);
        $args = ['wsname' => 'core_role_assign_roles', 'params' => json_encode(['assignments' => $assignments,
            'note' => $long])];

        $card = pending_action_service::hold(2, 'ws_execute', $args, 0);

        $this->assertStringContainsString("Assignments › #5 › Roleid: 1\nAssignments › #5 › Userid: 900005", $card['details']);
        $this->assertStringContainsString(trim($long), $card['details']);
        $this->assertStringNotContainsString('…', $card['details']);
        $this->assertFalse($card['truncated']);
    }

    /**
     * JEDOMI-SEC-013, SEC-JD-043: arguments too long to show whole are marked as cut, and the action can only be
     * cancelled: confirming it is refused and the action is kept.
     */
    public function test_cut_card_is_marked_and_cannot_be_confirmed(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $args = ['name' => 'Huge', 'prompt' => str_repeat('a', pending_action_service::DETAILS_LENGTH + 10),
            'schedule_cron' => '0 8 * * *'];

        $card = pending_action_service::hold((int) $user->id, 'create_agent', $args);

        $this->assertTrue($card['truncated']);
        $this->assertLessThanOrEqual(pending_action_service::DETAILS_LENGTH, \core_text::strlen($card['details']));
        $this->assertTrue(pending_action_service::cards_for_user((int) $user->id)[0]['truncated']);
        try {
            pending_action_service::resolve($card['id'], (int) $user->id, true);
            $this->fail('A cut action must not be confirmed');
        } catch (\moodle_exception $e) {
            $this->assertSame('pending_action_too_long', $e->errorcode);
        }
        $this->assertTrue($DB->record_exists(pending_action_service::TABLE, ['id' => $card['id']]));
        $result = pending_action_service::resolve($card['id'], (int) $user->id, false);
        $this->assertSame(pending_action_service::STATUS_CANCELLED, $result['status']);
        $this->assertSame(0, $DB->count_records('local_jedomi_agents'));
    }

    /**
     * REL-002: the same write held again by the same agent, in another run, is not stored twice; another write, the
     * same write of another agent and the same action of the chat are.
     */
    public function test_same_write_of_an_agent_is_held_once(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $make = fn(string $name): int => (int) $DB->insert_record('local_jedomi_agents', (object) ['userid' => $creator->id,
            'name' => $name, 'prompt' => 'p', 'schedule_cron' => '0 8 * * *', 'timecreated' => time(),
            'timemodified' => time()]);
        $agentid = $make('Night watch');
        $otheragent = $make('Day watch');
        $args = ['wsname' => 'core_course_create_categories', 'params' => '{"categories":[{"name":"x"}]}'];

        $first = pending_action_service::hold((int) $creator->id, 'ws_execute', $args, $agentid);
        $again = pending_action_service::hold((int) $creator->id, 'call_webservice', ['function' => $args['wsname'],
            'params' => $args['params']], $agentid);
        pending_action_service::hold((int) $creator->id, 'ws_execute', ['wsname' => $args['wsname'],
            'params' => '{"categories":[{"name":"y"}]}'], $agentid);
        pending_action_service::hold((int) $creator->id, 'ws_execute', $args, $otheragent);
        pending_action_service::hold((int) $creator->id, 'ws_execute', $args);
        pending_action_service::hold((int) $creator->id, 'ws_execute', $args);

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(2, $DB->count_records(pending_action_service::TABLE, ['agentid' => $agentid]));
        $this->assertSame(1, $DB->count_records(pending_action_service::TABLE, ['agentid' => $otheragent]));
        $this->assertSame(2, $DB->count_records(pending_action_service::TABLE, ['agentid' => 0]));
    }

    /**
     * RES-004: the cards of the writes held by agents are loaded with the same number of queries whatever their number.
     */
    public function test_cards_of_held_writes_do_not_query_once_per_row(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $agents = [];
        for ($i = 0; $i < 4; $i++) {
            $agents[] = (int) $DB->insert_record('local_jedomi_agents', (object) ['userid' => $creator->id,
                'name' => 'Agent ' . $i, 'prompt' => 'p', 'schedule_cron' => '0 8 * * *', 'timecreated' => time(),
                'timemodified' => time()]);
        }
        $hold = function (int $agentid, string $name) use ($creator): void {
            $params = json_encode(['categories' => [['name' => $name]]]);
            $args = ['wsname' => 'core_course_create_categories', 'params' => $params];
            pending_action_service::hold((int) $creator->id, 'ws_execute', $args, $agentid);
        };
        $hold($agents[0], 'a');
        $reads = $DB->perf_get_reads();
        pending_action_service::cards_for_user((int) $creator->id);
        $one = $DB->perf_get_reads() - $reads;
        foreach ($agents as $index => $agentid) {
            $hold($agentid, 'b' . $index);
            $hold($agentid, 'c' . $index);
        }

        $reads = $DB->perf_get_reads();
        $cards = pending_action_service::cards_for_user((int) $creator->id);
        $many = $DB->perf_get_reads() - $reads;

        $this->assertCount(9, $cards);
        $this->assertSame($one, $many);
        $this->assertStringContainsString('Agent 3', end($cards)['description']);
    }

    /**
     * REL-002: the writes held by an agent that no longer exists are removed by the cleanup and not shown.
     */
    public function test_writes_held_by_a_deleted_agent_are_purged(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $agentid = (int) $DB->insert_record('local_jedomi_agents', (object) ['userid' => $creator->id, 'name' => 'Gone',
            'prompt' => 'p', 'schedule_cron' => '0 8 * * *', 'timecreated' => time(), 'timemodified' => time()]);
        $orphan = $this->hold_for_agent((int) $creator->id, $agentid);
        $kept = pending_action_service::hold((int) $creator->id, 'delete_agent', ['id' => 1]);
        $DB->delete_records('local_jedomi_agents', ['id' => $agentid]);

        $this->assertSame([$kept['id']], array_column(pending_action_service::cards_for_user((int) $creator->id), 'id'));
        $this->assertSame(1, pending_action_service::purge_expired());
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $orphan]));
        $this->assertTrue($DB->record_exists(pending_action_service::TABLE, ['id' => $kept['id']]));
    }
}
