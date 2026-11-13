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

namespace local_jedomi\reportbuilder\local\systemreports;

use core_reportbuilder\external\systemreports\retrieve;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\user_filter_manager;
use core_reportbuilder\system_report_factory;
use local_jedomi\agent\agent_manager;
use local_jedomi\agent\query_profiler;
use local_jedomi\reportbuilder\local\helpers\filtered_ids;

/**
 * Tests for the recent and the slowest queries system reports of the Query profiler tab.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\reportbuilder\local\systemreports\query_log
 * @covers     \local_jedomi\reportbuilder\local\systemreports\query_log_aggregate
 * @covers     \local_jedomi\reportbuilder\local\entities\query_log
 * @covers     \local_jedomi\reportbuilder\local\helpers\filtered_ids
 */
final class query_log_test extends \advanced_testcase {
    /**
     * Record a query at a given moment.
     *
     * @param int $agentid Agent of the query; 0 for a chat channel.
     * @param string $query Query.
     * @param float $durationms Duration.
     * @param int $time Moment of the query.
     * @param string $channel Channel.
     * @param int $userid User the query ran for.
     */
    private function record(
        int $agentid,
        string $query,
        float $durationms,
        int $time,
        string $channel = 'agent',
        int $userid = 0
    ): void {
        global $DB;
        query_profiler::log_query($agentid, $query, $durationms, 3, $channel, $userid);
        $lastid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {local_jedomi_query_log}');
        $DB->set_field('local_jedomi_query_log', 'timecreated', $time, ['id' => $lastid]);
    }

    /**
     * A report for the current user, with the given filter values saved for them.
     *
     * @param string $source System report class.
     * @param array $filters Filter values.
     * @return \core_reportbuilder\system_report
     */
    private function report(string $source, array $filters = []): \core_reportbuilder\system_report {
        $report = system_report_factory::create($source, \context_system::instance());
        user_filter_manager::set($report->get_report_persistent()->get('id'), $filters);
        return $report;
    }

    /**
     * Rows of a report for the current user, as HTML cells.
     *
     * @param string $source System report class.
     * @param array $filters Filter values.
     * @return array[]
     */
    private function rows(string $source, array $filters = []): array {
        $this->report($source, $filters);
        $result = retrieve::execute($source, ['contextid' => \context_system::instance()->id], '', '', 0, [], 0, 100);
        return array_map(fn(array $row): array => array_map('strval', $row['columns']), $result['data']['rows']);
    }

    /**
     * Cells without tags.
     *
     * @param array $rows Rows.
     * @param int $column Column index.
     * @return string[]
     */
    private function texts(array $rows, int $column): array {
        return array_map(fn(array $row): string => trim(html_entity_decode(strip_tags($row[$column]))), $rows);
    }

    /**
     * MDL-INT-036: the recent queries show their origin with a link to the agent, the user, the query, the duration
     * (red at or above the slow threshold), the rows and the moment, newest first.
     */
    public function test_recent_queries_columns(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Clara', 'lastname' => 'Creator']);
        $chatuser = $this->getDataGenerator()->create_user(['firstname' => 'Wendy', 'lastname' => 'Web']);
        $agentid = agent_manager::create_agent((object) ['userid' => (int) $creator->id, 'prompt' => 'Daily users',
            'schedule_cron' => '0 8 * * *']);
        $now = time();
        $this->record($agentid, 'SELECT <b>SLOW</b>', 1000, $now - 100);
        $this->record(0, 'SELECT WEB', 999.99, $now, 'web', (int) $chatuser->id);
        $this->record($agentid + 999, 'SELECT ORPHAN', 5, $now - 500);

        $rows = $this->rows(query_log::class);
        $this->assertSame('', $this->texts($rows, 1)[2], 'A query of an agent that no longer exists has no user');
        array_pop($rows);

        $this->assertSame([get_string('query_profiler_origin_web', 'local_jedomi'),
            get_string('agent_number', 'local_jedomi', $agentid)], $this->texts($rows, 0));
        $this->assertStringContainsString('edit_agent=' . $agentid, $rows[1][0]);
        $this->assertStringNotContainsString('<a ', $rows[0][0]);
        $this->assertSame([fullname($chatuser), fullname($creator)], $this->texts($rows, 1), 'The owner for an agent');
        $this->assertStringContainsString('SELECT &lt;b&gt;SLOW&lt;/b&gt;', $rows[1][2], 'The query is escaped');
        $this->assertStringNotContainsString('text-danger', $rows[0][3]);
        $this->assertStringContainsString('text-danger', $rows[1][3], 'At the threshold the duration is red');
        $this->assertSame(get_string('query_profiler_ms', 'local_jedomi', '1000.00'), $this->texts($rows, 3)[1]);
        $this->assertSame(['3', '3'], $this->texts($rows, 4));
        $this->assertNotSame('', $this->texts($rows, 5)[0]);
    }

