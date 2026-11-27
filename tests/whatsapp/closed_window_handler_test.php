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
 * Tests for a reply that finds the 24-hour window closed and for the monthly limit of outgoing messages, at the
 * transport: the reply is held for the next message of the student, who is told once with the reply_ready template;
 * at the limit the student gets a single notice and nothing else until the next month. Nothing reaches Meta.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\whatsapp_closed_window_handler
 * @covers     \local_jedomi\whatsapp\held_replies
 * @covers     \local_jedomi\whatsapp\template_sender
 * @covers     \local_jedomi\whatsapp\monthly_cap
 * @covers     \local_jedomi\whatsapp\whatsapp_transport
 */
final class closed_window_handler_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Phone of the student. */
    private const WAID = '16505551234';

    /** @var int Frozen time of the tests: 2027-01-15 08:00 UTC. */
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
     * @param int $lastinbound Last inbound message.
     * @param array $fields Fields that replace the default ones.
     */
    private function link(int $lastinbound, array $fields = []): void {
        global $DB;
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) array_merge(['userid' => $this->userid,
            'bsuid' => self::BSUID, 'waid' => self::WAID, 'phone' => '+' . self::WAID, 'confirmed' => 1,
            'consentat' => self::NOW - DAYSECS, 'consentversion' => consent::VERSION, 'consentcategories' => 'chat,grades',
            'lastused' => $lastinbound, 'lastinbound' => $lastinbound, 'timecreated' => self::NOW - 2 * DAYSECS], $fields));
    }

    /**
     * A closed window: the last message of the student is 23 h 55 min old.
     *
     * @param array $fields Fields that replace the default ones.
     */
    private function closed_window(array $fields = []): void {
        $this->link(self::NOW - DAYSECS + 5 * MINSECS, $fields);
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
     * The row of the held replies of the student, or false.
     *
     * @return \stdClass|false
     */
    private static function held_row() {
        global $DB;
        return $DB->get_record(
            whatsapp_link_service::SESSIONS_TABLE,
            ['chatid' => whatsapp_link_service::HELD_PREFIX . self::chatkey()]
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
     * Set the usage of the month of the user.
     *
     * @param int $outservice Service messages sent.
     * @param int $outtemplate Templates sent.
     */
    private function set_usage(int $outservice, int $outtemplate = 0): void {
        global $DB;
        $DB->insert_record(whatsapp_link_service::USAGE_TABLE, (object) ['userid' => $this->userid,
            'period' => (int) date('Ym', self::NOW), 'inbound' => 0, 'outservice' => $outservice,
            'outtemplate' => $outtemplate, 'media' => 0]);
    }

    /**
     * A reply in a closed window is held and the student, who accepted the chat, gets the reply_ready template with
     * the name and language of the settings, counted as a template.
     */
    public function test_a_held_reply_sends_reply_ready_once(): void {
        $this->closed_window();
        manager::set_template_name(templates::REPLY_READY, 'jedomi_reply_ready');
        manager::set_template_language('es_MX');
        $transport = new whatsapp_transport();

        $this->assertFalse($transport->send_answer(self::chatkey(), 'Tiene *2* cursos.'));
        $this->assertFalse($transport->send_text(self::chatkey(), 'Second part'));

        $this->assertSame([], $this->client->calls_to('send_text'));
        $templates = $this->client->calls_to('send_template');
        $this->assertCount(1, $templates, 'One template per held reply, not one per message');
        [$to, $name, $language, $components] = $templates[0]['args'];
        $this->assertSame(['to' => self::WAID], $to->to_payload());
        $this->assertSame(['jedomi_reply_ready', 'es_MX', []], [$name, $language, $components]);
        $this->assertSame(1, $this->usage('outtemplate'));
        $this->assertSame(0, $this->usage('outservice'));
        $this->assertSame(['Tiene *2* cursos.', 'Second part'], held_replies::peek($this->link_record()));
    }

    /**
     * A student who did not accept the chat gets no template; the reply is still held.
     */
    public function test_no_template_without_the_chat_consent(): void {
        $this->closed_window(['consentcategories' => 'grades']);

        (new whatsapp_transport())->send_answer(self::chatkey(), 'Respuesta');

        $this->assertSame([], $this->client->calls);
        $this->assertNotFalse(self::held_row());
    }

    /**
     * After the held replies are delivered, a new closed window tells the student again.
     */
    public function test_a_new_held_reply_after_delivery_tells_the_student_again(): void {
        $this->closed_window();
        $transport = new whatsapp_transport();

        $transport->send_answer(self::chatkey(), 'One');
        held_replies::acknowledge($this->link_record(), ['One'], 1);
        $transport->send_answer(self::chatkey(), 'Two');

        $this->assertCount(2, $this->client->calls_to('send_template'));
        $this->assertNotFalse(self::held_row());
    }

    /**
     * Reading the held replies keeps them; acknowledging all of them as delivered removes them; with nothing held the
     * list is empty.
     */
    public function test_acknowledging_every_held_reply_removes_them(): void {
        $this->closed_window();
        (new whatsapp_transport())->send_text(self::chatkey(), 'Held');

        $held = held_replies::peek($this->link_record());
        $this->assertSame(['Held'], $held);
        $this->assertNotFalse(self::held_row(), 'Reading does not remove');

        held_replies::acknowledge($this->link_record(), $held, 1);
        $this->assertFalse(self::held_row());
        $this->assertSame([], held_replies::peek($this->link_record()));
    }

    /**
     * Only the delivered texts are removed: the first one not sent and those after it stay held, in order.
     */
    public function test_acknowledging_a_part_keeps_the_rest_in_order(): void {
        $this->closed_window();
        $transport = new whatsapp_transport();
        foreach (['One', 'Two', 'Three'] as $text) {
            $transport->send_text(self::chatkey(), $text);
        }
        $held = held_replies::peek($this->link_record());

        held_replies::acknowledge($this->link_record(), $held, 1);
        $this->assertSame(['Two', 'Three'], held_replies::peek($this->link_record()));

        held_replies::acknowledge($this->link_record(), ['Two', 'Three'], 0);
        $this->assertSame(['Two', 'Three'], held_replies::peek($this->link_record()), 'Nothing sent, nothing removed');
    }

    /**
     * A text held while the others were being delivered is kept: only the delivered ones are removed.
     */
    public function test_a_text_held_during_the_delivery_is_kept(): void {
        $this->closed_window();
        $transport = new whatsapp_transport();
        $transport->send_text(self::chatkey(), 'One');
        $transport->send_text(self::chatkey(), 'Two');
        $held = held_replies::peek($this->link_record());

        held_replies::hold($this->link_record(), 'Later');
        held_replies::acknowledge($this->link_record(), $held, 2);

        $this->assertSame(['Later'], held_replies::peek($this->link_record()));
    }

    /**
     * When the texts held during the delivery pushed out the oldest ones (limit of MAX_TEXTS), only the delivered
     * texts still kept are removed.
     */
    public function test_a_delivery_while_the_limit_trims_the_oldest_texts(): void {
        $this->closed_window();
        $transport = new whatsapp_transport();
        for ($i = 1; $i <= held_replies::MAX_TEXTS; $i++) {
            $transport->send_text(self::chatkey(), 'Text ' . $i);
        }
        $held = held_replies::peek($this->link_record());

        held_replies::hold($this->link_record(), 'Text 11');
        held_replies::hold($this->link_record(), 'Text 12');
        held_replies::acknowledge($this->link_record(), $held, 5);

        $expected = array_map(static fn(int $i): string => 'Text ' . $i, range(6, 12));
        $this->assertSame($expected, held_replies::peek($this->link_record()));
    }

    /**
     * Only the newest held texts are kept.
     */
    public function test_only_the_newest_held_texts_are_kept(): void {
        $this->closed_window();
        $transport = new whatsapp_transport();
        $total = held_replies::MAX_TEXTS + 2;
        for ($i = 1; $i <= $total; $i++) {
            $transport->send_text(self::chatkey(), 'Text ' . $i);
        }

        $texts = held_replies::peek($this->link_record());

        $this->assertCount(held_replies::MAX_TEXTS, $texts);
        $this->assertSame('Text ' . $total, end($texts));
        $this->assertSame('Text 3', reset($texts));
    }

    /**
     * Unlinking removes the held replies with the rest of the chat.
     */
    public function test_unlink_removes_the_held_replies(): void {
        $this->closed_window();
        (new whatsapp_transport())->send_text(self::chatkey(), 'Held');

        whatsapp_link_service::unlink($this->userid);

        $this->assertFalse(self::held_row());
    }

    /**
     * Below the monthly limit messages are sent; the message that would pass the limit is replaced by a single
     * notice, and nothing more is sent until the next month.
     */
    public function test_the_monthly_cap_sends_one_notice_then_silence(): void {
        $this->link(self::NOW - HOURSECS);
        manager::set_monthly_cap(3);
        $this->set_usage(2);
        $transport = new whatsapp_transport();

        $this->assertTrue($transport->send_text(self::chatkey(), 'Third'));
        $this->assertFalse($transport->send_text(self::chatkey(), 'Fourth'));
        $this->assertFalse($transport->send_text(self::chatkey(), 'Fifth'));

        $texts = array_map(static fn(array $call): string => $call['args'][1], $this->client->calls_to('send_text'));
        $this->assertSame(['Third', get_string('whatsapp_msg_monthly_cap', 'local_jedomi')], $texts);
        $this->assertSame(4, $this->usage('outservice'), 'The notice counts as an outgoing message');
    }

    /**
     * Templates count towards the monthly limit, and a capped student gets no template.
     */
    public function test_templates_count_towards_the_cap(): void {
        $this->closed_window();
        manager::set_monthly_cap(2);
        $this->set_usage(1, 1);

        (new whatsapp_transport())->send_answer(self::chatkey(), 'Held');

        $this->assertSame([], $this->client->calls);
        $this->assertFalse(template_sender::send($this->link_record(), templates::REPLY_READY, []));
        $this->assertSame([], $this->client->calls);
    }

    /**
     * The limit is per month of the Moodle clock: the next month the student is answered again.
     */
    public function test_the_cap_is_lifted_the_next_month(): void {
        $this->link(self::NOW - HOURSECS);
        manager::set_monthly_cap(1);
        $this->set_usage(5);

        global $DB;
        $this->assertFalse((new whatsapp_transport())->send_text(self::chatkey(), 'Blocked'));
        $this->mock_clock_with_frozen(self::NOW + 31 * DAYSECS);
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'lastinbound', self::NOW + 31 * DAYSECS - MINSECS, []);
        $this->assertTrue((new whatsapp_transport())->send_text(self::chatkey(), 'Next month'));

        $texts = array_map(static fn(array $call): string => $call['args'][1], $this->client->calls_to('send_text'));
        $this->assertSame(['Next month'], $texts, 'Past the limit no notice is repeated');
    }

    /**
     * With the limit at 0 (the default) nothing is limited.
     */
    public function test_a_zero_cap_is_unlimited(): void {
        $this->link(self::NOW - HOURSECS);
        $this->set_usage(100000, 100000);

        $this->assertTrue((new whatsapp_transport())->send_text(self::chatkey(), 'Hola'));
    }

    /**
     * The link of the student.
     *
     * @return \stdClass
     */
    private function link_record(): \stdClass {
        global $DB;
        return $DB->get_record(whatsapp_link_service::LINKS_TABLE, ['userid' => $this->userid], '*', MUST_EXIST);
    }
}
