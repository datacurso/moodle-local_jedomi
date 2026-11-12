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

namespace local_jedomi;

use local_jedomi\agent\agent_manager;

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

    // ... ========================
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

    // ... ========================
    // CRUD — create_agent
    // ========================

    /**
     * Test create_agent inserts a record and returns its id.
     * MDL-INT-021: creating an agent saves the prompt, name, schedule, context and roles.
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
     * SYS-E2E-009: a new agent waits for its next scheduled hour instead of being due at once.
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
        $this->assertGreaterThan(time(), (int) $agent->nextrun);
    }

    // ... ========================
    // CRUD — update_agent
    // ========================

    /**
     * Test update_agent changes fields and updates timemodified.
     * MDL-INT-021: editing updates the agent.
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
        // Create_agent sets timemodified = time(). update_agent sets a new timemodified,
        // so it should be >= original (after waitForSecond it will be strictly greater).
        $this->assertGreaterThanOrEqual($original->timemodified, $updated->timemodified);
    }

    // ... ========================
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
     * MDL-INT-021: deleting an agent leaves no saved results behind.
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

    // ... ========================
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
        $this->assertCount(2, $result['items']); // Matches Teacher report and All roles.
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

    // ... ========================
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

    // ... ========================
    // is_tool_readonly
    // ========================

    /**
     * MDL-UNIT-021: search, describe and query tools are classified as read-only.
     */
    public function test_is_tool_readonly_known_readonly(): void {
        $readonlytools = [
            'execute_sql',
            'search_tables',
            'describe_table',
            'get_site_config',
            'ws_search',
            'ws_describe',
            'admin_search',
            'admin_describe',
        ];
        foreach ($readonlytools as $tool) {
            $this->assertTrue(
                agent_manager::is_tool_readonly($tool),
                "Expected {$tool} to be read-only"
            );
        }
    }

    /**
     * MDL-UNIT-021: web service execution is not read-only by its name alone; the invoked function decides.
     */
    public function test_is_tool_readonly_ws_execute(): void {
        $this->assertFalse(agent_manager::is_tool_readonly('ws_execute'));
        $this->assertFalse(agent_manager::is_tool_readonly('call_webservice'));
    }

    /**
     * MDL-UNIT-021: an unknown tool is never treated as read-only.
     */
    public function test_is_tool_readonly_unknown_tool(): void {
        $this->assertFalse(agent_manager::is_tool_readonly('create_user'));
        $this->assertFalse(agent_manager::is_tool_readonly('delete_course'));
        $this->assertFalse(agent_manager::is_tool_readonly('enrol_user'));
    }

    // ... ========================
    // CRUD — get_due_agents
    // ========================

    /**
     * Test get_due_agents returns enabled agents past nextrun.
     * MDL-INT-029: only the enabled agents with an elapsed next run are selected.
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
     * MDL-INT-029: the due agents come in order of due time, the most overdue first.
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

    // ... ========================
    // CRUD — mark_run
    // ========================

    /**
     * Test mark_run with success: updates lastrun, nextrun, resets failcount.
     * MDL-INT-029: after a correct run the last run is recorded and the next one calculated.
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
        // Nextrun should be > now (since cron expression fires every 5 min).
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
        $originallastrun = $created->lastrun;

        agent_manager::mark_run($created->id, false);
        $agent = agent_manager::get_agent($created->id);

        $this->assertEquals($originallastrun, (int) $agent->lastrun);
        $this->assertEquals(1, (int) $agent->failcount);
    }

    // ... ========================
    // CRUD — calculate_next_run
    // ========================

    /**
     * MDL-UNIT-025: next run of the every-5-minutes schedule.
     */
    public function test_calculate_next_run_every_five_minutes(): void {
        $from = 1000000000;
        // Cron: every 5 minutes — next run should be the next minute divisible by 5.
        $next = agent_manager::calculate_next_run('*/5 * * * *', $from);
        $this->assertEquals(1000000200, $next);
    }

    /**
     * MDL-UNIT-025: next run of the hourly schedule.
     */
    public function test_calculate_next_run_hourly(): void {
        $from = 1000000000;
        $next = agent_manager::calculate_next_run('0 * * * *', $from);
        // 1000000000 mod 3600 = 2800 — next hour boundary is 1000000000 + 3600 - 2800 = 1000000800.
        $this->assertEquals(1000000800, $next);
    }

    /**
     * MDL-UNIT-025: next run of the daily schedule (at midnight).
     */
    public function test_calculate_next_run_daily(): void {
        $from = 1000000000;
        $next = agent_manager::calculate_next_run('0 0 * * *', $from);
        // Next midnight from 2001-09-09 01:46:40 = 1000080000.
        $this->assertEquals(1000080000, $next);
    }

    /**
     * MDL-UNIT-025: an unrecognised schedule has a defined, controlled behaviour (run again in 5 minutes).
     */
    public function test_calculate_next_run_fallback(): void {
        $from = 1000000000;
        $next = agent_manager::calculate_next_run('invalid cron', $from);
        // Fallback: $from + 300.
        $this->assertEquals($from + 300, $next);
    }

    // ... ========================
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

    // ... ========================
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

    // ... ========================
    // Stats — get_stats
    // ========================

    /**
     * Test get_stats returns correct structure.
     */
    public function test_get_stats_structure(): void {
        $this->create_agent_record(['prompt' => 'Agent 1']);

        $stats = agent_manager::get_stats([]);
        $this->assertObjectHasProperty('charts', $stats);
        $this->assertObjectHasProperty('rows', $stats);
        $this->assertObjectHasProperty('total', $stats);
        $this->assertObjectHasProperty('page', $stats);
        $this->assertObjectHasProperty('by_role', $stats->charts);
        $this->assertObjectHasProperty('by_status', $stats->charts);
        $this->assertObjectHasProperty('executions_30d', $stats->charts);
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

    // ... ========================
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

    // ... ========================
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

    // ... ========================
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

        $remainingcache = $DB->count_records('local_jedomi_agent_cache');
        $this->assertEquals(0, $remainingcache);
    }

    /**
     * Test purge_all returns 0 when there are no agents.
     */
    public function test_purge_all_empty(): void {
        $deleted = agent_manager::purge_all();
        $this->assertEquals(0, $deleted);
    }

    // ... ========================
    // Default agent name
    // ========================

    /**
     * Assert that a derived name respects the word and character limits.
     *
     * @param string $name Derived name.
     * @return void
     */
    private function assert_name_within_limits(string $name): void {
        $this->assertNotSame('', trim($name), 'An agent always has a readable name');
        $this->assertLessThanOrEqual(35, \core_text::strlen($name));
        $this->assertLessThanOrEqual(5, count(preg_split('/\s+/', trim($name))));
    }

    /**
     * MDL-UNIT-024: without a name, the first words of the first sentence of the instruction are used.
     */
    public function test_empty_name_takes_the_first_words_of_the_first_sentence(): void {
        $prompt = 'Count the inactive students. Then send a report to every teacher of the site.';

        $name = agent_manager::generate_name_from_prompt($prompt);

        $this->assert_name_within_limits($name);
        $this->assertStringStartsWith($name, 'Count the inactive students');
        $this->assertStringNotContainsString('Then', $name, 'Only the first sentence is used');
    }

    /**
     * Instructions of different shapes for the default name.
     *
     * @return array<string, array{0: string}>
     */
    public static function prompt_shape_provider(): array {
        return [
            'very short' => ['Hi'],
            'without punctuation' => ['list every student who has not logged in during the last thirty days in my courses'],
            'first sentence longer than the limit' => [
                'Summarise the participation of every learner enrolled in the advanced mathematics programme! Thanks.',
            ],
            'one very long word' => [str_repeat('x', 50) . ' report'],
            'markup in the instruction' => ['<b>Weekly</b> grades overview? Please.'],
        ];
    }

    /**
     * MDL-UNIT-024: the derived name never exceeds the word and character limits, whatever the instruction.
     *
     * @dataProvider prompt_shape_provider
     * @param string $prompt Agent instruction.
     */
    public function test_derived_name_never_exceeds_the_limits(string $prompt): void {
        $name = agent_manager::generate_name_from_prompt($prompt);

        $this->assert_name_within_limits($name);
        $this->assertStringNotContainsString('<', $name);
    }

    /**
     * MDL-UNIT-024: a short instruction is used whole; an empty one still gives a readable name.
     */
    public function test_short_and_empty_instructions_give_a_name(): void {
        $this->assertSame('Hi', agent_manager::generate_name_from_prompt('Hi'));
        $this->assertNotSame('', agent_manager::generate_name_from_prompt(''));
    }

    /**
     * MDL-UNIT-024: a name written by the user is kept; an empty one is derived from the instruction.
     */
    public function test_create_agent_keeps_the_user_name_or_derives_one(): void {
        $named = agent_manager::create_agent((object) [
            'userid' => 2,
            'name' => '  My weekly digest  ',
            'prompt' => 'Count the inactive students. Then report.',
            'schedule_cron' => '0 0 * * 0',
        ]);
        $unnamed = agent_manager::create_agent((object) [
            'userid' => 2,
            'name' => '',
            'prompt' => 'Count the inactive students. Then report.',
            'schedule_cron' => '0 0 * * 0',
        ]);

        $this->assertSame('My weekly digest', agent_manager::get_agent($named)->name);
        $derived = agent_manager::get_agent($unnamed)->name;
        $this->assert_name_within_limits($derived);
        $this->assertStringStartsWith($derived, 'Count the inactive students');
    }

    // ... ========================
    // Predefined schedules
    // ========================

    /**
     * Predefined schedules of the agent form, a starting instant and the expected next run (UTC).
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function predefined_schedule_provider(): array {
        return [
            'every 5 min, just before' => ['*/5 * * * *', '2026-03-02 10:04:59', '2026-03-02 10:05:00'],
            'every 5 min, exactly on it' => ['*/5 * * * *', '2026-03-02 10:05:00', '2026-03-02 10:10:00'],
            'every 15 min, just before' => ['*/15 * * * *', '2026-03-02 10:14:59', '2026-03-02 10:15:00'],
            'every 15 min, just after' => ['*/15 * * * *', '2026-03-02 10:15:01', '2026-03-02 10:30:00'],
            'every 30 min, exactly on it' => ['*/30 * * * *', '2026-03-02 10:30:00', '2026-03-02 11:00:00'],
            'hourly, just before the hour' => ['0 * * * *', '2026-03-02 10:59:59', '2026-03-02 11:00:00'],
            'hourly, exactly on the hour' => ['0 * * * *', '2026-03-02 11:00:00', '2026-03-02 12:00:00'],
            'every 6 hours, just before' => ['0 */6 * * *', '2026-03-02 05:59:59', '2026-03-02 06:00:00'],
            'every 6 hours, exactly on it' => ['0 */6 * * *', '2026-03-02 06:00:00', '2026-03-02 12:00:00'],
            'every 6 hours, across the day' => ['0 */6 * * *', '2026-03-02 23:30:00', '2026-03-03 00:00:00'],
            'daily, just before midnight' => ['0 0 * * *', '2026-03-02 23:59:59', '2026-03-03 00:00:00'],
            'daily, exactly at midnight' => ['0 0 * * *', '2026-03-03 00:00:00', '2026-03-04 00:00:00'],
            'weekly, just before Sunday' => ['0 0 * * 0', '2026-02-28 23:59:59', '2026-03-01 00:00:00'],
            'weekly, exactly on Sunday' => ['0 0 * * 0', '2026-03-01 00:00:00', '2026-03-08 00:00:00'],
            'weekly, mid week' => ['0 0 * * 0', '2026-03-04 12:00:00', '2026-03-08 00:00:00'],
        ];
    }

    /**
     * MDL-UNIT-025: each predefined schedule gives the right next run, always in the future, around each boundary.
     *
     * @dataProvider predefined_schedule_provider
     * @param string $cron Schedule.
     * @param string $from Starting instant (UTC).
     * @param string $expected Expected next run (UTC).
     */
    public function test_predefined_schedules_give_the_next_run(string $cron, string $from, string $expected): void {
        $utc = new \DateTimeZone('UTC');
        $fromts = (new \DateTimeImmutable($from, $utc))->getTimestamp();
        $expectedts = (new \DateTimeImmutable($expected, $utc))->getTimestamp();

        $next = agent_manager::calculate_next_run($cron, $fromts);

        $this->assertSame($expected, gmdate('Y-m-d H:i:s', $next));
        $this->assertSame($expectedts, $next);
        $this->assertGreaterThan($fromts, $next);
    }

    /**
     * MDL-UNIT-025: an unrecognised schedule always gives a controlled next run in the near future.
     */
    public function test_unrecognised_schedules_give_a_controlled_next_run(): void {
        $from = 1000000000;
        foreach (['', 'every day', '* * * *', '* * * * * *'] as $cron) {
            $next = agent_manager::calculate_next_run($cron, $from);
            $this->assertGreaterThan($from, $next, "'{$cron}' must give a future run");
            $this->assertSame($from + 300, $next, "'{$cron}' must fall back to 5 minutes");
        }
    }

    /**
     * MDL-UNIT-026: a custom schedule with a wrong number of parts or values out of range is rejected with an error message.
     * [Pendiente:skip]
     */
    public function test_invalid_custom_schedule_is_rejected(): void {
        $this->markTestSkipped('Pending fix: only the five parts are checked; values out of range are accepted and the '
            . 'agent runs every 5 minutes, a non numeric text is read as zero, and editing with an expression without five '
            . 'parts silently keeps the previous schedule.');
    }

    /**
     * Schedules in the local time of a creator and the instant they must run at, around midnight and daylight saving changes.
     *
     * @return array
     */
    public static function creator_timezone_schedule_provider(): array {
        return [
            'daily, behind UTC' => ['America/Bogota', '0 8 * * *', '2026-06-10 07:30:00', '2026-06-10 08:00:00'],
            'daily, ahead of UTC' => ['Asia/Tokyo', '0 8 * * *', '2026-06-10 08:00:00', '2026-06-11 08:00:00'],
            'weekly on Monday' => ['America/Bogota', '30 9 * * 1', '2026-06-10 12:00:00', '2026-06-15 09:30:00'],
            'midnight, just before' => ['America/Bogota', '0 0 * * *', '2026-06-10 23:59:00', '2026-06-11 00:00:00'],
            'midnight, just after' => ['America/Bogota', '0 0 * * *', '2026-06-11 00:00:30', '2026-06-12 00:00:00'],
            'weekly, just before midnight of Sunday' => ['Europe/Madrid', '0 0 * * 0', '2026-06-13 23:59:59',
                '2026-06-14 00:00:00'],
            'daily across the spring change' => ['Europe/Madrid', '0 8 * * *', '2026-03-28 09:00:00', '2026-03-29 08:00:00'],
            'daily across the autumn change' => ['Europe/Madrid', '0 8 * * *', '2026-10-24 09:00:00', '2026-10-25 08:00:00'],
            'weekly across the spring change' => ['Europe/Madrid', '0 9 * * 1', '2026-03-27 10:00:00', '2026-03-30 09:00:00'],
        ];
    }

    /**
     * MDL-UNIT-027: daily and weekly schedules run at their hour in the time zone of the creator, which differs from the
     * server one, around midnight and across daylight saving changes.
     *
     * @dataProvider creator_timezone_schedule_provider
     * @param string $timezone Time zone of the creator.
     * @param string $cron Schedule.
     * @param string $from Starting instant, in the time zone of the creator.
     * @param string $expected Expected next run, in the time zone of the creator.
     * @return void
     */
    public function test_schedules_follow_the_timezone_of_the_creator(
        string $timezone,
        string $cron,
        string $from,
        string $expected
    ): void {
        $this->setTimezone('UTC', 'UTC');
        $creator = $this->getDataGenerator()->create_user(['timezone' => $timezone]);
        $tz = new \DateTimeZone($timezone);
        $fromts = (new \DateTimeImmutable($from, $tz))->getTimestamp();

        $next = agent_manager::calculate_next_run($cron, $fromts, agent_manager::creator_timezone((int) $creator->id));

        $this->assertSame($expected, (new \DateTimeImmutable('@' . $next))->setTimezone($tz)->format('Y-m-d H:i:s'));
        $this->assertGreaterThan($fromts, $next);
    }

    /**
     * MDL-UNIT-027: a new agent waits for its hour in the time zone of its creator, not in the one of the server.
     *
     * @return void
     */
    public function test_new_agent_waits_for_the_hour_of_its_creator(): void {
        $this->setTimezone('Europe/London', 'Europe/London');
        $creator = $this->getDataGenerator()->create_user(['timezone' => 'America/Bogota']);

        $id = agent_manager::create_agent((object) ['userid' => $creator->id, 'prompt' => 'Daily summary',
            'schedule_cron' => '0 8 * * *', 'enabled' => 1]);

        $next = (int) agent_manager::get_agent($id)->nextrun;
        $local = (new \DateTimeImmutable('@' . $next))->setTimezone(new \DateTimeZone('America/Bogota'));
        $this->assertSame('08:00', $local->format('H:i'));
        $this->assertGreaterThan(time(), $next);
    }

    /**
     * MDL-UNIT-027: when the creator no longer exists the schedule follows the time zone of the server.
     *
     * @return void
     */
    public function test_schedule_of_a_missing_creator_follows_the_server_timezone(): void {
        global $DB;
        $this->setTimezone('Asia/Tokyo', 'Asia/Tokyo');
        $missing = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1000;

        $this->assertSame('Asia/Tokyo', agent_manager::creator_timezone($missing));
        $id = agent_manager::create_agent((object) ['userid' => $missing, 'prompt' => 'Daily summary',
            'schedule_cron' => '0 8 * * *', 'enabled' => 1]);
        $local = (new \DateTimeImmutable('@' . (int) agent_manager::get_agent($id)->nextrun))
            ->setTimezone(new \DateTimeZone('Asia/Tokyo'));
        $this->assertSame('08:00', $local->format('H:i'));
    }

    /**
     * MDL-UNIT-027: changing the schedule of an agent recalculates its next run, in the time zone of its creator.
     *
     * @return void
     */
    public function test_changing_the_schedule_recalculates_the_next_run(): void {
        $this->setTimezone('UTC', 'UTC');
        $creator = $this->getDataGenerator()->create_user(['timezone' => 'America/Bogota']);
        $id = agent_manager::create_agent((object) ['userid' => $creator->id, 'prompt' => 'Daily summary',
            'schedule_cron' => '0 8 * * *', 'enabled' => 1]);

        agent_manager::update_agent($id, (object) ['schedule_cron' => '45 17 * * *']);

        $next = (int) agent_manager::get_agent($id)->nextrun;
        $local = (new \DateTimeImmutable('@' . $next))->setTimezone(new \DateTimeZone('America/Bogota'));
        $this->assertSame('17:45', $local->format('H:i'));
        $this->assertGreaterThan(time(), $next);
        $this->assertLessThanOrEqual(time() + DAYSECS, $next);
    }

    /**
     * MDL-UNIT-028: the agent context id has one meaning per context type and an unknown or mismatched id is rejected when saving.
     * [Pendiente:skip]
     */
    public function test_context_id_is_validated_when_saving(): void {
        $this->markTestSkipped('Pending fix: the context id and type are not validated when saving, editing or creating '
            . 'agents from the chat or Telegram; the run reads a Moodle context id while the purge and the chat ask for '
            . 'the course, category or user id.');
    }

    /**
     * MDL-INT-021: enabling and disabling changes the state of the agent, and "Run now" leaves it queued for the next cycle.
     */
    public function test_toggle_and_run_now_queue_the_agent_for_the_next_cycle(): void {
        global $DB;
        $this->resetAfterTest();
        $id = agent_manager::create_agent((object) ['userid' => 2, 'prompt' => 'Weekly summary',
            'schedule_cron' => '0 8 * * 1', 'enabled' => 1]);

        agent_manager::update_agent($id, (object) ['enabled' => 0]);
        $this->assertSame(0, (int) agent_manager::get_agent($id)->enabled);
        agent_manager::update_agent($id, (object) ['enabled' => 1]);
        $this->assertSame(1, (int) agent_manager::get_agent($id)->enabled);
        $DB->set_field('local_jedomi_agents', 'nextrun', time() + DAYSECS, ['id' => $id]);
        $this->assertNotContains($id, array_map('intval', array_column(agent_manager::get_due_agents(), 'id')));

        $this->assertTrue(agent_manager::queue_for_run($id));

        $this->assertContains($id, array_map('intval', array_column(agent_manager::get_due_agents(), 'id')));
        $this->assertFalse(agent_manager::queue_for_run($id + 1000), 'An agent that does not exist cannot be queued');
    }

    /**
     * MDL-INT-023: agents can be created active, and enabling them or changing the schedule calculates the next run from the
     * schedule; "Run now" on a disabled agent runs it or says it will not run.
     * [Pendiente:skip]
     */
    public function test_activation_and_next_run_follow_the_schedule(): void {
        $this->markTestSkipped('Pending fix: the form has no Active field, so the agents created from the tab are disabled '
            . '(the ones created from the chat, Telegram or an agent are enabled), and "Run now" on a disabled agent '
            . 'reports a queue that never runs.');
    }

    /**
     * MDL-INT-024: editing an agent keeps and shows its name, custom schedule and roles.
     * [Pendiente:skip]
     */
    public function test_editing_keeps_name_schedule_and_roles(): void {
        $this->markTestSkipped('Pending fix: editing ignores the new name, silently drops an invalid or empty custom '
            . 'schedule, saves an instruction of only spaces and cannot remove every role; the role boxes have no effect.');
    }

    /**
     * Create the users of the permission tests: two managers, a teacher and the site administrator.
     *
     * @return int[] User ids keyed by managera, managerb, teacher and admin.
     */
    private function create_rank_users(): array {
        $generator = $this->getDataGenerator();
        $managera = $generator->create_user();
        $managerb = $generator->create_user();
        $generator->role_assign('manager', $managera->id);
        $generator->role_assign('manager', $managerb->id);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $generator->create_course()->id, 'editingteacher');
        return ['managera' => (int) $managera->id, 'managerb' => (int) $managerb->id, 'teacher' => (int) $teacher->id,
            'admin' => (int) get_admin()->id];
    }

    /**
     * MDL-INT-099 and MDL-INT-101: the owner of an agent always sees and manages it.
     */
    public function test_owner_sees_and_manages_their_own_agent(): void {
        $users = $this->create_rank_users();
        foreach ($users as $label => $userid) {
            $agent = $this->create_agent_record(['userid' => $userid]);
            $this->assertTrue(agent_manager::can_view_instruction($agent, $userid), "The {$label} sees their instruction");
            $this->assertTrue(agent_manager::can_manage_agent($agent, $userid), "The {$label} manages their agent");
        }
    }

    /**
     * MDL-INT-099 and MDL-INT-101: a site administrator sees and manages every agent.
     */
    public function test_admin_sees_and_manages_every_agent(): void {
        $users = $this->create_rank_users();
        foreach (['managera', 'teacher'] as $label) {
            $agent = $this->create_agent_record(['userid' => $users[$label]]);
            $this->assertTrue(agent_manager::can_view_instruction($agent, $users['admin']), "Instruction of the {$label}");
            $this->assertTrue(agent_manager::can_manage_agent($agent, $users['admin']), "Agent of the {$label}");
        }
    }

    /**
     * MDL-INT-099 and MDL-INT-101: a manager sees and manages the agents of a teacher (lower rank) only; the agents of
     * another manager (same rank) and of an administrator (higher rank) keep their instruction hidden and cannot be managed.
     */
    public function test_manager_sees_and_manages_only_agents_of_lower_ranks(): void {
        $users = $this->create_rank_users();
        $teacher = $this->create_agent_record(['userid' => $users['teacher']]);
        $manager = $this->create_agent_record(['userid' => $users['managerb']]);
        $admin = $this->create_agent_record(['userid' => $users['admin']]);

        $this->assertTrue(agent_manager::can_view_instruction($teacher, $users['managera']));
        $this->assertTrue(agent_manager::can_manage_agent($teacher, $users['managera']));
        foreach (['another manager' => $manager, 'an administrator' => $admin] as $label => $agent) {
            $this->assertFalse(agent_manager::can_view_instruction($agent, $users['managera']), "Instruction of {$label}");
            $this->assertFalse(agent_manager::can_manage_agent($agent, $users['managera']), "Agent of {$label}");
        }
        $this->assertFalse(agent_manager::can_view_instruction($manager, $users['teacher']), 'A teacher, a manager agent');
        $this->assertFalse(agent_manager::can_view_instruction($teacher, 0), 'Nobody without a user');
        $this->assertFalse(agent_manager::can_manage_agent($teacher, 0), 'Nobody without a user');
    }

    /**
     * MDL-INT-101: a manager cannot load, edit or delete the agent of another manager, nor purge it.
     */
    public function test_manager_cannot_manage_the_agent_of_another_manager(): void {
        $users = $this->create_rank_users();
        $agent = $this->create_agent_record(['userid' => $users['managerb'], 'context_type' => 'site']);

        try {
            agent_manager::require_manageable_agent((int) $agent->id, $users['managera']);
            $this->fail('The agent of another manager must not be manageable');
        } catch (\moodle_exception $e) {
            $this->assertSame('agent_error_nopermission', $e->errorcode);
        }
        $this->assertSame(0, agent_manager::purge_by_context('site', null, $users['managera']));
        $this->assertNotNull(agent_manager::get_agent((int) $agent->id));
    }

    /**
     * MDL-INT-099: an agent seen by a user keeps its instruction only when they may see it, and says whether they may
     * manage it.
     */
    public function test_agent_for_actor_hides_the_instruction_it_may_not_see(): void {
        $users = $this->create_rank_users();
        $teacher = $this->create_agent_record(['userid' => $users['teacher'], 'prompt' => 'Teacher instruction']);
        $admin = $this->create_agent_record(['userid' => $users['admin'], 'prompt' => 'Admin instruction']);

        $visible = agent_manager::agent_for_actor($teacher, $users['managera']);
        $hidden = agent_manager::agent_for_actor($admin, $users['managera']);

        $this->assertSame('Teacher instruction', $visible->prompt);
        $this->assertFalse($visible->prompthidden);
        $this->assertTrue($visible->canmanage);
        $this->assertNull($hidden->prompt);
        $this->assertTrue($hidden->prompthidden);
        $this->assertFalse($hidden->canmanage);
        $this->assertSame('Admin instruction', $admin->prompt, 'The given record is not changed');

        $derived = agent_manager::get_agent(agent_manager::create_agent((object) ['userid' => $users['admin'],
            'prompt' => 'Secret words of the admin', 'schedule_cron' => '0 8 * * *']));
        $named = agent_manager::get_agent(agent_manager::create_agent((object) ['userid' => $users['admin'],
            'name' => 'Weekly report', 'prompt' => 'Secret words of the admin', 'schedule_cron' => '0 8 * * *']));
        $hiddenname = agent_manager::agent_for_actor($derived, $users['managera'])->name;
        $this->assertSame('', $hiddenname, 'A name made of the first words of a hidden instruction is hidden too');
        $this->assertSame('Weekly report', agent_manager::agent_for_actor($named, $users['managera'])->name);
        $this->assertSame($derived->name, agent_manager::agent_for_actor($derived, $users['admin'])->name);
    }

    /**
     * MDL-INT-099: searching by instruction never matches the agents whose instruction the user may not see.
     */
    public function test_search_by_instruction_skips_hidden_instructions(): void {
        $users = $this->create_rank_users();
        $this->create_agent_record(['userid' => $users['admin'], 'prompt' => 'Shared marker of the admin']);
        $this->create_agent_record(['userid' => $users['managera'], 'prompt' => 'Shared marker of mine']);

        $asmanager = agent_manager::get_agents(['search' => 'Shared marker', 'actorid' => $users['managera']]);
        $asadmin = agent_manager::get_agents(['search' => 'Shared marker', 'actorid' => $users['admin']]);

        $this->assertSame(1, $asmanager['total']);
        $this->assertSame((string) $users['managera'], (string) $asmanager['items'][0]->userid);
        $this->assertSame(2, $asadmin['total']);
    }
}
