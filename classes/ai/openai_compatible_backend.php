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

/**
 * Template for backends speaking the OpenAI chat completions protocol over SSE.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\ai;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Builds the request, posts it, and normalises the streamed answer.
 *
 * Subclasses only decide where to post, which headers to send, how to
 * extend the payload and how to translate HTTP errors into notices.
 */
abstract class openai_compatible_backend implements backend {
    /** @var int Default total request timeout in seconds. */
    public const DEFAULT_TIMEOUT = 180;

    /** @var int Bytes of the response kept in error previews and logs. */
    private const PREVIEW_BYTES = 500;

    /** @var transport HTTP transport. */
    protected transport $transport;

    /**
     * Constructor.
     *
     * @param transport|null $transport Defaults to the cURL transport.
     */
    public function __construct(?transport $transport = null) {
        $this->transport = $transport ?? new curl_transport();
    }

    /**
     * Absolute URL of the chat completions endpoint.
     *
     * @return string
     */
    abstract protected function get_endpoint_url(): string;

    /**
     * Request headers, including Content-Type and authentication.
     *
     * @return string[] Header lines in "Name: value" form.
     */
    abstract protected function get_headers(): array;

    /**
     * Backend-specific payload adjustments (extra fields, model override, ...).
     *
     * @param array $payload Base OpenAI payload.
     * @param array $options Options passed to complete().
     * @return array Final payload.
     */
    abstract protected function extend_payload(array $payload, array $options): array;

    /**
     * Translate a non-200 response into a user-facing notice when it has a known meaning.
     *
     * @param int $code HTTP status code.
     * @param string $body Response body.
     * @return array|null A notice result, or null to fall through to the generic error.
     */
    abstract protected function map_http_error(int $code, string $body): ?array;

