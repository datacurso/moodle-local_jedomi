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

use local_jedomi\agent\tool\document_read_tool;
use local_jedomi\ai\ai_service;
use local_jedomi\ai\custom_api;
use local_jedomi\ai\fake_curl;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_curl.php');

/**
 * Tests for audio, speech, images and documents with the custom AI service.
 *
 * Needs only core: nothing goes through the Datacurso provider.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\audio_service
 * @covers     \local_jedomi\local\service\speech_service
 * @covers     \local_jedomi\local\service\vision_service
 * @covers     \local_jedomi\local\service\file_extraction_service
 * @covers     \local_jedomi\agent\tool\document_read_tool
 */
final class custom_media_test extends \advanced_testcase {
    /** @var fake_curl Transport double of the custom service. */
    private fake_curl $curl;

    /** @var \stdClass User of the tests. */
    private \stdClass $user;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $this->curl = new fake_curl();
        custom_api::set_curl_factory_for_testing(fn(bool $bypass): \curl => $this->curl);
        $settings = [
            'ai_service' => ai_service::CUSTOM,
            'custom_gateway_url' => 'https://api.example.com',
            'custom_api_key' => 'sk-test',
            'custom_model' => 'chat-model',
            'custom_vision_model' => 'vision-model',
            'custom_audio_model' => 'whisper-model',
            'custom_speech_model' => 'tts-model',
            'custom_speech_voice' => 'af_bella',
        ];
        foreach ($settings as $name => $value) {
            set_config($name, $value, 'local_jedomi');
        }
        $this->user = $this->getDataGenerator()->create_user(['lang' => 'en']);
        $this->setUser($this->user);
    }

    #[\Override]
    protected function tearDown(): void {
        custom_api::set_curl_factory_for_testing(null);
        parent::tearDown();
    }

    /**
     * Write a temporary file.
     *
     * @param string $name File name.
     * @param string $content Content.
     * @return string Path.
     */
    private function file(string $name, string $content): string {
        $path = make_request_directory() . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Audio is transcribed by the custom audio model at {url}/v1/audio/transcriptions.
     *
     * @return void
     */
    public function test_audio_is_transcribed_by_the_custom_service(): void {
        $this->curl->enqueue(json_encode(['text' => ' Hello there ']));

        $result = audio_service::transcribe_upload(
            (int) $this->user->id,
            $this->file('a.webm', str_repeat('x', 4096)),
            'a.webm'
        );

        $this->assertSame('Hello there', $result['text']);
        $this->assertSame('whisper-model', $result['model']);
        $call = $this->curl->calls[0];
        $this->assertSame('https://api.example.com/v1/audio/transcriptions', $call['url']);
        $this->assertSame('whisper-model', $call['params']['model']);
        $this->assertSame('en', $call['params']['language']);
    }

    /**
     * Speech is read by the custom speech model and voice, from the anonymised text.
     *
     * @return void
     */
    public function test_speech_is_read_by_the_custom_service(): void {
        $other = $this->getDataGenerator()->create_user(['firstname' => 'Zebulon', 'lastname' => 'Quixwell']);
        $this->curl->enqueue("ID3audio");

        $audio = speech_service::synthesise((int) $this->user->id, 'Write to Zebulon Quixwell at ' . $other->email);

        $this->assertSame('ID3audio', $audio);
        $call = $this->curl->calls[0];
        $this->assertSame('https://api.example.com/v1/audio/speech', $call['url']);
        $body = json_decode($call['params'], true);
        $this->assertSame('tts-model', $body['model']);
        $this->assertSame('af_bella', $body['voice']);
        $this->assertStringNotContainsString('Quixwell', $body['input']);
        $this->assertStringNotContainsString($other->email, $body['input']);
        $this->assertArrayNotHasKey('userid', $body);
    }

    /**
     * An image is sent as image_url content to the custom vision model, which answers its text.
     *
     * @return void
     */
    public function test_image_is_read_by_the_custom_vision_model(): void {
        $this->curl->enqueue(json_encode(['choices' => [['message' => ['content' => 'A cat'], 'finish_reason' => 'stop']]]));

        $result = vision_service::read_upload((int) $this->user->id, $this->file('cat.png', 'PNGDATA'), 'cat.png', 'What?');

        $this->assertSame('A cat', $result['text']);
        $this->assertSame('vision-model', $result['model']);
        $call = $this->curl->calls[0];
        $this->assertSame('https://api.example.com/v1/chat/completions', $call['url']);
        $body = json_decode($call['params'], true);
        $this->assertSame('vision-model', $body['model']);
        $parts = $body['messages'][0]['content'];
        $this->assertSame('text', $parts[0]['type']);
        $this->assertStringContainsString('What?', $parts[0]['text']);
        $this->assertSame(
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode('PNGDATA')]],
            $parts[1]
        );
    }

    /**
     * Documents (PDF, Word, text) cannot be read with the custom service, and the user is told why.
     *
     * @return void
     */
    public function test_documents_are_refused(): void {
        $userid = (int) $this->user->id;
        $message = get_string('documents_error_custom', 'local_jedomi');

        try {
            vision_service::read_upload($userid, $this->file('doc.pdf', '%PDF-1.4'), 'doc.pdf');
            $this->fail('A PDF must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('documents_error_custom', $e->errorcode);
            $this->assertSame($message, vision_service::error_for($e)['message']);
        }

        try {
            file_extraction_service::extract_upload($userid, $this->file('notes.docx', 'PK'), 'notes.docx');
            $this->fail('A document must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('documents_error_custom', $e->errorcode);
            $this->assertSame($message, file_extraction_service::error_for($e)['message']);
        }

        $this->assertFalse(document_read_tool::is_offered('admin'));
        $this->assertSame(['error' => $message], json_decode(document_read_tool::read(1, 'admin'), true));
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * An image that is too large for the custom service is refused before it is sent.
     *
     * @return void
     */
    public function test_large_image_is_refused(): void {
        $path = $this->file('big.png', str_repeat('x', vision_service::MAX_CUSTOM_IMAGE_BYTES + 1));

        try {
            vision_service::read_upload((int) $this->user->id, $path, 'big.png');
            $this->fail('A large image must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('file_error_too_large', $e->errorcode);
            $this->assertSame(get_string('file_error_too_large', 'local_jedomi'), vision_service::error_for($e)['message']);
        }
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * HTTP errors of the custom service keep the messages of each status (413, 415, 422).
     *
     * @return void
     */
    public function test_status_errors_keep_their_messages(): void {
        $this->curl->enqueue('{"error":"too long"}', 413);

        try {
            audio_service::transcribe_upload((int) $this->user->id, $this->file('a.webm', str_repeat('x', 4096)), 'a.webm');
            $this->fail('The error must be raised');
        } catch (\moodle_exception $e) {
            $this->assertSame(413, provider_upload_service::http_status($e));
            $this->assertSame(get_string('audio_error_too_long', 'local_jedomi'), audio_service::error_for($e)['message']);
        }
    }
}
