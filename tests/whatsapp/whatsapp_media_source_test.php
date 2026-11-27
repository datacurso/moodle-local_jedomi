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

use local_jedomi\messaging\media_ref;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_cloud_api_client.php');

/**
 * Tests for the download of WhatsApp media in two steps (address, then file with the access token) to a temporary file.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\whatsapp_media_source
 */
final class whatsapp_media_source_test extends \advanced_testcase {
    /** @var string Media id of the tests. */
    private const MEDIAID = '1037543291543636';

    /** @var fake_cloud_api_client Client double. */
    private fake_cloud_api_client $client;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->client = new fake_cloud_api_client();
        cloud_api_client::set_instance_for_testing($this->client);
    }

    #[\Override]
    protected function tearDown(): void {
        cloud_api_client::set_instance_for_testing(null);
        parent::tearDown();
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
     * Run with_file with a callback that records the path and the content it sees.
     *
     * @param media_ref $ref Attachment.
     * @param int $maxbytes Largest file accepted.
     * @param string|null $seen Set to the path the callback received.
     * @return array Result of the callback.
     */
    private function fetch(media_ref $ref, int $maxbytes = cloud_api_client::MAX_MEDIA_BYTES, ?string &$seen = null): array {
        return (new whatsapp_media_source(null, $maxbytes))->with_file(
            $ref,
            static function (string $path) use (&$seen): array {
                $seen = $path;
                return ['content' => file_get_contents($path)];
            }
        );
    }

    /**
     * Assert a call fails with a local_jedomi or provider error code and the callback is never called.
     *
     * @param media_ref $ref Attachment.
     * @param string $module Expected component.
     * @param string $errorcode Expected error code.
     * @param int $maxbytes Largest file accepted.
     * @return \moodle_exception The failure.
     */
    private function assert_refused(
        media_ref $ref,
        string $module,
        string $errorcode,
        int $maxbytes = cloud_api_client::MAX_MEDIA_BYTES
    ): \moodle_exception {
        $called = false;
        try {
            (new whatsapp_media_source(null, $maxbytes))->with_file(
                $ref,
                static function (string $path) use (&$called): array {
                    $called = true;
                    return [];
                }
            );
        } catch (\moodle_exception $e) {
            $this->assertSame($module, $e->module);
            $this->assertSame($errorcode, $e->errorcode);
            $this->assertFalse($called, 'The callback is not called for a refused file');
            return $e;
        }
        $this->fail('The file was not refused');
    }

    /**
     * Files of the temporary directory of the media source.
     *
     * @return string[]
     */
    private static function temp_files(): array {
        return glob(make_temp_directory(whatsapp_media_source::TEMP_DIR) . '/*') ?: [];
    }

    /**
     * Audio (a voice note): the address is asked with the media id, the file is downloaded with the 16 MB limit into the
     * temporary directory of Moodle, passed to the callback and removed afterwards.
     */
    public function test_audio_is_downloaded_in_two_steps_and_removed(): void {
        global $CFG;
        $this->serve('OggS voice', 'audio/ogg');
        $seen = null;

        $result = $this->fetch(new media_ref(media_ref::AUDIO, self::MEDIAID, 'voice.ogg', 'audio/ogg'), seen: $seen);

        $this->assertSame(['content' => 'OggS voice'], $result);
        $this->assertSame([self::MEDIAID], $this->client->calls_to('get_media_url')[0]['args']);
        $download = $this->client->calls_to('download_media');
        $this->assertCount(1, $download);
        $this->assertSame(fake_cloud_api_client::MEDIA_URL . self::MEDIAID, $download[0]['args'][0]);
        $this->assertSame(cloud_api_client::MAX_MEDIA_BYTES, $download[0]['args'][2]);
        $this->assertSame(16 * 1024 * 1024, cloud_api_client::MAX_MEDIA_BYTES);
        $this->assertStringStartsWith(realpath($CFG->tempdir) . '/', realpath(dirname($seen)));
        $this->assertFileDoesNotExist($seen);
    }

    /**
     * An image and an Office document are downloaded the same way; the type Meta gives may carry parameters.
     */
    public function test_image_and_office_document_are_downloaded(): void {
        $this->serve('jpeg bytes', 'image/jpeg');
        $this->assertSame(
            ['content' => 'jpeg bytes'],
            $this->fetch(new media_ref(media_ref::IMAGE, self::MEDIAID, 'photo.jpg', 'image/jpeg'))
        );

        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $this->serve('PK docx', $docx . '; charset=binary');
        $this->assertSame(
            ['content' => 'PK docx'],
            $this->fetch(new media_ref(media_ref::DOCUMENT, self::MEDIAID, 'notes.docx', $docx))
        );
        $this->assertSame([], self::temp_files());
    }

    /**
     * An expired address (404) is asked again and the download is retried once.
     */
    public function test_expired_address_is_renewed_once(): void {
        $this->serve('jpeg bytes', 'image/jpeg');
        $this->client->expired[self::MEDIAID] = 1;

        $result = $this->fetch(new media_ref(media_ref::IMAGE, self::MEDIAID, 'photo.jpg', 'image/jpeg'));

        $this->assertSame(['content' => 'jpeg bytes'], $result);
        $this->assertCount(2, $this->client->calls_to('get_media_url'));
        $this->assertCount(2, $this->client->calls_to('download_media'));
    }

    /**
     * An address that expires twice is not retried again: the download fails as an empty answer, like Telegram, and
     * neither the address nor the access token reach the log.
     */
    public function test_address_expired_twice_fails_after_one_retry(): void {
        global $CFG;
        $logfile = $CFG->dataroot . '/local/jedomi/jedomi.log';
        @unlink($logfile);
        $this->serve('jpeg bytes', 'image/jpeg');
        $this->client->expired[self::MEDIAID] = 2;

        $this->assert_refused(
            new media_ref(media_ref::IMAGE, self::MEDIAID, 'photo.jpg', 'image/jpeg'),
            'aiprovider_datacurso',
            'emptyresponse'
        );

        $this->assertCount(2, $this->client->calls_to('get_media_url'));
        $this->assertCount(2, $this->client->calls_to('download_media'));
        $this->assertSame([], self::temp_files());
        $log = (string) @file_get_contents($logfile);
        $this->assertStringContainsString('WhatsApp media not downloaded', $log);
        $this->assertStringNotContainsString('mid=', $log);
        $this->assertStringNotContainsString('test-token', $log);
        $this->assertStringNotContainsString(self::MEDIAID, $log);
    }

    /**
     * A failure other than an expired address is not retried.
     */
    public function test_other_failures_are_not_retried(): void {
        $this->serve('jpeg bytes', 'image/jpeg');
        $this->client->responses['download_media'] = new cloud_api_exception(500, 131016, 'Service unavailable');

        $this->assert_refused(
            new media_ref(media_ref::IMAGE, self::MEDIAID, 'photo.jpg', 'image/jpeg'),
            'aiprovider_datacurso',
            'emptyresponse'
        );
        $this->assertCount(1, $this->client->calls_to('get_media_url'));
        $this->assertCount(1, $this->client->calls_to('download_media'));
    }

    /**
     * The PHP time limit is raised before the media is downloaded, to cover every download request plus the slowest
     * reading service, so a host limit (often 30 s) cannot kill the webhook mid-media and leave the file on disk. It is
     * raised even when the download then fails, and follows the AI timeout setting that bounds the reading of a PDF.
     */
    public function test_media_phase_raises_the_time_limit_before_downloading(): void {
        $this->resetAfterTest();
        \core_php_time_limit::get_and_clear_unit_test_data();
        $this->serve('OggS voice', 'audio/ogg');

        $this->fetch(new media_ref(media_ref::AUDIO, self::MEDIAID, 'voice.ogg', 'audio/ogg'));

        // Four requests of 30 s (address and download, twice on an expired address), 180 s to read, 20 s of margin.
        $this->assertSame([320], \core_php_time_limit::get_and_clear_unit_test_data());
        $this->assertSame(320, whatsapp_media_source::time_limit());

        set_config('ai_timeout', \local_jedomi\ai\client::MAX_TIMEOUT, 'local_jedomi');
        $this->assert_refused(
            new media_ref(media_ref::AUDIO, '999', 'voice.ogg', 'audio/ogg'),
            'aiprovider_datacurso',
            'emptyresponse'
        );
        $this->assertSame([120 + 600 + 20], \core_php_time_limit::get_and_clear_unit_test_data());
    }

    /**
     * A media id Meta does not know, a malformed one or none fails as an empty answer; without id nothing is asked.
     */
    public function test_missing_or_invalid_media_id_fails_as_an_empty_answer(): void {
        foreach (['999', '../123/messages', ''] as $id) {
            $this->assert_refused(
                new media_ref(media_ref::AUDIO, $id, 'voice.ogg', 'audio/ogg'),
                'aiprovider_datacurso',
                'emptyresponse'
            );
        }
        $this->assertCount(1, $this->client->calls_to('get_media_url'));
        $this->assertSame([], $this->client->calls_to('download_media'));
    }

    /**
     * A file Meta announces larger than the limit is refused before it is downloaded, with the limit in MB.
     */
    public function test_file_announced_over_the_limit_is_not_downloaded(): void {
        $this->serve('small', 'image/jpeg', cloud_api_client::MAX_MEDIA_BYTES + 1);

        $e = $this->assert_refused(
            new media_ref(media_ref::IMAGE, self::MEDIAID, 'photo.jpg', 'image/jpeg'),
            'local_jedomi',
            'file_error_upload_too_large'
        );

        $this->assertSame(16, $e->a);
        $this->assertSame([], $this->client->calls_to('download_media'));
    }

    /**
     * A file that turns out larger than the limit while it is downloaded is stopped by the client and refused the same
     * way, without a file left behind.
     */
    public function test_file_over_the_limit_while_downloading_is_refused(): void {
        $this->serve(str_repeat('x', 1048577), 'image/jpeg', 10);

        $e = $this->assert_refused(
            new media_ref(media_ref::IMAGE, self::MEDIAID, 'photo.jpg', 'image/jpeg'),
            'local_jedomi',
            'file_error_upload_too_large',
            1048576
        );

        $this->assertSame(1, $e->a);
        $this->assertCount(1, $this->client->calls_to('download_media'));
        $this->assertSame([], self::temp_files());
    }

    /**
     * A type the services cannot read is refused before the download, with the message of the service of its kind.
     *
     * @dataProvider wrong_type_provider
     * @param string $kind Media kind.
     * @param string $filename File name.
     * @param string $mimetype Type Meta announces.
     * @param string $errorcode Expected error code of local_jedomi.
     */
    public function test_wrong_type_is_refused(string $kind, string $filename, string $mimetype, string $errorcode): void {
        $this->serve('bytes', $mimetype);

        $this->assert_refused(new media_ref($kind, self::MEDIAID, $filename, ''), 'local_jedomi', $errorcode);
        $this->assertSame([], $this->client->calls_to('download_media'));
    }

    /**
     * Types the services cannot read.
     *
     * @return array
     */
    public static function wrong_type_provider(): array {
        return [
            'amr audio' => [media_ref::AUDIO, '', 'audio/amr', 'audio_error_unsupported'],
            'gif image' => [media_ref::IMAGE, 'photo.jpg', 'image/gif', 'image_error_unsupported'],
            'zip as pdf' => [media_ref::DOCUMENT, 'essay.pdf', 'application/zip', 'image_error_unsupported'],
            'zip as docx' => [media_ref::DOCUMENT, 'notes.docx', 'application/zip', 'file_error_unsupported'],
            'video as image' => [media_ref::IMAGE, 'photo.jpg', 'video/mp4', 'image_error_unsupported'],
        ];
    }

    /**
     * A PDF and an image sent as documents are read; the type of the webhook is used when Meta gives none.
     */
    public function test_document_types_follow_the_services(): void {
        $this->serve('%PDF', '');
        $this->assertSame(
            ['content' => '%PDF'],
            $this->fetch(new media_ref(media_ref::DOCUMENT, self::MEDIAID, 'essay.pdf', 'application/pdf'))
        );

        $this->serve('png bytes', 'image/png');
        $this->assertSame(
            ['content' => 'png bytes'],
            $this->fetch(new media_ref(media_ref::DOCUMENT, self::MEDIAID, 'scan.png', ''))
        );
    }

    /**
     * The file is removed also when the callback fails, and its failure goes on unchanged.
     */
    public function test_file_is_removed_when_the_callback_fails(): void {
        $this->serve('jpeg bytes', 'image/jpeg');
        $seen = null;
        try {
            (new whatsapp_media_source())->with_file(
                new media_ref(media_ref::IMAGE, self::MEDIAID, 'photo.jpg', 'image/jpeg'),
                static function (string $path) use (&$seen): array {
                    $seen = $path;
                    throw new \coding_exception('failed');
                }
            );
            $this->fail('The failure of the callback goes on');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('failed', $e->getMessage());
        }
        $this->assertNotNull($seen);
        $this->assertFileDoesNotExist($seen);
    }
}
