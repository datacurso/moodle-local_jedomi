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

namespace local_jedomi\ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests that the Jedomi log never holds the license key nor AI response bodies.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\client
 * @covers     \local_jedomi\proxy\handler
 * @covers     \local_jedomi\task\agent_runner
 */
final class log_privacy_test extends \advanced_testcase {
    /** @var string License key value that must never be logged. */
    private const LICENSE = 'LICENSE-VALUE-THAT-MUST-NOT-BE-LOGGED';

    /** @var string Answer text that must never be logged. */
    private const ANSWER = 'ANSWER-CONTENT-THAT-MUST-NOT-BE-LOGGED';

    /** @var string Error body that must never be logged. */
    private const ERRORBODY = 'ERROR-BODY-THAT-MUST-NOT-BE-LOGGED';

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Path of the Jedomi log.
     *
     * @return string
     */
    private function log_path(): string {
        global $CFG;
        return $CFG->dataroot . '/local/jedomi/jedomi.log';
    }

    /**
     * Install a client over a fake transport that answers with the given queue.
     *
     * @param array $responses List of [body, http code].
     */
    private function install_client(array $responses): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the provider client cannot be built.');
        }
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        $curl = new fake_curl();
        foreach ($responses as [$body, $code]) {
            $curl->enqueue($body, $code);
        }
        client::set_instance_for_testing(
            new client(static fn(int $timeout): provider_api => new testable_provider_api($timeout, $curl))
        );
    }

    /**
     * Answer body in OpenAI chat completion format.
     *
     * @return string
     */
    private function answer_body(): string {
        return json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => self::ANSWER]]]]);
    }

    /**
     * M11: after web chat, agent and error calls, the log has neither the license nor response bodies.
     * MDL-INT-082: the technical log keeps neither the license nor the text of the AI answers.
     */
    public function test_log_has_no_license_or_response_bodies(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('licensekey', self::LICENSE, 'aiprovider_datacurso');
        @unlink($this->log_path());

        // Web chat: success.
        $this->install_client([[$this->answer_body(), 200]]);
        ob_start(static fn(string $buffer): string => '');
        \local_jedomi\proxy\handler::run([['role' => 'user', 'content' => 'hi']], 'admin', (int) get_admin()->id);
        ob_end_flush();

        // Web chat: upstream error with a body.
        $this->install_client([[json_encode(['detail' => self::ERRORBODY]), 503]]);
        ob_start(static fn(string $buffer): string => '');
        \local_jedomi\proxy\handler::run([['role' => 'user', 'content' => 'hi']], 'admin', (int) get_admin()->id);
        ob_end_flush();

        // Scheduled agent: success.
        $now = time();
        $DB->insert_record('local_jedomi_agents', (object) [
            'userid' => get_admin()->id, 'name' => 'Agent', 'prompt' => 'Report', 'schedule_cron' => '0 8 * * *',
            'context_type' => 'site', 'enabled' => 1, 'nextrun' => $now - 60, 'failcount' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->install_client([[$this->answer_body(), 200]]);
        ob_start();
        (new \local_jedomi\task\agent_runner())->execute();
        ob_end_clean();
        $this->resetDebugging();

        $log = (string) file_get_contents($this->log_path());
        $this->assertStringContainsString('AI_REQUEST', $log, 'The calls must be logged');
        $this->assertStringContainsString('AI_ERROR', $log);
        $this->assertStringNotContainsString(self::LICENSE, $log);
        $this->assertStringNotContainsString(self::ANSWER, $log);
        $this->assertStringNotContainsString(self::ERRORBODY, $log);
    }
    /**
     * Run one web chat question as the administrator over the given AI double, discarding the stream.
     *
     * @param fake_ai_client $fake AI double.
     * @param string $question Question of the user.
     * @return void
     */
    private function ask_in_the_web_chat(fake_ai_client $fake, string $question): void {
        client::set_instance_for_testing($fake);
        ob_start(static fn(string $buffer): string => '');
        \local_jedomi\proxy\handler::run([['role' => 'user', 'content' => $question]], 'admin', (int) get_admin()->id);
        ob_end_flush();
    }

    /**
     * MDL-INT-082: the technical log keeps the text of the questions neither from the web chat nor from Telegram.
     */
    public function test_log_has_no_question_text(): void {
        global $DB;
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; Telegram asks it whether Jedomi is enabled.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        @unlink($this->log_path());
        $question = 'QUESTION-TEXT-THAT-MUST-NOT-BE-LOGGED';

        $fake = new fake_ai_client();
        $fake->enqueue_text('Fine.');
        $this->ask_in_the_web_chat($fake, $question);

        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => get_admin()->id, 'chatid' => '776000001',
            'username' => 'admin', 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        set_config('telegram_enabled', 1, 'local_jedomi');
        \local_jedomi\telegram\api_client::set_instance_for_testing(new \local_jedomi\telegram\fake_telegram_api_client());
        $fake->enqueue_text('Fine.');
        $limit = (int) ini_get('max_execution_time');
        \local_jedomi\telegram\webhook_handler::handle(['message' => ['chat' => ['id' => '776000001'], 'text' => $question]]);
        set_time_limit($limit);
        \local_jedomi\telegram\api_client::set_instance_for_testing(null);

        $this->assertCount(2, $fake->calls, 'Both channels asked the AI');
        $log = (string) file_get_contents($this->log_path());
        $this->assertNotSame('', $log, 'The requests must be logged');
        $this->assertStringNotContainsString($question, $log);
    }

    /**
     * MDL-INT-083: the technical log does not keep the arguments of the tools, which can hold personal data (database
     * queries with names or e-mails, parameters of web services).
     */
    public function test_log_has_no_tool_arguments_with_personal_data(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        @unlink($this->log_path());
        $email = 'ana.private.person@example.com';
        $fake = new fake_ai_client();
        $fake->enqueue_tool_calls([['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'execute_sql',
            'arguments' => json_encode(['sql' => "SELECT id FROM {user} WHERE email = '{$email}'"])]]]);
        $fake->enqueue_text('There is no such user.');

        $this->ask_in_the_web_chat($fake, 'Does Ana have an account?');

        $this->assertCount(2, $fake->calls, 'The tool ran and its result went back to the AI');
        $log = (string) file_get_contents($this->log_path());
        $this->assertStringNotContainsString($email, $log, 'The personal data in the tool arguments is not logged');
    }
}