    /**
     * MDL-INT-036: the channel, minimum duration, user and date filters restrict the recent queries, and the
     * indicators follow them.
     */
    public function test_recent_queries_filters_and_indicators(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Tom', 'lastname' => 'Telegram']);
        $now = time();
        $this->record(3, 'SELECT AGENT', 4000, $now - 10);
        $this->record(0, 'SELECT TELEGRAM', 20, $now - 20, 'telegram', (int) $user->id);
        $this->record(0, 'SELECT OLD', 30, $now - 10 * DAYSECS, 'web', (int) $user->id);

        $channel = ['query_log:channel_operator' => select::EQUAL_TO, 'query_log:channel_value' => 'telegram'];
        $slow = ['query_log:duration_operator' => number::EQUAL_OR_GREATER_THAN, 'query_log:duration_value1' => 1000];
        $byuser = ['user:fullname_operator' => text::CONTAINS, 'user:fullname_value' => 'Tom'];
        $recent = ['query_log:timecreated_operator' => date::DATE_RANGE, 'query_log:timecreated_from' => $now - DAYSECS,
            'query_log:timecreated_to' => $now + DAYSECS];

        $this->assertSame(['SELECT TELEGRAM'], $this->texts($this->rows(query_log::class, $channel), 2));
        $this->assertSame(['SELECT AGENT'], $this->texts($this->rows(query_log::class, $slow), 2));
        $this->assertSame(['SELECT TELEGRAM', 'SELECT OLD'], $this->texts($this->rows(query_log::class, $byuser), 2));
        $this->assertSame(['SELECT AGENT', 'SELECT TELEGRAM'], $this->texts($this->rows(query_log::class, $recent), 2));

        $all = query_profiler::get_aggregate(1000, ['idsql' => filtered_ids::sql($this->report(query_log::class))]);
        $telegram = query_profiler::get_aggregate(1000, ['idsql' => filtered_ids::sql($this->report(query_log::class, $channel))]);
        $this->assertSame(3, $all->total_queries);
        $this->assertSame(1, $all->slow_queries);
        $this->assertSame(4000.0, $all->max_duration_ms);
        $this->assertSame(1, $telegram->total_queries);
        $this->assertSame(0, $telegram->slow_queries);
        $this->assertSame(20.0, $telegram->avg_duration_ms);
    }

    /**
     * MDL-INT-036: the slowest queries are grouped by query with their average, maximum and number of runs, slowest
     * average first, and follow the same filters.
     */
    public function test_slowest_queries_are_grouped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = time();
        $this->record(1, 'SELECT A', 100, $now - 300);
        $this->record(1, 'SELECT A', 300, $now - 200);
        $this->record(1, 'SELECT SLOW', 2500, $now - 100);
        $this->record(0, 'SELECT WEB', 50, $now, 'web');

        $rows = $this->rows(query_log_aggregate::class);
        $web = $this->rows(
            query_log_aggregate::class,
            ['query_log:channel_operator' => select::EQUAL_TO, 'query_log:channel_value' => 'web']
        );

        $this->assertSame(['SELECT SLOW', 'SELECT A', 'SELECT WEB'], $this->texts($rows, 0));
        $this->assertSame(get_string('query_profiler_ms', 'local_jedomi', '200.00'), $this->texts($rows, 1)[1]);
        $this->assertSame(get_string('query_profiler_ms', 'local_jedomi', '300.00'), $this->texts($rows, 2)[1]);
        $this->assertSame('2', $this->texts($rows, 3)[1]);
        $this->assertStringContainsString('text-danger', $rows[1][1], 'The average is red');
        $this->assertSame(['SELECT WEB'], $this->texts($web, 0));
    }

    /**
     * MDL-INT-036: ajax/profiler_stats.php gives the indicators of the queries that match the filters of the recent
     * queries report, and only to users who configure the site.
     */
    public function test_profiler_stats_endpoint_follows_the_report_filters(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->getDataGenerator()->create_user(['username' => 'profilermanager']);
        $this->getDataGenerator()->role_assign('manager', \core_user::get_user_by_username('profilermanager')->id);
        $this->record(1, 'SELECT AGENT', 2000, time());
        $this->record(0, 'SELECT WEB', 10, time(), 'web');
        $this->setAdminUser();
        $this->report(query_log::class, ['query_log:channel_operator' => select::EQUAL_TO, 'query_log:channel_value' => 'web']);

        $runner = $CFG->dirroot . '/local/jedomi/tests/fixtures/page_runner.php';
        $request = static fn(string $username): string => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($runner) . ' ' . escapeshellarg($username) . ' ajax/profiler_stats.php {} 2>&1');

        $answer = json_decode(trim($request('admin')), true);
        $this->assertTrue($answer['ok'] ?? false);
        $this->assertSame(1, $answer['indicators']['total']);
        $this->assertSame(0, $answer['indicators']['slow']);
        $this->assertSame(get_string('query_profiler_ms', 'local_jedomi', '10.00'), $answer['indicators']['max']);
        $this->assertStringNotContainsString('"ok":true', $request('profilermanager'));
    }

    /**
     * MDL-INT-075: only users who configure the site can view the profiler reports.
     */
    public function test_profiler_reports_require_the_site_configuration_permission(): void {
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->setUser($manager);

        foreach ([query_log::class, query_log_aggregate::class] as $source) {
            try {
                $this->report($source)->require_can_view();
                $this->fail("{$source} must be closed to managers");
            } catch (\core_reportbuilder\exception\report_access_exception $e) {
                $this->assertInstanceOf(\core_reportbuilder\exception\report_access_exception::class, $e);
            }
        }
    }
}
