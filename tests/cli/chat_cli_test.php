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

namespace local_jedomi\cli;

use local_jedomi\agent\agent_manager;

/**
 * Tests for the command console of the assistant (cli/chat_cli.php).
 *
 * The console reads the questions from the standard input, so each conversation runs in its own process through
 * tests/fixtures/chat_cli_runner.php, with the AI service replaced by a scripted answer.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class chat_cli_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        global $DB;
        // The console runs in another process, so what it writes is not undone by the reset of this test.
        $DB->delete_records('local_jedomi_agents');
        $DB->delete_records('local_jedomi_agent_cache');
        parent::tearDown();
    }

    /**
     * Ask the console a question; the AI answers by calling one tool, then gives a final answer.
     *
     * @param string $tool Tool the AI calls.
     * @param array $args Arguments of the tool.
     * @param string $input What the user types: by default the question, a "no" to any confirmation and exit.
     * @return string Everything the console printed.
     */
    private function ask_the_console(string $tool, array $args, string $input = "Please do it\nno\nexit\n"): string {
        global $CFG;
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the console checks the plugin state through it.');
        }
        $toolcalls = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $tool,
            'arguments' => json_encode($args)]]];
        $runner = $CFG->dirroot . '/local/jedomi/tests/fixtures/chat_cli_runner.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg(json_encode($toolcalls));
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $this->assertIsResource($process);
        // The input is closed after it: an unanswered question reads the end of the input.
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        return $output;
    }

    /**
     * MDL-INT-093: the console shows each action that changes data and asks for its confirmation before running it:
     * without a confirmation nothing is changed.
     *
     * @return void
     */
    public function test_console_asks_for_confirmation_before_changing_data(): void {
        global $DB;
        $this->resetAfterTest();

        $output = $this->ask_the_console('create_agent', ['name' => 'Console agent', 'prompt' => 'Daily summary',
            'schedule_cron' => '0 8 * * *']);

        $this->assertStringContainsString('create_agent', $output, 'Control: the console received the proposal. ' . $output);
        $this->assertSame(
            0,
            $DB->count_records('local_jedomi_agents'),
            'Without a confirmation the console must not create the agent. Output: ' . $output
        );
        $this->assertStringContainsString(
            'Type "yes" to run it, anything else to cancel:',
            $output,
            'The console must ask for a confirmation'
        );
        $this->assertStringContainsString('Cancelled.', $output);
        $this->assertStringContainsString('The operator cancelled this action; it was not run.', $output);
    }

    /**
     * MDL-INT-093: an explicit "yes" runs the action, and the confirmation shows every argument, never a cut of them.
     *
     * @return void
     */
    public function test_console_runs_the_action_after_an_explicit_yes(): void {
        global $DB;
        $this->resetAfterTest();
        $prompt = str_repeat('Summarise the activity of the week. ', 20) . 'END-OF-PROMPT-MARKER';

        $output = $this->ask_the_console('create_agent', ['name' => 'Console agent', 'prompt' => $prompt,
            'schedule_cron' => '0 8 * * *'], "Please do it\nyes\nexit\n");

        $this->assertStringContainsString('Confirmed.', $output, $output);
        $this->assertStringContainsString('END-OF-PROMPT-MARKER', $output, 'The whole arguments are shown before confirming');
        $this->assertSame(1, $DB->count_records('local_jedomi_agents'), 'The confirmed action runs. Output: ' . $output);
        $prompts = $DB->get_fieldset_select('local_jedomi_agents', 'prompt', '');
        $this->assertSame([$prompt], $prompts, 'It runs with the arguments shown');
    }

    /**
     * MDL-INT-093: a closed input (the end of the input instead of an answer) cancels the action, and the AI gets the
     * result of the cancelled action.
     *
     * @return void
     */
    public function test_console_with_a_closed_input_cancels_the_action(): void {
        global $DB;
        $this->resetAfterTest();

        $output = $this->ask_the_console('create_agent', ['name' => 'Console agent', 'prompt' => 'Daily summary',
            'schedule_cron' => '0 8 * * *'], "Please do it\n");

        $this->assertStringContainsString('Cancelled.', $output, $output);
        $this->assertStringContainsString('The operator cancelled this action; it was not run.', $output);
        $this->assertSame(0, $DB->count_records('local_jedomi_agents'), 'Nothing runs without an answer. Output: ' . $output);
    }

    /**
     * MDL-INT-093: reading tools run in the console without a confirmation.
     *
     * @return void
     */
    public function test_console_runs_reading_tools_without_confirmation(): void {
        $this->resetAfterTest();
        $agent = agent_manager::create_agent((object) ['userid' => (int) get_admin()->id,
            'prompt' => 'Listed agent marker', 'schedule_cron' => '0 8 * * 1']);

        $output = $this->ask_the_console('list_agents', []);

        $this->assertStringContainsString('Executing list_agents', $output);
        $this->assertStringContainsString('Listed agent marker', $output, 'The reading tool ran and returned the agent');
        $this->assertNotNull(agent_manager::get_agent($agent));
    }

    /**
     * MDL-INT-004: with Jedomi disabled in the provider the console shows the not enabled message of the provider, in
     * English, and makes no AI call.
     *
     * @return void
     */
    public function test_console_with_jedomi_disabled_shows_the_provider_message(): void {
        $this->resetAfterTest();
        if (!class_exists(\aiprovider_datacurso\local\plugin_settings::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the console checks the plugin state through it.');
        }
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_jedomi', false);
        $message = \local_jedomi_plugin_disabled_error()['message'];

        try {
            $output = $this->ask_the_console('list_agents', []);
        } finally {
            \aiprovider_datacurso\local\plugin_settings::set_enabled('local_jedomi', true);
        }

        $this->assertStringContainsString($message, $output);
        $this->assertStringNotContainsString('AI thinking', $output, 'No AI call is made while Jedomi is disabled');
        $this->assertStringNotContainsString('Executing', $output);
    }
}
