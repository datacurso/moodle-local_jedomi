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

namespace local_jedomi\local;

use local_jedomi\ai\client;
use local_jedomi\ai\error_message;
use local_jedomi\ai\model_settings;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * End-to-end check of the Jedomi AI route, run on purpose from cli/e2e_check.php.
 *
 * Uses the site configuration (provider license, configured model, region) and the real AI client
 * to hold one short conversation that needs the search_tables tool, runs that tool with the plugin's
 * tool runner, and asks for the final answer. Each step is reported as PASS or FAIL; a failure gives
 * its error code and the layer that returned it. The call consumes credits like any other message.
 * The license and the answer bodies are never written.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class e2e_check {
    /** @var string Step: the Datacurso AI provider is enabled. */
    public const STEP_PROVIDER = 'provider enabled';

    /** @var string Step: the first AI request was answered. */
    public const STEP_REQUEST = 'request answered';

    /** @var string Step: the model asked for the tool and the tool returned. */
    public const STEP_TOOL = 'tool call returned';

    /** @var string Step: the model gave a final answer after the tool result. */
    public const STEP_FINAL = 'final answer';

    /** @var string Step: the model the requests were made with. */
    public const STEP_MODEL = 'model used';

    /** @var string[] All the steps, in order. */
    public const STEPS = [self::STEP_PROVIDER, self::STEP_REQUEST, self::STEP_TOOL, self::STEP_FINAL, self::STEP_MODEL];

    /** @var string Layer: the Datacurso AI provider in this site. */
    public const LAYER_PROVIDER = 'provider';

    /** @var string Layer: plugins-ai, LiteLLM and the model behind them. */
    public const LAYER_AI_SERVICE = 'plugins-ai';

    /** @var string Layer: the Token Manager and the license shop (credit, limits, license). */
    public const LAYER_BILLING = 'token-manager';

    /** @var string Layer: the connection between this site and the AI service. */
    public const LAYER_NETWORK = 'network';

    /** @var string Layer: Jedomi itself (tool runner). */
    public const LAYER_JEDOMI = 'local_jedomi';

    /** @var string Tool the conversation needs: read-only and cheap. */
    public const TOOL = 'search_tables';

    /** @var int Longest excerpt of the final answer that is written. */
    private const EXCERPT_LENGTH = 60;

    /** @var client AI client. */
    private client $client;

    /** @var \Closure Runs a tool: fn(string $name, array $args): string. */
    private \Closure $toolrunner;

    /** @var \Closure Writes one output line: fn(string $line): void. */
    private \Closure $writer;

    /**
     * Constructor.
     *
     * @param client|null $client AI client; null for {@see client::instance()}.
     * @param \Closure|null $toolrunner Runs a tool; null for the web chat tool runner with the admin role.
     * @param \Closure|null $writer Writes one output line; null for standard output.
     */
    public function __construct(?client $client = null, ?\Closure $toolrunner = null, ?\Closure $writer = null) {
        $this->client = $client ?? client::instance();
        $this->toolrunner = $toolrunner
            ?? static fn(string $name, array $args): string => \local_jedomi\proxy\handler::run_tool($name, $args, 'admin');
        $this->writer = $writer ?? static function (string $line): void {
            echo $line . "\n";
        };
    }

    /**
     * Run the check.
     *
     * @param int $userid User the AI calls are made for.
     * @return bool True when every step passed.
     */
    public function run(int $userid): bool {
        $model = model_settings::get_model();
        $this->write('Jedomi end-to-end check: route ' . client::PATH . ', model ' . $model . ', region ' . $this->region());

        if (!\core\plugininfo\aiprovider::is_plugin_enabled('datacurso')) {
            return $this->fail(self::STEP_PROVIDER, 'code=' . error_message::CODE_PROVIDER_DISABLED
                . ' (aiprovider_datacurso/providerdisabled) layer=' . self::LAYER_PROVIDER);
        }
        $this->pass(self::STEP_PROVIDER, '');

        $tools = $this->tool_definitions();
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a test assistant for a Moodle site. Call the ' . self::TOOL
                    . ' tool exactly once with the query "user", then answer in one short sentence.',
            ],
            ['role' => 'user', 'content' => 'Which database table stores the users of this site?'],
        ];

        $answer = $this->complete(self::STEP_REQUEST, $messages, $userid, $tools);
        if ($answer === null) {
            return false;
        }
        $this->pass(self::STEP_REQUEST, 'finish_reason=' . $answer['finish_reason']);

        $toolcall = null;
        foreach ($answer['tool_calls'] as $tc) {
            if ($tc['function']['name'] === self::TOOL) {
                $toolcall = $tc;
                break;
            }
        }
        if ($toolcall === null) {
            return $this->fail(self::STEP_TOOL, 'code=no_tool_call (the model did not call ' . self::TOOL
                . ') layer=' . self::LAYER_AI_SERVICE);
        }

        $args = json_decode($toolcall['function']['arguments'], true);
        try {
            $toolresult = ($this->toolrunner)(self::TOOL, is_array($args) ? $args : []);
        } catch (\Throwable $e) {
            return $this->fail(self::STEP_TOOL, 'code=tool_error (' . get_class($e) . ') layer=' . self::LAYER_JEDOMI);
        }
        // The tools report failures as {"error": ...} instead of throwing; the text is not printed, it may hold SQL.
        $decoded = json_decode($toolresult, true);
        if (is_array($decoded) && array_key_exists('error', $decoded)) {
            return $this->fail(self::STEP_TOOL, 'code=tool_error (' . self::TOOL . ' returned an error) layer='
                . self::LAYER_JEDOMI);
        }
        $this->pass(self::STEP_TOOL, self::TOOL . ' returned ' . strlen($toolresult) . ' bytes');

        $messages[] = ['role' => 'assistant', 'content' => $answer['content'], 'tool_calls' => [$toolcall]];
        $messages[] = ['role' => 'tool', 'tool_call_id' => $toolcall['id'], 'content' => $toolresult];

        $final = $this->complete(self::STEP_FINAL, $messages, $userid, $tools);
        if ($final === null) {
            return false;
        }
        $content = trim($final['content']);
        if ($content === '') {
            return $this->fail(self::STEP_FINAL, 'code=empty_answer (finish_reason=' . $final['finish_reason']
                . ') layer=' . self::LAYER_AI_SERVICE);
        }
        $this->pass(self::STEP_FINAL, mb_strlen($content) . ' chars: "' . $this->excerpt($content) . '"');
        $this->pass(self::STEP_MODEL, $model);

        $this->write('All steps passed. The consumption is recorded as Jedomi in the provider history.');
        return true;
    }

    /**
     * Send the conversation, reporting a failure of the given step.
     *
     * @param string $step Step the call belongs to.
     * @param array $messages Conversation.
     * @param int $userid User the call is made for.
     * @param array $tools Tool definitions.
     * @return array|null The answer, or null when the call failed.
     */
    private function complete(string $step, array $messages, int $userid, array $tools): ?array {
        try {
            return $this->client->complete($messages, $userid, $tools);
        } catch (\Throwable $e) {
            $error = error_message::from_exception($e, 'en');
            $source = $e instanceof \moodle_exception ? $e->module . '/' . $e->errorcode : get_class($e);
            if ($e instanceof \moodle_exception && $e->errorcode === 'httperror') {
                $source .= ' HTTP ' . (int) $e->a;
            }
            $this->fail($step, 'code=' . $error['code'] . ' (' . $source . ') layer=' . self::layer_of($e));
            return null;
        }
    }

    /**
     * The layer that returned a failure.
     *
     * @param \Throwable $e Failure of the AI call.
     * @return string One of the LAYER_* constants.
     */
    public static function layer_of(\Throwable $e): string {
        if (!$e instanceof \moodle_exception) {
            return self::LAYER_JEDOMI;
        }
        if ($e->module === 'local_jedomi') {
            // An answer without choices: plugins-ai answered, but not in the expected format.
            return self::LAYER_AI_SERVICE;
        }
        switch ($e->errorcode) {
            case 'notenoughtokens':
            case 'error_ratelimit_exceeded':
            case 'license_not_allowed':
            case 'forbidden':
                return self::LAYER_BILLING;
            case 'curlerror':
            case 'emptyresponse':
                return self::LAYER_NETWORK;
            case 'httperror':
            case 'jsondecodeerror':
                return self::LAYER_AI_SERVICE;
            default:
                // Disabled provider, missing license, plugin version refused, region not resolved.
                return self::LAYER_PROVIDER;
        }
    }

    /**
     * Definitions of the tool the conversation needs.
     *
     * @return array
     */
    private function tool_definitions(): array {
        $tools = [];
        foreach (\local_jedomi_get_tool_definitions('admin') as $definition) {
            if (($definition['function']['name'] ?? '') === self::TOOL) {
                $tools[] = ['type' => 'function', 'function' => $definition['function']];
            }
        }
        return $tools;
    }

    /**
     * Region of the license, without asking the shop twice.
     *
     * @return string
     */
    private function region(): string {
        try {
            return \aiprovider_datacurso\local\license_region::is_european() ? 'EU' : 'standard';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * A short excerpt of a text, on one line.
     *
     * @param string $text Text.
     * @return string
     */
    private function excerpt(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return \core_text::strlen($text) > self::EXCERPT_LENGTH
            ? \core_text::substr($text, 0, self::EXCERPT_LENGTH) . '...'
            : $text;
    }

    /**
     * Report a passed step.
     *
     * @param string $step Step.
     * @param string $detail Detail; empty for none.
     */
    private function pass(string $step, string $detail): void {
        $this->write('PASS ' . $step . ($detail !== '' ? ': ' . $detail : ''));
    }

    /**
     * Report a failed step.
     *
     * @param string $step Step.
     * @param string $detail Code and layer.
     * @return bool Always false.
     */
    private function fail(string $step, string $detail): bool {
        $this->write('FAIL ' . $step . ': ' . $detail);
        return false;
    }

    /**
     * Write one line.
     *
     * @param string $line Line.
     */
    private function write(string $line): void {
        ($this->writer)($line);
    }
}
