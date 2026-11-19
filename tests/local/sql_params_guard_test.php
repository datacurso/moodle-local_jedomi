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

namespace local_jedomi\local;

use local_jedomi\agent\tool\sql_tool;
use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\proxy\handler;
use local_jedomi\telegram\webhook_handler;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');

/**
 * JEDOMI-SEC-015: the course guard checks exactly the parameters the database runs with, on every channel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\sql_guard
 * @covers     \local_jedomi\local\sql_select_parser
 * @covers     \local_jedomi\local\query_runner
 * @covers     \local_jedomi\agent\tool\sql_tool
 */
final class sql_params_guard_test extends \advanced_testcase {
    /** @var string Grades of one course, with the course as a named parameter. */
    private const GRADES_SQL = 'SELECT gi.courseid, gi.itemname, gg.userid, gg.finalgrade FROM {grade_items} gi '
        . 'JOIN {grade_grades} gg ON gg.itemid = gi.id WHERE gi.courseid = :c';

    /** @var string Forum posts of one course, with the course as a positional parameter. */
    private const POSTS_SQL = 'SELECT d.course, d.name, p.subject, p.message FROM {forum_discussions} d '
        . 'JOIN {forum_posts} p ON p.discussion = d.id WHERE d.course = ?';

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * A teacher of course A; course B taught by someone else, with a graded student and a forum post; course C taught
     * by the same teacher but excluded from Jedomi. Every course has a graded student.
     *
     * @return array{a: \stdClass, b: \stdClass, c: \stdClass, teacher: int}
     */
    private function scenario(): array {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $a = $gen->create_course();
        $b = $gen->create_course();
        $c = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $a->id, 'editingteacher');
        $gen->enrol_user($teacher->id, $c->id, 'editingteacher');
        $student = $gen->create_user();
        foreach (['A' => $a, 'B' => $b, 'C' => $c] as $label => $course) {
            $gen->enrol_user($student->id, $course->id, 'student');
            $item = $gen->create_grade_item(['courseid' => $course->id, 'itemname' => "SECRET-ITEM-{$label}"]);
            $DB->insert_record('grade_grades', (object) ['itemid' => $item->id, 'userid' => $student->id,
                'finalgrade' => 50.0, 'timecreated' => time(), 'timemodified' => time()]);
        }
        $forum = $gen->create_module('forum', ['course' => $b->id]);
        $gen->get_plugin_generator('mod_forum')->create_discussion((object) ['course' => $b->id, 'forum' => $forum->id,
            'userid' => $student->id, 'subject' => 'PRIVATE-POST-B', 'message' => 'private text of course B']);
        set_config('course_mode', 'exclude', 'local_jedomi');
        set_config('course_list', (string) $c->id, 'local_jedomi');
        return ['a' => $a, 'b' => $b, 'c' => $c, 'teacher' => (int) $teacher->id];
    }

    /**
     * Parameters that Moodle would read differently from their plain reading are refused before the course check.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ambiguous_params_provider(): array {
        return [
            'colon key next to the plain key' => [self::GRADES_SQL, 'colon'],
            'colon key alone' => [self::GRADES_SQL, 'colononly'],
            'string key before a positional key' => [self::POSTS_SQL, 'mixed'],
            'string key for a question mark' => [self::POSTS_SQL, 'named'],
            'list for a named placeholder' => [self::GRADES_SQL, 'list'],
            'unused extra key' => [self::GRADES_SQL, 'extra'],
            'extra positional value' => [self::POSTS_SQL, 'extralist'],
            'list not starting at zero' => [self::POSTS_SQL, 'shifted'],
            'placeholder written twice' => ['SELECT fullname FROM {course} WHERE id = :c AND id = :c', 'plain'],
        ];
    }

    /**
     * JEDOMI-SEC-015 (NEW-POC-1a, 1b): ambiguous parameters are refused with their own reason, also when they name the
     * own course, and nothing reaches the database.
     *
     * @dataProvider ambiguous_params_provider
     * @param string $sql Query.
     * @param string $shape Shape of the parameters.
     */
    public function test_ambiguous_params_are_refused(string $sql, string $shape): void {
        $s = $this->scenario();
        $a = (string) $s['a']->id;
        $b = (string) $s['b']->id;
        $params = [
            'colon' => ['c' => $b, ':c' => $a],
            'colononly' => [':c' => $a],
            // What the params object of a tool call decodes to: a string key before the key 0.
            'mixed' => json_decode(json_encode(['x' => $b, '0' => $a]), true),
            'named' => ['c' => $a],
            'list' => [$a],
            'extra' => ['c' => $a, 'd' => $b],
            'extralist' => [$a, $b],
            'shifted' => [1 => $a],
            'plain' => ['c' => $a],
        ][$shape];

        $this->assertSame('sql_params_rejected', sql_guard::rejection_reason($sql, $s['teacher'], $params));
        try {
            query_runner::run($sql, $params, $s['teacher'], 50, 'web');
            $this->fail('The query must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('sql_params_rejected', $e->errorcode);
        }
    }

    /**
     * JEDOMI-SEC-015: the plain parameters of the own course run and return its data; course B and the own course
     * excluded from Jedomi are refused.
     */
    public function test_plain_params_are_checked_and_run(): void {
        $s = $this->scenario();
        $result = query_runner::run(self::GRADES_SQL, ['c' => (string) $s['a']->id], $s['teacher'], 50, 'web');
        $this->assertSame(['SECRET-ITEM-A'], array_column($result['rows'], 'itemname'));
        $result = query_runner::run(self::POSTS_SQL, [(string) $s['a']->id], $s['teacher'], 50, 'web');
        $this->assertSame([], $result['rows']);

        foreach ([$s['b'], $s['c']] as $course) {
            $this->assertSame(
                'sql_course_rejected',
                sql_guard::rejection_reason(self::GRADES_SQL, $s['teacher'], ['c' => (string) $course->id])
            );
            $this->assertSame(
                'sql_course_rejected',
                sql_guard::rejection_reason(self::POSTS_SQL, $s['teacher'], [(string) $course->id])
            );
        }
    }

    /**
     * JEDOMI-SEC-015 (NEW-POC-1c): in the web chat the model gets the refusal, never the grades of course B.
     */
    public function test_web_chat_refuses_ambiguous_params(): void {
        $s = $this->scenario();
        $this->setUser($s['teacher']);
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([['id' => 'c1', 'type' => 'function', 'function' => [
            'name' => 'execute_sql',
            'arguments' => json_encode(['sql' => self::GRADES_SQL,
                'params' => ['c' => (string) $s['b']->id, ':c' => (string) $s['a']->id]]),
        ]]]);
        $fake->enqueue_text('done');
        client::set_instance_for_testing($fake);
        $input = ['messages' => [['role' => 'user', 'content' => 'grades please']],
            'context' => ['course_id' => $s['a']->id, 'location' => 'course']];

        // The stream flushes its buffers, so the output is captured by the buffer callback.
        $sse = '';
        ob_start(static function (string $buffer) use (&$sse): string {
            $sse .= $buffer;
            return '';
        });
        try {
            handler::run_from_request($input, 'teacher', ['course_id' => $s['a']->id], $s['teacher']);
        } finally {
            ob_end_flush();
        }

        $toolmsg = '';
        foreach ($fake->calls[1]['messages'] ?? [] as $message) {
            if (($message['role'] ?? '') === 'tool') {
                $toolmsg = (string) $message['content'];
            }
        }
        $this->assertStringContainsString(json_encode(get_string('sql_params_rejected', 'local_jedomi')), $toolmsg);
        $this->assertStringNotContainsString('SECRET-ITEM-B', $toolmsg);
        $this->assertStringNotContainsString('SECRET-ITEM-B', $sse);
    }

    /**
     * JEDOMI-SEC-015 (NEW-POC-1d): the Telegram tool refuses the same parameters and still runs the plain ones.
     */
    public function test_telegram_refuses_ambiguous_params(): void {
        $s = $this->scenario();
        $out = webhook_handler::execute_tool('execute_sql', ['sql' => self::GRADES_SQL,
            'params' => ['c' => (string) $s['b']->id, ':c' => (string) $s['a']->id]], $s['teacher']);
        $this->assertStringNotContainsString('SECRET-ITEM-B', $out);
        $this->assertStringContainsString(get_string('sql_params_rejected', 'local_jedomi'), $out);

        $own = webhook_handler::execute_tool('execute_sql', ['sql' => self::GRADES_SQL,
            'params' => ['c' => (string) $s['a']->id]], $s['teacher']);
        $this->assertStringContainsString('SECRET-ITEM-A', $own);
    }

    /**
     * JEDOMI-SEC-015 (NEW-POC-1e): the agent SQL tool checks its parameters: the own course runs, another course and
     * ambiguous parameters are refused.
     */
    public function test_agent_sql_tool_checks_its_params(): void {
        $s = $this->scenario();
        $tool = new sql_tool(0, $s['teacher'], 'agent');

        $own = json_decode($tool->execute((object) ['query' => self::GRADES_SQL,
            'params' => (object) ['c' => (string) $s['a']->id]]), true);
        $this->assertTrue($own['success'] ?? false, json_encode($own));
        $this->assertSame(['SECRET-ITEM-A'], array_column($own['rows'], 'itemname'));

        $positional = json_decode($tool->execute((object) ['query' => self::POSTS_SQL,
            'params' => [(string) $s['a']->id]]), true);
        $this->assertTrue($positional['success'] ?? false, json_encode($positional));

        $other = json_decode($tool->execute((object) ['query' => self::GRADES_SQL,
            'params' => (object) ['c' => (string) $s['b']->id]]), true);
        $this->assertSame(get_string('sql_course_rejected', 'local_jedomi'), $other['error'] ?? null);

        $ambiguous = json_decode($tool->execute((object) ['query' => self::GRADES_SQL,
            'params' => (object) ['c' => (string) $s['b']->id, ':c' => (string) $s['a']->id]]), true);
        $this->assertSame(get_string('sql_params_rejected', 'local_jedomi'), $ambiguous['error'] ?? null);
    }
}
