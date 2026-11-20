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
    use \local_jedomi\tests\spanish_strings;

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * MDL-CTR-011: in the preview, a "person_1" or "Person 1" in the text the document reading returns reaches the AI as
     * a literal placeholder, so the answer gives it back as written and never as the real value of [person_1].
     */
    public function test_placeholders_in_a_read_document_are_not_restored(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the provider client cannot be built.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        $curl = new fake_curl();
        \local_jedomi\local\service\provider_upload_service::set_api_for_testing(new testable_provider_api(30, $curl));
        $this->getDataGenerator()->create_user(['firstname' => 'Bruno', 'lastname' => 'Valderrama']);
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($page->cmid)->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'syllabus.pdf',
        ], '%PDF-1.4 fake');
        $curl->enqueue(json_encode(['text' => 'Bruno signed for person_1 and Person 1.', 'model' => 'm',
            'pages_total' => 1, 'pages_vision' => [], 'pages_skipped' => []]));
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'document_read',
                'arguments' => json_encode(['cmid' => (int) $page->cmid])]],
        ]);
        $fake->enqueue_text('[person_1] signed for [literal_1] and [literal_2].');
        client::set_instance_for_testing($fake);

        try {
            $result = preview_runner::run('Who signed the syllabus?', (int) get_admin()->id);
        } finally {
            \local_jedomi\local\service\provider_upload_service::set_api_for_testing(null);
        }

        $this->assertCount(1, $curl->calls, 'The document was read');
        $tool = json_encode($fake->calls[1]['messages']);
        $this->assertStringContainsString('[person_1] signed for [literal_1] and [literal_2].', $tool);
        $this->assertSame('Bruno signed for person_1 and Person 1.', $result['answer']);
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
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
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
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_text('Cached answer');
        client::set_instance_for_testing($fake);
        preview_runner::run('List the users', (int) get_admin()->id);

        $result = preview_runner::run('  List the users ', (int) get_admin()->id);

        $this->assertTrue($result['cached']);
        $this->assertSame('Cached answer', $result['answer']);
        $this->assertNotEmpty($result['cache_hash']);
        $this->assertTrue(
            \local_jedomi\agent\agent_manager::clear_preview_cache($result['cache_hash']),
            'The returned key clears that saved preview'
        );
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
     * REL-008 / JEDOMI-SEC-005: a preview answered from its cache replays the saved plan and sends the fresh tool results
     * to the AI between untrusted data markers, with the rule about them; the reformulated answer gets the real values.
     */
    public function test_cached_preview_replay_sends_tool_results_as_untrusted_data(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
                'arguments' => json_encode(['query' => 'SELECT id FROM {course}'])]],
        ]);
        $fake->enqueue_text('There is one course.');
        $fake->enqueue_text('There is still one course.');
        client::set_instance_for_testing($fake);
        preview_runner::run('How many courses are there?', (int) get_admin()->id);

        $again = preview_runner::run('How many courses are there?', (int) get_admin()->id);

        $this->assertTrue($again['reformulated'] ?? false, 'Control: the cached plan was replayed');
        $this->assertSame('There is still one course.', $again['answer']);
        $reform = end($fake->calls)['messages'];
        $this->assertStringContainsString(\local_jedomi\local\untrusted::rule(), $reform[0]['content']);
        $this->assertMatchesRegularExpression('/<<<UNTRUSTED_DATA id=(\w+) source="tool execute_sql">>>\n.*\n'
            . '<<<END_UNTRUSTED_DATA id=\1>>>/s', $reform[1]['content']);
        $this->assertStringNotContainsString('--- execute_sql ---', $reform[1]['content']);
    }

    /**
     * Phase 23 unit 6: a preview answered from its cache sends the cached answer anonymised with the placeholders of the
     * cached run, even when the identifier it shows changed since; the reformulated answer gets the real values.
     */
    public function test_cached_preview_replay_sends_the_cached_answer_anonymised(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $student = $this->getDataGenerator()->create_user(['idnumber' => 'ZX9-4471']);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
                'arguments' => json_encode(['query' => 'SELECT idnumber FROM {user} WHERE id = ' . (int) $student->id])]],
        ]);
        $fake->enqueue_text('The code is ZX9-4471.');
        $fake->enqueue_text('The code is still the same.');
        client::set_instance_for_testing($fake);
        $first = preview_runner::run('What is the code of the student?', (int) get_admin()->id);
        $this->assertStringContainsString('ZX9-4471', $first['answer'], 'Control: the creator sees the real value');
        $DB->set_field('user', 'idnumber', 'QQ7-0001', ['id' => $student->id]);

        $again = preview_runner::run('What is the code of the student?', (int) get_admin()->id);

        $this->assertTrue($again['reformulated'] ?? false, 'Control: the cached plan was replayed');
        $reform = end($fake->calls)['messages'];
        $sent = json_encode($reform, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('ZX9-4471', $sent, 'The cached answer reaches the AI anonymised');
        $this->assertStringNotContainsString('QQ7-0001', $sent, 'The fresh results reach the AI anonymised');
        $this->assertMatchesRegularExpression('/Previous answer:\nThe code is \[id_\d+\]\./', $reform[1]['content']);
    }

    /**
     * MDL-INT-026: a preview never runs an action that changes data without confirmation.
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

        $this->assertNotNull(
            \local_jedomi\agent\agent_manager::get_agent($agentid),
            'The preview must not delete an agent without confirmation'
        );
    }

    /**
     * MDL-INT-026: the saved result of a preview is private to the user who ran it.
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

        $this->assertNotSame(
            'Private answer for the administrator.',
            $result['answer'],
            'A second user must not receive the saved preview of the first one'
        );
    }

    /**
     * SYS-E2E-010: the preview of an agent can query the database and the site configuration with the web service list
     * of its creator, so its result matches the scheduled run. Behat: agents_tab.feature.
     * MDL-INT-026: the preview uses the permissions, the context, the tools and the step limit of the scheduled run.
     */
    public function test_preview_uses_the_same_tools_as_the_scheduled_run(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int) get_admin()->id;
        // Another user ran a tool before, as in a previous request: the preview must not keep their permissions.
        $previous = new \local_jedomi\agent\tool_executor(1, (int) $this->getDataGenerator()->create_user()->id);
        $previous->execute('search_tables', '{"query":"user"}');
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
                'arguments' => json_encode(['query' => 'SELECT COUNT(id) AS total FROM {course}'])]],
            ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'get_site_config',
                'arguments' => json_encode(['keys' => ['fullname']])]],
        ]);
        $fake->enqueue_text('Done.');
        client::set_instance_for_testing($fake);

        $result = preview_runner::run('How many courses are there?', $adminid);

        $this->assertTrue($result['ok']);
        foreach ($result['profiler'] as $step) {
            $this->assertNull($step['error'], "The preview must run {$step['tool']} with the permissions of its user");
        }
        [, $system, $tools] = \local_jedomi\task\agent_runner::run_conditions($adminid, 'site', null);
        $this->assertSame($tools, $fake->calls[0]['tools'], 'The preview offers the tools of the scheduled run');
        $this->assertSame($system, $fake->calls[0]['messages'][0], 'The preview gives the instructions of the scheduled run');
    }

    /**
     * MDL-INT-026: the preview stops at the same maximum number of steps as the scheduled run.
     */
    public function test_preview_stops_at_the_step_limit_of_the_scheduled_run(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        for ($i = 1; $i <= \local_jedomi\task\agent_runner::MAX_TOOL_ITERATIONS + 2; $i++) {
            $fake->enqueue_tool_calls([
                ['id' => 'call_' . $i, 'type' => 'function', 'function' => ['name' => 'no_such_tool',
                    'arguments' => json_encode(['step' => $i])]],
            ]);
        }
        client::set_instance_for_testing($fake);

        preview_runner::run('Keep going', (int) get_admin()->id);

        $this->assertCount(\local_jedomi\task\agent_runner::MAX_TOOL_ITERATIONS, $fake->calls);
    }

    /**
     * MDL-INT-026: the saved preview of a user cannot be cleared by another user who knows its key.
     */
    public function test_saved_preview_can_only_be_cleared_by_its_user(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_text('Saved answer');
        client::set_instance_for_testing($fake);
        preview_runner::run('Summarise my week', (int) get_admin()->id);
        $hash = preview_runner::run('Summarise my week', (int) get_admin()->id)['cache_hash'];
        $other = $this->getDataGenerator()->create_user();

        $this->assertFalse(\local_jedomi\agent\agent_manager::clear_preview_cache($hash, (int) $other->id));
        $this->assertTrue(\local_jedomi\agent\agent_manager::clear_preview_cache($hash, (int) get_admin()->id));
    }

    /**
     * Anonymisation levels of the Privacy tab and the personal data the AI must not receive with each one.
     *
     * @return array
     */
    public static function anonymization_level_provider(): array {
        return [
            'basic' => ['basic', ['ximena.quintanilla@example.com', 'Quintanilla', 'ID-778899']],
            'strict' => ['strict', ['ximena.quintanilla@example.com', 'Quintanilla', 'ID-778899']],
        ];
    }

    /**
     * MDL-INT-100: the preview sends the tool results to the AI anonymised with the level of the Privacy tab, as the
     * scheduled run does.
     *
     * @dataProvider anonymization_level_provider
     * @param string $level Anonymisation level.
     * @param string[] $hidden Personal data the AI must not receive.
     * @return void
     */
    public function test_preview_anonymises_the_tool_results_like_the_scheduled_run(string $level, array $hidden): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('anonymization_level', $level, 'local_jedomi');
        $named = $this->getDataGenerator()->create_user(['firstname' => 'Ximena', 'lastname' => 'Quintanilla',
            'email' => 'ximena.quintanilla@example.com', 'idnumber' => 'ID-778899']);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
            'arguments' => json_encode(['query' => 'SELECT id, firstname, lastname, email, idnumber FROM {user} WHERE id = '
                . (int) $named->id])]]]);
        $fake->enqueue_text('Done.');
        client::set_instance_for_testing($fake);

        $result = preview_runner::run('Who is Ximena?', (int) get_admin()->id);

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $fake->calls);
        $tools = array_values(array_filter($fake->calls[1]['messages'], static fn(array $m): bool => $m['role'] === 'tool'));
        $this->assertNotEmpty($tools);
        $sent = (string) end($tools)['content'];
        $this->assertStringContainsString('row', $sent, 'Control: the query ran');
        foreach ($hidden as $value) {
            $this->assertStringNotContainsString($value, $sent, "With the {$level} level the AI must not receive {$value}");
        }
    }

    /**
     * MDL-INT-100: the answer of the preview, written from anonymised data, shows the real values to the administrator,
     * and the reformulation of a saved preview sends the fresh results and the previous answer to the AI anonymised.
     *
     * @return void
     */
    public function test_preview_shows_real_values_and_reformulates_with_anonymised_data(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('anonymization_level', 'basic', 'local_jedomi');
        $named = $this->getDataGenerator()->create_user(['firstname' => 'Ximena', 'lastname' => 'Quintanilla',
            'email' => 'ximena.quintanilla@example.com']);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
            'arguments' => json_encode(['query' => 'SELECT id, firstname, lastname, email FROM {user} WHERE id = '
                . (int) $named->id])]]]);
        $fake->enqueue_text('The user is [person_1] [person_2].');
        $fake->enqueue_text('Still [person_1] [person_2], [email_1].');
        client::set_instance_for_testing($fake);

        $first = preview_runner::run('Who is that user?', (int) get_admin()->id);

        $this->assertTrue($first['ok']);
        $this->assertSame('The user is Ximena Quintanilla.', $first['answer']);
        $this->assertStringContainsString('Quintanilla', $first['profiler'][0]['result_preview'], 'The profiler is real');
        $this->assertStringContainsString('Quintanilla', json_encode($first['conversation']), 'The conversation is real');

        $again = preview_runner::run('Who is that user?', (int) get_admin()->id);

        $this->assertTrue($again['reformulated'] ?? false, 'Control: the saved plan was replayed');
        $this->assertSame('Still Ximena Quintanilla, ximena.quintanilla@example.com.', $again['answer']);
        $sent = json_encode(end($fake->calls)['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Quintanilla', $sent);
        $this->assertStringNotContainsString('ximena.quintanilla@example.com', $sent);
    }

    /**
     * Decision 6, REL-005: in the preview, with the Basic level an identifier the AI received as a placeholder is
     * restored in the arguments of the tool before it runs and in the answer shown to the administrator.
     *
     * @return void
     */
    public function test_preview_restores_identifier_placeholders_in_tool_arguments_and_answer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('anonymization_level', 'basic', 'local_jedomi');
        $named = $this->getDataGenerator()->create_user(['idnumber' => 'ID-778899', 'city' => 'Cartagena']);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
            'arguments' => json_encode(['query' => 'SELECT id, idnumber FROM {user} WHERE id = ' . (int) $named->id])]]]);
        $fake->enqueue_tool_calls([['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'execute_sql',
            'arguments' => json_encode(['query' => "SELECT city FROM {user} WHERE idnumber = '[id_1]'"])]]]);
        $fake->enqueue_text('The user [id_1] lives in the city found.');
        client::set_instance_for_testing($fake);

        $result = preview_runner::run('Where does that user live?', (int) get_admin()->id);

        $this->assertTrue($result['ok']);
        $this->assertCount(3, $fake->calls);
        $tools = array_values(array_filter($fake->calls[2]['messages'], static fn(array $m): bool => $m['role'] === 'tool'));
        $this->assertStringNotContainsString('ID-778899', (string) $tools[0]['content']);
        $this->assertStringContainsString('[id_1]', (string) $tools[0]['content']);
        $this->assertStringContainsString('Cartagena', (string) $tools[1]['content'], 'The query ran with the real idnumber');
        $this->assertSame('The user ID-778899 lives in the city found.', $result['answer']);
    }

    /**
     * RISK-001: a query with a placeholder the AI wrote in a form that cannot be restored ("[ID 1]") is not run: the AI
     * receives an error telling it to write the exact placeholder, never the result of the query run against the token.
     *
     * @return void
     */
    public function test_preview_does_not_run_a_query_with_a_placeholder_that_cannot_be_restored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('anonymization_level', 'basic', 'local_jedomi');
        $named = $this->getDataGenerator()->create_user(['idnumber' => 'ID-778899', 'city' => 'Cartagena']);
        $this->getDataGenerator()->create_user(['idnumber' => 'ID-OTHER', 'city' => 'Lima']);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
            'arguments' => json_encode(['query' => 'SELECT id, idnumber FROM {user} WHERE id = ' . (int) $named->id])]]]);
        $fake->enqueue_tool_calls([['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'execute_sql',
            'arguments' => json_encode(['query' => "SELECT city FROM {user} WHERE idnumber <> '[ID 1]'"])]]]);
        $fake->enqueue_text('Done.');
        client::set_instance_for_testing($fake);

        $result = preview_runner::run('Where do the other users live?', (int) get_admin()->id);

        $this->assertTrue($result['ok']);
        $tools = array_values(array_filter($fake->calls[2]['messages'], static fn(array $m): bool => $m['role'] === 'tool'));
        $this->assertStringContainsString('unrestored_placeholder', (string) $tools[1]['content']);
        $this->assertStringContainsString('[kind_N]', (string) $tools[1]['content']);
        $this->assertStringNotContainsString('Lima', (string) $tools[1]['content'], 'The query was not run');
        $this->assertStringNotContainsString('Cartagena', (string) $tools[1]['content'], 'The query was not run');
        $this->assertStringContainsString('unrestored_placeholder', $result['profiler'][1]['result_preview']);
    }

    /**
     * MDL-INT-100: the error messages of the preview are shown in the language of the user.
     *
     * @return void
     */
    public function test_preview_errors_are_in_the_user_language(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/jedomi/classes/agent/preview_runner.php');
        $fixed = ['No response from AI', 'encountered an issue', 'Same tool with same arguments', 'does not run actions'];
        foreach ($fixed as $text) {
            $this->assertStringNotContainsString($text, $source, "'{$text}' is no longer fixed in English");
        }
        $manager = get_string_manager();
        $keys = ['preview_error_no_answer', 'preview_error_fetch_failed', 'preview_detail_loop', 'preview_detail_confirmation'];
        foreach ($keys as $key) {
            $this->assertStringContainsString($key, $source);
            $this->assertNotSame(
                $manager->get_string($key, 'local_jedomi', 3, 'en'),
                self::plugin_spanish_string($key, 3),
                "{$key} is translated"
            );
        }
    }

    /**
     * Agent preview bug: a preview never runs a web service write called through call_webservice with "function".
     */
    public function test_preview_does_not_run_a_call_webservice_write(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('ws_allowlist_admin', json_encode(['core_course_create_categories']), 'local_jedomi');
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'call_webservice',
                'arguments' => json_encode(['function' => 'core_course_create_categories',
                    'params' => ['categories' => [['name' => 'Preview category', 'parent' => 0]]]])]],
        ]);
        $fake->enqueue_text('Created.');
        client::set_instance_for_testing($fake);

        $result = preview_runner::run('Create a category', (int) get_admin()->id);

        $this->assertFalse($DB->record_exists('course_categories', ['name' => 'Preview category']));
        $this->assertSame('confirmation_required', $result['profiler'][0]['error'] ?? null);
    }
}
