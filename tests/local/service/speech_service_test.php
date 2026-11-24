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
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\speech_service
 * @covers     \local_jedomi\ai\provider_api
 * @covers     \local_jedomi\local\anonymizer
 */
final class speech_service_test extends \advanced_testcase {
    use \local_jedomi\tests\spanish_strings;

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
        // The valid values are only built when not overridden: a guest or a request without a session has none.
        $input = $overrides + ['text' => 'Hello, I am Jedomi.'];
        $input += ['sesskey' => array_key_exists('sesskey', $overrides) ? '' : sesskey()];
        if (!array_key_exists('spt', $input)) {
            $input['spt'] = \local_jedomi_generate_spt(\local_jedomi_get_user_role());
        }
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
     * MDL-INT-110 / MDL-CTR-012: the speech goes through the provider with the configured model and voice and the audio comes back
     * unmodified.
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
        $this->assertSame('Rafael', $body['voice']);
        $this->assertSame('Hello, I am Jedomi.', $body['input']);
        $this->assertArrayHasKey('userid', $body);
        $this->assertArrayHasKey('site_id', $body);
    }

    /**
     * MDL-INT-110 / MDL-CTR-012: the request carries the configured model and voice and the text to read.
     * V8: the model and voice chosen in the settings are the ones sent.
     *
     * @return void
     */
    public function test_speech_uses_the_configured_model_and_voice(): void {
        $this->setup_user();
        set_config('speech_model', 'jedomi/Qwen/Qwen3-TTS', 'local_jedomi');
        set_config('speech_voice', 'Serena', 'local_jedomi');
        $this->curl->enqueue(self::MP3);

        $response = $this->request();

        $this->assertSame(200, $response['status']);
        $this->assertSame('jedomi/Qwen/Qwen3-TTS', $this->curl->last_body()['model']);
        $this->assertSame('Serena', $this->curl->last_body()['voice']);
    }

    /**
     * MDL-INT-110 / MDL-INT-092: guests and requests without a session are refused without calling the AI.
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
     * MDL-INT-110: a request without a valid session key is refused without calling the AI.
     * V7: a missing or wrong session key is refused without calling the AI.
     *
     * @return void
     */
    public function test_bad_sesskey_is_refused(): void {
        $this->setup_user();

        $this->assert_refused_without_a_call($this->request(['sesskey' => 'wrong']), 403, 'invalid_sesskey');
    }

    /**
     * MDL-INT-116: a request without a session key is refused with a JSON error, without calling the AI.
     *
     * @return void
     */
    public function test_missing_sesskey_is_refused_with_a_json_error(): void {
        $this->setup_user();

        try {
            $response = $this->request(['sesskey' => '']);
        } catch (\moodle_exception $e) {
            $this->fail('A request without a session key must get the JSON refusal, not the exception ' . $e->errorcode);
        }
        $this->assert_refused_without_a_call($response, 403, 'invalid_sesskey');
    }

    /**
     * MDL-INT-110: invalid or expired chat permissions are refused without calling the AI.
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
     * MDL-INT-110 / MDL-INT-095: the course signed in the permissions is checked again on every speech request: a user
     * who lost access to it (unenrolled) is refused while the permissions are still valid, without calling the AI.
     *
     * @return void
     */
    public function test_user_who_lost_access_to_the_signed_course_is_refused(): void {
        global $DB, $PAGE;
        $user = $this->setup_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $spt = \local_jedomi_generate_spt(\local_jedomi_get_user_role());
        $this->assertSame((int) $course->id, (int) $spt['course_id'], 'Precondition: permissions signed for the course');

        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->unenrol_user($instance, (int) $user->id);

        $this->assert_refused_without_a_call($this->request(['spt' => $spt]), 403, 'course_access_denied');
    }

    /**
     * MDL-INT-110: with Jedomi disabled the speech is refused without calling the AI.
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
     * MDL-INT-110 / MDL-INT-006: with the voice conversation or audio off the speech is refused with the voice not enabled message,
     * without calling the AI.
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
     * MDL-INT-110: an empty text and one above the maximum are refused, covering the exact limit and one above.
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
     * MDL-INT-110: above the per-minute limit the speech is refused, covering the request at the limit and the next
     * one. V7: above the per-minute limit of the user the request is refused without calling the AI.
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
     * MDL-INT-110: another user is not affected by the limit of a user.
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
     * Restore the lock factory of the rate limit after each test.
     *
     * @return void
     */
    protected function tearDown(): void {
        speech_service::set_lock_factory_for_testing(null);
        parent::tearDown();
    }

    /**
     * Lock factory double that grants every lock and records the resources locked and released.
     *
     * @param array $log Resources locked ("lock <resource>") and released ("release <resource>"), in order.
     * @return \core\lock\lock_factory
     */
    private function recording_lock_factory(array &$log): \core\lock\lock_factory {
        $factory = $this->createMock(\core\lock\lock_factory::class);
        $factory->method('get_lock')->willReturnCallback(function (string $resource) use (&$log, $factory) {
            $log[] = 'lock ' . $resource;
            return new \core\lock\lock($resource, $factory);
        });
        $factory->method('release_lock')->willReturnCallback(function (\core\lock\lock $lock) use (&$log) {
            $log[] = 'release ' . $lock->get_key();
            return true;
        });
        return $factory;
    }

    /**
     * MDL-INT-110: the per-minute counter of a user is read and written under a lock of that user, so parallel requests
     * (the session lock is released) cannot read the same count; sequential requests still stop at the limit.
     *
     * @return void
     */
    public function test_rate_limit_counter_is_locked_per_user(): void {
        $user = $this->setup_user();
        $log = [];
        speech_service::set_lock_factory_for_testing($this->recording_lock_factory($log));

        for ($i = 0; $i < speech_service::RATE_LIMIT_PER_MINUTE; $i++) {
            $this->curl->enqueue(self::MP3);
            $this->assertSame(200, $this->request()['status'], "Request {$i} is within the limit");
        }
        $calls = count($this->curl->calls);
        $response = $this->request();

        $this->assert_refused_status($response, 429, 'speech_rate_limited');
        $this->assertCount($calls, $this->curl->calls, 'The AI service must not be called above the limit');
        $resource = 'speech_rate_' . $user->id;
        $expected = array_merge(...array_fill(0, speech_service::RATE_LIMIT_PER_MINUTE + 1, ["lock {$resource}",
            "release {$resource}"]));
        $this->assertSame($expected, $log, 'Each request takes the lock of the user and releases it');
    }

    /**
     * MDL-INT-110: when the lock of the counter cannot be taken the request is refused as rate limited, never let
     * through.
     *
     * @return void
     */
    public function test_rate_limit_refuses_when_the_lock_is_not_available(): void {
        $this->setup_user();
        $factory = $this->createMock(\core\lock\lock_factory::class);
        $factory->method('get_lock')->willReturn(false);
        speech_service::set_lock_factory_for_testing($factory);

        $this->assert_refused_without_a_call($this->request(), 429, 'speech_rate_limited');
    }

    /**
     * MDL-INT-110: a failed login check of speech.php (require_login) answers the JSON error the sidebar reads, never
     * an HTML page.
     *
     * @return void
     */
    public function test_login_failure_answers_a_json_refusal(): void {
        $this->setup_user();

        $response = speech_service::login_error(new \require_login_exception('test'));

        $this->assertSame(403, $response['status']);
        $this->assertStringStartsWith('application/json', $response['contenttype']);
        $error = json_decode($response['body'], true)['error'];
        $this->assertSame('requireloginerror', $error['code']);
        $this->assertNotEmpty($error['message']);
    }

    /**
     * Assert a response is a JSON error with a status and a code.
     *
     * @param array $response Response of the request.
     * @param int $status Expected HTTP status.
     * @param string $code Expected error code.
     * @return void
     */
    private function assert_refused_status(array $response, int $status, string $code): void {
        $this->assertSame($status, $response['status']);
        $this->assertSame($code, json_decode($response['body'], true)['error']['code']);
    }

    /**
     * MDL-CTR-012 / MDL-INT-003: an error of the provider reaches the browser as an interpretable error.
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
        // The provider reports the HTTP error with debugging(); the answer to the browser is what is checked.
        $this->resetDebugging();
    }

    /**
     * MDL-CTR-012 / MDL-INT-001: the speech goes through the provider with its licence.
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
     * MDL-CTR-012: the no credit error of the provider reaches the browser as an interpretable error.
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
     * MDL-CTR-012: an answer without audio is reported as an error, never handed out as audio.
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

    /**
     * Create a site user whose name and e-mail can appear in a spoken answer.
     *
     * @return \stdClass
     */
    private function create_named_user(): \stdClass {
        return $this->getDataGenerator()->create_user([
            'firstname' => 'Marisol',
            'lastname' => 'Quintanilla',
            'email' => 'marisol.q@example.com',
        ]);
    }

    /**
     * MDL-INT-117: the text read aloud is anonymised with the level of the Privacy tab before it leaves the site, and
     * the placeholders are spoken as plain words. Basic level: the name and e-mail of a site user never reach the AI
     * service; the grade is kept.
     *
     * @return void
     */
    public function test_speech_text_is_anonymised_at_the_basic_level(): void {
        $this->setup_user();
        $this->create_named_user();
        set_config('anonymization_level', 'basic', 'local_jedomi');
        $this->curl->enqueue(self::MP3);

        $response = $this->request(['text' => 'Marisol Quintanilla (marisol.q@example.com) got 8.5 in the quiz.']);

        $this->assertSame(200, $response['status']);
        $input = $this->curl->last_body()['input'];
        $this->assertStringNotContainsString('Marisol', $input);
        $this->assertStringNotContainsString('Quintanilla', $input);
        $this->assertStringNotContainsString('marisol.q@example.com', $input);
        $this->assertStringNotContainsString('[', $input, 'Placeholders are never read as raw tokens');
        $this->assertSame(
            get_string('speech_placeholder_person', 'local_jedomi') . ' ('
                . get_string('speech_placeholder_email', 'local_jedomi') . ') got 8.5 in the quiz.',
            $input
        );
    }

    /**
     * MDL-INT-117: Strict level: names, e-mails and codes such as student numbers are spoken as plain words.
     *
     * @return void
     */
    public function test_speech_text_is_anonymised_at_the_strict_level(): void {
        $this->setup_user();
        $this->create_named_user();
        set_config('anonymization_level', 'strict', 'local_jedomi');
        $this->curl->enqueue(self::MP3);

        $this->request(['text' => 'Quintanilla has the student number A12345 and wrote to marisol.q@example.com.']);

        $this->assertSame(
            get_string('speech_placeholder_person', 'local_jedomi') . ' has the student number '
                . get_string('speech_placeholder_id', 'local_jedomi') . ' and wrote to '
                . get_string('speech_placeholder_email', 'local_jedomi') . '.',
            $this->curl->last_body()['input']
        );
    }

    /**
     * MDL-INT-117: Feedback only level: what is sent to the AI service is anonymised as with the Basic level.
     *
     * @return void
     */
    public function test_speech_text_is_anonymised_at_the_feedback_level(): void {
        $this->setup_user();
        $this->create_named_user();
        set_config('anonymization_level', 'feedback', 'local_jedomi');
        $this->curl->enqueue(self::MP3);

        $this->request(['text' => 'Marisol wrote to marisol.q@example.com.']);

        $this->assertSame(
            get_string('speech_placeholder_person', 'local_jedomi') . ' wrote to '
                . get_string('speech_placeholder_email', 'local_jedomi') . '.',
            $this->curl->last_body()['input']
        );
    }

    /**
     * MDL-INT-117: A text without personal data is read exactly as written at every level.
     *
     * @return void
     */
    public function test_text_without_personal_data_is_sent_unchanged(): void {
        $this->setup_user();
        $this->create_named_user();
        foreach (['basic', 'strict', 'feedback'] as $level) {
            set_config('anonymization_level', $level, 'local_jedomi');
            $this->curl->enqueue(self::MP3);

            $this->request(['text' => 'The average grade of the course is 7.5 out of 10.']);

            $this->assertSame('The average grade of the course is 7.5 out of 10.', $this->curl->last_body()['input'], $level);
        }
    }

    /**
     * MDL-INT-117: Only the spoken text is anonymised: the answer shown in the chat still gets the real values back.
     *
     * @return void
     */
    public function test_chat_answer_keeps_the_real_values(): void {
        $this->setup_user();
        $this->create_named_user();
        set_config('anonymization_level', 'basic', 'local_jedomi');
        // As the chat proxy does: the AI sees the tool result anonymised and its answer is restored for display.
        $anonymizer = new \local_jedomi\local\anonymizer();
        $toolresult = json_decode($anonymizer->anonymize_tool_result(json_encode([
            'firstname' => 'Marisol', 'email' => 'marisol.q@example.com', 'grade' => '8.5',
        ])), true);
        $shown = $anonymizer->restore("{$toolresult['firstname']} ({$toolresult['email']}) got {$toolresult['grade']}.");
        $this->assertSame('Marisol (marisol.q@example.com) got 8.5.', $shown);

        $spoken = speech_service::anonymise_for_speech($shown);

        $this->assertSame('Marisol (marisol.q@example.com) got 8.5.', $shown, 'The displayed answer is unchanged');
        $this->assertStringNotContainsString('Marisol', $spoken);
        $this->assertStringNotContainsString('marisol.q@example.com', $spoken);
    }

    /**
     * MDL-UNIT-116: each reason a reading aloud is refused (per minute limit, text too long, voice disabled, expired
     * permissions, no credit) is shown to the user in their language.
     *
     * @return void
     */
    public function test_user_sees_the_real_reason_of_a_refused_reading(): void {
        $this->setup_user();
        $message = fn(array $response): string => (string) json_decode($response['body'], true)['error']['message'];

        // Each refusal answers its own message, ready to show.
        $toolong = $this->request(['text' => str_repeat('a', speech_service::MAX_CHARS + 1)]);
        $this->assertSame(get_string('speech_error_too_long', 'local_jedomi', speech_service::MAX_CHARS), $message($toolong));
        $this->assertSame(get_string('invalid_session', 'local_jedomi'), $message($this->request(['spt' => 'expired'])));
        $this->curl->enqueue(json_encode(['detail' => 'tokens_not_sufficient', 'current_balance' => 0]), 403);
        $nocredit = $message($this->request());
        $this->assertNotSame(get_string('voice_mode_speech_error', 'local_jedomi'), $nocredit);
        $this->assertNotSame('', $nocredit);
        $this->resetDebugging();
        for ($i = 0; $i <= speech_service::RATE_LIMIT_PER_MINUTE; $i++) {
            $this->curl->enqueue(self::MP3);
            $response = $this->request();
        }
        $this->assertSame(get_string('speech_error_rate_limited', 'local_jedomi'), $message($response));
        set_config('voice_enabled', 0, 'local_jedomi');
        $this->assertSame(get_string('speech_error_disabled', 'local_jedomi'), $message($this->request()));

        // The sidebar shows the message of the refusal, or the string of its code, not the generic notice.
        $source = file_get_contents(__DIR__ . '/../../../amd/src/local/sidebar/voice_playback.js')
            . file_get_contents(__DIR__ . '/../../../amd/src/local/sidebar/voice_text.js');
        $this->assertStringContainsString("error.reason = data.error?.message || '';", $source);
        $this->assertStringContainsString('${voiceSpeechErrorReason(err)}', $source, 'The notice shows the reason');
        $codes = [
            'voice_disabled' => 'speech_error_disabled',
            'speech_empty' => 'speech_error_empty',
            'speech_rate_limited' => 'speech_error_rate_limited',
            'speech_too_long' => 'speech_error_too_long',
        ];
        foreach ($codes as $code => $key) {
            $this->assertStringContainsString("'{$code}': '{$key}',", $source, "{$code} has its string in the sidebar");
            $this->assertNotSame(
                get_string_manager()->get_string($key, 'local_jedomi', 5, 'en'),
                self::plugin_spanish_string($key, 5),
                "{$key} is translated"
            );
        }
    }
}
