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
 * Unit tests for \local_jedomi\agent\agent_manager.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent;

defined('MOODLE_INTERNAL') || die();

/**
 * Test cases for agent_manager.
 *
 * @covers \local_jedomi\agent\agent_manager
 */
final class agent_manager_test extends \advanced_testcase {
    /**
     * Setup test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create a test agent record directly in the DB.
     *
     * @param  array $overrides
     * @return \stdClass
     */
    private function create_agent_record(array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $record = (object) array_merge([
            'userid'        => 1,
            'prompt'        => 'Test prompt',
            'schedule_cron' => '*/5 * * * *',
            'context_type'  => 'site',
            'context_id'    => null,
            'role_filter'   => null,
            'enabled'       => 1,
            'lastrun'       => null,
            'nextrun'       => $now - 60,
            'failcount'     => 0,
            'timecreated'   => $now,
            'timemodified'  => $now,
        ], $overrides);
        $record->id = $DB->insert_record('local_jedomi_agents', $record);
        return $record;
    }

    // ========================
    // CRUD — get_agent
    // ========================

    /**
     * Test get_agent returns the correct record.
     */
    public function test_get_agent(): void {
        $created = $this->create_agent_record();
        $agent = agent_manager::get_agent($created->id);
        $this->assertNotNull($agent);
        $this->assertEquals($created->id, $agent->id);
        $this->assertEquals($created->prompt, $agent->prompt);
    }

    /**
     * Test get_agent returns null for non-existent id.
     */
    public function test_get_agent_not_found(): void {
        $agent = agent_manager::get_agent(99999);
        $this->assertNull($agent);
    }

    // ========================
    // CRUD — create_agent
    // ========================

    /**
     * Test create_agent inserts a record and returns its id.
     */
    public function test_create_agent(): void {
        $data = (object) [
            'userid'        => 1,
            'prompt'        => 'Daily summary report',
            'schedule_cron' => '0 8 * * *',
            'context_type'  => 'site',
            'context_id'    => null,
            'role_filter'   => json_encode(['manager', 'teacher']),
            'enabled'       => 1,
        ];
        $id = agent_manager::create_agent($data);
        $this->assertGreaterThan(0, $id);

        $saved = agent_manager::get_agent($id);
        $this->assertNotNull($saved);
        $this->assertEquals('Daily summary report', $saved->prompt);
        $this->assertEquals('0 8 * * *', $saved->schedule_cron);
        $this->assertGreaterThan(0, $saved->timecreated);
        $this->assertGreaterThan(0, $saved->timemodified);
    }

    /**
     * Test create_agent sets default nextrun to 0.
     */
    public function test_create_agent_default_nextrun(): void {
        $data = (object) [
            'userid'        => 1,
            'prompt'        => 'Test',
            'schedule_cron' => '* * * * *',
            'context_type'  => 'site',
        ];
        $id = agent_manager::create_agent($data);
        $agent = agent_manager::get_agent($id);
        $this->assertEquals(0, $agent->nextrun);
    }

    // ========================
    // CRUD — update_agent
    // ========================

    /**
     * Test update_agent changes fields and updates timemodified.
     */
    public function test_update_agent(): void {
        $created = $this->create_agent_record();
        $original = agent_manager::get_agent($created->id);

        // Wait briefly to ensure timemodified changes.
        $this->waitForSecond();

        agent_manager::update_agent($created->id, (object) [
            'prompt'  => 'Updated prompt',
            'enabled' => 0,
        ]);

        $updated = agent_manager::get_agent($created->id);
        $this->assertEquals('Updated prompt', $updated->prompt);
        $this->assertEquals(0, (int) $updated->enabled);
        // create_agent sets timemodified = time(). update_agent sets a new timemodified,
        // so it should be >= original (after waitForSecond it will be strictly greater).
        $this->assertGreaterThanOrEqual($original->timemodified, $updated->timemodified);
    }

    // ========================
    // CRUD — delete_agent
    // ========================

