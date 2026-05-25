<?php
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

class agent_runner extends \core\task\scheduled_task {

    /** Max tool call iterations per agent run. */
    const MAX_TOOL_ITERATIONS = 10;

    public function get_name(): string {
        return get_string('agent_runner_task', 'local_jedomi');
    }

    /**
     * Build tool-calling instructions that get appended to the system prompt.
     *
     * Uses a simple text-based protocol that works with ANY model:
     *
     *   TOOL_CALL: tool_name
     *   {json_args}
     *   END_TOOL_CALL
     *
     * The system parses this format, executes the tool, feeds the result back,
     * and asks the model for a final answer. The model should call a tool ONCE
     * and then respond with the final result.
     */
    private function build_tool_instructions(array $tools): string {
        if (empty($tools)) {
            return '';
        }

        $lines = [
            "",
            "=== TOOLS ===",
            "You have tools to get REAL data from the database.",
            "",
        ];

        foreach ($tools as $tool) {
            $fn = $tool['function'] ?? [];
            $lines[] = '- ' . ($fn['name'] ?? '?') . ': ' . ($fn['description'] ?? '');
        }

        $lines = array_merge($lines, [
            "",
            "=== HOW TO USE A TOOL ===",
            "If you need data, output EXACTLY this (no extra text around it):",
            "",
            'TOOL_CALL: tool_name',
            '{"param": "value"}',
            "END_TOOL_CALL",
            "",
            "Example:",
            'TOOL_CALL: execute_sql',
            '{"query": "SELECT COUNT(*) FROM {user}"}',
            "END_TOOL_CALL",
            "",
            "=== RULES ===",
            "1. Call a tool ONCE when you need data.",
            "2. When you get the result, respond with your FINAL ANSWER immediately.",
            "3. Do NOT call tools multiple times. One call is enough.",
            "4. Your final answer MUST be a complete response with the real data.",
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
        //   a) <|tool_call|>{"name":"x","arguments":{...}}<|/tool_call|>
        //   b) <|tool_call|>tool_name\n{json}<|/tool_call|>
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
     * Execute due AI agents.
     */
    public function execute(): void {
        global $DB;

        $start = time();

        mtrace('[AGENT] Starting agent_runner');
        error_log('[AGENT] Starting agent_runner');

        $due = \local_jedomi\agent\agent_manager::get_due_agents();
        $count = count($due);

        mtrace("[AGENT] Starting agent_runner — {$count} due agents");
        error_log("[AGENT] Starting agent_runner — {$count} due agents");

        if (empty($due)) {
            mtrace('[AGENT] No due agents — nothing to do');
            error_log('[AGENT] No due agents — nothing to do');
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
                error_log("[AGENT] {$agentLabel} \"{$excerpt}\"");

                // ═══════════════════════════════════════════════════════════════
                // PRE-EXECUTION GATE — verifica que el creador del agente
                // siga siendo un usuario válido y tenga acceso al contexto.
                // Si no → deshabilita el agente, notifica y saltea.
                // ═══════════════════════════════════════════════════════════════

                // ── 1) ¿El creador existe y no está suspendido/eliminado? ──
                $creator = \core_user::get_user($agent->userid);
                if (!$creator || !empty($creator->deleted)) {
                    mtrace("[AGENT] {$agentLabel} — creator user {$agent->userid} not found — DISABLING");
                    error_log("[AGENT] {$agentLabel} — creator user {$agent->userid} not found — DISABLING");
                    $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                    $this->notify_disabled($agent, 'Usuario creador no encontrado.');
                    $fail++;
                    continue;
                }
                if (!empty($creator->suspended) || !empty($creator->deleted)) {
                    $reason = !empty($creator->suspended) ? 'suspendido' : 'eliminado';
                    mtrace("[AGENT] {$agentLabel} — creator user {$agent->userid} {$reason} — DISABLING");
                    error_log("[AGENT] {$agentLabel} — creator user {$agent->userid} {$reason} — DISABLING");
                    $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                    $this->notify_disabled($agent, "Usuario creador {$reason}.");
                    $fail++;
                    continue;
                }

                // ── 2) ¿El creador aún tiene acceso al contexto del agente? ──
                if (!empty($agent->context_type) && $agent->context_type !== 'site'
                    && !empty($agent->context_id)) {
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
                        error_log("[AGENT] {$agentLabel} — creator {$agent->userid} lost access to context {$agent->context_type}/{$agent->context_id} — DISABLING");
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

                // ── Check cache ──────────────────────────────────────────────
                $cached = \local_jedomi\agent\agent_manager::get_cached_result(
                    $agent->id, $context_hash
                );

                if ($cached !== null) {
                    mtrace("[AGENT] {$agentLabel} — cache HIT ({$context_hash}) — using cached result directly");
                    error_log("[AGENT] {$agentLabel} — cache HIT ({$context_hash}) — using cached");
                    $finalContent = $cached;
                    $totalDuration = 0.0;
                    $totalRounds = 0;
                } else {
                    mtrace("[AGENT] {$agentLabel} — cache MISS ({$context_hash})");
                    error_log("[AGENT] {$agentLabel} — cache MISS ({$context_hash})");

                    // ── Tool definitions ─────────────────────────────────────
                    $tools = \local_jedomi\agent\tool_executor::get_definitions(
                        $agent->id, (int) ($agent->userid ?? 0)
                    );

                    // ── Build messages ──────────────────────────────────────
                    $systemContent = $agent->prompt;
                    $toolInstructions = $this->build_tool_instructions($tools);
                    if (!empty($toolInstructions)) {
                        $systemContent .= $toolInstructions;
                    }

                    $messages = [
                        ['role' => 'system', 'content' => $systemContent],
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
                        }

                        // ── Gateway call ─────────────────────────────────────
                        $gateway_start = microtime(true);
                        mtrace("[AGENT] {$agentLabel} — round {$round} gateway call");
                        error_log("[AGENT] {$agentLabel} — round {$round} gateway call");

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
                            error_log("[AGENT] {$agentLabel} — round {$round} FAILED ({$err_msg}, {$duration_fmt}s)");
                            if ($curl_errno === CURLE_OPERATION_TIMEDOUT) {
                                mtrace("[AGENT] {$agentLabel} — round {$round} TIMEOUT");
                                error_log("[AGENT] {$agentLabel} — round {$round} TIMEOUT");
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
                            error_log("[AGENT] {$agentLabel} — round {$round} invalid JSON");
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
                            error_log("[AGENT] {$agentLabel} — round {$round} final response ({$duration_fmt}s, {$bytes}B)");
                            break;
                        }

                        $toolCount = count($allToolCalls);
                        $via = !empty($nativeToolCalls) ? 'native' : 'text';
                        mtrace("[AGENT] {$agentLabel} — round {$round} {$toolCount} tool call(s) via {$via}");
                        error_log("[AGENT] {$agentLabel} — round {$round} {$toolCount} tool call(s) via {$via}");

                        $messages[] = [
                            'role' => 'assistant',
                            'content' => $content ?: '[Tool call requested]',
                        ];

                        foreach ($allToolCalls as $tc) {
                            $funcName = $tc['function']['name'] ?? '?';
                            $funcArgs = $tc['function']['arguments'] ?? '{}';
                            $tcId = $tc['id'] ?? 'tc_' . uniqid();

                            mtrace("[AGENT] {$agentLabel} — executing tool: {$funcName}()");
                            error_log("[AGENT] {$agentLabel} — executing tool: {$funcName}()");

                            $toolResult = \local_jedomi\agent\tool_executor::execute($funcName, $funcArgs);

                            if (strlen($toolResult) > 64000) {
                                $toolResult = mb_substr($toolResult, 0, 64000)
                                    . "\n\n[Result truncated at 64000 characters]";
                            }

                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $tcId,
                                'content' => $toolResult,
                            ];
                        }

                        // Remove tools so AI cannot call more on next round.
                        $tools = [];
                        $messages[] = [
                            'role' => 'system',
                            'content' => 'You now have the data. Provide your FINAL ANSWER now.',
                        ];
                    }

                    // ── Safety net: if the for loop exhausted without a clean answer ──
                    // This can happen if the model keeps returning TOOL_CALL text
                    // even without tools in the payload.
                    if (empty($finalContent) && !empty($content)) {
                        $finalContent = $content;
                        mtrace("[AGENT] {$agentLabel} — using last content as final ({$totalRounds}r)");
                        error_log("[AGENT] {$agentLabel} — using last content as final ({$totalRounds}r)");
                    }

                    // ── Strip any TOOL_CALL or <|tool_call|> blocks that may have leaked ──
                    $finalContent = trim(preg_replace([
                        '/(?:TOOL_CALL|call):\s*\w+\s*\n.*?\nEND_TOOL_CALL[^\n]*/si',
                        '/<\|(?:tool_call|python_tag)\|>.*?<\|\\/\1\|>/si',
                    ], '', $finalContent ?? ''));

                    if (empty($finalContent)) {
                        $totalDurationFmt = number_format($totalDuration, 2);
                        mtrace("[AGENT] {$agentLabel} — empty final ({$totalDurationFmt}s, {$totalRounds}r)");
                        error_log("[AGENT] {$agentLabel} — empty final ({$totalDurationFmt}s, {$totalRounds}r)");
                        \local_jedomi\agent\agent_manager::mark_run($agent->id, false);
                        $fail++;
                        continue;
                    }

                    // ── Save cache ──────────────────────────────────────────
                    \local_jedomi\agent\agent_manager::set_cached_result(
                        $agent->id, $context_hash, $finalContent
                    );
                    mtrace("[AGENT] {$agentLabel} — cached (" . strlen($finalContent) . "B)");
                    error_log("[AGENT] {$agentLabel} — cached (" . strlen($finalContent) . "B)");
                }

                // ── Mark run ────────────────────────────────────────────────
                \local_jedomi\agent\agent_manager::mark_run($agent->id, true);
                $reloaded = \local_jedomi\agent\agent_manager::get_agent($agent->id);
                $nextrun_str = $reloaded ? userdate($reloaded->nextrun) : 'unknown';
                mtrace("[AGENT] {$agentLabel} — next {$nextrun_str}");
                error_log("[AGENT] {$agentLabel} — next {$nextrun_str}");

                // ── Telegram notification ────────────────────────────────────
                try {
                    $telegram_link = $DB->get_record(
                        'local_jedomi_telegram_links',
                        ['userid' => $agent->userid]
                    );

                    if ($telegram_link && !empty($telegram_link->chatid)) {
                        $summary = mb_substr(strip_tags($finalContent), 0, 200);
                        $msg = "🤖 <b>{$agentLabel}</b>\n";
                        $msg .= "✅ Executed successfully\n";
                        $msg .= "📅 " . userdate(time()) . "\n\n";
                        $msg .= "<i>" . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . "</i>\n\n";
                        $dashboard_url = (new \moodle_url(
                            '/local/jedomi/admin_settings.php',
                            ['tab' => 'agents']
                        ))->out(false);
                        $msg .= "🔗 <a href=\"{$dashboard_url}\">Dashboard</a>";

                        $client = new \local_jedomi\telegram\api_client();
                        $sent = $client->send_message($telegram_link->chatid, $msg);

                        if ($sent) {
                            mtrace("[AGENT] {$agentLabel} — Telegram sent");
                            error_log("[AGENT] {$agentLabel} — Telegram sent");
                        } else {
                            mtrace("[AGENT] {$agentLabel} — Telegram send FAILED");
                            error_log("[AGENT] {$agentLabel} — Telegram send FAILED");
                        }
                    } else {
                        mtrace("[AGENT] {$agentLabel} — no Telegram for user {$agent->userid}");
                        error_log("[AGENT] {$agentLabel} — no Telegram for user {$agent->userid}");
                    }
                } catch (\Exception $e) {
                    mtrace("[AGENT] {$agentLabel} — Telegram error: " . $e->getMessage());
                    error_log("[AGENT] {$agentLabel} — Telegram error: " . $e->getMessage());
                }

                $ok++;

            } catch (\Exception $e) {
                $aid = $agent->id ?? '?';
                $alabel = ($agent->name ?? "Agent #{$aid}");
                mtrace("[AGENT] {$alabel} — ERROR: " . $e->getMessage());
                error_log("[AGENT] {$alabel} — ERROR: " . $e->getMessage());
                try {
                    \local_jedomi\agent\agent_manager::mark_run($aid, false);
                } catch (\Exception $ignored) {
                }
                $fail++;
            }
        }

        $elapsed = time() - $start;
        mtrace("[AGENT] agent_runner done — {$ok} ok, {$fail} fail, {$elapsed}s");
        error_log("[AGENT] agent_runner done — {$ok} ok, {$fail} fail, {$elapsed}s");
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
        error_log("[AGENT] {$label} — DISABLED: {$reason}");

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
            error_log("[AGENT] {$label} — Telegram notify disabled error: " . $e->getMessage());
        }
    }
}
