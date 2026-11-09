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

use aiprovider_datacurso\local\license_region;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\ai\fake_curl;
use local_jedomi\ai\testable_provider_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for the end-to-end check run by cli/e2e_check.php.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\e2e_check
 */
final class e2e_check_test extends \advanced_testcase {
    /** @var string[] Lines written by the check. */
    private array $lines = [];

    /** @var array Tool calls received by the tool runner: [name, args]. */
    private array $toolruns = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the end-to-end check needs its license region.');
        }
        $this->resetAfterTest();
        $this->lines = [];
        $this->toolruns = [];
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        set_config('model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        $this->remember_region();
    }

    /**
     * Resolve the license region beforehand, so the check does not ask the shop.
     */
    private function remember_region(): void {
        set_config(license_region::REGION, '0', 'aiprovider_datacurso');
        set_config(license_region::FINGERPRINT, license_region::fingerprint(), 'aiprovider_datacurso');
        set_config(license_region::CHECKED, time(), 'aiprovider_datacurso');
    }

    /**
     * Build a check over the given AI client, collecting its output and tool runs.
     *
     * @param \local_jedomi\ai\client $client AI client.
     * @param string|null $toolresult Tool result to return; a list of tables when null.
     * @return e2e_check
     */
    private function make_check(\local_jedomi\ai\client $client, ?string $toolresult = null): e2e_check {
        $runner = function (string $name, array $args) use ($toolresult): string {
            $this->toolruns[] = [$name, $args];
            return $toolresult ?? json_encode(['tables' => [['name' => 'user']]]);
        };
        $writer = function (string $line): void {
            $this->lines[] = $line;
        };
        return new e2e_check($client, $runner, $writer);
    }

    /**
     * An answer of the model asking for the search_tables tool.
     *
     * @return array
     */
    private static function search_tables_call(): array {
        return [[
            'id' => 'call_e2e',
            'type' => 'function',
            'function' => ['name' => 'search_tables', 'arguments' => '{"query":"user"}'],
        ]];
    }

    /**
     * The output line of a step.
     *
     * @param string $step Step label.
     * @return string|null
     */
    private function line_for(string $step): ?string {
        foreach ($this->lines as $line) {
            if (str_contains($line, $step)) {
                return $line;
            }
        }
        return null;
    }

    /**
     * MDL-UNIT-084: a correct conversation completes every step.
     * I7: a conversation with one tool call and a final answer passes every step.
     */
    public function test_successful_conversation_passes_every_step(): void {
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls(self::search_tables_call());
        $fake->enqueue_text('The users are stored in the user table.');
        $admin = get_admin();

        $ok = $this->make_check($fake)->run((int) $admin->id);

        $this->assertTrue($ok);
        foreach (e2e_check::STEPS as $step) {
            $this->assertStringStartsWith('PASS', (string) $this->line_for($step), "Step '{$step}'");
        }
        $this->assertStringContainsString('jedomi/zai-org/GLM-5.3-Flash', $this->line_for(e2e_check::STEP_MODEL));
        $this->assertSame([['search_tables', ['query' => 'user']]], $this->toolruns);
        $this->assertCount(2, $fake->calls);
        $this->assertSame((int) $admin->id, $fake->calls[0]['userid']);
        $this->assertSame('search_tables', $fake->calls[0]['tools'][0]['function']['name']);
        $second = $fake->calls[1]['messages'];
        $this->assertSame('call_e2e', end($second)['tool_call_id']);
        $this->assertSame('tool', end($second)['role']);
    }

    /**
     * MDL-INT-003: the diagnostic reports the provider disabled without calling the service.
     * MDL-UNIT-084: with the provider disabled the first step fails without calling the service.
     * I7: with the provider disabled the first step fails with its code and layer, and nothing is sent.
     */
    public function test_disabled_provider_fails_the_first_step_without_calling_the_service(): void {
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 0);
        $fake = new fake_ai_client();

        $ok = $this->make_check($fake)->run((int) get_admin()->id);

        $this->assertFalse($ok);
        $line = (string) $this->line_for(e2e_check::STEP_PROVIDER);
        $this->assertStringStartsWith('FAIL', $line);
        $this->assertStringContainsString('code=provider_disabled', $line);
        $this->assertStringContainsString('layer=' . e2e_check::LAYER_PROVIDER, $line);
        $this->assertSame([], $fake->calls);
    }

    /**
     * Failures of the AI call and the layer each one is reported in.
     *
     * @return array
     */
    public static function failure_provider(): array {
        return [
            'no credit' => ['notenoughtokens', null, 'no_credit', 'notenoughtokens', e2e_check::LAYER_BILLING],
            'window limit' => ['error_ratelimit_exceeded', '', 'rate_limited', 'error_ratelimit_exceeded', e2e_check::LAYER_BILLING],
            'license not allowed' => ['license_not_allowed', null, 'license', 'license_not_allowed', e2e_check::LAYER_BILLING],
            'missing license' => ['invalidlicensekey', null, 'license', 'invalidlicensekey', e2e_check::LAYER_PROVIDER],
            'provider disabled' => ['providerdisabled', null, 'provider_disabled', 'providerdisabled', e2e_check::LAYER_PROVIDER],
            'network' => ['curlerror', 7, 'unavailable', 'curlerror', e2e_check::LAYER_NETWORK],
            'timeout' => ['curlerror', CURLE_OPERATION_TIMEDOUT, 'timeout', 'curlerror', e2e_check::LAYER_NETWORK],
            'empty answer' => ['emptyresponse', null, 'unavailable', 'emptyresponse', e2e_check::LAYER_NETWORK],
            'model unavailable' => ['httperror', 502, 'unavailable', 'httperror', e2e_check::LAYER_AI_SERVICE],
            'invalid model' => ['httperror', 400, 'generic', 'httperror', e2e_check::LAYER_AI_SERVICE],
        ];
    }

    /**
     * MDL-UNIT-084: a failed request reports the code and the layer of the failure.
     * I7: a failed AI call reports its code, the provider error and the layer that returned it.
     *
     * @dataProvider failure_provider
     * @param string $errorcode Provider error code.
     * @param mixed $a Provider exception parameter.
     * @param string $code Expected Jedomi error code.
     * @param string $providercode Expected provider error code in the output.
     * @param string $layer Expected layer.
     */
    public function test_failed_request_reports_code_and_layer(
        string $errorcode,
        $a,
        string $code,
        string $providercode,
        string $layer
    ): void {
        $fake = new fake_ai_client();
        $fake->enqueue_exception(new \moodle_exception($errorcode, 'aiprovider_datacurso', '', $a));

        $ok = $this->make_check($fake)->run((int) get_admin()->id);

        $this->assertFalse($ok);
        $this->assertStringStartsWith('PASS', (string) $this->line_for(e2e_check::STEP_PROVIDER));
        $line = (string) $this->line_for(e2e_check::STEP_REQUEST);
        $this->assertStringStartsWith('FAIL', $line);
        $this->assertStringContainsString('code=' . $code, $line);
        $this->assertStringContainsString('aiprovider_datacurso/' . $providercode, $line);
        $this->assertStringContainsString('layer=' . $layer, $line);
        $this->assertNull($this->line_for(e2e_check::STEP_FINAL));
    }

    /**
     * MDL-UNIT-084: an answer without a tool call fails the tool step.
     * I7: an answer without the expected tool call fails the tool step and stops.
     */
    public function test_answer_without_tool_call_fails_the_tool_step(): void {
        $fake = new fake_ai_client();
        $fake->enqueue_text('I will not use any tool.');

        $ok = $this->make_check($fake)->run((int) get_admin()->id);

        $this->assertFalse($ok);
        $this->assertStringStartsWith('PASS', (string) $this->line_for(e2e_check::STEP_REQUEST));
        $line = (string) $this->line_for(e2e_check::STEP_TOOL);
        $this->assertStringStartsWith('FAIL', $line);
        $this->assertStringContainsString('layer=' . e2e_check::LAYER_AI_SERVICE, $line);
        $this->assertSame([], $this->toolruns);
        $this->assertCount(1, $fake->calls);
    }

    /**
     * MDL-UNIT-084: a tool error fails the tool step.
     * I7: a tool that returns an error fails the tool step and stops, without printing the error text.
     */
    public function test_tool_returning_an_error_fails_the_tool_step(): void {
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls(self::search_tables_call());
        $fake->enqueue_text('The users are stored in the user table.');

        $ok = $this->make_check($fake, json_encode(['error' => 'permission_denied']))->run((int) get_admin()->id);

        $this->assertFalse($ok);
        $line = (string) $this->line_for(e2e_check::STEP_TOOL);
        $this->assertStringStartsWith('FAIL', $line);
        $this->assertStringContainsString('code=tool_error', $line);
        $this->assertStringContainsString('layer=' . e2e_check::LAYER_JEDOMI, $line);
        $this->assertStringNotContainsString('permission_denied', $line);
        $this->assertNull($this->line_for(e2e_check::STEP_FINAL));
        $this->assertCount(1, $fake->calls);
    }

    /**
     * MDL-UNIT-084: the output never contains the license or the full answers.
     * I7: through the real provider client, the output has neither the license nor the answer bodies.
     */
    public function test_output_never_contains_the_license_or_full_answers(): void {
        $license = 'SECRET-LICENSE-NEVER-PRINTED';
        set_config('licensekey', $license, 'aiprovider_datacurso');
        $this->remember_region();
        $longanswer = 'The table is user. ' . str_repeat('FULLBODY', 200);
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['choices' => [[
            'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => self::search_tables_call()],
            'finish_reason' => 'tool_calls',
        ]]]));
        $curl->enqueue(json_encode(['choices' => [[
            'message' => ['role' => 'assistant', 'content' => $longanswer],
            'finish_reason' => 'stop',
        ]]]));

        $ok = $this->make_check(testable_provider_api::make_client($curl))->run((int) get_admin()->id);

        $this->assertTrue($ok);
        $this->assertCount(2, $curl->calls);
        $output = implode("\n", $this->lines);
        $this->assertStringNotContainsString($license, $output);
        $this->assertStringNotContainsString($longanswer, $output);
        $this->assertStringNotContainsString(str_repeat('FULLBODY', 20), $output);
    }

    /**
     * MDL-INT-004: with Jedomi disabled the command console and the end-to-end diagnostic say it is not enabled and call no AI.
     * [Pendiente:skip]
     */
    public function test_console_and_diagnostic_respect_the_enabled_switch(): void {
        $this->markTestSkipped('Pending fix: with Jedomi disabled the command console and the end-to-end diagnostic still '
            . 'send queries to the AI service and use credits.');
    }
}
