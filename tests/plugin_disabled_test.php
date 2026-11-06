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

namespace local_jedomi;

use aiprovider_datacurso\local\plugin_settings;
use aiprovider_datacurso\local\suite_plugins;
use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\event\observers;
use local_jedomi\proxy\handler;
use local_jedomi\task\agent_runner;
use local_jedomi\task\run_scheduled_task_adhoc;
use local_jedomi\telegram\api_client;
use local_jedomi\telegram\fake_telegram_api_client;
use local_jedomi\telegram\session;
use local_jedomi\telegram\webhook_handler;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for Jedomi when it is disabled in the Datacurso AI provider: no AI call, no notification, no link.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_jedomi_plugin_disabled_error
 * @covers     ::local_jedomi_myprofile_navigation
 * @covers     \local_jedomi\proxy\handler
 * @covers     \local_jedomi\telegram\webhook_handler
 * @covers     \local_jedomi\task\agent_runner
 * @covers     \local_jedomi\task\run_scheduled_task_adhoc
 * @covers     \local_jedomi\event\observers
 */
final class plugin_disabled_test extends \advanced_testcase {
    /** @var string Linked Telegram chat id. */
    private const CHATID = '771000002';

    /** @var fake_ai_client Chat model double. */
    private fake_ai_client $ai;

    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
        $this->telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($this->telegram);
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Link the admin to the test Telegram chat.
     *
     * @param int $userid User to link.
     * @return void
     */
    private function link_chat(int $userid): void {
        global $DB;
        $DB->insert_record('local_jedomi_telegram_links', (object) [
            'userid' => $userid,
            'chatid' => self::CHATID,
            'username' => 'linked',
            'confirmed' => 1,
            'timecreated' => time(),
            'lastproactivesent' => 0,
        ]);
    }

    /**
     * Run a callable and return everything it printed.
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
     * The JSON endpoints get no error while Jedomi is enabled.
     */
    public function test_disabled_error_is_null_when_enabled(): void {
        plugin_settings::set_enabled('local_jedomi', true);

        $this->assertNull(local_jedomi_plugin_disabled_error());
    }

    /**
     * Disabled, the JSON endpoints get the provider message in their {"code", "message"} format.
     */
    public function test_disabled_error_has_the_provider_message(): void {
        plugin_settings::set_enabled('local_jedomi', false);
        $name = suite_plugins::get_display_name('local_jedomi');

        $this->assertSame([
            'code' => 'plugindisabled',
            'message' => get_string('plugindisabled', 'aiprovider_datacurso', $name),
        ], local_jedomi_plugin_disabled_error());
    }

