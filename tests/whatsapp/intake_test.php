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
use local_jedomi\messaging\http_responder;
use local_jedomi\messaging\recording_http_responder;
use local_jedomi\task\process_whatsapp_inbox;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/recording_http_responder.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/recording_message_processor.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/recording_status_sink.php');

/**
 * Tests for the intake of the WhatsApp webhook: handshake, signature, inbox, deduplication, rescue and early response.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\intake
 */
final class intake_test extends \advanced_testcase {
    /** @var string App Secret of the tests. */
    private const SECRET = 'test-app-secret';

    /** @var string Verify token of the tests. */
    private const VERIFY = 'test-verify-token';

    /** @var string BSUID of the sender. */
    private const BSUID = 'US.13491208655302741918';

    /** @var int Frozen time of the tests. */
    private const NOW = 1800000000;

    /** @var recording_http_responder Responder double. */
    private recording_http_responder $responder;

    /** @var recording_message_processor Processor double. */
    private recording_message_processor $processor;

    /** @var recording_status_sink Status sink double. */
    private recording_status_sink $sink;

    /** @var \ArrayObject Journal of the events of the doubles. */
    private \ArrayObject $journal;

    /**
     * Configure the channel and register the doubles.
     *
     * @param string $mode Processing mode.
     */
    private function setup_channel(string $mode = manager::PROCESSING_INLINE): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::NOW);
        manager::set_app_secret(self::SECRET);
        manager::set_verify_token(self::VERIFY);
        manager::set_enabled(true);
        manager::set_processing_mode($mode);
        $this->journal = new \ArrayObject();
        $this->responder = new recording_http_responder($this->journal);
        $this->processor = new recording_message_processor($this->journal);
        $this->sink = new recording_status_sink();
        \core\di::set(http_responder::class, $this->responder);
        \core\di::set(message_processor::class, $this->processor);
        \core\di::set(status_sink::class, $this->sink);
    }

    /**
     * Signature header of a body.
     *
     * @param string $body Raw body.
     * @return string
     */
    private static function sign(string $body): string {
        return 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
    }

    /**
     * A webhook body with one change.
     *
     * @param array $messages Messages.
     * @param array $contacts Contacts.
     * @param array $statuses Statuses.
     * @return string
     */
    private static function body(array $messages, array $contacts = [], array $statuses = []): string {
        $value = ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '106540352242922']];
        if ($contacts) {
            $value['contacts'] = $contacts;
        }
        if ($messages) {
            $value['messages'] = $messages;
        }
        if ($statuses) {
            $value['statuses'] = $statuses;
        }
        return json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => $value]]]],
        ]);
    }

    /**
     * A text message from a sender that only has a BSUID.
     *
     * @param string $wamid Message id.
     * @param string $text Text.
     * @return array
     */
    private static function text(string $wamid, string $text = 'Hello'): array {
        return ['from_user_id' => self::BSUID, 'id' => $wamid, 'timestamp' => '1777000000', 'type' => 'text',
            'text' => ['body' => $text]];
    }

    /**
     * Post a signed body.
     *
     * @param string $body Raw body.
     */
    private function post(string $body): void {
        (new intake())->handle('POST', [], $body, self::sign($body));
    }

    /**
     * The handshake answers the challenge as plain text when the mode and the verify token match.
     */
    public function test_a_valid_handshake_returns_the_challenge(): void {
        $this->setup_channel();

        (new intake())->handle('GET', ['hub_mode' => 'subscribe', 'hub_verify_token' => self::VERIFY,
            'hub_challenge' => '1158201444'], '', null);

        $response = $this->responder->single();
        $this->assertSame(200, $response['status']);
        $this->assertSame('1158201444', $response['body']);
        $this->assertContains('Content-Type: text/plain; charset=utf-8', $response['headers']);
    }

    /**
     * A handshake with a wrong token, another mode, no challenge or no saved token gets 403 without the challenge.
     *
     * @dataProvider invalid_handshake_provider
     * @param array $query Query of the request.
     * @param bool $savetoken Whether a verify token is saved.
     */
    public function test_an_invalid_handshake_is_forbidden(array $query, bool $savetoken): void {
        $this->setup_channel();
        if (!$savetoken) {
            secret_store::set('whatsapp_verify_token', '');
        }

        (new intake())->handle('GET', $query, '', null);

        $response = $this->responder->single();
        $this->assertSame(403, $response['status']);
        $this->assertSame('', $response['body']);
    }

    /**
     * Invalid handshakes.
     *
     * @return array
     */
    public static function invalid_handshake_provider(): array {
        $valid = ['hub_mode' => 'subscribe', 'hub_verify_token' => self::VERIFY, 'hub_challenge' => '1158201444'];
        return [
            'wrong token' => [array_merge($valid, ['hub_verify_token' => 'other']), true],
            'empty token' => [array_merge($valid, ['hub_verify_token' => '']), true],
            'other mode' => [array_merge($valid, ['hub_mode' => 'unsubscribe']), true],
            'no challenge' => [array_merge($valid, ['hub_challenge' => '']), true],
            'markup challenge' => [array_merge($valid, ['hub_challenge' => '<script>x</script>']), true],
            'no saved token' => [array_merge($valid, ['hub_verify_token' => '']), false],
            'nothing' => [[], true],
        ];
    }

    /**
     * Methods other than GET and POST get 405 with the allowed methods.
     *
     * @dataProvider other_method_provider
     * @param string $method HTTP method.
     */
    public function test_other_methods_are_not_allowed(string $method): void {
        $this->setup_channel();

        (new intake())->handle($method, [], '', null);

        $response = $this->responder->single();
        $this->assertSame(405, $response['status']);
        $this->assertContains('Allow: GET, POST', $response['headers']);
    }

    /**
     * Other methods.
     *
     * @return array
     */
    public static function other_method_provider(): array {
        return ['put' => ['PUT'], 'delete' => ['DELETE'], 'head' => ['HEAD'], 'empty' => ['']];
    }

    /**
     * A POST with a missing, malformed or wrong signature gets 403 and nothing is stored nor processed.
     *
     * @dataProvider bad_signature_provider
     * @param string|null $signature Signature header.
     */
    public function test_a_post_with_a_bad_signature_is_forbidden(?string $signature): void {
        global $DB;
        $this->setup_channel();
        $body = self::body([self::text('wamid.A1')]);

        (new intake())->handle('POST', [], $body, $signature);

        $response = $this->responder->single();
        $this->assertSame(403, $response['status']);
        $this->assertSame('', $response['body']);
        $this->assertSame(0, $DB->count_records('local_jedomi_whatsapp_inbox'));
        $this->assertSame([], $this->processor->messages);
    }

    /**
     * Bad signatures.
     *
     * @return array
     */
    public static function bad_signature_provider(): array {
        return [
            'missing' => [null],
            'no prefix' => [hash_hmac('sha256', 'x', self::SECRET)],
            'other secret' => ['sha256=' . hash_hmac('sha256', 'x', 'other')],
        ];
    }

    /**
     * The signature is checked on the raw bytes: a body re-encoded with other spacing does not match.
     */
    public function test_the_signature_is_checked_on_the_raw_body(): void {
        $this->setup_channel();
        $body = self::body([self::text('wamid.A1')]);
        $reencoded = json_encode(json_decode($body), JSON_PRETTY_PRINT);

        (new intake())->handle('POST', [], $reencoded, self::sign($body));

        $this->assertSame(403, $this->responder->single()['status']);
    }

    /**
     * A message without from nor contacts[].wa_id is stored with its BSUID, encrypted, a rescue is queued and it is
     * processed inline after the early empty JSON response.
     */
    public function test_a_message_without_phone_is_stored_answered_early_and_processed(): void {
        global $DB;
        $this->setup_channel();
        $body = self::body([self::text('wamid.A1', 'What is my grade?')], [['user_id' => self::BSUID]]);

        $this->post($body);

        $row = $DB->get_record('local_jedomi_whatsapp_inbox', ['wamid' => 'wamid.A1'], '*', MUST_EXIST);
        $this->assertSame(self::BSUID, $row->bsuid);
        $this->assertSame(inbox_processor::STATUS_DONE, $row->status);
        $this->assertSame('', (string) $row->payload);
        $this->assertSame(self::NOW, (int) $row->timecreated);

        $response = $this->responder->single();
        $this->assertSame(200, $response['status']);
        $this->assertSame('', $response['body']);
        $this->assertTrue($response['closed']);
        $this->assertContains('Content-Type: application/json', $response['headers']);
        $this->assertContains('Content-Length: 0', $response['headers']);
        $this->assertContains('Connection: close', $response['headers']);
        $this->assertSame(['response', 'process'], $this->journal->getArrayCopy());

        $this->assertCount(1, $this->processor->messages);
        $this->assertSame('What is my grade?', $this->processor->messages[0]->text);
        $this->assertNull($this->processor->messages[0]->waid);

        $tasks = \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame((int) $row->id, (int) $task->get_custom_data()->inboxid);
        $this->assertSame(self::NOW + intake::RESCUE_DELAY, (int) $task->get_next_run_time());
    }

    /**
     * The payload waiting in the inbox is encrypted, never kept in clear text.
     */
    public function test_the_pending_payload_is_encrypted(): void {
        global $DB;
        $this->setup_channel(manager::PROCESSING_QUEUE);

        $this->post(self::body([self::text('wamid.A1', 'Secret question')]));

        $row = $DB->get_record('local_jedomi_whatsapp_inbox', ['wamid' => 'wamid.A1'], '*', MUST_EXIST);
        $this->assertSame(inbox_processor::STATUS_PENDING, $row->status);
        $this->assertStringNotContainsString('Secret question', $row->payload);
        $this->assertTrue(secret_store::is_encrypted($row->payload));
        $this->assertStringContainsString('Secret question', secret_store::decrypt($row->payload));
    }

    /**
     * In queue mode the message is stored and answered but only the rescue task processes it.
     */
    public function test_queue_mode_leaves_the_processing_to_the_task(): void {
        global $DB;
        $this->setup_channel(manager::PROCESSING_QUEUE);

        $this->post(self::body([self::text('wamid.A1')]));

        $this->assertSame(200, $this->responder->single()['status']);
        $this->assertSame([], $this->processor->messages);
        $this->assertSame(
            inbox_processor::STATUS_PENDING,
            $DB->get_field('local_jedomi_whatsapp_inbox', 'status', ['wamid' => 'wamid.A1'])
        );
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class));
    }

    /**
     * Each message of a POST gets its own row and rescue task.
     */
    public function test_each_message_gets_a_row(): void {
        global $DB;
        $this->setup_channel(manager::PROCESSING_QUEUE);

        $this->post(self::body([self::text('wamid.A1'), self::text('wamid.A2')]));

        $this->assertSame(2, $DB->count_records('local_jedomi_whatsapp_inbox'));
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class));
    }

    /**
     * A retry of Meta with messages already stored answers 200 and processes nothing again.
     */
    public function test_a_duplicate_post_is_answered_and_not_processed_again(): void {
        global $DB;
        $this->setup_channel();
        $body = self::body([self::text('wamid.A1')]);
        $this->post($body);
        $this->responder->responses = [];

        $this->post($body);

        $this->assertSame(200, $this->responder->single()['status']);
        $this->assertSame(1, $DB->count_records('local_jedomi_whatsapp_inbox'));
        $this->assertCount(1, $this->processor->messages);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class));
    }

    /**
     * In a POST with a new message and a duplicate, only the new one is stored and processed.
     */
    public function test_only_new_messages_of_a_mixed_post_are_processed(): void {
        global $DB;
        $this->setup_channel();
        $this->post(self::body([self::text('wamid.A1')]));

        $this->post(self::body([self::text('wamid.A1'), self::text('wamid.A2', 'Second')]));

        $this->assertSame(2, $DB->count_records('local_jedomi_whatsapp_inbox'));
        $this->assertSame(['Hello', 'Second'], array_map(fn(inbound $m) => $m->text, $this->processor->messages));
    }

    /**
     * A message without BSUID nor phone is stored as failed, without payload, and never processed nor rescued.
     */
    public function test_a_message_without_sender_is_stored_as_failed(): void {
        global $DB;
        $this->setup_channel();
        $message = self::text('wamid.A1');
        unset($message['from_user_id']);

        $this->post(self::body([$message], [['profile' => ['name' => 'Ana']]]));

        $row = $DB->get_record('local_jedomi_whatsapp_inbox', ['wamid' => 'wamid.A1'], '*', MUST_EXIST);
        $this->assertSame(inbox_processor::STATUS_FAILED, $row->status);
        $this->assertSame('', $row->bsuid);
        $this->assertSame('', (string) $row->payload);
        $this->assertSame([], $this->processor->messages);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class));
        $this->assertSame(200, $this->responder->single()['status']);
    }

    /**
     * A message with a phone but no BSUID is valid: it is stored with an empty BSUID and processed.
     */
    public function test_a_message_with_only_a_phone_is_processed(): void {
        global $DB;
        $this->setup_channel();
        $message = self::text('wamid.A1');
        unset($message['from_user_id']);
        $message['from'] = '16505551234';

        $this->post(self::body([$message]));

        $this->assertSame(
            inbox_processor::STATUS_DONE,
            $DB->get_field('local_jedomi_whatsapp_inbox', 'status', ['wamid' => 'wamid.A1'])
        );
        $this->assertSame('16505551234', $this->processor->messages[0]->waid);
        $this->assertSame('', $this->processor->messages[0]->bsuid);
    }

    /**
     * Statuses go to the status sink, never to the inbox; a failed one without contacts and one sent to a phone
     * without recipient_user_id are both read.
     */
    public function test_statuses_go_to_the_sink_and_not_to_the_inbox(): void {
        global $DB;
        $this->setup_channel();
        $statuses = [
            ['id' => 'wamid.S1', 'status' => 'failed', 'timestamp' => '1777000100',
                'recipient_user_id' => self::BSUID, 'errors' => [['code' => 131047]]],
            ['id' => 'wamid.S2', 'status' => 'delivered', 'timestamp' => '1777000100', 'recipient_id' => '16505551234'],
        ];

        $this->post(self::body([], [], $statuses));

        $this->assertSame(200, $this->responder->single()['status']);
        $this->assertSame(0, $DB->count_records('local_jedomi_whatsapp_inbox'));
        $this->assertCount(2, $this->sink->statuses);
        $this->assertSame([131047], $this->sink->statuses[0]->errorcodes);
        $this->assertSame(self::BSUID, $this->sink->statuses[0]->recipientbsuid);
        $this->assertSame('16505551234', $this->sink->statuses[1]->recipientwaid);
        $this->assertSame([], $this->processor->messages);
    }

    /**
     * An internal error after the rows are saved is not returned to Meta: the answer is 200 and the rescue stays.
     */
    public function test_an_error_after_saving_still_answers_200(): void {
        global $DB;
        $this->setup_channel();
        $this->sink->fail = true;
        $this->processor->failures = 1;
        $statuses = [['id' => 'wamid.S1', 'status' => 'sent', 'recipient_user_id' => self::BSUID]];

        $this->post(self::body([self::text('wamid.A1')], [], $statuses));

        $this->assertSame(200, $this->responder->single()['status']);
        $row = $DB->get_record('local_jedomi_whatsapp_inbox', ['wamid' => 'wamid.A1'], '*', MUST_EXIST);
        $this->assertSame(inbox_processor::STATUS_PENDING, $row->status);
        $this->assertNotSame('', (string) $row->payload);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class));
    }

    /**
     * When the rescue cannot be queued, the row is not kept either and Meta gets 500 to deliver the message again.
     *
     * @dataProvider mode_provider
     * @param string $mode Processing mode.
     */
    public function test_a_rescue_that_cannot_be_queued_leaves_no_row_and_answers_500(string $mode): void {
        global $DB;
        $this->setup_channel($mode);
        $intake = new class extends intake {
            /**
             * Fail as an unavailable task queue would.
             *
             * @param int $inboxid Row id.
             */
            protected function queue_rescue(int $inboxid): void {
                throw new \coding_exception('Queue unavailable');
            }
        };
        $body = self::body([self::text('wamid.A1')]);

        $intake->handle('POST', [], $body, self::sign($body));

        $this->assertSame(500, $this->responder->single()['status']);
        $this->assertSame(0, $DB->count_records('local_jedomi_whatsapp_inbox'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class));
        $this->assertSame([], $this->processor->messages);
    }

    /**
     * Processing modes.
     *
     * @return array
     */
    public static function mode_provider(): array {
        return ['inline' => [manager::PROCESSING_INLINE], 'queue' => [manager::PROCESSING_QUEUE]];
    }

    /**
     * A retry of Meta for a row that is still recoverable but lost its rescue queues the rescue again, once, without a
     * new row; in inline mode the row is processed in the same request.
     *
     * @dataProvider recoverable_duplicate_provider
     * @param string $mode Processing mode.
     * @param string $status Status of the stored row.
     * @param int $age Seconds since the row was claimed.
     * @param bool $processed Whether the row is processed inline.
     */
    public function test_a_duplicate_of_an_orphaned_row_queues_its_rescue(
        string $mode,
        string $status,
        int $age,
        bool $processed
    ): void {
        global $DB;
        $this->setup_channel(manager::PROCESSING_QUEUE);
        $body = self::body([self::text('wamid.A1')]);
        $this->post($body);
        $id = (int) $DB->get_field('local_jedomi_whatsapp_inbox', 'id', ['wamid' => 'wamid.A1']);
        $DB->update_record('local_jedomi_whatsapp_inbox', (object) ['id' => $id, 'status' => $status,
            'timeprocessed' => $status === inbox_processor::STATUS_PENDING ? 0 : self::NOW - $age]);
        $DB->delete_records('task_adhoc');
        manager::set_processing_mode($mode);
        $this->responder->responses = [];

        $this->post($body);
        $this->post($body);

        $this->assertSame([200, 200], array_column($this->responder->responses, 'status'));
        $this->assertSame(1, $DB->count_records('local_jedomi_whatsapp_inbox'));
        $tasks = \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class);
        $this->assertCount(1, $tasks);
        $this->assertSame($id, (int) reset($tasks)->get_custom_data()->inboxid);
        $this->assertCount($processed ? 1 : 0, $this->processor->messages);
        $this->assertSame(
            $processed ? inbox_processor::STATUS_DONE : $status,
            $DB->get_field('local_jedomi_whatsapp_inbox', 'status', ['id' => $id])
        );
    }

    /**
     * Recoverable rows whose rescue was lost.
     *
     * @return array
     */
    public static function recoverable_duplicate_provider(): array {
        $stale = inbox_processor::LOCK_TTL + 1;
        return [
            'pending, inline' => [manager::PROCESSING_INLINE, inbox_processor::STATUS_PENDING, 0, true],
            'pending, queue' => [manager::PROCESSING_QUEUE, inbox_processor::STATUS_PENDING, 0, false],
            'stale processing, inline' => [manager::PROCESSING_INLINE, inbox_processor::STATUS_PROCESSING, $stale, true],
            'stale processing, queue' => [manager::PROCESSING_QUEUE, inbox_processor::STATUS_PROCESSING, $stale, false],
        ];
    }

    /**
     * A retry of Meta for a row that is finished or claimed right now queues nothing and processes nothing.
     *
     * @dataProvider settled_duplicate_provider
     * @param string $status Status of the stored row.
     */
    public function test_a_duplicate_of_a_settled_row_queues_nothing(string $status): void {
        global $DB;
        $this->setup_channel(manager::PROCESSING_QUEUE);
        $body = self::body([self::text('wamid.A1')]);
        $this->post($body);
        $DB->set_field('local_jedomi_whatsapp_inbox', 'status', $status, ['wamid' => 'wamid.A1']);
        $DB->set_field('local_jedomi_whatsapp_inbox', 'timeprocessed', self::NOW, ['wamid' => 'wamid.A1']);
        $DB->delete_records('task_adhoc');
        manager::set_processing_mode(manager::PROCESSING_INLINE);
        $this->responder->responses = [];

        $this->post($body);

        $this->assertSame(200, $this->responder->single()['status']);
        $this->assertSame(1, $DB->count_records('local_jedomi_whatsapp_inbox'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(process_whatsapp_inbox::class));
        $this->assertSame([], $this->processor->messages);
    }

    /**
     * Rows that need no rescue.
     *
     * @return array
     */
    public static function settled_duplicate_provider(): array {
        return [
            'done' => [inbox_processor::STATUS_DONE],
            'failed' => [inbox_processor::STATUS_FAILED],
            'fresh processing' => [inbox_processor::STATUS_PROCESSING],
        ];
    }

    /**
     * A signed body that is not a webhook of WhatsApp is answered with 200 and ignored, so Meta does not retry it.
     *
     * @dataProvider ignored_body_provider
     * @param string $body Raw body.
     */
    public function test_a_signed_body_without_messages_is_ignored(string $body): void {
        global $DB;
        $this->setup_channel();

        $this->post($body);

        $this->assertSame(200, $this->responder->single()['status']);
        $this->assertSame(0, $DB->count_records('local_jedomi_whatsapp_inbox'));
    }

    /**
     * Bodies without messages.
     *
     * @return array
     */
    public static function ignored_body_provider(): array {
        return [
            'not json' => ['not json'],
            'other object' => [json_encode(['object' => 'page', 'entry' => []])],
            'other field' => [json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [
                ['field' => 'account_update', 'value' => ['messages' => [['id' => 'wamid.X', 'from_user_id' => 'US.1']]]],
            ]]]])],
            'message without id' => [json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [
                ['field' => 'messages', 'value' => ['messages' => [['from_user_id' => 'US.1', 'type' => 'text']]]],
            ]]]])],
        ];
    }

    /**
     * While the channel is disabled, a signed POST is acknowledged and nothing is stored.
     */
    public function test_a_disabled_channel_stores_nothing(): void {
        global $DB;
        $this->setup_channel();
        manager::set_enabled(false);

        $this->post(self::body([self::text('wamid.A1')]));

        $this->assertSame(200, $this->responder->single()['status']);
        $this->assertSame(0, $DB->count_records('local_jedomi_whatsapp_inbox'));
    }

    /**
     * The entry point delegates to the intake and reads the raw body before anything decodes it.
     */
    public function test_the_entry_point_hands_the_raw_body_to_the_intake(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/jedomi/whatsapp_webhook.php');

        $this->assertStringContainsString("file_get_contents('php://input')", $source);
        $this->assertStringContainsString('HTTP_X_HUB_SIGNATURE_256', $source);
        $this->assertStringContainsString('->handle(', $source);
        $this->assertStringNotContainsString('json_decode', $source);
    }
}