    /**
     * Test delete_agent removes the record.
     */
    public function test_delete_agent(): void {
        $created = $this->create_agent_record();
        agent_manager::delete_agent($created->id);
        $this->assertNull(agent_manager::get_agent($created->id));
    }

    /**
     * Test delete_agent also removes associated cache records.
     */
    public function test_delete_agent_cascades_cache(): void {
        global $DB;
        $created = $this->create_agent_record();

        // Insert a cache row for this agent.
        $DB->insert_record('local_jedomi_agent_cache', (object) [
            'agentid'       => $created->id,
            'context_hash'  => sha1('test'),
            'result_json'   => '{"test": true}',
            'timecreated'   => time(),
        ]);

        agent_manager::delete_agent($created->id);
        $cached = $DB->get_records('local_jedomi_agent_cache', ['agentid' => $created->id]);
        $this->assertEmpty($cached);
    }

    // ========================
    // CRUD — get_agents
    // ========================

    /**
     * Test get_agents returns all agents with no filters.
     */
    public function test_get_agents_no_filters(): void {
        $this->create_agent_record(['prompt' => 'Agent 1']);
        $this->create_agent_record(['prompt' => 'Agent 2']);
        $this->create_agent_record(['prompt' => 'Agent 3']);

        $result = agent_manager::get_agents();
        $this->assertCount(3, $result['items']);
        $this->assertEquals(3, $result['total']);
    }

    /**
     * Test get_agents with status filter.
     */
    public function test_get_agents_filter_by_status(): void {
        $this->create_agent_record(['prompt' => 'Enabled 1', 'enabled' => 1]);
        $this->create_agent_record(['prompt' => 'Enabled 2', 'enabled' => 1]);
        $this->create_agent_record(['prompt' => 'Disabled', 'enabled' => 0]);

        $enabled = agent_manager::get_agents(['status' => 'enabled']);
        $this->assertCount(2, $enabled['items']);
        $this->assertEquals(2, $enabled['total']);

        $disabled = agent_manager::get_agents(['status' => 'disabled']);
        $this->assertCount(1, $disabled['items']);
        $this->assertEquals(1, $disabled['total']);
    }

    /**
     * Test get_agents with context_type filter.
     */
    public function test_get_agents_filter_by_context_type(): void {
        $this->create_agent_record(['prompt' => 'Site agent', 'context_type' => 'site']);
        $this->create_agent_record(['prompt' => 'Course agent', 'context_type' => 'course']);
        $this->create_agent_record(['prompt' => 'User agent', 'context_type' => 'user']);

        $courses = agent_manager::get_agents(['context_type' => 'course']);
        $this->assertCount(1, $courses['items']);
        $this->assertEquals('Course agent', $courses['items'][0]->prompt);
    }

    /**
     * Test get_agents with search filter on prompt field.
     */
    public function test_get_agents_filter_by_search(): void {
        $this->create_agent_record(['prompt' => 'Daily report for managers']);
        $this->create_agent_record(['prompt' => 'Weekly summary for students']);
        $this->create_agent_record(['prompt' => 'Monthly analytics']);

        $result = agent_manager::get_agents(['search' => 'report']);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('Daily report for managers', $result['items'][0]->prompt);
    }

    /**
     * Test get_agents with role_filter (match role shortname in JSON).
     */
    public function test_get_agents_filter_by_role(): void {
        $this->create_agent_record([
            'prompt'      => 'Manager report',
            'role_filter' => json_encode(['manager']),
        ]);
        $this->create_agent_record([
            'prompt'      => 'Teacher report',
            'role_filter' => json_encode(['teacher']),
        ]);
        $this->create_agent_record([
            'prompt'      => 'All roles',
            'role_filter' => json_encode(['manager', 'teacher']),
        ]);

        $result = agent_manager::get_agents(['role_filter' => 'teacher']);
        $this->assertCount(2, $result['items']); // "Teacher report" + "All roles"
    }

