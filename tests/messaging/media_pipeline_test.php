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

namespace local_jedomi\messaging;

use local_jedomi\local\service\audio_service;
use local_jedomi\local\service\file_extraction_service;
use local_jedomi\local\service\vision_service;
use local_jedomi\telegram\api_client;
use local_jedomi\telegram\fake_telegram_api_client;
use local_jedomi\telegram\telegram_channel;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_continuation.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_transport.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_media_source.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_channel.php');

/**
 * Tests for the channel-neutral media pipeline: what text of a message reaches the AI, and what the user is told when
 * none does. The transcription, vision and extraction services themselves are covered through the Telegram webhook.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\media_pipeline
 */
final class media_pipeline_test extends \advanced_testcase {
    /** @var string Chat of the tests. */
    private const CHATKEY = '793000001';

    /** @var fake_channel Channel of the tests. */
    private fake_channel $channel;

    /** @var fake_media_source Media of the tests. */
    private fake_media_source $media;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        api_client::set_instance_for_testing(new fake_telegram_api_client());
        $this->channel = new fake_channel(new telegram_channel());
        $this->media = new fake_media_source();
        $this->channel->mediasource = $this->media;
    }

    #[\Override]
    protected function tearDown(): void {
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * A message of the chat of the tests.
     *
     * @param string $text Text.
     * @param media_ref|null $media Attachment.
     * @param string $caption Caption of the attachment.
     * @return inbound_message
     */
    private function message(string $text, ?media_ref $media = null, string $caption = ''): inbound_message {
        return new inbound_message('telegram', self::CHATKEY, '1', $text, null, $media, $caption, true, '', '1');
    }

    /**
     * Text of a message for the AI.
     *
     * @param inbound_message $message Message.
     * @param array $map Placeholders set by the pipeline.
     * @return string|null
     */
    private function text_of(inbound_message $message, array &$map = []): ?string {
        return (new media_pipeline($this->channel))->text($message, 2, $map);
    }

    /**
     * A text message reaches the AI as it is, with nothing sent to the user.
     */
    public function test_text_reaches_the_ai_as_it_is(): void {
        $map = ['kept' => 'value'];

        $this->assertSame('What are my grades?', $this->text_of($this->message('What are my grades?'), $map));
        $this->assertSame([], $this->channel->transport->sent);
        $this->assertSame([], $this->media->requested);
        $this->assertSame(['kept' => 'value'], $map);
    }

    /**
     * A message without text nor attachment asks for text.
     */
    public function test_message_without_text_asks_for_text(): void {
        $this->assertNull($this->text_of($this->message('')));
        $this->assertSame([channel::MESSAGE_TEXT_ONLY], $this->channel->transport->texts());
    }

    /**
     * Audio is refused while audio processing is off, and nothing is downloaded.
     */
    public function test_audio_is_refused_while_audio_is_disabled(): void {
        $this->resetAfterTest();
        set_config('audio_enabled', 0, 'local_jedomi');

        $this->assertNull($this->text_of($this->message('', new media_ref(media_ref::AUDIO, 'v-1', 'voice.ogg', ''))));
        $this->assertSame([channel::MESSAGE_AUDIO_DISABLED], $this->channel->transport->texts());
        $this->assertSame([], $this->media->requested);
    }

    /**
     * Images and documents are refused while file processing is off, and nothing is downloaded.
     */
    public function test_files_are_refused_while_files_are_disabled(): void {
        $this->resetAfterTest();
        set_config('files_enabled', 0, 'local_jedomi');

        $this->assertNull($this->text_of($this->message('', new media_ref(media_ref::IMAGE, 'p-1', 'photo.jpg', ''))));
        $this->assertNull($this->text_of($this->message('', new media_ref(media_ref::DOCUMENT, 'd-1', 'a.docx', ''))));
        $this->assertSame([channel::MESSAGE_FILES_DISABLED, channel::MESSAGE_FILES_DISABLED], $this->channel->transport->texts());
        $this->assertSame([], $this->media->requested);
    }

    /**
     * A document the vision model cannot read is refused without downloading it.
     */
    public function test_unsupported_document_is_refused(): void {
        $this->resetAfterTest();
        set_config('files_enabled', 1, 'local_jedomi');

        $this->assertNull($this->text_of($this->message('', new media_ref(media_ref::DOCUMENT, 'd-1', 'tool.exe', ''))));
        $this->assertSame([channel::MESSAGE_FILE_UNSUPPORTED], $this->channel->transport->texts());
        $this->assertSame([], $this->media->requested);
    }

    /**
     * An audio that cannot be downloaded gets the activity status and the error of the transcription service.
     */
    public function test_audio_that_cannot_be_downloaded(): void {
        $this->resetAfterTest();
        set_config('audio_enabled', 1, 'local_jedomi');
        $ref = new media_ref(media_ref::AUDIO, 'missing', 'talk.mp3', 'audio/mpeg');

        $this->assertNull($this->text_of($this->message('', $ref)));

        $error = audio_service::error_for(new \moodle_exception('emptyresponse', 'aiprovider_datacurso'))['message'];
        $this->assertSame([$error], $this->channel->transport->texts());
        $this->assertCount(1, $this->channel->transport->activities);
        $this->assertSame([$ref], $this->media->requested);
    }

    /**
     * An Office document and an image that cannot be downloaded get the error of their service.
     */
    public function test_files_that_cannot_be_downloaded(): void {
        $this->resetAfterTest();
        set_config('files_enabled', 1, 'local_jedomi');
        $failure = new \moodle_exception('emptyresponse', 'aiprovider_datacurso');

        $this->assertNull($this->text_of($this->message('', new media_ref(media_ref::DOCUMENT, 'd', 'a.docx', ''))));
        $this->assertNull($this->text_of($this->message('', new media_ref(media_ref::IMAGE, 'p', 'photo.jpg', ''))));

        $this->assertSame([
            file_extraction_service::error_for($failure)['message'],
            vision_service::error_for($failure)['message'],
        ], $this->channel->transport->texts());
        $this->assertCount(2, $this->channel->transport->activities);
    }

    /**
     * Audio names: a supported name is kept, otherwise the type gives the format, and the name is the last resort.
     */
    public function test_audio_filename(): void {
        $this->assertSame('talk.mp3', media_pipeline::audio_filename('talk.mp3', 'audio/ogg'));
        $this->assertSame('voice.ogg', media_pipeline::audio_filename('voice.ogg', ''));
        $this->assertSame('audio.m4a', media_pipeline::audio_filename('talk.xyz', 'audio/x-m4a'));
        $this->assertSame('audio.ogg', media_pipeline::audio_filename('', 'audio/opus'));
        $this->assertSame('talk.xyz', media_pipeline::audio_filename('talk.xyz', 'audio/unknown'));
        $this->assertSame('audio', media_pipeline::audio_filename('', ''));
    }
}
