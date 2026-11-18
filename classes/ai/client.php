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

require_once(__DIR__ . '/../../lib.php');

/**
 * Single entry point for every Jedomi AI call (web chat, agents, preview, Telegram and CLI).
 *
 * The destination follows the service in use ({@see ai_service}). With the Datacurso service, it calls
 * POST /jedomi/v1/chat/completions on plugins-ai through the Datacurso provider client, which adds the license key,
 * rate-limit headers, the pseudonymised user id, site id, timezone and language. With the custom service, it calls
 * POST {url}/v1/chat/completions directly ({@see custom_api}), without the user id and outside the Datacurso credits,
 * consumption history and rate limit.
 * The messages are anonymised by the callers in the same way for both services.
 * Calls are not streamed: the client reads the whole answer.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var string Chat completions route of plugins-ai for Jedomi. */
    public const PATH = '/jedomi/v1/chat/completions';

    /** @var int Default total request timeout, in seconds: the default of the ai_timeout setting. */
    public const DEFAULT_TIMEOUT = 180;

    /** @var int Shortest AI request timeout of the ai_timeout setting, in seconds. */
    public const MIN_TIMEOUT = 10;

    /** @var int Longest AI request timeout of the ai_timeout setting, in seconds. */
    public const MAX_TIMEOUT = 600;

    /** @var client|null Replacement used by tests. */
    private static ?client $testinstance = null;

    /** @var \Closure Builds the provider client for a given timeout: fn(int $timeout): provider_api. */
    private \Closure $apifactory;

    /** @var \Closure Builds the custom service client: fn(): custom_api. */
    private \Closure $customfactory;

    /**
     * Constructor.
     *
     * @param \Closure|null $apifactory Builds the provider client for a timeout; defaults to {@see provider_api}.
     * @param \Closure|null $customfactory Builds the custom service client; defaults to {@see custom_api::from_settings()}.
     */
    public function __construct(?\Closure $apifactory = null, ?\Closure $customfactory = null) {
        $this->apifactory = $apifactory ?? static fn(int $timeout): provider_api => new provider_api($timeout);
        $this->customfactory = $customfactory ?? static fn(): custom_api => custom_api::from_settings();
    }

    /**
     * Client to use: the test replacement when one is set, otherwise a default client.
     *
     * @return client
     */
    public static function instance(): client {
        return self::$testinstance ?? new client();
    }

    /**
     * Replace the client returned by {@see instance()}. Only for PHPUnit tests.
     *
     * @param client|null $client Replacement, or null to restore the default.
     */
    public static function set_instance_for_testing(?client $client): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('client::set_instance_for_testing() is only for PHPUnit tests');
        }
        self::$testinstance = $client;
    }

    /**
     * Send a conversation and return the assistant answer.
     *
     * @param array $messages Conversation in OpenAI chat format.
     * @param int $userid Moodle user the call is made for (cron and Telegram have no session user).
     * @param array $tools OpenAI-format tool definitions; empty for none.
     * @param int|null $timeout Total request timeout in seconds; null for the AI request timeout of the settings
     *        ({@see self::configured_timeout()}), as the web chat and the CLI do.
     * @param float|null $temperature Sampling temperature; null for the model default.
     * @return array{content: string, tool_calls: array, finish_reason: string, reasoning: string}
     * @throws \moodle_exception Provider errors (component aiprovider_datacurso), custom service errors (custom_error_*,
     *         ai_error_tools_unsupported), or an answer without choices.
     */
    public function complete(
        array $messages,
        int $userid,
        array $tools = [],
        ?int $timeout = null,
        ?float $temperature = null
    ): array {
        $service = ai_service::get_active();
        $model = model_settings::get_model();
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => model_settings::get_max_tokens(),
            'userid' => $userid,
        ];
        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }

        \local_jedomi_log('AI_REQUEST', [
            'service' => $service,
            'model' => $model,
            'messages_count' => count($messages),
            'tools_count' => count($tools),
        ]);

        $timeout = $timeout ?? self::configured_timeout();
        $start = microtime(true);
        try {
            if ($service === ai_service::CUSTOM) {
                $response = ($this->customfactory)()->chat($payload, $timeout);
            } else {
                $api = ($this->apifactory)($timeout);
                $response = $api->request('POST', self::PATH, $payload);
            }
        } catch (\moodle_exception $e) {
            \local_jedomi_log('AI_ERROR', [
                'code' => $e->errorcode,
                'component' => $e->module,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ] + error_message::log_detail($e));
            throw $e;
        }

        $choice = $response['choices'][0] ?? null;
        if (!is_array($choice) || !isset($choice['message']) || !is_array($choice['message'])) {
            \local_jedomi_log('AI_ERROR', ['code' => 'ai_error_invalidanswer', 'component' => 'local_jedomi']);
            throw new \moodle_exception('ai_error_invalidanswer', 'local_jedomi');
        }

        $result = self::normalise_message($choice['message'], (string) ($choice['finish_reason'] ?? ''));
        \local_jedomi_log('AI_RESPONSE', [
            'finish_reason' => $result['finish_reason'],
            'content_length' => mb_strlen($result['content']),
            'tool_calls_count' => count($result['tool_calls']),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ]);
        return $result;
    }

    /**
     * AI request timeout of the settings (ai_timeout), shared by both services.
     *
     * Proxies in front of the site (Cloudflare closes a request after 100 seconds) and the max_execution_time of PHP
     * can end a request earlier.
     *
     * @return int Seconds, within {@see self::MIN_TIMEOUT} and {@see self::MAX_TIMEOUT}; {@see self::DEFAULT_TIMEOUT}
     *         when nothing valid is saved.
     */
    public static function configured_timeout(): int {
        $saved = trim((string) get_config('local_jedomi', 'ai_timeout'));
        if (!preg_match('/^\d+$/', $saved)) {
            return self::DEFAULT_TIMEOUT;
        }
        return min(self::MAX_TIMEOUT, max(self::MIN_TIMEOUT, (int) $saved));
    }

    /**
     * Normalise an assistant message: string content, and tool calls with an id and string arguments.
     *
     * @param array $message Assistant message from the answer.
     * @param string $finishreason Finish reason from the answer.
     * @return array{content: string, tool_calls: array, finish_reason: string, reasoning: string}
     */
    private static function normalise_message(array $message, string $finishreason): array {
        $toolcalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            if (!is_array($tc)) {
                continue;
            }
            $arguments = $tc['function']['arguments'] ?? '{}';
            if (!is_string($arguments)) {
                $arguments = json_encode($arguments, JSON_UNESCAPED_UNICODE);
            }
            $toolcalls[] = [
                'id' => !empty($tc['id']) ? (string) $tc['id'] : 'call_' . random_string(12),
                'type' => 'function',
                'function' => [
                    'name' => (string) ($tc['function']['name'] ?? ''),
                    'arguments' => $arguments,
                ],
            ];
        }

        return [
            'content' => is_string($message['content'] ?? null) ? $message['content'] : '',
            'tool_calls' => $toolcalls,
            'finish_reason' => $finishreason,
            'reasoning' => is_string($message['reasoning_content'] ?? null) ? $message['reasoning_content'] : '',
        ];
    }
}