    /**
     * Run one completion round.
     *
     * @param string $model Model name.
     * @param array $messages OpenAI chat messages.
     * @param array $tools OpenAI tool definitions.
     * @param array $options See backend::complete().
     * @return array Normalised result.
     */
    public function complete(string $model, array $messages, array $tools = [], array $options = []): array {
        // Resolving the endpoint may reach out to a third party (e.g. the Datacurso shop) and throw.
        // Normalise that before anything is logged or sent so callers never see an uncaught exception.
        try {
            $url = $this->get_endpoint_url();
            $headers = $this->get_headers();
        } catch (\Throwable $e) {
            \local_jedomi_log('AI_BACKEND_UNAVAILABLE', [
                'backend' => $this->get_key(),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return [
                'type' => self::TYPE_ERROR,
                'error' => 'backend_unavailable',
                'http_code' => 0,
                'curl_errno' => 0,
                'curl_error' => $e->getMessage(),
                'preview' => '',
            ];
        }

        $payload = $this->build_payload($model, $messages, $tools, $options);
        $payload = $this->extend_payload($payload, $options);
        $this->log_request($payload, $messages);

        $timeout = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $response = $this->transport->post(
            $url,
            $headers,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $timeout
        );

        $code = (int) $response['code'];
        $body = (string) $response['body'];
        $errno = (int) $response['errno'];
        $curlerror = (string) $response['error'];

        if ($code !== 200 && $errno === 0) {
            $notice = $this->map_http_error($code, $body);
            if ($notice !== null) {
                return $notice;
            }
        }

        if ($code !== 200 || $body === '' || $errno !== 0) {
            return $this->error_result('ai_api_error', $code, $errno, $curlerror, $body);
        }

        $parsed = sse_parser::parse($body);
        if ($parsed['error'] !== null) {
            $result = $this->error_result('ai_stream_error', $code, $errno, $curlerror, $body);
            $result['message'] = (string) $parsed['error']['message'];
            return $result;
        }

        return $this->normalise($parsed, $body);
    }

    /**
     * Build the base OpenAI payload shared by every backend.
     *
     * @param string $model
     * @param array $messages
     * @param array $tools
     * @param array $options
     * @return array
     */
    protected function build_payload(string $model, array $messages, array $tools, array $options): array {
        $payload = [];
        if ($model !== '') {
            $payload['model'] = $model;
        }
        $payload['messages'] = $messages;
        $payload['stream'] = true;
        $payload['max_tokens'] = (int) ($options['max_tokens'] ?? client::DEFAULT_MAX_TOKENS);
        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        return $payload;
    }

    /**
     * Log a preview of the outgoing request (last five messages, truncated).
     *
     * @param array $payload Final payload.
     * @param array $messages Messages sent.
     */
    protected function log_request(array $payload, array $messages): void {
        $contextpreview = array_map(static fn(array $msg): array => [
            'role' => $msg['role'] ?? '?',
            'content_preview' => isset($msg['content']) && is_string($msg['content'])
                ? mb_substr($msg['content'], 0, 150)
                : (isset($msg['tool_calls']) ? 'tool_calls:' . count($msg['tool_calls']) : ''),
            'tool_call_id' => $msg['tool_call_id'] ?? null,
        ], array_slice($messages, -5));

        \local_jedomi_log('AI_API_REQUEST', [
            'backend' => $this->get_key(),
            'model' => $payload['model'] ?? '',
            'messages_count' => count($messages),
            'last_messages' => $contextpreview,
        ]);
    }

    /**
     * Build and log the generic error result.
     *
     * @param string $error Error code.
     * @param int $code HTTP status.
     * @param int $errno cURL errno.
     * @param string $curlerror cURL message.
     * @param string $body Response body.
     * @return array
     */
    private function error_result(string $error, int $code, int $errno, string $curlerror, string $body): array {
        $preview = substr($body, 0, self::PREVIEW_BYTES);
        \local_jedomi_log('AI_API_ERROR', [
            'backend' => $this->get_key(),
            'error' => $error,
            'http_code' => $code,
            'curl_errno' => $errno,
            'curl_error' => $curlerror,
            'preview' => $preview,
        ]);
        return [
            'type' => self::TYPE_ERROR,
            'error' => $error,
            'http_code' => $code,
            'curl_errno' => $errno,
            'curl_error' => $curlerror,
            'preview' => $preview,
        ];
    }

    /**
     * Turn the parsed stream into a text or tool_calls result.
     *
     * @param array $parsed Output of sse_parser::parse().
     * @param string $body Raw body, for diagnostics.
     * @return array
     */
    private function normalise(array $parsed, string $body): array {
        if (!empty($parsed['tool_calls']) || $parsed['finish_reason'] === 'tool_calls') {
            $toolcalls = $parsed['tool_calls'];
            foreach ($toolcalls as &$tc) {
                if (empty($tc['id'])) {
                    $tc['id'] = 'call_' . substr(md5($tc['function']['name'] . microtime()), 0, 12);
                }
            }
            unset($tc);

            $toolnames = array_map(static fn(array $tc): string => $tc['function']['name'], $toolcalls);
            \local_jedomi_log('AI_API_RESPONSE_TOOL_CALLS', ['function_count' => count($toolcalls), 'functions' => $toolnames]);

            return ['type' => self::TYPE_TOOL_CALLS, 'tool_calls' => $toolcalls];
        }

        // Prefer visible content; fall back to reasoning (thinking tokens).
        $text = $parsed['text'] !== '' ? $parsed['text'] : $parsed['reasoning'];
        if ($text === '') {
            \local_jedomi_log('AI_API_RESPONSE_EMPTY', ['buffer_preview' => substr($body, 0, self::PREVIEW_BYTES)]);
        }
        \local_jedomi_log('AI_API_RESPONSE_TEXT', ['length' => mb_strlen($text), 'preview' => mb_substr($text, 0, 200)]);

        return ['type' => self::TYPE_TEXT, 'content' => $text];
    }
}
