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
 * Calls POST /jedomi/v1/chat/completions on plugins-ai through the Datacurso provider client, which
 * adds the license key, rate-limit headers, the pseudonymised user id, site id, timezone and language.
 * Calls are not streamed: the provider client reads the whole answer.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var string Chat completions route of plugins-ai for Jedomi. */
    public const PATH = '/jedomi/v1/chat/completions';

    /** @var int Default total request timeout, in seconds. */
    public const DEFAULT_TIMEOUT = 180;

    /** @var client|null Replacement used by tests. */
    private static ?client $testinstance = null;

    /** @var \Closure Builds the provider client for a given timeout: fn(int $timeout): provider_api. */
    private \Closure $apifactory;

    /**
     * Constructor.
     *
     * @param \Closure|null $apifactory Builds the provider client for a timeout; defaults to {@see provider_api}.
     */
    public function __construct(?\Closure $apifactory = null) {
        $this->apifactory = $apifactory ?? static fn(int $timeout): provider_api => new provider_api($timeout);
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
     * @param int|null $timeout Total request timeout in seconds; null for {@see DEFAULT_TIMEOUT}.
     * @param float|null $temperature Sampling temperature; null for the model default.
     * @return array{content: string, tool_calls: array, finish_reason: string, reasoning: string}
     * @throws \moodle_exception Provider errors (component aiprovider_datacurso) or an answer without choices.
     */
    public function complete(
        array $messages,
        int $userid,
        array $tools = [],
        ?int $timeout = null,
        ?float $temperature = null
    ): array {
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
            'model' => $model,
            'messages_count' => count($messages),
            'tools_count' => count($tools),
        ]);

        $start = microtime(true);
        try {
            $api = ($this->apifactory)($timeout ?? self::DEFAULT_TIMEOUT);
            $response = $api->request('POST', self::PATH, $payload);
        } catch (\moodle_exception $e) {
            \local_jedomi_log('AI_ERROR', [
                'code' => $e->errorcode,
                'component' => $e->module,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);
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
