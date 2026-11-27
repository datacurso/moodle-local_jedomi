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

use local_jedomi\event\whatsapp_opted_out;
use local_jedomi\local\service\whatsapp_link_service;

/**
 * Tests for the status sink of the WhatsApp channel: a failed status with an error code that means the student stopped
 * the messages marks the link as opted out, once, whatever times Meta sends the same status.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\whatsapp_status_sink
 */
final class whatsapp_status_sink_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Phone of the student. */
    private const WAID = '16505551234';

    /** @var int Frozen time of the tests. */
    private const NOW = 1800000000;

    /** @var int Error code configured as an opt-out in the tests. */
    private const OPT_OUT_CODE = 131050;

    /** @var int Linked user. */
    private int $userid;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::NOW);
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) ['userid' => $this->userid, 'bsuid' => self::BSUID,
            'waid' => self::WAID, 'phone' => '+' . self::WAID, 'confirmed' => 1, 'consentat' => self::NOW - DAYSECS,
            'consentversion' => consent::VERSION, 'consentcategories' => 'chat,grades', 'lastused' => self::NOW,
            'lastinbound' => self::NOW, 'timecreated' => self::NOW - DAYSECS]);
        manager::set_opt_out_error_codes((string) self::OPT_OUT_CODE);
    }

    /**
     * A status of a message sent to the student.
     *
     * @param string $status Status.
     * @param int[] $codes Error codes.
     * @param string $wamid Message id.
     * @param string $bsuid BSUID of the recipient; empty when Meta omitted it.
     * @param string|null $waid Phone of the recipient.
     * @return delivery_status
     */
    private static function status(
        string $status,
        array $codes = [],
        string $wamid = 'wamid.OUT1',
        string $bsuid = self::BSUID,
        ?string $waid = null
    ): delivery_status {
        return new delivery_status($wamid, $status, $bsuid, $waid, $codes, self::NOW);
    }

    /**
     * The link of the student.
     *
     * @return \stdClass
     */
    private function link(): \stdClass {
        global $DB;
        return $DB->get_record(whatsapp_link_service::LINKS_TABLE, ['userid' => $this->userid], '*', MUST_EXIST);
    }

    /**
     * A failed status with an opt-out code marks the link as opted out and triggers whatsapp_opted_out.
     */
    public function test_a_failed_status_with_an_opt_out_code_opts_the_student_out(): void {
        $sink = $this->redirectEvents();

        (new whatsapp_status_sink())->record(self::status(delivery_status::FAILED, [self::OPT_OUT_CODE]));

        $link = $this->link();
        $this->assertSame(1, (int) $link->optedout);
        $this->assertSame(self::NOW, (int) $link->optoutat);
        $events = array_values(array_filter($sink->get_events(), static fn($e): bool => $e instanceof whatsapp_opted_out));
        $this->assertCount(1, $events);
        $this->assertSame($this->userid, (int) $events[0]->relateduserid);
    }

    /**
     * Meta can send the same status twice: the second copy changes nothing, even after the student opted in again.
     */
    public function test_the_same_status_twice_is_handled_once(): void {
        global $DB;
        $status = self::status(delivery_status::FAILED, [self::OPT_OUT_CODE]);
        $sink = $this->redirectEvents();

        (new whatsapp_status_sink())->record($status);
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'optedout', 0, ['userid' => $this->userid]);
        (new whatsapp_status_sink())->record($status);

        $this->assertSame(0, (int) $this->link()->optedout, 'The repeated status does not opt out again');
        $this->assertCount(1, array_filter($sink->get_events(), static fn($e): bool => $e instanceof whatsapp_opted_out));
    }

    /**
     * A failed status of another message with the opt-out code is a new status and opts out again.
     */
    public function test_a_failed_status_of_another_message_is_handled(): void {
        global $DB;
        (new whatsapp_status_sink())->record(self::status(delivery_status::FAILED, [self::OPT_OUT_CODE]));
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'optedout', 0, ['userid' => $this->userid]);

        (new whatsapp_status_sink())->record(self::status(delivery_status::FAILED, [self::OPT_OUT_CODE], 'wamid.OUT2'));

        $this->assertSame(1, (int) $this->link()->optedout);
    }

    /**
     * A student already opted out is not opted out again: no second event.
     */
    public function test_an_opted_out_student_triggers_no_second_event(): void {
        global $DB;
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'optedout', 1, ['userid' => $this->userid]);
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'optoutat', self::NOW - 50, ['userid' => $this->userid]);
        $sink = $this->redirectEvents();

        (new whatsapp_status_sink())->record(self::status(delivery_status::FAILED, [self::OPT_OUT_CODE]));

        $this->assertSame(self::NOW - 50, (int) $this->link()->optoutat);
        $this->assertSame([], $sink->get_events());
    }

    /**
     * A failed status with another error code (as 131047, window closed, or 131030, recipient not allowed with the
     * test number) is only logged.
     */
    public function test_other_error_codes_do_not_opt_out(): void {
        (new whatsapp_status_sink())->record(self::status(delivery_status::FAILED, [131047, 131030]));

        $this->assertSame(0, (int) $this->link()->optedout);
    }

    /**
     * Without recipient_user_id (a message sent to the phone) the link is found by the phone.
     */
    public function test_the_link_is_found_by_phone_when_meta_omits_the_bsuid(): void {
        (new whatsapp_status_sink())->record(self::status(
            delivery_status::FAILED,
            [self::OPT_OUT_CODE],
            'wamid.OUT1',
            '',
            self::WAID
        ));

        $this->assertSame(1, (int) $this->link()->optedout);
    }

    /**
     * Sent, delivered and read statuses change nothing; a status of an unknown recipient is ignored.
     */
    public function test_other_statuses_and_unknown_recipients_change_nothing(): void {
        $sink = $this->redirectEvents();
        $recorder = new whatsapp_status_sink();

        foreach ([delivery_status::SENT, delivery_status::DELIVERED, delivery_status::READ] as $state) {
            $recorder->record(self::status($state, [self::OPT_OUT_CODE]));
        }
        $recorder->record(self::status(delivery_status::FAILED, [self::OPT_OUT_CODE], 'wamid.X', 'CO.1', '573001112233'));

        $this->assertSame(0, (int) $this->link()->optedout);
        $this->assertSame([], $sink->get_events());
    }

    /**
     * The webhook records statuses with this sink when no other one is registered.
     */
    public function test_the_intake_uses_this_sink_by_default(): void {
        $method = new \ReflectionMethod(intake::class, 'status_sink');

        $this->assertInstanceOf(whatsapp_status_sink::class, $method->invoke(null));
    }
}
