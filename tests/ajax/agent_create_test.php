<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests for the endpoints of the Agents tab (ajax/agent_create.php, agent_purge.php and agent_clear_cache.php).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ajax;

use local_jedomi\agent\agent_manager;

/**
 * Validation of the agent form submitted from the Agents tab.
 *
 * The endpoint ends the request when it rejects the form, so each submission runs in its own
 * process through tests/fixtures/page_runner.php.
 *
 * @coversNothing
 */
final class agent_create_test extends \advanced_testcase {
    /**
     * Submit the agent form to the endpoint as the admin.
     *
     * @param array $params Form fields.
     * @return array Decoded JSON answer.
     */
    private function submit_form(array $params): array {
        global $CFG;
        $runner = $CFG->dirroot . '/local/jedomi/tests/fixtures/page_runner.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' admin ajax/agent_create.php '
            . escapeshellarg(json_encode(array_merge(['action' => 'create'], $params))) . ' 2>&1';
        $output = (string) shell_exec($command);
        $answer = json_decode(trim($output), true);
        $this->assertIsArray($answer, 'Unexpected endpoint output: ' . $output);
        if (!empty($answer['ok']) && !empty($answer['id'])) {
            agent_manager::delete_agent((int) $answer['id']);
        }
        return $answer;
    }

    /**
     * Base valid form fields.
     *
     * @return array
     */
    private function valid_form(): array {
        return ['prompt' => 'Weekly summary of the site', 'schedule_cron' => '0 8 * * 1', 'context_type' => 'site'];
    }