    /**
     * Disabled, the web chat answers the provider message on the stream and does not call the AI.
     */
    public function test_chat_proxy_sends_the_disabled_error_without_calling_the_ai(): void {
        $this->setAdminUser();
        plugin_settings::set_enabled('local_jedomi', false);

        $output = $this->capture(fn() => handler::run_from_request(
            ['messages' => [['role' => 'user', 'content' => 'Hello']]],
            'admin',
            [],
            (int) get_admin()->id
        ));

        $this->assertStringContainsString('data: ' . json_encode(['error' => local_jedomi_plugin_disabled_error()],
            JSON_UNESCAPED_UNICODE), $output);
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * Enabled, the web chat calls the AI.
     */
    public function test_chat_proxy_calls_the_ai_when_enabled(): void {
        $this->setAdminUser();
        plugin_settings::set_enabled('local_jedomi', true);
        $this->ai->enqueue_text('Hi');

        $this->capture(fn() => handler::run_from_request(
            ['messages' => [['role' => 'user', 'content' => 'Hello']]],
            'admin',
            [],
            (int) get_admin()->id
        ));

        $this->assertCount(1, $this->ai->calls);
    }

    /**
     * Disabled, a Telegram message gets the "not enabled" answer and the AI is not called.
     */
    public function test_telegram_message_gets_the_disabled_answer(): void {
        $this->link_chat((int) get_admin()->id);
        plugin_settings::set_enabled('local_jedomi', false);

        webhook_handler::handle(['message' => ['chat' => ['id' => self::CHATID], 'text' => 'How many users?']]);

        $this->assertSame([local_jedomi_plugin_disabled_error()['message']],
            array_column($this->telegram->sent, 'text'));
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * Disabled, an inline button is acknowledged and answered with the "not enabled" message, without running it.
     */
    public function test_telegram_button_gets_the_disabled_answer(): void {
        $this->link_chat((int) get_admin()->id);
        plugin_settings::set_enabled('local_jedomi', false);

        webhook_handler::handle(['callback_query' => [
            'id' => 'cb-1',
            'data' => 'cmd:new',
            'message' => ['message_id' => 10, 'chat' => ['id' => self::CHATID]],
        ]]);

        $this->assertSame(['cb-1'], $this->telegram->answeredcallbacks);
        $this->assertSame([local_jedomi_plugin_disabled_error()['message']],
            array_column($this->telegram->sent, 'text'));
    }

    /**
     * Disabled, a self-ping does not continue the tool loop with the AI.
     */
    public function test_telegram_internal_ping_does_not_call_the_ai(): void {
        $this->link_chat((int) get_admin()->id);
        session::set_context('tool_loop_' . self::CHATID, [
            'messages' => [['role' => 'user', 'content' => 'How many users?']],
            'userid' => (int) get_admin()->id,
        ]);
        plugin_settings::set_enabled('local_jedomi', false);

        webhook_handler::handle_internal_ping(self::CHATID);

        $this->assertSame([], $this->ai->calls);
    }

    /**
     * Disabled, the scheduled agents task skips its work and leaves the agents due.
     */
    public function test_agent_runner_skips_when_disabled(): void {
        global $DB;
        $creator = $this->getDataGenerator()->create_user();
        $agentid = \local_jedomi\agent\agent_manager::create_agent((object) [
            'userid' => (int) $creator->id,
            'name' => 'Daily report',
            'prompt' => 'Count the users',
            'schedule_cron' => '0 8 * * *',
        ]);
        $DB->set_field('local_jedomi_agents', 'nextrun', time() - 60, ['id' => $agentid]);
        $before = $DB->get_record('local_jedomi_agents', ['id' => $agentid]);
        plugin_settings::set_enabled('local_jedomi', false);

        $output = $this->capture(fn() => (new agent_runner())->execute());

        $this->assertStringContainsString(get_string('task_skipped_plugin_disabled', 'local_jedomi'), $output);
        $this->assertSame([], $this->ai->calls);
        $this->assertEquals($before, $DB->get_record('local_jedomi_agents', ['id' => $agentid]));
    }

    /**
     * Disabled, the adhoc task does not run the task it was queued for.
     */
    public function test_adhoc_task_skips_when_disabled(): void {
        plugin_settings::set_enabled('local_jedomi', false);
        $task = new run_scheduled_task_adhoc();
        $task->set_custom_data(['classname' => '\local_jedomi\task\no_such_task']);

        $output = $this->capture(fn() => $task->execute());

        $this->assertStringContainsString(get_string('task_skipped_plugin_disabled', 'local_jedomi'), $output);
        $this->assertStringNotContainsString('class not found', $output);
    }

    /**
     * Enabled, the adhoc task looks the queued task up.
     */
    public function test_adhoc_task_runs_when_enabled(): void {
        plugin_settings::set_enabled('local_jedomi', true);
        $task = new run_scheduled_task_adhoc();
        $task->set_custom_data(['classname' => '\local_jedomi\task\no_such_task']);

        $output = $this->capture(fn() => $task->execute());

        $this->assertStringNotContainsString(get_string('task_skipped_plugin_disabled', 'local_jedomi'), $output);
        $this->assertStringContainsString('class not found', $output);
    }

    /**
     * Log the admin in through the observer with a failing scheduled task.
     *
     * @return string[] Texts sent to Telegram.
     */
    private function admin_logs_in_with_a_failing_task(): array {
        global $DB;
        set_config('telegram_enabled', 1, 'local_jedomi');
        $admin = get_admin();
        $this->link_chat((int) $admin->id);
        $DB->set_field('task_scheduled', 'faildelay', 60, ['classname' => '\core\task\cache_cron_task']);
        $event = \core\event\user_loggedin::create([
            'userid' => $admin->id,
            'objectid' => $admin->id,
            'other' => ['username' => $admin->username],
        ]);

        observers::user_loggedin($event);

        return array_column($this->telegram->sent, 'text');
    }

    /**
     * Disabled, the cron health alert is not sent on admin login.
     */
    public function test_login_alert_is_not_sent_when_disabled(): void {
        plugin_settings::set_enabled('local_jedomi', false);

        $this->assertSame([], $this->admin_logs_in_with_a_failing_task());
    }

    /**
     * Enabled, the cron health alert is sent on admin login.
     */
    public function test_login_alert_is_sent_when_enabled(): void {
        plugin_settings::set_enabled('local_jedomi', true);

        $this->assertCount(1, $this->admin_logs_in_with_a_failing_task());
    }

    /**
     * Grade a linked student's submission through the observer.
     *
     * @return string[] Texts sent to Telegram.
     */
    private function student_is_graded(): array {
        set_config('telegram_enabled', 1, 'local_jedomi');
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->link_chat((int) $student->id);
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $context = \context_module::instance($instance->cmid);
        $assign = new \assign($context, get_coursemodule_from_id('assign', $instance->cmid), $course);
        $grade = $assign->get_user_grade((int) $student->id, true);
        $event = \mod_assign\event\submission_graded::create_from_grade($assign, $grade);

        observers::submission_graded($event);

        return array_column($this->telegram->sent, 'text');
    }

    /**
     * Disabled, a graded student is not notified.
     */
    public function test_grade_notification_is_not_sent_when_disabled(): void {
        plugin_settings::set_enabled('local_jedomi', false);

        $this->assertSame([], $this->student_is_graded());
    }

    /**
     * Enabled, a graded student is notified.
     */
    public function test_grade_notification_is_sent_when_enabled(): void {
        plugin_settings::set_enabled('local_jedomi', true);

        $this->assertCount(1, $this->student_is_graded());
    }

    /**
     * Build the profile navigation of the current user and tell whether the Telegram link is in it.
     *
     * @return bool
     */
    private function profile_has_telegram_link(): bool {
        global $USER;
        set_config('telegram_enabled', 1, 'local_jedomi');
        set_config('telegram_bot_verified', 1, 'local_jedomi');
        $tree = new \core_user\output\myprofile\tree();

        local_jedomi_myprofile_navigation($tree, $USER, true, null);

        return array_key_exists('telegram', $tree->nodes);
    }

    /**
     * Disabled, the profile has no Telegram link.
     */
    public function test_profile_link_is_absent_when_disabled(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        plugin_settings::set_enabled('local_jedomi', false);

        $this->assertFalse($this->profile_has_telegram_link());
    }

    /**
     * Enabled, the profile has the Telegram link.
     */
    public function test_profile_link_is_present_when_enabled(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        plugin_settings::set_enabled('local_jedomi', true);

        $this->assertTrue($this->profile_has_telegram_link());
    }
}
