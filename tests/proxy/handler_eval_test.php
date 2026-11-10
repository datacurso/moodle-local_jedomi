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

namespace local_jedomi\proxy;

use local_jedomi\ai\client;
use local_jedomi\ai\live_provider_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/live_provider_api.php');

/**
 * Evaluation of the answers of the real AI service in the web chat against a golden dataset.
 *
 * These tests call the real AI service, so they are opt-in: set JEDOMI_EVAL_AI_URL (address of the AI service, for
 * example http://host.docker.internal:8002) and JEDOMI_EVAL_LICENSE (a Datacurso licence accepted by that service) in
 * the environment of PHPUnit. Without them the tests are skipped: that skip is an environment limitation, not a
 * pending defect. The dataset is tests/fixtures/eval/jedomi_eval_dataset.json; the answers are checked on structural
 * and quality properties (values of the site data, language, tools used, actions proposed), never on exact text.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\proxy\handler
 */
final class handler_eval_test extends \advanced_testcase {
    /** @var array Golden dataset. */
    private array $dataset = [];

    /** @var array<string, \stdClass> Users of the evaluation site by dataset role. */
    private array $users = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $url = (string) getenv('JEDOMI_EVAL_AI_URL');
        $license = (string) getenv('JEDOMI_EVAL_LICENSE');
        if ($url === '' || $license === '') {
            $this->markTestSkipped('Environment, not a pending gap: set JEDOMI_EVAL_AI_URL and JEDOMI_EVAL_LICENSE to '
                . 'evaluate the answers of the real AI service.');
        }
        if (!class_exists(live_provider_api::class)) {
            $this->markTestSkipped('Environment, not a pending gap: aiprovider_datacurso is not installed.');
        }
        $this->resetAfterTest();
        set_config('licensekey', $license, 'aiprovider_datacurso');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        client::set_instance_for_testing(live_provider_api::make_client($url));
        $this->dataset = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/eval/jedomi_eval_dataset.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->create_site();
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Create the courses and students of the dataset and the users of each role.
     *
     * @return void
     */
    private function create_site(): void {
        global $DB;
        $site = $this->dataset['site'];
        $DB->set_field('course', 'fullname', $site['fullname'], ['id' => SITEID]);
        $generator = $this->getDataGenerator();
        $number = 0;
        $firstcourse = null;
        foreach ($site['courses'] as $data) {
            $course = $generator->create_course(['fullname' => $data['fullname'], 'shortname' => $data['shortname']]);
            $firstcourse = $firstcourse ?? $course;
            for ($i = 0; $i < $data['students']; $i++) {
                $number++;
                $student = $generator->create_user(['username' => $site['student_prefix'] . $number]);
                $generator->enrol_user($student->id, $course->id, 'student');
            }
        }
        $this->users['admin'] = get_admin();
        $this->users['student'] = $DB->get_record('user', ['username' => $site['student_prefix'] . '1'], '*', MUST_EXIST);
    }

    /**
     * Ask a question in the web chat as a user and collect the answer, the tools used and the actions proposed.
     *
     * @param string $datasetrole Dataset role: the user that asks.
     * @param string $question Question.
     * @return array{answer: string, tools: string[], pending: array[], errors: array[]}
     */
    private function ask(string $datasetrole, string $question): array {
        $user = $this->users[$datasetrole];
        $this->setUser($user);
        $role = \local_jedomi_get_user_role();
        $spt = \local_jedomi_generate_spt($role);
        $captured = '';
        ob_start(static function (string $buffer) use (&$captured): string {
            $captured .= $buffer;
            return '';
        });
        try {
            handler::run_from_request(
                ['messages' => [['role' => 'user', 'content' => $question]], 'context' => []],
                $role,
                $spt,
                (int) $user->id
            );
        } finally {
            ob_end_flush();
        }
        $this->resetDebugging();

        $result = ['answer' => '', 'tools' => [], 'pending' => [], 'errors' => []];
        foreach (explode("\n", $captured) as $line) {
            if (!str_starts_with($line, 'data: ') || substr($line, 6) === '[DONE]') {
                continue;
            }
            $event = json_decode(substr($line, 6), true);
            if (!is_array($event)) {
                continue;
            }
            if (isset($event['_jedomi_tool_executing']['tool'])) {
                $result['tools'][] = (string) $event['_jedomi_tool_executing']['tool'];
            } else if (isset($event['_jedomi_pending_action'])) {
                $result['pending'][] = $event['_jedomi_pending_action'];
            } else if (isset($event['error'])) {
                $result['errors'][] = $event['error'];
            } else if (isset($event['choices'][0]['delta']['content'])) {
                $result['answer'] .= (string) $event['choices'][0]['delta']['content'];
            }
        }
        return $result;
    }

