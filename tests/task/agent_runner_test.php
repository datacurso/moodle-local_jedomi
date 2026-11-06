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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

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
     * M5: the agent calls the AI client on behalf of its creator, not of the session user.
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
     * I6: with the provider disabled the run fails with the provider's message and nothing is sent.
     */
    public function test_agent_with_the_provider_disabled_fails_with_the_provider_message(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
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
}
