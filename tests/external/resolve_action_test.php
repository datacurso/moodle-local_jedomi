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
     * @return array
     */
    private function resolve(int $id, bool $confirm): array {
        return external_api::clean_returnvalue(resolve_action::execute_returns(), resolve_action::execute($id, $confirm));
    }

    /**
     * A3: confirming runs the action once and removes it.
     */
    public function test_confirm_runs_the_action_and_removes_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $agentid = $this->create_agent((int) get_admin()->id);
        $pending = pending_action_service::hold((int) get_admin()->id, 'delete_agent', ['id' => $agentid]);

        $result = $this->resolve($pending['id'], true);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(get_string('confirm_done', 'local_jedomi'), $result['message']);
        $this->assertNull(agent_manager::get_agent($agentid));
        $this->assertFalse($DB->record_exists(pending_action_service::TABLE, ['id' => $pending['id']]));
    }

    /**
     * A3: cancelling removes the action without running it.
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
     * A3: an expired action is rejected, removed and not run.
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
     * A4: the role is checked again when confirming; without it the action is not run.
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
        $result = $this->resolve($pending['id'], true);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(get_string('pending_action_denied', 'local_jedomi'), $result['message']);
        $this->assertNotNull(agent_manager::get_agent($agentid));
    }

    /**
     * A3: a failing action returns a short message, never the internal error.
     */
    public function test_failed_action_returns_a_short_message(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $pending = pending_action_service::hold((int) get_admin()->id, 'delete_agent', ['id' => 999999]);

        $result = $this->resolve($pending['id'], true);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(get_string('pending_action_failed', 'local_jedomi'), $result['message']);
    }

    /**
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
     */
    public function test_guest_cannot_resolve(): void {
        $this->resetAfterTest();
        $this->setGuestUser();

        $this->expectException(\moodle_exception::class);
        resolve_action::execute(1, true);
    }
}