    /**
     * Test get_agents with pagination.
     */
    public function test_get_agents_pagination(): void {
        for ($i = 1; $i <= 5; $i++) {
            $this->create_agent_record(['prompt' => "Agent {$i}"]);
        }

        $page1 = agent_manager::get_agents(['page' => 1, 'perpage' => 2]);
        $this->assertCount(2, $page1['items']);
        $this->assertEquals(5, $page1['total']);

        $page3 = agent_manager::get_agents(['page' => 3, 'perpage' => 2]);
        $this->assertCount(1, $page3['items']);
        $this->assertEquals(5, $page3['total']);
    }

    // ========================
    // Cache — Semantic Plan
    // ========================

    /**
     * Test set_cached_plan stores and get_cached_plan retrieves plan + answer.
     */
    public function test_set_get_cached_plan(): void {
        $agent = $this->create_agent_record();
        $hash = sha1('test_context');
        $plan = [
            ['tool' => 'execute_sql', 'arguments' => '{"sql": "SELECT 1"}'],
            ['tool' => 'search_tables', 'arguments' => '{"query": "users"}'],
        ];
        $answer = 'Here are the results.';

        agent_manager::set_cached_plan($agent->id, $hash, $plan, $answer);
        $retrieved = agent_manager::get_cached_plan($agent->id, $hash);

        $this->assertNotNull($retrieved);
        $this->assertEquals($plan, $retrieved['plan']);
        $this->assertEquals($answer, $retrieved['answer']);
    }

    /**
     * Test get_cached_plan returns null when no plan_json exists.
     */
    public function test_get_cached_plan_no_plan(): void {
        $agent = $this->create_agent_record();
        $hash = sha1('no_plan');

        // Store a plain text result (no plan_json).
        agent_manager::set_cached_result($agent->id, $hash, '"some result"');

        $retrieved = agent_manager::get_cached_plan($agent->id, $hash);
        $this->assertNull($retrieved);
    }

    /**
     * Test get_cached_plan returns null for non-existent cache.
     */
    public function test_get_cached_plan_not_found(): void {
        $result = agent_manager::get_cached_plan(999, sha1('nope'));
        $this->assertNull($result);
    }

    /**
     * Test set_cached_plan with same agent+hash updates existing row.
     */
    public function test_set_cached_plan_updates_existing(): void {
        $agent = $this->create_agent_record();
        $hash = sha1('context');

        agent_manager::set_cached_plan($agent->id, $hash, [
            ['tool' => 'execute_sql', 'arguments' => '{}'],
        ], 'First answer');

        agent_manager::set_cached_plan($agent->id, $hash, [
            ['tool' => 'search_tables', 'arguments' => '{}'],
        ], 'Second answer');

        $retrieved = agent_manager::get_cached_plan($agent->id, $hash);
        $this->assertNotNull($retrieved);
        $this->assertEquals('search_tables', $retrieved['plan'][0]['tool']);
        $this->assertEquals('Second answer', $retrieved['answer']);
    }

    // ========================
    // is_tool_readonly
    // ========================

    /**
     * Test is_tool_readonly returns true for known read-only tools.
     */
    public function test_is_tool_readonly_known_readonly(): void {
        $readonlyTools = [
            'execute_sql',
            'search_tables',
            'describe_table',
            'get_site_config',
            'ws_search',
            'ws_describe',
            'admin_search',
            'admin_describe',
        ];
        foreach ($readonlyTools as $tool) {
            $this->assertTrue(
                agent_manager::is_tool_readonly($tool),
                "Expected {$tool} to be read-only"
            );
        }
    }

    /**
     * Test is_tool_readonly returns false for ws_execute (conservative).
     */
    public function test_is_tool_readonly_ws_execute(): void {
        $this->assertFalse(agent_manager::is_tool_readonly('ws_execute'));
        $this->assertFalse(agent_manager::is_tool_readonly('call_webservice'));
    }

