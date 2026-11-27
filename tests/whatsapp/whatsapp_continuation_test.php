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

namespace local_jedomi\whatsapp;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\fake_plugin_state;
use local_jedomi\messaging\http_responder;
use local_jedomi\messaging\plugin_state;
use local_jedomi\messaging\recording_http_responder;
use local_jedomi\provider_absence_probe;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_cloud_api_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/recording_http_responder.php');

/**
 * Tests for the continuation of a long WhatsApp question: the self-ping to whatsapp_webhook.php?internal=1, signed
 * with the internal secret of the plugin (never the App Secret of Meta), and its handling by the webhook, which resumes
 * the tool loop of the chat.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\whatsapp_continuation
 * @covers     \local_jedomi\whatsapp\internal_ping
 * @covers     \local_jedomi\whatsapp\intake
 */
final class whatsapp_continuation_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Phone of the student. */
    private const WAID = '16505551234';

    /** @var int Frozen time of the tests. */
    private const NOW = 1800000000;

    /** @var int Session generation of the seeded loop. */
    private const GEN = 4242;

    /** @var fake_cloud_api_client Client double. */
    private fake_cloud_api_client $client;

    /** @var fake_ai_client AI double. */
    private fake_ai_client $ai;

    /** @var recording_http_responder Responder double. */
    private recording_http_responder $responder;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::NOW);
        provider_absence_probe::start();
        set_config('whatsapp_enabled', 1, 'local_jedomi');
        manager::set_app_secret('meta-app-secret');
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $this->client = new fake_cloud_api_client();
        cloud_api_client::set_instance_for_testing($this->client);
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
        $this->responder = new recording_http_responder();
        \core\di::set(http_responder::class, $this->responder);
    }

    #[\Override]
    protected function assertPostConditions(): void {
        parent::assertPostConditions();
        $this->assertSame([], provider_absence_probe::touched(), 'The test reached the Datacurso AI provider.');
    }

    #[\Override]
    protected function tearDown(): void {
        cloud_api_client::set_instance_for_testing(null);
        client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Chat key of the student.
     *
     * @return string
     */
    private static function chatkey(): string {
        return whatsapp_link_service::chatkey(self::BSUID);
    }

    /**
     * A linked student with a tool loop in progress, as a worker leaves it when it hands over.
     *
     * @return int User id.
     */
    private function seed(): int {
        global $DB;
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) ['userid' => $userid, 'bsuid' => self::BSUID,
            'waid' => self::WAID, 'phone' => '+' . self::WAID, 'confirmed' => 1, 'consentat' => self::NOW - DAYSECS,
            'consentversion' => consent::VERSION, 'consentcategories' => 'chat,grades', 'lastused' => self::NOW - 60,
            'lastinbound' => self::NOW - 60, 'timecreated' => self::NOW - DAYSECS]);
        (new whatsapp_channel())->store()->set_context('tool_loop_' . self::chatkey(), [
            'session_gen' => self::GEN,
            'started_at' => self::NOW - 40,
            'is_locked' => true,
            'locked_at' => self::NOW - 40,
            'iteration' => 1,
            'messages' => [['role' => 'system', 'content' => 'placeholder'], ['role' => 'user', 'content' => 'hola']],
            'userid' => $userid,
        ]);
        return $userid;
    }

    /**
     * Post an internal ping to the webhook.
     *
     * @param array $request Request built by the continuation.
     * @param string|null $body Body that replaces the signed one.
     */
    private function ping(array $request, ?string $body = null): void {
        $signature = null;
        foreach ($request['headers'] as $header) {
            if (str_starts_with($header, internal_ping::SIGNATURE_HEADER . ': ')) {
                $signature = substr($header, strlen(internal_ping::SIGNATURE_HEADER . ': '));
            }
        }
        $query = [];
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        $limit = (int) ini_get('max_execution_time');
        (new intake())->handle('POST', $query, $body ?? $request['body'], null, $signature);
        set_time_limit($limit);
    }

    /**
     * The self-ping goes to whatsapp_webhook.php?internal=1 with the chat in its body, never in the address, and is
     * signed with the internal secret of the plugin, not with the App Secret of Meta.
     */
    public function test_the_self_ping_is_signed_with_the_internal_secret(): void {
        $request = (new whatsapp_continuation())->request(self::chatkey(), self::GEN);

        $this->assertStringEndsWith('/local/jedomi/whatsapp_webhook.php?internal=1', $request['url']);
        $this->assertStringNotContainsString(self::BSUID, $request['url']);
        $this->assertSame(
            ['chatkey' => self::chatkey(), 'session_gen' => self::GEN, 'time' => self::NOW],
            json_decode($request['body'], true)
        );

        $secret = internal_ping::secret();
        $this->assertSame(64, strlen($secret));
        $this->assertNotSame('meta-app-secret', $secret);
        $this->assertSame($secret, internal_ping::secret(), 'The secret is generated once and kept.');
        $this->assertContains(
            internal_ping::SIGNATURE_HEADER . ': sha256=' . hash_hmac('sha256', $request['body'], $secret),
            $request['headers']
        );
        $this->assertNotContains(
            internal_ping::SIGNATURE_HEADER . ': sha256=' . hash_hmac('sha256', $request['body'], 'meta-app-secret'),
            $request['headers']
        );
        $this->assertFalse($request['options']['CURLOPT_FOLLOWLOCATION']);
    }

    /**
     * A signed ping is acknowledged at once and resumes the tool loop of the chat: the answer reaches the student.
     */
    public function test_a_signed_ping_resumes_the_tool_loop(): void {
        $this->seed();
        $this->ai->enqueue_text('Listo, aquí tiene la respuesta.');

        $this->ping((new whatsapp_continuation())->request(self::chatkey(), self::GEN));

        $this->assertSame(200, $this->responder->single()['status']);
        $this->assertTrue($this->responder->single()['closed']);
        $this->assertCount(1, $this->ai->calls);
        $sent = $this->client->calls_to('send_text');
        $this->assertCount(1, $sent);
        $this->assertSame(['to' => self::WAID], $sent[0]['args'][0]->to_payload());
        $this->assertSame('Listo, aquí tiene la respuesta.', $sent[0]['args'][1]);
    }

    /**
     * A ping of another session generation does nothing.
     */
    public function test_a_ping_of_an_old_session_does_nothing(): void {
        $this->seed();

        $this->ping((new whatsapp_continuation())->request(self::chatkey(), self::GEN - 1));

        $this->assertSame(200, $this->responder->single()['status']);
        $this->assertSame([], $this->ai->calls);
        $this->assertSame([], $this->client->calls);
    }

    /**
     * A ping without a valid signature, signed with the App Secret, too old, of another method or for a malformed chat
     * is refused with 403 (405 for the method) and resumes nothing.
     *
     * @dataProvider refused_ping_provider
     * @param string $case Case.
     */
    public function test_an_invalid_ping_is_refused(string $case): void {
        $this->seed();
        $continuation = new whatsapp_continuation();
        $request = $continuation->request(self::chatkey(), self::GEN);
        $body = null;
        $status = 403;
        switch ($case) {
            case 'unsigned':
                $request['headers'] = [];
                break;
            case 'tampered':
                $body = str_replace((string) self::GEN, (string) (self::GEN + 1), $request['body']);
                break;
            case 'app secret':
                $request['headers'] = [internal_ping::SIGNATURE_HEADER . ': sha256='
                    . hash_hmac('sha256', $request['body'], 'meta-app-secret')];
                break;
            case 'too old':
                $this->mock_clock_with_frozen(self::NOW + internal_ping::MAX_AGE + 1);
                break;
            case 'malformed chat':
                $request = self::signed(['chatkey' => '../' . self::BSUID, 'session_gen' => self::GEN, 'time' => self::NOW]);
                break;
            case 'not json':
                $request = self::signed_body('nope');
                break;
        }

        if ($case === 'get') {
            (new intake())->handle('GET', ['internal' => '1'], '', null, null);
            $status = 405;
        } else {
            $this->ping($request, $body);
        }

        $this->assertSame($status, $this->responder->single()['status']);
        $this->assertSame([], $this->ai->calls);
        $this->assertSame([], $this->client->calls);
    }

    /**
     * Refused pings.
     *
     * @return array
     */
    public static function refused_ping_provider(): array {
        return [
            'unsigned' => ['unsigned'],
            'tampered' => ['tampered'],
            'app secret' => ['app secret'],
            'too old' => ['too old'],
            'malformed chat' => ['malformed chat'],
            'not json' => ['not json'],
            'get' => ['get'],
        ];
    }

    /**
     * A request with a signed JSON body.
     *
     * @param array $data Body data.
     * @return array
     */
    private static function signed(array $data): array {
        return self::signed_body(json_encode($data));
    }

    /**
     * A request with a signed body.
     *
     * @param string $body Body.
     * @return array
     */
    private static function signed_body(string $body): array {
        return [
            'url' => 'https://www.example.com/moodle/local/jedomi/whatsapp_webhook.php?internal=1',
            'body' => $body,
            'headers' => [internal_ping::SIGNATURE_HEADER . ': ' . internal_ping::sign($body)],
        ];
    }
}
