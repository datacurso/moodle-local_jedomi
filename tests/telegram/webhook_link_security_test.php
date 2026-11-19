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

namespace local_jedomi\telegram;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\local\service\telegram_link_service;
use local_jedomi\local\service\telegram_pin_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for the security of the Telegram link in the bot (Decision 7, SEC-JD-049): expiry after 30 days without use,
 * repeated updates and the PIN of administrators and managers.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\webhook_handler
 * @covers     \local_jedomi\local\service\telegram_pin_service
 */
final class webhook_link_security_test extends \advanced_testcase {
    use \local_jedomi\tests\other_plugins_debugging;

    /** @var string PIN used by the tests. */
    private const PIN = '135790';

    /** @var int Message id of every message the tests send. */
    private const MESSAGEID = 77;

    /** @var fake_ai_client AI double. */
    private fake_ai_client $ai;

    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the bot asks it whether Jedomi is enabled.');
        }
        $this->resetAfterTest();
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        set_config('telegram_enabled', 1, 'local_jedomi');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
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
     * Link a chat to a user.
     *
     * @param int $userid User.
     * @param string $chatid Chat id.
     * @param int $timecreated Time of the link; now when 0.
     * @param int $lastused Last use; 0 for never.
     * @return void
     */
    private function link(int $userid, string $chatid, int $timecreated = 0, int $lastused = 0): void {
        global $DB;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $userid, 'chatid' => $chatid,
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => $timecreated ?: time(), 'lastproactivesent' => 0,
            'lastused' => $lastused]);
    }

    /**
     * Send a message to the bot.
     *
     * @param string $chatid Chat id.
     * @param string $text Text.
     * @param int|null $updateid Update id; none when null.
     * @return void
     */
    private function say(string $chatid, string $text, ?int $updateid = null): void {
        $limit = (int) ini_get('max_execution_time');
        $update = ['message' => ['message_id' => self::MESSAGEID, 'chat' => ['id' => $chatid, 'type' => 'private'],
            'text' => $text]];
        if ($updateid !== null) {
            $update['update_id'] = $updateid;
        }
        webhook_handler::handle($update);
        set_time_limit($limit);
    }

    /**
     * Queue one tool call and the final text of the AI.
     *
     * @param string $tool Tool name.
     * @param array $args Arguments.
     * @param string $final Final text.
     * @return void
     */
    private function ai_uses(string $tool, array $args, string $final = 'Done.'): void {
        $this->ai->enqueue_tool_calls([['id' => 'call_' . $tool, 'type' => 'function',
            'function' => ['name' => $tool, 'arguments' => json_encode($args)]]]);
        $this->ai->enqueue_text($final);
    }

    /**
     * Create an agent and ask the bot to delete it.
     *
     * @param string $chatid Chat id.
     * @return int Agent id.
     */
    private function ask_to_delete_an_agent(string $chatid): int {
        $owner = $this->getDataGenerator()->create_user();
        $agentid = \local_jedomi\agent\agent_manager::create_agent((object) ['userid' => $owner->id, 'name' => 'Report',
            'prompt' => 'List the students at risk', 'schedule_cron' => '0 8 * * 1', 'context_type' => 'site']);
        $this->telegram->sent = [];
        $this->ai_uses('delete_agent', ['id' => $agentid], 'Noted.');
        $this->say($chatid, 'Delete the report agent');
        return $agentid;
    }

    /**
     * Texts sent to the chat.
     *
     * @return string[]
     */
    private function texts(): array {
        return array_column($this->telegram->sent, 'text');
    }

    /**
     * Decision 7: a link unused for more than 30 days is removed when the chat writes, and the user is told to link
     * again; a link used within 30 days is served and its use recorded.
     */
    public function test_link_unused_for_thirty_days_expires(): void {
        global $DB;
        $old = $this->getDataGenerator()->create_user();
        $recent = $this->getDataGenerator()->create_user();
        $this->link((int) $old->id, '784000001', time() - 40 * DAYSECS, time() - 31 * DAYSECS);
        $this->link((int) $recent->id, '784000002', time() - 40 * DAYSECS, time() - 29 * DAYSECS);
        $this->ai->enqueue_text('Here you are.');

        $this->say('784000001', 'Show me my grades');
        $this->assertSame([], $this->ai->calls, 'An expired link is not served');
        $this->assertFalse($DB->record_exists('local_jedomi_telegram_links', ['userid' => $old->id]));
        $this->assertSame([get_string('telegram_msg_link_expired', 'local_jedomi')], $this->texts());

        $this->say('784000002', 'Show me my grades');
        $this->assertCount(1, $this->ai->calls);
        $this->assertGreaterThanOrEqual(time() - 5, (int) $DB->get_field(
            'local_jedomi_telegram_links',
            'lastused',
            ['userid' => $recent->id]
        ));
    }

    /**
     * Decision 7: a button pressed on a chat whose link expired removes the link and does nothing else.
     */
    public function test_button_of_an_expired_link_does_nothing(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->link((int) $user->id, '784000003', time() - 31 * DAYSECS);

        webhook_handler::handle(['callback_query' => ['id' => 'cb-1', 'data' => 'cmd:new',
            'message' => ['message_id' => 9, 'chat' => ['id' => '784000003', 'type' => 'private']]]]);

        $this->assertFalse($DB->record_exists('local_jedomi_telegram_links', ['userid' => $user->id]));
        $this->assertSame(get_string('telegram_msg_link_expired', 'local_jedomi'), end($this->telegram->edited)['text']);
    }

    /**
     * SEC-JD-049: an update Telegram delivers again with the same update_id is acknowledged but processed only once.
     */
    public function test_repeated_update_is_processed_once(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->link((int) $user->id, '784000004');
        $this->ai->enqueue_text('First.');
        $this->ai->enqueue_text('Second.');

        $this->say('784000004', 'Hello', 5001);
        $this->say('784000004', 'Hello', 5001);
        $this->assertCount(1, $this->ai->calls, 'The repeated update is ignored');

        $this->say('784000004', 'Hello again', 5002);
        $this->assertCount(2, $this->ai->calls, 'A new update is processed');
    }

    /**
     * Decision 7: with the setting off an administrator confirms as before, without a PIN.
     */
    public function test_without_the_setting_no_pin_is_asked(): void {
        global $DB;
        $this->link((int) get_admin()->id, '784000010');

        $agentid = $this->ask_to_delete_an_agent('784000010');
        $this->assertStringNotContainsString(get_string('telegram_pin_prompt', 'local_jedomi'), implode("\n", $this->texts()));

        $this->say('784000010', \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi')));
        $this->assertFalse($DB->record_exists('local_jedomi_agents', ['id' => $agentid]));
    }

    /**
     * Decision 7: an administrator without a PIN has every write refused with a link to set it; reads keep working.
     */
    public function test_writes_without_a_pin_are_refused_and_reads_work(): void {
        global $DB;
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $this->link((int) get_admin()->id, '784000011');

        $agentid = $this->ask_to_delete_an_agent('784000011');

        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]));
        $this->assertSame(0, $DB->count_records('local_jedomi_telegram_pending_actions'), 'Nothing is held');
        $url = (new \moodle_url('/local/jedomi/telegram_profile.php'))->out(false);
        $this->assertStringContainsString($url, implode("\n", $this->texts()));
        $toolresult = $this->ai->calls[1]['messages'][count($this->ai->calls[1]['messages']) - 1]['content'];
        $this->assertStringContainsString('pin_required', (string) $toolresult);

        $this->ai_uses('search_tables', ['query' => 'grade'], 'There are tables.');
        $this->say('784000011', 'Which tables store grades?');
        $toolresult = (string) $this->ai->calls[3]['messages'][count($this->ai->calls[3]['messages']) - 1]['content'];
        $this->assertStringNotContainsString('pin_required', $toolresult);
        $this->assertStringContainsString('There are tables.', end($this->telegram->sent)['text']);
    }

    /**
     * Decision 7: the PIN confirms a write and opens a 15-minute window; the confirmation word does not confirm without
     * the PIN, and the PIN never reaches the AI. Within the window (JEDOMI-SEC-016) a write is still held with its card
     * and confirmed with the button or the word, without the PIN.
     */
    public function test_pin_confirms_the_write_and_opens_the_window(): void {
        global $DB;
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $adminid = (int) get_admin()->id;
        $this->link($adminid, '784000012');
        telegram_pin_service::set_pin($adminid, self::PIN);

        $agentid = $this->ask_to_delete_an_agent('784000012');
        $this->assertStringContainsString(get_string('telegram_pin_prompt', 'local_jedomi'), implode("\n", $this->texts()));
        $buttons = array_merge(...array_map(
            static fn(array $m): array => array_column($m['replymarkup']['inline_keyboard'][0] ?? [], 'callback_data'),
            $this->telegram->sent
        ));
        $this->assertSame(
            [],
            array_values(array_filter($buttons, static fn(string $d): bool => str_starts_with($d, 'confirm:'))),
            'No confirm button'
        );

        $calls = count($this->ai->calls);
        $this->say('784000012', \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi')));
        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]), 'The word does not confirm');

        $this->say('784000012', self::PIN);
        $this->assertFalse($DB->record_exists('local_jedomi_agents', ['id' => $agentid]), 'The PIN confirms');
        $this->assertSame(get_string('confirm_done', 'local_jedomi'), end($this->telegram->sent)['text']);
        foreach (array_slice($this->ai->calls, $calls) as $call) {
            $this->assertStringNotContainsString(self::PIN, json_encode($call['messages']));
        }

        // JEDOMI-SEC-016: within the window the next write is still held with its card; only the PIN is not asked.
        $agentid = $this->ask_to_delete_an_agent('784000012');
        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]), 'Held within the window');
        $this->assertSame(1, $DB->count_records('local_jedomi_telegram_pending_actions'));
        $card = implode("\n", $this->texts());
        $this->assertStringContainsString((string) $agentid, $card, 'The card shows the action');
        $this->assertStringNotContainsString(get_string('telegram_pin_prompt', 'local_jedomi'), $card);
        $keyword = \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi'));
        $this->assertStringContainsString(get_string('telegram_confirm_keyword', 'local_jedomi', s($keyword)), $card);
        $this->say('784000012', $keyword);
        $this->assertFalse($DB->record_exists('local_jedomi_agents', ['id' => $agentid]), 'Runs once confirmed');
        $this->assertSame(get_string('confirm_done', 'local_jedomi'), end($this->telegram->sent)['text']);

        // After the window the PIN is asked again and the confirmation word does not confirm.
        $DB->set_field(
            'local_jedomi_telegram_links',
            'pinverifiedat',
            time() - telegram_pin_service::WINDOW - 1,
            ['userid' => $adminid]
        );
        $agentid = $this->ask_to_delete_an_agent('784000012');
        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]), 'Held again after the window');
        $this->assertStringContainsString(get_string('telegram_pin_prompt', 'local_jedomi'), implode("\n", $this->texts()));
        $this->say('784000012', \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi')));
        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]), 'The word needs the PIN again');
        $this->assertSame(get_string('telegram_pin_prompt', 'local_jedomi'), end($this->telegram->sent)['text']);
    }

    /**
     * JEDOMI-SEC-016 (NEW-POC-2): within the PIN window a critical web service write (deleting users) is held with its
     * card and runs only after the confirmation word; a high write keeps its confirm button, which confirms it.
     */
    public function test_writes_within_the_pin_window_are_still_confirmed(): void {
        global $DB;
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $adminid = (int) get_admin()->id;
        $this->link($adminid, '784000015');
        telegram_pin_service::set_pin($adminid, self::PIN);
        $this->assertSame(telegram_pin_service::RESULT_OK, telegram_pin_service::verify($adminid, self::PIN)['result']);
        $this->assertTrue(telegram_pin_service::in_window($adminid));
        $victim = $this->getDataGenerator()->create_user();

        $this->telegram->sent = [];
        $this->ai_uses('ws_execute', ['wsname' => 'core_user_delete_users', 'params' => ['userids' => [(int) $victim->id]]]);
        $this->say('784000015', 'Delete that user');

        $this->assertFalse((bool) $DB->get_field('user', 'deleted', ['id' => $victim->id]), 'Held within the window');
        $this->assertSame(1, $DB->count_records('local_jedomi_telegram_pending_actions'));
        $card = implode("\n", $this->texts());
        $this->assertStringContainsString((string) $victim->id, $card, 'The card shows the arguments');
        $this->assertStringNotContainsString(get_string('telegram_pin_prompt', 'local_jedomi'), $card);
        $toolresult = (string) $this->ai->calls[1]['messages'][count($this->ai->calls[1]['messages']) - 1]['content'];
        $this->assertStringContainsString('awaiting_confirmation', $toolresult);

        $this->say('784000015', \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi')));
        $this->assertTrue((bool) $DB->get_field('user', 'deleted', ['id' => $victim->id]), 'Runs once confirmed');
        $this->assertSame(0, $DB->count_records('local_jedomi_telegram_pending_actions'));

        // A high write: the card has the confirm button, and the button confirms within the window.
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $this->telegram->sent = [];
        $this->ai_uses('ws_execute', ['wsname' => 'core_role_assign_roles', 'params' => ['assignments' => [
            ['roleid' => $roleid, 'userid' => (int) $student->id, 'contextid' => \context_course::instance($course->id)->id]]]]);
        $this->say('784000015', 'Make them manager of the course');
        $buttons = array_merge(...array_map(
            static fn(array $m): array => array_column($m['replymarkup']['inline_keyboard'][0] ?? [], 'callback_data'),
            $this->telegram->sent
        ));
        $confirm = array_values(array_filter($buttons, static fn(string $d): bool => str_starts_with($d, 'confirm:')));
        $this->assertCount(1, $confirm, 'The card has the confirm button');
        $this->assertFalse($DB->record_exists('role_assignments', ['userid' => $student->id, 'roleid' => $roleid]));

        webhook_handler::handle(['callback_query' => ['id' => 'cb-016', 'data' => $confirm[0],
            'message' => ['message_id' => self::MESSAGEID, 'chat' => ['id' => '784000015', 'type' => 'private']]]]);
        $this->assertTrue($DB->record_exists('role_assignments', ['userid' => $student->id, 'roleid' => $roleid]));
    }

    /**
     * Decision 7: three wrong PINs lock the Telegram writes of the user: the right PIN and new writes are refused.
     */
    public function test_three_wrong_pins_lock_the_writes(): void {
        global $DB;
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_system::instance()->id);
        $this->link((int) $manager->id, '784000013');
        telegram_pin_service::set_pin((int) $manager->id, self::PIN);
        $agentid = $this->ask_to_delete_an_agent('784000013');
        $calls = count($this->ai->calls);

        $this->say('784000013', '000000');
        $this->say('784000013', '000001');
        $this->say('784000013', '000002');
        $this->assertSame($calls, count($this->ai->calls), 'No PIN attempt reaches the AI');
        $this->assertStringContainsString(
            get_string('telegram_pin_locked', 'local_jedomi', telegram_pin_service::LOCK_TIME / MINSECS),
            end($this->telegram->sent)['text']
        );

        $this->say('784000013', self::PIN);
        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]), 'Locked: the right PIN is refused');

        $this->ask_to_delete_an_agent('784000013');
        $this->assertStringContainsString(
            get_string('telegram_pin_locked', 'local_jedomi', telegram_pin_service::LOCK_TIME / MINSECS),
            implode("\n", $this->texts())
        );
    }

    /**
     * Decision 7: the PIN applies only to administrators and managers: a teacher confirms as before.
     */
    public function test_teacher_confirms_without_a_pin(): void {
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->link((int) $teacher->id, '784000014');
        $this->ai->enqueue_text('Hello.');

        $this->say('784000014', '135790');

        $this->assertCount(1, $this->ai->calls, 'A number is a normal message for a user without a PIN');

        // A write of the teacher is held with the usual card, without any PIN.
        $this->telegram->sent = [];
        $hold = new \ReflectionMethod(webhook_handler::class, 'hold_action');
        $result = (string) $hold->invoke(null, '784000014', (int) $teacher->id, 'delete_agent', ['id' => 1]);
        $this->assertStringContainsString('awaiting_confirmation', $result);
        $this->assertStringNotContainsString('PIN', $result);
        $this->assertStringNotContainsString(get_string('telegram_pin_prompt', 'local_jedomi'), implode("\n", $this->texts()));
    }

    /**
     * Text of the Jedomi log written since it was cleared.
     *
     * @return string
     */
    private function log_text(): string {
        global $CFG;
        $path = $CFG->dataroot . '/local/jedomi/jedomi.log';
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Clear the Jedomi log.
     *
     * @return void
     */
    private function clear_log(): void {
        global $CFG;
        @unlink($CFG->dataroot . '/local/jedomi/jedomi.log');
    }

    /**
     * RISK-001: the message with the PIN is deleted from the chat right after it is read, right or wrong; a failed
     * deletion is logged and the PIN is still checked.
     */
    public function test_pin_message_is_deleted_from_the_chat(): void {
        global $DB;
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $adminid = (int) get_admin()->id;
        $this->link($adminid, '784000020');
        telegram_pin_service::set_pin($adminid, self::PIN);
        $this->ask_to_delete_an_agent('784000020');

        $this->say('784000020', '000000');
        $this->assertSame([['chatid' => '784000020', 'messageid' => self::MESSAGEID]], $this->telegram->deleted);

        $this->telegram->deletefails = true;
        $this->clear_log();
        $agentid = (int) $DB->get_field('local_jedomi_agents', 'id', []);
        $this->say('784000020', self::PIN);
        $this->assertFalse($DB->record_exists('local_jedomi_agents', ['id' => $agentid]), 'The PIN is still checked');
        $this->assertStringContainsString('PIN message could not be deleted', $this->log_text());
    }

    /**
     * RES-003, REL-004, RISK-003: a 6 to 8 digit message of a user who needs a PIN is a PIN only while an action waits
     * in the chat; otherwise it is a normal message for the AI and no PIN attempt is counted.
     */
    public function test_number_without_a_waiting_action_goes_to_the_ai(): void {
        global $DB;
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $adminid = (int) get_admin()->id;
        $this->link($adminid, '784000021');
        telegram_pin_service::set_pin($adminid, self::PIN);
        $this->ai->enqueue_text('Order 12345678 is ready.');

        $this->say('784000021', '12345678');

        $this->assertCount(1, $this->ai->calls, 'The number reaches the AI');
        $this->assertSame([], $this->telegram->deleted);
        $this->assertSame(0, (int) $DB->get_field('local_jedomi_telegram_links', 'pinfailures', ['userid' => $adminid]));

        // With an action waiting, the same number is a PIN attempt.
        $this->ask_to_delete_an_agent('784000021');
        $calls = count($this->ai->calls);
        $this->say('784000021', '12345678');
        $this->assertSame($calls, count($this->ai->calls), 'The PIN attempt does not reach the AI');
        $this->assertSame(1, (int) $DB->get_field('local_jedomi_telegram_links', 'pinfailures', ['userid' => $adminid]));
    }

    /**
     * REL-003: "/start CODE" sent from a chat whose link expired links the chat in the same message.
     */
    public function test_expired_link_relinks_with_a_start_code(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->link((int) $user->id, '784000022', time() - 40 * DAYSECS, time() - 31 * DAYSECS);
        $code = manager::generate_link_code((int) $user->id);

        $this->say('784000022', '/start ' . $code);

        $link = $DB->get_record('local_jedomi_telegram_links', ['userid' => $user->id]);
        $this->assertNotFalse($link, 'The chat is linked again');
        $this->assertFalse(telegram_link_service::is_expired($link));
        $this->assertStringContainsString($user->username, end($this->telegram->sent)['text']);
    }

    /**
     * REL-006: an update whose processing fails is forgotten, so the retry of Telegram is processed.
     */
    public function test_failed_update_is_processed_on_retry(): void {
        $this->telegram->onsend = static function (): void {
            throw new \moodle_exception('error');
        };
        try {
            $this->say('784000023', '/help', 7001);
            $this->fail('The failure reaches the webhook');
        } catch (\moodle_exception $e) {
            $this->assertSame([], $this->telegram->sent);
        }

        $this->telegram->onsend = null;
        $this->say('784000023', '/help', 7001);
        $this->assertCount(1, $this->telegram->sent, 'The retry is processed');
        $this->say('784000023', '/help', 7001);
        $this->assertCount(1, $this->telegram->sent, 'A processed update is not processed again');
    }

    /**
     * RES-002: a wrong PIN, the lockout and a write refused for a missing PIN are logged, never with the PIN.
     */
    public function test_pin_events_are_logged_without_the_pin(): void {
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $adminid = (int) get_admin()->id;
        $this->link($adminid, '784000024');
        $this->clear_log();
        $this->ask_to_delete_an_agent('784000024');
        $this->assertStringContainsString('PIN required', $this->log_text());

        telegram_pin_service::set_pin($adminid, self::PIN);
        $this->ask_to_delete_an_agent('784000024');
        $this->clear_log();
        $this->say('784000024', '246813');
        $this->assertStringContainsString('wrong PIN', $this->log_text());
        $this->say('784000024', '246813');
        $this->say('784000024', '246813');
        $log = $this->log_text();
        $this->assertStringContainsString('PIN lockout', $log);
        $this->assertStringNotContainsString('246813', $log);
        $this->assertStringNotContainsString(self::PIN, $log);
    }
}
