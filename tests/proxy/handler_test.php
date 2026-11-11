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

namespace local_jedomi\proxy;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\ai\fake_curl;
use local_jedomi\ai\provider_api;
use local_jedomi\ai\testable_provider_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for the web chat proxy handler over the AI client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\proxy\handler
 */
final class handler_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Skip the test when aiprovider_datacurso is missing: the code under test asks it whether Jedomi is enabled.
     */
    private function require_provider(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the plugin state and its client are not available.');
        }
    }

    /**
     * Run a callable and return everything it echoed, including flushed output.
     *
     * @param callable $fn Code to run.
     * @return string
     */
    private function capture(callable $fn): string {
        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;
            return '';
        });
        try {
            $fn();
        } finally {
            ob_end_flush();
        }
        return $captured;
    }

    /**
     * Decode the SSE data lines of a captured stream.
     *
     * @param string $output Captured output.
     * @return array<int, array|string> Decoded payloads; "[DONE]" is kept as a string.
     */
    private function events(string $output): array {
        $events = [];
        foreach (explode("\n", $output) as $line) {
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }
            $raw = substr($line, 6);
            $events[] = $raw === '[DONE]' ? $raw : json_decode($raw, true);
        }
        return $events;
    }

    /**
     * M3: the browser gets the tool events, the answer in SSE chunks and [DONE].
     * MDL-CTR-003: the server emits, in order, the tool in use, the text fragments and the end of the answer.
     * MDL-CTR-004: the tool results are sent to the AI as tool messages.
     */
    public function test_run_streams_tool_events_text_chunks_and_done(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'no_such_tool', 'arguments' => '{"a":1}']],
        ]);
        $answer = 'This is the final answer, long enough to be split into several chunks.';
        $fake->enqueue_text($answer);
        client::set_instance_for_testing($fake);

        $output = $this->capture(fn() => handler::run(
            [['role' => 'user', 'content' => 'hi']],
            'admin',
            (int) get_admin()->id
        ));

        $events = $this->events($output);
        $this->assertSame(['tool' => 'no_such_tool', 'args' => ['a' => 1]], $events[0]['_jedomi_tool_executing']);
        $this->assertSame('no_such_tool', $events[1]['_jedomi_tool_result']['tool']);
        $chunks = array_filter($events, static fn($e) => is_array($e) && isset($e['choices']));
        $this->assertGreaterThan(1, count($chunks));
        $text = implode('', array_map(static fn($e) => $e['choices'][0]['delta']['content'], $chunks));
        $this->assertSame($answer, $text);
        $this->assertSame('[DONE]', end($events));

        // The second call carries the assistant tool call followed by its tool result.
        $this->assertCount(2, $fake->calls);
        $second = $fake->calls[1]['messages'];
        $this->assertSame('assistant', $second[1]['role']);
        $this->assertSame('call_1', $second[1]['tool_calls'][0]['id']);
        $this->assertSame('tool', $second[2]['role']);
        $this->assertSame('call_1', $second[2]['tool_call_id']);
        $this->assertSame((int) get_admin()->id, $fake->calls[0]['userid']);
    }

    /**
     * M3: an AI error reaches the browser as an {"error":{code,message}} event with the translated message.
     * MDL-CTR-003: an error is emitted as an event with its code and the translated message ready to show.
     */
    public function test_run_sends_error_event_with_translated_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_exception(new \moodle_exception('notenoughtokens', 'aiprovider_datacurso'));
        client::set_instance_for_testing($fake);

        $output = $this->capture(fn() => handler::run(
            [['role' => 'user', 'content' => 'hi']],
            'admin',
            (int) get_admin()->id
        ));

        $events = $this->events($output);
        $this->assertCount(1, $events);
        $this->assertSame('no_credit', $events[0]['error']['code']);
        $this->assertSame(get_string('ai_error_nocredit', 'local_jedomi'), $events[0]['error']['message']);
    }

    /**
     * MDL-INT-003: with the provider disabled the web chat shows its message and sends nothing.
     * I6: with the provider disabled the web chat gets an error event with the provider's message.
     * MDL-CTR-003: with the provider disabled only the error event with the provider message is emitted.
     */
    public function test_run_with_the_provider_disabled_sends_the_provider_message(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        // A forced setting, not set_config(): changing an existing row of config_plugins makes the reset after
        // the test reload the whole table, which took about ten seconds per test.
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 0);
        $curl = new fake_curl();
        client::set_instance_for_testing(testable_provider_api::make_client($curl));

        $output = $this->capture(fn() => handler::run(
            [['role' => 'user', 'content' => 'hi']],
            'admin',
            (int) get_admin()->id
        ));

        $events = $this->events($output);
        $this->assertCount(1, $events);
        $this->assertSame('provider_disabled', $events[0]['error']['code']);
        $this->assertSame(get_string('providerdisabled', 'aiprovider_datacurso'), $events[0]['error']['message']);
        $this->assertSame([], $curl->calls);
    }

    /**
     * MDL-INT-005: a model sent by the browser is ignored and the configured one is used.
     * M4: the model sent by the browser is ignored; the configured model is used.
     */
    public function test_run_from_request_ignores_browser_model(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        // A forced setting, not set_config(): changing an existing row of config_plugins makes the reset after
        // the test reload the whole table, which took about ten seconds per test.
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        set_config('model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]]]));
        client::set_instance_for_testing(
            new client(static fn(int $timeout): provider_api => new testable_provider_api($timeout, $curl))
        );

        $input = [
            'model' => 'jedomi/expensive/unpriced-model',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'context' => [],
        ];
        $this->capture(fn() => handler::run_from_request($input, 'admin', ['course_id' => 0], (int) get_admin()->id));

        $body = $curl->last_body();
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', $body['model']);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'hi'], $body['messages'][1]);
    }

    /**
     * Names of the tools offered to the model in a recorded AI call.
     *
     * @param array $call Call recorded by the fake AI client.
     * @return string[]
     */
    private function tool_names(array $call): array {
        return array_map(static fn(array $tool): string => $tool['function']['name'], $call['tools']);
    }

    /**
     * Run the web chat loop with one tool call followed by a final answer.
     *
     * @param string $tool Tool the model calls.
     * @param array $args Tool arguments.
     * @param fake_ai_client $fake AI client double.
     * @return array Decoded SSE events.
     */
    private function run_with_tool_call(string $tool, array $args, fake_ai_client $fake): array {
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => json_encode($args)]],
        ]);
        $fake->enqueue_text('Final answer.');
        client::set_instance_for_testing($fake);
        global $USER;
        return $this->events($this->capture(fn() => handler::run(
            [['role' => 'user', 'content' => 'hi']],
            'admin',
            (int) $USER->id
        )));
    }

    /**
     * MDL-INT-045: a writing tool is not run and the answer ends with the confirmation card data.
     * A1: a writing tool is not run: the action is held, the card event is sent and the turn ends.
     * MDL-UNIT-021: an action that changes data does not run without confirmation.
     */
    public function test_writing_tool_is_held_for_confirmation(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $agentid = \local_jedomi\agent\agent_manager::create_agent((object) [
            'userid' => (int) get_admin()->id,
            'prompt' => 'Count the users',
            'schedule_cron' => '0 8 * * *',
        ]);
        $fake = new fake_ai_client();

        $events = $this->run_with_tool_call('delete_agent', ['id' => $agentid], $fake);

        $pendingevents = array_values(array_filter($events, static fn($e) => is_array($e) && isset($e['_jedomi_pending_action'])));
        $this->assertCount(1, $pendingevents);
        $pending = $pendingevents[0]['_jedomi_pending_action'];
        $this->assertSame('delete_agent', $pending['tool']);
        $this->assertSame('critical', $pending['severity']);
        $this->assertSame(get_string('pending_desc_delete_agent', 'local_jedomi', $agentid), $pending['description']);
        $record = $DB->get_record(\local_jedomi\local\service\pending_action_service::TABLE, ['id' => $pending['id']]);
        $this->assertSame((int) get_admin()->id, (int) $record->userid);
        $this->assertNotNull(\local_jedomi\agent\agent_manager::get_agent($agentid));

        $executing = array_filter($events, static fn($e) => is_array($e) && isset($e['_jedomi_tool_executing']));
        $this->assertSame([], $executing);
        $chunks = array_filter($events, static fn($e) => is_array($e) && isset($e['choices']));
        $text = implode('', array_map(static fn($e) => $e['choices'][0]['delta']['content'], $chunks));
        $this->assertSame(get_string('pending_action_needed', 'local_jedomi'), $text);
        $this->assertSame('[DONE]', end($events));
        $this->assertCount(1, $fake->calls);
    }

    /**
     * MDL-INT-045: a reading tool runs without confirmation.
     * MDL-UNIT-021: a reading tool runs without confirmation and nothing is held.
     */
    public function test_reading_tool_runs_without_confirmation(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();

        $events = $this->run_with_tool_call('search_tables', ['query' => 'user'], $fake);

        $this->assertSame('search_tables', $events[0]['_jedomi_tool_executing']['tool']);
        $this->assertSame('search_tables', $events[1]['_jedomi_tool_result']['tool']);
        $this->assertSame(0, $DB->count_records(\local_jedomi\local\service\pending_action_service::TABLE));
        $this->assertCount(2, $fake->calls);
        $this->assertSame('tool', $fake->calls[1]['messages'][2]['role']);
    }

    /**
     * MDL-INT-045: sending messages is offered and held for confirmation.
     * MDL-UNIT-094: sending messages is offered in the web chat to users allowed to send messages.
     * A5: send_messages is offered to a user with moodle/site:sendmessage and goes through confirmation.
     */
    public function test_send_messages_is_offered_and_held(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();

        $events = $this->run_with_tool_call('send_messages', ['user_ids' => [3, 4], 'message' => 'Hello class'], $fake);

        $this->assertContains('send_messages', $this->tool_names($fake->calls[0]));
        $pendingevents = array_values(array_filter($events, static fn($e) => is_array($e) && isset($e['_jedomi_pending_action'])));
        $pending = $pendingevents[0]['_jedomi_pending_action'];
        $this->assertSame('send_messages', $pending['tool']);
        $this->assertSame('medium', $pending['severity']);
        $this->assertSame(2, $pending['count']);
        $this->assertSame('Hello class', $pending['preview']);
    }

    /**
     * MDL-UNIT-094: sending messages is not offered without the permission to send messages.
     * A5: without moodle/site:sendmessage the send_messages tool is not offered.
     */
    public function test_send_messages_is_not_offered_without_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $userroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability('moodle/site:sendmessage', CAP_PROHIBIT, $userroleid, \context_system::instance()->id, true);
        $this->setUser($user);
        $fake = new fake_ai_client();
        $fake->enqueue_text('ok');
        client::set_instance_for_testing($fake);

        $this->capture(fn() => handler::run([['role' => 'user', 'content' => 'hi']], 'student', (int) $user->id));

        $this->assertNotContains('send_messages', $this->tool_names($fake->calls[0]));
    }

    /**
     * Web chat request whose last message has an image part.
     *
     * @return array
     */
    private function request_with_image(): array {
        return [
            'messages' => [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'old question'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
                ]],
                ['role' => 'assistant', 'content' => 'old answer'],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'What is this?'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,BBBB']],
                ]],
            ],
            'context' => [],
        ];
    }

    /**
     * MDL-INT-006: with file processing off the web chat refuses images without calling the AI.
     * M8: with file processing off a message with an image is refused without calling the AI.
     */
    public function test_image_parts_are_refused_when_files_are_disabled(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('files_enabled', 0, 'local_jedomi');
        $fake = new fake_ai_client();
        client::set_instance_for_testing($fake);

        $events = $this->events($this->capture(fn() => handler::run_from_request(
            $this->request_with_image(),
            'admin',
            ['course_id' => 0],
            (int) get_admin()->id
        )));

        $this->assertSame([], $fake->calls);
        $this->assertSame(
            ['code' => 'files_disabled', 'message' => get_string('files_error_disabled', 'local_jedomi')],
            $events[0]['error']
        );
    }

    /**
     * M11: the chat model never receives image parts; their text parts are kept.
     */
    public function test_chat_model_receives_only_text(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_text('A photo.');
        client::set_instance_for_testing($fake);

        $this->capture(fn() => handler::run_from_request(
            $this->request_with_image(),
            'admin',
            ['course_id' => 0],
            (int) get_admin()->id
        ));

        $messages = $fake->calls[0]['messages'];
        $this->assertStringNotContainsString('image_url', json_encode($messages));
        $this->assertSame(['role' => 'user', 'content' => 'old question'], $messages[1]);
        $this->assertSame(['role' => 'user', 'content' => 'What is this?'], end($messages));
    }

    /**
     * Text streamed to the browser in the SSE chunks.
     *
     * @param array $events Decoded SSE events.
     * @return string
     */
    private function streamed_text(array $events): string {
        $chunks = array_filter($events, static fn($e) => is_array($e) && isset($e['choices']));
        return implode('', array_map(static fn($e) => $e['choices'][0]['delta']['content'], $chunks));
    }

    /**
     * MDL-UNIT-079: the web chat also rejects an unknown tool.
     * MDL-UNIT-021: an unknown tool is never run and its result is a controlled error for the model.
     */
    public function test_unknown_tool_gets_a_controlled_error(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();

        $events = $this->run_with_tool_call('drop_every_table', ['really' => true], $fake);

        $result = $fake->calls[1]['messages'][2];
        $this->assertSame('tool', $result['role']);
        $this->assertSame(
            ['error' => get_string('error_unknown_tool', 'local_jedomi', 'drop_every_table')],
            json_decode($result['content'], true)
        );
        $this->assertSame('Final answer.', $this->streamed_text($events));
    }

    /**
     * MDL-UNIT-021: a web service function that does not exist is not run and answers a controlled error.
     */
    public function test_unknown_web_service_function_gets_a_controlled_error(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = json_decode(
            handler::run_tool('ws_execute', ['wsname' => 'core_no_such_function', 'params' => '{}'], 'admin'),
            true
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('core_no_such_function', $result['wsname']);
    }

    /**
     * MDL-UNIT-040: when the AI reaches the maximum number of steps the web chat says the operation could not be completed.
     */
    public function test_too_many_steps_show_the_could_not_complete_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        for ($i = 1; $i <= 60; $i++) {
            $fake->enqueue_tool_calls([
                ['id' => 'call_' . $i, 'type' => 'function',
                    'function' => ['name' => 'no_such_tool', 'arguments' => json_encode(['step' => $i])]],
            ]);
        }
        client::set_instance_for_testing($fake);

        $events = $this->events($this->capture(fn() => handler::run(
            [['role' => 'user', 'content' => 'hi']],
            'admin',
            (int) get_admin()->id
        )));

        $this->assertSame(get_string('error_fallback_iterations', 'local_jedomi'), $this->streamed_text($events));
        $this->assertSame('[DONE]', end($events));
        $this->assertLessThan(60, count($fake->calls), 'The chat must stop before using every queued step');
    }

    /**
     * MDL-UNIT-046: the web chat instructions include the context of the page the user is on.
     * MDL-CTR-004: the instructions carry the context of the page.
     */
    public function test_web_chat_instructions_include_the_page_context(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        $fake = new fake_ai_client();
        $fake->enqueue_text('ok');
        client::set_instance_for_testing($fake);
        $input = [
            'messages' => [['role' => 'user', 'content' => 'How is this course going?']],
            'context' => ['location' => 'course', 'course_name' => 'Algebra Quantum 101', 'page_title' => 'Algebra page'],
        ];

        $this->capture(fn() => handler::run_from_request($input, 'admin', ['course_id' => 77], (int) get_admin()->id));

        $system = $fake->calls[0]['messages'][0];
        $this->assertSame('system', $system['role']);
        $this->assertStringContainsString('Algebra Quantum 101', $system['content']);
        $this->assertStringContainsString('when the user explicitly asks for that action', $system['content']);
    }

    /**
     * Run a web chat request as the admin and return the system message the AI received.
     *
     * @param array $input Extra request fields.
     * @return string
     */
    private function system_message_for(array $input): string {
        $fake = new fake_ai_client();
        $fake->enqueue_text('ok');
        client::set_instance_for_testing($fake);
        $input += ['messages' => [['role' => 'user', 'content' => 'What is due this week?']], 'context' => []];
        $this->capture(fn() => handler::run_from_request($input, 'admin', ['course_id' => 0], (int) get_admin()->id));
        return $fake->calls[0]['messages'][0]['content'];
    }

    /**
     * V8: a request of the voice conversation adds the spoken answer instructions, only while the voice conversation is
     * enabled; the answer streamed to the chat is the full text.
     */
    public function test_voice_mode_adds_the_spoken_answer_instructions(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('audio_enabled', 1, 'local_jedomi');
        set_config('voice_enabled', 1, 'local_jedomi');

        $this->assertStringContainsString('VOICE CONVERSATION:', $this->system_message_for(['voice_mode' => true]));
        $this->assertStringNotContainsString('VOICE CONVERSATION:', $this->system_message_for([]));
        $this->assertStringNotContainsString('VOICE CONVERSATION:', $this->system_message_for(['voice_mode' => false]));

        set_config('voice_enabled', 0, 'local_jedomi');
        $this->assertStringNotContainsString(
            'VOICE CONVERSATION:',
            $this->system_message_for(['voice_mode' => true]),
            'The browser cannot turn on the voice mode while the voice conversation is disabled'
        );
    }

    /**
     * MDL-CTR-004: each web chat query sends instructions with the site name and address, the Moodle version, the
     * database type, the role, the page title and address and the location, followed by the last messages with the text
     * of the attachments.
     */
    public function test_web_chat_query_sends_the_defined_context(): void {
        global $CFG;
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sitename', 'Quantum Academy');
        $fake = new fake_ai_client();
        $fake->enqueue_text('ok');
        client::set_instance_for_testing($fake);
        $history = [
            ['role' => 'user', 'content' => 'What is due this week?'],
            ['role' => 'assistant', 'content' => 'The essay is due on Friday.'],
            ['role' => 'user', 'content' => "Summarise this file\n\n[Attached file: notes.txt]\nPhotosynthesis needs light."],
        ];
        $input = [
            'messages' => $history,
            'context' => ['location' => 'grades', 'page_title' => 'Grader report',
                'page_url' => 'https://example.com/grade/report/grader/index.php?id=5'],
        ];

        $this->capture(fn() => handler::run_from_request($input, 'teacher', ['course_id' => 5], (int) get_admin()->id));

        $messages = $fake->calls[0]['messages'];
        $system = $messages[0]['content'];
        $this->assertSame('system', $messages[0]['role']);
        foreach (
            ['Quantum Academy', $CFG->wwwroot, $CFG->release, 'teacher', 'Grader report',
                'https://example.com/grade/report/grader/index.php?id=5', 'grades'] as $expected
        ) {
            $this->assertStringContainsString($expected, $system);
        }
        $this->assertMatchesRegularExpression('/Database: \S+/', $system);
        $this->assertSame($history, array_slice($messages, 1));
    }

    /**
     * MDL-INT-092: the chat permissions the server issues to a guest session are never accepted by the chat.
     */
    public function test_permissions_issued_to_a_guest_are_not_accepted(): void {
        $this->resetAfterTest();
        $this->setGuestUser();

        $accepted = true;
        try {
            spt_validator::validate(\local_jedomi_generate_spt(\local_jedomi_get_user_role()));
        } catch (\moodle_exception $e) {
            $accepted = false;
        }

        $this->assertFalse($accepted, 'A guest must not get usable chat permissions');
    }

    /**
     * MDL-INT-092: a chat query of a guest is refused with a controlled error and never reaches the AI.
     */
    public function test_chat_query_of_a_guest_never_reaches_the_ai(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setGuestUser();
        $fake = new fake_ai_client();
        $fake->enqueue_text('Hello visitor.');
        client::set_instance_for_testing($fake);
        $input = ['messages' => [['role' => 'user', 'content' => 'Show me the courses']], 'context' => []];

        $events = $this->events($this->capture(fn() => handler::run_from_request(
            $input,
            \local_jedomi_get_user_role(),
            [],
            (int) guest_user()->id
        )));

        $this->assertSame([], $fake->calls, 'The AI is never called for a guest');
        $this->assertArrayHasKey('error', $events[0] ?? [], 'The guest gets a controlled error');
    }

    /**
     * Sign some permissions with the secret of the site, as if the signature had been obtained by the browser.
     *
     * @param array $spt Permissions.
     * @return array Permissions with a signature the server accepts.
     */
    private function sign_with_site_secret(array $spt): array {
        unset($spt['signature']);
        ksort($spt);
        $spt['signature'] = hash_hmac('sha256', \local_jedomi_spt_signing_string($spt), \local_jedomi_get_spt_secret());
        return $spt;
    }

    /**
     * Run a chat request as the chat endpoint does: the role comes from validating the presented permissions.
     *
     * @param array $spt Permissions presented by the browser.
     * @param fake_ai_client $fake AI client double.
     * @param string $text Question of the user.
     * @return array Decoded SSE events.
     */
    private function chat_request(array $spt, fake_ai_client $fake, string $text = 'hi'): array {
        global $USER;
        client::set_instance_for_testing($fake);
        $role = spt_validator::validate($spt);
        return $this->events($this->capture(fn() => handler::run_from_request(
            ['messages' => [['role' => 'user', 'content' => $text]], 'context' => []],
            $role,
            $spt,
            (int) $USER->id
        )));
    }

    /**
     * Error events of a chat answer.
     *
     * @param array $events Decoded SSE events.
     * @return array[]
     */
    private function error_events(array $events): array {
        return array_values(array_filter($events, static fn($e) => is_array($e) && isset($e['error'])));
    }

    /**
     * MDL-INT-007: the role of a chat query is the role of the session user, whatever the browser presents.
     * Even with validly signed permissions that declare the administrator role, a student only gets the student tools.
     */
    public function test_chat_role_is_computed_from_the_session_user(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $presented = $this->sign_with_site_secret(array_merge(\local_jedomi_generate_spt('student'), ['role' => 'admin']));
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'admin_search', 'arguments' => '{"query":"cache"}']],
        ]);
        $fake->enqueue_text('Done.');

        $this->chat_request($presented, $fake);

        $offered = $this->tool_names($fake->calls[0]);
        $studenttools = array_column(array_column(\local_jedomi_get_tool_definitions('student'), 'function'), 'name');
        foreach (['execute_sql', 'admin_search', 'admin_execute', 'get_site_config'] as $tool) {
            $this->assertNotContains($tool, $offered, "A student must never be offered {$tool}");
        }
        $this->assertEqualsCanonicalizing($studenttools, array_values(array_diff($offered, ['send_messages'])));
        if (isset($fake->calls[1])) {
            $result = json_decode($fake->calls[1]['messages'][array_key_last($fake->calls[1]['messages'])]['content'], true);
            $this->assertSame('permission_denied', $result['error'] ?? null, 'The administration tool must be denied to a student');
        }
    }

    /**
     * MDL-INT-009: a chat query sent from a course where Jedomi is not available is rejected by the server.
     */
    public function test_chat_query_from_an_excluded_course_is_rejected(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        set_config('course_mode', 'exclude', 'local_jedomi');
        set_config('course_list', (string) $course->id, 'local_jedomi');
        $this->setUser($student);
        $fake = new fake_ai_client();
        $fake->enqueue_text('An answer that must never be produced.');
        client::set_instance_for_testing($fake);

        $events = $this->events($this->capture(fn() => handler::run_from_request(
            ['messages' => [['role' => 'user', 'content' => 'Summarise this course']], 'context' => []],
            'student',
            ['course_id' => (int) $course->id],
            (int) $student->id
        )));

        $this->assertSame([], $fake->calls, 'A query from an excluded course must not reach the AI');
        $errors = $this->error_events($events);
        $this->assertCount(1, $errors, 'The user must get a clear rejection');
        $this->assertNotSame('', trim((string) ($errors[0]['error']['message'] ?? '')));
    }

    /**
     * MDL-INT-010: the server rejects the chat queries of a user whose roles are all hidden.
     */
    public function test_chat_query_of_a_user_with_every_role_hidden_is_rejected(): void {
        global $DB;
        $this->require_provider();
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'coursecreator']);
        role_assign($roleid, $user->id, \context_system::instance());
        set_config('hidden_roles', (string) $roleid, 'local_jedomi');
        $this->setUser($user);
        $this->assertFalse(\local_jedomi_is_role_allowed(), 'Precondition: the panel is hidden for this user');
        $fake = new fake_ai_client();
        $fake->enqueue_text('An answer that must never be produced.');
        client::set_instance_for_testing($fake);

        $events = $this->events($this->capture(fn() => handler::run_from_request(
            ['messages' => [['role' => 'user', 'content' => 'hi']], 'context' => []],
            'student',
            ['course_id' => 0],
            (int) $user->id
        )));

        $this->assertSame([], $fake->calls, 'A user with every role hidden must not reach the AI');
        $this->assertCount(1, $this->error_events($events));
    }

    /**
     * Allow the Jedomi service capability to authenticated users, as a site that grants it to its users would do.
     */
    private function grant_service_capability(): void {
        global $DB;
        $userroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability('local/jedomi:companion_api', CAP_ALLOW, $userroleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * MDL-INT-017: without a saved list the administrator can run every function, and any other role none, with the AI told
     * that no services are configured for the role.
     */
    public function test_without_a_saved_list_only_the_administrator_runs_web_services(): void {
        $this->require_provider();
        $this->resetAfterTest();
        unset_config('ws_allowlist_admin', 'local_jedomi');
        unset_config('ws_allowlist_student', 'local_jedomi');
        $this->grant_service_capability();

        $this->setAdminUser();
        $admin = json_decode(handler::run_tool(
            'ws_execute',
            ['wsname' => 'core_webservice_get_site_info', 'params' => '{}'],
            'admin'
        ), true);
        $this->assertArrayHasKey('result', $admin, 'The administrator has every function without a saved list');

        $this->setUser($this->getDataGenerator()->create_user());
        $student = json_decode(handler::run_tool(
            'ws_execute',
            ['wsname' => 'core_webservice_get_site_info', 'params' => '{}'],
            'student'
        ), true);
        $this->assertArrayHasKey('error', $student);
        $this->assertArrayNotHasKey('result', $student);
        $search = json_decode(handler::run_tool('ws_search', ['query' => 'site info'], 'student'), true);
        $this->assertSame([], $search['results']);
        $this->assertSame(get_string('note_ws_none_configured', 'local_jedomi'), $search['note']);
    }

    /**
     * MDL-INT-017: in the web chat a function outside the role list is rejected, and a listed one runs with the real
     * Moodle permissions of the user, failing when the user lacks the capability it needs.
     */
    public function test_web_chat_applies_the_role_list_and_the_real_permissions(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->grant_service_capability();
        $this->getDataGenerator()->create_category(['name' => 'Hidden from the list']);
        \local_jedomi\local\service\admin_settings_service::save_ws_allowlist(
            'student',
            ['core_webservice_get_site_info', 'core_user_create_users']
        );
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $listed = json_decode(handler::run_tool(
            'ws_execute',
            ['wsname' => 'core_webservice_get_site_info', 'params' => '{}'],
            'student'
        ), true);
        $this->assertArrayHasKey('result', $listed);
        $this->assertSame((int) $student->id, (int) json_decode($listed['result'], true)['userid']);

        $unlisted = json_decode(handler::run_tool(
            'ws_execute',
            ['wsname' => 'core_course_get_categories', 'params' => '{}'],
            'student'
        ), true);
        $this->assertArrayHasKey('error', $unlisted);
        $this->assertStringContainsString(get_string('ws_not_in_allowlist', 'local_jedomi'), $unlisted['error']);

        $nocapability = json_decode(handler::run_tool('ws_execute', ['wsname' => 'core_user_create_users',
            'params' => json_encode(['users' => [['username' => 'intruder', 'password' => 'Intruder-1', 'firstname' => 'I',
                'lastname' => 'N', 'email' => 'intruder@example.com']]])], 'student'), true);
        $this->assertArrayHasKey('error', $nocapability);
        $this->assertFalse(\core_user::get_user_by_username('intruder'), 'A listed function still needs the real capability');
    }

    /**
     * Sensitive queries the AI may try, with the secret each one would reveal.
     *
     * @return array<string, array{0: string, 1: string}> Query and the secret marker set up for it.
     */
    private function sensitive_queries(): array {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['username' => 'victim', 'password' => 'Victim-Pass-1']);
        $hash = (string) $DB->get_field('user', 'password', ['id' => $user->id]);
        set_config('jedomi_test_secret', 'SiteSecretMarker42');
        set_config('licensekey', 'LicenseMarker42', 'aiprovider_datacurso');
        $DB->insert_record('external_tokens', (object) ['token' => 'TokenMarker42abcdef0123456789abcd', 'tokentype' => 0,
            'userid' => $user->id, 'externalserviceid' => 1, 'contextid' => 1, 'creatorid' => $user->id,
            'timecreated' => time(), 'privatetoken' => null, 'name' => 'test']);
        return [
            'password hashes' => ["SELECT username, password FROM {user} WHERE id = {$user->id}", $hash],
            'web service tokens' => ['SELECT token FROM {external_tokens}', 'TokenMarker42'],
            'plugin keys' => ["SELECT value FROM {config_plugins} WHERE name = 'licensekey'", 'LicenseMarker42'],
            'site configuration' => ["SELECT value FROM {config} WHERE name = 'jedomi_test_secret'", 'SiteSecretMarker42'],
        ];
    }

    /**
     * MDL-INT-042: in the web chat the database query never reveals credentials, keys or configuration.
     */
    public function test_web_chat_database_query_excludes_sensitive_data(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();

        foreach ($this->sensitive_queries() as $label => [$sql, $secret]) {
            $result = handler::run_tool('execute_sql', ['sql' => $sql], 'admin');
            $this->assertStringNotContainsString($secret, $result, "The web chat must reject the query on {$label}");
        }
    }

    /**
     * MDL-INT-039: the chat knows the real role, course and activity of the page.
     * [Pendiente:skip]
     */
    public function test_chat_uses_the_real_role_course_and_activity_of_the_page(): void {
        $this->markTestSkipped('Pending fix: the role and the context are computed in a request without course, so teachers '
            . 'get the student role and the AI does not receive the course or the activity.');
    }

    /**
     * An editing teacher of one course, who does not hold the companion API permission, set as the session user.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Teacher, own course and another course.
     */
    private function editing_teacher(): array {
        $this->resetAfterTest();
        $own = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Foreign Course Marker']);
        $teacher = $this->getDataGenerator()->create_and_enrol($own, 'editingteacher');
        $this->getDataGenerator()->create_and_enrol($own, 'student', ['firstname' => 'Participant', 'lastname' => 'Marker']);
        $this->setUser($teacher);
        $this->assertFalse(has_capability('local/jedomi:companion_api', \context_system::instance()));
        return [$teacher, $own, $other];
    }

    /**
     * MDL-INT-041 / MDL-INT-042: like in Telegram, an editing teacher (not a manager, without the companion API
     * permission) queries the data of their own course from the web chat, and is refused for another course and for
     * private data of the site.
     */
    public function test_teacher_queries_their_own_course_from_the_web_chat(): void {
        [, $own, $other] = $this->editing_teacher();
        $query = static fn(string $sql): array => json_decode(handler::run_tool('execute_sql', ['sql' => $sql], 'teacher'), true);

        $owncourse = $query("SELECT id, fullname FROM {course} WHERE id = {$own->id}");
        $this->assertSame(1, $owncourse['row_count'] ?? null, json_encode($owncourse));

        $names = $query('SELECT u.firstname, u.lastname FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid '
            . 'JOIN {user} u ON u.id = ue.userid WHERE e.courseid = ' . $own->id);
        $this->assertSame(2, $names['row_count'] ?? null, json_encode($names));
        $this->assertStringContainsString('Marker', implode(' ', array_column($names['rows'], 'json_row')));

        $othercourse = $query("SELECT fullname FROM {course} WHERE id = {$other->id}");
        $this->assertStringNotContainsString('Foreign Course Marker', json_encode($othercourse));
        $this->assertStringContainsString(get_string('sql_course_rejected', 'local_jedomi'), $othercourse['error']);

        $messages = $query('SELECT id, fullmessage FROM {messages}');
        $this->assertStringContainsString(get_string('sql_table_not_allowed', 'local_jedomi'), $messages['error']);
    }

    /**
     * MDL-INT-041: a student never runs database queries from the web chat, not even on their own course.
     */
    public function test_student_cannot_query_from_the_web_chat(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $sql = "SELECT id FROM {course} WHERE id = {$course->id}";

        $result = json_decode(handler::run_tool('execute_sql', ['sql' => $sql], 'student'), true);

        $this->assertSame('permission_denied', $result['error'] ?? null);
        $this->assertArrayNotHasKey('rows', $result);
    }

    /**
     * MDL-INT-041 / MDL-INT-017: like in Telegram, a teacher without the companion API permission runs from the web chat
     * the web service functions of the teacher list, and not the others.
     */
    public function test_teacher_runs_the_web_services_of_their_role_list_from_the_web_chat(): void {
        [$teacher] = $this->editing_teacher();
        \local_jedomi\local\service\admin_settings_service::save_ws_allowlist('teacher', ['core_webservice_get_site_info']);

        $listed = json_decode(handler::run_tool(
            'ws_execute',
            ['wsname' => 'core_webservice_get_site_info', 'params' => '{}'],
            'teacher'
        ), true);
        $this->assertArrayHasKey('result', $listed, json_encode($listed));
        $this->assertSame((int) $teacher->id, (int) json_decode($listed['result'], true)['userid']);

        $unlisted = json_decode(handler::run_tool(
            'ws_execute',
            ['wsname' => 'core_course_get_categories', 'params' => '{}'],
            'teacher'
        ), true);
        $this->assertStringContainsString(get_string('ws_not_in_allowlist', 'local_jedomi'), $unlisted['error']);
    }

    /**
     * SYS-E2E-004: a teacher asks to notify the group and, after confirming, the message reaches only the valid
     * recipients. Behat: chat_system.feature.
     * [Pendiente:skip]
     */
    public function test_teacher_can_notify_the_group_of_the_course(): void {
        $this->markTestSkipped('Pending fix: a teacher gets the Student role in the chat, without database or web '
            . 'service tools, so the recipients cannot be found and the sending is never proposed.');
    }

    /**
     * SYS-E2E-007: a teacher uses the assistant with the teacher role and the real context of the course.
     * Behat: chat_system.feature.
     * [Pendiente:skip]
     */
    public function test_teacher_uses_the_assistant_with_the_role_and_context_of_the_course(): void {
        $this->markTestSkipped('Pending fix: teachers always get the Student role, the AI does not know the real course '
            . 'nor activity and the header does not show the course.');
    }

    /**
     * MDL-INT-001: without a valid license in the provider the web chat gets no AI answer and shows the license message.
     */
    public function test_without_a_license_the_chat_shows_the_license_message(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('licensekey', 'aiprovider_datacurso');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        $curl = new fake_curl();
        client::set_instance_for_testing(testable_provider_api::make_client($curl));

        $events = $this->events($this->capture(fn() => handler::run(
            [['role' => 'user', 'content' => 'hi']],
            'admin',
            (int) get_admin()->id
        )));
        $this->resetDebugging();

        $this->assertSame([], $curl->calls, 'Nothing is sent to the AI service without a license');
        $errors = $this->error_events($events);
        $this->assertCount(1, $errors);
        $this->assertSame('license', $errors[0]['error']['code']);
        $this->assertSame(get_string('ai_error_license', 'local_jedomi'), $errors[0]['error']['message']);
    }
    /**
     * MDL-INT-048: when the AI proposes several actions in one answer, each one gets its own confirmation or the user is
     * told about the discarded ones.
     * [Pendiente:skip]
     */
    public function test_every_proposed_action_gets_a_confirmation_or_a_notice(): void {
        $this->markTestSkipped('Pending fix: only the first proposed action is kept; the others are dropped without notice.');
    }

    /**
     * Run a database query proposed by the AI with an anonymisation level and return what the AI received as its result.
     *
     * @param string $level Anonymisation level saved in the Privacy tab.
     * @return array{0: string, 1: \stdClass} Tool result received by the AI and the user it names.
     */
    private function tool_result_with_level(string $level): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        \local_jedomi\local\service\admin_settings_service::save_privacy_settings(false, $level, []);
        $named = $this->getDataGenerator()->create_user(['firstname' => 'Ximena', 'lastname' => 'Quintanilla',
            'email' => 'ximena.quintanilla@example.com', 'idnumber' => 'ID-778899']);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
            'arguments' => json_encode(['sql' => 'SELECT id, firstname, lastname, email, idnumber FROM {user} WHERE id = '
                . (int) $named->id])]]]);
        $fake->enqueue_text('Done.');
        client::set_instance_for_testing($fake);

        $this->capture(fn() => handler::run(
            [['role' => 'user', 'content' => 'Who is that user?']],
            'admin',
            (int) get_admin()->id
        ));

        $this->assertCount(2, $fake->calls);
        $tools = array_values(array_filter($fake->calls[1]['messages'], static fn(array $m): bool => $m['role'] === 'tool'));
        $this->assertNotEmpty($tools);
        return [(string) end($tools)['content'], $named];
    }

    /**
     * MDL-CTR-007: with the Basic level the AI receives names and e-mails returned by the tools replaced.
     */
    public function test_basic_level_replaces_names_and_emails_sent_to_the_ai(): void {
        [$result] = $this->tool_result_with_level('basic');

        $this->assertStringNotContainsString('ximena.quintanilla@example.com', $result);
        $this->assertStringNotContainsString('Quintanilla', $result);
    }

    /**
     * MDL-CTR-007: with the Strict level identifiers are also replaced in what the AI receives.
     */
    public function test_strict_level_also_replaces_identifiers_sent_to_the_ai(): void {
        [$result] = $this->tool_result_with_level('strict');

        $this->assertStringNotContainsString('ximena.quintanilla@example.com', $result);
        $this->assertStringNotContainsString('ID-778899', $result);
    }

    /**
     * MDL-CTR-006: the web chat instructions do not contain the internal Moodle id of the user and the user is
     * identified to the AI service only by the pseudonym of the provider.
     */
    public function test_web_chat_instructions_do_not_contain_the_internal_user_id(): void {
        $this->require_provider();
        global $DB;
        $this->resetAfterTest();
        // A high id, so the number cannot be confused with other numbers of the instructions.
        $DB->insert_record_raw(
            'user',
            ['id' => 800000 + random_int(1, 90000), 'username' => 'filler_' . random_string(10),
            'mnethostid' => 1, 'confirmed' => 1, 'auth' => 'manual', 'deleted' => 1, 'email' => 'filler@example.com'],
            false,
            false,
            true
        );
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $fake = new fake_ai_client();
        $fake->enqueue_text('ok');
        client::set_instance_for_testing($fake);
        $input = ['messages' => [['role' => 'user', 'content' => 'Hello']], 'context' => ['location' => 'dashboard']];

        $this->capture(fn() => handler::run_from_request($input, 'student', ['course_id' => 0], (int) $user->id));

        $this->assertDoesNotMatchRegularExpression('/\b' . (int) $user->id . '\b/', $fake->calls[0]['messages'][0]['content']);
    }

    /**
     * MDL-INT-032: from the web chat a manager cannot create an agent on behalf of an administrator nor delete theirs.
     */
    public function test_chat_cannot_create_or_delete_agents_of_users_with_more_permissions(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $adminid = (int) get_admin()->id;
        $foreign = \local_jedomi\agent\agent_manager::create_agent((object) ['userid' => $adminid, 'prompt' => 'Admin report',
            'schedule_cron' => '0 8 * * *']);
        $this->setUser($manager);

        $created = json_decode(handler::run_tool('create_agent', ['prompt' => 'Spy', 'schedule_cron' => '0 8 * * *',
            'userid' => $adminid], 'manager'), true);
        $deleted = json_decode(handler::run_tool('delete_agent', ['id' => $foreign], 'manager'), true);
        $own = json_decode(
            handler::run_tool('create_agent', ['prompt' => 'Mine', 'schedule_cron' => '0 8 * * *'], 'manager'),
            true
        );

        $this->assertSame('permission_denied', $created['error'] ?? null);
        $this->assertSame(
            1,
            $DB->count_records('local_jedomi_agents', ['userid' => $adminid]),
            'No agent is created on behalf of the administrator'
        );
        $this->assertSame('permission_denied', $deleted['error'] ?? null);
        $this->assertNotNull(\local_jedomi\agent\agent_manager::get_agent($foreign));
        $this->assertTrue($own['success'] ?? false, 'The manager still creates their own agents');
    }
}
