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

use local_jedomi\ai\ai_service;
use local_jedomi\ai\custom_api;
use local_jedomi\ai\error_message;
use local_jedomi\ai\model_settings;
use local_jedomi\ai\provider_api;
use local_jedomi\proxy\spt_validator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../lib.php');

/**
 * Reads a sentence of an answer aloud for the voice conversation, through POST /jedomi/v1/audio/speech of the
 * Datacurso service, or POST {url}/v1/audio/speech of the custom service.
 *
 * Every request to speech.php goes through {@see handle_request()}, which applies the same protections
 * as the chat proxy (session, session key, signed permissions, plugin and chat access) plus the voice
 * conversation switch, a length limit and a per-user rate limit, and calls the AI service through the
 * Datacurso provider only when all of them pass.
 *
 * The text the browser sends is the answer shown in the chat, which carries the real personal data. Before it
 * leaves the site it is anonymised on the server with the level of the Privacy tab, as the data the chat sends to
 * the AI ({@see self::anonymise_for_speech()}): e-mails and names of site users are replaced at every level, and
 * codes mixing letters and digits too at the Strict level; plain numbers such as grades are kept, as in the chat.
 * The placeholders are not read as raw tokens: each one is spoken as a plain word in the user's language
 * ("a person", "an email address", "an identifier", "some text"), and consecutive names of one person become a
 * single word. The answer shown in the chat is never changed; only the spoken text is anonymised.
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

    /** @var int Longest wait for the lock of the rate limit counter of a user, in seconds. */
    private const RATE_LOCK_TIMEOUT = 2;

    /** @var string[] Kinds of placeholder of the anonymiser, each spoken with a string "speech_placeholder_<kind>". */
    private const PLACEHOLDER_KINDS = ['person', 'email', 'id', 'text'];

    /** @var provider_api|null Provider client used instead of Jedomi's own in PHPUnit tests. */
    private static ?provider_api $testapi = null;

    /** @var \core\lock\lock_factory|null Lock factory of the rate limit used instead of Moodle's own in PHPUnit tests. */
    private static ?\core\lock\lock_factory $testlockfactory = null;

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
     * Replace the lock factory of the rate limit. Only for PHPUnit tests.
     *
     * @param \core\lock\lock_factory|null $factory Factory to use; null to restore Moodle's own.
     * @return void
     */
    public static function set_lock_factory_for_testing(?\core\lock\lock_factory $factory): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('speech_service::set_lock_factory_for_testing() is only for PHPUnit tests');
        }
        self::$testlockfactory = $factory;
    }

    /**
     * JSON refusal of speech.php when its login check fails, in the shape the sidebar reads.
     *
     * @param \moodle_exception $e Failure of require_login().
     * @return array Keys status (403), contenttype and body.
     */
    public static function login_error(\moodle_exception $e): array {
        return self::error(403, $e->errorcode, $e->getMessage());
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
        // An empty key is refused here: confirm_sesskey('') would look for a sesskey parameter and throw instead.
        $sesskey = (string) ($input['sesskey'] ?? '');
        if ($sesskey === '' || !confirm_sesskey($sesskey)) {
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
        // The signed course is checked again as the chat does: the user must still be able to enter it, it must be
        // available and the user must have a visible role. The speech request carries no course of the page.
        $denied = \local_jedomi_signed_course_error((int) ($spt['course_id'] ?? 0), null);
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
     * Anonymise a text to read aloud with the level of the Privacy tab, speaking the placeholders as plain words.
     *
     * @param string $text Text shown in the chat.
     * @param string|null $level Anonymisation level; null for the one of the Privacy tab.
     * @return string Text without the personal data, at most {@see self::MAX_CHARS} characters long.
     */
    public static function anonymise_for_speech(string $text, ?string $level = null): string {
        $anonymised = (new \local_jedomi\local\anonymizer($level))->anonymize_text($text);
        $kinds = implode('|', self::PLACEHOLDER_KINDS);
        // A full name is replaced word by word: consecutive placeholders of one kind are spoken once.
        $spoken = (string) preg_replace_callback(
            '/\[(' . $kinds . ')_\d+\](?:\s+\[\1_\d+\])*/u',
            fn(array $m): string => get_string('speech_placeholder_' . $m[1], 'local_jedomi'),
            $anonymised
        );
        // The words may be longer than the values they replace; the AI service refuses longer texts.
        return \core_text::substr($spoken, 0, self::MAX_CHARS);
    }

    /**
     * Read a text aloud with the speech model and voice of the settings.
     *
     * The text is anonymised first with {@see self::anonymise_for_speech()}: the personal data hidden from the AI
     * by the Privacy tab never reaches the speech service either.
     *
     * @param int $userid User the answer is read for.
     * @param string $text Text to read, already checked.
     * @param provider_api|null $api Provider client of the Datacurso service; null for Jedomi's own.
     * @return string MP3 audio.
     * @throws \moodle_exception Any provider or custom service error, or ai_error_invalidanswer when the answer has no
     *         audio.
     */
    public static function synthesise(int $userid, string $text, ?provider_api $api = null): string {
        $model = model_settings::get_speech_model();
        $text = self::anonymise_for_speech($text);
        $start = microtime(true);
        $body = ['model' => $model, 'input' => $text, 'voice' => model_settings::get_speech_voice()];
        try {
            if (ai_service::is_custom()) {
                $audio = custom_api::from_settings()->speech($body, self::TIMEOUT);
            } else {
                $audio = ($api ?? self::$testapi ?? new provider_api(self::TIMEOUT))->request_audio(
                    self::ENDPOINT,
                    $body + ['userid' => $userid]
                );
            }
        } catch (\moodle_exception $e) {
            \local_jedomi_log('SPEECH_ERROR', [
                'code' => $e->errorcode,
                'component' => $e->module,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ] + error_message::log_detail($e));
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
     * speech.php releases the session lock, so the requests of a user can run in parallel: the counter is read and
     * written under a lock of the user, or two requests could read the same count and both pass the limit. When the
     * lock cannot be taken in time the request is refused, never let through.
     *
     * @param int $userid User.
     * @return bool False when the user already reached the limit of the minute, or the counter is busy; the request is
     *         then not counted.
     */
    private static function consume_rate_limit(int $userid): bool {
        $factory = self::$testlockfactory ?? \core\lock\lock_config::get_lock_factory('local_jedomi');
        $lock = $factory->get_lock('speech_rate_' . $userid, self::RATE_LOCK_TIMEOUT);
        if (!$lock) {
            return false;
        }
        try {
            $cache = \cache::make('local_jedomi', 'speech_rate');
            $key = $userid . '_' . intdiv(time(), 60);
            $count = (int) $cache->get($key);
            if ($count >= self::RATE_LIMIT_PER_MINUTE) {
                return false;
            }
            $cache->set($key, $count + 1);
            return true;
        } finally {
            $lock->release();
        }
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