    /**
     * Language a text is written in, between English and Spanish, by its most frequent function words.
     *
     * @param string $text Text.
     * @return string "en" or "es".
     */
    private static function language_of(string $text): string {
        $words = preg_split('/[^\p{L}]+/u', \core_text::strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $en = ['the', 'is', 'are', 'there', 'of', 'and', 'in', 'courses', 'course', 'students', 'enrolled', 'with', 'has'];
        $es = ['el', 'la', 'los', 'las', 'de', 'hay', 'y', 'en', 'cursos', 'curso', 'estudiantes', 'matriculados', 'con',
            'que', 'del', 'tiene', 'son'];
        $score = ['en' => 0, 'es' => 0];
        foreach ($words as $word) {
            $score['en'] += in_array($word, $en, true) ? 1 : 0;
            $score['es'] += in_array($word, $es, true) ? 1 : 0;
        }
        return $score['es'] > $score['en'] ? 'es' : 'en';
    }

    /**
     * Assert that the share of passed items reaches the threshold of the dataset.
     *
     * @param array<string,string[]> $failures Problems by item id; an item without problems passed.
     * @return void
     */
    private function assert_threshold(array $failures): void {
        $total = count($failures);
        $passed = count(array_filter($failures, static fn(array $problems): bool => $problems === []));
        $report = [];
        foreach ($failures as $id => $problems) {
            if ($problems !== []) {
                $report[] = $id . ': ' . implode('; ', $problems);
            }
        }
        $this->assertGreaterThanOrEqual(
            $this->dataset['threshold'],
            $total ? $passed / $total : 0,
            "Only {$passed} of {$total} items passed:\n" . implode("\n", $report)
        );
    }

    /**
     * SYS-EVAL-001: the answers use the data returned by the tools without inventing values, are in the language of the
     * question, leave out the front page and the guest user and explain why something cannot be done.
     *
     * @return void
     */
    public function test_answers_use_real_data_in_the_language_of_the_question(): void {
        $failures = [];
        foreach ($this->dataset['answer_quality'] as $item) {
            $result = $this->ask($item['role'], $item['question']);
            $problems = [];
            $answer = $result['answer'];
            if ($result['errors']) {
                $problems[] = 'service error ' . json_encode($result['errors']);
            }
            foreach ($item['must_contain'] as $expected) {
                if (!str_contains(\core_text::strtolower($answer), \core_text::strtolower($expected))) {
                    $problems[] = "missing \"{$expected}\"";
                }
            }
            foreach ($item['must_not_contain'] as $unexpected) {
                if (str_contains(\core_text::strtolower($answer), \core_text::strtolower($unexpected))) {
                    $problems[] = "contains \"{$unexpected}\"";
                }
            }
            if (trim($answer) !== '' && self::language_of($answer) !== $item['language']) {
                $problems[] = 'answered in ' . self::language_of($answer) . ' instead of ' . $item['language'];
            }
            if (!empty($item['explains_refusal'])) {
                if ($result['pending']) {
                    $problems[] = 'proposed an action instead of explaining why it cannot be done';
                }
                if (\core_text::strlen(trim($answer)) < 20) {
                    $problems[] = 'no explanation';
                }
            }
            $failures[$item['id']] = $problems;
        }
        $this->assert_threshold($failures);
    }

    /**
     * SYS-EVAL-002: the AI asks the web services before the database, never proposes an action that changes data when
     * the user only asks, and proposes the right action when the user asks for it.
     *
     * @return void
     */
    public function test_tools_are_chosen_well_and_actions_need_an_explicit_request(): void {
        global $DB;
        $dbtools = ['execute_sql', 'search_tables', 'describe_table'];
        $failures = [];
        foreach ($this->dataset['tool_choice'] as $item) {
            $result = $this->ask($item['role'], $item['question']);
            $problems = [];
            if ($result['errors']) {
                $problems[] = 'service error ' . json_encode($result['errors']);
            }
            if ($item['expect'] === 'read') {
                if ($result['pending']) {
                    $problems[] = 'proposed ' . json_encode(array_column($result['pending'], 'tool')) . ' for a question';
                }
                $firstdb = null;
                $firstws = null;
                foreach ($result['tools'] as $position => $tool) {
                    if ($firstdb === null && in_array($tool, $dbtools, true)) {
                        $firstdb = $position;
                    }
                    if ($firstws === null && str_starts_with($tool, 'ws_')) {
                        $firstws = $position;
                    }
                }
                if (!empty($item['web_services_first']) && $firstdb !== null && ($firstws === null || $firstws > $firstdb)) {
                    $problems[] = 'used the database before the web services: ' . implode(', ', $result['tools']);
                }
            } else {
                $match = array_filter($result['pending'], static fn(array $pending): bool =>
                    ($pending['tool'] ?? '') === $item['pending_tool']
                    && ($item['pending_contains'] === '' || str_contains(json_encode($pending), $item['pending_contains'])));
                if (!$match) {
                    $problems[] = "did not propose {$item['pending_tool']}: " . json_encode($result['pending']);
                }
            }
            $failures[$item['id']] = $problems;
        }
        $this->assertFalse($DB->record_exists('course', ['shortname' => 'EVC1']), 'No action runs without confirmation');
        $this->assert_threshold($failures);
    }
}
