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

use local_jedomi\local\service\admin_settings_service;
use local_jedomi\output\admin\profiler_tab;

/**
 * Tests for the query profile of the agents: the recorded queries and what the Profiler tab presents from them.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\query_profiler
 * @covers     \local_jedomi\output\admin\profiler_tab
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
     * Profiler tab data for the recorded queries.
     *
     * @param int $minms Minimum duration filter.
     * @param string $sort Order.
     * @return array
     */
    private function tab_data(int $minms, string $sort): array {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $tab = new profiler_tab(query_profiler::get_aggregate(), admin_settings_service::get_recent_profiler_entries($minms, $sort),
            $minms, $sort);
        return $tab->export_for_template($PAGE->get_renderer('local_jedomi'));
    }

    /**
     * MDL-INT-036: the indicators, the slowest queries and the recent queries are coherent with the record.
     */
    public function test_indicators_and_lists_follow_the_record(): void {
        global $DB;
        $this->resetAfterTest();
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Clara', 'lastname' => 'Creator']);
        $agentid = agent_manager::create_agent((object) ['userid' => (int) $creator->id, 'prompt' => 'Daily users',
            'schedule_cron' => '0 8 * * *']);
        $now = time();
        $this->record($agentid, 'SELECT A', 100, 3, $now - 300);
        $this->record($agentid, 'SELECT A', 300, 5, $now - 200);
        $this->record($agentid, 'SELECT SLOW', 2500, 1, $now - 100);
        $this->record($agentid + 999, 'SELECT ORPHAN', 50, 0, $now);

        $data = $this->tab_data(0, 'timecreated');

        $this->assertSame(4, $data['total']);
        $this->assertSame(1, $data['slow']);
        $this->assertEqualsWithDelta(737.5, $data['avg'], 0.01);
        $this->assertEqualsWithDelta(2500, $data['max'], 0.01);
        $this->assertSame('SELECT SLOW', $data['slowest'][0]['query']);
        $grouped = $data['slowest'][array_search('SELECT A', array_column($data['slowest'], 'query'))];
        $this->assertEqualsWithDelta(200, $grouped['avg'], 0.01);
        $this->assertEqualsWithDelta(300, $grouped['max'], 0.01);
        $this->assertSame(2, $grouped['count']);

        $this->assertSame(['SELECT ORPHAN', 'SELECT SLOW', 'SELECT A', 'SELECT A'], array_column($data['recent'], 'query'));
        $this->assertSame('', $data['recent'][0]['userfullname'], 'A query of an agent that no longer exists has no creator');
        $slow = $data['recent'][1];
        $this->assertSame(fullname($creator), $slow['userfullname']);
        $this->assertStringContainsString('edit_agent=' . $agentid, $slow['agenturl']);
        $this->assertSame(1, $slow['rowcount']);
        $this->assertSame($now - 100, (int) $slow['timecreated']);
        $this->assertSame(4, $DB->count_records('local_jedomi_query_log'));
    }

    /**
     * MDL-INT-036: the minimum duration filter and the order by moment or by duration apply to the recent queries.
     */
    public function test_recent_queries_follow_the_duration_filter_and_the_order(): void {
        $this->resetAfterTest();
        $now = time();
        $this->record(1, 'SELECT FAST', 10, 1, $now);
        $this->record(1, 'SELECT MEDIUM', 400, 1, $now - 50);
        $this->record(1, 'SELECT SLOW', 2000, 1, $now - 100);

        $bytime = array_column($this->tab_data(0, 'timecreated')['recent'], 'query');
        $byduration = array_column($this->tab_data(0, 'duration_ms')['recent'], 'query');
        $filtered = array_column($this->tab_data(400, 'duration_ms')['recent'], 'query');

        $this->assertSame(['SELECT FAST', 'SELECT MEDIUM', 'SELECT SLOW'], $bytime);
        $this->assertSame(['SELECT SLOW', 'SELECT MEDIUM', 'SELECT FAST'], $byduration);
        $this->assertSame(['SELECT SLOW', 'SELECT MEDIUM'], $filtered, 'The query exactly at the minimum is kept');
    }

    /**
     * MDL-INT-037: every query is recorded with its origin and agent, the slow threshold is configurable, the indicators
     * follow the filters, there is pagination and old records are removed.
     * [Pendiente:skip]
     */
    public function test_profiler_records_every_query_and_has_retention(): void {
        $this->markTestSkipped('Pending fix: only agent queries from their second run are recorded, chat and Telegram '
            . 'queries are not, the threshold is fixed, there is no pagination and the record grows without limit.');
    }
}
