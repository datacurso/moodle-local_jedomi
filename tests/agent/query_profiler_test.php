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

use local_jedomi\reportbuilder\local\entities\query_log;

/**
 * Tests for the query profile of the agents: the recorded queries and what the Profiler tab presents from them.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\query_profiler
 * @covers     \local_jedomi\reportbuilder\local\entities\query_log
 */
final class query_profiler_test extends \advanced_testcase {
    /**
     * Record a query of an agent at a given moment.
     *
     * @param int $agentid Agent.
     * @param string $query Query.
     * @param float $durationms Duration.
     * @param int $rows Rows returned.
     * @param int $time Moment of the query.
     */
    private function record(int $agentid, string $query, float $durationms, int $rows, int $time): void {
        global $DB;
        query_profiler::log_query($agentid, $query, $durationms, $rows);
        $lastid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_jedomi_query_log}');
        $DB->set_field('local_jedomi_query_log', 'timecreated', $time, ['id' => $lastid]);
    }

    /**
     * MDL-INT-036: the indicators are coherent with the record.
     */
    public function test_indicators_follow_the_record(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $this->record(1, 'SELECT A', 100, 3, $now - 300);
        $this->record(1, 'SELECT A', 300, 5, $now - 200);
        $this->record(1, 'SELECT SLOW', 2500, 1, $now - 100);
        $this->record(1000, 'SELECT ORPHAN', 50, 0, $now);

        $aggregate = query_profiler::get_aggregate();

        $this->assertSame(4, $aggregate->total_queries);
        $this->assertSame(1, $aggregate->slow_queries);
        $this->assertEqualsWithDelta(737.5, $aggregate->avg_duration_ms, 0.01);
        $this->assertEqualsWithDelta(2500, $aggregate->max_duration_ms, 0.01);
        $this->assertObjectNotHasProperty('slowest', $aggregate, 'The slowest queries come from their report');
        $this->assertSame(4, $DB->count_records('local_jedomi_query_log'));
    }

    /**
     * MDL-INT-037: every query is recorded with its origin and agent, the indicators follow the filters and there is
     * pagination is the report's. (The configurable threshold and the purge are covered by the two tests above.)
     *
     * @return void
     */
    public function test_profiler_records_every_query_and_has_retention(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int) get_admin()->id;

        // The web chat and Telegram queries are recorded with their channel and user.
        \local_jedomi\local\query_runner::run('SELECT id FROM {course}', [], $adminid, 5, 'web');
        \local_jedomi\local\query_runner::run('SELECT shortname FROM {course}', [], $adminid, 5, 'telegram');
        $channels = $DB->get_fieldset_select('local_jedomi_query_log', 'channel', '1 = 1');
        $this->assertEqualsCanonicalizing(['web', 'telegram'], $channels);
        $this->assertSame([$adminid], array_map('intval', array_unique(
            $DB->get_fieldset_select('local_jedomi_query_log', 'userid', '1 = 1')
        )));

        // A preview is recorded as a preview (of the agent being edited, if any), never as "Agent #0".
        (new tool\sql_tool(0, $adminid, 'preview'))->execute((object) ['query' => 'SELECT fullname FROM {course}']);
        (new tool\sql_tool(7, $adminid, 'preview'))->execute((object) ['query' => 'SELECT summary FROM {course}']);
        $origins = array_map(
            fn(\stdClass $entry): string => query_log::origin((int) $entry->agentid, (string) $entry->channel)[0],
            $DB->get_records('local_jedomi_query_log', ['channel' => 'preview'])
        );
        $this->assertEqualsCanonicalizing([
            get_string('query_profiler_origin_preview', 'local_jedomi'),
            get_string('query_profiler_origin_preview_agent', 'local_jedomi', 7),
        ], $origins);
        $this->assertNotContains(get_string('agent_number', 'local_jedomi', 0), $origins);
        $this->assertSame(
            get_string('query_profiler_origin_web', 'local_jedomi'),
            query_log::origin(0, 'web')[0]
        );

        // The indicators follow the filters of the list.
        $this->record(3, 'SELECT AGENT', 4000, 1, time());
        $all = query_profiler::get_aggregate(1000);
        $web = query_profiler::get_aggregate(1000, ['channel' => 'web']);
        $agent = query_profiler::get_aggregate(1000, ['channel' => 'agent']);
        $this->assertSame(5, $all->total_queries);
        $this->assertSame(1, $web->total_queries);
        $this->assertSame(1, $agent->total_queries);
        $this->assertSame(1, $agent->slow_queries);
        $this->assertSame(4000.0, $agent->max_duration_ms);
                $this->assertSame(1, query_profiler::get_aggregate(1000, ['min_duration_ms' => 3000])->total_queries);
    }
}
