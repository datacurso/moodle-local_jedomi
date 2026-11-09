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

namespace local_jedomi\agent;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\ai\fake_curl;
use local_jedomi\ai\testable_provider_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for the agent preview over the AI client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\preview_runner
 */
final class preview_runner_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * MDL-UNIT-083: assistant tool calls precede the messages with their results.
     * M6: the assistant message with its tool_calls goes before the tool messages.
     */
    public function test_assistant_tool_calls_precede_tool_messages(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'no_such_tool', 'arguments' => '{}']],
        ]);
        $fake->enqueue_text('Final answer');
        client::set_instance_for_testing($fake);

        $result = preview_runner::run('List the users', (int) get_admin()->id);

        $this->assertTrue($result['ok']);
        $this->assertSame('Final answer', $result['answer']);
        $this->assertCount(2, $fake->calls);
        $second = $fake->calls[1]['messages'];
        $this->assertSame('assistant', $second[2]['role']);
        $this->assertSame('call_1', $second[2]['tool_calls'][0]['id']);
        $this->assertSame('tool', $second[3]['role']);
        $this->assertSame('call_1', $second[3]['tool_call_id']);
        $this->assertSame((int) get_admin()->id, $fake->calls[0]['userid']);
    }

    /**
     * MDL-UNIT-083: tool calls given as text are recorded on the assistant message.
     * M6: tool calls written as text are also declared on the assistant message before their results.
     */
    public function test_text_tool_calls_are_declared_on_assistant_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_text("TOOL_CALL: no_such_tool\n{\"a\":1}\nEND_TOOL_CALL");
        $fake->enqueue_text('Final answer');
        client::set_instance_for_testing($fake);

        preview_runner::run('Count courses', (int) get_admin()->id);

        $second = $fake->calls[1]['messages'];
        $this->assertSame('assistant', $second[2]['role']);
        $this->assertSame('no_such_tool', $second[2]['tool_calls'][0]['function']['name']);
        $this->assertSame('tool', $second[3]['role']);
        $this->assertSame($second[2]['tool_calls'][0]['id'], $second[3]['tool_call_id']);
    }

    /**
     * MDL-UNIT-083: an AI error is returned with its translated message.
     * M6: an AI error gives the translated message instead of gateway details.
     */
    public function test_ai_error_returns_translated_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_exception(new \moodle_exception('httperror', 'aiprovider_datacurso', '', 503));
        client::set_instance_for_testing($fake);

        $result = preview_runner::run('List the users', (int) get_admin()->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('unavailable', $result['error_code']);
        $this->assertSame(get_string('ai_error_unavailable', 'local_jedomi'), $result['error']);
    }

    /**
     * MDL-INT-003: with the provider disabled the preview shows its message and sends nothing.
     * I6: with the provider disabled the preview shows the provider's message and nothing is sent.
     */
    public function test_preview_with_the_provider_disabled_shows_the_provider_message(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the provider client cannot be built.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 0);
        $curl = new fake_curl();
        client::set_instance_for_testing(testable_provider_api::make_client($curl));

        $result = preview_runner::run('List the users', (int) get_admin()->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('provider_disabled', $result['error_code']);
        $this->assertSame(get_string('providerdisabled', 'aiprovider_datacurso'), $result['error']);
        $this->assertSame([], $curl->calls);
    }
    /**
     * A cached preview returns the cache key of the prompt, so the page can clear that entry.
     *
     * @return void
     */
    public function test_cached_preview_returns_the_cache_key(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $DB->insert_record('local_jedomi_agent_cache', (object) ['agentid' => 0, 'context_hash' => md5('List the users'),
            'result_json' => json_encode(['answer' => 'Cached answer']), 'timecreated' => 1700000000]);
        client::set_instance_for_testing(new fake_ai_client());

        $result = preview_runner::run('  List the users ', (int) get_admin()->id);

        $this->assertTrue($result['cached']);
        $this->assertSame('Cached answer', $result['answer']);
        $this->assertSame(md5('List the users'), $result['cache_hash']);
    }

    /**
     * MDL-UNIT-046: the preview instructions limit the actions that change data to explicit user requests.
     */
    public function test_preview_instructions_limit_writing_actions_to_explicit_requests(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_text('Final answer');
        client::set_instance_for_testing($fake);

        preview_runner::run('Notify the students of course 5', (int) get_admin()->id);

        $system = $fake->calls[0]['messages'][0];
        $this->assertSame('system', $system['role']);
        $this->assertStringContainsString('when the user explicitly asks for that action', $system['content']);
        $this->assertStringContainsString('Never call send_messages to answer a question', $system['content']);
    }

    /**
     * MDL-INT-025: the preview runs the prompt without saving an agent and returns every tool with its duration, rows,
     * arguments and result, the total time and the final answer; clearing its cache runs it again.
     */
    public function test_preview_returns_the_detail_of_the_run_and_can_be_repeated(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
                'arguments' => json_encode(['query' => 'SELECT id FROM {course}'])]],
        ]);
        $fake->enqueue_text('There is one course.');
        $fake->enqueue_text('Run again.');
        client::set_instance_for_testing($fake);
        \local_jedomi\agent\tool_executor::get_definitions(1, (int) get_admin()->id);

        $result = preview_runner::run('How many courses are there?', (int) get_admin()->id);

        $this->assertTrue($result['ok']);
        $this->assertSame('There is one course.', $result['answer']);
        $this->assertSame(0, $DB->count_records('local_jedomi_agents'), 'A preview never saves an agent');
        $this->assertCount(1, $result['profiler']);
        $step = $result['profiler'][0];
        $this->assertSame('execute_sql', $step['tool']);
        $this->assertStringContainsString('SELECT id FROM {course}', $step['arguments']);
        $this->assertNotSame('', $step['result_preview']);
        $this->assertGreaterThanOrEqual(1, $step['row_count']);
        $this->assertIsFloat((float) $step['duration_ms']);
        $this->assertEqualsWithDelta(array_sum(array_column($result['profiler'], 'duration_ms')), $result['total_time_ms'], 0.01);
        $this->assertNotEmpty($result['conversation']);

        $again = preview_runner::run('How many courses are there?', (int) get_admin()->id);
        $this->assertTrue($again['cached']);
        $this->assertNotEmpty($again['cached_at'], 'A saved result is identified by its date');

        \local_jedomi\agent\agent_manager::clear_preview_cache($again['cache_hash']);
        $calls = count($fake->calls);
        $fresh = preview_runner::run('How many courses are there?', (int) get_admin()->id);
        $this->assertArrayNotHasKey('cached', $fresh);
        $this->assertGreaterThan($calls, count($fake->calls), 'After clearing the cache the preview runs again');
    }

    /**
     * MDL-INT-026: a preview never runs an action that changes data without confirmation.
     * [Pendiente:fail] The preview really runs writing actions such as deleting an agent.
     */
    public function test_preview_does_not_run_writing_actions_without_confirmation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $agentid = \local_jedomi\agent\agent_manager::create_agent((object) ['userid' => (int) get_admin()->id,
            'prompt' => 'Daily report', 'schedule_cron' => '0 8 * * *']);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'delete_agent',
                'arguments' => json_encode(['id' => $agentid])]],
        ]);
        $fake->enqueue_text('Deleted.');
        client::set_instance_for_testing($fake);

        preview_runner::run('Delete the daily report agent', (int) get_admin()->id);

        $this->assertNotNull(\local_jedomi\agent\agent_manager::get_agent($agentid),
            'The preview must not delete an agent without confirmation');
    }

    /**
     * MDL-INT-026: the saved result of a preview is private to the user who ran it.
     * [Pendiente:fail] Any user who previews the same prompt receives the saved result of the first one.
     */
    public function test_saved_preview_is_not_shared_between_users(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_text('Private answer for the administrator.');
        $fake->enqueue_text('Answer for the manager.');
        client::set_instance_for_testing($fake);
        preview_runner::run('Summarise my week', (int) get_admin()->id);

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->setUser($manager);
        $result = preview_runner::run('Summarise my week', (int) $manager->id);

        $this->assertNotSame('Private answer for the administrator.', $result['answer'],
            'A second user must not receive the saved preview of the first one');
    }

    /**
     * SYS-E2E-010: the preview of an agent can query the database and the site configuration with the web service list
     * of its creator, so its result matches the scheduled run. Behat: agents_tab.feature.
     * [Pendiente:skip]
     */
    public function test_preview_uses_the_same_tools_as_the_scheduled_run(): void {
        $this->markTestSkipped('Pending fix: the preview cannot query the database nor the site configuration and uses '
            . 'the web service list of the Student role.');
    }
}
