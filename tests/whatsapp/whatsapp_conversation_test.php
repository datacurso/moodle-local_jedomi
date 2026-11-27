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

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\local\secret_store;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\fake_plugin_state;
use local_jedomi\messaging\plugin_state;
use local_jedomi\provider_absence_probe;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_cloud_api_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');

/**
 * End-to-end tests of a WhatsApp conversation with doubles: a message stored in the inbox is processed by the default
 * pieces (inbox_processor, chat_processor and the WhatsApp conversation handler), runs the router and the conversation
 * engine with the WhatsApp channel, and the answer goes back through the fake Cloud API client. The AI is
 * fake_ai_client; nothing reaches Meta, Telegram or the Datacurso AI provider.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\whatsapp_conversation_handler
 * @covers     \local_jedomi\whatsapp\whatsapp_channel
 * @covers     \local_jedomi\whatsapp\whatsapp_commands
 * @covers     \local_jedomi\whatsapp\whatsapp_transport
 * @covers     \local_jedomi\whatsapp\usage_meter
 * @covers     \local_jedomi\whatsapp\chat_processor
 */
final class whatsapp_conversation_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Phone of the student. */
    private const WAID = '16505551234';

    /** @var int Frozen time of the tests. */
    private const NOW = 1800000000;

    /** @var fake_cloud_api_client Client double. */
    private fake_cloud_api_client $client;

    /** @var fake_ai_client AI double. */
    private fake_ai_client $ai;

    /** @var int Messages delivered, for unique wamids. */
    private int $count = 0;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::NOW);
        provider_absence_probe::start();
        set_config('whatsapp_enabled', 1, 'local_jedomi');
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $this->client = new fake_cloud_api_client();
        cloud_api_client::set_instance_for_testing($this->client);
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
    }

    #[\Override]
    protected function assertPostConditions(): void {
        parent::assertPostConditions();
        $this->assertSame([], provider_absence_probe::touched(), 'The test reached the Datacurso AI provider.');
    }

    #[\Override]
    protected function tearDown(): void {
        cloud_api_client::set_instance_for_testing(null);
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * A linked student.
     *
     * @param string|null $waid Phone of the link; null when Meta did not share it.
     * @param array $fields Fields of the link that replace the default ones.
     * @param int|null $userid Existing user; a new one when null.
     * @return int User id.
     */
    private function student(?string $waid = self::WAID, array $fields = [], ?int $userid = null): int {
        global $DB;
        $userid = $userid ?? (int) $this->getDataGenerator()->create_user(['firstname' => 'Ada'])->id;
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) array_merge(
            ['userid' => $userid,
            'bsuid' => self::BSUID, 'waid' => $waid, 'phone' => $waid ? '+' . $waid : null, 'confirmed' => 1,
            'consentat' => self::NOW - DAYSECS, 'consentversion' => consent::VERSION, 'consentcategories' => 'chat,grades',
            'lastused' => self::NOW - HOURSECS, 'lastinbound' => self::NOW - HOURSECS, 'timecreated' => self::NOW - DAYSECS],
            $fields
        ));
        return $userid;
    }

    /**
     * Deliver a message from the student as the webhook stores it, and process it with the default pieces.
     *
     * @param array $content Type-specific fields of the message, e.g. ['type' => 'text', 'text' => ['body' => 'Hi']].
     * @param bool $withphone Whether Meta shares the phone of the sender.
     * @return string Wamid of the message.
     */
    private function deliver(array $content, bool $withphone = true): string {
        global $DB;
        $this->count++;
        $wamid = 'wamid.E2E' . $this->count;
        $message = ['from_user_id' => self::BSUID, 'id' => $wamid, 'timestamp' => (string) self::NOW] + $content;
        if ($withphone) {
            $message['from'] = self::WAID;
        }
        $id = $DB->insert_record(whatsapp_link_service::INBOX_TABLE, (object) [
            'wamid' => $wamid,
            'bsuid' => self::BSUID,
            'payload' => secret_store::encrypt(json_encode(['message' => $message, 'contact' => null])),
            'status' => inbox_processor::STATUS_PENDING,
            'attempts' => 0,
            'timecreated' => self::NOW,
            'timeprocessed' => 0,
        ]);
        $limit = (int) ini_get('max_execution_time');
        $result = (new inbox_processor())->process((int) $id);
        set_time_limit($limit);
        $this->assertSame(inbox_processor::RESULT_DONE, $result);
        return $wamid;
    }

    /**
     * Deliver a text message.
     *
     * @param string $text Text.
     * @param bool $withphone Whether Meta shares the phone of the sender.
     * @return string Wamid.
     */
    private function say(string $text, bool $withphone = true): string {
        return $this->deliver(['type' => 'text', 'text' => ['body' => $text]], $withphone);
    }

    /**
     * Texts sent, in order, with the fields of their body that address the recipient.
     *
     * @return array<int, array{payload: array, text: string}>
     */
    private function sent(): array {
        return array_map(
            static fn(array $call): array => ['payload' => $call['args'][0]->to_payload(), 'text' => $call['args'][1]],
            $this->client->calls_to('send_text')
        );
    }

    /**
     * A usage counter of the month.
     *
     * @param int $userid User id.
     * @param string $counter Column.
     * @return int
     */
    private static function usage(int $userid, string $counter): int {
        global $DB;
        return (int) $DB->get_field(
            whatsapp_link_service::USAGE_TABLE,
            $counter,
            ['userid' => $userid, 'period' => (int) date('Ym', self::NOW)]
        );
    }

    /**
     * A question of a student linked with phone is answered end to end: the message is marked read with the typing
     * indicator, the answer goes only to the phone ("to"), formatted for WhatsApp, the turn is kept under the chat key
     * of the BSUID, the answer is recorded for the whatsapp channel and the month counts one message each way.
     */
    public function test_a_question_is_answered_to_the_phone_only(): void {
        global $DB;
        $userid = $this->student();
        $this->ai->enqueue_text('Hola **Ada**, tiene 2 cursos.');
        $sink = $this->redirectEvents();

        $wamid = $this->say('¿Cuántos cursos tengo?');

        $this->assertSame([['payload' => ['to' => self::WAID], 'text' => 'Hola *Ada*, tiene 2 cursos.']], $this->sent());
        $typing = $this->client->calls_to('mark_read_typing');
        $this->assertNotEmpty($typing);
        $this->assertSame([$wamid, null], $typing[0]['args'], 'The first activity uses the usual timeout');

        $chatkey = 'wa_' . self::BSUID;
        $this->assertSame([
            ['role' => 'user', 'content' => '¿Cuántos cursos tengo?'],
            ['role' => 'assistant', 'content' => 'Hola **Ada**, tiene 2 cursos.'],
        ], (new whatsapp_channel())->store()->get($chatkey));
        $this->assertTrue($DB->record_exists(whatsapp_link_service::SESSIONS_TABLE, ['chatid' => $chatkey]));

        $system = $this->ai->calls[0]['messages'][0]['content'];
        $this->assertStringContainsString('WHATSAPP', $system);
        $this->assertSame($userid, $this->ai->calls[0]['userid']);
        $toolnames = array_map(static fn(array $tool): string => $tool['function']['name'], $this->ai->calls[0]['tools']);
        $this->assertSame([], array_diff($toolnames, whatsapp_limits::SUPPORTED_TOOLS));

        $answered = array_values(array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof \local_jedomi\event\chat_answered
        ));
        $this->assertCount(1, $answered);
        $this->assertSame('whatsapp', $answered[0]->other['channel']);

        $this->assertSame(1, self::usage($userid, 'inbound'));
        $this->assertSame(1, self::usage($userid, 'outservice'));
    }

    /**
     * Without a phone the answer goes only to the BSUID ("recipient").
     */
    public function test_without_phone_the_answer_goes_to_the_bsuid_only(): void {
        $this->student(null);
        $this->ai->enqueue_text('Hola.');

        $this->say('Hola', false);

        $this->assertSame([['payload' => ['recipient' => self::BSUID], 'text' => 'Hola.']], $this->sent());
    }

    /**
     * NUEVO starts a new conversation: the history is cleared and the AI greets the student by name.
     */
    public function test_nuevo_starts_a_new_conversation(): void {
        $this->student();
        $chatkey = 'wa_' . self::BSUID;
        $store = (new whatsapp_channel())->store();
        $store->append($chatkey, 'user', 'pregunta vieja');
        $store->append($chatkey, 'assistant', 'respuesta vieja');
        $this->ai->enqueue_text('¡Hola, Ada!');

        $this->say('nuevo');

        $this->assertCount(1, $this->ai->calls);
        $contents = array_column($this->ai->calls[0]['messages'], 'content');
        $this->assertNotContains('pregunta vieja', $contents);
        $this->assertSame(
            get_string('telegram_new_conversation_greeting', 'local_jedomi', 'Ada'),
            $store->get($chatkey)[0]['content']
        );
        $this->assertCount(2, $store->get($chatkey));
        $this->assertSame(['¡Hola, Ada!'], array_column($this->sent(), 'text'));
    }

    /**
     * Messages over the limit per minute get the rate limit message and never reach the AI.
     */
    public function test_messages_over_the_rate_limit_do_not_reach_the_ai(): void {
        set_config('whatsapp_rate_limit', 1, 'local_jedomi');
        set_config('whatsapp_cooldown', 3, 'local_jedomi');
        $userid = $this->student();
        $this->ai->enqueue_text('Respuesta.');

        $this->say('Uno');
        $this->say('Dos');

        $this->assertCount(1, $this->ai->calls);
        $this->assertSame(
            ['Respuesta.', get_string('whatsapp_msg_rate_limited', 'local_jedomi', ['minutes' => 3])],
            array_column($this->sent(), 'text')
        );
        $this->assertSame(2, self::usage($userid, 'inbound'));
    }

    /**
     * While another process holds the chat of the BSUID, a message gets the still processing message and is not run.
     */
    public function test_a_busy_chat_gets_the_still_processing_message(): void {
        global $CFG;
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $this->student();
        $lock = whatsapp_conversation_handler::acquire_chat_lock('wa_' . self::BSUID);
        $this->assertInstanceOf(\core\lock\lock::class, $lock);
        try {
            $this->say('Hola');
        } finally {
            $lock->release();
        }

        $this->assertSame([], $this->ai->calls);
        $this->assertSame([get_string('whatsapp_msg_still_processing', 'local_jedomi')], array_column($this->sent(), 'text'));
    }

    /**
     * The chat lock is per BSUID and free again once released.
     */
    public function test_the_chat_lock_is_per_bsuid(): void {
        global $CFG;
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $taken = [];
        try {
            $taken[] = $first = whatsapp_conversation_handler::acquire_chat_lock('wa_US.1');
            $this->assertInstanceOf(\core\lock\lock::class, $first);
            $taken[] = $second = whatsapp_conversation_handler::acquire_chat_lock('wa_US.1');
            $this->assertFalse($second);
            $taken[] = $other = whatsapp_conversation_handler::acquire_chat_lock('wa_US.2');
            $this->assertInstanceOf(\core\lock\lock::class, $other);
            $first->release();
            $taken[] = $again = whatsapp_conversation_handler::acquire_chat_lock('wa_US.1');
            $this->assertInstanceOf(\core\lock\lock::class, $again);
        } finally {
            foreach ($taken as $held) {
                if ($held instanceof \core\lock\lock) {
                    $held->release();
                }
            }
        }
    }

    /**
     * Media is not read yet: an image gets the text only message, is not downloaded and does not reach the AI; it
     * counts as received media.
     */
    public function test_media_gets_the_text_only_message(): void {
        $userid = $this->student();

        $this->deliver(['type' => 'image', 'image' => ['id' => '1234567890', 'mime_type' => 'image/jpeg',
            'caption' => 'Mire esto']]);

        $this->assertSame([], $this->ai->calls);
        $this->assertSame([], $this->client->calls_to('get_media_url'));
        $this->assertSame([get_string('whatsapp_msg_text_only', 'local_jedomi')], array_column($this->sent(), 'text'));
        $this->assertSame(1, self::usage($userid, 'media'));
    }

    /**
     * A student who did not accept the chat category gets a notice and the question does not reach the AI.
     */
    public function test_a_student_without_chat_consent_gets_a_notice(): void {
        $this->student(self::WAID, ['consentcategories' => consent::GRADES]);

        $this->say('Hola');

        $this->assertSame([], $this->ai->calls);
        $this->assertSame([get_string('whatsapp_msg_chat_not_accepted', 'local_jedomi')], array_column($this->sent(), 'text'));
    }

    /**
     * NO cancels the held action of the chat; SÍ answers it as the confirm button does. Neither reaches the AI.
     */
    public function test_si_and_no_answer_a_held_action(): void {
        global $DB;
        $userid = $this->student();
        $channel = new whatsapp_channel();
        $chatkey = 'wa_' . self::BSUID;
        $args = ['name' => 'Digest', 'prompt' => 'Summarise', 'schedule_cron' => '0 8 * * 1'];

        $channel->confirmation_service()->hold($chatkey, $userid, 'create_agent', $args);
        $this->assertSame(1, $DB->count_records(whatsapp_link_service::PENDING_TABLE));
        $this->say('NO');
        $this->assertSame(0, $DB->count_records(whatsapp_link_service::PENDING_TABLE));
        $texts = array_column($this->sent(), 'text');
        $this->assertSame(get_string('confirm_cancelled', 'local_jedomi'), end($texts));

        $channel->confirmation_service()->hold($chatkey, $userid, 'create_agent', $args);
        $this->say('Sí');
        $this->assertSame(0, $DB->count_records(whatsapp_link_service::PENDING_TABLE));
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * Without a held action, NO is a question for the AI.
     */
    public function test_no_without_a_held_action_is_a_question(): void {
        $this->student();
        $this->ai->enqueue_text('Entendido.');

        $this->say('No');

        $this->assertCount(1, $this->ai->calls);
    }

    /**
     * A write tool is never run in WhatsApp version 1, even for an administrator: it is not offered, so a call of the
     * AI is denied, nothing is held, and only the answer is sent (no tool progress line).
     */
    public function test_a_write_tool_is_not_run_on_whatsapp(): void {
        global $DB;
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $this->student(self::WAID, [], (int) get_admin()->id);
        $this->ai->enqueue_tool_calls([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'create_agent',
            'arguments' => json_encode(['name' => 'Digest', 'prompt' => 'Summarise', 'schedule_cron' => '0 8 * * 1'])]]]);
        $this->ai->enqueue_text('Hágalo en el chat web.');

        $this->say('Cree un agente');

        $this->assertSame(0, $DB->count_records(whatsapp_link_service::PENDING_TABLE));
        $this->assertCount(2, $this->ai->calls);
        $toolresults = array_values(array_filter(
            $this->ai->calls[1]['messages'],
            static fn(array $message): bool => $message['role'] === 'tool'
        ));
        $this->assertStringContainsString('permission_denied', $toolresults[0]['content']);
        $this->assertSame(['Hágalo en el chat web.'], array_column($this->sent(), 'text'));
    }

    /**
     * A student's read tool runs, and WhatsApp sends no tool progress line: only the answer reaches the student.
     */
    public function test_a_read_tool_runs_without_progress_messages(): void {
        $this->student();
        $this->ai->enqueue_tool_calls([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'my_courses',
            'arguments' => '{}']]]);
        $this->ai->enqueue_text('No tienes cursos.');

        $this->say('¿Qué cursos tengo?');

        $this->assertCount(2, $this->ai->calls);
        $toolresults = array_values(array_filter(
            $this->ai->calls[1]['messages'],
            static fn(array $message): bool => $message['role'] === 'tool'
        ));
        $this->assertStringNotContainsString('permission_denied', $toolresults[0]['content']);
        $this->assertSame(['No tienes cursos.'], array_column($this->sent(), 'text'));
    }

    /**
     * The real conversation handler is the default one of the chat_processor.
     */
    public function test_the_chat_processor_uses_the_whatsapp_conversation_by_default(): void {
        $this->student();
        $this->ai->enqueue_text('Hola.');

        $this->say('Hola');

        $this->assertCount(1, $this->ai->calls);
    }
}
