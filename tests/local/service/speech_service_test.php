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

namespace local_jedomi\local\service;

use local_jedomi\ai\error_message;
use local_jedomi\ai\fake_curl;
use local_jedomi\ai\model_settings;
use local_jedomi\ai\testable_provider_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for the spoken answers of the voice conversation (speech.php and its service): V7 and V8.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\speech_service
 * @covers     \local_jedomi\ai\provider_api
 */
final class speech_service_test extends \advanced_testcase {
    /** @var string Body of a fake MP3 answer, with bytes that are not valid UTF-8. */
    private const MP3 = "ID3\x04\x00\x00\x00\x00\x00\x00\xff\xfb\x90\x64 fake mp3 frames";

    /** @var fake_curl Transport double: no network request is made. */
    private fake_curl $curl;

    /**
     * Create a user with the provider, audio processing and the voice conversation enabled, and log in as that user.
     *
     * @return \stdClass
     */
    private function setup_user(): \stdClass {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the provider client cannot be built.');
        }
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        set_config('audio_enabled', 1, 'local_jedomi');
        set_config('voice_enabled', 1, 'local_jedomi');
        $this->curl = new fake_curl();
        return $user;
    }

    /**
     * Send a speech request as speech.php does, with a valid session key and permissions unless overridden.
     *
     * @param array $overrides Request fields replacing the valid ones.
     * @return array Keys status, contenttype and body.
     */
    private function request(array $overrides = []): array {
        $input = array_merge([
            'text' => 'Hello, I am Jedomi.',
            'sesskey' => sesskey(),
            'spt' => \local_jedomi_generate_spt(\local_jedomi_get_user_role()),
        ], $overrides);
        return speech_service::handle_request($input, new testable_provider_api(30, $this->curl));
    }

    /**
     * Assert a request was refused with a JSON error and the AI service was never called.
     *
     * @param array $response Response of the request.
     * @param int $status Expected HTTP status.
     * @param string $code Expected error code.
     * @return void
     */
    private function assert_refused_without_a_call(array $response, int $status, string $code): void {
        $this->assertSame($status, $response['status']);
        $this->assertStringStartsWith('application/json', $response['contenttype']);
        $error = json_decode($response['body'], true)['error'] ?? [];
        $this->assertSame($code, $error['code'] ?? null);
        $this->assertNotEmpty($error['message'] ?? '');
        $this->assertSame([], $this->curl->calls, 'The AI service must not be called');
    }

    /**
     * V8: with the voice conversation on, the text goes to audio/speech with the configured model and voice and the
     * MP3 of the provider comes back as is.
     *
     * @return void
     */
    public function test_speech_returns_the_audio_of_the_provider(): void {
        $this->setup_user();
        $this->curl->enqueue(self::MP3);

        $response = $this->request(['text' => "  Hello, I am Jedomi.\n"]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('audio/mpeg', $response['contenttype']);
        $this->assertSame(self::MP3, $response['body']);
        $this->assertCount(1, $this->curl->calls);
        $this->assertStringEndsWith('/jedomi/v1/audio/speech', $this->curl->calls[0]['url']);
        $body = $this->curl->last_body();
        $this->assertSame(model_settings::DEFAULT_SPEECH_MODEL, $body['model']);
        $this->assertSame('Vivian', $body['voice']);
        $this->assertSame('Hello, I am Jedomi.', $body['input']);
        $this->assertArrayHasKey('userid', $body);
        $this->assertArrayHasKey('site_id', $body);
    }

    /**
     * V8: the model and voice chosen in the settings are the ones sent.
     *
     * @return void
     */
    public function test_speech_uses_the_configured_model_and_voice(): void {
        $this->setup_user();
        set_config('speech_model', 'jedomi/inworld-ai/inworld-tts-1.5-mini', 'local_jedomi');
        set_config('speech_voice', 'Rafael', 'local_jedomi');
        $this->curl->enqueue(self::MP3);

        $response = $this->request();

        $this->assertSame(200, $response['status']);
        $this->assertSame('jedomi/inworld-ai/inworld-tts-1.5-mini', $this->curl->last_body()['model']);
        $this->assertSame('Rafael', $this->curl->last_body()['voice']);
    }

    /**
     * V7: a guest or a request without a session is refused without calling the AI.
     *
     * @return void
     */
    public function test_guest_and_no_session_are_refused(): void {
        $this->setup_user();
        $spt = \local_jedomi_generate_spt('student');

        $this->setGuestUser();
        $this->assert_refused_without_a_call($this->request(['spt' => $spt]), 403, 'guest_not_allowed');

        $this->setUser(null);
        $this->assert_refused_without_a_call($this->request(['spt' => $spt]), 403, 'guest_not_allowed');
    }

    /**
     * V7: a missing or wrong session key is refused without calling the AI.
     *
     * @return void
     */
    public function test_bad_sesskey_is_refused(): void {
        $this->setup_user();

        $this->assert_refused_without_a_call($this->request(['sesskey' => 'wrong']), 403, 'invalid_sesskey');
        $this->assert_refused_without_a_call($this->request(['sesskey' => '']), 403, 'invalid_sesskey');
    }

    /**
     * V7: missing, altered or expired permissions are refused without calling the AI.
     *
     * @return void
     */
    public function test_bad_or_expired_permissions_are_refused(): void {
        $this->setup_user();
        $spt = \local_jedomi_generate_spt('student');

        $this->assert_refused_without_a_call($this->request(['spt' => null]), 403, 'invalid_spt');
        $this->assert_refused_without_a_call($this->request(['spt' => 'text']), 403, 'invalid_spt');
        $this->assert_refused_without_a_call($this->request(['spt' => ['role' => 'admin'] + $spt]), 403, 'invalid_spt');

        $expired = $spt;
        unset($expired['signature']);
        $expired['expires'] = time() - 1;
        ksort($expired);
        $expired['signature'] = hash_hmac('sha256', \local_jedomi_spt_signing_string($expired), \local_jedomi_get_spt_secret());
        $this->assert_refused_without_a_call($this->request(['spt' => $expired]), 403, 'invalid_spt');
    }

    /**
     * V7: with Jedomi disabled in the provider the request is refused without calling the AI.
     *
     * @return void
     */
    public function test_disabled_plugin_is_refused(): void {
        $this->setup_user();
        set_config('plugin_enabled_local_jedomi', 0, 'aiprovider_datacurso');

        $this->assert_refused_without_a_call($this->request(), 403, 'plugindisabled');
    }

    /**
     * V7: with the voice conversation off, or audio processing off, the request is refused without calling the AI.
     *
     * @return void
     */
    public function test_disabled_voice_is_refused(): void {
        $this->setup_user();

        set_config('voice_enabled', 0, 'local_jedomi');
        $this->assert_refused_without_a_call($this->request(), 403, 'voice_disabled');

        set_config('voice_enabled', 1, 'local_jedomi');
        set_config('audio_enabled', 0, 'local_jedomi');
        $this->assert_refused_without_a_call($this->request(), 403, 'voice_disabled');
    }

    /**
     * V7: an empty text or a text longer than the limit is refused without calling the AI; the limit counts characters.
     *
     * @return void
     */
    public function test_empty_or_too_long_text_is_refused(): void {
        $this->setup_user();

        $this->assert_refused_without_a_call($this->request(['text' => "  \n "]), 400, 'speech_empty');
        $this->assert_refused_without_a_call($this->request(['text' => ['not', 'text']]), 400, 'speech_empty');
        $this->assert_refused_without_a_call(
            $this->request(['text' => str_repeat('a', speech_service::MAX_CHARS + 1)]),
            413,
            'speech_too_long'
        );

        $this->curl->enqueue(self::MP3);
        $response = $this->request(['text' => ' ' . str_repeat('ñ', speech_service::MAX_CHARS) . ' ']);
        $this->assertSame(200, $response['status'], 'The limit counts characters after trimming, not bytes');
    }

    /**
     * V7: above the per-minute limit of the user the request is refused without calling the AI.
     *
     * @return void
     */
    public function test_rate_limit_is_refused_without_a_call(): void {
        $this->setup_user();
        for ($i = 0; $i < speech_service::RATE_LIMIT_PER_MINUTE; $i++) {
            $this->curl->enqueue(self::MP3);
            $this->assertSame(200, $this->request()['status'], "Request {$i} is within the limit");
        }
        $calls = count($this->curl->calls);

        $response = $this->request();

        $this->assertSame(429, $response['status']);
        $this->assertSame('speech_rate_limited', json_decode($response['body'], true)['error']['code']);
        $this->assertCount($calls, $this->curl->calls, 'The AI service must not be called above the limit');
    }

    /**
     * V7: the limit is per user: another user can still ask for speech.
     *
     * @return void
     */
    public function test_rate_limit_is_per_user(): void {
        $this->setup_user();
        for ($i = 0; $i < speech_service::RATE_LIMIT_PER_MINUTE; $i++) {
            $this->curl->enqueue(self::MP3);
            $this->request();
        }

        $this->setUser($this->getDataGenerator()->create_user());
        $this->curl->enqueue(self::MP3);
        $this->assertSame(200, $this->request()['status']);
    }

    /**
     * An error of the AI service answers a JSON error with a message ready to show, without internal details.
     *
     * @return void
     */
    public function test_service_error_answers_a_clean_json_error(): void {
        $this->setup_user();
        $this->curl->enqueue(json_encode(['code' => 'jedomi_invalid_voice', 'message' => 'internal detail']), 400);

        $response = $this->request();

        $this->assertSame(502, $response['status']);
        $this->assertStringStartsWith('application/json', $response['contenttype']);
        $error = json_decode($response['body'], true)['error'];
        $this->assertNotEmpty($error['code']);
        $this->assertNotEmpty($error['message']);
        $this->assertStringNotContainsString('internal detail', $response['body']);
    }

    /**
     * The audio is asked through the provider's raw request: same licence and JSON headers as a chat call, and
     * Jedomi's total timeout on the transport.
     *
     * @return void
     */
    public function test_speech_goes_through_the_provider_with_its_headers_and_timeout(): void {
        $this->setup_user();
        $this->curl->enqueue(self::MP3);

        $this->request();

        $headers = $this->curl->calls[0]['options']['CURLOPT_HTTPHEADER'];
        $this->assertContains('License-Key: test-license', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
        $this->assertSame(30, $this->curl->setoptions['CURLOPT_TIMEOUT']);
    }

    /**
     * A refusal of the AI service for lack of credit answers a clean JSON error with the no credit code.
     *
     * @return void
     */
    public function test_no_credit_answer_becomes_a_clean_json_error(): void {
        $this->setup_user();
        $this->curl->enqueue(json_encode(['detail' => 'tokens_not_sufficient', 'current_balance' => 0]), 403);

        $response = $this->request();

        $this->assertSame(502, $response['status']);
        $this->assertStringStartsWith('application/json', $response['contenttype']);
        $this->assertSame(error_message::CODE_NO_CREDIT, json_decode($response['body'], true)['error']['code']);
        $this->resetDebugging();
    }

    /**
     * An answer without audio (a JSON body with status 200) is reported as an invalid answer.
     *
     * @return void
     */
    public function test_answer_without_audio_is_an_error(): void {
        $this->setup_user();
        $this->curl->enqueue(json_encode(['ok' => true]));

        $response = $this->request();

        $this->assertSame(502, $response['status']);
        $this->assertArrayHasKey('error', json_decode($response['body'], true));
    }
}
