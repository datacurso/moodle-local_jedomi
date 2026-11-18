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

namespace local_jedomi\external;

use core_external\external_api;
use local_jedomi\agent\agent_manager;
use local_jedomi\local\service\pending_action_service;

/**
 * Tests for confirming or cancelling an action held by the web chat.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\resolve_action
 * @covers     \local_jedomi\local\service\pending_action_service
 */
final class resolve_action_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the web service checks the plugin state through it.');
        }
    }

    /**
     * Create a scheduled agent.
     *
     * @param int $userid Owner.
     * @return int Agent id.
     */
    private function create_agent(int $userid): int {
        return agent_manager::create_agent((object) [
            'userid' => $userid,
            'name' => 'Daily report',
            'prompt' => 'Count the users',
            'schedule_cron' => '0 8 * * *',
        ]);
    }

    /**
     * Call the web service and clean its result like the AJAX layer does.
     *
     * @param int $id Pending action id.
     * @param bool $confirm Whether the user confirms.
     * @param string $keyword Confirmation keyword typed by the user.
     * @return array
     */
    private function resolve(int $id, bool $confirm, string $keyword = ''): array {
        return external_api::clean_returnvalue(
            resolve_action::execute_returns(),
            resolve_action::execute($id, $confirm, $keyword)
        );
    }

    /**
     * The confirmation keyword the panel asks the user to type for critical actions.
     *
     * @return string
     */
    private function keyword(): string {
        return \core_text::strtoupper(get_string('confirm_btn_yes', 'local_jedomi'));
    }

    /**
     * MDL-UNIT-096: an action run without errors gives the action executed message.
     * A3: confirming runs the action once and removes it.
     * MDL-INT-046: confirming runs the action and removes it.
     * MDL-INT-047: a critical action confirmed with the confirmation word runs.
     */
    public function test_confirm_runs_the_action_and_removes_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $agentid = $this->create_agent((int) get_admin()->id);
        $pending = pending_action_service::hold((int) get_admin()->id, 'delete_agent', ['id' => $agentid]);

        $result = $this->resolve($pending['id'], true, $this->keyword());

        $this->assertSame('executed', $result['status']);
        $this->assertSame(get_string('confirm_done', 'local_jedomi'), $result['message']);
        $this->assertNull(agent_manager::get_agent($agentid));
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $pending['id']]));
    }

    /**
     * A3: cancelling removes the action without running it.
     * MDL-INT-046: cancelling removes the action without running it.
     */
    public function test_cancel_removes_the_action_without_running_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $agentid = $this->create_agent((int) get_admin()->id);
        $pending = pending_action_service::hold((int) get_admin()->id, 'delete_agent', ['id' => $agentid]);

        $result = $this->resolve($pending['id'], false);

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame(get_string('confirm_cancelled', 'local_jedomi'), $result['message']);
        $this->assertNotNull(agent_manager::get_agent($agentid));
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $pending['id']]));
    }

    /**
     * A3: an action of another user is rejected and left untouched.
     * MDL-INT-046: an action of another user is rejected with its message.
     */
    public function test_action_of_another_user_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $agentid = $this->create_agent((int) get_admin()->id);
        $pending = pending_action_service::hold((int) get_admin()->id, 'delete_agent', ['id' => $agentid]);
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            $this->resolve($pending['id'], true);
            $this->fail('An action of another user must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('pending_action_not_found', $e->errorcode);
        }
        $this->assertNotNull(agent_manager::get_agent($agentid));
        $this->assertTrue($DB->record_exists(pending_action_service::TABLE, ['id' => $pending['id']]));
    }

    /**
     * RISK-003: through the web service a site administrator cannot approve a write held by the agent of another user,
     * but may cancel it.
     */
    public function test_administrator_cannot_approve_a_write_held_by_the_agent_of_another_user(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user();
        $agentid = $this->create_agent((int) $creator->id);
        $pending = pending_action_service::hold((int) $creator->id, 'delete_agent', ['id' => $agentid], $agentid);
        $this->setAdminUser();

        try {
            $this->resolve($pending['id'], true, $this->keyword());
            $this->fail('An administrator must not approve the held write of another creator');
        } catch (\moodle_exception $e) {
            $this->assertSame('pending_action_not_found', $e->errorcode);
        }
        $this->assertNotNull(agent_manager::get_agent($agentid));

        $this->assertSame('cancelled', $this->resolve($pending['id'], false)['status']);
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $pending['id']]));
        $this->assertNotNull(agent_manager::get_agent($agentid));
    }

    /**
     * MDL-UNIT-062: an action past its validity is rejected and not run.
     * A3: an expired action is rejected, removed and not run.
     * MDL-INT-046: an expired action is rejected with its message.
     */
    public function test_expired_action_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $agentid = $this->create_agent((int) get_admin()->id);
        $pending = pending_action_service::hold((int) get_admin()->id, 'delete_agent', ['id' => $agentid]);
        $DB->set_field(pending_action_service::TABLE, 'timecreated', time() - pending_action_service::EXPIRY - 1);

        try {
            $this->resolve($pending['id'], true);
            $this->fail('An expired action must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('pending_action_expired', $e->errorcode);
        }
        $this->assertNotNull(agent_manager::get_agent($agentid));
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $pending['id']]));
    }

    /**
     * A3: an action can be resolved only once.
     * MDL-INT-046: an action already resolved is rejected.
     */
    public function test_action_cannot_be_resolved_twice(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $pending = pending_action_service::hold((int) get_admin()->id, 'create_agent', [
            'prompt' => 'Count the users',
            'schedule_cron' => '0 8 * * *',
        ]);
        $this->resolve($pending['id'], true);

        try {
            $this->resolve($pending['id'], true);
            $this->fail('A resolved action must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('pending_action_not_found', $e->errorcode);
        }
        $this->assertCount(1, agent_manager::get_agents([])['items']);
    }

    /**
     * MDL-UNIT-096: a permission failure gives the no permission message.
     * A4: the role is checked again when confirming; without it the action is not run.
     * MDL-INT-046: the current role is checked again when confirming.
     */
    public function test_confirm_checks_the_role_again(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $sysctx = \context_system::instance();
        role_assign($managerroleid, $user->id, $sysctx->id);
        $this->setUser($user);
        $this->assertSame('manager', \local_jedomi_get_user_role());
        $agentid = $this->create_agent((int) get_admin()->id);
        $pending = pending_action_service::hold((int) $user->id, 'delete_agent', ['id' => $agentid]);

        role_unassign($managerroleid, $user->id, $sysctx->id);
        $result = $this->resolve($pending['id'], true, $this->keyword());

        $this->assertSame('failed', $result['status']);
        $this->assertSame(get_string('pending_action_denied', 'local_jedomi'), $result['message']);
        $this->assertNotNull(agent_manager::get_agent($agentid));
    }

    /**
     * MDL-UNIT-096: any other failure gives the could not complete message without internal details.
     * A3: a failing action returns a short message, never the internal error.
     * MDL-INT-046: an action that fails is left resolved and cannot be repeated.
     */
    public function test_failed_action_returns_a_short_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $pending = pending_action_service::hold((int) get_admin()->id, 'delete_agent', ['id' => 999999]);

        $result = $this->resolve($pending['id'], true, $this->keyword());

        $this->assertSame('failed', $result['status']);
        $this->assertSame(get_string('pending_action_failed', 'local_jedomi'), $result['message']);

        try {
            $this->resolve($pending['id'], true, $this->keyword());
            $this->fail('A failed action must not be run again');
        } catch (\moodle_exception $e) {
            $this->assertSame('pending_action_not_found', $e->errorcode);
        }
    }

    /**
     * MDL-UNIT-096: sending messages reports the sent and the failed ones.
     * MDL-INT-049: confirming the sending delivers to the valid recipient and reports exact sent and failed counts.
     * A5: confirming send_messages sends the messages and reports sent and failed.
     */
    public function test_confirm_send_messages_reports_sent_and_failed(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $student = $this->getDataGenerator()->create_user();
        $pending = pending_action_service::hold((int) get_admin()->id, 'send_messages', [
            'user_ids' => [(int) $student->id, 987654],
            'message' => 'Please submit your essay.',
        ]);
        $sink = $this->redirectMessages();

        $result = $this->resolve($pending['id'], true);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame((int) $student->id, (int) $sink->get_messages()[0]->useridto);
    }

    /**
     * Guests cannot resolve actions.
     * MDL-INT-046: a guest cannot resolve actions.
     */
    public function test_guest_cannot_resolve(): void {
        $this->resetAfterTest();
        $this->setGuestUser();

        $this->expectException(\moodle_exception::class);
        resolve_action::execute(1, true);
    }

    /**
     * Hold a send_messages action for the admin and confirm it.
     *
     * @param int[] $userids Recipients.
     * @return array Result of the web service.
     */
    private function confirm_send(array $userids): array {
        $pending = pending_action_service::hold((int) get_admin()->id, 'send_messages', [
            'user_ids' => $userids,
            'message' => 'Please submit your essay.',
        ]);
        return $this->resolve($pending['id'], true);
    }

    /**
     * MDL-UNIT-096: when every message is sent the result gives the sent count and no failed part.
     */
    public function test_send_messages_all_sent_has_no_failed_part(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $this->redirectMessages();

        $result = $this->confirm_send([(int) $one->id, (int) $two->id]);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(str_replace('{sent}', '2', get_string('messages_sent', 'local_jedomi')), $result['message']);
    }

    /**
     * MDL-UNIT-096: when some messages fail the result adds the failed count after the sent count.
     */
    public function test_send_messages_some_failed_adds_the_failed_part(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $one = $this->getDataGenerator()->create_user();
        $this->redirectMessages();

        $result = $this->confirm_send([(int) $one->id, 987654, 987655]);

        $expected = str_replace('{sent}', '1', get_string('messages_sent', 'local_jedomi')) . ' '
            . str_replace('{failed}', '2', get_string('messages_sent_fail', 'local_jedomi'));
        $this->assertSame('executed', $result['status']);
        $this->assertSame($expected, $result['message']);
    }

    /**
     * MDL-UNIT-096: a send without any delivered message is reported as no messages sent, not as success.
     */
    public function test_send_messages_none_sent_is_not_reported_as_success(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $sink = $this->redirectMessages();

        $result = $this->confirm_send([987654, 987655]);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame(get_string('no_messages_sent', 'local_jedomi'), $result['message']);
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * MDL-INT-047: a critical action confirmed without the confirmation word is rejected by the server and not run, and it
     * runs once the word is received.
     */
    public function test_critical_action_without_the_confirmation_word_is_not_run(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $agentid = $this->create_agent((int) get_admin()->id);
        $pending = pending_action_service::hold((int) get_admin()->id, 'delete_agent', ['id' => $agentid]);
        $this->assertSame('critical', $pending['severity'], 'Precondition: deleting an agent is a critical action');

        try {
            $result = $this->resolve($pending['id'], true);
        } catch (\moodle_exception $e) {
            $result = ['status' => 'rejected'];
        }

        $this->assertNotSame('executed', $result['status'], 'The server must reject the action without the confirmation word');
        $this->assertNotNull(agent_manager::get_agent($agentid), 'The critical action must not run without the confirmation word');

        $wrong = 'rejected';
        try {
            $wrong = $this->resolve($pending['id'], true, 'yes')['status'];
        } catch (\moodle_exception $e) {
            $wrong = 'rejected';
        }
        $this->assertNotSame('executed', $wrong, 'A wrong word must not confirm the critical action');
        $this->assertNotNull(agent_manager::get_agent($agentid));

        $this->assertSame(
            'executed',
            $this->resolve($pending['id'], true, $this->keyword())['status'],
            'With the confirmation word the critical action runs'
        );
        $this->assertNull(agent_manager::get_agent($agentid));
    }
}
