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
use local_jedomi\local\service\file_extraction_service;
use local_jedomi\messaging\media_ref;
use local_jedomi\messaging\media_source;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Media source of the WhatsApp channel: downloads the media of a message in two steps through the Cloud API client.
 *
 * 1. GET /<media-id> with the access token gives a download address that expires about five minutes later, the type
 *    and the size of the file.
 * 2. The address is downloaded with the same token, straight to a temporary file of Moodle, stopping at 16 MB.
 *
 * A type the services of the media pipeline cannot read, or a file Meta announces over the limit, is refused before it
 * is downloaded, with the message of the service of its kind; a file that turns out larger while it is downloaded is
 * stopped by the client and refused the same way. An expired address (404) is asked again and the download is retried
 * once. Any other failure is logged with its HTTP status and Meta code only (never the address, the token, the media
 * id or the text of Meta) and fails as an empty answer, like Telegram. The temporary file is always removed.
 *
 * The media phase runs before the conversation engine raises the PHP time limit, inside the webhook request in inline
 * mode, so the limit is raised here first ({@see self::time_limit()}): a host limit (often 30 s) would otherwise kill the
 * process mid-media, before the file is removed. A file left by a process killed anyway is swept by the cleanup task.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_media_source implements media_source {
    /** @var string Directory of the temporary files, under the temporary directory of Moodle. */
    public const TEMP_DIR = 'local_jedomi/whatsapp_media';

    /** @var string[] Audio types the transcription service reads (see media_pipeline::audio_filename()). */
    private const AUDIO_TYPES = [
        'audio/ogg', 'audio/opus', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/m4a',
        'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/webm',
    ];

    /** @var string[] Image types the vision service reads. */
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var string[] Types of the documents the vision service reads: PDF and images. */
    private const VISION_DOCUMENT_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** @var string[] Types of the Word and PowerPoint documents the extraction service reads. */
    private const OFFICE_TYPES = [
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /** @var int HTTP status of an expired download address. */
    private const HTTP_NOT_FOUND = 404;

    /**
     * @var int Requests of the download in the worst case: the address and the file, twice when the address expired.
     */
    private const DOWNLOAD_REQUESTS = 4;

    /** @var int Seconds each request of the download may take: the usual timeout of the Cloud API client. */
    private const DOWNLOAD_REQUEST_SECONDS = 30;

    /**
     * @var int Seconds of the slowest single request of the services that read the media: the vision service (180 s;
     *      transcription and extraction take 120 s). A PDF read page by page is bounded by the AI timeout setting instead.
     */
    private const READ_SECONDS = 180;

    /** @var int Seconds kept on top of the download and the reading, for the local work around them. */
    private const TIME_LIMIT_MARGIN = 20;

    /** @var cloud_api_client|null Client; the configured one when null. */
    private ?cloud_api_client $client;

    /** @var int Largest file accepted, in bytes. */
    private int $maxbytes;

    /**
     * Constructor.
     *
     * @param cloud_api_client|null $client Client; the configured one when null.
     * @param int $maxbytes Largest file accepted, in bytes (16 MB, the media limit of WhatsApp).
     */
    public function __construct(?cloud_api_client $client = null, int $maxbytes = cloud_api_client::MAX_MEDIA_BYTES) {
        $this->client = $client;
        $this->maxbytes = $maxbytes;
    }

    #[\Override]
    public function with_file(media_ref $ref, callable $callback): array {
        if ($ref->id === '') {
            throw new \moodle_exception('emptyresponse', 'aiprovider_datacurso');
        }
        \core_php_time_limit::raise(self::time_limit());
        $path = make_temp_directory(self::TEMP_DIR) . '/' . random_string(32);
        try {
            $this->download($ref, $path);
            return $callback($path);
        } finally {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Seconds the media phase may take in the worst case: every download request, the slowest reading (a single request
     * of a service, or a PDF read page by page within the AI timeout setting) and a margin. The conversation engine
     * raises the limit again for itself afterwards.
     *
     * @return int
     */
    public static function time_limit(): int {
        return self::DOWNLOAD_REQUESTS * self::DOWNLOAD_REQUEST_SECONDS
            + max(self::READ_SECONDS, client::configured_timeout())
            + self::TIME_LIMIT_MARGIN;
    }

    /**
     * Ask for the address of the media, check it and download it, renewing an expired address once.
     *
     * @param media_ref $ref Attachment.
     * @param string $path Temporary file.
     * @throws \moodle_exception The error of the service of the kind for a type or size refused, emptyresponse
     *     (aiprovider_datacurso) when the file cannot be downloaded.
     */
    private function download(media_ref $ref, string $path): void {
        $client = $this->client ?? cloud_api_client::instance();
        try {
            $url = $this->checked_url($client, $ref);
            try {
                $client->download_media($url, $path, $this->maxbytes);
            } catch (cloud_api_exception $e) {
                if ($e->get_http_code() !== self::HTTP_NOT_FOUND) {
                    throw $e;
                }
                // The address expired before it was downloaded: a new one is asked once.
                $client->download_media($this->checked_url($client, $ref), $path, $this->maxbytes);
            }
        } catch (cloud_api_exception $e) {
            if (self::is_too_large($e)) {
                throw $this->too_large();
            }
            \local_jedomi_log('WhatsApp media not downloaded', ['kind' => $ref->kind,
                'http' => $e->get_http_code(), 'code' => $e->get_meta_code()]);
            throw new \moodle_exception('emptyresponse', 'aiprovider_datacurso');
        } catch (\coding_exception $e) {
            // A media id that is not numeric, or an address that is not an https address of Meta.
            \local_jedomi_log('WhatsApp media not downloaded: invalid media id or address', ['kind' => $ref->kind]);
            throw new \moodle_exception('emptyresponse', 'aiprovider_datacurso');
        }
    }

    /**
     * Download address of the media, after its type and size are checked.
     *
     * @param cloud_api_client $client Client.
     * @param media_ref $ref Attachment.
     * @return string
     * @throws \moodle_exception The error of the service of the kind for a type or size refused.
     * @throws cloud_api_exception
     */
    private function checked_url(cloud_api_client $client, media_ref $ref): string {
        $info = $client->get_media_url($ref->id);
        $mimetype = inbound_parser::mimetype($info['mime_type'] ?? '');
        if ($mimetype === '') {
            $mimetype = inbound_parser::mimetype($ref->mimetype);
        }
        if (!in_array($mimetype, self::types_of($ref), true)) {
            throw $this->unsupported($ref);
        }
        if ((int) ($info['file_size'] ?? 0) > $this->maxbytes) {
            throw $this->too_large();
        }
        return (string) $info['url'];
    }

    /**
     * Types the service that reads an attachment accepts, as the media pipeline chooses it by kind and file name.
     *
     * @param media_ref $ref Attachment.
     * @return string[]
     */
    private static function types_of(media_ref $ref): array {
        if ($ref->kind === media_ref::AUDIO) {
            return self::AUDIO_TYPES;
        }
        if ($ref->kind === media_ref::IMAGE) {
            return self::IMAGE_TYPES;
        }
        return self::is_office($ref) ? self::OFFICE_TYPES : self::VISION_DOCUMENT_TYPES;
    }

    /**
     * Whether a document goes to the extraction service (Word or PowerPoint by its name).
     *
     * @param media_ref $ref Attachment.
     * @return bool
     */
    private static function is_office(media_ref $ref): bool {
        return $ref->kind === media_ref::DOCUMENT
            && file_extraction_service::is_office_document(clean_param($ref->filename, PARAM_FILE));
    }

    /**
     * Failure for a type the service of the attachment cannot read, with that service's own message.
     *
     * @param media_ref $ref Attachment.
     * @return \moodle_exception
     */
    private function unsupported(media_ref $ref): \moodle_exception {
        if ($ref->kind === media_ref::AUDIO) {
            return new \moodle_exception('audio_error_unsupported', 'local_jedomi');
        }
        return new \moodle_exception(self::is_office($ref) ? 'file_error_unsupported' : 'image_error_unsupported', 'local_jedomi');
    }

    /**
     * Failure for a file over the limit, with the limit in MB.
     *
     * @return \moodle_exception
     */
    private function too_large(): \moodle_exception {
        return new \moodle_exception('file_error_upload_too_large', 'local_jedomi', '', intdiv($this->maxbytes, 1048576));
    }

    /**
     * Whether the client stopped a download because the file was larger than the limit.
     *
     * @param cloud_api_exception $e Failure.
     * @return bool
     */
    private static function is_too_large(cloud_api_exception $e): bool {
        // The client stops the download itself, after a successful answer, with its own text.
        $code = $e->get_http_code();
        return ($code === 0 || ($code >= 200 && $code < 300)) && str_contains($e->getMessage(), ' file larger than ');
    }
}
