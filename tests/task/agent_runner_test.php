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

namespace local_jedomi\task;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\ai\fake_curl;
use local_jedomi\ai\testable_provider_api;
use local_jedomi\telegram\api_client;
use local_jedomi\telegram\fake_telegram_api_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for the scheduled agent runner over the AI client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\task\agent_runner
 */
final class agent_runner_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Create a due site-level agent owned by a user.
     *
     * @param int $userid Agent creator.
     * @return \stdClass
     */
    private function create_due_agent(int $userid): \stdClass {
        global $DB;
        // The task checks the plugin state through aiprovider_datacurso before running any agent.
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the agent runner cannot check the plugin state.');
        }
        $now = time();
        $record = (object) [
            'userid' => $userid,
            'name' => 'Daily report',
            'prompt' => 'How many users are there?',
            'schedule_cron' => '0 8 * * *',
            'context_type' => 'site',
            'context_id' => null,
            'role_filter' => null,
            'enabled' => 1,
            'lastrun' => null,
            'nextrun' => $now - 60,
            'failcount' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_jedomi_agents', $record);
        return $record;
    }

    /**
     * Run the task and return what it printed.
     *
     * @return string
     */
    private function run_task(): string {
        ob_start();
        (new agent_runner())->execute();
        return ob_get_clean();
    }

    /**
     * MDL-INT-001: agents call the AI through the same provider client.
     * M5: the agent calls the AI client on behalf of its creator, not of the session user.
     * MDL-CTR-001: in the agents the user sent to the AI service is the creator.
     */
    public function test_agent_calls_client_with_creator_userid(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $agent = $this->create_due_agent((int) $creator->id);
        $fake = new fake_ai_client();
        $fake->enqueue_text('There are 3 users.');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $this->assertCount(1, $fake->calls);
        $this->assertSame((int) $creator->id, $fake->calls[0]['userid']);
        $messages = $fake->calls[0]['messages'];
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'How many users are there?'], $messages[1]);
        $after = $DB->get_record('local_jedomi_agents', ['id' => $agent->id]);
        $this->assertSame(0, (int) $after->failcount);
        $this->assertNotEmpty($after->lastrun);
    }

    /**
     * MDL-UNIT-083: agents also send the assistant tool calls before the tool results.
     * M5: after a tool call, the next request carries the assistant tool call before its tool result.
     */
    public function test_agent_sends_assistant_tool_calls_before_tool_results(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $this->create_due_agent((int) $creator->id);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_7', 'type' => 'function', 'function' => ['name' => 'no_such_tool', 'arguments' => '{}']],
        ]);
        $fake->enqueue_text('Done.');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $this->assertCount(2, $fake->calls);
        $second = $fake->calls[1]['messages'];
        $this->assertSame('assistant', $second[2]['role']);
        $this->assertSame('call_7', $second[2]['tool_calls'][0]['id']);
        $this->assertSame('tool', $second[3]['role']);
        $this->assertSame('call_7', $second[3]['tool_call_id']);
        $this->assertSame((int) $creator->id, $fake->calls[1]['userid']);
    }

    /**
     * MDL-UNIT-083: an AI error in an agent is reported with its translated message.
     * M5: an AI error fails the run with the translated message and counts a failure.
     */
    public function test_agent_ai_error_counts_failure_with_translated_message(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $agent = $this->create_due_agent((int) $creator->id);
        $fake = new fake_ai_client();
        $fake->enqueue_exception(new \moodle_exception('notenoughtokens', 'aiprovider_datacurso'));
        client::set_instance_for_testing($fake);

        $output = $this->run_task();

        $this->assertStringContainsString(get_string('ai_error_nocredit', 'local_jedomi'), $output);
        $after = $DB->get_record('local_jedomi_agents', ['id' => $agent->id]);
        $this->assertSame(1, (int) $after->failcount);
    }

    /**
     * MDL-INT-003: with the provider disabled the agents show its message and send nothing.
     * I6: with the provider disabled the run fails with the provider's message and nothing is sent.
     */
    public function test_agent_with_the_provider_disabled_fails_with_the_provider_message(): void {
        global $DB;
        $this->resetAfterTest();
        // A forced setting, not set_config(): changing an existing row of config_plugins makes the reset after
        // the test reload the whole table, which took about ten seconds per test.
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 0);
        $creator = $this->getDataGenerator()->create_user();
        $agent = $this->create_due_agent((int) $creator->id);
        $curl = new fake_curl();
        client::set_instance_for_testing(testable_provider_api::make_client($curl));

        $output = $this->run_task();

        $this->assertStringContainsString(get_string('providerdisabled', 'aiprovider_datacurso'), $output);
        $this->assertSame([], $curl->calls);
        $after = $DB->get_record('local_jedomi_agents', ['id' => $agent->id]);
        $this->assertSame(1, (int) $after->failcount);
    }

    /**
     * Queue one tool call answer.
     *
     * @param fake_ai_client $fake AI client.
     * @param string $id Tool call id.
     * @param array $args Tool arguments.
     * @return void
     */
    private function enqueue_tool_call(fake_ai_client $fake, string $id, array $args): void {
        $fake->enqueue_tool_calls([
            ['id' => $id, 'type' => 'function', 'function' => ['name' => 'no_such_tool', 'arguments' => json_encode($args)]],
        ]);
    }

    /**
     * Tool messages of the last request sent to the AI, in order.
     *
     * @param fake_ai_client $fake AI client.
     * @return string[] Contents of the tool messages.
     */
    private function tool_results(fake_ai_client $fake): array {
        $last = end($fake->calls)['messages'];
        $tools = array_values(array_filter($last, static fn(array $m): bool => $m['role'] === 'tool'));
        return array_column($tools, 'content');
    }

    /**
     * MDL-UNIT-030: the run stops at the maximum number of steps even if the AI keeps asking for tools.
     */
    public function test_agent_run_stops_at_the_maximum_number_of_steps(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $this->create_due_agent((int) $creator->id);
        $fake = new fake_ai_client();
        for ($i = 1; $i <= agent_runner::MAX_TOOL_ITERATIONS + 5; $i++) {
            $this->enqueue_tool_call($fake, 'call_' . $i, ['step' => $i]);
        }
        client::set_instance_for_testing($fake);

        $this->run_task();

        $this->assertCount(agent_runner::MAX_TOOL_ITERATIONS, $fake->calls);
        $this->assertCount(5, $fake->queue, 'The answers after the maximum must never be requested');
    }

    /**
     * MDL-UNIT-030: repeating the same tool with the same data is cut instead of running again.
     */
    public function test_repeated_identical_tool_call_is_cut(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $this->create_due_agent((int) $creator->id);
        $fake = new fake_ai_client();
        for ($i = 1; $i <= 7; $i++) {
            $this->enqueue_tool_call($fake, 'call_' . $i, ['table' => 'user']);
        }
        $fake->enqueue_text('Done.');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $results = $this->tool_results($fake);
        $this->assertCount(7, $results);
        $looped = array_filter($results, static fn(string $r): bool => str_contains($r, 'loop_detected'));
        $this->assertNotEmpty($looped, 'The repeated identical call must be cut');
        $this->assertStringNotContainsString('loop_detected', $results[0], 'The first call runs normally');
        $this->assertStringContainsString('loop_detected', end($results));
    }

    /**
     * MDL-UNIT-030: the same tool with different data is allowed every time.
     */
    public function test_same_tool_with_different_data_is_allowed(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $this->create_due_agent((int) $creator->id);
        $fake = new fake_ai_client();
        for ($i = 1; $i <= 7; $i++) {
            $this->enqueue_tool_call($fake, 'call_' . $i, ['table' => 'table_' . $i]);
        }
        $fake->enqueue_text('Done.');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $results = $this->tool_results($fake);
        $this->assertCount(7, $results);
        foreach ($results as $result) {
            $this->assertStringNotContainsString('loop_detected', $result);
        }
    }

    /**
     * MDL-UNIT-046: the agent instructions limit the actions that change data to explicit user requests.
     */
    public function test_agent_instructions_limit_writing_actions_to_explicit_requests(): void {
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $this->create_due_agent((int) $creator->id);
        $fake = new fake_ai_client();
        $fake->enqueue_text('There are 3 users.');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $system = $fake->calls[0]['messages'][0];
        $this->assertSame('system', $system['role']);
        $this->assertStringContainsString('when the user explicitly asks for that action', $system['content']);
        $this->assertStringContainsString('Never call send_messages to answer a question', $system['content']);
    }

    /**
     * MDL-UNIT-063: the Telegram message of an agent names the agent, the run mark and the date, and the deactivation
     * message names the agent and the reason.
     *
     * @return void
     */
    public function test_agent_telegram_messages_name_the_agent_the_result_and_the_date(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('telegram_enabled', 1, 'local_jedomi');
        $telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($telegram);
        $creator = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user();
        foreach ([$creator, $suspended] as $i => $user) {
            $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $user->id, 'chatid' => '77400010' . $i,
                'username' => 'user' . $i, 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        }
        $this->create_due_agent((int) $creator->id);
        $disabled = $this->create_due_agent((int) $suspended->id);
        $DB->update_record('local_jedomi_agents', (object) ['id' => $disabled->id, 'name' => 'Risk watch']);
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);
        $fake = new fake_ai_client();
        $fake->enqueue_text('There are 3 users.');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $bychat = array_column($telegram->sent, 'text', 'chatid');
        $this->assertArrayHasKey('774000100', $bychat, 'The result is delivered to the creator');
        $result = $bychat['774000100'];
        $this->assertStringContainsString('Daily report', $result);
        $this->assertStringContainsString('✅', $result, 'The message marks a successful run');
        $this->assertStringContainsString('There are 3 users.', $result);
        $this->assertStringContainsString(userdate(time(), get_string('strftimedaydate', 'langconfig')), $result);

        $this->assertArrayHasKey('774000101', $bychat, 'The deactivation is notified to the creator');
        $notice = $bychat['774000101'];
        $this->assertStringContainsString('Risk watch', $notice);
        $this->assertStringContainsString(get_string('agent_disabled_notice', 'local_jedomi'), $notice);
        $this->assertStringContainsString(get_string('agent_disabled_reason_creator_suspended', 'local_jedomi'), $notice);
    }

    /**
     * Last tool result sent to the AI in a recorded call.
     *
     * @param array $call Call recorded by the fake AI client.
     * @return array Decoded tool result.
     */
    private function last_tool_result(array $call): array {
        $tools = array_values(array_filter($call['messages'], static fn(array $m): bool => $m['role'] === 'tool'));
        return (array) json_decode((string) end($tools)['content'], true);
    }

    /**
     * Queue one tool call followed by a final text.
     *
     * @param fake_ai_client $fake AI client.
     * @param string $tool Tool name.
     * @param array $args Tool arguments.
     */
    private function enqueue_named_tool_call(fake_ai_client $fake, string $tool, array $args): void {
        $fake->enqueue_tool_calls([
            ['id' => 'call_' . $tool, 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode($args)]],
        ]);
        $fake->enqueue_text('Done.');
    }

    /**
     * MDL-INT-029: the task runs only the enabled agents that are due, the most overdue first, and records the run and the
     * next one.
     */
    public function test_due_agents_run_most_overdue_first_and_get_their_next_run(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $recent = $this->create_due_agent((int) $creator->id);
        $oldest = $this->create_due_agent((int) $creator->id);
        $DB->update_record(
            'local_jedomi_agents',
            (object) ['id' => $recent->id, 'prompt' => 'Recent prompt', 'nextrun' => time() - 60]
        );
        $DB->update_record(
            'local_jedomi_agents',
            (object) ['id' => $oldest->id, 'prompt' => 'Oldest prompt', 'nextrun' => time() - 3600]
        );
        $future = $this->create_due_agent((int) $creator->id);
        $DB->update_record(
            'local_jedomi_agents',
            (object) ['id' => $future->id, 'prompt' => 'Future prompt', 'nextrun' => time() + 3600]
        );
        $disabled = $this->create_due_agent((int) $creator->id);
        $DB->update_record('local_jedomi_agents', (object) ['id' => $disabled->id, 'prompt' => 'Disabled prompt', 'enabled' => 0]);
        $fake = new fake_ai_client();
        $fake->enqueue_text('First.');
        $fake->enqueue_text('Second.');
        client::set_instance_for_testing($fake);
        $before = time();

        $this->run_task();

        $prompts = array_map(static fn(array $call): string => $call['messages'][1]['content'], $fake->calls);
        $this->assertSame(['Oldest prompt', 'Recent prompt'], $prompts);
        foreach ([$oldest, $recent] as $agent) {
            $after = $DB->get_record('local_jedomi_agents', ['id' => $agent->id]);
            $this->assertGreaterThanOrEqual($before, (int) $after->lastrun);
            $this->assertGreaterThan(time(), (int) $after->nextrun, 'The next run is calculated from the schedule');
        }
        $this->assertEmpty($DB->get_field('local_jedomi_agents', 'lastrun', ['id' => $future->id]));
        $this->assertEmpty($DB->get_field('local_jedomi_agents', 'lastrun', ['id' => $disabled->id]));
    }

    /**
     * MDL-INT-030: an agent whose creator is suspended is disabled and not run.
     */
    public function test_agent_of_a_suspended_creator_is_disabled_without_running(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $agent = $this->create_due_agent((int) $creator->id);
        $fake = new fake_ai_client();
        client::set_instance_for_testing($fake);

        $this->run_task();

        $this->assertSame([], $fake->calls);
        $this->assertSame(0, (int) $DB->get_field('local_jedomi_agents', 'enabled', ['id' => $agent->id]));
    }

    /**
     * MDL-INT-030: an agent whose creator lost access to its course is disabled and not run; with access it runs on behalf
     * of the creator, who is the user the credits are charged to.
     */
    public function test_agent_runs_only_while_its_creator_keeps_access_to_the_context(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $withaccess = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $withoutaccess = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($course->id);
        $kept = $this->create_due_agent((int) $withaccess->id);
        $lost = $this->create_due_agent((int) $withoutaccess->id);
        foreach ([$kept, $lost] as $agent) {
            $DB->update_record('local_jedomi_agents', (object) ['id' => $agent->id, 'context_type' => 'course',
                'context_id' => $context->id]);
        }
        $fake = new fake_ai_client();
        $fake->enqueue_text('Course summary.');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $this->assertCount(1, $fake->calls);
        $this->assertSame((int) $withaccess->id, $fake->calls[0]['userid']);
        $this->assertSame(1, (int) $DB->get_field('local_jedomi_agents', 'enabled', ['id' => $kept->id]));
        $this->assertSame(0, (int) $DB->get_field('local_jedomi_agents', 'enabled', ['id' => $lost->id]));
    }

    /**
     * MDL-INT-031: in one cycle each agent runs its tools with the permissions of its own creator, from the first run.
     */
    public function test_each_agent_runs_its_tools_with_the_permissions_of_its_creator(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        $student = $this->getDataGenerator()->create_user();
        $adminagent = $this->create_due_agent((int) $admin->id);
        $studentagent = $this->create_due_agent((int) $student->id);
        $DB->set_field('local_jedomi_agents', 'nextrun', time() - 3600, ['id' => $adminagent->id]);
        $DB->set_field('local_jedomi_agents', 'prompt', 'Count the courses as student', ['id' => $studentagent->id]);
        $fake = new fake_ai_client();
        $this->enqueue_named_tool_call($fake, 'execute_sql', ['query' => 'SELECT COUNT(id) AS total FROM {course}']);
        $this->enqueue_named_tool_call($fake, 'execute_sql', ['query' => 'SELECT COUNT(id) AS total FROM {course}']);
        client::set_instance_for_testing($fake);

        $this->run_task();

        $this->assertCount(4, $fake->calls);
        $this->assertSame((int) $admin->id, $fake->calls[1]['userid']);
        $this->assertTrue(
            $this->last_tool_result($fake->calls[1])['success'] ?? false,
            'The administrator agent must query the database with its creator permissions on its first run'
        );
        $this->assertSame((int) $student->id, $fake->calls[3]['userid']);
        $this->assertSame(
            'permission_denied',
            $this->last_tool_result($fake->calls[3])['error'] ?? null,
            'The student agent must not use the permissions of the administrator agent of the same cycle'
        );
    }

    /**
     * MDL-INT-032: the tools of an agent check the permissions of its creator, not of the user the task runs as.
     */
    public function test_agent_tools_check_the_permissions_of_the_creator(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $student = $this->getDataGenerator()->create_user();
        $foreign = \local_jedomi\agent\agent_manager::create_agent((object) ['userid' => (int) get_admin()->id,
            'prompt' => 'Admin report', 'schedule_cron' => '0 8 * * *']);
        $this->create_due_agent((int) $student->id);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'admin_search', 'arguments' => '{"query":"cache"}']],
            ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'delete_agent',
                'arguments' => json_encode(['id' => $foreign])]],
            ['id' => 'c3', 'type' => 'function', 'function' => ['name' => 'create_agent',
                'arguments' => json_encode(
                    ['prompt' => 'Spy', 'schedule_cron' => '0 8 * * *', 'userid' => (int) get_admin()->id]
                )]],
        ]);
        $fake->enqueue_text('Done.');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $results = array_values(array_filter($fake->calls[1]['messages'], static fn(array $m): bool => $m['role'] === 'tool'));
        $this->assertSame(
            'permission_denied',
            json_decode($results[0]['content'], true)['error'] ?? null,
            'An agent of a student must not run administration operations'
        );
        $this->assertNotNull(\local_jedomi\agent\agent_manager::get_agent($foreign), 'An agent must not delete agents of others');
        $spies = $DB->count_records_select(
            'local_jedomi_agents',
            $DB->sql_compare_text('prompt') . ' = ? AND userid = ?',
            ['Spy', (int) get_admin()->id]
        );
        $this->assertSame(
            0,
            $spies,
            'An agent must not create agents on behalf of another user'
        );
    }

    /**
     * The instructions of a course agent, in the scheduled run and in the preview, name the real course (its id and
     * name), not the id of the course context.
     */
    public function test_course_agent_instructions_name_the_real_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Agent Course Marker']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $contextid = (int) \context_course::instance($course->id)->id;
        $this->assertNotSame((int) $course->id, $contextid, 'Control: the context id differs from the course id');
        $agent = $this->create_due_agent((int) $teacher->id);
        $DB->update_record('local_jedomi_agents', (object) ['id' => $agent->id, 'context_type' => 'course',
            'context_id' => $contextid]);
        $fake = new fake_ai_client();
        $fake->enqueue_text('Report.');
        $fake->enqueue_text('Preview.');
        client::set_instance_for_testing($fake);

        $this->run_task();
        \local_jedomi\agent\preview_runner::run('Course report', (int) $teacher->id, 'course', $contextid);

        $this->assertCount(2, $fake->calls);
        foreach (['scheduled run' => 0, 'preview' => 1] as $label => $index) {
            $instructions = (string) $fake->calls[$index]['messages'][0]['content'];
            $this->assertStringContainsString(
                get_string('ctx_loc_course_id', 'local_jedomi', $course->id),
                $instructions,
                "The {$label} names the course id"
            );
            $this->assertStringNotContainsString(
                get_string('ctx_loc_course_id', 'local_jedomi', $contextid),
                $instructions,
                "The {$label} does not use the context id as course id"
            );
            $this->assertStringContainsString('Agent Course Marker', $instructions, "The {$label} names the course");
        }
    }

    /**
     * MDL-INT-042: in the agents the database query never reveals credentials, keys or configuration.
     */
    public function test_agent_database_query_excludes_sensitive_data(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        $victim = $this->getDataGenerator()->create_user(['username' => 'victim', 'password' => 'Victim-Pass-1']);
        set_config('licensekey', 'LicenseMarker42', 'aiprovider_datacurso');
        $queries = [
            'password hashes' => ["SELECT username, password FROM {user} WHERE id = {$victim->id}",
                (string) $DB->get_field('user', 'password', ['id' => $victim->id])],
            'plugin keys' => ["SELECT value FROM {config_plugins} WHERE name = 'licensekey'", 'LicenseMarker42'],
        ];
        \local_jedomi\agent\tool_executor::get_definitions(1, (int) $admin->id);

        foreach ($queries as $label => [$sql, $secret]) {
            $result = \local_jedomi\agent\tool_executor::execute('execute_sql', json_encode(['query' => $sql]));
            $this->assertStringNotContainsString($secret, $result, "An agent must reject the query on {$label}");
        }
    }

    /**
     * Run an agent of the administrator whose AI queries a named user and answers with the placeholders it received.
     *
     * @param string $level Anonymisation level of the Privacy tab.
     * @return array{0: string, 1: string} Tool result the AI received and the result delivered by Telegram.
     */
    private function agent_tool_result_with_level(string $level): array {
        global $DB;
        $this->resetAfterTest();
        set_config('anonymization_level', $level, 'local_jedomi');
        set_config('telegram_enabled', 1, 'local_jedomi');
        $telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($telegram);
        $admin = get_admin();
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $admin->id, 'chatid' => '774000300',
            'username' => 'admin', 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        $named = $this->getDataGenerator()->create_user(['firstname' => 'Ximena', 'lastname' => 'Quintanilla',
            'email' => 'ximena.quintanilla@example.com', 'idnumber' => 'ID-778899']);
        $this->create_due_agent((int) $admin->id);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
            'arguments' => json_encode(['query' => 'SELECT id, firstname, lastname, email, idnumber FROM {user} WHERE id = '
                . (int) $named->id])]]]);
        $fake->enqueue_text('The user is [person_1] [person_2].');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $this->assertCount(2, $fake->calls);
        $tools = array_values(array_filter($fake->calls[1]['messages'], static fn(array $m): bool => $m['role'] === 'tool'));
        $this->assertNotEmpty($tools);
        $bychat = array_column($telegram->sent, 'text', 'chatid');
        return [(string) end($tools)['content'], (string) ($bychat['774000300'] ?? '')];
    }

    /**
     * MDL-CTR-007: in the agents, with the Basic level the AI receives names and e-mails returned by the tools
     * replaced, and the result delivered to the creator has the real values back.
     */
    public function test_agent_basic_level_replaces_names_and_emails_sent_to_the_ai(): void {
        [$result, $delivered] = $this->agent_tool_result_with_level('basic');

        $this->assertStringNotContainsString('ximena.quintanilla@example.com', $result);
        $this->assertStringNotContainsString('Quintanilla', $result);
        $this->assertStringContainsString('Ximena Quintanilla', $delivered);
    }

    /**
     * MDL-CTR-007: in the agents, with the Strict level identifiers are also replaced in what the AI receives.
     */
    public function test_agent_strict_level_also_replaces_identifiers_sent_to_the_ai(): void {
        [$result] = $this->agent_tool_result_with_level('strict');

        $this->assertStringNotContainsString('ximena.quintanilla@example.com', $result);
        $this->assertStringNotContainsString('ID-778899', $result);
    }

    /**
     * MDL-INT-033: every run of an agent produces a new result with the data of the moment.
     * [Pendiente:skip]
     */
    public function test_each_run_reflects_the_data_of_the_moment(): void {
        $this->markTestSkipped('Pending fix: agents without tools or with a writing action resend the answer saved on '
            . 'their first run, and a failed query resends the previous result.');
    }

    /**
     * MDL-INT-034: failures follow the schedule, are visible and disable the agent when they repeat.
     * [Pendiente:skip]
     */
    public function test_repeated_failures_follow_the_schedule_and_disable_the_agent(): void {
        $this->markTestSkipped('Pending fix: a failing agent is retried every minute forever, never disabled for repeated '
            . 'failures, and the Failing state is not shown anywhere.');
    }

    /**
     * MDL-INT-035: the result of every agent can be consulted in Moodle.
     * [Pendiente:skip]
     */
    public function test_agent_result_can_be_consulted_in_moodle(): void {
        $this->markTestSkipped('Pending feature: the agent result is not shown anywhere in Moodle, so without a linked '
            . 'Telegram, with the integration disabled or with the role blocked for Telegram it never reaches its creator.');
    }

    /**
     * MDL-UNIT-120: the description of the query given to the AI in Telegram and in the agents tells the limits that
     * are really applied there, and the parameters offered to an agent are applied or not offered.
     * [Pendiente:skip]
     */
    public function test_ai_is_told_the_real_query_limits_of_its_channel(): void {
        $this->markTestSkipped('Pending fix: Telegram and the agents get the query description of the web chat (its row '
            . 'and time limits and a parameters field), while Telegram applies fewer rows and the agents apply larger '
            . 'limits and ignore the parameters.');
    }

    /**
     * MDL-INT-044: agents run web services with the same format and validation as the other channels.
     * [Pendiente:skip]
     */
    public function test_agents_run_web_services_with_the_standard_format_and_validation(): void {
        $this->markTestSkipped('Pending fix: in agents the parameters arrive in another format than the one given to the AI '
            . 'and the Moodle parameter validation is skipped.');
    }
    /**
     * Run the due agent of a creator linked to Telegram and return what the technical log says about its delivery.
     *
     * No Telegram client is replaced here: with no bot token configured every delivery attempt ends in the log as
     * "Telegram sent" or "Telegram send FAILED", so the log lines are the trace of an attempt to send to Telegram.
     *
     * @param \stdClass $creator Agent creator.
     * @return string[] Log lines about a Telegram delivery of the agent result.
     */
    private function telegram_delivery_attempts(\stdClass $creator): array {
        global $CFG, $DB;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $creator->id, 'chatid' => '774000001',
            'username' => 'creator', 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        $this->create_due_agent((int) $creator->id);
        $fake = new fake_ai_client();
        $fake->enqueue_text('Three students are at risk: Ana, Ben and Cy.');
        client::set_instance_for_testing($fake);
        $logfile = $CFG->dataroot . '/local/jedomi/jedomi.log';
        @unlink($logfile);

        $this->run_task();

        $this->assertCount(1, $fake->calls, 'The agent ran');
        $lines = is_readable($logfile) ? file($logfile) : [];
        return array_values(array_filter($lines, static fn(string $line): bool => strpos($line, '— Telegram send') !== false
            || strpos($line, '— Telegram sent') !== false));
    }

    /**
     * MDL-INT-074: with the Telegram integration disabled the result of an agent is not sent to its linked creator.
     */
    public function test_agent_result_is_not_sent_to_telegram_with_the_integration_disabled(): void {
        $this->resetAfterTest();
        set_config('telegram_enabled', 0, 'local_jedomi');
        unset_config('telegram_bot_token', 'local_jedomi');
        $creator = $this->getDataGenerator()->create_user();

        $this->assertSame([], $this->telegram_delivery_attempts($creator), 'Nothing is sent to a disabled integration');
    }

    /**
     * MDL-INT-074: with the role of the creator blocked for Telegram the result of an agent is not sent to Telegram.
     */
    public function test_agent_result_is_not_sent_to_telegram_when_the_role_is_blocked(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('telegram_enabled', 1, 'local_jedomi');
        unset_config('telegram_bot_token', 'local_jedomi');
        $creator = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($managerroleid, $creator->id, \context_system::instance());
        set_config('telegram_hidden_roles', (string) $managerroleid, 'local_jedomi');

        $this->assertSame([], $this->telegram_delivery_attempts($creator), 'Nothing is sent to a blocked role');
    }
    /**
     * MDL-INT-088: the agents of a deleted course are disabled in the next run and do not reach the AI.
     */
    public function test_agents_of_a_deleted_course_are_disabled(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $creator = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $agent = $this->create_due_agent((int) $creator->id);
        $DB->update_record('local_jedomi_agents', (object) ['id' => $agent->id, 'context_type' => 'course',
            'context_id' => \context_course::instance($course->id)->id]);
        delete_course($course, false);
        $fake = new fake_ai_client();
        $fake->enqueue_text('Course summary.');
        client::set_instance_for_testing($fake);

        $this->run_task();

        $this->assertSame([], $fake->calls);
        $this->assertSame(0, (int) $DB->get_field('local_jedomi_agents', 'enabled', ['id' => $agent->id]));
    }

    /**
     * SYS-E2E-009: an agent enabled from the Agents tab waits for its scheduled hour, and its hour is the one of the time
     * zone of its creator. Behat: agents_tab.feature.
     */
    public function test_enabled_agent_runs_at_its_hour_in_the_creator_time_zone(): void {
        $this->resetAfterTest();
        $timezone = 'America/Bogota';
        $creator = $this->getDataGenerator()->create_user(['timezone' => $timezone]);
        $id = \local_jedomi\agent\agent_manager::create_agent((object) [
            'userid' => (int) $creator->id,
            'name' => 'Morning report',
            'prompt' => 'How many users logged in yesterday?',
            'schedule_cron' => '0 8 * * *',
            'context_type' => 'site',
            'context_id' => null,
            'enabled' => 0,
        ]);
        // The Agents tab enables an agent by updating its enabled flag.
        \local_jedomi\agent\agent_manager::update_agent($id, (object) ['enabled' => 1]);

        $due = array_map(static fn($agent): int => (int) $agent->id, \local_jedomi\agent\agent_manager::get_due_agents());
        $nextrun = (int) \local_jedomi\agent\agent_manager::get_agent($id)->nextrun;
        $this->assertNotContains($id, $due, 'A newly enabled agent waits for its scheduled hour');
        $this->assertGreaterThan(time(), $nextrun, 'A newly enabled agent has its next run in the future');

        \local_jedomi\agent\agent_manager::mark_run($id, true);
        $next = new \DateTime('@' . \local_jedomi\agent\agent_manager::get_agent($id)->nextrun);
        $next->setTimezone(new \DateTimeZone($timezone));
        $this->assertSame('08:00', $next->format('H:i'), 'The schedule is applied in the time zone of the creator');
    }

    /**
     * MDL-INT-102: a Category or User context agent created by a teacher is not disabled on its first run for lack of
     * a permission the teacher role does not have, or the form does not offer those contexts.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_teacher_category_or_user_agents_are_not_disabled_by_an_incoherent_check(): void {
        $this->markTestSkipped('Pending fix: Category and User context agents of a teacher are disabled on their first '
            . 'run because the access check needs the permission to view courses.');
    }
}
