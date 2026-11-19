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

    /** @var array[] Arguments the guard passed to the spy executor, one entry per call (null: none passed). */
    private array $executed = [];

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
        return function (?array $args = null): string {
            $this->runs++;
            $this->executed[] = $args;
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
            // READ-001: the message names the cap that was reached.
            $this->assertSame(
                get_string('agent_write_cap_writes', 'local_jedomi', write_guard::MAX_WRITES_PER_RUN),
                $e->getMessage()
            );
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

        // READ-001: the message names the cap of recipients, not the one of writes.
        $this->expectException(write_cap_exceeded::class);
        $cap = write_guard::MAX_RECIPIENTS_PER_RUN;
        $this->expectExceptionMessage(get_string('agent_write_cap_recipients', 'local_jedomi', $cap));
        $guard->run('ws_execute', $this->message_to([end($ids)]), $this->spy());
    }

    /**
     * REL-003, READ-001: going over the cap of held writes does not stop the run as a failure: the extra write is
     * neither held nor run, the AI is told so, and the creator gets the message of that cap.
     */
    public function test_held_cap_is_not_a_run_failure(): void {
        $this->resetAfterTest();
        [$agent, , $teacher] = $this->course_agent([]);
        $guard = new write_guard($agent);
        for ($i = 0; $i < write_guard::MAX_WRITES_PER_RUN; $i++) {
            $guard->run('create_agent', ['prompt' => 'Agent ' . $i], $this->spy());
        }

        $result = json_decode($guard->run('create_agent', ['prompt' => 'One more'], $this->spy()), true);

        $this->assertSame('held_cap_reached', $result['error']);
        $this->assertSame(0, $this->runs);
        $this->assertSame(write_guard::MAX_WRITES_PER_RUN, $this->count_pending());
        $sink = $this->redirectMessages();
        $this->assertTrue($guard->notify_creator());
        $text = $sink->get_messages()[0]->fullmessage;
        $this->assertStringContainsString(
            get_string('agent_writes_held_cap', 'local_jedomi', write_guard::MAX_WRITES_PER_RUN),
            $text
        );
        $writescap = get_string('agent_write_cap_writes', 'local_jedomi', write_guard::MAX_WRITES_PER_RUN);
        $this->assertStringNotContainsString($writescap, $text);
        $this->assertSame((int) $teacher->id, (int) $sink->get_messages()[0]->useridto);
    }

    /**
     * REL-002: the same write held by the agent in two runs waits once for the creator.
     */
    public function test_same_write_held_in_two_runs_waits_once(): void {
        $this->resetAfterTest();
        [$agent, $course] = $this->course_agent([]);
        $agent = $this->store($agent);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        (new write_guard($agent))->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy());
        $second = new write_guard($agent);
        $result = json_decode($second->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy()), true);

        $this->assertSame('confirmation_required', $result['error']);
        $this->assertSame(1, $this->count_pending());
        $this->assertSame(0, $this->runs);
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
     * Store an agent record so the guard can keep the writes of its run, and return it with its id.
     *
     * @param \stdClass $agent Agent fields.
     * @return \stdClass
     */
    private function store(\stdClass $agent): \stdClass {
        global $DB;
        $record = clone $agent;
        unset($record->id);
        $record->prompt = 'Remind the students';
        $record->schedule_cron = '0 8 * * *';
        $record->enabled = 1;
        $record->nextrun = time() - 60;
        $record->failcount = 0;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = (int) $DB->insert_record('local_jedomi_agents', $record);
        return $record;
    }

    /**
     * JEDOMI-SEC-014 (RE-POC-2c): an approved write of a site agent whose scope cannot be tied to a concrete target (an
     * injected category without a parent, or at the top level) is held for the creator instead of running; with a real
     * parent category it runs.
     */
    public function test_site_agent_approved_write_without_a_concrete_target_is_held(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $agent = (object) ['id' => 43, 'userid' => (int) get_admin()->id, 'name' => 'Site', 'context_type' => 'site',
            'context_id' => null, 'approvedwrites' => json_encode(['core_course_create_categories'])];
        $guard = new write_guard($agent);
        $create = static fn(array $category): array => ['function' => 'core_course_create_categories',
            'params' => ['categories' => [$category]]];

        $noparent = json_decode($guard->run('call_webservice', $create(['name' => 'AUDIT-INJECTED']), $this->spy()), true);
        $toplevel = json_decode($guard->run('call_webservice', $create(['name' => 'x', 'parent' => 0]), $this->spy()), true);

        $this->assertSame('confirmation_required', $noparent['error']);
        $this->assertSame('confirmation_required', $toplevel['error']);
        $this->assertSame(0, $this->runs);
        $this->assertSame(2, $this->count_pending());

        $guard->run('call_webservice', $create(['name' => 'x', 'parent' => (int) $category->id]), $this->spy());
        $this->assertSame(1, $this->runs);
    }

    /**
     * RISK-001: the members of a cohort a write targets count toward the recipient cap of the run.
     */
    public function test_cohort_members_count_toward_the_recipient_cap(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $cohort = $gen->create_cohort();
        for ($i = 0; $i <= write_guard::MAX_RECIPIENTS_PER_RUN; $i++) {
            $student = $gen->create_and_enrol($course, 'student');
            cohort_add_member($cohort->id, $student->id);
        }
        $agent = (object) ['id' => 44, 'userid' => (int) get_admin()->id, 'name' => 'Site', 'context_type' => 'site',
            'context_id' => null, 'approvedwrites' => json_encode(['core_cohort_add_cohort_members'])];
        $guard = new write_guard($agent);

        try {
            $guard->run('ws_execute', ['wsname' => 'core_cohort_add_cohort_members', 'params' => ['members' => [[
                'cohorttype' => ['type' => 'id', 'value' => (int) $cohort->id],
                'usertype' => ['type' => 'username', 'value' => $student->username],
            ]]]], $this->spy());
            $this->fail('A write to more members than the recipient cap must stop the run');
        } catch (write_cap_exceeded $e) {
            $this->assertSame(0, $this->runs);
        }
    }

    /**
     * RES-006: a write that reached execution counts toward the cap of the run even when it fails with an exception
     * after it was applied.
     */
    public function test_a_write_that_fails_while_running_counts_toward_the_cap(): void {
        $this->resetAfterTest();
        [$agent, , $teacher] = $this->course_agent();
        $guard = new write_guard($agent);
        $failing = function (): string {
            $this->runs++;
            throw new \moodle_exception('generalexceptionmessage', 'error', '', 'Applied, then failed');
        };
        for ($i = 0; $i < write_guard::MAX_WRITES_PER_RUN; $i++) {
            try {
                $guard->run('ws_execute', $this->message_to([(int) $teacher->id]), $failing);
                $this->fail('The failure of the write must reach the run');
            } catch (\moodle_exception $e) {
                $this->assertSame('generalexceptionmessage', $e->errorcode);
            }
        }

        $this->assertCount(write_guard::MAX_WRITES_PER_RUN, $guard->get_done());
        $this->assertFalse($guard->get_done()[0]['ok']);
        $this->expectException(write_cap_exceeded::class);
        $guard->run('ws_execute', $this->message_to([(int) $teacher->id]), $this->spy());
    }

    /**
     * Run a callable and return what it threw, or null when it threw nothing.
     *
     * @param callable $call Callable to run.
     * @return \Throwable|null
     */
    private function thrown(callable $call): ?\Throwable {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }
        return null;
    }

    /**
     * RES-003 (C2): a retry of a failed run (a new run before a successful one) skips only the writes a failed attempt
     * made successfully. A write that failed cleanly (an error result) runs again and counts again; a write whose
     * outcome is unknown (it threw, or the process died before its outcome was recorded) is held for the creator. After
     * a successful run the writes run again.
     */
    public function test_a_retry_skips_only_the_writes_already_made_successfully(): void {
        $this->resetAfterTest();
        [$agent, $course, $teacher] = $this->course_agent();
        $agent = $this->store($agent);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $first = new write_guard($agent);
        $first->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy());
        $first->run('ws_execute', $this->message_to([(int) $teacher->id]), function (): string {
            $this->runs++;
            return json_encode(['error' => 'invalid_parameter']);
        });
        $throwing = function (): string {
            $this->runs++;
            throw new \coding_exception('Applied, then failed');
        };
        $both = $this->message_to([(int) $student->id, (int) $teacher->id]);
        $failure = $this->thrown(fn() => $first->run('ws_execute', $both, $throwing));
        $this->assertInstanceOf(\coding_exception::class, $failure);
        $this->assertSame(3, $this->runs);

        $retry = new write_guard($agent);
        $done = json_decode($retry->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy()), true);
        $rerun = json_decode($retry->run('ws_execute', $this->message_to([(int) $teacher->id]), $this->spy()), true);
        $unknown = json_decode(
            $retry->run('ws_execute', $this->message_to([(int) $student->id, (int) $teacher->id]), $this->spy()),
            true
        );

        $this->assertSame('already_done', $done['status']);
        $this->assertTrue($rerun['success'], 'A write that failed cleanly runs again');
        $this->assertSame('confirmation_required', $unknown['error'], 'A write of unknown outcome waits for the creator');
        $this->assertSame(4, $this->runs);
        $this->assertSame(1, $this->count_pending());
        $this->assertCount(4, agent_manager::get_run_writes((int) $agent->id), 'The write run again counts again');

        agent_manager::mark_run((int) $agent->id, true, 'Done');
        (new write_guard($agent))->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy());
        $this->assertSame(5, $this->runs, 'A new scheduled run starts without the writes of the previous one');
    }

    /**
     * RES-003 (C2): a write recorded without an outcome (the process died while it ran) is held in the retry.
     */
    public function test_a_write_without_a_recorded_outcome_is_held_in_the_retry(): void {
        $this->resetAfterTest();
        [$agent, $course] = $this->course_agent();
        $agent = $this->store($agent);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $first = new write_guard($agent);
        $first->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy());
        $writes = agent_manager::get_run_writes((int) $agent->id);
        $writes[0]['ok'] = null;
        agent_manager::set_run_writes((int) $agent->id, $writes);

        $retry = new write_guard($agent);
        $result = json_decode($retry->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy()), true);

        $this->assertSame('confirmation_required', $result['error']);
        $this->assertSame(1, $this->runs);
    }

    /**
     * REL-002 (C3): when the writes kept for the run are corrupt (not a JSON list, or rows without a fingerprint) the
     * guard fails closed: every write of the run is held for the creator, none runs automatically.
     */
    public function test_corrupt_run_writes_hold_every_write(): void {
        global $DB;
        $this->resetAfterTest();
        [$agent, $course] = $this->course_agent();
        $agent = $this->store($agent);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        foreach (['not json', '{"f":"x"}', '[{"ok":true,"r":[]}]', '[5]'] as $stored) {
            $DB->set_field('local_jedomi_agents', 'runwrites', $stored, ['id' => $agent->id]);
            $guard = new write_guard($agent);
            $result = json_decode($guard->run('ws_execute', $this->message_to([(int) $student->id]), $this->spy()), true);
            $this->assertSame('confirmation_required', $result['error'], $stored);
        }
        $this->assertSame(0, $this->runs);
    }

    /**
     * R3: the fingerprint is taken from the validated parameters with their keys sorted: an id written as text and as a
     * number is the same write once Moodle validated it, and the order of the keys does not matter. Texts are kept as
     * they are, so texts that differ only in white space are two different writes (RISK-004).
     */
    public function test_fingerprint_of_validated_parameters(): void {
        $this->resetAfterTest();
        [$agent, $course] = $this->course_agent();
        $agent = $this->store($agent);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $message = static fn($userid, string $text): array => ['wsname' => self::SEND, 'params' => ['messages' => [
            ['text' => $text, 'touserid' => $userid],
        ]]];
        (new write_guard($agent))->run('ws_execute', $message((string) $student->id, 'Please submit'), $this->spy());

        $retry = new write_guard($agent);
        $same = json_decode($retry->run('ws_execute', ['wsname' => self::SEND, 'params' => json_encode(['messages' => [
            ['touserid' => (int) $student->id, 'text' => 'Please submit'],
        ]])], $this->spy()), true);
        $spaces = json_decode($retry->run('ws_execute', $message((int) $student->id, 'Please  submit '), $this->spy()), true);

        $this->assertSame('already_done', $same['status']);
        $this->assertTrue($spaces['success'], 'A text with other white space is another write');
        $this->assertSame(2, $this->runs);
    }

    /**
     * RES-003: the writes made by the failed attempts of a run count toward the cap of its retry, so retrying never
     * makes more writes than one run may make.
     */
    public function test_writes_of_a_failed_attempt_count_toward_the_cap_of_the_retry(): void {
        $this->resetAfterTest();
        [$agent, , $teacher] = $this->course_agent();
        $agent = $this->store($agent);
        $first = new write_guard($agent);
        for ($i = 0; $i < write_guard::MAX_WRITES_PER_RUN; $i++) {
            $first->run('ws_execute', ['wsname' => self::SEND, 'params' => json_encode(['messages' => [
                ['touserid' => (int) $teacher->id, 'text' => 'Note ' . $i],
            ]])], $this->spy());
        }

        $this->expectException(write_cap_exceeded::class);
        (new write_guard($agent))->run('ws_execute', $this->message_to([(int) $teacher->id]), $this->spy());
    }

    /**
     * FIX-002 and R3: "007" and "7" in an idnumber (a text for Moodle) are two writes, while a parent category written
     * as "5" and as 5 is the same write, because Moodle validates it as an integer before the fingerprint is taken.
     */
    public function test_only_identifiers_written_as_text_match_numbers(): void {
        $this->resetAfterTest();
        $agent = $this->store((object) ['userid' => (int) get_admin()->id, 'name' => 'Site', 'context_type' => 'site',
            'context_id' => null, 'approvedwrites' => json_encode(['core_course_create_categories'])]);
        $parent = (int) $this->getDataGenerator()->create_category()->id;
        $create = static fn($idnumber, $parentid): array => ['wsname' => 'core_course_create_categories',
            'params' => ['categories' => [['name' => 'x', 'idnumber' => $idnumber, 'parent' => $parentid]]]];
        (new write_guard($agent))->run('ws_execute', $create('007', (string) $parent), $this->spy());

        $retry = new write_guard($agent);
        $other = json_decode($retry->run('ws_execute', $create('7', (string) $parent), $this->spy()), true);
        $same = json_decode($retry->run('ws_execute', $create('007', $parent), $this->spy()), true);

        $this->assertTrue($other['success'], 'Another idnumber is another write');
        $this->assertSame('already_done', $same['status'], 'The same parent written as a number is the same write');
        $this->assertSame(2, $this->runs);
    }

    /**
     * Agent of the site, or of a category, that may run some writes without confirmation.
     *
     * @param array $approved Approved write functions.
     * @param \core_course_category|null $category Category of the agent; null for a site agent.
     * @return \stdClass
     */
    private function agent_of(array $approved, ?\core_course_category $category = null): \stdClass {
        return (object) ['id' => 45, 'userid' => (int) get_admin()->id, 'name' => 'Admin',
            'context_type' => $category ? 'category' : 'site',
            'context_id' => $category ? (int) \context_coursecat::instance($category->id)->id : null,
            'approvedwrites' => json_encode($approved)];
    }

    /**
     * R1 (RISK-001): the guard checks the parameters Moodle validates, not the raw ones. Identifiers written as a float
     * or a boolean, which Moodle turns into integers, never run automatically: for a site agent and for a category agent
     * updating a course outside its category the write is held, and nothing runs.
     */
    public function test_coerced_identifiers_never_run_automatically(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $category = $gen->create_category();
        $outside = $gen->create_course();
        $course = $gen->create_course(['category' => $category->id]);
        $student = $gen->create_and_enrol($course, 'student');
        $functions = ['core_course_update_courses', 'core_course_update_categories', 'core_role_assign_roles'];
        $update = static fn($id): array => ['wsname' => 'core_course_update_courses',
            'params' => ['courses' => [['id' => $id, 'categoryid' => (int) $category->id]]]];
        $calls = [
            'site: course 99.0' => [null, $update(99.0)],
            'site: front page 1.0' => [null, $update(1.0)],
            'site: true' => [null, $update(true)],
            'site: category 99.0' => [null, ['wsname' => 'core_course_update_categories',
                'params' => ['categories' => [['id' => 99.0, 'parent' => (int) $category->id]]]]],
            'site: role 1.0' => [null, ['wsname' => 'core_role_assign_roles', 'params' => ['assignments' => [
                ['roleid' => 1.0, 'userid' => (int) $student->id, 'contextid' => \context_system::instance()->id],
            ]]]],
            'category: course outside' => [$category, $update((float) $outside->id)],
        ];

        foreach ($calls as $label => [$agentcategory, $args]) {
            $guard = new write_guard($this->agent_of($functions, $agentcategory));
            $result = json_decode($guard->run('ws_execute', $args, $this->spy()), true);
            $this->assertArrayHasKey('error', $result, $label);
        }

        $this->assertSame(0, $this->runs, 'Nothing runs');
        $this->assertSame((int) $outside->category, (int) $DB->get_field('course', 'category', ['id' => $outside->id]));
        $held = $DB->get_records(pending_action_service::TABLE);
        $this->assertNotEmpty($held);
        foreach ($held as $record) {
            $this->assertStringNotContainsString('99.0', $record->argsjson, 'The held write keeps the validated parameters');
        }
    }

    /**
     * R1: a write runs with exactly the parameters the guard checked (validated by Moodle), and a write whose parameters
     * Moodle rejects returns the validation error: it neither runs nor waits for approval.
     */
    public function test_writes_run_with_the_validated_parameters_and_invalid_ones_are_rejected(): void {
        $this->resetAfterTest();
        [$agent, $course] = $this->course_agent();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $guard = new write_guard($agent);

        $guard->run('ws_execute', ['wsname' => self::SEND, 'params' => json_encode(['messages' => [
            ['touserid' => (string) $student->id, 'text' => 'Reminder'],
        ]])], $this->spy());
        $invalid = json_decode($guard->run('ws_execute', ['wsname' => self::SEND, 'params' => ['messages' => [
            ['text' => 'No recipient'],
        ]]], $this->spy()), true);

        $this->assertSame(1, $this->runs);
        $message = $this->executed[0]['params']['messages'][0];
        $this->assertSame((int) $student->id, $message['touserid'], 'The id runs as the integer Moodle validated');
        $this->assertSame(self::SEND, $this->executed[0]['wsname']);
        $this->assertSame(self::SEND, $invalid['function']);
        $this->assertStringContainsString('touserid', $invalid['error']);
        $this->assertSame(0, $this->count_pending(), 'An invalid write is not held');
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
