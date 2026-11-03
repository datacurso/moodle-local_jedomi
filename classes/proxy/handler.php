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
 * Contains the main tool loop (run), the AI client call,
 * tool execution dispatch, all individual tool functions, and SSE
 * helper methods. No in-memory cache, no query history tracking.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\proxy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_jedomi\local\service\message_service;
use local_jedomi\local\service\pending_action_service;

/**
 * AI chat proxy handler: runs the tool-calling loop, dispatches tool executions and streams SSE.
 */
class handler {
    /**
     * Run the tool loop for a web chat request, ignoring any model the browser sends.
     *
     * @param array  $input  Decoded browser payload (messages, context; any "model" is ignored).
     * @param string $role   User role (already validated).
     * @param array  $spt    SPT payload (already validated).
     * @param int    $userid Moodle user the AI calls are made for.
     */
    public static function run_from_request(array $input, string $role, array $spt, int $userid): void {
        $context = is_array($input['context'] ?? null) ? $input['context'] : [];
        $messages = is_array($input['messages'] ?? null) ? $input['messages'] : [];
        $system = system_message::build($role, $spt, $context);
        self::run(array_merge([$system], array_values($messages)), $role, $userid);
    }

    /**
     * Run the tool-calling loop using native OpenAI function calling.
     *
     * Passes tool definitions to the model via the API. The model returns structured
     * tool_calls (no text parsing). Tool results are re-injected as role=tool messages
     * (OpenAI standard). The final text is sent to the browser as SSE chunks.
     *
     * @param array  $messages Conversation messages (system message first).
     * @param string $role     User role (already validated).
     * @param int    $userid   Moodle user the AI calls are made for.
     */
    public static function run(array $messages, string $role, int $userid): void {
        require_once(__DIR__ . '/../../lib.php');
        \local_jedomi_log('RUN_START', ['role' => $role, 'user_message_count' => count($messages)]);

        $tools = spt_validator::get_tool_definitions($role);
        if (message_service::can_send($userid)) {
            // Offered only in the web chat, where the user confirms each send.
            $tools[] = message_service::tool_definition();
        }
        $toolrepeatcounts = [];

        for ($i = 0; $i < 50; $i++) {
            echo ": thinking\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();

            $response = self::call_ai($messages, $tools, $userid);

            if ($response['type'] === 'error') {
                echo "data: " . json_encode(['error' => $response['error']], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                return;
            }

            if ($response['type'] === 'text') {
                \local_jedomi_log('AI_RESPONSE_TEXT', ['iteration' => $i, 'length' => mb_strlen($response['content'])]);
                // No tool calls — stream the final response to the client.
                self::stream_sse_content($response['content']);
                return;
            }

            // Type === 'tool_calls': execute each one and collect results.
            $toolcalls = $response['tool_calls'];
            \local_jedomi_log('AI_TOOL_CALLS', [
                'iteration' => $i,
                'tool_calls_count' => count($toolcalls),
                'tool_names' => array_map(fn($tc) => $tc['function']['name'] ?? '?', $toolcalls),
            ]);

            // Add the assistant message with tool_calls to conversation history.
            $messages[] = [
                'role'       => 'assistant',
                'content'    => null,
                'tool_calls' => $toolcalls,
            ];

            foreach ($toolcalls as $tc) {
                $name = $tc['function']['name'];
                $tcid = $tc['id'];
                $argsraw = $tc['function']['arguments'];
                $args = is_array($argsraw) ? $argsraw : (json_decode($argsraw, true) ?? []);

                // A tool that changes data does not run now: it waits for the user's confirmation
                // and the turn ends, so the model cannot act on a result that does not exist yet.
                $pending = pending_action_service::hold($userid, $name, $args);
                if ($pending !== null) {
                    \local_jedomi_log('TOOL_HELD', ['tool' => $name, 'severity' => $pending['severity']]);
                    echo "data: " . json_encode(['_jedomi_pending_action' => $pending], JSON_UNESCAPED_UNICODE) . "\n\n";
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    self::stream_sse_content(get_string('pending_action_needed', 'local_jedomi'));
                    return;
                }

                $callkey = $name . '|' . self::stable_tool_args_signature($args);
                $toolrepeatcounts[$callkey] = ($toolrepeatcounts[$callkey] ?? 0) + 1;

                // Notify the client that a tool is being executed.
                echo "data: " . json_encode([
                    '_jedomi_tool_executing' => ['tool' => $name, 'args' => $args],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
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
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                // Inject tool result as role=tool message (OpenAI standard format).
                // Special case: document_read returns base64 images — inject as multimodal content.
                if ($name === 'document_read') {
                    $decoded = json_decode($result, true);
                    if ($decoded && !empty($decoded['pages'])) {
                        $fmt = strtolower($decoded['pages'][0]['format'] ?? 'jpeg');
                        $contentparts = [
                            ['type' => 'text', 'text' =>
                                "Document \"{$decoded['filename']}\" ({$decoded['total_pages']} pages) converted to images:"],
                        ];
                        foreach ($decoded['pages'] as $page) {
                            $contentparts[] = [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:image/{$fmt};base64,{$page['image']}",
                                ],
                            ];
                        }
                        $messages[] = [
                            'role'    => 'user',
                            'content' => $contentparts,
                        ];
                    } else {
                        // Fallback: plain text error.
                        $messages[] = [
                            'role'         => 'tool',
                            'tool_call_id' => $tcid,
                            'content'      => $result,
                        ];
                    }
                } else {
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $tcid,
                        'content'      => $result,
                    ];
                }

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
     * Call the AI service through the Jedomi AI client.
     *
     * @param  array $messages Conversation messages.
     * @param  array $tools    OpenAI-format tool definitions.
     * @param  int   $userid   Moodle user the call is made for.
     * @return array           ['type'=>'text', 'content'=>'...']
     *                         ['type'=>'tool_calls', 'tool_calls'=>[...]]
     *                         ['type'=>'error', 'error'=>['code'=>'...', 'message'=>'...']]
     */
    private static function call_ai(array $messages, array $tools, int $userid): array {
        try {
            $answer = \local_jedomi\ai\client::instance()->complete($messages, $userid, $tools);
        } catch (\Throwable $e) {
            return ['type' => 'error', 'error' => \local_jedomi\ai\error_message::from_exception($e)];
        }

        if (!empty($answer['tool_calls'])) {
            return ['type' => 'tool_calls', 'tool_calls' => $answer['tool_calls']];
        }

        // Text response: prefer content, fall back to reasoning (thinking tokens).
        $text = $answer['content'] !== '' ? $answer['content'] : ($answer['reasoning'] ?? '');
        return ['type' => 'text', 'content' => $text];
    }

    /**
     * Public entry point for CLI debug — delegates to the private dispatcher.
     *
     * @param  string $name Tool name.
     * @param  array  $args Tool arguments.
     * @param  string $role User role for permission checks.
     * @return string       JSON-encoded result.
     */
    public static function run_tool(string $name, array $args, string $role): string {
        return self::execute_tool_by_name($name, $args, $role);
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

            case 'document_read':
                return self::document_read_tool($args, $role);
            case 'send_messages':
                return self::send_messages_tool($args);
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
                '',
                0,
                '',
                true
            );
            return json_encode($result);
        } catch (\Exception $e) {
            $realerror = $e->getMessage();
            if ($e instanceof \dml_exception && !empty($e->debuginfo)) {
                $realerror .= ' | DBG: ' . $e->debuginfo;
            }
            $prev = $e->getPrevious();
            if ($prev) {
                $realerror .= ' | PREV: ' . $prev->getMessage();
                if ($prev instanceof \dml_exception && !empty($prev->debuginfo)) {
                    $realerror .= ' | PREV_DBG: ' . $prev->debuginfo;
                }
            }
            if (strpos($sql, '{') === false) {
                $realerror .= get_string('hint_sql_braces', 'local_jedomi');
            }
            \local_jedomi_log('EXECUTE_SQL_ERROR', ['sql' => $sql, 'params' => $params, 'error' => $realerror]);
            \local_jedomi_log("[local_jedomi] execute_sql_tool error: class=" . get_class($e)
                . " msg=" . json_encode($realerror, JSON_UNESCAPED_UNICODE));
            return json_encode(['error' => $realerror]);
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
            static function (array $matches): string {
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
            $cfgkeys = $args['keys'] ?? [];
            if (empty($cfgkeys) && isset($args['key'])) {
                $cfgkeys = is_array($args['key']) ? $args['key'] : [$args['key']];
            }
            $result = \local_jedomi\external\get_site_config::execute(
                $cfgkeys,
                '', // Hmac_signature.
                0, // Hmac_timestamp.
                '', // Payload_hash.
                true    // Skip_hmac.
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
                '', // Hmac_signature.
                0, // Hmac_timestamp.
                '', // Payload_hash.
                true    // Skip_hmac.
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
            \local_jedomi_log('WS_EXECUTE_ERROR', [
                'wsname' => $wsname ?? '',
                'params' => $params ?? '{}',
                'error' => $e->getMessage(),
            ]);
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
     *
     * @param  array  $args Tool arguments; expects "query" and optional "limit" keys.
     * @param  string $role User role; only admin and manager are allowed.
     * @return string JSON-encoded search results, or a permission_denied error.
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

    /**
     * Describe one admin CLI operation from the registry.
     *
     * @param  array  $args Tool arguments; expects a "name" key with the operation name.
     * @param  string $role User role; only admin and manager are allowed.
     * @return string JSON-encoded registry entry, or a permission_denied error.
     */
    private static function admin_describe_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $name  = trim($args['name'] ?? '');
        $entry = \local_jedomi\schema\cli_registry::describe($name);
        return json_encode($entry);
    }

    /**
     * Execute one admin CLI operation from the registry.
     *
     * @param  array  $args Tool arguments; expects "name" and optional "params" keys.
     * @param  string $role User role; only admin and manager are allowed.
     * @return string JSON-encoded execution result, or a permission_denied error.
     */
    private static function admin_execute_tool(array $args, string $role): string {
        if (!in_array($role, ['admin', 'manager'], true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        global $USER;

        $name   = trim($args['name'] ?? '');
        $params = $args['params'] ?? [];

        $result = \local_jedomi\schema\cli_registry::execute($name, $params, (int) ($USER->id ?? 0));
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
            'agents' => array_map(function ($a) {
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
     * Send Moodle internal messages as the current user.
     *
     * @param  array $args Tool arguments; expects "user_ids" and "message".
     * @return string JSON with the sent and failed counts, or a permission_denied error.
     */
    private static function send_messages_tool(array $args): string {
        global $USER;
        if (!message_service::can_send((int) $USER->id)) {
            return json_encode(['error' => 'permission_denied']);
        }
        return json_encode(message_service::send((int) $USER->id, $args['user_ids'] ?? [], (string) ($args['message'] ?? '')));
    }

    /**
     * Read a PDF document from a course module and convert to images.
     *
     * @param  array  $args Tool arguments; expects "cmid" and optional "dpi"/"fmt" keys.
     * @param  string $role User role forwarded by the tool dispatcher.
     * @return string JSON-encoded document payload, or an error message.
     */
    private static function document_read_tool(array $args, string $role): string {
        global $CFG, $DB;

        $cmid = (int) ($args['cmid'] ?? 0);
        $dpi  = (int) ($args['dpi'] ?? 150);
        $fmt  = in_array($args['fmt'] ?? '', ['JPEG', 'PNG'], true) ? $args['fmt'] : 'JPEG';

        if ($cmid <= 0) {
            return json_encode(['error' => 'cmid is required']);
        }

        // Role check — teacher+.
        $allowed = ['teacher', 'editingteacher', 'manager', 'admin'];
        if (!in_array($role, $allowed, true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $serviceurl = rtrim((string) get_config('local_jedomi', 'docservice_url'), '/');
        if (empty($serviceurl)) {
            return json_encode(['error' => 'document service not configured']);
        }

        try {
            $context = \context_module::instance($cmid);
            $cm      = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            require_capability('moodle/course:view', $context->get_course_context());

            // Single query: find all PDFs in this module context (any activity type).
            $sql = "SELECT id, filename, filepath, mimetype, component, filearea, itemid
                      FROM {files}
                     WHERE contextid = ?
                       AND filename != '.'
                       AND mimetype = 'application/pdf'";
            $rows = $DB->get_records_sql($sql, [$context->id]);

            if (empty($rows)) {
                return json_encode(['error' => 'no PDF files found in this activity']);
            }

            $row  = reset($rows);
            $fs   = get_file_storage();
            $file = $fs->get_file_by_id($row->id);
            if (!$file) {
                return json_encode(['error' => 'file not found in storage']);
            }

            $filename = $file->get_filename();

            // Send to document-to-image service.
            $tmpdir  = make_temp_directory('jedomi/docread');
            $tmppath = $tmpdir . '/' . $filename;
            $file->copy_content_to($tmppath);

            $ch = curl_init($serviceurl . '/api/v1/pdf/to-images?dpi=' . $dpi . '&fmt=' . $fmt);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_POSTFIELDS     => ['file' => new \CURLFile($tmppath, 'application/pdf', $filename)],
            ]);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlerr  = curl_error($ch);
            curl_close($ch);

            unlink($tmppath);

            if ($curlerr) {
                return json_encode(['error' => 'document service request failed: ' . $curlerr]);
            }

            if ($httpcode !== 200) {
                return json_encode(['error' => 'document service returned HTTP ' . $httpcode]);
            }

            $data = json_decode($response, true);
            if (!isset($data['pages']) || empty($data['pages'])) {
                return json_encode(['error' => 'document service returned no pages']);
            }

            return json_encode([
                'cmid'        => $cmid,
                'filename'    => $filename,
                'total_pages' => $data['total_pages'],
                'dpi'         => $data['dpi'],
                'pages'       => $data['pages'],
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => 'document read failed: ' . $e->getMessage()]);
        }
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
        if (ob_get_level()) {
            ob_flush();
        }
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
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            return;
        }

        $chunks = mb_str_split($content, 15);
        foreach ($chunks as $chunk) {
            echo "data: " . json_encode([
                'choices' => [['delta' => ['content' => $chunk]]],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
            usleep(2000);
        }
        echo "data: [DONE]\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
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
            'execute_sql' => ($decoded['row_count'] ?? count($decoded['rows'] ?? []))
                . get_string('summary_rows_returned', 'local_jedomi'),
            'describe_table' => get_string('summary_table_structure', 'local_jedomi'),
            'search_tables' => ($decoded['count'] ?? count($decoded['tables'] ?? $decoded) ?? 0)
                . get_string('summary_matching_tables', 'local_jedomi'),
            'get_site_config' => get_string('summary_config_data', 'local_jedomi'),
            'ws_search'   => count($decoded['results'] ?? []) . get_string('summary_functions_found', 'local_jedomi'),
            'ws_describe' => get_string('summary_function_details', 'local_jedomi'),
            'ws_execute' => get_string('summary_ws_executed', 'local_jedomi'),
            default => get_string('summary_done', 'local_jedomi'),
        };
    }
}
