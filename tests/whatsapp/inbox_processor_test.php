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

namespace local_jedomi\whatsapp;

use local_jedomi\local\secret_store;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\task\process_whatsapp_inbox;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/recording_message_processor.php');

/**
 * Tests for the processing of the WhatsApp inbox rows and for the rescue task.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\inbox_processor
 * @covers     \local_jedomi\task\process_whatsapp_inbox
 * @covers     \local_jedomi\local\service\whatsapp_link_service
 */
final class inbox_processor_test extends \advanced_testcase {
    /** @var string Previous BSUID of the student. */
    private const OLD = 'US.11111111111111111111';

    /** @var string New BSUID of the student. */
    private const NEW = 'US.22222222222222222222';

    /** @var int Frozen time of the tests. */
    private const NOW = 1800000000;

    /** @var recording_message_processor Processor double. */
    private recording_message_processor $processor;

    /**
     * Freeze the clock and register the processor double.
     */
    private function setup_processor(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::NOW);
        $this->processor = new recording_message_processor();
        \core\di::set(message_processor::class, $this->processor);
    }

    /**
     * Insert an inbox row.
     *
     * @param array $message Raw message of Meta.
     * @param array $fields Fields that replace the default ones.
     * @return int Row id.
     */
    private static function insert_row(array $message, array $fields = []): int {
        global $DB;
        $payload = secret_store::encrypt(json_encode(['message' => $message, 'contact' => null]));
        return $DB->insert_record('local_jedomi_whatsapp_inbox', (object) array_merge([
            'wamid' => $message['id'],
            'bsuid' => $message['from_user_id'] ?? '',
            'payload' => $payload,
            'status' => inbox_processor::STATUS_PENDING,
            'attempts' => 0,
            'timecreated' => self::NOW,
            'timeprocessed' => 0,
        ], $fields));
    }

    /**
     * A text message.
     *
     * @param string $wamid Message id.
     * @return array
     */
    private static function text(string $wamid = 'wamid.A1'): array {
        return ['from_user_id' => self::OLD, 'id' => $wamid, 'type' => 'text', 'text' => ['body' => 'Hello']];
    }

    /**
     * An identity change system message.
     *
     * @param string $type System type.
     * @param array $extra Fields of the system part.
     * @return array
     */
    private static function identity_change(string $type = 'user_changed_user_id', array $extra = []): array {
        return ['from_user_id' => self::NEW, 'id' => 'wamid.SYS', 'type' => 'system',
            'system' => array_merge(['type' => $type, 'body' => 'changed', 'previous_user_id' => self::OLD], $extra)];
    }

    /**
     * Read a row.
     *
     * @param int $id Row id.
     * @return \stdClass
     */
    private static function row(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_jedomi_whatsapp_inbox', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Insert a link with its chat rows for a BSUID.
     *
     * @param int $userid User id.
     * @param string $bsuid BSUID.
     * @param string|null $waid Phone.
     */
    private static function insert_link(int $userid, string $bsuid, ?string $waid = null): void {
        global $DB;
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) ['userid' => $userid, 'bsuid' => $bsuid,
            'waid' => $waid, 'phone' => $waid ? '+' . $waid : null, 'timecreated' => self::NOW]);
        $chatkey = whatsapp_link_service::chatkey($bsuid);
        $DB->insert_record(whatsapp_link_service::SESSIONS_TABLE, (object) ['chatid' => $chatkey,
            'messages' => '[]', 'lastactivity' => self::NOW]);
        $DB->insert_record(whatsapp_link_service::SESSIONS_TABLE, (object) ['chatid' => 'tool_loop_' . $chatkey,
            'messages' => '[]', 'lastactivity' => self::NOW]);
        $DB->insert_record(whatsapp_link_service::RATE_TABLE, (object) ['chatid' => $chatkey, 'windowstart' => self::NOW]);
        $DB->insert_record(whatsapp_link_service::PENDING_TABLE, (object) ['callbackid' => 'cb' . $userid,
            'chatid' => $chatkey, 'actionjson' => '{}', 'timecreated' => self::NOW]);
    }

    /**
     * A pending row is claimed, processed and marked done, and its payload is blanked.
     */
    public function test_a_pending_row_is_processed_and_blanked(): void {
        $this->setup_processor();
        $id = self::insert_row(self::text());

        $result = (new inbox_processor())->process($id);

        $this->assertSame(inbox_processor::RESULT_DONE, $result);
        $row = self::row($id);
        $this->assertSame(inbox_processor::STATUS_DONE, $row->status);
        $this->assertSame('', (string) $row->payload);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(self::NOW, (int) $row->timeprocessed);
        $this->assertCount(1, $this->processor->messages);
        $this->assertSame('Hello', $this->processor->messages[0]->text);
    }

    /**
     * Rows already done or failed, and missing rows, are left alone.
     *
     * @dataProvider finished_provider
     * @param string $status Status of the row.
     */
    public function test_finished_rows_are_left_alone(string $status): void {
        $this->setup_processor();
        $id = self::insert_row(self::text(), ['status' => $status, 'payload' => '', 'timeprocessed' => 5]);

        $this->assertSame(inbox_processor::RESULT_SKIPPED, (new inbox_processor())->process($id));
        $this->assertSame(inbox_processor::RESULT_SKIPPED, (new inbox_processor())->process($id + 100));

        $this->assertSame($status, self::row($id)->status);
        $this->assertSame(5, (int) self::row($id)->timeprocessed);
        $this->assertSame([], $this->processor->messages);
    }

    /**
     * Finished statuses.
     *
     * @return array
     */
    public static function finished_provider(): array {
        return ['done' => [inbox_processor::STATUS_DONE], 'failed' => [inbox_processor::STATUS_FAILED]];
    }

    /**
     * A row another worker is processing is busy until it is older than LOCK_TTL; then it is taken over.
     */
    public function test_a_processing_row_is_taken_over_only_when_stale(): void {
        $this->setup_processor();
        $fresh = self::insert_row(self::text('wamid.F'), ['status' => inbox_processor::STATUS_PROCESSING,
            'attempts' => 1, 'timeprocessed' => self::NOW - inbox_processor::LOCK_TTL + 1]);
        $stale = self::insert_row(self::text('wamid.S'), ['status' => inbox_processor::STATUS_PROCESSING,
            'attempts' => 1, 'timeprocessed' => self::NOW - inbox_processor::LOCK_TTL - 1]);

        $this->assertSame(inbox_processor::RESULT_BUSY, (new inbox_processor())->process($fresh));
        $this->assertSame(inbox_processor::RESULT_DONE, (new inbox_processor())->process($stale));

        $this->assertSame(inbox_processor::STATUS_PROCESSING, self::row($fresh)->status);
        $this->assertSame(inbox_processor::STATUS_DONE, self::row($stale)->status);
        $this->assertSame(2, (int) self::row($stale)->attempts);
    }

    /**
     * A processing error puts the row back to pending with its payload, for the rescue; after MAX_ATTEMPTS the row
     * fails and its payload is blanked.
     */
    public function test_errors_are_retried_up_to_the_maximum(): void {
        $this->setup_processor();
        $this->processor->failures = inbox_processor::MAX_ATTEMPTS;
        $id = self::insert_row(self::text());

        for ($i = 1; $i < inbox_processor::MAX_ATTEMPTS; $i++) {
            $this->assertSame(inbox_processor::RESULT_RETRY, (new inbox_processor())->process($id));
            $this->assertSame(inbox_processor::STATUS_PENDING, self::row($id)->status);
            $this->assertNotSame('', (string) self::row($id)->payload);
        }
        $this->assertSame(inbox_processor::RESULT_FAILED, (new inbox_processor())->process($id));

        $row = self::row($id);
        $this->assertSame(inbox_processor::STATUS_FAILED, $row->status);
        $this->assertSame('', (string) $row->payload);
        $this->assertSame(inbox_processor::MAX_ATTEMPTS, (int) $row->attempts);
    }

    /**
     * A payload that cannot be read fails the row at once.
     */
    public function test_an_unreadable_payload_fails_the_row(): void {
        $this->setup_processor();
        $id = self::insert_row(self::text(), ['payload' => secret_store::encrypt('not json')]);

        $this->assertSame(inbox_processor::RESULT_FAILED, (new inbox_processor())->process($id));
        $this->assertSame(inbox_processor::STATUS_FAILED, self::row($id)->status);
        $this->assertSame('', (string) self::row($id)->payload);
    }

    /**
     * user_changed_user_id moves the link and the chat rows of the student to the new BSUID, without the processor.
     */
    public function test_an_identity_change_moves_the_link_and_the_chat_rows(): void {
        global $DB;
        $this->setup_processor();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::OLD, '16505551234');
        $waiting = self::insert_row(self::text('wamid.W'), ['status' => inbox_processor::STATUS_DONE, 'payload' => '']);
        $id = self::insert_row(self::identity_change());

        $this->assertSame(inbox_processor::RESULT_DONE, (new inbox_processor())->process($id));

        $link = $DB->get_record(whatsapp_link_service::LINKS_TABLE, ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame(self::NEW, $link->bsuid);
        $this->assertSame('16505551234', $link->waid);
        $old = whatsapp_link_service::chatkey(self::OLD);
        $new = whatsapp_link_service::chatkey(self::NEW);
        $this->assertTrue($DB->record_exists(whatsapp_link_service::SESSIONS_TABLE, ['chatid' => $new]));
        $this->assertTrue($DB->record_exists(whatsapp_link_service::SESSIONS_TABLE, ['chatid' => 'tool_loop_' . $new]));
        $this->assertTrue($DB->record_exists(whatsapp_link_service::RATE_TABLE, ['chatid' => $new]));
        $this->assertTrue($DB->record_exists(whatsapp_link_service::PENDING_TABLE, ['chatid' => $new]));
        foreach (
            [whatsapp_link_service::SESSIONS_TABLE, whatsapp_link_service::RATE_TABLE,
                whatsapp_link_service::PENDING_TABLE] as $table
        ) {
            $this->assertFalse($DB->record_exists($table, ['chatid' => $old]), $table);
        }
        $this->assertFalse($DB->record_exists(whatsapp_link_service::SESSIONS_TABLE, ['chatid' => 'tool_loop_' . $old]));
        $this->assertSame(self::NEW, self::row($waiting)->bsuid);
        $this->assertSame([], $this->processor->messages);
    }

    /**
     * user_changed_number also replaces the phone of the link, so nothing is sent to the old number.
     */
    public function test_a_number_change_replaces_the_phone(): void {
        global $DB;
        $this->setup_processor();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::OLD, '16505551234');
        $id = self::insert_row(self::identity_change('user_changed_number', ['new_wa_id' => '16505559999']));

        (new inbox_processor())->process($id);

        $link = $DB->get_record(whatsapp_link_service::LINKS_TABLE, ['userid' => $user->id], '*', MUST_EXIST);
        $this->assertSame(self::NEW, $link->bsuid);
        $this->assertSame('16505559999', $link->waid);
        $this->assertNull($link->phone);
    }

    /**
     * user_changed_number without a new phone clears the old one.
     */
    public function test_a_number_change_without_new_phone_clears_the_old_one(): void {
        global $DB;
        $this->setup_processor();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::OLD, '16505551234');
        $id = self::insert_row(self::identity_change('user_changed_number'));

        (new inbox_processor())->process($id);

        $this->assertNull($DB->get_field(whatsapp_link_service::LINKS_TABLE, 'waid', ['userid' => $user->id]));
    }

    /**
     * An identity change for a BSUID that is not linked is ignored and the row is done.
     */
    public function test_an_identity_change_without_link_is_ignored(): void {
        global $DB;
        $this->setup_processor();
        $id = self::insert_row(self::identity_change());

        $this->assertSame(inbox_processor::RESULT_DONE, (new inbox_processor())->process($id));
        $this->assertSame(0, $DB->count_records(whatsapp_link_service::LINKS_TABLE));
    }

    /**
     * When the new BSUID is already linked to an account, neither link nor chat rows are touched.
     */
    public function test_an_identity_change_never_overwrites_another_link(): void {
        global $DB;
        $this->setup_processor();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        self::insert_link((int) $first->id, self::OLD);
        self::insert_link((int) $second->id, self::NEW);

        $this->assertFalse(whatsapp_link_service::change_identity(self::OLD, self::NEW));

        $this->assertSame(self::OLD, $DB->get_field(whatsapp_link_service::LINKS_TABLE, 'bsuid', ['userid' => $first->id]));
        $this->assertSame(self::NEW, $DB->get_field(whatsapp_link_service::LINKS_TABLE, 'bsuid', ['userid' => $second->id]));
        $this->assertSame(1, $DB->count_records(
            whatsapp_link_service::RATE_TABLE,
            ['chatid' => whatsapp_link_service::chatkey(self::OLD)]
        ));
    }

    /**
     * When chat rows of the new BSUID exist without a link, the old ones are dropped instead of breaking the unique keys.
     */
    public function test_an_identity_change_drops_old_rows_that_would_collide(): void {
        global $DB;
        $this->setup_processor();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::OLD);
        $new = whatsapp_link_service::chatkey(self::NEW);
        $DB->insert_record(whatsapp_link_service::SESSIONS_TABLE, (object) ['chatid' => $new, 'lastactivity' => 1]);
        $DB->insert_record(whatsapp_link_service::RATE_TABLE, (object) ['chatid' => $new, 'windowstart' => 1]);

        $this->assertTrue(whatsapp_link_service::change_identity(self::OLD, self::NEW));

        $this->assertSame(1, $DB->count_records(whatsapp_link_service::SESSIONS_TABLE, ['chatid' => $new]));
        $this->assertSame(1, $DB->count_records(whatsapp_link_service::RATE_TABLE, ['chatid' => $new]));
        $this->assertSame(0, $DB->count_records_select(
            whatsapp_link_service::SESSIONS_TABLE,
            'chatid LIKE ?',
            ['%' . self::OLD]
        ));
        $this->assertSame(self::NEW, $DB->get_field(whatsapp_link_service::LINKS_TABLE, 'bsuid', ['userid' => $user->id]));
    }

    /**
     * The rescue task processes a row still pending.
     */
    public function test_the_rescue_task_processes_a_pending_row(): void {
        $this->setup_processor();
        $id = self::insert_row(self::text());
        $task = new process_whatsapp_inbox();
        $task->set_custom_data(['inboxid' => $id]);

        $task->execute();

        $this->assertSame(inbox_processor::STATUS_DONE, self::row($id)->status);
        $this->assertCount(1, $this->processor->messages);
    }

    /**
     * The rescue task does nothing for a row already done, and without an inbox id.
     */
    public function test_the_rescue_task_does_nothing_for_a_done_row(): void {
        $this->setup_processor();
        $id = self::insert_row(self::text(), ['status' => inbox_processor::STATUS_DONE, 'payload' => '']);
        $task = new process_whatsapp_inbox();
        $task->set_custom_data(['inboxid' => $id]);
        $empty = new process_whatsapp_inbox();

        $task->execute();
        $empty->execute();

        $this->assertSame([], $this->processor->messages);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class));
    }

    /**
     * When the row is busy or must be retried, the rescue task queues itself again after LOCK_TTL.
     */
    public function test_the_rescue_task_comes_back_for_a_busy_or_failed_row(): void {
        $this->setup_processor();
        $this->processor->failures = 1;
        $busy = self::insert_row(self::text('wamid.B'), ['status' => inbox_processor::STATUS_PROCESSING,
            'attempts' => 1, 'timeprocessed' => self::NOW]);
        $retry = self::insert_row(self::text('wamid.R'));

        foreach ([$busy, $retry] as $id) {
            $task = new process_whatsapp_inbox();
            $task->set_custom_data(['inboxid' => $id]);
            $task->execute();
        }

        $tasks = \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class);
        $this->assertCount(2, $tasks);
        $ids = [];
        foreach ($tasks as $task) {
            $ids[] = (int) $task->get_custom_data()->inboxid;
            $this->assertSame(self::NOW + inbox_processor::LOCK_TTL, (int) $task->get_next_run_time());
        }
        sort($ids);
        $this->assertSame([$busy, $retry], $ids);
    }
}
