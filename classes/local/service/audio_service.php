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

use aiprovider_datacurso\httpclient\ai_services_api;
use local_jedomi\ai\model_settings;

/**
 * Transcribes audio with the audio model of Jedomi, through POST /jedomi/v1/audio/transcriptions.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audio_service {
    /** @var string AI service endpoint. */
    public const ENDPOINT = '/jedomi/v1/audio/transcriptions';

    /** @var string[] Audio extensions the service transcribes. */
    public const EXTENSIONS = ['ogg', 'oga', 'opus', 'webm', 'mp3', 'm4a', 'mp4', 'wav'];

    /** @var int Total request timeout, in seconds. */
    private const TIMEOUT = 120;

    /** @var array<int, array{0: string, 1: string}> Code and message string for each error status of the AI service. */
    private const STATUS_ERRORS = [
        413 => ['audio_too_long', 'audio_error_too_long'],
        415 => ['audio_unsupported', 'audio_error_unsupported'],
        422 => ['audio_unreadable', 'audio_error_unreadable'],
    ];

    /** @var array<string, array{0: string, 1: string}> Code and message string for each local error. */
    private const LOCAL_ERRORS = [
        'audio_error_disabled' => ['audio_disabled', 'audio_error_disabled'],
        'audio_error_unsupported' => ['audio_unsupported', 'audio_error_unsupported'],
        'audio_error_unreadable' => ['audio_unreadable', 'audio_error_unreadable'],
    ];

    /**
     * Transcribe an audio file on disk (a browser recording or upload, or a Telegram voice message).
     *
     * @param int $userid User the audio belongs to.
     * @param string $path Path of the file on disk.
     * @param string $filename File name; its extension decides the format.
     * @param ai_services_api|null $api Provider client; null for Jedomi's own.
     * @return array{text: string, model: string, duration_seconds: float}
     * @throws \moodle_exception audio_error_disabled, audio_error_unsupported, audio_error_unreadable
     *         (nothing was understood), or any provider error.
     */
    public static function transcribe_upload(int $userid, string $path, string $filename, ?ai_services_api $api = null): array {
        if (!model_settings::is_audio_enabled()) {
            throw new \moodle_exception('audio_error_disabled', 'local_jedomi');
        }
        $filename = clean_param($filename, PARAM_FILE);
        if ($filename === '' || !self::is_supported($filename) || !is_readable($path)) {
            throw new \moodle_exception('audio_error_unsupported', 'local_jedomi');
        }

        $response = provider_upload_service::send(
            $userid,
            $path,
            $filename,
            self::ENDPOINT,
            ['model' => model_settings::get_audio_model()],
            self::TIMEOUT,
            $api
        );

        $text = trim((string) ($response['text'] ?? ''));
        if ($text === '') {
            throw new \moodle_exception('audio_error_unreadable', 'local_jedomi');
        }
        return [
            'text' => $text,
            'model' => (string) ($response['model'] ?? ''),
            'duration_seconds' => (float) ($response['duration_seconds'] ?? 0),
        ];
    }

    /**
     * Whether a file name has an audio extension the service transcribes.
     *
     * @param string $filename File name.
     * @return bool
     */
    public static function is_supported(string $filename): bool {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Code and user message for a failed transcription; internal details never reach the message.
     *
     * @param \Throwable $e Failure.
     * @return array{code: string, message: string}
     */
    public static function error_for(\Throwable $e): array {
        return provider_upload_service::error_for($e, self::STATUS_ERRORS, self::LOCAL_ERRORS);
    }
}
