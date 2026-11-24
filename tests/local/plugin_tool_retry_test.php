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

namespace local_jedomi\local;

use local_jedomi\fixtures\fake_plugin_tool;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/plugin_tools/fake_tool_plugin.php');

/**
 * Tests for running a plugin tool again while it answers that its data is still being prepared.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\plugin_tool_retry
 */
final class plugin_tool_retry_test extends \advanced_testcase {
    /** @var int[] Seconds asked to each wait, in order. */
    private array $waits = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        fake_plugin_tool::$runs = [];
        $this->waits = [];
        $clock = $this->mock_clock_with_frozen(1794972600);
        // The waits never sleep: they move the frozen clock instead.
        plugin_tool_retry::set_sleeper_for_testing(function (int $seconds) use ($clock): void {
            $this->waits[] = $seconds;
            $clock->bump($seconds);
        });
    }

    #[\Override]
    protected function tearDown(): void {
        plugin_tool_retry::set_sleeper_for_testing(null);
        fake_plugin_tool::$runs = [];
        parent::tearDown();
    }

    /**
     * Answer of a tool whose data is still being prepared, in the shape of local_datacurso_heatmap.
     *
     * @return string
     */
    private function pending(): string {
        return json_encode(['meta' => ['courseid' => 2, 'pending' => true, 'notices' => ['pending'],
            'message' => 'The data is being prepared. Ask again in a minute.'], 'areas' => []]);
    }

    /**
     * Answer of a tool whose data is ready.
     *
     * @return string
     */
    private function ready(): string {
        return json_encode(['meta' => ['courseid' => 2, 'pending' => false, 'notices' => []], 'areas' => [['id' => 1]]]);
    }

    /**
     * A read-only tool that answers pending runs again after a short wait, and its first ready answer is returned.
     */
    public function test_pending_read_only_tool_runs_again_until_its_data_is_ready(): void {
        $tool = new fake_plugin_tool('demo_report', answers: [$this->pending(), $this->ready()]);

        $result = plugin_tool_retry::run($tool, ['q' => 'map']);

        $this->assertSame($this->ready(), $result);
        $this->assertSame(['demo_report', 'demo_report'], fake_plugin_tool::$runs);
        $this->assertSame([plugin_tool_retry::WAIT_SECONDS], $this->waits);
    }

    /**
     * A tool that stays pending runs at most MAX_ATTEMPTS times and its last pending answer is returned.
     */
    public function test_tool_that_stays_pending_stops_after_the_last_attempt(): void {
        $tool = new fake_plugin_tool('demo_report', answers: [$this->pending()]);

        $result = plugin_tool_retry::run($tool, []);

        $this->assertSame($this->pending(), $result);
        $this->assertCount(plugin_tool_retry::MAX_ATTEMPTS, fake_plugin_tool::$runs);
        $this->assertSame(array_fill(0, plugin_tool_retry::MAX_ATTEMPTS - 1, plugin_tool_retry::WAIT_SECONDS), $this->waits);
        $this->assertLessThanOrEqual(plugin_tool_retry::MAX_TOTAL_SECONDS, array_sum($this->waits));
    }

    /**
     * The limits stay short: a few attempts and less than half a minute of waiting in all.
     */
    public function test_limits_are_bounded(): void {
        $this->assertGreaterThanOrEqual(2, plugin_tool_retry::MAX_ATTEMPTS);
        $this->assertLessThanOrEqual(3, plugin_tool_retry::MAX_ATTEMPTS);
        $this->assertGreaterThan(0, plugin_tool_retry::WAIT_SECONDS);
        $this->assertLessThanOrEqual(30, plugin_tool_retry::MAX_TOTAL_SECONDS);
        $this->assertGreaterThanOrEqual(plugin_tool_retry::MAX_TOTAL_SECONDS, plugin_tool_retry::MAX_REQUEST_WAIT_SECONDS);
        $this->assertLessThanOrEqual(30, plugin_tool_retry::MAX_REQUEST_WAIT_SECONDS);
        $this->assertGreaterThanOrEqual(
            (plugin_tool_retry::MAX_ATTEMPTS - 1) * plugin_tool_retry::WAIT_SECONDS,
            plugin_tool_retry::MAX_TOTAL_SECONDS
        );
    }

    /**
     * When the time already spent leaves no room for another wait inside MAX_TOTAL_SECONDS, the tool is not run again,
     * even with attempts left.
     */
    public function test_no_wait_goes_past_the_total_time(): void {
        $clock = $this->mock_clock_with_frozen(1794972600);
        // Each wait takes far longer than asked, as a slow server would.
        plugin_tool_retry::set_sleeper_for_testing(function (int $seconds) use ($clock): void {
            $this->waits[] = $seconds;
            $clock->bump(plugin_tool_retry::MAX_TOTAL_SECONDS);
        });
        $tool = new fake_plugin_tool('demo_report', answers: [$this->pending()]);

        $result = plugin_tool_retry::run($tool, []);

        $this->assertSame($this->pending(), $result);
        $this->assertCount(2, fake_plugin_tool::$runs);
        $this->assertCount(1, $this->waits);
    }

    /**
     * All the waits of one request share a budget: after a tool used most of it, the next pending tool waits only what
     * is left, and then not at all.
     */
    public function test_waits_of_one_request_share_a_budget(): void {
        $first = new fake_plugin_tool('demo_first', answers: [$this->pending()]);
        $second = new fake_plugin_tool('demo_second', answers: [$this->pending()]);
        $third = new fake_plugin_tool('demo_third', answers: [$this->pending()]);

        plugin_tool_retry::run($first, []);
        plugin_tool_retry::run($second, []);
        plugin_tool_retry::run($third, []);

        $this->assertLessThanOrEqual(plugin_tool_retry::MAX_REQUEST_WAIT_SECONDS, array_sum($this->waits));
        $this->assertSame(
            ['demo_first', 'demo_first', 'demo_first', 'demo_second', 'demo_second', 'demo_third'],
            fake_plugin_tool::$runs
        );
        $this->assertSame(array_fill(0, 3, plugin_tool_retry::WAIT_SECONDS), $this->waits);
    }

    /**
     * A ready answer, an answer of another shape or a tool without meta never waits.
     */
    public function test_answer_that_is_not_pending_runs_once(): void {
        $tool = new fake_plugin_tool('demo_report', answers: [$this->ready()]);

        $this->assertSame($this->ready(), plugin_tool_retry::run($tool, []));
        $this->assertSame(['demo_report'], fake_plugin_tool::$runs);
        $this->assertSame([], $this->waits);
    }

    /**
     * A tool that changes data is never run twice, even when it answers pending.
     */
    public function test_tool_that_changes_data_is_never_run_again(): void {
        $tool = new fake_plugin_tool('demo_writer', readonly: false, answers: [$this->pending()]);

        $this->assertSame($this->pending(), plugin_tool_retry::run($tool, []));
        $this->assertSame(['demo_writer'], fake_plugin_tool::$runs);
        $this->assertSame([], $this->waits);
    }

    /**
     * An error is never retried: a failing tool, and an error answer that also says pending.
     */
    public function test_errors_are_never_retried(): void {
        $failing = new fake_plugin_tool('demo_report', failure: new \moodle_exception('nopermissions', 'error', '', 'x'));
        $failed = json_decode(plugin_tool_retry::run($failing, []), true);
        $this->assertSame('tool_failed', $failed['error']);

        $errorpending = json_encode(['error' => 'busy', 'meta' => ['pending' => true]]);
        $tool = new fake_plugin_tool('demo_other', answers: [$errorpending]);
        $this->assertSame($errorpending, plugin_tool_retry::run($tool, []));

        $this->assertSame(['demo_report', 'demo_other'], fake_plugin_tool::$runs);
        $this->assertSame([], $this->waits);
    }

    /**
     * Answers that are pending in the machine-readable contract: a JSON object with meta.pending exactly true.
     *
     * @return array[]
     */
    public static function pending_answers_provider(): array {
        return [
            'heatmap pending' => ['{"meta":{"pending":true,"notices":["pending"]},"areas":[]}', true],
            'pending false' => ['{"meta":{"pending":false}}', false],
            'pending as text' => ['{"meta":{"pending":"true"}}', false],
            'pending as number' => ['{"meta":{"pending":1}}', false],
            'pending outside meta' => ['{"pending":true}', false],
            'meta is a list' => ['{"meta":[true]}', false],
            'meta is text' => ['{"meta":"pending"}', false],
            'error with pending' => ['{"error":"x","meta":{"pending":true}}', false],
            'top level list' => ['[{"meta":{"pending":true}}]', false],
            'not json' => ['The data is being prepared. Ask again in a minute.', false],
            'empty' => ['', false],
        ];
    }

    /**
     * Only meta.pending set to true marks an answer as pending; the text of the message never does.
     *
     * @dataProvider pending_answers_provider
     * @param string $answer Answer of the tool.
     * @param bool $expected Whether it is pending.
     */
    public function test_pending_contract(string $answer, bool $expected): void {
        $this->assertSame($expected, plugin_tool_retry::is_pending($answer));
    }
}
