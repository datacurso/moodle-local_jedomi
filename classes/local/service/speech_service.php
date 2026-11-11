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
use local_jedomi\ai\model_settings;
use local_jedomi\ai\provider_api;
use local_jedomi\proxy\spt_validator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../lib.php');

/**
 * Reads a sentence of an answer aloud for the voice conversation, through POST /jedomi/v1/audio/speech.
 *
 * Every request to speech.php goes through {@see handle_request()}, which applies the same protections
 * as the chat proxy (session, session key, signed permissions, plugin and chat access) plus the voice
 * conversation switch, a length limit and a per-user rate limit, and calls the AI service through the
 * Datacurso provider only when all of them pass.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class speech_service {
    /** @var string AI service endpoint. */
    public const ENDPOINT = '/jedomi/v1/audio/speech';

    /** @var int Longest text read aloud in one request, in characters; the AI service has the same limit. */
    public const MAX_CHARS = 1000;

    /** @var int Requests a user can make in one minute. The sidebar asks for one per sentence. */
    public const RATE_LIMIT_PER_MINUTE = 30;

    /** @var string Content type of the audio answer. */
    public const AUDIO_TYPE = 'audio/mpeg';

    /** @var int Total request timeout, in seconds. */
    private const TIMEOUT = 60;

    /** @var provider_api|null Provider client used instead of Jedomi's own in PHPUnit tests. */
    private static ?provider_api $testapi = null;

    /**
     * Replace the provider client used when a caller passes none. Only for PHPUnit tests.
     *
     * @param provider_api|null $api Client to use; null to restore Jedomi's own.
     * @return void
     */
    public static function set_api_for_testing(?provider_api $api): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('speech_service::set_api_for_testing() is only for PHPUnit tests');
        }
        self::$testapi = $api;
    }

    /**
     * Answer a request of speech.php: the audio of the text, or a JSON error without calling the AI.
     *
     * @param array $input Decoded request: text, sesskey and spt (the signed permissions of the sidebar).
     * @param provider_api|null $api Provider client; null for Jedomi's own.
     * @return array Keys status (HTTP status), contenttype and body.
     */
    public static function handle_request(array $input, ?provider_api $api = null): array {
        global $USER;

        if (!isloggedin() || isguestuser()) {
            return self::error(403, 'guest_not_allowed', get_string('chat_error_guest', 'local_jedomi'));
        }
        if (!confirm_sesskey((string) ($input['sesskey'] ?? ''))) {
            return self::error(403, 'invalid_sesskey', get_string('invalidsesskey', 'error'));
        }
        $spt = $input['spt'] ?? null;
        if (!is_array($spt)) {
            return self::error(403, 'invalid_spt', get_string('invalid_session', 'local_jedomi'));
        }
        try {
            spt_validator::validate($spt);
        } catch (\moodle_exception $e) {
            return self::error(403, 'invalid_spt', $e->getMessage());
        }

        $disabled = \local_jedomi_plugin_disabled_error();
        if ($disabled !== null) {
            return self::error(403, $disabled['code'], $disabled['message']);
        }
        $denied = \local_jedomi_chat_access_error((int) ($spt['course_id'] ?? 0));
        if ($denied !== null) {
            return self::error(403, $denied['code'], $denied['message']);
        }
        if (!model_settings::is_voice_enabled()) {
            return self::error(403, 'voice_disabled', get_string('speech_error_disabled', 'local_jedomi'));
        }

        $text = is_string($input['text'] ?? null) ? trim($input['text']) : '';
        if ($text === '') {
            return self::error(400, 'speech_empty', get_string('speech_error_empty', 'local_jedomi'));
        }
        if (\core_text::strlen($text) > self::MAX_CHARS) {
            return self::error(413, 'speech_too_long', get_string('speech_error_too_long', 'local_jedomi', self::MAX_CHARS));
        }
        if (!self::consume_rate_limit((int) $USER->id)) {
            return self::error(429, 'speech_rate_limited', get_string('speech_error_rate_limited', 'local_jedomi'));
        }

        try {
            $audio = self::synthesise((int) $USER->id, $text, $api);
        } catch (\Throwable $e) {
            $error = error_message::from_exception($e);
            return self::error(502, $error['code'], $error['message']);
        }
        return ['status' => 200, 'contenttype' => self::AUDIO_TYPE, 'body' => $audio];
    }

    /**
     * Read a text aloud with the speech model and voice of the settings.
     *
     * @param int $userid User the answer is read for.
     * @param string $text Text to read, already checked.
     * @param provider_api|null $api Provider client; null for Jedomi's own.
     * @return string MP3 audio.
     * @throws \moodle_exception Any provider error, or ai_error_invalidanswer when the answer has no audio.
     */
    public static function synthesise(int $userid, string $text, ?provider_api $api = null): string {
        $model = model_settings::get_speech_model();
        $start = microtime(true);
        try {
            $audio = ($api ?? self::$testapi ?? new provider_api(self::TIMEOUT))->request_audio(self::ENDPOINT, [
                'model' => $model,
                'input' => $text,
                'voice' => model_settings::get_speech_voice(),
                'userid' => $userid,
            ]);
        } catch (\moodle_exception $e) {
            \local_jedomi_log('SPEECH_ERROR', [
                'code' => $e->errorcode,
                'component' => $e->module,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);
            throw $e;
        }
        \local_jedomi_log('SPEECH_RESPONSE', [
            'model' => $model,
            'chars' => \core_text::strlen($text),
            'bytes' => strlen($audio),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
        return $audio;
    }

    /**
     * Count a request of a user in the current minute.
     *
     * @param int $userid User.
     * @return bool False when the user already reached the limit of the minute; the request is then not counted.
     */
    private static function consume_rate_limit(int $userid): bool {
        $cache = \cache::make('local_jedomi', 'speech_rate');
        $key = $userid . '_' . intdiv(time(), 60);
        $count = (int) $cache->get($key);
        if ($count >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }
        $cache->set($key, $count + 1);
        return true;
    }

    /**
     * JSON error answer, with a message ready to show.
     *
     * @param int $status HTTP status.
     * @param string $code Error code.
     * @param string $message Message for the user.
     * @return array Keys status, contenttype and body.
     */
    private static function error(int $status, string $code, string $message): array {
        return [
            'status' => $status,
            'contenttype' => 'application/json; charset=utf-8',
            'body' => json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE),
        ];
    }
}
