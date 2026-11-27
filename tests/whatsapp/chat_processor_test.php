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

use local_jedomi\event\whatsapp_linked;
use local_jedomi\event\whatsapp_opted_in;
use local_jedomi\event\whatsapp_opted_out;
use local_jedomi\event\whatsapp_unlinked;
use local_jedomi\local\secret_store;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\fake_plugin_state;
use local_jedomi\messaging\plugin_state;
use local_jedomi\telegram\link_code_test_clock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_cloud_api_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/link_code_test_clock.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/recording_conversation_handler.php');

/**
 * Tests for the processing of a WhatsApp message: linking, consent, BAJA/ALTA/DESVINCULAR, help and the hand-off to the
 * conversation, keyed by the BSUID with the phone as fallback.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\chat_processor
 * @covers     \local_jedomi\whatsapp\cloud_api_replier
 * @covers     \local_jedomi\whatsapp\inbox_processor
 */
final class chat_processor_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Another BSUID. */
    private const OTHER = 'CO.99999999999999999999';

    /** @var string Phone of the student. */
    private const WAID = '16505551234';

    /** @var int Frozen time of the tests. */
    private const NOW = 1800000000;

    /** @var fake_cloud_api_client Client double. */
    private fake_cloud_api_client $client;

    /** @var recording_conversation_handler Conversation double. */
    private recording_conversation_handler $conversation;

    /** @var int Messages built, for unique wamids. */
    private int $count = 0;

    /**
     * Enable the channel and Jedomi, freeze the clock and register the doubles.
     */
    private function setup_channel(): void {
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::NOW);
        set_config('whatsapp_enabled', 1, 'local_jedomi');
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $this->client = new fake_cloud_api_client();
        cloud_api_client::set_instance_for_testing($this->client);
        $this->conversation = new recording_conversation_handler();
        \core\di::set(conversation_handler::class, $this->conversation);
    }

    /**
     * Restore the client and the cache clock.
     */
    protected function tearDown(): void {
        cloud_api_client::set_instance_for_testing(null);
        link_code_test_clock::reset();
        parent::tearDown();
    }

    /**
     * A text message.
     *
     * @param string $text Text.
     * @param string $bsuid BSUID of the sender; empty for none.
     * @param string|null $waid Phone of the sender; null when Meta did not share it.
     * @return inbound
     */
    private function text(string $text, string $bsuid = self::BSUID, ?string $waid = self::WAID): inbound {
        $this->count++;
        return new inbound('wamid.T' . $this->count, $bsuid, $waid, 'text', $text, null, null, '', '', '', null, self::NOW);
    }

    /**
     * Process a message with the default processor.
     *
     * @param inbound $message Message.
     */
    private static function process(inbound $message): void {
        (new chat_processor())->process($message);
    }

    /**
     * Generate a link code with consent to both categories.
     *
     * @param int $userid User id.
     * @return string
     */
    private static function code(int $userid): string {
        return link_codes::generate($userid, new consent(consent::VERSION, consent::CATEGORIES, self::NOW - 60));
    }

    /**
     * Insert a link.
     *
     * @param int $userid User id.
     * @param string $bsuid BSUID.
     * @param string|null $waid Phone.
     * @param array $fields Other fields.
     * @return \stdClass
     */
    private static function insert_link(
        int $userid,
        string $bsuid = self::BSUID,
        ?string $waid = self::WAID,
        array $fields = []
    ): \stdClass {
        global $DB;
        $record = (object) array_merge(['userid' => $userid, 'bsuid' => $bsuid, 'waid' => $waid,
            'phone' => $waid ? '+' . $waid : null, 'confirmed' => 1, 'consentat' => self::NOW - DAYSECS,
            'consentversion' => consent::VERSION, 'consentcategories' => 'chat,grades', 'lastused' => self::NOW - HOURSECS,
            'lastinbound' => self::NOW - HOURSECS, 'timecreated' => self::NOW - DAYSECS], $fields);
        $record->id = $DB->insert_record(whatsapp_link_service::LINKS_TABLE, $record);
        return $record;
    }

    /**
     * The link of a user, or null.
     *
     * @param int $userid User id.
     * @return \stdClass|null
     */
    private static function link_of(int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record(whatsapp_link_service::LINKS_TABLE, ['userid' => $userid]) ?: null;
    }

    /**
     * Texts sent, in order, with their recipient fields.
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
     * A link code sent from WhatsApp links the BSUID and the phone to the account with the consent of the code, records
     * the inbound time, triggers whatsapp_linked and answers once; the code cannot be used again.
     */
    public function test_link_code_links_the_identity_with_its_consent(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $code = self::code((int) $user->id);

        $sink = $this->redirectEvents();
        self::process($this->text('JEDOMI ' . $code));
        $events = $sink->get_events();
        $sink->close();

        $link = self::link_of((int) $user->id);
        $this->assertNotNull($link);
        $this->assertSame(self::BSUID, $link->bsuid);
        $this->assertSame(self::WAID, $link->waid);
        $this->assertSame('+' . self::WAID, $link->phone);
        $this->assertSame(1, (int) $link->confirmed);
        $this->assertSame(self::NOW - 60, (int) $link->consentat);
        $this->assertSame(consent::VERSION, $link->consentversion);
        $this->assertSame('chat,grades', $link->consentcategories);
        $this->assertSame(0, (int) $link->optedout);
        $this->assertSame(self::NOW, (int) $link->lastinbound);
        $this->assertSame(self::NOW, (int) $link->lastused);
        $this->assertSame(self::NOW, (int) $link->timecreated);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(whatsapp_linked::class, $events[0]);
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(['to' => self::WAID], $sent[0]['payload']);
        $this->assertStringContainsString('Ada Lovelace', $sent[0]['text']);
        $this->assertSame([], $this->conversation->received, 'The code never reaches the conversation');
        $this->assertNull(link_codes::resolve($code), 'The code is used once');
    }

    /**
     * A bare code in lower case links too.
     */
    public function test_bare_code_in_lower_case_links(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();

        self::process($this->text(strtolower(self::code((int) $user->id))));

        $this->assertSame(self::BSUID, self::link_of((int) $user->id)->bsuid);
    }

    /**
     * A student who hides the phone links by BSUID alone; the answer goes to the BSUID as recipient.
     */
    public function test_link_without_phone(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();

        self::process($this->text('JEDOMI ' . self::code((int) $user->id), self::BSUID, null));

        $link = self::link_of((int) $user->id);
        $this->assertSame(self::BSUID, $link->bsuid);
        $this->assertNull($link->waid);
        $this->assertNull($link->phone);
        $this->assertSame([['recipient' => self::BSUID]], array_column($this->sent(), 'payload'));
    }

    /**
     * An unknown, expired or already used code links nothing and says the code is not valid.
     */
    public function test_invalid_or_expired_code_links_nothing(): void {
        global $DB;
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        $start = (float) time();
        link_code_test_clock::set_now($start);
        $code = self::code((int) $user->id);

        self::process($this->text('JEDOMI ABC234'));
        link_code_test_clock::set_now($start + link_codes::TTL + 1);
        self::process($this->text('JEDOMI ' . $code));

        $this->assertSame(0, $DB->count_records(whatsapp_link_service::LINKS_TABLE));
        $expected = get_string('whatsapp_msg_expired_code', 'local_jedomi');
        $this->assertSame([$expected, $expected], array_column($this->sent(), 'text'));
    }

    /**
     * The code of a suspended account links nothing.
     */
    public function test_code_of_a_suspended_account_links_nothing(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        $code = self::code((int) $user->id);
        $user->suspended = 1;
        user_update_user($user, false);

        self::process($this->text('JEDOMI ' . $code));

        $this->assertNull(self::link_of((int) $user->id));
        $this->assertSame([get_string('whatsapp_msg_expired_code', 'local_jedomi')], array_column($this->sent(), 'text'));
    }

    /**
     * A message without BSUID cannot be linked: the BSUID is the key of the link. The code stays valid.
     */
    public function test_code_without_bsuid_links_nothing(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        $code = self::code((int) $user->id);

        self::process($this->text('JEDOMI ' . $code, '', self::WAID));

        $this->assertNull(self::link_of((int) $user->id));
        $this->assertNotNull(link_codes::resolve($code));
        $this->assertSame([['to' => self::WAID]], array_column($this->sent(), 'payload'));
    }

    /**
     * One account per identity: a code of another account moves the identity, and the earlier account loses its link
     * with the data of its chat.
     */
    public function test_relink_moves_the_identity_from_the_previous_owner(): void {
        global $DB;
        $this->setup_channel();
        $previous = $this->getDataGenerator()->create_user();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $previous->id);
        $DB->insert_record(whatsapp_link_service::SESSIONS_TABLE, (object) [
            'chatid' => whatsapp_link_service::chatkey(self::BSUID), 'messages' => '["old"]', 'lastactivity' => self::NOW]);

        self::process($this->text('JEDOMI ' . self::code((int) $user->id)));

        $this->assertNull(self::link_of((int) $previous->id));
        $this->assertSame(self::BSUID, self::link_of((int) $user->id)->bsuid);
        $this->assertFalse($DB->record_exists(whatsapp_link_service::SESSIONS_TABLE, []));
    }

    /**
     * One identity per account: an account linked to one identity that sends a code from another keeps only the new
     * one, and the data of the old chat is removed.
     */
    public function test_relink_from_another_identity_replaces_the_link(): void {
        global $DB;
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::OTHER, '15550000000');

        self::process($this->text('JEDOMI ' . self::code((int) $user->id)));

        $this->assertSame(1, $DB->count_records(whatsapp_link_service::LINKS_TABLE));
        $link = self::link_of((int) $user->id);
        $this->assertSame(self::BSUID, $link->bsuid);
        $this->assertSame(self::WAID, $link->waid);
    }

    /**
     * A linked identity that sends a new code renews the consent and starts receiving messages again.
     */
    public function test_relink_of_the_same_identity_renews_the_consent(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::BSUID, self::WAID, ['optedout' => 1, 'optoutat' => self::NOW - 10,
            'consentcategories' => 'grades', 'consentat' => 1]);

        self::process($this->text('JEDOMI ' . self::code((int) $user->id)));

        $link = self::link_of((int) $user->id);
        $this->assertSame(0, (int) $link->optedout);
        $this->assertSame('chat,grades', $link->consentcategories);
        $this->assertSame(self::NOW - 60, (int) $link->consentat);
    }

    /**
     * An identity that is not linked gets the linking instructions only: no conversation, no data.
     */
    public function test_unlinked_identity_gets_the_instructions(): void {
        $this->setup_channel();

        self::process($this->text('What is my grade?'));
        self::process($this->text('AYUDA'));

        $expected = get_string('whatsapp_msg_link_first', 'local_jedomi');
        $this->assertSame([$expected, $expected], array_column($this->sent(), 'text'));
        $this->assertSame([], $this->conversation->received);
    }

    /**
     * The link is found by BSUID first: a phone of another link does not take over.
     */
    public function test_link_is_found_by_bsuid_first(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::BSUID, '15551110000');
        self::insert_link((int) $other->id, self::OTHER, self::WAID);

        self::process($this->text('Hello'));

        $this->assertCount(1, $this->conversation->received);
        $this->assertSame((int) $user->id, (int) $this->conversation->received[0]['user']->id);
        $this->assertSame('15551110000', self::link_of((int) $user->id)->waid, 'A known phone is not replaced');
        $this->assertSame(self::OTHER, self::link_of((int) $other->id)->bsuid);
    }

    /**
     * Without a link for the BSUID, the phone finds the link, which keeps the new BSUID with its chat.
     */
    public function test_phone_fallback_saves_the_bsuid(): void {
        global $DB;
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::OTHER, self::WAID);
        $DB->insert_record(whatsapp_link_service::SESSIONS_TABLE, (object) [
            'chatid' => whatsapp_link_service::chatkey(self::OTHER), 'messages' => '[]', 'lastactivity' => self::NOW]);

        self::process($this->text('Hello'));

        $this->assertSame(self::BSUID, self::link_of((int) $user->id)->bsuid);
        $this->assertTrue($DB->record_exists(
            whatsapp_link_service::SESSIONS_TABLE,
            ['chatid' => whatsapp_link_service::chatkey(self::BSUID)]
        ));
        $this->assertCount(1, $this->conversation->received);
        $this->assertSame(self::BSUID, $this->conversation->received[0]['link']->bsuid);
    }

    /**
     * A phone that the link did not have is filled; a later message without phone never clears it. Each message
     * records the inbound time.
     */
    public function test_missing_phone_is_filled_and_never_cleared(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::BSUID, null);

        self::process($this->text('Hello'));
        $this->assertSame(self::WAID, self::link_of((int) $user->id)->waid);
        $this->assertSame('+' . self::WAID, self::link_of((int) $user->id)->phone);

        self::process($this->text('Hello again', self::BSUID, null));
        $link = self::link_of((int) $user->id);
        $this->assertSame(self::WAID, $link->waid);
        $this->assertSame(self::NOW, (int) $link->lastinbound);
        $this->assertSame(self::NOW, (int) $link->lastused);
    }

    /**
     * The username of WhatsApp never links: a message from an unknown BSUID with the username of a linked student is
     * treated as unlinked, and the username is stored nowhere.
     */
    public function test_username_never_links(): void {
        global $DB;
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user(['username' => 'ada_wa']);
        self::insert_link((int) $user->id, self::OTHER, null);
        $payload = ['message' => ['from_user_id' => self::BSUID, 'id' => 'wamid.U1', 'type' => 'text',
            'text' => ['body' => 'Hello']], 'contact' => ['user_id' => self::BSUID, 'profile' => ['name' => 'Ada',
            'username' => 'ada_wa']]];
        $id = $DB->insert_record(whatsapp_link_service::INBOX_TABLE, (object) ['wamid' => 'wamid.U1',
            'bsuid' => self::BSUID, 'payload' => secret_store::encrypt(json_encode($payload)),
            'status' => inbox_processor::STATUS_PENDING, 'attempts' => 0, 'timecreated' => self::NOW, 'timeprocessed' => 0]);
        \core\di::set(message_processor::class, new chat_processor());

        (new inbox_processor())->process($id);

        $this->assertSame(self::OTHER, self::link_of((int) $user->id)->bsuid);
        $this->assertSame([], $this->conversation->received);
        $this->assertSame([get_string('whatsapp_msg_link_first', 'local_jedomi')], array_column($this->sent(), 'text'));
    }

    /**
     * Without a registered processor, the inbox uses this one; after user_changed_user_id with previous_user_id the
     * new BSUID reaches the same account.
     */
    public function test_inbox_uses_it_by_default_and_follows_an_identity_change(): void {
        global $DB;
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::OTHER, null);
        $rows = [
            ['from_user_id' => self::BSUID, 'id' => 'wamid.S1', 'type' => 'system',
                'system' => ['type' => 'user_changed_user_id', 'body' => 'changed', 'previous_user_id' => self::OTHER]],
            ['from_user_id' => self::BSUID, 'id' => 'wamid.S2', 'type' => 'text', 'text' => ['body' => 'Hello']],
        ];
        foreach ($rows as $message) {
            $id = $DB->insert_record(whatsapp_link_service::INBOX_TABLE, (object) ['wamid' => $message['id'],
                'bsuid' => self::BSUID, 'payload' => secret_store::encrypt(json_encode(['message' => $message])),
                'status' => inbox_processor::STATUS_PENDING, 'attempts' => 0, 'timecreated' => self::NOW,
                'timeprocessed' => 0]);
            $this->assertSame(inbox_processor::RESULT_DONE, (new inbox_processor())->process($id));
        }

        $this->assertSame(self::BSUID, self::link_of((int) $user->id)->bsuid);
        $this->assertCount(1, $this->conversation->received);
        $this->assertSame((int) $user->id, (int) $this->conversation->received[0]['user']->id);
    }

    /**
     * BAJA stops the messages with one confirmation and whatsapp_opted_out; a second BAJA and any other text get no
     * answer and do not reach the conversation.
     */
    public function test_baja_opts_out_with_one_confirmation(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);

        $sink = $this->redirectEvents();
        self::process($this->text('baja'));
        self::process($this->text('STOP'));
        self::process($this->text('What is my grade?'));
        self::process($this->text('AYUDA'));
        self::process($this->text('NUEVO'));
        $events = $sink->get_events();
        $sink->close();

        $link = self::link_of((int) $user->id);
        $this->assertSame(1, (int) $link->optedout);
        $this->assertSame(self::NOW, (int) $link->optoutat);
        $this->assertSame([whatsapp_opted_out::class], array_map('get_class', $events));
        $this->assertSame([get_string('whatsapp_msg_opted_out', 'local_jedomi')], array_column($this->sent(), 'text'));
        $this->assertSame([], $this->conversation->received);
    }

    /**
     * ALTA after BAJA receives the messages again, with whatsapp_opted_in and a confirmation.
     */
    public function test_alta_opts_back_in(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::BSUID, self::WAID, ['optedout' => 1, 'optoutat' => self::NOW - 10]);

        $sink = $this->redirectEvents();
        self::process($this->text('/START'));
        $events = $sink->get_events();
        $sink->close();
        self::process($this->text('Hello'));

        $this->assertSame(0, (int) self::link_of((int) $user->id)->optedout);
        $this->assertSame([whatsapp_opted_in::class], array_map('get_class', $events));
        $this->assertSame([get_string('whatsapp_msg_opted_in', 'local_jedomi')], array_column($this->sent(), 'text'));
        $this->assertCount(1, $this->conversation->received);
    }

    /**
     * DESVINCULAR removes the link with all the data of the channel and triggers whatsapp_unlinked.
     */
    public function test_desvincular_unlinks_with_all_the_data(): void {
        global $DB;
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);
        $DB->insert_record(whatsapp_link_service::SESSIONS_TABLE, (object) [
            'chatid' => whatsapp_link_service::chatkey(self::BSUID), 'messages' => '[]', 'lastactivity' => self::NOW]);

        $sink = $this->redirectEvents();
        self::process($this->text('Desvincular'));
        $events = $sink->get_events();
        $sink->close();

        $this->assertNull(self::link_of((int) $user->id));
        $this->assertFalse($DB->record_exists(whatsapp_link_service::SESSIONS_TABLE, []));
        $this->assertSame([whatsapp_unlinked::class], array_map('get_class', $events));
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
        $this->assertSame([get_string('whatsapp_msg_unlinked', 'local_jedomi')], array_column($this->sent(), 'text'));
    }

    /**
     * A student who stopped the messages can still unlink, and the command is confirmed.
     */
    public function test_opted_out_student_can_unlink(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::BSUID, self::WAID, ['optedout' => 1, 'optoutat' => self::NOW - 10]);

        self::process($this->text('/desvincular'));

        $this->assertNull(self::link_of((int) $user->id));
        $this->assertSame([get_string('whatsapp_msg_unlinked', 'local_jedomi')], array_column($this->sent(), 'text'));
    }

    /**
     * AYUDA answers with the help; NUEVO and any other text go to the conversation with the link and the account.
     */
    public function test_help_answers_and_other_texts_go_to_the_conversation(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);

        self::process($this->text('ayuda'));
        self::process($this->text('NUEVO'));
        self::process($this->text('What is my grade?'));

        $this->assertSame([get_string('whatsapp_help', 'local_jedomi')], array_column($this->sent(), 'text'));
        $this->assertSame(
            ['NUEVO', 'What is my grade?'],
            array_map(static fn(array $r): string => $r['message']->text, $this->conversation->received)
        );
        $this->assertSame((int) $user->id, (int) $this->conversation->received[0]['link']->userid);
    }

    /**
     * A linked account that can no longer be served is told so, and nothing reaches the conversation.
     */
    public function test_inactive_account_or_blocked_role_is_not_served(): void {
        global $DB;
        $this->setup_channel();
        $suspended = $this->getDataGenerator()->create_user();
        self::insert_link((int) $suspended->id);
        $DB->set_field('user', 'suspended', 1, ['id' => $suspended->id]);
        $student = $this->getDataGenerator()->create_user();
        self::insert_link((int) $student->id, self::OTHER, null);
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        set_config('whatsapp_hidden_roles', (string) $roleid, 'local_jedomi');

        self::process($this->text('Hello'));
        self::process($this->text('Hello', self::OTHER, null));

        $this->assertSame([
            get_string('whatsapp_msg_account_inactive', 'local_jedomi'),
            get_string('whatsapp_msg_role_blocked', 'local_jedomi'),
        ], array_column($this->sent(), 'text'));
        $this->assertSame([], $this->conversation->received);
    }

    /**
     * A link unused for more than 30 days is removed on its next message, which gets the linking instructions.
     */
    public function test_expired_link_is_removed(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        $old = self::NOW - whatsapp_link_service::EXPIRY - 1;
        self::insert_link((int) $user->id, self::BSUID, self::WAID, ['lastused' => $old, 'timecreated' => $old]);

        $sink = $this->redirectEvents();
        self::process($this->text('Hello'));
        $events = $sink->get_events();
        $sink->close();

        $this->assertNull(self::link_of((int) $user->id));
        $this->assertSame([whatsapp_unlinked::class], array_map('get_class', $events));
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
        $this->assertSame([get_string('whatsapp_msg_link_first', 'local_jedomi')], array_column($this->sent(), 'text'));
        $this->assertSame([], $this->conversation->received);
    }

    /**
     * A step of the link that fails rolls the whole link back: no transaction stays open and the earlier link of the
     * account is kept.
     */
    public function test_failed_link_rolls_back_and_leaves_no_open_transaction(): void {
        global $DB;
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::OTHER, '15550000000');
        $code = self::code((int) $user->id);
        // A phone longer than the column makes the write of the new link fail after the earlier link was removed.
        $toolong = str_repeat('1', 25);

        try {
            self::process($this->text('JEDOMI ' . $code, self::BSUID, $toolong));
            $this->fail('The write of the link must fail');
        } catch (\dml_exception $e) {
            $this->assertFalse($DB->is_transaction_started(), 'No transaction stays open');
        }

        $link = self::link_of((int) $user->id);
        $this->assertNotNull($link, 'The earlier link is kept');
        $this->assertSame(self::OTHER, $link->bsuid);
        $this->assertFalse($DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['bsuid' => self::BSUID]));
        $this->assertSame([], $this->sent());
    }

    /**
     * A linked student whose text has the shape of a code (as "TAREA5") is in a conversation, not linking: the text
     * reaches the conversation and no code reply is sent.
     */
    public function test_code_shaped_word_of_a_linked_student_goes_to_the_conversation(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);

        self::process($this->text('TAREA5'));

        $this->assertSame([], $this->sent());
        $this->assertSame(['TAREA5'], array_map(static fn(array $r): string => $r['message']->text, $this->conversation->received));
    }

    /**
     * A student who stopped the messages and sends a code-shaped word gets no reply at all.
     */
    public function test_code_shaped_word_of_an_opted_out_student_gets_no_reply(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::BSUID, self::WAID, ['optedout' => 1, 'optoutat' => self::NOW - 10]);

        self::process($this->text('TAREA5'));

        $this->assertSame([], $this->sent());
        $this->assertSame([], $this->conversation->received);
    }

    /**
     * A linked student relinks with the explicit JEDOMI form only; the bare code of a linked student is plain text.
     */
    public function test_linked_student_relinks_with_the_prefix_only(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::BSUID, self::WAID, ['consentcategories' => 'grades', 'consentat' => 1]);

        $code = self::code((int) $user->id);
        self::process($this->text($code));
        $this->assertSame('grades', self::link_of((int) $user->id)->consentcategories, 'A bare code does not relink');
        $this->assertCount(1, $this->conversation->received);

        self::process($this->text('JEDOMI ' . $code));
        $this->assertSame('chat,grades', self::link_of((int) $user->id)->consentcategories);
        $this->assertSame(self::NOW - 60, (int) self::link_of((int) $user->id)->consentat);
    }

    /**
     * Replacing the earlier owner of the identity triggers whatsapp_unlinked once for it, then whatsapp_linked.
     */
    public function test_owner_replacement_triggers_unlinked_once(): void {
        $this->setup_channel();
        $previous = $this->getDataGenerator()->create_user();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $previous->id);

        $sink = $this->redirectEvents();
        self::process($this->text('JEDOMI ' . self::code((int) $user->id)));
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame([whatsapp_unlinked::class, whatsapp_linked::class], array_map('get_class', $events));
        $this->assertSame((int) $previous->id, (int) $events[0]->relateduserid);
        $this->assertSame((int) $user->id, (int) $events[1]->relateduserid);
    }

    /**
     * Replacing the earlier identity of the account triggers whatsapp_unlinked once, then whatsapp_linked.
     */
    public function test_identity_replacement_triggers_unlinked_once(): void {
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id, self::OTHER, '15550000000');

        $sink = $this->redirectEvents();
        self::process($this->text('JEDOMI ' . self::code((int) $user->id)));
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame([whatsapp_unlinked::class, whatsapp_linked::class], array_map('get_class', $events));
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
    }

    /**
     * While Jedomi is disabled the sender gets the message of the provider, as in Telegram, and nothing else runs:
     * no link, no conversation. An opted-out student gets nothing.
     */
    public function test_disabled_plugin_answers_with_its_message_only(): void {
        $this->setup_channel();
        \core\di::set(plugin_state::class, fake_plugin_state::disabled('Jedomi is not enabled'));
        $user = $this->getDataGenerator()->create_user();
        $optedout = $this->getDataGenerator()->create_user();
        self::insert_link((int) $optedout->id, self::OTHER, null, ['optedout' => 1]);

        self::process($this->text('JEDOMI ' . self::code((int) $user->id)));
        self::process($this->text('Hello', self::OTHER, null));

        $this->assertNull(self::link_of((int) $user->id));
        $this->assertSame(['Jedomi is not enabled'], array_column($this->sent(), 'text'));
        $this->assertSame([], $this->conversation->received);
    }

    /**
     * While the integration is turned off, a linked student is told the channel is unavailable and others get nothing.
     */
    public function test_disabled_integration_tells_linked_students_only(): void {
        $this->setup_channel();
        set_config('whatsapp_enabled', 0, 'local_jedomi');
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);

        self::process($this->text('Hello'));
        self::process($this->text('Hello', self::OTHER, null));

        $this->assertSame([['payload' => ['to' => self::WAID],
            'text' => get_string('whatsapp_msg_unavailable', 'local_jedomi')]], $this->sent());
        $this->assertSame([], $this->conversation->received);
    }

    /**
     * A reply Meta refuses does not fail the message, and the link is still made.
     */
    public function test_refused_reply_does_not_fail_the_message(): void {
        $this->setup_channel();
        $this->client->responses['send_text'] = new cloud_api_exception(400, 131047, 'Re-engagement message detail');
        $user = $this->getDataGenerator()->create_user();

        self::process($this->text('JEDOMI ' . self::code((int) $user->id)));

        $this->assertNotNull(self::link_of((int) $user->id));
        $this->assertCount(1, $this->client->calls_to('send_text'));
    }

    /**
     * System messages other than identity changes are not answered.
     */
    public function test_system_messages_get_no_answer(): void {
        $this->setup_channel();

        self::process(new inbound(
            'wamid.X',
            self::BSUID,
            null,
            'system',
            '',
            null,
            null,
            '',
            'customer_identity_changed',
            '',
            null,
            self::NOW
        ));

        $this->assertSame([], $this->client->calls);
    }

    /**
     * Load the user library.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        parent::setUpBeforeClass();
    }
}