    /**
     * Test is_tool_readonly returns false for unknown tools (safe fallback).
     */
    public function test_is_tool_readonly_unknown_tool(): void {
        $this->assertFalse(agent_manager::is_tool_readonly('create_user'));
        $this->assertFalse(agent_manager::is_tool_readonly('delete_course'));
        $this->assertFalse(agent_manager::is_tool_readonly('enrol_user'));
    }

    // ========================
    // CRUD — get_due_agents
    // ========================

    /**
     * Test get_due_agents returns enabled agents past nextrun.
     */
    public function test_get_due_agents(): void {
        $now = time();
        $this->create_agent_record([
            'prompt'  => 'Due agent',
            'enabled' => 1,
            'nextrun' => $now - 120,
        ]);
        $this->create_agent_record([
            'prompt'  => 'Not due yet',
            'enabled' => 1,
            'nextrun' => $now + 3600,
        ]);
        $this->create_agent_record([
            'prompt'  => 'Disabled',
            'enabled' => 0,
            'nextrun' => $now - 120,
        ]);

        $due = agent_manager::get_due_agents();
        $this->assertCount(1, $due);
        $this->assertEquals('Due agent', $due[0]->prompt);
    }

    /**
     * Test get_due_agents results are ordered by nextrun ASC.
     */
    public function test_get_due_agents_ordering(): void {
        $now = time();
        $this->create_agent_record([
            'prompt'  => 'Later',
            'enabled' => 1,
            'nextrun' => $now - 60,
        ]);
        $this->create_agent_record([
            'prompt'  => 'Earlier',
            'enabled' => 1,
            'nextrun' => $now - 3600,
        ]);

        $due = agent_manager::get_due_agents();
        $this->assertCount(2, $due);
        $this->assertEquals('Earlier', $due[0]->prompt);
        $this->assertEquals('Later', $due[1]->prompt);
    }

    // ========================
    // CRUD — mark_run
    // ========================

    /**
     * Test mark_run with success: updates lastrun, nextrun, resets failcount.
     */
    public function test_mark_run_success(): void {
        $now = time();
        $created = $this->create_agent_record([
            'nextrun'   => $now - 60,
            'failcount' => 3,
        ]);

        agent_manager::mark_run($created->id, true);
        $agent = agent_manager::get_agent($created->id);

        $this->assertNotNull($agent->lastrun);
        $this->assertGreaterThanOrEqual($now, (int) $agent->lastrun);
        // nextrun should be > now (since cron expression fires every 5 min)
        $this->assertGreaterThan($now, (int) $agent->nextrun);
        $this->assertEquals(0, (int) $agent->failcount);
    }

    /**
     * Test mark_run with failure: increments failcount, does NOT update lastrun.
     */
    public function test_mark_run_failure(): void {
        $now = time();
        $created = $this->create_agent_record([
            'lastrun'   => $now - 3600,
            'nextrun'   => $now - 60,
            'failcount' => 0,
        ]);
        $original_lastrun = $created->lastrun;

        agent_manager::mark_run($created->id, false);
        $agent = agent_manager::get_agent($created->id);

        $this->assertEquals($original_lastrun, (int) $agent->lastrun);
        $this->assertEquals(1, (int) $agent->failcount);
    }

    // ========================
    // CRUD — calculate_next_run
    // ========================

    /**
     * Test calculate_next_run with every-5-minutes expression.
     */
    public function test_calculate_next_run_every_five_minutes(): void {
        $from = 1000000000;
        // cron: every 5 minutes — next run should be the next minute divisible by 5.
        $next = agent_manager::calculate_next_run('*/5 * * * *', $from);
        $this->assertEquals(1000000200, $next);
    }

    /**
     * Test calculate_next_run with hourly expression.
     */
    public function test_calculate_next_run_hourly(): void {
        $from = 1000000000;
        $next = agent_manager::calculate_next_run('0 * * * *', $from);
        // 1000000000 mod 3600 = 2800 — next hour boundary is 1000000000 + 3600 - 2800 = 1000000800.
        $this->assertEquals(1000000800, $next);
    }

