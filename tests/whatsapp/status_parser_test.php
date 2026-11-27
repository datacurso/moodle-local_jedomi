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

/**
 * Tests for the parser of the delivery statuses of a WhatsApp webhook.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\status_parser
 * @covers     \local_jedomi\whatsapp\delivery_status
 */
final class status_parser_test extends \basic_testcase {
    /** @var string Message id of the message sent. */
    private const WAMID = 'wamid.HBgLMTY1MDM4Nzk0MzkVAgARGBI3MjBBRjk0QzZCNjBBNzM1RTEA';

    /**
     * A failed status arrives without contacts; it is read by its message id, with its error codes.
     */
    public function test_a_failed_status_without_contacts_is_read(): void {
        $value = ['messaging_product' => 'whatsapp', 'statuses' => [[
            'id' => self::WAMID,
            'status' => 'failed',
            'timestamp' => '1777000100',
            'recipient_user_id' => 'US.13491208655302741918',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message'], ['code' => '131026']],
        ]]];

        $statuses = status_parser::parse($value);

        $this->assertCount(1, $statuses);
        $this->assertSame(self::WAMID, $statuses[0]->wamid);
        $this->assertSame(delivery_status::FAILED, $statuses[0]->status);
        $this->assertTrue($statuses[0]->is_failed());
        $this->assertSame('US.13491208655302741918', $statuses[0]->recipientbsuid);
        $this->assertNull($statuses[0]->recipientwaid);
        $this->assertSame([131047, 131026], $statuses[0]->errorcodes);
        $this->assertSame(1777000100, $statuses[0]->timestamp);
    }

    /**
     * The status of a message sent to a phone has no recipient_user_id; the recipient is the phone.
     */
    public function test_a_status_of_a_send_to_a_phone_uses_recipient_id(): void {
        $value = ['statuses' => [[
            'id' => self::WAMID,
            'status' => 'delivered',
            'timestamp' => '1777000100',
            'recipient_id' => '16505551234',
        ]]];

        $statuses = status_parser::parse($value);

        $this->assertCount(1, $statuses);
        $this->assertSame('', $statuses[0]->recipientbsuid);
        $this->assertSame('16505551234', $statuses[0]->recipientwaid);
        $this->assertSame([], $statuses[0]->errorcodes);
        $this->assertFalse($statuses[0]->is_failed());
    }

    /**
     * Sent, delivered, read and failed are read; unknown states and statuses without message id are skipped.
     */
    public function test_only_known_statuses_with_an_id_are_read(): void {
        $value = ['statuses' => [
            ['id' => self::WAMID, 'status' => 'sent', 'recipient_id' => '16505551234'],
            ['id' => self::WAMID, 'status' => 'read', 'recipient_user_id' => 'US.1'],
            ['id' => self::WAMID, 'status' => 'deleted', 'recipient_id' => '16505551234'],
            ['status' => 'delivered', 'recipient_id' => '16505551234'],
            'not an array',
        ]];

        $statuses = status_parser::parse($value);

        $this->assertSame(['sent', 'read'], array_map(fn(delivery_status $s) => $s->status, $statuses));
    }

    /**
     * A value without statuses gives none.
     */
    public function test_a_value_without_statuses_gives_none(): void {
        $this->assertSame([], status_parser::parse(['messages' => []]));
        $this->assertSame([], status_parser::parse(['statuses' => 'x']));
    }
}
