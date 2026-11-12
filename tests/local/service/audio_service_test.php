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

use local_jedomi\ai\fake_curl;
use local_jedomi\ai\model_settings;
use local_jedomi\ai\testable_provider_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for the transcription of audio with the audio model (M9, M10).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\audio_service
 */
final class audio_service_test extends \advanced_testcase {
    /**
     * Create a user, enable the provider and audio processing, and return the user id.
     *
     * @return int
     */
    private function setup_user(): int {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the provider client cannot be built.');
        }
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        set_config('audio_enabled', 1, 'local_jedomi');
        return (int) $user->id;
    }

    /**
     * Write a temporary audio file.
     *
     * @return string Path.
     */
    private function upload(): string {
        $path = make_request_directory() . '/upload.tmp';
        file_put_contents($path, 'OggS fake audio');
        return $path;
    }

    /**
     * MDL-UNIT-113: the recording of the voice conversation is transcribed with the audio model.
     * M10: the audio goes to audio/transcriptions with the audio model and its text comes back.
     * MDL-INT-053: the audio is transcribed with the configured audio model.
     */
    public function test_audio_is_transcribed_with_the_audio_model(): void {
        global $DB;
        $userid = $this->setup_user();
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['text' => ' How many users? ', 'model' => model_settings::DEFAULT_AUDIO_MODEL,
            'duration_seconds' => 4.5]));

        $result = audio_service::transcribe_upload($userid, $this->upload(), 'voice.ogg', new testable_provider_api(30, $curl));

        $this->assertSame(['text' => 'How many users?', 'model' => model_settings::DEFAULT_AUDIO_MODEL,
            'duration_seconds' => 4.5], $result);
        $this->assertStringEndsWith('/jedomi/v1/audio/transcriptions', $curl->calls[0]['url']);
        $this->assertSame(model_settings::DEFAULT_AUDIO_MODEL, $curl->calls[0]['params']['model']);
        $this->assertSame('voice.ogg', $curl->calls[0]['params']['file']->getPostFilename());
        $this->assertFalse($DB->record_exists('files', ['component' => 'user', 'filearea' => 'draft', 'filename' => 'voice.ogg']));
    }

    /**
     * MDL-UNIT-113: the recording is transcribed in the language of the user.
     * Outside a page of the user (a Telegram voice message, a scheduled task), the language of the user's profile goes
     * with the audio, as a two-letter code, so the model does not guess it.
     *
     * @covers \local_jedomi\local\service\audio_service::language_code
     */
    public function test_audio_is_sent_with_the_language_of_the_user(): void {
        global $DB;
        $userid = $this->setup_user();
        $DB->set_field('user', 'lang', 'es_mx', ['id' => $userid]);
        // A Telegram update has no session of the user.
        $this->setUser(null);
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['text' => 'Hola', 'model' => model_settings::DEFAULT_AUDIO_MODEL, 'duration_seconds' => 1]));

        audio_service::transcribe_upload($userid, $this->upload(), 'voice.ogg', new testable_provider_api(30, $curl));

        $this->assertSame('es', $curl->calls[0]['params']['language']);
        $this->assertSame('es', audio_service::language_code($userid));
        // Without a user the current language is used, still as two letters (or nothing).
        $this->assertMatchesRegularExpression('/^([a-z]{2})?$/', audio_service::language_code(0));
    }

    /**
     * On a page of the user the language of the page is used: a language forced by the course, or one chosen for the
     * session, wins over the language of the profile, still as two letters.
     *
     * @covers \local_jedomi\local\service\audio_service::language_code
     */
    public function test_language_of_the_page_wins_on_a_page_of_the_user(): void {
        global $PAGE, $SESSION, $USER;
        $userid = $this->setup_user();
        $USER->lang = 'es_mx';

        $this->assertSame('es', audio_service::language_code($userid), 'Without another choice the profile language is used');

        $SESSION->lang = 'en';
        $this->assertSame('en', audio_service::language_code($userid), 'A language chosen for the session wins');
        unset($SESSION->lang);

        $PAGE->set_course($this->getDataGenerator()->create_course(['lang' => 'en']));
        $this->assertSame('en', audio_service::language_code($userid), 'A language forced by the course wins');
    }

    /**
     * MDL-INT-006: with audio processing off audio is rejected without calling the AI.
     * M9: with audio processing off nothing is sent and the "not enabled" message is returned.
     * MDL-INT-053: with audio processing off the audio is rejected without calling the AI service.
     */
    public function test_disabled_audio_is_rejected_without_a_call(): void {
        $userid = $this->setup_user();
        set_config('audio_enabled', 0, 'local_jedomi');
        $curl = new fake_curl();

        try {
            audio_service::transcribe_upload($userid, $this->upload(), 'voice.ogg', new testable_provider_api(30, $curl));
            $this->fail('Disabled audio processing must stop the transcription');
        } catch (\moodle_exception $e) {
            $this->assertSame(
                ['code' => 'audio_disabled', 'message' => get_string('audio_error_disabled', 'local_jedomi')],
                audio_service::error_for($e)
            );
        }
        $this->assertSame([], $curl->calls);
    }

    /**
     * M9: a format the service does not transcribe is rejected without a call.
     * MDL-INT-053: an unsupported format is rejected without calling the AI service.
     */
    public function test_unsupported_format_is_rejected_without_a_call(): void {
        $userid = $this->setup_user();
        $curl = new fake_curl();

        try {
            audio_service::transcribe_upload($userid, $this->upload(), 'voice.flac', new testable_provider_api(30, $curl));
            $this->fail('An unsupported format must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('audio_unsupported', audio_service::error_for($e)['code']);
        }
        $this->assertSame([], $curl->calls);
    }

    /**
     * MDL-UNIT-115: a transcription that fails gives its reason to show.
     * M10: an empty transcription is reported as an audio that could not be understood.
     * MDL-INT-053: an empty transcription is treated as audio not understood.
     */
    public function test_empty_transcription_is_unreadable(): void {
        $userid = $this->setup_user();
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['text' => '  ', 'model' => 'm', 'duration_seconds' => 2]));

        try {
            audio_service::transcribe_upload($userid, $this->upload(), 'voice.webm', new testable_provider_api(30, $curl));
            $this->fail('An empty transcription must be reported');
        } catch (\moodle_exception $e) {
            $this->assertSame('audio_unreadable', audio_service::error_for($e)['code']);
        }
    }

    /**
     * Status codes of audio/transcriptions and the message each one shows.
     *
     * @return array
     */
    public static function service_error_provider(): array {
        return [
            'too long or too large' => [413, 'audio_too_long', 'audio_error_too_long'],
            'unsupported' => [415, 'audio_unsupported', 'audio_error_unsupported'],
            'unreadable' => [422, 'audio_unreadable', 'audio_error_unreadable'],
            'not configured' => [503, 'unavailable', 'ai_error_unavailable'],
        ];
    }

    /**
     * MDL-UNIT-115: each failure of the transcription has its own reason to show.
     * M9: errors of audio/transcriptions get their own message.
     * MDL-INT-053: rejections of the service (too long, unsupported format) reach the user as a controlled answer.
     * API-INT-002: a transcription the service rejects reaches the user as a controlled answer.
     *
     * @dataProvider service_error_provider
     * @param int $status HTTP status from plugins-ai.
     * @param string $code Expected code.
     * @param string $stringkey Expected message string.
     */
    public function test_service_errors_have_their_message(int $status, string $code, string $stringkey): void {
        $userid = $this->setup_user();
        $curl = new fake_curl();
        $body = json_encode(['detail' => ['code' => 'x']]);
        $curl->enqueue($body, $status);

        try {
            audio_service::transcribe_upload($userid, $this->upload(), 'voice.mp3', new testable_provider_api(30, $curl));
            $this->fail('A service error must be raised');
        } catch (\moodle_exception $e) {
            $error = audio_service::error_for($e);
            $this->assertSame($code, $error['code']);
            $this->assertSame(get_string($stringkey, 'local_jedomi'), $error['message']);
        }
        $this->assertDebuggingCalled("HTTP error {$status} from Datacurso API (" . strlen($body) . ' bytes)');
    }

    /**
     * M9: no credit is reported with the shared message.
     * MDL-INT-053: lack of credits reaches the user as a controlled answer.
     * API-INT-002: when the service reports no credits left, the user gets the shared message and nothing is retried.
     */
    public function test_no_credit_uses_the_shared_message(): void {
        $error = new \moodle_exception('notenoughtokens', 'aiprovider_datacurso');

        $this->assertSame('no_credit', audio_service::error_for($error)['code']);
    }
}