    /**
     * Test calculate_next_run with daily expression (at midnight).
     */
    public function test_calculate_next_run_daily(): void {
        $from = 1000000000;
        $next = agent_manager::calculate_next_run('0 0 * * *', $from);
        // Next midnight from 2001-09-09 01:46:40 = 1000080000
        $this->assertEquals(1000080000, $next);
    }

    /**
     * Test calculate_next_run returns fallback for invalid cron expression.
     */
    public function test_calculate_next_run_fallback(): void {
        $from = 1000000000;
        $next = agent_manager::calculate_next_run('invalid cron', $from);
        // Fallback: $from + 300
        $this->assertEquals($from + 300, $next);
    }

    // ========================
    // Cache — get_cached_result / set_cached_result
    // ========================

    /**
     * Test set_cached_result stores and get_cached_result retrieves.
     */
    public function test_get_set_cached_result(): void {
        $agent = $this->create_agent_record();
        $hash = sha1('test_context');
        $result = '{"data": "value"}';

        agent_manager::set_cached_result($agent->id, $hash, $result);
        $retrieved = agent_manager::get_cached_result($agent->id, $hash);
        $this->assertEquals($result, $retrieved);
    }

    /**
     * Test get_cached_result returns null for non-existent cache.
     */
    public function test_get_cached_result_not_found(): void {
        $result = agent_manager::get_cached_result(999, sha1('nope'));
        $this->assertNull($result);
    }

    /**
     * Test set_cached_result with same agent+hash updates existing row.
     */
    public function test_set_cached_result_updates_existing(): void {
        $agent = $this->create_agent_record();
        $hash = sha1('context');

        agent_manager::set_cached_result($agent->id, $hash, '{"first": true}');
        agent_manager::set_cached_result($agent->id, $hash, '{"second": true}');

        $retrieved = agent_manager::get_cached_result($agent->id, $hash);
        $this->assertEquals('{"second": true}', $retrieved);
    }

    // ========================
    // Cache — clear_agent_cache
    // ========================

    /**
     * Test clear_agent_cache removes all cache entries for an agent.
     */
    public function test_clear_agent_cache(): void {
        global $DB;
        $agent = $this->create_agent_record();
        $hash1 = sha1('ctx1');
        $hash2 = sha1('ctx2');

        agent_manager::set_cached_result($agent->id, $hash1, '"result1"');
        agent_manager::set_cached_result($agent->id, $hash2, '"result2"');

        agent_manager::clear_agent_cache($agent->id);

        $remaining = $DB->count_records('local_jedomi_agent_cache', ['agentid' => $agent->id]);
        $this->assertEquals(0, $remaining);
    }

    /**
     * Test clear_agent_cache does not affect other agents.
     */
    public function test_clear_agent_cache_only_own_agent(): void {
        global $DB;
        $agent1 = $this->create_agent_record();
        $agent2 = $this->create_agent_record();

        agent_manager::set_cached_result($agent1->id, sha1('a'), '"a"');
        agent_manager::set_cached_result($agent2->id, sha1('b'), '"b"');

        agent_manager::clear_agent_cache($agent1->id);

        $remaining = $DB->count_records('local_jedomi_agent_cache');
        $this->assertEquals(1, $remaining);
    }

    // ========================
    // Stats — get_stats
    // ========================

    /**
     * Test get_stats returns correct structure.
     */
    public function test_get_stats_structure(): void {
        $this->create_agent_record(['prompt' => 'Agent 1']);

        $stats = agent_manager::get_stats([]);
        $this->assertObjectHasAttribute('charts', $stats);
        $this->assertObjectHasAttribute('rows', $stats);
        $this->assertObjectHasAttribute('total', $stats);
        $this->assertObjectHasAttribute('page', $stats);
        $this->assertObjectHasAttribute('by_role', $stats->charts);
        $this->assertObjectHasAttribute('by_status', $stats->charts);
        $this->assertObjectHasAttribute('executions_30d', $stats->charts);
    }

