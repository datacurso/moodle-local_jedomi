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
     * I6: with the provider disabled the preview shows the provider's message and nothing is sent.
     */
    public function test_preview_with_the_provider_disabled_shows_the_provider_message(): void {
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
}
