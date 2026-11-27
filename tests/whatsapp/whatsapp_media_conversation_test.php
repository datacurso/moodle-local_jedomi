<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_jedomi\whatsapp;

use local_jedomi\ai\ai_service;
use local_jedomi\ai\client;
use local_jedomi\ai\custom_api;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\ai\fake_curl;
use local_jedomi\local\secret_store;
use local_jedomi\local\service\file_extraction_service;
use local_jedomi\local\service\vision_service;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\fake_plugin_state;
use local_jedomi\messaging\plugin_state;
use local_jedomi\provider_absence_probe;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_cloud_api_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_curl.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');

/**
 * End-to-end tests of WhatsApp media (unit 14): a voice note, a photo or a document stored in the inbox is processed
 * by the default pieces, downloaded in two steps through the fake Cloud API client, read by the transcription, vision
 * or extraction service of the custom AI service (a fake cURL) and answered by fake_ai_client. Nothing reaches Meta,
 * an AI service or the Datacurso AI provider.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\whatsapp_conversation_handler
 * @covers     \local_jedomi\whatsapp\whatsapp_media_source
 * @covers     \local_jedomi\whatsapp\whatsapp_channel
 * @covers     \local_jedomi\messaging\media_pipeline
 */
final class whatsapp_media_conversation_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Media id of the tests. */
    private const MEDIAID = '1037543291543636';

    /** @var int Frozen time of the tests. */
    private const NOW = 1800000000;

    /** @var fake_cloud_api_client Client double. */
    private fake_cloud_api_client $client;

    /** @var fake_ai_client Chat model double. */
    private fake_ai_client $ai;

    /** @var fake_curl Transport double of the custom AI service (transcription and vision). */
    private fake_curl $curl;

    /** @var int User id of the student. */
    private int $userid;

    /** @var int Messages delivered, for unique wamids. */
    private int $count = 0;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::NOW);
        provider_absence_probe::start();
        set_config('whatsapp_enabled', 1, 'local_jedomi');
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $settings = [
            'ai_service' => ai_service::CUSTOM,
            'custom_gateway_url' => 'https://api.example.com',
            'custom_api_key' => 'sk-test',
            'custom_model' => 'chat-model',
            'custom_vision_model' => 'vision-model',
            'custom_audio_model' => 'whisper-model',
            'audio_enabled' => 1,
            'files_enabled' => 1,
        ];
        foreach ($settings as $name => $value) {
            set_config($name, $value, 'local_jedomi');
        }
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        $this->client = new fake_cloud_api_client();
        cloud_api_client::set_instance_for_testing($this->client);
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
        $this->curl = new fake_curl();
        custom_api::set_curl_factory_for_testing(fn(bool $bypass): \curl => $this->curl);

        $this->userid = (int) $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lang' => 'en'])->id;
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) [
            'userid' => $this->userid, 'bsuid' => self::BSUID, 'waid' => null, 'phone' => null, 'confirmed' => 1,
            'consentat' => self::NOW - DAYSECS, 'consentversion' => consent::VERSION, 'consentcategories' => 'chat',
            'lastused' => self::NOW - HOURSECS, 'lastinbound' => self::NOW - HOURSECS, 'timecreated' => self::NOW - DAYSECS,
        ]);
    }

    #[\Override]
    protected function assertPostConditions(): void {
        parent::assertPostConditions();
        $this->assertSame([], provider_absence_probe::touched(), 'The test reached the Datacurso AI provider.');
        $this->assertSame(
            [],
            glob(make_temp_directory(whatsapp_media_source::TEMP_DIR) . '/*') ?: [],
            'A temporary media file was left behind.'
        );
    }

    #[\Override]
    protected function tearDown(): void {
        cloud_api_client::set_instance_for_testing(null);
        client::set_instance_for_testing(null);
        custom_api::set_curl_factory_for_testing(null);
        parent::tearDown();
    }

    /**
     * Deliver a message from the student as the webhook stores it, and process it with the default pieces.
     *
     * @param array $content Type-specific fields of the message.
     */
    private function deliver(array $content): void {
        global $DB;
        $this->count++;
        $wamid = 'wamid.MEDIA' . $this->count;
        $message = ['from_user_id' => self::BSUID, 'id' => $wamid, 'timestamp' => (string) self::NOW] + $content;
        $id = $DB->insert_record(whatsapp_link_service::INBOX_TABLE, (object) [
            'wamid' => $wamid,
            'bsuid' => self::BSUID,
            'payload' => secret_store::encrypt(json_encode(['message' => $message, 'contact' => null])),
            'status' => inbox_processor::STATUS_PENDING,
            'attempts' => 0,
            'timecreated' => self::NOW,
            'timeprocessed' => 0,
        ]);
        $limit = (int) ini_get('max_execution_time');
        $result = (new inbox_processor())->process((int) $id);
        set_time_limit($limit);
        $this->assertSame(inbox_processor::RESULT_DONE, $result);
    }

    /**
     * Serve a media file from the client double.
     *
     * @param string $content Content.
     * @param string $mimetype Type Meta announces.
     * @param int|null $filesize Size Meta announces; the real size when null.
     */
    private function serve(string $content, string $mimetype, ?int $filesize = null): void {
        $this->client->media[self::MEDIAID] = ['content' => $content, 'mime_type' => $mimetype]
            + ($filesize === null ? [] : ['file_size' => $filesize]);
    }

    /**
     * Texts sent to the student, in order.
     *
     * @return string[]
     */
    private function texts(): array {
        return array_map(static fn(array $call): string => $call['args'][1], $this->client->calls_to('send_text'));
    }

    /**
     * Last user message the chat model received.
     *
     * @return string
     */
    private function last_user_content(): string {
        $messages = $this->ai->calls[0]['messages'];
        $users = array_values(array_filter($messages, static fn(array $m): bool => $m['role'] === 'user'));
        return (string) end($users)['content'];
    }

    /**
     * Usage counter of the month.
     *
     * @param string $counter Column.
     * @return int
     */
    private function usage(string $counter): int {
        global $DB;
        return (int) $DB->get_field(
            whatsapp_link_service::USAGE_TABLE,
            $counter,
            ['userid' => $this->userid, 'period' => (int) date('Ym', self::NOW)]
        );
    }

    /**
     * A voice note is downloaded in two steps, transcribed, echoed escaped for WhatsApp and answered; it counts as
     * received media.
     */
    public function test_voice_note_is_transcribed_echoed_and_answered(): void {
        $this->serve('OggS' . str_repeat('v', 4096), 'audio/ogg; codecs=opus');
        $this->curl->enqueue(json_encode(['text' => 'How are my *grades*?']));
        $this->ai->enqueue_text('They are good.');

        $this->deliver(['type' => 'audio', 'audio' => ['id' => self::MEDIAID, 'mime_type' => 'audio/ogg; codecs=opus',
            'voice' => true]]);

        $this->assertSame([self::MEDIAID], $this->client->calls_to('get_media_url')[0]['args']);
        $this->assertCount(1, $this->client->calls_to('download_media'));
        $this->assertSame('https://api.example.com/v1/audio/transcriptions', $this->curl->calls[0]['url']);
        $texts = $this->texts();
        $this->assertSame(get_string('whatsapp_msg_heard', 'local_jedomi', formatter::escape('How are my *grades*?')), $texts[0]);
        $this->assertStringNotContainsString('*grades*', $texts[0]);
        $this->assertSame('How are my *grades*?', $this->last_user_content());
        $this->assertStringContainsString('They are good.', end($texts));
        $this->assertSame(1, $this->usage(usage_meter::MEDIA));
    }

    /**
     * A photo is read by the vision model with its caption as the question; the chat model gets only text.
     */
    public function test_photo_is_read_with_its_caption(): void {
        $this->serve('PNGDATA', 'image/png');
        $this->curl->enqueue(json_encode(['choices' => [['message' => ['content' => 'A grade report'],
            'finish_reason' => 'stop']]]));
        $this->ai->enqueue_text('It is your grade report.');

        $this->deliver(['type' => 'image', 'image' => ['id' => self::MEDIAID, 'mime_type' => 'image/png',
            'caption' => 'What is this?']]);

        $this->assertSame('https://api.example.com/v1/chat/completions', $this->curl->calls[0]['url']);
        $parts = json_decode($this->curl->calls[0]['params'], true)['messages'][0]['content'];
        $this->assertStringContainsString('What is this?', $parts[0]['text']);
        $this->assertSame('data:image/png;base64,' . base64_encode('PNGDATA'), $parts[1]['image_url']['url']);
        $user = $this->last_user_content();
        $this->assertStringContainsString('What is this?', $user);
        $this->assertStringContainsString('A grade report', $user);
        $texts = $this->texts();
        $this->assertStringContainsString('It is your grade report.', end($texts));
        $this->assertSame(1, $this->usage(usage_meter::MEDIA));
    }

    /**
     * An Office document goes to the extraction service after it is downloaded; the custom AI service of this site
     * cannot read Word files, so the student gets its message and nothing reaches the chat model.
     */
    public function test_office_document_goes_to_the_extraction_service(): void {
        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $this->serve('PK docx', $docx);

        $this->deliver(['type' => 'document', 'document' => ['id' => self::MEDIAID, 'mime_type' => $docx,
            'filename' => 'notes.docx', 'caption' => 'Summarise']]);

        $this->assertCount(1, $this->client->calls_to('download_media'));
        $expected = file_extraction_service::error_for(new \moodle_exception('documents_error_custom', 'local_jedomi'));
        $this->assertSame([$expected['message']], $this->texts());
        $this->assertSame([], $this->ai->calls);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * A file Meta announces over 16 MB is not downloaded and the student is told the limit.
     */
    public function test_file_over_the_size_limit_is_refused(): void {
        $this->serve('PNGDATA', 'image/png', cloud_api_client::MAX_MEDIA_BYTES + 1);

        $this->deliver(['type' => 'image', 'image' => ['id' => self::MEDIAID, 'mime_type' => 'image/png']]);

        $this->assertSame([], $this->client->calls_to('download_media'));
        $this->assertSame([get_string('file_error_upload_too_large', 'local_jedomi', 16)], $this->texts());
        $this->assertSame([], $this->ai->calls);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * An expired download address is renewed once and the photo is answered.
     */
    public function test_expired_address_is_renewed(): void {
        $this->serve('PNGDATA', 'image/png');
        $this->client->expired[self::MEDIAID] = 1;
        $this->curl->enqueue(json_encode(['choices' => [['message' => ['content' => 'A cat'], 'finish_reason' => 'stop']]]));
        $this->ai->enqueue_text('A cat.');

        $this->deliver(['type' => 'image', 'image' => ['id' => self::MEDIAID, 'mime_type' => 'image/png']]);

        $this->assertCount(2, $this->client->calls_to('get_media_url'));
        $this->assertCount(2, $this->client->calls_to('download_media'));
        $this->assertCount(1, $this->ai->calls);
    }

    /**
     * A file that cannot be downloaded gets a message without any detail of Meta, and nothing reaches the AI.
     */
    public function test_download_failure_hides_the_error_of_meta(): void {
        $this->serve('PNGDATA', 'image/png');
        $this->client->responses['download_media'] = new cloud_api_exception(500, 131016, 'Secret detail of Meta');

        $this->deliver(['type' => 'image', 'image' => ['id' => self::MEDIAID, 'mime_type' => 'image/png']]);

        $expected = vision_service::error_for(new \moodle_exception('emptyresponse', 'aiprovider_datacurso'));
        $this->assertSame([$expected['message']], $this->texts());
        $this->assertStringNotContainsString('131016', $this->texts()[0]);
        $this->assertStringNotContainsString('Secret', $this->texts()[0]);
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * A sticker is not read: the student is asked for text and nothing is downloaded.
     */
    public function test_sticker_asks_for_text(): void {
        $this->deliver(['type' => 'sticker', 'sticker' => ['id' => self::MEDIAID, 'mime_type' => 'image/webp']]);

        $this->assertSame([get_string('whatsapp_msg_text_only', 'local_jedomi')], $this->texts());
        $this->assertSame([], $this->client->calls_to('get_media_url'));
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * With audio processing off, a voice note gets the audio message of WhatsApp and is not downloaded.
     */
    public function test_voice_note_is_refused_when_audio_is_disabled(): void {
        set_config('custom_audio_enabled', 0, 'local_jedomi');

        $this->deliver(['type' => 'audio', 'audio' => ['id' => self::MEDIAID, 'mime_type' => 'audio/ogg', 'voice' => true]]);

        $this->assertSame([get_string('whatsapp_msg_audio_disabled', 'local_jedomi')], $this->texts());
        $this->assertSame([], $this->client->calls_to('get_media_url'));
    }
}