    /**
     * Test get_stats counts enabled vs disabled.
     */
    public function test_get_stats_by_status(): void {
        $this->create_agent_record(['prompt' => 'E1', 'enabled' => 1]);
        $this->create_agent_record(['prompt' => 'E2', 'enabled' => 1]);
        $this->create_agent_record(['prompt' => 'D1', 'enabled' => 0]);

        $stats = agent_manager::get_stats([]);
        $this->assertEquals(2, $stats->charts->by_status->enabled);
        $this->assertEquals(1, $stats->charts->by_status->disabled);
    }

    /**
     * Test get_stats aggregates by role_filter.
     */
    public function test_get_stats_by_role(): void {
        $this->create_agent_record([
            'prompt'      => 'Mgr',
            'role_filter' => json_encode(['manager']),
        ]);
        $this->create_agent_record([
            'prompt'      => 'Tch',
            'role_filter' => json_encode(['teacher']),
        ]);
        $this->create_agent_record([
            'prompt'      => 'Both',
            'role_filter' => json_encode(['manager', 'teacher']),
        ]);

        $stats = agent_manager::get_stats([]);
        $this->assertEquals(2, $stats->charts->by_role['manager']);
        $this->assertEquals(2, $stats->charts->by_role['teacher']);
    }

    // ========================
    // Purge — purge_by_context
    // ========================

    /**
     * Test purge_by_context removes agents matching type+id.
     */
    public function test_purge_by_context(): void {
        $this->create_agent_record(['context_type' => 'course', 'context_id' => 5]);
        $this->create_agent_record(['context_type' => 'course', 'context_id' => 5]);
        $this->create_agent_record(['context_type' => 'course', 'context_id' => 10]);
        $this->create_agent_record(['context_type' => 'site', 'context_id' => null]);

        $deleted = agent_manager::purge_by_context('course', 5);
        $this->assertEquals(2, $deleted);
    }

    /**
     * Test purge_by_context does not affect other contexts.
     */
    public function test_purge_by_context_only_target(): void {
        $this->create_agent_record(['context_type' => 'site', 'context_id' => null]);

        $deleted = agent_manager::purge_by_context('course', 999);
        $this->assertEquals(0, $deleted);

        $all = agent_manager::get_agents();
        $this->assertEquals(1, $all['total']);
    }

    // ========================
    // Purge — purge_by_role
    // ========================

    /**
     * Test purge_by_role removes agents with matching role in role_filter JSON.
     */
    public function test_purge_by_role(): void {
        $this->create_agent_record([
            'role_filter' => json_encode(['manager']),
        ]);
        $this->create_agent_record([
            'role_filter' => json_encode(['manager', 'teacher']),
        ]);
        $this->create_agent_record([
            'role_filter' => json_encode(['teacher']),
        ]);

        $deleted = agent_manager::purge_by_role('manager');
        $this->assertEquals(2, $deleted);
    }

    // ========================
    // Purge — purge_all
    // ========================

    /**
     * Test purge_all removes all agents and cache entries.
     */
    public function test_purge_all(): void {
        global $DB;
        $a1 = $this->create_agent_record();
        $a2 = $this->create_agent_record();
        $DB->insert_record('local_jedomi_agent_cache', (object) [
            'agentid'      => $a1->id,
            'context_hash' => 'hash1',
            'result_json'  => '{}',
            'timecreated'  => time(),
        ]);

        $deleted = agent_manager::purge_all();
        $this->assertEquals(2, $deleted);

        $remaining = $DB->count_records('local_jedomi_agents');
        $this->assertEquals(0, $remaining);

        $remaining_cache = $DB->count_records('local_jedomi_agent_cache');
        $this->assertEquals(0, $remaining_cache);
    }

    /**
     * Test purge_all returns 0 when there are no agents.
     */
    public function test_purge_all_empty(): void {
        $deleted = agent_manager::purge_all();
        $this->assertEquals(0, $deleted);
    }
}
