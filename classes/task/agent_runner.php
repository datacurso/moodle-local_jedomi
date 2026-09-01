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
 * Scheduled task: execute due AI agents.
 *
 * Supports TWO tool-calling mechanisms:
 *  1. Native OpenAI function_calling (for models that support "tools").
 *  2. Text-based TOOL_CALL protocol (works with ANY model).
 *
 * The text-based protocol appends instructions to the system prompt telling
 * the AI to respond with:
 *   TOOL_CALL: tool_name
 *   {"json": "arguments"}
 *   END_TOOL_CALL
 *
 * Both mechanisms feed into the same multi-turn execution loop.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Scheduled task that executes due AI agents through the gateway with tool calling and result caching.
 */
class agent_runner extends \core\task\scheduled_task {
    /** Max tool call iterations per agent run. */
    const MAX_TOOL_ITERATIONS = 50;

    /**
     * Return the localised name of this scheduled task.
     *
     * @return string Task name from the language pack.
     */
    public function get_name(): string {
        return get_string('agent_runner_task', 'local_jedomi');
    }

    /**
     * Build tool-calling instructions appended to the system prompt.
     *
     * Lists available tools from lang string descriptions and provides
     * workflow guidance (search_tables → describe_table → execute_sql).
     * Native OpenAI tool_calls are used primarily; text protocol is a fallback.
     */
    private function build_tool_instructions(array $tools): string {
        if (empty($tools)) {
            return '';
        }

        $lines = [
            "",
            "=== AVAILABLE TOOLS ===",
            "You have tools to get REAL data from Moodle. Use them to answer the user's question.",
            "",
        ];

        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? [];
            $lines[] = '- ' . ($fn['name'] ?? '?') . ': ' . ($fn['description'] ?? '');
        }

        $lines = array_merge($lines, [
            "",
            "=== RECOMMENDED WORKFLOW ===",
            "1. search_tables(keyword in ENGLISH) → find the correct table name for your question.",
            "2. describe_table(tablename) → understand the table's columns and structure.",
            "3. execute_sql(SELECT query) → retrieve actual data from the database.",
            "",
            "Call at most 2 search_tables calls. Once you identify the table, move to describe_table and execute_sql.",
            "",
            "=== RULES ===",
            "1. Call tools to get the data you need.",
            "2. When you have enough data, stop and provide your FINAL ANSWER.",
            "3. You have up to " . self::MAX_TOOL_ITERATIONS . " rounds total.",
            "4. Your FINAL ANSWER MUST include the actual data, not tool descriptions.",
        ]);

