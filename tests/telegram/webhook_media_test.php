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

namespace local_jedomi\telegram;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\ai\fake_curl;
use local_jedomi\ai\testable_provider_api;
use local_jedomi\local\service\audio_service;
use local_jedomi\local\service\file_extraction_service;
use local_jedomi\local\service\provider_upload_service;
use local_jedomi\local\service\vision_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for photos, documents, voice messages and other messages without text in Telegram (M8-M11).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\webhook_handler
 */
final class webhook_media_test extends \advanced_testcase {
    /** @var string Linked chat id. */
    private const CHATID = '771000001';

    /** @var fake_ai_client Chat model double. */
    private fake_ai_client $ai;

    /** @var fake_curl Transport of the provider client used for files/read and audio/transcriptions. */
    private fake_curl $curl;

    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the provider client cannot be built.');
        }
        $this->resetAfterTest();
        // A forced setting, not set_config(): changing an existing row of config_plugins makes the reset after
        // the test reload the whole table, which took about ten seconds per test.
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
        // Precondition: the Telegram integration is turned on.
        set_config('telegram_enabled', 1, 'local_jedomi');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        $DB->insert_record('local_jedomi_telegram_links', (object) [
            'userid' => (int) get_admin()->id,
            'chatid' => self::CHATID,
            'username' => 'admin',
            'confirmed' => 1,
            'timecreated' => time(),
            'lastproactivesent' => 0,
        ]);
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
        $this->curl = new fake_curl();
        provider_upload_service::set_api_for_testing(new testable_provider_api(30, $this->curl));
        $this->telegram = new fake_telegram_api_client();
        // Audio files are at least as large as the smallest audio the audio model receives.
        $this->telegram->files = ['photo-big' => 'jpeg bytes', 'voice-1' => self::audio('OggS'), 'doc-1' => '%PDF bytes'];
        api_client::set_instance_for_testing($this->telegram);
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        api_client::set_instance_for_testing(null);
        provider_upload_service::set_api_for_testing(null);
        parent::tearDown();
    }

    /**
     * Send a message from the linked chat through the webhook entry point.
     *
     * @param array $fields Message fields besides the chat.
     * @return string[] Texts the bot sent.
     */
    private function receive(array $fields): array {
        $originallimit = (int) ini_get('max_execution_time');
        webhook_handler::handle(['message' => array_merge(['chat' => ['id' => self::CHATID]], $fields)]);
        set_time_limit($originallimit);
        return array_column($this->telegram->sent, 'text');
    }

    /**
     * Last user message the chat model received.
     *
     * @return mixed
     */
    private function last_user_content() {
        $messages = $this->ai->calls[0]['messages'];
        $users = array_values(array_filter($messages, static fn(array $m): bool => $m['role'] === 'user'));
        return end($users)['content'];
    }

    /**
     * Telegram photo sizes, smallest first.
     *
     * @return array
     */
    private function photo(): array {
        return [['file_id' => 'photo-small', 'width' => 90], ['file_id' => 'photo-big', 'width' => 1280]];
    }

    /**
     * MDL-INT-006: with file processing off the bot answers that photos and files are not enabled.
     * M8: with file processing off a photo or a document gets the "not enabled" answer and nothing is sent to the AI.
     */
    public function test_photo_and_document_are_refused_when_files_are_disabled(): void {
        set_config('files_enabled', 0, 'local_jedomi');

        $this->receive(['photo' => $this->photo(), 'caption' => 'What is this?']);
        $texts = $this->receive(['document' => ['file_id' => 'doc-1', 'file_name' => 'a.pdf', 'mime_type' => 'application/pdf']]);

        $expected = get_string('telegram_msg_files_disabled', 'local_jedomi');
        $this->assertSame([$expected, $expected], $texts);
        $this->assertSame([], $this->ai->calls);
        $this->assertSame([], $this->curl->calls);
        $this->assertSame([], $this->telegram->downloads);
    }

    /**
     * MDL-INT-006: with audio processing off the bot answers that voice messages are not enabled.
     * M9: with audio processing off a voice message gets the "not enabled" answer and nothing is sent to the AI.
     */
    public function test_voice_is_refused_when_audio_is_disabled(): void {
        $texts = $this->receive(['voice' => ['file_id' => 'voice-1', 'mime_type' => 'audio/ogg', 'duration' => 3]]);

        $this->assertSame([get_string('telegram_msg_audio_disabled', 'local_jedomi')], $texts);
        $this->assertSame([], $this->ai->calls);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * MDL-UNIT-080: a sticker is classified as not supported.
     * M10: a message without text, such as a sticker, asks for text and is not sent to the AI.
     */
    public function test_message_without_text_asks_for_text(): void {
        $texts = $this->receive(['sticker' => ['file_id' => 'sticker-1']]);

        $this->assertSame([get_string('telegram_msg_text_only', 'local_jedomi')], $texts);
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * MDL-UNIT-080: a voice message goes to the transcription. MDL-UNIT-102: a voice message gets the ogg format.
     * MDL-UNIT-049: a voice message is transcribed and its text reaches the chat model like a question.
     * MDL-INT-064: a voice message is transcribed, what was understood is returned and that text is answered.
     */
    public function test_voice_is_transcribed_echoed_and_answered(): void {
        set_config('audio_enabled', 1, 'local_jedomi');
        $this->curl->enqueue(json_encode(['text' => 'How many users are there?', 'model' => 'm', 'duration_seconds' => 3]));
        $this->ai->enqueue_text('There are 12 users.');

        $texts = $this->receive(['voice' => ['file_id' => 'voice-1', 'mime_type' => 'audio/ogg', 'duration' => 3]]);

        $this->assertStringEndsWith('/jedomi/v1/audio/transcriptions', $this->curl->calls[0]['url']);
        $this->assertStringEndsWith('.ogg', $this->curl->calls[0]['params']['file']->getPostFilename());
        $this->assertSame(get_string('telegram_msg_heard', 'local_jedomi', 'How many users are there?'), $texts[0]);
        $this->assertCount(1, $this->ai->calls);
        $this->assertSame('How many users are there?', $this->last_user_content());
        $this->assertStringContainsString('There are 12 users.', end($texts));
    }

    /**
     * M10: a voice message that cannot be understood gets its message and is not sent to the AI.
     * MDL-INT-064: an unintelligible audio is not sent to the AI.
     */
    public function test_unreadable_voice_is_not_sent_to_the_ai(): void {
        set_config('audio_enabled', 1, 'local_jedomi');
        $body = json_encode(['detail' => ['code' => 'jedomi_audio_unreadable']]);
        $this->curl->enqueue($body, 422);

        $texts = $this->receive(['voice' => ['file_id' => 'voice-1', 'mime_type' => 'audio/ogg', 'duration' => 3]]);

        $this->assertSame([get_string('audio_error_unreadable', 'local_jedomi')], $texts);
        $this->assertSame([], $this->ai->calls);
        $this->assertDebuggingCalled("HTTP error 422 from Datacurso API (" . strlen($body) . ' bytes)');
    }

    /**
     * MDL-UNIT-080: a photo goes to the vision model.
     * MDL-UNIT-049: a photo is read by the vision model with its caption as the question; the chat model gets only text.
     * MDL-INT-064: a photo is read with the vision model using the caption as the question.
     */
    public function test_photo_goes_through_the_vision_model(): void {
        $this->curl->enqueue(json_encode(['text' => 'A grade report for Math 50', 'model' => 'm', 'pages_total' => null,
            'pages_vision' => [], 'pages_skipped' => []]));
        $this->ai->enqueue_text('It is a grade report.');

        $texts = $this->receive(['photo' => $this->photo(), 'caption' => 'What is this?']);

        $this->assertSame(['photo-big'], $this->telegram->downloads);
        $this->assertStringEndsWith('/jedomi/v1/files/read', $this->curl->calls[0]['url']);
        $this->assertSame('What is this?', $this->curl->calls[0]['params']['question']);
        $this->assertSame('photo.jpg', $this->curl->calls[0]['params']['file']->getPostFilename());
        $this->assertSame(
            vision_service::compose_message('What is this?', 'photo.jpg', 'A grade report for Math 50'),
            $this->last_user_content()
        );
        $this->assertStringNotContainsString('image_url', json_encode($this->ai->calls[0]['messages']));
        $this->assertStringContainsString('It is a grade report.', end($texts));
    }

    /**
     * MDL-UNIT-080: a document goes to the document reading.
     * M8: a PDF document is read through files/read with its own name.
     * MDL-INT-064: a PDF is read with the reading service.
     */
    public function test_pdf_document_goes_through_the_vision_service(): void {
        $this->curl->enqueue(json_encode(['text' => 'Syllabus', 'model' => 'm', 'pages_total' => 2, 'pages_vision' => [],
            'pages_skipped' => []]));
        $this->ai->enqueue_text('Summary.');

        $this->receive(['document' => ['file_id' => 'doc-1', 'file_name' => 'syllabus.pdf', 'mime_type' => 'application/pdf']]);

        $this->assertSame('syllabus.pdf', $this->curl->calls[0]['params']['file']->getPostFilename());
        $this->assertArrayNotHasKey('question', $this->curl->calls[0]['params']);
        $this->assertSame(vision_service::compose_message('', 'syllabus.pdf', 'Syllabus'), $this->last_user_content());
    }

    /**
     * M8: a document type that cannot be read gets its message and nothing is sent.
     * MDL-INT-064: an unsupported document gets the notice of supported types.
     */
    public function test_unsupported_document_is_refused(): void {
        $texts = $this->receive(['document' => ['file_id' => 'doc-1', 'file_name' => 'a.xlsx', 'mime_type' => 'x']]);

        $this->assertSame([get_string('telegram_msg_file_unsupported', 'local_jedomi')], $texts);
        $this->assertSame([], $this->curl->calls);
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * A Word or PowerPoint document is read for free through files/extract, with its caption as the question.
     * MDL-INT-064: Word and PowerPoint documents are read without a model.
     */
    public function test_office_document_goes_through_files_extract(): void {
        $this->telegram->files['doc-2'] = 'PK office bytes';
        $this->curl->enqueue(json_encode(['text' => 'Unit 1 plan', 'format' => 'word', 'chars' => 11,
            'truncated' => false]));
        $this->ai->enqueue_text('It is a plan.');

        $texts = $this->receive(['document' => ['file_id' => 'doc-2', 'file_name' => 'plan.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'caption' => 'What is it about?']);

        $this->assertSame(['doc-2'], $this->telegram->downloads);
        $this->assertCount(1, $this->curl->calls);
        $this->assertStringEndsWith('/jedomi/v1/files/extract', $this->curl->calls[0]['url']);
        $this->assertSame('plan.docx', $this->curl->calls[0]['params']['file']->getPostFilename());
        $this->assertArrayNotHasKey('question', $this->curl->calls[0]['params']);
        $this->assertSame(
            file_extraction_service::compose_message('What is it about?', 'plan.docx', 'Unit 1 plan'),
            $this->last_user_content()
        );
        $this->assertStringContainsString('It is a plan.', end($texts));
    }

    /**
     * MDL-UNIT-049: without a caption the default instruction to summarise the file is used.
     * MDL-INT-064: without a caption a summary is asked.
     */
    public function test_presentation_without_caption_asks_for_a_summary(): void {
        $this->curl->enqueue(json_encode(['text' => 'Slide one', 'format' => 'presentation', 'chars' => 9,
            'truncated' => false]));
        $this->ai->enqueue_text('Summary.');

        $this->receive(['document' => ['file_id' => 'doc-1', 'file_name' => 'deck.ppt', 'mime_type' => 'x']]);

        $this->assertStringEndsWith('/jedomi/v1/files/extract', $this->curl->calls[0]['url']);
        $this->assertSame(file_extraction_service::compose_message('', 'deck.ppt', 'Slide one'), $this->last_user_content());
    }

    /**
     * An office document without text gets the "no text" message and nothing reaches the chat model.
     */
    public function test_office_document_without_text_is_not_sent_to_the_ai(): void {
        $body = json_encode(['detail' => ['code' => 'jedomi_file_no_text']]);
        $this->curl->enqueue($body, 422);

        $texts = $this->receive(['document' => ['file_id' => 'doc-1', 'file_name' => 'empty.pptx', 'mime_type' => 'x']]);

        $this->assertSame([get_string('file_error_no_text', 'local_jedomi')], $texts);
        $this->assertCount(1, $this->curl->calls);
        $this->assertSame([], $this->ai->calls);
        $this->assertDebuggingCalled("HTTP error 422 from Datacurso API (" . strlen($body) . ' bytes)');
    }

    /**
     * MDL-INT-006: with file processing off the bot refuses office documents.
     * An office document is refused like any file when file processing is off.
     */
    public function test_office_document_is_refused_when_files_are_disabled(): void {
        set_config('files_enabled', 0, 'local_jedomi');

        $texts = $this->receive(['document' => ['file_id' => 'doc-1', 'file_name' => 'plan.docx', 'mime_type' => 'x']]);

        $this->assertSame([get_string('telegram_msg_files_disabled', 'local_jedomi')], $texts);
        $this->assertSame([], $this->curl->calls);
        $this->assertSame([], $this->telegram->downloads);
    }

    /**
     * Telegram messages of types the bot does not support.
     *
     * @return array
     */
    public static function unsupported_message_provider(): array {
        return [
            'location' => [['location' => ['latitude' => 4.6, 'longitude' => -74.1]]],
            'video' => [['video' => ['file_id' => 'video-1', 'duration' => 4]]],
            'contact' => [['contact' => ['phone_number' => '+570000', 'first_name' => 'Ana']]],
            'animation' => [['animation' => ['file_id' => 'gif-1']]],
        ];
    }

    /**
     * MDL-UNIT-080: locations, videos and other types are classified as not supported and never reach the AI.
     * MDL-INT-064: messages without text (stickers, locations, videos) get the notice of supported types.
     *
     * @dataProvider unsupported_message_provider
     * @param array $fields Message fields.
     */
    public function test_unsupported_message_types_are_rejected(array $fields): void {
        set_config('files_enabled', 1, 'local_jedomi');
        set_config('audio_enabled', 1, 'local_jedomi');

        $texts = $this->receive($fields);

        $this->assertSame([get_string('telegram_msg_text_only', 'local_jedomi')], $texts);
        $this->assertSame([], $this->ai->calls);
        $this->assertSame([], $this->curl->calls);
        $this->assertSame([], $this->telegram->downloads);
    }

    /**
     * MDL-UNIT-080: a text message goes straight to the chat model without reading any file.
     */
    public function test_text_message_goes_to_the_chat_model(): void {
        $this->ai->enqueue_text('Hello there.');

        $texts = $this->receive(['text' => 'How many courses are there?']);

        $this->assertCount(1, $this->ai->calls);
        $this->assertSame('How many courses are there?', $this->last_user_content());
        $this->assertSame([], $this->curl->calls);
        $this->assertStringContainsString('Hello there.', end($texts));
    }

    /**
     * MDL-UNIT-080: an audio message goes to the transcription like a voice message.
     */
    public function test_audio_message_goes_to_the_transcription(): void {
        set_config('audio_enabled', 1, 'local_jedomi');
        $this->telegram->files['audio-1'] = self::audio('ID3');
        $this->curl->enqueue(json_encode(['text' => 'List my courses', 'model' => 'm', 'duration_seconds' => 2]));
        $this->ai->enqueue_text('Here they are.');

        $this->receive(['audio' => ['file_id' => 'audio-1', 'file_name' => 'question.mp3', 'mime_type' => 'audio/mpeg']]);

        $this->assertStringEndsWith('/jedomi/v1/audio/transcriptions', $this->curl->calls[0]['url']);
        $this->assertSame('List my courses', $this->last_user_content());
    }

    /**
     * Send an audio message whose transcription succeeds and return the file name the service received.
     *
     * @param array $audio Telegram audio object (without file_id).
     * @return string
     */
    private function transcribed_filename(array $audio): string {
        set_config('audio_enabled', 1, 'local_jedomi');
        $this->telegram->files['audio-x'] = self::audio('audio');
        $this->curl->enqueue(json_encode(['text' => 'Hi', 'model' => 'm', 'duration_seconds' => 1]));
        $this->ai->enqueue_text('Hello.');

        $this->receive(['audio' => ['file_id' => 'audio-x'] + $audio]);

        $this->assertNotEmpty($this->curl->calls, 'The audio must reach the transcription');
        return $this->curl->calls[0]['params']['file']->getPostFilename();
    }

    /**
     * MDL-UNIT-102: an audio whose file name has a supported format keeps its name.
     */
    public function test_audio_with_a_supported_name_keeps_it(): void {
        $this->assertSame('lecture.m4a', $this->transcribed_filename(['file_name' => 'lecture.m4a', 'mime_type' => 'audio/mpeg']));
    }

    /**
     * Declared audio types and the format each one must get.
     *
     * @return array
     */
    public static function audio_type_provider(): array {
        return [
            'mpeg' => ['audio/mpeg', 'mp3'],
            'mp4' => ['audio/x-m4a', 'm4a'],
            'wav' => ['audio/x-wav', 'wav'],
            'webm' => ['audio/webm', 'webm'],
            'opus' => ['audio/opus', 'ogg'],
            'upper case type' => ['AUDIO/MPEG', 'mp3'],
        ];
    }

    /**
     * MDL-UNIT-102: an audio without a supported name gets the format of its declared type.
     *
     * @dataProvider audio_type_provider
     * @param string $mimetype Declared type.
     * @param string $extension Expected format.
     */
    public function test_audio_without_supported_name_gets_the_format_of_its_type(string $mimetype, string $extension): void {
        $this->assertStringEndsWith('.' . $extension, $this->transcribed_filename(['mime_type' => $mimetype]));
    }

    /**
     * MDL-UNIT-102: an audio with an unsupported name and a recognised type gets the format of its type.
     */
    public function test_audio_with_unsupported_name_and_known_type_gets_the_type_format(): void {
        $this->assertStringEndsWith('.wav', $this->transcribed_filename(['file_name' => 'memo.aiff', 'mime_type' => 'audio/wav']));
    }

    /**
     * MDL-UNIT-102: an audio of an unrecognised type keeps its name, or a generic one, and is rejected with the
     * unsupported format message without calling the service.
     */
    public function test_audio_of_unknown_type_is_rejected_as_unsupported(): void {
        set_config('audio_enabled', 1, 'local_jedomi');
        $this->telegram->files['audio-flac'] = 'fLaC bytes';
        $this->telegram->files['audio-anon'] = 'bytes';

        $named = $this->receive(['audio' => ['file_id' => 'audio-flac', 'file_name' => 'song.flac', 'mime_type' => 'audio/flac']]);
        $this->telegram->sent = [];
        $unnamed = $this->receive(['audio' => ['file_id' => 'audio-anon', 'mime_type' => 'audio/x-unknown']]);

        $expected = get_string('audio_error_unsupported', 'local_jedomi');
        $this->assertSame([$expected], $named);
        $this->assertSame([$expected], $unnamed);
        $this->assertSame([], $this->curl->calls);
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * MDL-INT-013: with the Telegram integration turned off the bot stops processing the messages of linked chats.
     */
    public function test_disabled_integration_stops_the_bot(): void {
        set_config('telegram_enabled', 0, 'local_jedomi');
        $this->ai->enqueue_text('An answer that must never be produced.');

        $this->receive(['text' => 'How many courses are there?']);

        $this->assertSame([], $this->ai->calls, 'The bot must not call the AI while the integration is off');
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * MDL-INT-013: with the integration turned on the same message is processed (control of the previous test).
     */
    public function test_enabled_integration_processes_the_message(): void {
        set_config('telegram_enabled', 1, 'local_jedomi');
        $this->ai->enqueue_text('There are 3 courses.');

        $this->receive(['text' => 'How many courses are there?']);

        $this->assertCount(1, $this->ai->calls);
    }

    /**
     * Content of an audio file large enough to be sent to the audio model.
     *
     * @param string $header First bytes of the file.
     * @return string
     */
    private static function audio(string $header): string {
        return str_pad($header, audio_service::MIN_BYTES, "\0");
    }

    /**
     * MDL-INT-112, MDL-INT-118: a voice message smaller than the smallest audio the audio model receives (noise or an empty
     * recording) is answered with its message without calling the transcription nor the AI, so it is not charged.
     */
    public function test_tiny_voice_message_is_not_transcribed(): void {
        set_config('audio_enabled', 1, 'local_jedomi');
        $this->telegram->files['voice-tiny'] = 'OggS bytes';

        $texts = $this->receive(['voice' => ['file_id' => 'voice-tiny', 'mime_type' => 'audio/ogg', 'duration' => 0]]);

        $this->assertSame([get_string('audio_error_too_short', 'local_jedomi')], $texts);
        $this->assertSame([], $this->curl->calls);
        $this->assertSame([], $this->ai->calls);
    }
}
