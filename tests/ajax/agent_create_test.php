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
 * Tests for the validation of the Agents tab form (ajax/agent_create.php).
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
}
