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

namespace local_jedomi\agent;

use local_jedomi\local\service\pending_action_service;

/**
 * Tests for the guard of the writes of a scheduled agent run.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\write_guard
 */
final class write_guard_test extends \advanced_testcase {
    /** @var string Write function used by the tests. */
    private const SEND = 'core_message_send_instant_messages';

    /** @var int Calls of the spy executor. */
    private int $runs = 0;

    /**
     * Course agent of a teacher that may send messages without confirmation.
     *
     * @param array $approved Approved write functions.
     * @return array Agent, course and creator.
     */
    private function course_agent(array $approved = [self::SEND]): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $agent = (object) ['id' => 42, 'userid' => (int) $teacher->id, 'name' => 'Reminder', 'context_type' => 'course',
            'context_id' => (int) \context_course::instance($course->id)->id, 'approvedwrites' => json_encode($approved)];
        return [$agent, $course, $teacher];
    }

    /**
     * Spy executor that counts its calls.
     *
     * @return callable
     */
    private function spy(): callable {
        return function (): string {
            $this->runs++;
            return json_encode(['success' => true, 'data' => []]);
        };
    }

    /**
     * Arguments of a message to some users.
     *
     * @param int[] $userids Recipients.
     * @return array
     */
    private function message_to(array $userids): array {
        $messages = [];
        foreach ($userids as $userid) {
            $messages[] = ['touserid' => $userid, 'text' => 'Reminder'];
        }
        return ['wsname' => self::SEND, 'params' => json_encode(['messages' => $messages])];
    }

    /**
     * JEDOMI-SEC-001: an approved write inside the scope runs and is recorded.
     */
    public function test_approved_write_in_scope_runs(): void {
        $this->resetAfterTest();
        [$agent, $course] = $this->course_agent();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $guard = new write_guard($agent);

        $result = json_decode($guard->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy()), true);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $this->runs);
        $this->assertCount(1, $guard->get_done());
        $this->assertSame(self::SEND, $guard->get_done()[0]['function']);
        $this->assertSame(0, $this->count_pending());
    }

    /**
     * JEDOMI-SEC-001: an approved write outside the scope (another course, another recipient) is rejected: it neither runs
     * nor waits for approval.
     */
    public function test_approved_write_out_of_scope_is_rejected(): void {
        $this->resetAfterTest();
        [$agent] = $this->course_agent();
        $other = $this->getDataGenerator()->create_course();
        $outsider = $this->getDataGenerator()->create_and_enrol($other, 'student');
        $guard = new write_guard($agent);

        $result = json_decode($guard->run('call_webservice', ['function' => self::SEND,
            'params' => ['messages' => [['touserid' => $outsider->id, 'text' => 'x']]]], $this->spy()), true);

        $this->assertSame('write_out_of_scope', $result['error']);
        $this->assertSame(0, $this->runs);
        $this->assertCount(1, $guard->get_blocked());
        $this->assertSame(0, $this->count_pending());
    }

    /**
     * JEDOMI-SEC-001: a write that is not approved for the agent is held for its creator and does not run.
     */
    public function test_write_not_approved_is_held_for_the_creator(): void {
        global $DB;
        $this->resetAfterTest();
        [$agent, $course, $teacher] = $this->course_agent([]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $guard = new write_guard($agent);

        $result = json_decode($guard->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy()), true);

        $this->assertSame('confirmation_required', $result['error']);
        $this->assertSame(0, $this->runs);
        $held = $DB->get_record(pending_action_service::TABLE, []);
        $this->assertSame((int) $teacher->id, (int) $held->userid);
        $this->assertSame(42, (int) $held->agentid);
        $this->assertSame('ws_execute', $held->tool);
        $this->assertCount(1, $guard->get_held());
    }

    /**
     * JEDOMI-SEC-001: agent management and administration operations are never run by an agent without approval.
     */
    public function test_agent_and_admin_operations_are_always_held(): void {
        $this->resetAfterTest();
        [$agent] = $this->course_agent();
        $guard = new write_guard($agent);

        $operations = ['create_agent' => ['prompt' => 'x'], 'delete_agents' => ['id' => 1], 'admin_execute' => ['name' => 'x']];
        foreach ($operations as $tool => $args) {
            $result = json_decode($guard->run($tool, $args, $this->spy()), true);
            $this->assertSame('confirmation_required', $result['error'], $tool);
        }
        $this->assertSame(0, $this->runs);
        $this->assertSame(3, $this->count_pending());
    }

    /**
     * JEDOMI-SEC-001: reading tools run without the guard getting in the way.
     */
    public function test_reading_tools_run(): void {
        $this->resetAfterTest();
        [$agent] = $this->course_agent([]);
        $guard = new write_guard($agent);

        $guard->run('execute_sql', ['sql' => 'SELECT 1'], $this->spy());
        $guard->run('ws_execute', ['wsname' => 'core_course_get_courses_by_field', 'params' => '{}'], $this->spy());

        $this->assertSame(2, $this->runs);
        $this->assertSame([], $guard->get_done());
    }

    /**
     * JEDOMI-SEC-001: the run stops when the approved writes of one run exceed the cap.
     */
    public function test_run_stops_at_the_write_cap(): void {
        $this->resetAfterTest();
        [$agent, , $teacher] = $this->course_agent();
        $guard = new write_guard($agent);
        for ($i = 0; $i < write_guard::MAX_WRITES_PER_RUN; $i++) {
            $guard->run('ws_execute', $this->message_to([(int) $teacher->id]), $this->spy());
        }

        try {
            $guard->run('ws_execute', $this->message_to([(int) $teacher->id]), $this->spy());
            $this->fail('The write over the cap must stop the run');
        } catch (write_cap_exceeded $e) {
            $this->assertInstanceOf(run_failure::class, $e);
        }
        $this->assertSame(write_guard::MAX_WRITES_PER_RUN, $this->runs);
    }

    /**
     * JEDOMI-SEC-001: the run stops when the distinct recipients of one run exceed the cap.
     */
    public function test_run_stops_at_the_recipient_cap(): void {
        $this->resetAfterTest();
        [$agent, $course] = $this->course_agent();
        $ids = [];
        for ($i = 0; $i <= write_guard::MAX_RECIPIENTS_PER_RUN; $i++) {
            $ids[] = (int) $this->getDataGenerator()->create_and_enrol($course, 'student')->id;
        }
        $guard = new write_guard($agent);
        $guard->run('ws_execute', $this->message_to(array_slice($ids, 0, write_guard::MAX_RECIPIENTS_PER_RUN)), $this->spy());

        $this->expectException(write_cap_exceeded::class);
        $guard->run('ws_execute', $this->message_to([end($ids)]), $this->spy());
    }

    /**
     * JEDOMI-SEC-001: the creator gets a summary of the writes run, held and blocked, without their contents.
     */
    public function test_creator_gets_a_summary_of_the_run(): void {
        $this->resetAfterTest();
        [$agent, $course, $teacher] = $this->course_agent();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $outsider = $this->getDataGenerator()->create_user();
        $guard = new write_guard($agent);
        $guard->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy());
        $guard->run('ws_execute', $this->message_to([(int) $outsider->id]), $this->spy());
        $guard->run('create_agent', ['prompt' => 'Secret prompt text'], $this->spy());
        $sink = $this->redirectMessages();

        $this->assertTrue($guard->notify_creator());

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertSame('local_jedomi', $message->component);
        $this->assertSame('agentwrites', $message->eventtype);
        $this->assertSame((int) $teacher->id, (int) $message->useridto);
        $this->assertStringContainsString('Reminder', $message->subject);
        $this->assertStringContainsString(self::SEND, $message->fullmessage);
        $this->assertStringNotContainsString('Secret prompt text', $message->fullmessage);
        $this->assertStringContainsString(get_string('agent_writes_held_heading', 'local_jedomi', 1), $message->fullmessage);
        $this->assertStringContainsString(get_string('agent_writes_blocked_heading', 'local_jedomi', 1), $message->fullmessage);
    }

    /**
     * JEDOMI-SEC-001: without any write in the run nothing is sent.
     */
    public function test_no_summary_without_writes(): void {
        $this->resetAfterTest();
        [$agent] = $this->course_agent();
        $sink = $this->redirectMessages();

        $this->assertFalse((new write_guard($agent))->notify_creator());
        $this->assertSame(0, $sink->count());
    }

    /**
     * Pending actions stored.
     *
     * @return int
     */
    private function count_pending(): int {
        global $DB;
        return $DB->count_records(pending_action_service::TABLE);
    }
}
