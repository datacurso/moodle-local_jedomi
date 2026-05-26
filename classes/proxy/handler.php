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

/**
 * AI Chat Proxy — tool-calling loop and SSE streaming handler.
 *
 * Contains the main tool loop (run), AI API buffered streaming,
 * tool execution dispatch, all individual tool functions, and SSE
 * helper methods. No in-memory cache, no query history tracking.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\proxy;

defined('MOODLE_INTERNAL') || die();

class handler {

    /**
     * Run the tool-calling loop using native OpenAI function calling.
     *
     * Passes tool definitions to the model via the API. The model returns structured
     * tool_calls (no text parsing). Tool results are re-injected as role=tool messages
     * (OpenAI standard). Final text response is streamed to the client via SSE.
     *
     * @param string $model    Model name.
     * @param array  $messages Conversation messages.
     * @param string $role     User role (already validated).
     * @param array  $spt      SPT payload.
     */
    public static function run(string $model, array $messages, string $role, array $spt): void {
        require_once(__DIR__ . '/../../lib.php');
        \local_jedomi_log('RUN_START', ['model' => $model, 'role' => $role, 'user_message_count' => count($messages)]);

        $tools = spt_validator::get_tool_definitions($role);
        $toolrepeatcounts = [];

        for ($i = 0; $i < 50; $i++) {
            echo ": thinking\n\n";
            if (ob_get_level()) { ob_flush(); }
            flush();

            $response = self::call_ai_api_buffer($model, $messages, $spt, $tools);

            if ($response['type'] === 'error') {
                echo "data: {\"error\":\"ai_api_error\"}\n\n";
                if (ob_get_level()) { ob_flush(); }
                flush();
                return;
            }

            if ($response['type'] === 'text') {
                \local_jedomi_log('AI_RESPONSE_TEXT', ['iteration' => $i, 'preview' => mb_substr($response['content'], 0, 200)]);
                // No tool calls — stream the final response to the client.
                self::stream_sse_content($response['content']);
                return;
            }

            // type === 'tool_calls': execute each one and collect results.
            $tool_calls = $response['tool_calls'];
            \local_jedomi_log('AI_TOOL_CALLS', ['iteration' => $i, 'tool_calls_count' => count($tool_calls), 'tool_names' => array_map(fn($tc) => $tc['function']['name'] ?? '?', $tool_calls)]);

            // Add the assistant message with tool_calls to conversation history.
            $messages[] = [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => $tool_calls,
            ];

            foreach ($tool_calls as $tc) {
                $name = $tc['function']['name'];
                $tc_id = $tc['id'];
                $args_raw = $tc['function']['arguments'];
                $args = is_array($args_raw) ? $args_raw : (json_decode($args_raw, true) ?? []);

                $callkey = $name . '|' . self::stable_tool_args_signature($args);
                $toolrepeatcounts[$callkey] = ($toolrepeatcounts[$callkey] ?? 0) + 1;

                // Notify the client that a tool is being executed.
                echo "data: " . json_encode([
                    '_jedomi_tool_executing' => ['tool' => $name, 'args' => $args],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) { ob_flush(); }
                flush();

                if ($toolrepeatcounts[$callkey] > 5) {
                    $result = json_encode([
                        'error' => 'loop_detected',
                        'tool' => $name,
                        'detail' => get_string('error_tool_loop_detected', 'local_jedomi'),
                    ]);
                    \local_jedomi_log('LOOP_DETECTED', ['tool' => $name, 'args' => $args, 'count' => $toolrepeatcounts[$callkey]]);
                } else {
                    try {
                        $result = self::execute_tool_by_name($name, $args, $role);
                    } catch (\Throwable $e) {
                        $result = json_encode([
                            'error' => 'tool_failed',
                            'tool'  => $name,
                            'detail' => get_class($e) . ': ' . $e->getMessage(),
                        ]);
                    }
                }

                // Send result summary to client.
                echo "data: " . json_encode([
                    '_jedomi_tool_result' => [
                        'tool'    => $name,
                        'summary' => self::summarize_tool_result($name, $result),
                    ],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) { ob_flush(); }
                flush();

                // Inject tool result as role=tool message (OpenAI standard format).
                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc_id,
                    'content'      => $result,
                ];

                \local_jedomi_log('TOOL_RESULT', ['tool' => $name, 'summary' => self::summarize_tool_result($name, $result)]);
            }
        }

        \local_jedomi_log('RUN_FALLBACK_ITERATIONS_EXCEEDED', ['iteration_count' => 10]);
        self::stream_sse_content(get_string('error_fallback_iterations', 'local_jedomi'));
    }

    /**
     * Build a deterministic signature for tool arguments.
     *
     * @param  array $args Tool arguments.
     * @return string
     */
    private static function stable_tool_args_signature(array $args): string {
        $normalized = self::sort_recursive($args);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
    }

    /**
     * Recursively sort associative arrays for stable hashing.
     *
     * @param  mixed $value
     * @return mixed
     */
    private static function sort_recursive($value) {
        if (!is_array($value)) {
            return $value;
        }

        $islist = array_keys($value) === range(0, count($value) - 1);
        if ($islist) {
            return array_map(static fn($v) => self::sort_recursive($v), $value);
        }

        ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = self::sort_recursive($v);
        }
        return $value;
    }

    /**
     * Call the AI API with streaming (buffered) using native OpenAI function calling.
     *
     * @param  string $model    Model name.
     * @param  array  $messages Conversation messages.
     * @param  array  $spt      SPT payload.
     * @param  array  $tools    OpenAI-format tool definitions.
     * @return array            ['type'=>'text', 'content'=>'...']
     *                          ['type'=>'tool_calls', 'tool_calls'=>[...]]
     *                          ['type'=>'error', 'error'=>'...']
     */
    private static function call_ai_api_buffer(string $model, array $messages, array $spt, array $tools = []): array {
        $apiUrl = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://192.168.1.28:8080', '/');
        $apiKey = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';

        $payload = [
            'model'      => $model,
            'messages'   => $messages,
            'stream'     => true,
            'max_tokens' => 16384,
        ];
        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        // Log conversation context sent to AI (last 5 messages, truncated).
        $contextpreview = array_map(static fn(array $msg): array => [
            'role' => $msg['role'] ?? '?',
            'content_preview' => isset($msg['content']) && is_string($msg['content'])
                ? mb_substr($msg['content'], 0, 150) : (isset($msg['tool_calls']) ? 'tool_calls:' . count($msg['tool_calls']) : ''),
            'tool_call_id' => $msg['tool_call_id'] ?? null,
        ], array_slice($messages, -5));
        \local_jedomi_log('AI_API_REQUEST', ['model' => $model, 'messages_count' => count($messages), 'last_messages' => $contextpreview]);

        $buffer = '';
        $ch = curl_init($apiUrl . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer) {
                $buffer .= $data;
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || empty($buffer)) {
            $buf_preview = substr($buffer, 0, 500);
            \local_jedomi_log('AI_API_ERROR', ['http_code' => $httpCode, 'curl_error' => $curlError, 'preview' => $buf_preview]);
            error_log("[local_jedomi] AI API error: http=$httpCode curl='$curlError' preview="
                . json_encode($buf_preview, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
            return ['type' => 'error', 'error' => 'ai_api_error'];
        }

        $text_buf = '';
        $reason_buf = '';
        $tool_calls_acc = [];
        $finish_reason = '';

        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data: ')) {
                continue;
            }
            $raw = trim(substr($line, 6));
            if ($raw === '[DONE]') {
                break;
            }
            $json = json_decode($raw, true);
            if (!$json) {
                continue;
            }

            $delta = $json['choices'][0]['delta'] ?? [];
            $finish_reason = $json['choices'][0]['finish_reason'] ?? $finish_reason;

            // Accumulate text content.
            if (isset($delta['content']) && $delta['content'] !== null) {
                $text_buf .= $delta['content'];
            }
            // Gemma reasoning_content (thinking tokens — fallback only).
            if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== null) {
                $reason_buf .= $delta['reasoning_content'];
            }
            // Accumulate tool_calls streamed across multiple delta chunks.
            if (!empty($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $tc) {
                    $idx = $tc['index'] ?? 0;
                    if (!isset($tool_calls_acc[$idx])) {
                        $tool_calls_acc[$idx] = [
                            'id'       => $tc['id'] ?? '',
                            'type'     => $tc['type'] ?? 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                    }
                    if (!empty($tc['id'])) {
                        $tool_calls_acc[$idx]['id'] = $tc['id'];
                    }
                    if (!empty($tc['function']['name'])) {
                        $tool_calls_acc[$idx]['function']['name'] = $tc['function']['name'];
                    }
                    if (isset($tc['function']['arguments'])) {
                        $arg = $tc['function']['arguments'];
                        // Some Ollama versions send arguments as a pre-decoded object.
                        if (is_array($arg)) {
                            $tool_calls_acc[$idx]['function']['arguments'] = json_encode($arg, JSON_UNESCAPED_UNICODE);
                        } else {
                            $tool_calls_acc[$idx]['function']['arguments'] .= $arg;
                        }
                    }
                }
            }
        }

        // Tool calls detected — ensure every entry has a non-empty id.
        if (!empty($tool_calls_acc) || $finish_reason === 'tool_calls') {
            ksort($tool_calls_acc);
            $tool_calls = array_values($tool_calls_acc);
            foreach ($tool_calls as &$tc) {
                if (empty($tc['id'])) {
                    $tc['id'] = 'call_' . substr(md5($tc['function']['name'] . microtime()), 0, 12);
                }
            }
            unset($tc);

            $toolnames = array_map(static fn($tc) => $tc['function']['name'], $tool_calls);
            \local_jedomi_log('AI_API_RESPONSE_TOOL_CALLS', ['function_count' => count($tool_calls), 'functions' => $toolnames]);

            return ['type' => 'tool_calls', 'tool_calls' => $tool_calls];
        }

        // Text response: prefer content, fall back to reasoning (Gemma thinking tokens).
        $text = !empty($text_buf) ? $text_buf : $reason_buf;
        if (empty($text) && !empty($buffer)) {
            \local_jedomi_log('AI_API_RESPONSE_EMPTY', ['buffer_preview' => substr($buffer, 0, 500)]);
            error_log("[local_jedomi] SSE parse warning: no content or tool_calls extracted. First 2KB: "
                . json_encode(substr($buffer, 0, 2048), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
        }

        \local_jedomi_log('AI_API_RESPONSE_TEXT', ['length' => mb_strlen($text), 'preview' => mb_substr($text, 0, 200)]);

        return ['type' => 'text', 'content' => $text];
    }

    /**
     * Execute a tool by name, dispatching to the correct handler.
     *
     * @param  string $name Tool name.
     * @param  array  $args Tool arguments.
     * @param  string $role User role for permission checks.
     * @return string       JSON-encoded result.
     */
    private static function execute_tool_by_name(string $name, array $args, string $role): string {
        // Log every tool invocation with full args.
        require_once(__DIR__ . '/../../lib.php');
        \local_jedomi_log('TOOL_CALL', ['tool' => $name, 'args' => $args, 'role' => $role]);

        switch ($name) {
            case 'describe_table':
                return self::describe_table_tool($args);
            case 'search_tables':
                return self::search_tables_tool($args);
            case 'execute_sql':
                return self::execute_sql_tool($args, $role);
            case 'get_site_config':
                return self::get_site_config_tool($args, $role);
            case 'ws_search':
                return self::ws_search_tool($args, $role);
            case 'ws_describe':
                return self::ws_describe_tool($args, $role);
            case 'ws_execute':
                return self::ws_execute_tool($args, $role);
            case 'admin_search':
                return self::admin_search_tool($args, $role);
            case 'admin_describe':
                return self::admin_describe_tool($args, $role);
            case 'admin_execute':
                return self::admin_execute_tool($args, $role);

            case 'create_agents':   // Model sometimes hallucinates plural form.
            case 'create_agent':
                return self::create_agent_tool($args, $role);
            case 'list_agent':     // Model sometimes hallucinates singular form.
            case 'list_agents':
                return self::list_agents_tool($args, $role);
            case 'delete_agents':   // Model sometimes hallucinates plural form.
            case 'delete_agent':
                return self::delete_agent_tool($args, $role);

            default:
                return json_encode(['error' => get_string('error_unknown_tool', 'local_jedomi', $name)]);
        }
    }

    /**
     * Execute SQL tool.
     *
     * @param  array  $args Tool arguments (sql, params).
     * @param  string $role User role.
     * @return string       JSON-encoded result.
     */
    private static function execute_sql_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager', 'teacher'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }
        $sql    = $args['sql'] ?? $args['query'] ?? '';
        $sql    = self::normalize_moodle_table_placeholders($sql);
        $params = $args['params'] ?? [];
        if (!is_array($params)) {
            $params = [$params];
        }

        \local_jedomi_log('EXECUTE_SQL', ['sql' => $sql, 'params' => $params]);

        try {
            $result = \local_jedomi\external\execute_sql::execute(
                $sql,
                $params,
                '', 0, '', true
            );
            return json_encode($result);
        } catch (\Exception $e) {
            $realError = $e->getMessage();
            if ($e instanceof \dml_exception && !empty($e->debuginfo)) {
                $realError .= ' | DBG: ' . $e->debuginfo;
            }
            $prev = $e->getPrevious();
            if ($prev) {
                $realError .= ' | PREV: ' . $prev->getMessage();
                if ($prev instanceof \dml_exception && !empty($prev->debuginfo)) {
                    $realError .= ' | PREV_DBG: ' . $prev->debuginfo;
                }
            }
            if (strpos($sql, '{') === false) {
                $realError .= get_string('hint_sql_braces', 'local_jedomi');
            }
            \local_jedomi_log('EXECUTE_SQL_ERROR', ['sql' => $sql, 'params' => $params, 'error' => $realError]);
            error_log("[local_jedomi] execute_sql_tool error: class=" . get_class($e) . " msg=" . json_encode($realError, JSON_UNESCAPED_UNICODE));
            return json_encode(['error' => $realError]);
        }
    }

    /**
     * Normalize SQL by wrapping plain table names in FROM/JOIN with Moodle braces.
     *
     * Example: FROM course -> FROM {course}
     *
     * @param string $sql
     * @return string
     */
    private static function normalize_moodle_table_placeholders(string $sql): string {
        return (string)preg_replace_callback(
            '/\b(FROM|JOIN)\s+([a-z][a-z0-9_]*)\b/i',
            static function(array $matches): string {
                $keyword = $matches[1];
                $table = $matches[2];
                return $keyword . ' {' . $table . '}';
            },
            $sql
        );
    }

    /**
     * Describe table tool (batch).
     *
     * @param  array  $args Tool arguments (tables).
     * @return string       JSON-encoded result.
     */
    /**
     * Describe table tool (single).
     *
     * @param  array  $args Tool arguments (tablename).
     * @return string       JSON-encoded result.
     */
    private static function describe_table_tool(array $args): string {
        try {
            $tablename = $args['tablename'] ?? $args['table_name'] ?? $args['table'] ?? '';
            $result = \local_jedomi\schema\table_descriptor::describe($tablename);
            return json_encode($result);
        } catch (\Exception $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Search tables tool — semantic search over install.xml definitions.
     *
     * Unlike the old table_descriptor::search_tables (plain string match),
     * this searches across table names, table COMMENTS, field names,
     * and field COMMENTS from install.xml files — same approach as ws_search.
     *
     * @param  array  $args Tool arguments (query, limit).
     * @return string       JSON-encoded result.
     */
    private static function search_tables_tool(array $args): string {
        try {
            $query  = trim($args['query'] ?? $args['keyword'] ?? '');
            $limit  = max(1, min(15, (int) ($args['limit'] ?? 8)));

            if (empty($query)) {
                return json_encode(['error' => 'query is required']);
            }

            $results = \local_jedomi\schema\table_indexer::search($query, $limit);

            return json_encode([
                'tables' => $results,
                'count'  => count($results),
                'note'   => count($results) === 0
                    ? get_string('note_tables_not_found', 'local_jedomi')
                    : get_string('note_tables_found', 'local_jedomi'),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => get_string('error_search_tables_failed', 'local_jedomi', $e->getMessage())]);
        }
    }

    /**
     * Get site config tool.
     *
     * @param  array  $args Tool arguments (keys).
     * @param  string $role The user's resolved role.
     * @return string       JSON-encoded result.
     */
    private static function get_site_config_tool(array $args, string $role): string {
        if ($role !== 'admin') {
            return json_encode(['error' => 'permission_denied']);
        }
        try {
            $cfgKeys = $args['keys'] ?? [];
            if (empty($cfgKeys) && isset($args['key'])) {
                $cfgKeys = is_array($args['key']) ? $args['key'] : [$args['key']];
            }
            $result = \local_jedomi\external\get_site_config::execute(
                $cfgKeys,
                '',     // hmac_signature
                0,      // hmac_timestamp
                '',     // payload_hash
                true    // skip_hmac
            );
            return json_encode($result);
        } catch (\Exception $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * WS Search tool — semantic keyword search over available web service functions.
     *
     * @param  array  $args Tool arguments (query, limit).
     * @param  string $role The user's resolved role.
     * @return string       JSON-encoded result.
     */
    private static function ws_search_tool(array $args, string $role): string {
        try {
            $query = trim($args['query'] ?? $args['keyword'] ?? $args['filter'] ?? '');
            $limit = max(1, min(15, (int) ($args['limit'] ?? 8)));

            if (empty($query)) {
                return json_encode(['error' => 'query is required']);
            }

            $allowlist = \local_jedomi_get_role_allowlist($role);

            if (empty($allowlist) && $role !== 'admin') {
                return json_encode(['results' => [], 'note' => get_string('note_ws_none_configured', 'local_jedomi')]);
            }

            $wsnames = $role === 'admin' ? [] : $allowlist;

            $results = \local_jedomi\schema\ws_indexer::search($query, $wsnames, $limit);

            \local_jedomi_log('WS_SEARCH_RESULTS', [
                'query' => $query,
                'count' => count($results),
                'names' => array_map(fn($r) => $r['name'] ?? '?', $results),
                'descriptions' => array_map(fn($r) => mb_substr($r['description'] ?? '', 0, 120), $results),
            ]);

            return json_encode([
                'results' => $results,
                'count'   => count($results),
                'note'    => count($results) === 0
                    ? get_string('note_ws_not_found', 'local_jedomi')
                    : get_string('note_ws_found', 'local_jedomi'),
            ]);
        } catch (\Throwable $e) {
            return json_encode([
                'error' => get_string('error_ws_search_failed', 'local_jedomi', get_class($e) . ': ' . $e->getMessage()),
            ]);
        }
    }

    /**
     * WS Describe tool — returns full compact documentation for a specific WS function.
     *
     * @param  array  $args Tool arguments (wsname).
     * @param  string $role The user's resolved role.
     * @return string       JSON-encoded result.
     */
    private static function ws_describe_tool(array $args, string $role): string {
        try {
            $wsname = trim($args['wsname'] ?? $args['name'] ?? $args['ws_name'] ?? '');

            if (empty($wsname)) {
                return json_encode(['error' => 'wsname is required']);
            }

            if ($role !== 'admin') {
                $allowlist = \local_jedomi_get_role_allowlist($role);
                if (!empty($allowlist) && !in_array($wsname, $allowlist, true)) {
                    return json_encode(['error' => 'permission_denied: function not in allowlist']);
                }
            }

            return json_encode(\local_jedomi\schema\ws_indexer::describe($wsname));
        } catch (\Throwable $e) {
            return json_encode([
                'error' => get_string('error_ws_describe_failed', 'local_jedomi', get_class($e) . ': ' . $e->getMessage()),
            ]);
        }
    }

    /**
     * WS Execute tool — executes a web service on behalf of the user.
     *
     * @param  array  $args Tool arguments (wsname, params).
     * @param  string $role The user's resolved role.
     * @return string       JSON-encoded result.
     */
    private static function ws_execute_tool(array $args, string $role): string {
        try {
            $wsname = $args['wsname'] ?? $args['ws_name'] ?? '';
            $raw = $args['params'] ?? '{}';
            $params = is_array($raw) ? json_encode($raw) : $raw;

            \local_jedomi_log('WS_EXECUTE', ['wsname' => $wsname, 'params' => $params]);

            $result = \local_jedomi\external\ws_execute::execute(
                $wsname,
                $params,
                '',     // hmac_signature
                0,      // hmac_timestamp
                '',     // payload_hash
                true    // skip_hmac
            );
            return json_encode($result);
        } catch (\moodle_exception $e) {
            $detail = $e->getMessage();
            if (method_exists($e, 'getDebugInfo') && $e->getDebugInfo()) {
                $detail .= get_string('ws_debug_prefix', 'local_jedomi') . $e->getDebugInfo();
            }
            \local_jedomi_log('WS_EXECUTE_ERROR', ['wsname' => $wsname ?? '', 'params' => $params ?? '{}', 'error' => $detail]);
            return json_encode([
                'error'    => get_string('error_ws_execute_failed', 'local_jedomi', $detail),
                'wsname'   => $wsname ?? '',
                'params'   => $params ?? '{}',
                'errclass' => get_class($e),
            ]);
        } catch (\Throwable $e) {
            \local_jedomi_log('WS_EXECUTE_ERROR', ['wsname' => $wsname ?? '', 'params' => $params ?? '{}', 'error' => $e->getMessage()]);
            return json_encode([
                'error'    => get_string('error_ws_execute_failed', 'local_jedomi', $e->getMessage()),
                'wsname'   => $wsname ?? '',
                'params'   => $params ?? '{}',
                'errclass' => get_class($e),
            ]);
        }
    }

    /**
     * Search admin CLI operations by keyword.
     */
    private static function admin_search_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $query = trim($args['query'] ?? '');
        $limit = min((int) ($args['limit'] ?? 8), 20);

        $results = \local_jedomi\schema\cli_registry::search($query, $limit);
        return json_encode(['results' => $results, 'count' => count($results)]);
    }

    private static function admin_describe_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $name  = trim($args['name'] ?? '');
        $entry = \local_jedomi\schema\cli_registry::describe($name);
        return json_encode($entry);
    }

    private static function admin_execute_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $name   = trim($args['name'] ?? '');
        $params = $args['params'] ?? [];

        $result = \local_jedomi\schema\cli_registry::execute($name, $params);
        return json_encode($result);
    }

    /**
     * Create a scheduled AI agent.
     *
     * @param  array  $args Tool arguments.
     * @param  string $role User role.
     * @return string       JSON result.
     */
    private static function create_agent_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }
        $prompt = trim($args['prompt'] ?? '');
        $schedule = trim($args['schedule_cron'] ?? '');
        if (empty($prompt) || empty($schedule)) {
            return json_encode(['error' => 'Both prompt and schedule_cron are required.']);
        }
        $parts = preg_split('/\s+/', $schedule);
        if (count($parts) !== 5) {
            return json_encode(['error' => 'Invalid cron expression. Use 5 fields.']);
        }
        global $USER;
        $data = new \stdClass();
        $data->userid = (int) ($args['userid'] ?? $USER->id);
        $data->name = trim($args['name'] ?? '');
        $data->prompt = $prompt;
        $data->schedule_cron = $schedule;
        $data->context_type = $args['context_type'] ?? 'site';
        $data->context_id = isset($args['context_id']) ? (int) $args['context_id'] : null;
        $data->enabled = isset($args['enabled']) ? (int) $args['enabled'] : 1;
        $id = \local_jedomi\agent\agent_manager::create_agent($data);
        $agent = \local_jedomi\agent\agent_manager::get_agent($id);
        $label = $agent->name ?? "Agent #{$id}";
        return json_encode([
            'success' => true,
            'agent_id' => $id,
            'name' => $agent->name ?? '',
            'message' => "✅ {$label} created successfully.",
        ]);
    }

    /**
     * List scheduled AI agents.
     *
     * @param  array  $args Tool arguments.
     * @param  string $role User role.
     * @return string       JSON result.
     */
    private static function list_agents_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }
        $filters = [];
        if (!empty($args['status'])) {
            $filters['status'] = $args['status'];
        }
        $result = \local_jedomi\agent\agent_manager::get_agents($filters);
        return json_encode([
            'success' => true,
            'total' => $result['total'],
            'agents' => array_map(function($a) {
                return [
                    'id' => (int) $a->id,
                    'prompt' => $a->prompt,
                    'schedule_cron' => $a->schedule_cron,
                    'context_type' => $a->context_type,
                    'context_id' => $a->context_id ? (int) $a->context_id : null,
                    'enabled' => (bool) $a->enabled,
                    'lastrun' => $a->lastrun ? (int) $a->lastrun : null,
                    'nextrun' => (int) $a->nextrun,
                ];
            }, $result['items']),
        ]);
    }

    /**
     * Delete a scheduled AI agent.
     *
     * @param  array  $args Tool arguments.
     * @param  string $role User role.
     * @return string       JSON result.
     */
    private static function delete_agent_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return json_encode(['error' => 'Valid agent ID required.']);
        }
        $agent = \local_jedomi\agent\agent_manager::get_agent($id);
        if (!$agent) {
            return json_encode(['error' => "Agent #{$id} not found."]);
        }
        \local_jedomi\agent\agent_manager::delete_agent($id);
        return json_encode([
            'success' => true,
            'message' => "Agent #{$id} deleted.",
        ]);
    }

    /**
     * Send an SSE event line to the client.
     *
     * @param string $event Event name (e.g. _jedomi_tool_executing).
     * @param mixed  $data  Data payload (will be JSON-encoded).
     */
    private static function sse_send(string $event, $data): void {
        $payload = json_encode(
            array_merge(
                ['event' => $event],
                is_array($data) ? $data : ['data' => $data]
            ),
            JSON_UNESCAPED_UNICODE
        );
        echo "data: {$payload}\n\n";
        if (ob_get_level()) { ob_flush(); }
        flush();
    }

    /**
     * Stream text content to the client as OpenAI-format SSE tokens.
     *
     * @param string $content The text to stream.
     */
    private static function stream_sse_content(string $content): void {
        if (empty($content)) {
            echo "data: [DONE]\n\n";
            if (ob_get_level()) { ob_flush(); }
            flush();
            return;
        }

        $chunks = mb_str_split($content, 15);
        foreach ($chunks as $chunk) {
            echo "data: " . json_encode([
                'choices' => [['delta' => ['content' => $chunk]]],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level()) { ob_flush(); }
            flush();
            usleep(2000);
        }
        echo "data: [DONE]\n\n";
        if (ob_get_level()) { ob_flush(); }
        flush();
    }

    /**
     * Summarize a tool result for the _jedomi_tool_result SSE event.
     *
     * @param  string $name   Tool name.
     * @param  string $result JSON-encoded result.
     * @return string         Human-readable summary.
     */
    private static function summarize_tool_result(string $name, string $result): string {
        $decoded = json_decode($result, true);
        if (!$decoded) {
            return get_string('summary_invalid_json', 'local_jedomi');
        }
        if (isset($decoded['error'])) {
            return get_string('summary_error_prefix', 'local_jedomi') . $decoded['error'];
        }
        return match ($name) {
            'execute_sql' => ($decoded['row_count'] ?? count($decoded['rows'] ?? [])) . get_string('summary_rows_returned', 'local_jedomi'),
            'describe_table' => get_string('summary_table_structure', 'local_jedomi'),
            'search_tables' => ($decoded['count'] ?? count($decoded['tables'] ?? $decoded) ?? 0) . get_string('summary_matching_tables', 'local_jedomi'),
            'get_site_config' => get_string('summary_config_data', 'local_jedomi'),
            'ws_search'   => count($decoded['results'] ?? []) . get_string('summary_functions_found', 'local_jedomi'),
            'ws_describe' => get_string('summary_function_details', 'local_jedomi'),
            'ws_execute' => get_string('summary_ws_executed', 'local_jedomi'),
            default => get_string('summary_done', 'local_jedomi'),
        };
    }
}
