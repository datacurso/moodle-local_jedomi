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

namespace local_jedomi\local\service;

/**
 * Tests for sending Moodle internal messages from the web chat (send_messages tool).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\message_service
 */
final class message_service_test extends \advanced_testcase {
    /**
     * A5: messages go only to valid recipients the sender may contact; the rest count as failed.
     * MDL-INT-049: only existing recipients the sender can contact receive it and the counts match the delivered messages.
     */
    public function test_send_reaches_only_valid_recipients(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $course = $generator->create_course();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student1 = $generator->create_user();
        $student2 = $generator->create_user();
        $generator->enrol_user($student1->id, $course->id, 'student');
        $generator->enrol_user($student2->id, $course->id, 'student');
        // Not in the course and only accepts messages from contacts: the teacher cannot contact them.
        $stranger = $generator->create_user();
        set_user_preference('message_blocknoncontacts', \core_message\api::MESSAGE_PRIVACY_ONLYCONTACTS, $stranger->id);
        $deleted = $generator->create_user();
        delete_user($deleted);
        $this->setUser($teacher);
        $sink = $this->redirectMessages();

        $result = message_service::send((int) $teacher->id, [
            $student1->id, (string) $student2->id, $student1->id, $stranger->id, $deleted->id, 987654, 0,
        ], 'Please submit your essay before Friday.');

        $this->assertSame(['sent' => 2, 'failed' => 3], $result);
        $messages = $sink->get_messages();
        $this->assertCount(2, $messages);
        $recipients = array_map(static fn($m) => (int) $m->useridto, $messages);
        sort($recipients);
        $this->assertSame([(int) $student1->id, (int) $student2->id], $recipients);
        $this->assertSame('Please submit your essay before Friday.', $messages[0]->fullmessage);
        $this->assertSame((int) $teacher->id, (int) $messages[0]->useridfrom);
    }

    /**
     * A5: without moodle/site:sendmessage nothing is sent.
     * MDL-INT-049: without the permission to send messages nothing is sent.
     */
    public function test_send_without_capability_sends_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator();
        $sender = $generator->create_user();
        $recipient = $generator->create_user();
        $userroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability('moodle/site:sendmessage', CAP_PROHIBIT, $userroleid, \context_system::instance()->id, true);
        $this->setUser($sender);
        $sink = $this->redirectMessages();

        $this->assertFalse(message_service::can_send((int) $sender->id));
        $result = message_service::send((int) $sender->id, [$recipient->id], 'Hello');

        $this->assertSame(['sent' => 0, 'failed' => 1], $result);
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * An empty message is not sent.
     * MDL-INT-049: an empty message is not sent.
     */
    public function test_empty_message_is_not_sent(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $recipient = $this->getDataGenerator()->create_user();
        $sink = $this->redirectMessages();

        $this->assertSame(['sent' => 0, 'failed' => 1], message_service::send((int) get_admin()->id, [$recipient->id], '   '));
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * MDL-UNIT-095: recipients are reduced to distinct positive ids.
     * Recipients are reduced to distinct positive ids.
     */
    public function test_recipients_are_distinct_positive_ids(): void {
        $this->assertSame([5, 6], message_service::recipients([5, '6', 5, 0, -3, 'x', null]));
        $this->assertSame([], message_service::recipients('5'));
    }

    /**
     * Recipient lists proposed by the AI and the ids that must be kept.
     *
     * @return array
     */
    public static function recipients_provider(): array {
        return [
            'numbers' => [[3, 4], [3, 4]],
            'numeric text' => [['3', '4'], [3, 4]],
            'numbers and text mixed' => [[3, '4'], [3, 4]],
            'non numeric values' => [['abc', '4a', '1.5', ' 7', 2.5, true], []],
            'empty values' => [['', null, []], []],
            'zero' => [[0, '0'], []],
            'negative' => [[-1, '-2'], []],
            'repeated as number and text' => [[9, '9', 9], [9]],
            'valid among invalid' => [['x', 12, 0, '12', -5, '13'], [12, 13]],
            'empty list' => [[], []],
        ];
    }

    /**
     * MDL-UNIT-095: numbers and numeric text are accepted; non numeric, empty, zero and negative values are dropped;
     * a repeated recipient counts once.
     *
     * @dataProvider recipients_provider
     * @param array $proposed Recipients proposed by the AI.
     * @param int[] $expected Recipients kept.
     */
    public function test_recipients_proposed_by_the_ai_are_normalised(array $proposed, array $expected): void {
        $this->assertSame($expected, message_service::recipients($proposed));
    }

    /**
     * MDL-UNIT-095: a missing list or a value that is not a list gives no recipients.
     */
    public function test_missing_or_non_list_recipients_give_none(): void {
        foreach ([null, '5', 5, '5,6', new \stdClass(), false] as $value) {
            $this->assertSame([], message_service::recipients($value));
        }
    }

    /**
     * MDL-INT-049: the sender is never a recipient of their own message and the sent count is the number of messages
     * really delivered.
     */
    public function test_sender_does_not_receive_their_own_message(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($teacher);
        $sink = $this->redirectMessages();

        $result = message_service::send((int) $teacher->id, [$teacher->id, $student->id], 'Reminder for tomorrow.');

        $recipients = array_map(static fn($m) => (int) $m->useridto, $sink->get_messages());
        $this->assertSame([(int) $student->id], $recipients);
        $this->assertSame(count($recipients), $result['sent']);
    }
}