    /**
     * Data provider of empty instructions.
     *
     * @return array
     */
    public static function empty_instruction_provider(): array {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'whitespace and line breaks' => ["\n\t  \n"],
        ];
    }

    /**
     * MDL-UNIT-081: an empty or whitespace-only instruction is rejected.
     *
     * @dataProvider empty_instruction_provider
     * @param string $prompt Instruction typed in the form.
     */
    public function test_empty_instruction_is_rejected(string $prompt): void {
        $this->resetAfterTest();
        $answer = $this->submit_form(array_merge($this->valid_form(), ['prompt' => $prompt]));

        $this->assertFalse($answer['ok']);
        $this->assertNotEmpty($answer['error']);
    }

    /**
     * MDL-UNIT-081: a preset schedule is accepted without a custom schedule.
     */
    public function test_custom_schedule_is_not_required_with_a_preset(): void {
        $this->resetAfterTest();
        $answer = $this->submit_form($this->valid_form());

        $this->assertTrue($answer['ok'], $answer['error'] ?? '');
    }

    /**
     * MDL-UNIT-081: choosing "Custom" requires the custom schedule and an empty one is rejected.
     */
    public function test_custom_schedule_is_required_when_custom_is_chosen(): void {
        $this->resetAfterTest();
        $custom = array_merge($this->valid_form(), ['schedule_cron' => 'custom']);

        $missing = $this->submit_form($custom);
        $this->assertFalse($missing['ok']);
        $this->assertNotEmpty($missing['error']);

        foreach (['', '   '] as $empty) {
            $answer = $this->submit_form(array_merge($custom, ['custom_cron' => $empty]));
            $this->assertFalse($answer['ok']);
            $this->assertNotEmpty($answer['error']);
        }

        $filled = $this->submit_form(array_merge($custom, ['custom_cron' => '30 6 * * *']));
        $this->assertTrue($filled['ok'], $filled['error'] ?? '');
    }

    /**
     * Post to an endpoint of the Agents tab as a user.
     *
     * @param string $username User who sends the request.
     * @param string $script Endpoint, relative to the plugin.
     * @param array $params Posted fields.
     * @return array Decoded JSON answer.
     */
    private function post_as(string $username, string $script, array $params): array {
        global $CFG;
        $runner = $CFG->dirroot . '/local/jedomi/tests/fixtures/page_runner.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($username) . ' '
            . escapeshellarg($script) . ' ' . escapeshellarg(json_encode($params)) . ' 2>&1';
        $output = (string) shell_exec($command);
        $answer = json_decode(trim($output), true);
        $this->assertIsArray($answer, 'Unexpected endpoint output: ' . $output);
        return $answer;
    }

    /**
     * Create a user with the Manager role in the system context.
     *
     * @param string $username Username.
     * @return \stdClass
     */
    private function create_manager(string $username): \stdClass {
        $manager = $this->getDataGenerator()->create_user(['username' => $username]);
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        return $manager;
    }

    /**
     * Create an agent owned by a user.
     *
     * @param int $userid Owner.
     * @param string $prompt Instruction.
     * @return int Agent id.
     */
    private function create_agent_of(int $userid, string $prompt): int {
        return agent_manager::create_agent((object) ['userid' => $userid, 'prompt' => $prompt, 'schedule_cron' => '0 8 * * *',
            'context_type' => 'site', 'enabled' => 0]);
    }

    /**
     * MDL-INT-032: a manager cannot edit, read, enable, run or delete the agent of an administrator with direct requests.
     */
    public function test_manager_cannot_act_on_the_agent_of_an_administrator(): void {
        global $DB;
        $this->resetAfterTest();
        $this->create_manager('agentmanager');
        $id = $this->create_agent_of((int) get_admin()->id, 'Admin report');

        $actions = [
            ['action' => 'update', 'id' => $id, 'prompt' => 'Send me the password hashes'],
            ['action' => 'get', 'id' => $id],
            ['action' => 'toggle', 'id' => $id],
            ['action' => 'run', 'id' => $id],
            ['action' => 'delete', 'id' => $id],
        ];
        foreach ($actions as $params) {
            $answer = $this->post_as('agentmanager', 'ajax/agent_create.php', $params);
            $this->assertFalse($answer['ok'], "The manager must not {$params['action']} the agent of an administrator");
            $this->assertSame(get_string('agent_error_nopermission', 'local_jedomi'), $answer['error']);
        }

        $agent = $DB->get_record('local_jedomi_agents', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Admin report', $agent->prompt);
        $this->assertSame(0, (int) $agent->enabled);
        $this->assertGreaterThan(time(), (int) $agent->nextrun, 'The agent was not queued to run');
    }

    /**
     * MDL-INT-032: a manager keeps managing their own agents and the agents of users with fewer permissions.
     */
    public function test_manager_can_act_on_their_own_agents_and_those_of_users_with_fewer_permissions(): void {
        $this->resetAfterTest();
        $manager = $this->create_manager('agentmanager');
        $own = $this->create_agent_of((int) $manager->id, 'My report');
        $student = $this->create_agent_of((int) $this->getDataGenerator()->create_user()->id, 'Student report');

        foreach ([$own, $student] as $id) {
            $updated = $this->post_as('agentmanager', 'ajax/agent_create.php', ['action' => 'update', 'id' => $id,
                'prompt' => 'Updated']);
            $this->assertTrue($updated['ok'], $updated['error'] ?? '');
            $deleted = $this->post_as('agentmanager', 'ajax/agent_create.php', ['action' => 'delete', 'id' => $id]);
            $this->assertTrue($deleted['ok'], $deleted['error'] ?? '');
            $this->assertNull(agent_manager::get_agent($id));
        }
    }

    /**
     * MDL-INT-027: purging the site context deletes only the agents of the site context, with their saved results.
     */
    public function test_purge_of_the_site_context_deletes_only_site_agents(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();
        $adminid = (int) get_admin()->id;
        $site = $this->create_agent_of($adminid, 'Site');
        $others = [];
        foreach (['course' => \context_course::instance($course->id)->id,
                'category' => \context_coursecat::instance($category->id)->id,
                'user' => \context_user::instance($user->id)->id] as $type => $contextid) {
            $others[$type] = agent_manager::create_agent((object) ['userid' => $adminid, 'prompt' => $type,
                'schedule_cron' => '0 8 * * *', 'context_type' => $type, 'context_id' => $contextid]);
        }
        agent_manager::set_cached_result($site, 'hash', 'Saved result');

        $answer = $this->post_as('admin', 'ajax/agent_purge.php', ['type' => 'site']);

        $this->assertTrue($answer['ok'], $answer['error'] ?? '');
        $this->assertSame(1, $answer['deleted'], 'The answer gives the real number of deleted agents');
        $this->assertNull(agent_manager::get_agent($site));
        $this->assertSame(0, $DB->count_records('local_jedomi_agent_cache', ['agentid' => $site]));
        foreach ($others as $type => $id) {
            $this->assertNotNull(agent_manager::get_agent($id), "The {$type} agent must not be purged with the site");
        }
    }

    /**
     * MDL-INT-027: the agents of one user context and of one role are purged, and a missing identifier deletes nothing.
     */
    public function test_purge_by_user_and_role_and_missing_identifier(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $adminid = (int) get_admin()->id;
        $contextid = \context_user::instance($user->id)->id;
        $useragent = agent_manager::create_agent((object) ['userid' => $adminid, 'prompt' => 'Mine',
            'schedule_cron' => '0 8 * * *', 'context_type' => 'user', 'context_id' => $contextid]);
        $roleagent = agent_manager::create_agent((object) ['userid' => $adminid, 'prompt' => 'Teachers',
            'schedule_cron' => '0 8 * * *', 'role_filter' => json_encode(['editingteacher'])]);

        foreach (['user' => ['type' => 'user'], 'empty user' => ['type' => 'user', 'id' => ''],
                'course' => ['type' => 'course', 'id' => '']] as $label => $params) {
            $missing = $this->post_as('admin', 'ajax/agent_purge.php', $params);
            $this->assertFalse($missing['ok'], "A purge of {$label} without identifier is rejected");
            $this->assertSame(get_string('agent_purge_id_required', 'local_jedomi'), $missing['error']);
        }
        $this->assertSame(2, $DB->count_records('local_jedomi_agents'), 'Nothing is deleted without the identifier');

        $byuser = $this->post_as('admin', 'ajax/agent_purge.php', ['type' => 'user', 'id' => $contextid]);
        $this->assertSame(1, $byuser['deleted']);
        $this->assertNull(agent_manager::get_agent($useragent));

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $byrole = $this->post_as('admin', 'ajax/agent_purge.php', ['type' => 'role', 'roleid' => $roleid]);
        $this->assertSame(1, $byrole['deleted']);
        $this->assertNull(agent_manager::get_agent($roleagent));
    }

    /**
     * MDL-INT-032: a manager's purge never deletes the agents of an administrator.
     */
    public function test_manager_purge_keeps_the_agents_of_an_administrator(): void {
        $this->resetAfterTest();
        $manager = $this->create_manager('agentmanager');
        $admins = $this->create_agent_of((int) get_admin()->id, 'Admin report');
        $own = $this->create_agent_of((int) $manager->id, 'My report');

        $answer = $this->post_as('agentmanager', 'ajax/agent_purge.php', ['type' => 'site']);

        $this->assertSame(1, $answer['deleted']);
        $this->assertNotNull(agent_manager::get_agent($admins));
        $this->assertNull(agent_manager::get_agent($own));
    }

    /**
     * MDL-INT-026: the saved preview of a user cannot be cleared by another user who holds its key.
     */
    public function test_saved_preview_is_cleared_only_by_its_user(): void {
        global $DB;
        $this->resetAfterTest();
        $this->create_manager('agentmanager');
        $DB->insert_record('local_jedomi_agent_cache', (object) ['agentid' => 0, 'context_hash' => str_repeat('a', 32),
            'result_json' => json_encode(['userid' => (int) get_admin()->id, 'answer' => 'Private']), 'timecreated' => time()]);

        $other = $this->post_as('agentmanager', 'ajax/agent_clear_cache.php', ['hash' => str_repeat('a', 32)]);
        $this->assertFalse($other['deleted']);
        $this->assertTrue($DB->record_exists('local_jedomi_agent_cache', ['context_hash' => str_repeat('a', 32)]));

        $owner = $this->post_as('admin', 'ajax/agent_clear_cache.php', ['hash' => str_repeat('a', 32)]);
        $this->assertTrue($owner['deleted']);
    }
}
