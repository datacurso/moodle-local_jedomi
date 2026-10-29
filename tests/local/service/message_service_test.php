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
     * Recipients are reduced to distinct positive ids.
     */
    public function test_recipients_are_distinct_positive_ids(): void {
        $this->assertSame([5, 6], message_service::recipients([5, '6', 5, 0, -3, 'x', null]));
        $this->assertSame([], message_service::recipients('5'));
    }
}
