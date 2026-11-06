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
     * I6: with the provider disabled the web chat gets an error event with the provider's message.
     */
    public function test_run_with_the_provider_disabled_sends_the_provider_message(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
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
     * M4: the model sent by the browser is ignored; the configured model is used.
     */
    public function test_run_from_request_ignores_browser_model(): void {
        $this->require_provider();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
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
     * A1: a writing tool is not run: the action is held, the card event is sent and the turn ends.
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
     * A1: a reading tool runs as before and nothing is held.
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
}
