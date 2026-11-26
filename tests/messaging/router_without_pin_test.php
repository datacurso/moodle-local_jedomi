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

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\provider_absence_probe;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/neutral_channel.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');

/**
 * Tests for the router with a channel that is not Telegram and cannot ask for a PIN (neutral_channel): the writes of
 * an administrator who needs a PIN are refused, no text is read as a PIN, and the confirmation word runs nothing.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\router
 * @covers     \local_jedomi\messaging\no_pin_policy
 */
final class router_without_pin_test extends \advanced_testcase {
    /** @var neutral_channel Channel of the tests. */
    private neutral_channel $channel;

    /** @var fake_ai_client AI double. */
    private fake_ai_client $ai;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Jedomi is enabled through the plugin state seam: the router never asks the Datacurso AI provider, which the
        // CI does not install.
        provider_absence_probe::start();
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
        $this->channel = new neutral_channel();
    }

    #[\Override]
    protected function assertPostConditions(): void {
        parent::assertPostConditions();
        $this->assertSame([], provider_absence_probe::touched(), 'The test reached the Datacurso AI provider.');
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        neutral_channel::drop_tables();
        parent::tearDown();
    }

    /**
     * A typed message of a private chat of the channel.
     *
     * @param string $chatkey Chat.
     * @param string $text Text.
     * @return inbound_message
     */
    private function message(string $chatkey, string $text): inbound_message {
        return new inbound_message(neutral_channel::NAME, $chatkey, '5', $text, null, null, '', true, 'someone', '5');
    }

    /**
     * Create an agent owned by a new user.
     *
     * @return int Agent id.
     */
    private function agent(): int {
        $owner = $this->getDataGenerator()->create_user();
        return \local_jedomi\agent\agent_manager::create_agent((object) ['userid' => $owner->id, 'name' => 'Report',
            'prompt' => 'List the students at risk', 'schedule_cron' => '0 8 * * 1', 'context_type' => 'site']);
    }

    /**
     * Run the router restoring the PHP time limit the loop raises.
     *
     * @param inbound_message $message Message.
     */
    private function route(inbound_message $message): void {
        $limit = (int) ini_get('max_execution_time');
        (new router($this->channel))->route($message);
        set_time_limit($limit);
    }

    /**
     * The confirmation word as the user types it.
     *
     * @return string
     */
    private function keyword(): string {
        return \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi'));
    }

    /**
     * A write of an administrator who needs a PIN is refused when the AI asks for it: nothing is held nor shown, the AI
     * is told to send the user to the web chat, and the confirmation word typed afterwards runs nothing.
     */
    public function test_write_of_a_user_who_needs_a_pin_is_refused_and_the_word_runs_nothing(): void {
        global $DB;
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $adminid = (int) get_admin()->id;
        $this->channel->links['nc001'] = $adminid;
        $agentid = $this->agent();
        $this->ai->enqueue_tool_calls([['id' => 'call1', 'type' => 'function',
            'function' => ['name' => 'delete_agent', 'arguments' => json_encode(['id' => $agentid])]]]);
        $this->ai->enqueue_text('Use the web chat for that.');

        $this->route($this->message('nc001', 'Delete the agent'));

        $toolmessages = array_filter($this->ai->calls[1]['messages'], static fn(array $m): bool => $m['role'] === 'tool');
        $this->assertStringContainsString('pin_unsupported', (string) reset($toolmessages)['content']);
        $this->assertSame([], $this->channel->ui->shown);
        $this->assertSame([], $this->channel->ui->notified);
        $this->assertSame(0, $DB->count_records(neutral_channel::PENDING_TABLE));

        $this->ai->enqueue_text('Nothing to confirm.');
        $this->route($this->message('nc001', $this->keyword()));

        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]));
        $this->assertSame($this->keyword(), end($this->ai->calls[2]['messages'])['content']);
        $answers = array_filter($this->channel->transport->sent, static fn(array $sent): bool => $sent['kind'] === 'answer');
        $this->assertSame(['Use the web chat for that.', 'Nothing to confirm.'], array_column($answers, 'text'));
    }

    /**
     * An action held before the user needed a PIN is never run: a number is not read as a PIN (it reaches the AI as a
     * normal message), and the confirmation word gets the prompt of the channel and leaves the action waiting.
     */
    public function test_no_text_is_a_pin_and_the_word_does_not_run_a_held_write(): void {
        global $DB;
        $adminid = (int) get_admin()->id;
        $this->channel->links['nc002'] = $adminid;
        $agentid = $this->agent();
        set_config('telegram_require_pin', 0, 'local_jedomi');
        $this->channel->confirmation_service()->hold('nc002', $adminid, 'delete_agent', ['id' => $agentid]);
        $actionid = $this->channel->ui->shown[0][1]->actionid;
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $this->ai->enqueue_text('Which number?');

        $this->route($this->message('nc002', '246810'));
        $this->route($this->message('nc002', $this->keyword()));

        $this->assertCount(1, $this->ai->calls);
        $this->assertSame('246810', end($this->ai->calls[0]['messages'])['content']);
        $this->assertSame(['Which number?', 'pin prompt'], $this->channel->transport->texts());
        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]));
        $this->assertSame($actionid, $this->channel->confirmation_service()->waiting('nc002'));
    }
}
