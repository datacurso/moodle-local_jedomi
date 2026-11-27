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

use local_jedomi\local\service\whatsapp_link_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_cloud_api_client.php');

/**
 * Tests for the WhatsApp transport: recipient by phone or BSUID, the 24-hour window, the split of long answers, the
 * read receipt with the typing indicator and the usage counters. Nothing reaches Meta: the client is
 * fake_cloud_api_client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\whatsapp_transport
 * @covers     \local_jedomi\whatsapp\usage_meter
 */
final class whatsapp_transport_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Phone of the student. */
    private const WAID = '16505551234';

    /** @var int Frozen time of the tests. */
    private const NOW = 1800000000;

    /** @var fake_cloud_api_client Client double. */
    private fake_cloud_api_client $client;

    /** @var int Linked user. */
    private int $userid;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::NOW);
        set_config('whatsapp_enabled', 1, 'local_jedomi');
        $this->client = new fake_cloud_api_client();
        cloud_api_client::set_instance_for_testing($this->client);
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
    }

    #[\Override]
    protected function tearDown(): void {
        cloud_api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Link the user of the test.
     *
     * @param string|null $waid Phone; null when Meta did not share it.
     * @param int $lastinbound Last inbound message.
     */
    private function link(?string $waid = self::WAID, int $lastinbound = self::NOW - HOURSECS): void {
        global $DB;
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) ['userid' => $this->userid, 'bsuid' => self::BSUID,
            'waid' => $waid, 'phone' => $waid ? '+' . $waid : null, 'confirmed' => 1, 'consentat' => self::NOW - DAYSECS,
            'consentversion' => consent::VERSION, 'consentcategories' => 'chat,grades', 'lastused' => $lastinbound,
            'lastinbound' => $lastinbound, 'timecreated' => self::NOW - DAYSECS]);
    }

    /**
     * Chat key of the student.
     *
     * @return string
     */
    private static function chatkey(): string {
        return whatsapp_link_service::chatkey(self::BSUID);
    }

    /**
     * Texts sent, with their recipient fields.
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
     * Usage counter of the user in the current month.
     *
     * @param string $counter Column.
     * @return int
     */
    private function usage(string $counter): int {
        global $DB;
        return (int) $DB->get_field(
            whatsapp_link_service::USAGE_TABLE,
            $counter,
            ['userid' => $this->userid, 'period' => (int) date('Ym', self::NOW)]
        );
    }

    /**
     * With a phone the body addresses only "to"; the reply counts as a service message of the month.
     */
    public function test_a_link_with_phone_is_addressed_by_to_only(): void {
        $this->link();

        $this->assertTrue((new whatsapp_transport())->send_text(self::chatkey(), 'Hola'));

        $this->assertSame([['payload' => ['to' => self::WAID], 'text' => 'Hola']], $this->sent());
        $this->assertSame(1, $this->usage('outservice'));
    }

    /**
     * Without a phone the body addresses only "recipient", with the BSUID.
     */
    public function test_a_link_without_phone_is_addressed_by_bsuid_only(): void {
        $this->link(null);

        $this->assertTrue((new whatsapp_transport())->send_answer(self::chatkey(), '*Hola*'));

        $this->assertSame([['payload' => ['recipient' => self::BSUID], 'text' => '*Hola*']], $this->sent());
    }

    /**
     * A chat without a link, or a key that is not of WhatsApp, gets nothing.
     */
    public function test_a_chat_without_link_gets_nothing(): void {
        $transport = new whatsapp_transport();

        $this->assertFalse($transport->send_text(self::chatkey(), 'Hola'));
        $this->assertFalse($transport->send_text('782000001', 'Hola'));
        $this->assertFalse($transport->send_activity(self::chatkey()));
        $this->assertSame([], $this->client->calls);
    }

    /**
     * Outside the 24-hour window no free text is sent: the transport says so and hands the link to the closed window
     * handler, which unit 15 uses to send the reply_ready template.
     */
    public function test_a_closed_window_sends_nothing_and_calls_the_closed_window_handler(): void {
        $this->link(self::WAID, self::NOW - DAYSECS + 5 * MINSECS);
        $handler = new class implements closed_window_handler {
            /** @var \stdClass[] Links handed over. */
            public array $links = [];

            /** @var string[] Texts handed over. */
            public array $texts = [];

            #[\Override]
            public function reply_held(\stdClass $link, string $text): void {
                $this->links[] = $link;
                $this->texts[] = $text;
            }
        };
        \core\di::set(closed_window_handler::class, $handler);

        $this->assertFalse((new whatsapp_transport())->send_answer(self::chatkey(), 'Respuesta'));

        $this->assertSame([], $this->sent());
        $this->assertCount(1, $handler->links);
        $this->assertSame(self::BSUID, $handler->links[0]->bsuid);
        $this->assertSame(['Respuesta'], $handler->texts);
        $this->assertSame(0, $this->usage('outservice'));
    }

    /**
     * The default closed window handler sends no free-form text: it holds the reply and sends the reply_ready template.
     */
    public function test_the_default_closed_window_handler_sends_only_the_template(): void {
        $this->link(self::WAID, self::NOW - 2 * DAYSECS);

        $this->assertFalse((new whatsapp_transport())->send_text(self::chatkey(), 'Hola'));

        $this->assertSame([], $this->client->calls_to('send_text'));
        $this->assertCount(1, $this->client->calls_to('send_template'));
    }

    /**
     * A long answer is sent in parts of at most 1600 characters, each counted.
     */
    public function test_a_long_answer_is_sent_in_parts(): void {
        $this->link();
        $one = str_repeat('a', 1000);
        $two = str_repeat('b', 1000);

        $this->assertTrue((new whatsapp_transport())->send_answer(self::chatkey(), $one . "\n\n" . $two));

        $this->assertSame([$one, $two], array_column($this->sent(), 'text'));
        $this->assertSame(2, $this->usage('outservice'));
    }

    /**
     * An answer that needs more than four parts is cut to three parts and a link to the web chat.
     */
    public function test_an_answer_over_four_parts_ends_with_a_link_to_the_web_chat(): void {
        $this->link();
        $paragraphs = [];
        foreach (range('a', 'f') as $letter) {
            $paragraphs[] = str_repeat($letter, 1500);
        }

        $this->assertTrue((new whatsapp_transport())->send_answer(self::chatkey(), implode("\n\n", $paragraphs)));

        $texts = array_column($this->sent(), 'text');
        $this->assertCount(whatsapp_limits::MAX_PARTS, $texts);
        $this->assertSame(array_slice($paragraphs, 0, 3), array_slice($texts, 0, 3));
        $weburl = (new \moodle_url(whatsapp_transport::WEB_CHAT_PATH))->out(false);
        $this->assertSame(get_string('whatsapp_msg_continue_on_web', 'local_jedomi', $weburl), $texts[3]);
    }

    /**
     * An error of Meta is not shown to the student: the send fails quietly and no further part is sent.
     */
    public function test_an_error_of_meta_fails_quietly(): void {
        $this->link();
        $this->client->responses['send_text'] = new cloud_api_exception(400, 131047, 'Re-engagement message secret detail');

        $transport = new whatsapp_transport();
        $this->assertFalse($transport->send_answer(self::chatkey(), str_repeat('a', 1000) . "\n\n" . str_repeat('b', 1000)));

        $this->assertCount(1, $this->client->calls_to('send_text'));
        $this->assertSame(0, $this->usage('outservice'));
    }

    /**
     * The activity marks the latest message of the student as read and shows the typing indicator.
     */
    public function test_activity_marks_the_latest_inbound_message_read_with_typing(): void {
        global $DB;
        $this->link();
        foreach ([['wamid.OLD', self::NOW - 60], ['wamid.NEW', self::NOW - 5]] as [$wamid, $time]) {
            $DB->insert_record(whatsapp_link_service::INBOX_TABLE, (object) ['wamid' => $wamid, 'bsuid' => self::BSUID,
                'payload' => '', 'status' => inbox_processor::STATUS_DONE, 'attempts' => 1, 'timecreated' => $time,
                'timeprocessed' => $time]);
        }
        $DB->insert_record(whatsapp_link_service::INBOX_TABLE, (object) ['wamid' => 'wamid.OTHER',
            'bsuid' => 'CO.1', 'payload' => '', 'status' => inbox_processor::STATUS_DONE, 'attempts' => 1,
            'timecreated' => self::NOW, 'timeprocessed' => self::NOW]);

        $this->assertTrue((new whatsapp_transport())->send_activity(self::chatkey(), 2));

        $calls = $this->client->calls_to('mark_read_typing');
        $this->assertCount(1, $calls);
        $this->assertSame(['wamid.NEW', 2], $calls[0]['args'], 'The timeout of the activity reaches the client');
    }

    /**
     * Without a received message, or when Meta refuses it, there is no activity.
     */
    public function test_activity_fails_without_message_or_on_error(): void {
        global $DB;
        $this->link();
        $transport = new whatsapp_transport();
        $this->assertFalse($transport->send_activity(self::chatkey()));

        $DB->insert_record(whatsapp_link_service::INBOX_TABLE, (object) ['wamid' => 'wamid.A', 'bsuid' => self::BSUID,
            'payload' => '', 'status' => inbox_processor::STATUS_DONE, 'attempts' => 1, 'timecreated' => self::NOW,
            'timeprocessed' => self::NOW]);
        $this->client->responses['mark_read_typing'] = new cloud_api_exception(400, 100, 'detail');
        $this->assertFalse($transport->send_activity(self::chatkey()));
    }

    /**
     * An answer is formatted for WhatsApp, and an answer with only format characters is blank.
     */
    public function test_answers_are_formatted_and_blank_ones_detected(): void {
        $transport = new whatsapp_transport();

        $this->assertSame('*Hola*', $transport->format_answer('**Hola**'));
        $this->assertTrue($transport->is_blank(" * _ ~ " . formatter::MONOSPACE . " \n"));
        $this->assertFalse($transport->is_blank('*a*'));
    }
}
