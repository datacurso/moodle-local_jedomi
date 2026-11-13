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

namespace local_jedomi\ai;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * HTTP client of the custom OpenAI-compatible AI service: GET /v1/models, POST /v1/chat/completions,
 * /v1/audio/transcriptions and /v1/audio/speech, with the key as a Bearer token.
 *
 * Nothing goes through the Datacurso provider: no licence, no Token Manager credits, no consumption history and no
 * Datacurso rate limit. The Moodle user id and site are never sent. Every request follows {@see custom_endpoint}
 * (address policy, cURL security of the site, no redirects, short connection timeout). Errors never carry the key,
 * the address or the body of the answer.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_api {
    /** @var \Closure|null Builds the transport in PHPUnit tests: fn(bool $bypass): \curl. */
    private static ?\Closure $testcurlfactory = null;

    /** @var string Base address. */
    private string $baseurl;

    /** @var string API key; empty for none. */
    private string $apikey;

    /** @var bool Whether "Allow HTTP for local models" is on. */
    private bool $allowhttp;

    /**
     * Constructor.
     *
     * @param string $baseurl Base address of the service.
     * @param string $apikey API key; empty to send no Authorization header.
     * @param bool $allowhttp Whether "Allow HTTP for local models" is on.
     */
    public function __construct(string $baseurl, string $apikey, bool $allowhttp) {
        $this->baseurl = custom_endpoint::normalise($baseurl);
        $this->apikey = trim($apikey);
        $this->allowhttp = $allowhttp;
    }

    /**
     * Client of the saved custom service.
     *
     * @return custom_api
     */
    public static function from_settings(): custom_api {
        return new custom_api(ai_service::get_custom_url(), ai_service::get_custom_key(), ai_service::allows_custom_http());
    }

    /**
     * Replace the transport. Only for PHPUnit tests.
     *
     * @param \Closure|null $factory fn(bool $bypass): \curl, where $bypass tells whether the site cURL security is
     *        skipped; null to restore Moodle's own.
     * @return void
     */
    public static function set_curl_factory_for_testing(?\Closure $factory): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('custom_api::set_curl_factory_for_testing() is only for PHPUnit tests');
        }
        self::$testcurlfactory = $factory;
    }

    /**
     * Ids of the models the service lists (GET /v1/models), which proves it is OpenAI-compatible.
     *
     * @param int $timeout Total request timeout, in seconds.
     * @return string[]
     * @throws \moodle_exception custom_error_url, custom_error_unreachable, custom_error_http, or ai_error_invalidanswer
     *         when the answer is not an OpenAI model list.
     */
    public function list_models(int $timeout): array {
        [$status, $body] = $this->send('GET', '/models', null, [], $timeout);
        self::require_success($status);
        $answer = json_decode($body, true);
        if (!is_array($answer) || ($answer['object'] ?? null) !== 'list' || !is_array($answer['data'] ?? null)) {
            throw new \moodle_exception('ai_error_invalidanswer', 'local_jedomi');
        }
        $ids = [];
        foreach ($answer['data'] as $model) {
            if (!is_array($model) || !is_string($model['id'] ?? null) || $model['id'] === '') {
                throw new \moodle_exception('ai_error_invalidanswer', 'local_jedomi');
            }
            $ids[] = $model['id'];
        }
        if (empty($ids)) {
            throw new \moodle_exception('ai_error_invalidanswer', 'local_jedomi');
        }
        return $ids;
    }

    /**
     * Send a chat completion request and return the decoded answer.
     *
     * @param array $payload OpenAI chat completion body; a userid in it is removed.
     * @param int $timeout Total request timeout, in seconds.
     * @return array
     * @throws \moodle_exception custom_error_*, ai_error_tools_unsupported when the model rejects the tools, or
     *         ai_error_invalidanswer when the answer is not JSON.
     */
    public function chat(array $payload, int $timeout): array {
        unset($payload['userid']);
        [$status, $body] = $this->send(
            'POST',
            '/chat/completions',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json'],
            $timeout
        );
        if (!empty($payload['tools']) && in_array($status, [400, 422], true) && stripos($body, 'tool') !== false) {
            // Servers such as vLLM and Ollama answer 400 when the model cannot call tools; Jedomi needs them.
            throw new \moodle_exception('ai_error_tools_unsupported', 'local_jedomi');
        }
        self::require_success($status);
        return self::decode($body);
    }

    /**
     * Transcribe an audio file (multipart POST /v1/audio/transcriptions).
     *
     * @param string $path Path of the file on disk.
     * @param string $filename File name, already clean.
     * @param array $fields Form fields: model and, when known, language.
     * @param int $timeout Total request timeout, in seconds.
     * @return array Decoded answer, with the text.
     * @throws \moodle_exception custom_error_* or ai_error_invalidanswer.
     */
    public function transcribe(string $path, string $filename, array $fields, int $timeout): array {
        $params = ['file' => new \CURLFile($path, '', $filename)] + array_filter(
            $fields,
            static fn($value): bool => $value !== null && $value !== ''
        );
        [$status, $body] = $this->send('POST', '/audio/transcriptions', $params, [], $timeout);
        self::require_success($status);
        return self::decode($body);
    }

    /**
     * Read a text aloud (POST /v1/audio/speech) and return the MP3 audio.
     *
     * @param array $body Model, input and voice.
     * @param int $timeout Total request timeout, in seconds.
     * @return string Audio bytes.
     * @throws \moodle_exception custom_error_* or ai_error_invalidanswer when the answer carries no audio.
     */
    public function speech(array $body, int $timeout): string {
        $body['response_format'] = 'mp3';
        [$status, $audio] = $this->send(
            'POST',
            '/audio/speech',
            json_encode($body, JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json'],
            $timeout
        );
        self::require_success($status);
        json_decode($audio);
        if ($audio === '' || json_last_error() === JSON_ERROR_NONE) {
            throw new \moodle_exception('ai_error_invalidanswer', 'local_jedomi');
        }
        return $audio;
    }

    /**
     * Send a request that passes the address policy and return its status and body.
     *
     * @param string $method GET or POST.
     * @param string $route Route under /v1.
     * @param string|array|null $params Raw body, multipart fields, or null for none.
     * @param string[] $headers Extra header lines.
     * @param int $timeout Total request timeout, in seconds.
     * @return array HTTP status and body.
     * @throws \moodle_exception custom_error_url when the address is refused (nothing is sent), or
     *         custom_error_unreachable when there is no answer.
     */
    private function send(string $method, string $route, string|array|null $params, array $headers, int $timeout): array {
        $url = custom_endpoint::url($this->baseurl, $route);
        $refused = custom_endpoint::check($url, $this->allowhttp);
        if ($refused !== null) {
            throw new \moodle_exception('custom_error_url', 'local_jedomi', '', $refused);
        }

        // The cURL security of the site is skipped only for a request pinned to a checked local address.
        $options = custom_endpoint::curl_options($url, $this->allowhttp, $timeout);
        $bypass = isset($options['CURLOPT_RESOLVE']);
        $curl = self::$testcurlfactory !== null
            ? (self::$testcurlfactory)($bypass)
            : new \curl(['ignoresecurity' => $bypass]);
        if ($this->apikey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apikey;
        }
        $options['CURLOPT_HTTPHEADER'] = $headers;

        $body = $method === 'GET' ? $curl->get($url, [], $options) : $curl->post($url, $params ?? '', $options);
        $status = (int) ($curl->get_info()['http_code'] ?? 0);
        if (!empty($curl->errno) || ($status === 0 && !empty($curl->error))) {
            // The libcurl text may hold the host: only its number is kept.
            throw new \moodle_exception('custom_error_unreachable', 'local_jedomi', '', (int) $curl->errno);
        }
        if ($status === 0) {
            throw new \moodle_exception('custom_error_unreachable', 'local_jedomi', '', 0);
        }
        return [$status, (string) $body];
    }

    /**
     * Stop on an error or redirect status; the body is never kept, it may hold internals of the service.
     *
     * @param int $status HTTP status.
     * @return void
     * @throws \moodle_exception custom_error_http with the status.
     */
    private static function require_success(int $status): void {
        if ($status < 200 || $status >= 300) {
            throw new \moodle_exception('custom_error_http', 'local_jedomi', '', $status);
        }
    }

    /**
     * Decode a JSON object answer.
     *
     * @param string $body Body.
     * @return array
     * @throws \moodle_exception ai_error_invalidanswer when it is not a JSON object.
     */
    private static function decode(string $body): array {
        $answer = json_decode($body, true);
        if (!is_array($answer)) {
            throw new \moodle_exception('ai_error_invalidanswer', 'local_jedomi');
        }
        return $answer;
    }
}