        return implode("\n", $lines);
    }

    /**
     * Parse text-based TOOL_CALL blocks from AI response content.
     *
     * @param  string $content The assistant response text.
     * @return array  Parsed tool calls in native format (same as OpenAI tool_calls).
     */
    private function parse_text_tool_calls(string &$content): array {
        $calls = [];

        // Skip if content is empty.
        if (empty(trim($content))) {
            return $calls;
        }

        // ── Pattern 1: TOOL_CALL: tool_name\n{json}\nEND_TOOL_CALL ──────────
        // Used by our custom system prompt instructions.
        $pattern1 = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n(.+?)\nEND_TOOL_CALL[^\n]*/si';

        // ── Pattern 2: <|tool_call|> or <|python_tag|> special tokens ──────
        // Used by Llama 3.x models and similar architectures.
        // Two sub-patterns:
        // a) <|tool_call|>{"name":"x","arguments":{...}}<|/tool_call|>
        // b) <|tool_call|>tool_name\n{json}<|/tool_call|>
        $pattern2 = '/<\|(?:tool_call|python_tag)\|>(.+?)<\|\\/\1\|>/si';

        // Try pattern 1 first.
        if (preg_match_all($pattern1, $content, $matches, PREG_SET_ORDER)) {
            $content = trim(preg_replace($pattern1, '', $content));

            foreach ($matches as $m) {
                $parsed = $this->tool_call_from_match($m[1], $m[2]);
                if ($parsed) {
                    $calls[] = $parsed;
                }
            }
            return $calls;
        }

        // Try pattern 2 (<|tool_call|>).
        if (preg_match_all($pattern2, $content, $matches, PREG_SET_ORDER)) {
            $content = trim(preg_replace($pattern2, '', $content));

            foreach ($matches as $m) {
                $inner = trim($m[1]);
                // Try parsing inner as JSON with name/arguments.
                $decoded = json_decode($inner, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['name'])) {
                    $toolName = $decoded['name'];
                    $args = $decoded['arguments'] ?? '{}';
                    if (is_array($args)) {
                        $args = json_encode($args, JSON_UNESCAPED_UNICODE);
                    }
                    $parsed = $this->tool_call_from_match($toolName, $args);
                } else {
                    // Try "tool_name\n{json}" format.
                    $parts = explode("\n", $inner, 2);
                    $toolName = trim($parts[0]);
                    $argsJson = $parts[1] ?? '{}';
                    $parsed = $this->tool_call_from_match($toolName, $argsJson);
                }
                if ($parsed) {
                    $calls[] = $parsed;
                }
            }
            return $calls;
        }

        return $calls;
    }

    /**
     * Build a tool call entry from a name + JSON string, validating JSON.
     */
    private function tool_call_from_match(string $name, string $argsJson): ?array {
        // Validate JSON.
        json_decode($argsJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{.*\}/s', $argsJson, $jsonMatch)) {
                $argsJson = $jsonMatch[0];
                json_decode($argsJson);
            }
        }
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return [
            'id' => 'text_' . md5(uniqid('', true)),
            'type' => 'function',
            'function' => [
                'name' => trim($name),
                'arguments' => $argsJson,
            ],
        ];
    }

    /**
     * Build a deterministic signature for tool arguments for loop detection.
     *
     * @param  array $args Tool arguments.
     * @return string      SHA256 hash of sorted JSON.
     */
    private static function stable_args_signature(array $args): string {
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
     * Execute due AI agents.
     */
    public function execute(): void {
        global $DB;

        require_once(__DIR__ . '/../../lib.php');

        $start = time();

        mtrace('[AGENT] Starting agent_runner');
        \local_jedomi_log('[AGENT] Starting agent_runner');

        $due = \local_jedomi\agent\agent_manager::get_due_agents();
        $count = count($due);

        mtrace("[AGENT] Starting agent_runner — {$count} due agents");
        \local_jedomi_log("[AGENT] Starting agent_runner — {$count} due agents");

        if (empty($due)) {
            mtrace('[AGENT] No due agents — nothing to do');
            \local_jedomi_log('[AGENT] No due agents — nothing to do');
            return;
        }

        $ok = 0;
        $fail = 0;

        foreach ($due as $agent) {
            try {
                // ── Log agent info ────────────────────────────────────────────
                $agentLabel = $agent->name ?? "Agent #{$agent->id}";
                $excerpt = mb_substr($agent->prompt, 0, 80);
                mtrace("[AGENT] {$agentLabel} \"{$excerpt}\"");
                \local_jedomi_log("[AGENT] {$agentLabel} (prompt " . strlen($agent->prompt) . "B)");

                // ═══════════════════════════════════════════════════════════════
                // PRE-EXECUTION GATE — verifica que el creador del agente
                // siga siendo un usuario válido y tenga acceso al contexto.
                // Si no → deshabilita el agente, notifica y saltea.
                // ═══════════════════════════════════════════════════════════════

                // ── 1) ¿El creador existe y no está suspendido/eliminado? ──
                $creator = \core_user::get_user($agent->userid);
                if (!$creator || !empty($creator->deleted)) {
                    mtrace("[AGENT] {$agentLabel} — creator user {$agent->userid} not found — DISABLING");
                    \local_jedomi_log("[AGENT] {$agentLabel} — creator user {$agent->userid} not found — DISABLING");
                    $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                    $this->notify_disabled($agent, 'Usuario creador no encontrado.');
                    $fail++;
                    continue;
                }
                if (!empty($creator->suspended) || !empty($creator->deleted)) {
                    $reason = !empty($creator->suspended) ? 'suspendido' : 'eliminado';
                    mtrace("[AGENT] {$agentLabel} — creator user {$agent->userid} {$reason} — DISABLING");
                    \local_jedomi_log("[AGENT] {$agentLabel} — creator user {$agent->userid} {$reason} — DISABLING");
                    $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                    $this->notify_disabled($agent, "Usuario creador {$reason}.");
                    $fail++;
                    continue;
                }

                // ── 2) ¿El creador aún tiene acceso al contexto del agente? ──
                if (
                    !empty($agent->context_type) && $agent->context_type !== 'site'
                    && !empty($agent->context_id)
                ) {
                    $hasaccess = false;
                    try {
                        $context = \context::instance_by_id($agent->context_id);
                        if ($context instanceof \context_course) {
                            // Contexto de curso → verificar matrícula activa.
                            $hasaccess = is_enrolled($context, $creator) || is_viewing($context, $creator);
                        } else {
                            // Otros contextos → verificar capability básica.
                            $hasaccess = has_capability('moodle/course:view', $context, $creator);
                        }
                    } catch (\Exception $e) {
                        // Contexto inválido o eliminado.
                        $hasaccess = false;
                    }
                    if (!$hasaccess) {
                        mtrace("[AGENT] {$agentLabel} — creator {$agent->userid} lost access to context {$agent->context_type}/{$agent->context_id} — DISABLING");
                        \local_jedomi_log("[AGENT] {$agentLabel} — creator {$agent->userid} lost access to context {$agent->context_type}/{$agent->context_id} — DISABLING");
                        $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                        $this->notify_disabled($agent, 'Ya no tienes acceso al contexto del agente.');
                        $fail++;
                        continue;
                    }
                }
                // ═══════════════════════════════════════════════════════════════
                // END PRE-EXECUTION GATE
                // ═══════════════════════════════════════════════════════════════

                // ── Build context hash ────────────────────────────────────────
                $context_data = [
                    'prompt' => $agent->prompt,
                    'context_type' => $agent->context_type,
                    'context_id' => $agent->context_id,
                    'role_filter' => $agent->role_filter,
                ];
                $context_hash = hash('sha256', serialize($context_data));

                // ── Pre-cache init (needed by both cache hit and miss paths) ──
                $role = \local_jedomi_get_user_role_for_user(
                    (int) ($agent->userid ?? 0)
                );
                $spt = [
                    'course_id' => (!empty($agent->context_type) && $agent->context_type === 'course')
                        ? (int) $agent->context_id : 0,
                ];
                $context = [
                    'location' => $agent->context_type ?? 'site',
                    'course_name' => '',
                    'output' => 'telegram',
                ];
                $system = \local_jedomi\proxy\system_message::build($role, $spt, $context);

                // ── Check cache ──────────────────────────────────────────────
                $cached = \local_jedomi\agent\agent_manager::get_cached_result(
                    $agent->id,
                    $context_hash
                );

                if ($cached !== null) {
                    $finalContent = '';

                    // ── Try semantic plan replay first ────────────────────────────
                    $cachedPlan = \local_jedomi\agent\agent_manager::get_cached_plan(
                        $agent->id,
                        $context_hash
                    );

                    if ($cachedPlan && !empty($cachedPlan['plan'])) {
                        mtrace("[AGENT] {$agentLabel} — cache HIT plan ({$context_hash}) — replaying " . count($cachedPlan['plan']) . " tools");
                        \local_jedomi_log("[AGENT] {$agentLabel} — cache HIT plan ({$context_hash}) — replaying tools");

                        // Initialize tool instances with correct user context before replay.
                        \local_jedomi\agent\tool_executor::get_definitions(
                            $agent->id,
                            (int) ($agent->userid ?? 0)
                        );

                        // Re-execute all tools from plan.
                        $freshResults = [];
                        $replayFailed = false;
                        foreach ($cachedPlan['plan'] as $i => $step) {
                            $toolResult = \local_jedomi\agent\tool_executor::execute(
                                $step['tool'],
                                $step['arguments']
                            );
                            $decoded = json_decode($toolResult, true);
                            if (!empty($decoded['error'])) {
                                mtrace("[AGENT] {$agentLabel} — replay tool {$step['tool']} FAILED: {$decoded['error']}");
                                \local_jedomi_log("[AGENT] {$agentLabel} — replay tool {$step['tool']} FAILED: {$decoded['error']}");
                                $replayFailed = true;
                                break;
                            }
                            if (strlen($toolResult) > 64000) {
                                $toolResult = mb_substr($toolResult, 0, 64000)
                                    . "\n\n[Result truncated at 64000 characters]";
                            }
                            $freshResults[] = [
                                'tool' => $step['tool'],
                                'result' => $toolResult,
                            ];
                        }

                        if (!$replayFailed) {
                            // ── Generate answer from fresh data ────────────────────
                            // Replay succeeded — we have fresh tool results. Build a
                            // single-round payload with the ORIGINAL context + fresh
                            // data, so the AI answers based on current data without
                            // needing to discover the approach again.
                            $dataCtx = "Current data from the system:\n";
                            foreach ($freshResults as $fr) {
                                $dataCtx .= "\n--- {$fr['tool']} ---\n{$fr['result']}\n";
                            }

                            $replayMessages = [
                                $system,
                                [
                                    'role' => 'user',
                                    'content' => $agent->prompt . "\n\n" . $dataCtx,
                                ],
                            ];

                            $gateway_url = rtrim(get_config('local_jedomi', 'gateway_url'), '/');
                            $api_key = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';
                            $model = get_config('local_jedomi', 'model') ?: 'jedomi-e4b';
                            $maxTokens = (int) get_config('local_jedomi', 'max_tokens');
                            if ($maxTokens < 256) {
                                $maxTokens = 16384;
                            }
                            if (empty($gateway_url)) {
                                $gateway_url = 'http://localhost:8765';
                            }

                            $replayStart = microtime(true);
                            $ch = curl_init($gateway_url . '/v1/chat/completions');
                            curl_setopt_array($ch, [
                                CURLOPT_POST           => true,
                                CURLOPT_POSTFIELDS     => json_encode([
                                    'model' => $model,
                                    'messages' => $replayMessages,
                                    'stream' => false,
                                    'max_tokens' => $maxTokens,
                                ], JSON_UNESCAPED_UNICODE),
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT        => 120,
                                CURLOPT_HTTPHEADER     => [
                                    'Content-Type: application/json',
                                    'Authorization: Bearer ' . $api_key,
                                ],
                            ]);
                            $replayResponse = curl_exec($ch);
                            $replayHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            $replayErrno = curl_errno($ch);
                            $replayError = curl_error($ch);
                            $replayDuration = microtime(true) - $replayStart;
                            curl_close($ch);

                            if ($replayErrno !== 0) {
                                mtrace("[AGENT] {$agentLabel} — replay answer curl error ({$replayError})");
                                \local_jedomi_log("[AGENT] {$agentLabel} — replay answer curl error ({$replayError})");
                            } else if ($replayHttp === 200) {
                                $replayResult = json_decode($replayResponse, true);
                                $replayContent = $replayResult['choices'][0]['message']['content'] ?? '';
                                if (!empty(trim($replayContent))) {
                                    $finalContent = $replayContent;
                                    mtrace("[AGENT] {$agentLabel} — replay answer generated ({$replayDuration}s, " . strlen($finalContent) . "B)");
                                    \local_jedomi_log("[AGENT] {$agentLabel} — replay answer generated ({$replayDuration}s, " . strlen($finalContent) . "B)");
                                }
                            }
                        }
                    }

                    // ── Fallback: use cached answer text ────────────────────────
                    if (empty($finalContent)) {
                        mtrace("[AGENT] {$agentLabel} — cache HIT text ({$context_hash}) — using cached text");
                        \local_jedomi_log("[AGENT] {$agentLabel} — cache HIT text ({$context_hash}) — using cached text");
                        $finalContent = $cached;
                    }
                    $totalDuration = 0.0;
                    $totalRounds = 0;
                    // Guard: strip any leaked tool call blocks from cached content.
                    $stripped = trim(preg_replace([
                        '/(?:TOOL_CALL|call):\s*\w+\s*\n.*?\nEND_TOOL_CALL[^\n]*/si',
                    ], '', $finalContent ?? ''));
                    if (!empty($stripped)) {
                        $finalContent = $stripped;
                    }
                } else {
                    mtrace("[AGENT] {$agentLabel} — cache MISS ({$context_hash})");
                    \local_jedomi_log("[AGENT] {$agentLabel} — cache MISS ({$context_hash})");

                    // ── Tool definitions (reuse web version's lang string descriptions) ──
                    $tools = \local_jedomi_get_tool_definitions($role);

                    // ── Build messages (same rich context as web chat) ──────
                    $messages = [
                        $system,
                        ['role' => 'user', 'content' => $agent->prompt],
                    ];

                    // ── Gateway config ───────────────────────────────────────
                    $gateway_url = rtrim(get_config('local_jedomi', 'gateway_url'), '/');
                    $api_key = get_config('local_jedomi', 'api_key');
                    $model = get_config('local_jedomi', 'model');

                    if (empty($gateway_url)) {
                        $gateway_url = 'http://localhost:8765';
                    }
                    if (empty($api_key)) {
                        $api_key = 'jedomi-local-key';
                    }
                    if (empty($model)) {
                        $model = 'jedomi-e4b';
                    }

                    $maxTokens = (int) get_config('local_jedomi', 'max_tokens');
                    if ($maxTokens < 256) {
                        $maxTokens = 16384; // Default: 16k tokens.
                    }

                    // ── Multi-turn gateway loop ──────────────────────────────
                    $finalContent = '';
                    $totalDuration = 0.0;
                    $totalRounds = 0;
                    $plan = [];
                    $toolcallCounts = [];

                    for ($round = 1; $round <= self::MAX_TOOL_ITERATIONS; $round++) {
                        $totalRounds = $round;

                        $payload = [
                            'model' => $model,
                            'messages' => $messages,
                            'stream' => false,
                            'max_tokens' => $maxTokens,
                        ];
                        if (!empty($tools)) {
                            $payload['tools'] = $tools;
                            $payload['tool_choice'] = 'auto';
                        }

                        // ── Gateway call ─────────────────────────────────────
                        $gateway_start = microtime(true);
                        mtrace("[AGENT] {$agentLabel} — round {$round} gateway call");
                        \local_jedomi_log("[AGENT] {$agentLabel} — round {$round} gateway call");

                        $ch = curl_init($gateway_url . '/v1/chat/completions');
                        curl_setopt_array($ch, [
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 120,
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/json',
                                'Authorization: Bearer ' . $api_key,
                            ],
                        ]);

                        $response = curl_exec($ch);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curl_errno = curl_errno($ch);
                        $curl_error = curl_error($ch);
                        $roundDuration = microtime(true) - $gateway_start;
                        $totalDuration += $roundDuration;
                        curl_close($ch);

                        if ($curl_errno !== 0 || $http_code !== 200) {
                            $err_msg = $curl_error ?: "HTTP {$http_code}";
                            $duration_fmt = number_format($roundDuration, 2);
                            mtrace("[AGENT] {$agentLabel} — round {$round} FAILED ({$err_msg}, {$duration_fmt}s)");
                            \local_jedomi_log("[AGENT] {$agentLabel} — round {$round} FAILED ({$err_msg}, {$duration_fmt}s)");
                            if ($curl_errno === CURLE_OPERATION_TIMEDOUT) {
                                mtrace("[AGENT] {$agentLabel} — round {$round} TIMEOUT");
                                \local_jedomi_log("[AGENT] {$agentLabel} — round {$round} TIMEOUT");
                            }
                            if ($round === 1) {
                                throw new \RuntimeException("Gateway failed: {$err_msg}");
                            }
                            break;
                        }

                        $result = json_decode($response, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $duration_fmt = number_format($roundDuration, 2);
                            mtrace("[AGENT] {$agentLabel} — round {$round} invalid JSON");
                            \local_jedomi_log("[AGENT] {$agentLabel} — round {$round} invalid JSON");
                            if ($round === 1) {
                                throw new \RuntimeException('Invalid JSON response from gateway');
                            }
                            break;
                        }

                        $message = $result['choices'][0]['message'] ?? [];
                        $nativeToolCalls = $message['tool_calls'] ?? [];
                        $content = $message['content'] ?? '';

                        // ── Parse text-based tool calls (only if tools are available) ──
                        $textToolCalls = [];
                        if (!empty($tools)) {
                            $textToolCalls = $this->parse_text_tool_calls($content);
                        }
                        $allToolCalls = array_merge($nativeToolCalls, $textToolCalls);

                        if (empty($allToolCalls)) {
                            $finalContent = $content;
                            $duration_fmt = number_format($roundDuration, 2);
                            $bytes = strlen($response);
                            mtrace("[AGENT] {$agentLabel} — round {$round} final response ({$duration_fmt}s, {$bytes}B)");
                            \local_jedomi_log("[AGENT] {$agentLabel} — round {$round} final response ({$duration_fmt}s, {$bytes}B)");
                            break;
                        }

                        $toolCount = count($allToolCalls);
                        $via = !empty($nativeToolCalls) ? 'native' : 'text';
                        mtrace("[AGENT] {$agentLabel} — round {$round} {$toolCount} tool call(s) via {$via}");
                        \local_jedomi_log("[AGENT] {$agentLabel} — round {$round} {$toolCount} tool call(s) via {$via}");

                        $assistantMsg = [
                            'role' => 'assistant',
                        ];
                        if (!empty($nativeToolCalls)) {
                            $assistantMsg['content'] = null;
                            $assistantMsg['tool_calls'] = $nativeToolCalls;
                        } else {
                            $assistantMsg['content'] = $content ?: '[Tool call requested]';
                        }
                        $messages[] = $assistantMsg;

                        foreach ($allToolCalls as $tc) {
                            $funcName = $tc['function']['name'] ?? '?';
                            $funcArgs = $tc['function']['arguments'] ?? '{}';
                            $tcId = $tc['id'] ?? 'tc_' . uniqid();

                            // ── Loop detection: hash by tool name + stable args ──
                            $decodedArgs = is_string($funcArgs) ? json_decode($funcArgs, true) : $funcArgs;
                            $stableArgs = is_array($decodedArgs) ? self::stable_args_signature($decodedArgs) : md5($funcArgs);
                            $callkey = $funcName . '|' . $stableArgs;
                            $toolcallCounts[$callkey] = ($toolcallCounts[$callkey] ?? 0) + 1;

                            if ($toolcallCounts[$callkey] > 5) {
                                mtrace("[AGENT] {$agentLabel} — LOOP DETECTED: {$funcName} repeated {$toolcallCounts[$callkey]}x");
                                \local_jedomi_log("[AGENT] {$agentLabel} — LOOP DETECTED: {$funcName} repeated {$toolcallCounts[$callkey]}x");
                                $toolResult = json_encode([
                                    'error' => 'loop_detected',
                                    'tool' => $funcName,
                                    'detail' => 'Same tool with same arguments called ' . $toolcallCounts[$callkey] . ' times. Try different parameters or a different tool.',
                                ]);
                            } else {
                                mtrace("[AGENT] {$agentLabel} — executing tool: {$funcName}()");
                                \local_jedomi_log("[AGENT] {$agentLabel} — executing tool: {$funcName}()");

                                $plan[] = [
                                    'tool' => $funcName,
                                    'arguments' => $funcArgs,
                                ];

                                $toolResult = \local_jedomi\agent\tool_executor::execute($funcName, $funcArgs);

                                if (strlen($toolResult) > 64000) {
                                    $toolResult = mb_substr($toolResult, 0, 64000)
                                        . "\n\n[Result truncated at 64000 characters]";
                                }
                            }

                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $tcId,
                                'content' => $toolResult,
                            ];
                        }
                    }

                    // ── Safety net: if the for loop exhausted without a clean answer ──
                    // This can happen if:
                    // - The model returns empty content (some models do when they get tool results)
                    // - The model keeps returning TOOL_CALL text even without tools
                    // Loop detection errors are fed back to the model as tool results so it
                    // can self-correct (same behavior as the web proxy handler).
                    if (empty($finalContent)) {
                        // Try last loop iteration content first.
                        if (!empty($content)) {
                            $finalContent = $content;
                        } else {
                            // Fallback: extract content from the last non-error tool result.
                            foreach (array_reverse($messages) as $msg) {
                                if (($msg['role'] ?? '') === 'tool' && !empty($msg['content'])) {
                                    $decoded = json_decode($msg['content'], true);
                                    if (!empty($decoded['error'])) {
                                        continue;
                                    }
                                    $finalContent = is_string($msg['content'])
                                        ? $msg['content']
                                        : json_encode($msg['content'], JSON_UNESCAPED_UNICODE);
                                    break;
                                }
                            }
                        }
                        if (!empty($finalContent)) {
                            mtrace("[AGENT] {$agentLabel} — fallback final ({$totalRounds}r, " . strlen($finalContent) . "B)");
                            \local_jedomi_log("[AGENT] {$agentLabel} — fallback final ({$totalRounds}r, " . strlen($finalContent) . "B)");
                        }
                    }

                    // ── Strip any TOOL_CALL or <|tool_call|> blocks that may have leaked ──
                    $hadToolCallBlocks = preg_match(
                        '/(?:TOOL_CALL|call):\s*\w+/si',
                        $finalContent ?? ''
                    );
                    $stripped = trim(preg_replace([
                        '/(?:TOOL_CALL|call):\s*\w+\s*\n.*?\nEND_TOOL_CALL[^\n]*/si',
                        '/<\|(?:tool_call|python_tag)\|>.*?<\|\\/\1\|>/si',
                    ], '', $finalContent ?? ''));

                    if (!empty($stripped)) {
                        $finalContent = $stripped;
                    } else if ($hadToolCallBlocks) {
                        // Content was ONLY tool call blocks — fallback to last tool result (skip errors).
                        $finalContent = '';
                        foreach (array_reverse($messages ?? []) as $msg) {
                            if (($msg['role'] ?? '') === 'tool' && !empty($msg['content'])) {
                                $decoded = json_decode($msg['content'], true);
                                if (!empty($decoded['error'])) {
                                    continue;
                                }
                                $finalContent = is_string($msg['content'])
                                    ? $msg['content']
                                    : json_encode($msg['content'], JSON_UNESCAPED_UNICODE);
                                break;
                            }
                        }
                    }

                    if (empty($finalContent)) {
                        $totalDurationFmt = number_format($totalDuration, 2);
                        mtrace("[AGENT] {$agentLabel} — empty final ({$totalDurationFmt}s, {$totalRounds}r)");
                        \local_jedomi_log("[AGENT] {$agentLabel} — empty final ({$totalDurationFmt}s, {$totalRounds}r)");
                        \local_jedomi\agent\agent_manager::mark_run($agent->id, false);
                        $fail++;
                        continue;
                    }

                    // ── Save cache ──────────────────────────────────────────
                    $allReadonly = true;
                    if (!empty($plan)) {
                        foreach ($plan as $p) {
                            if (!\local_jedomi\agent\agent_manager::is_tool_readonly($p['tool'])) {
                                $allReadonly = false;
                                break;
                            }
                        }
                    }
                    if (!empty($plan) && $allReadonly) {
                        \local_jedomi\agent\agent_manager::set_cached_plan(
                            $agent->id,
                            $context_hash,
                            $plan,
                            $finalContent
                        );
                        mtrace("[AGENT] {$agentLabel} — cached plan (" . count($plan) . " tools, " . strlen($finalContent) . "B answer)");
                        \local_jedomi_log("[AGENT] {$agentLabel} — cached plan (" . count($plan) . " tools, " . strlen($finalContent) . "B answer)");
                    } else {
                        \local_jedomi\agent\agent_manager::set_cached_result(
                            $agent->id,
                            $context_hash,
                            $finalContent
                        );
                        $why = empty($plan) ? 'no tools' : 'write tool detected';
                        mtrace("[AGENT] {$agentLabel} — cached text ({$why}, " . strlen($finalContent) . "B)");
                        \local_jedomi_log("[AGENT] {$agentLabel} — cached text ({$why}, " . strlen($finalContent) . "B)");
                    }
                }

                // ── Mark run ────────────────────────────────────────────────
                \local_jedomi\agent\agent_manager::mark_run($agent->id, true);
                $reloaded = \local_jedomi\agent\agent_manager::get_agent($agent->id);
                $nextrun_str = $reloaded ? userdate($reloaded->nextrun) : 'unknown';
                mtrace("[AGENT] {$agentLabel} — next {$nextrun_str}");
                \local_jedomi_log("[AGENT] {$agentLabel} — next {$nextrun_str}");

                // ── Telegram notification ────────────────────────────────────
                try {
                    $telegram_link = $DB->get_record(
                        'local_jedomi_telegram_links',
                        ['userid' => $agent->userid]
                    );

                    if ($telegram_link && !empty($telegram_link->chatid)) {
                        $sent = \local_jedomi_send_agent_notification(
                            $telegram_link->chatid,
                            $agentLabel,
                            $finalContent
                        );

                        if ($sent) {
                            mtrace("[AGENT] {$agentLabel} — Telegram sent");
                            \local_jedomi_log("[AGENT] {$agentLabel} — Telegram sent");
                        } else {
                            mtrace("[AGENT] {$agentLabel} — Telegram send FAILED");
                            \local_jedomi_log("[AGENT] {$agentLabel} — Telegram send FAILED");
                        }
                    } else {
                        mtrace("[AGENT] {$agentLabel} — no Telegram for user {$agent->userid}");
                        \local_jedomi_log("[AGENT] {$agentLabel} — no Telegram for user {$agent->userid}");
                    }
                } catch (\Exception $e) {
                    mtrace("[AGENT] {$agentLabel} — Telegram error: " . $e->getMessage());
                    \local_jedomi_log("[AGENT] {$agentLabel} — Telegram error: " . $e->getMessage());
                }

                $ok++;
            } catch (\Exception $e) {
                $aid = $agent->id ?? '?';
                $alabel = ($agent->name ?? "Agent #{$aid}");
                mtrace("[AGENT] {$alabel} — ERROR: " . $e->getMessage());
                \local_jedomi_log("[AGENT] {$alabel} — ERROR: " . $e->getMessage());
                try {
                    \local_jedomi\agent\agent_manager::mark_run($aid, false);
                } catch (\Exception $ignored) {
                }
                $fail++;
            }
        }

        $elapsed = time() - $start;
        mtrace("[AGENT] agent_runner done — {$ok} ok, {$fail} fail, {$elapsed}s");
        \local_jedomi_log("[AGENT] agent_runner done — {$ok} ok, {$fail} fail, {$elapsed}s");
    }

    /**
     * Send a Telegram notification that an agent was disabled, then log it.
     *
     * @param \stdClass $agent The agent record (needs ->id, ->name, ->userid).
     * @param string    $reason Short human-readable reason.
     */
    private function notify_disabled(\stdClass $agent, string $reason): void {
        global $DB;

        $label = $agent->name ?? "Agent #{$agent->id}";
        mtrace("[AGENT] {$label} — DISABLED: {$reason}");
        \local_jedomi_log("[AGENT] {$label} — DISABLED: {$reason}");

        try {
            \local_jedomi\agent\agent_manager::mark_run($agent->id, false);
        } catch (\Exception $ignored) {
        }

        try {
            $telegram_link = $DB->get_record(
                'local_jedomi_telegram_links',
                ['userid' => $agent->userid]
            );
            if ($telegram_link && !empty($telegram_link->chatid)) {
                $msg = "🤖 <b>{$label}</b>\n";
                $msg .= "⛔ Agent disabled\n";
                $msg .= "📅 " . userdate(time()) . "\n\n";
                $msg .= "<i>" . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</i>";
                $client = new \local_jedomi\telegram\api_client();
                $client->send_message($telegram_link->chatid, $msg);
            }
        } catch (\Exception $e) {
            mtrace("[AGENT] {$label} — Telegram notify disabled error: " . $e->getMessage());
            \local_jedomi_log("[AGENT] {$label} — Telegram notify disabled error: " . $e->getMessage());
        }
    }
}
