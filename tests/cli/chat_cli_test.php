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
     * @return string Everything the console printed.
     */
    private function ask_the_console(string $tool, array $args): string {
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
        // The user asks, does not answer any confirmation request with a yes, and leaves.
        fwrite($pipes[0], "Please do it\nno\nexit\n");
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        return $output;
    }

    /**
     * MDL-INT-093: the console shows each action that changes data and asks for its confirmation before running it:
     * without a confirmation nothing is changed.
     * [Pendiente:fail] The console runs every tool as the site administrator, including the ones that change data,
     * without asking for a confirmation.
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
        $this->assertMatchesRegularExpression('/confirm/i', $output, 'The console must ask for a confirmation');
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
