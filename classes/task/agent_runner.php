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
 * Scheduled task that executes due AI agents through the AI client with tool calling and result caching.
 */
class agent_runner extends \core\task\scheduled_task {
    /** Max tool call iterations per agent run. */
    const MAX_TOOL_ITERATIONS = 50;

    /** @var int Total timeout of each AI call, in seconds. */
    const AI_TIMEOUT = 120;

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
     *
     * @param  array $tools Tool definitions in OpenAI function format.
     * @return string Instructions block to append to the system prompt.
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
     * @param  string $content The assistant response text (modified by reference).
     * @return array  Parsed tool calls in native format (same as OpenAI tool_calls).
     */
    private function parse_text_tool_calls(string &$content): array {
        $calls = [];

        // Skip if content is empty.
        if (empty(trim($content))) {
            return $calls;
        }

        // ... ── Pattern 1: TOOL_CALL: tool_name\n{json}\nEND_TOOL_CALL ──────────
        // Used by our custom system prompt instructions.
        $pattern1 = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n(.+?)\nEND_TOOL_CALL[^\n]*/si';

        // ... ── Pattern 2: <|tool_call|> or <|python_tag|> special tokens ──────
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

        // Try the second pattern, the tool_call marker.
        if (preg_match_all($pattern2, $content, $matches, PREG_SET_ORDER)) {
            $content = trim(preg_replace($pattern2, '', $content));

            foreach ($matches as $m) {
                $inner = trim($m[1]);
                // Try parsing inner as JSON with name/arguments.
                $decoded = json_decode($inner, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['name'])) {
                    $toolname = $decoded['name'];
                    $args = $decoded['arguments'] ?? '{}';
                    if (is_array($args)) {
                        $args = json_encode($args, JSON_UNESCAPED_UNICODE);
                    }
                    $parsed = $this->tool_call_from_match($toolname, $args);
                } else {
                    // Try "tool_name\n{json}" format.
                    $parts = explode("\n", $inner, 2);
                    $toolname = trim($parts[0]);
                    $argsjson = $parts[1] ?? '{}';
                    $parsed = $this->tool_call_from_match($toolname, $argsjson);
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
     *
     * @param  string $name     The tool name extracted from the match.
     * @param  string $argsjson The tool arguments as a JSON string.
     * @return array|null       Tool call entry, or null when the payload is invalid.
     */
    private function tool_call_from_match(string $name, string $argsjson): ?array {
        // Validate JSON.
        json_decode($argsjson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{.*\}/s', $argsjson, $jsonmatch)) {
                $argsjson = $jsonmatch[0];
                json_decode($argsjson);
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
                'arguments' => $argsjson,
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
                // ... ── Log agent info ────────────────────────────────────────────
                $agentlabel = $agent->name ?? "Agent #{$agent->id}";
                $excerpt = mb_substr($agent->prompt, 0, 80);
                mtrace("[AGENT] {$agentlabel} \"{$excerpt}\"");
                \local_jedomi_log("[AGENT] {$agentlabel} (prompt " . strlen($agent->prompt) . "B)");

                // ... ═══════════════════════════════════════════════════════════════
                // PRE-EXECUTION GATE — verifica que el creador del agente
                // siga siendo un usuario válido y tenga acceso al contexto.
                // Si no → deshabilita el agente, notifica y saltea.
                // ═══════════════════════════════════════════════════════════════

                // ... ── 1) ¿El creador existe y no está suspendido/eliminado? ──
                $creator = \core_user::get_user($agent->userid);
                if (!$creator || !empty($creator->deleted)) {
                    mtrace("[AGENT] {$agentlabel} — creator user {$agent->userid} not found — DISABLING");
                    \local_jedomi_log("[AGENT] {$agentlabel} — creator user {$agent->userid} not found — DISABLING");
                    $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                    $this->notify_disabled($agent, 'Usuario creador no encontrado.');
                    $fail++;
                    continue;
                }
                if (!empty($creator->suspended) || !empty($creator->deleted)) {
                    $reason = !empty($creator->suspended) ? 'suspendido' : 'eliminado';
                    mtrace("[AGENT] {$agentlabel} — creator user {$agent->userid} {$reason} — DISABLING");
                    \local_jedomi_log("[AGENT] {$agentlabel} — creator user {$agent->userid} {$reason} — DISABLING");
                    $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                    $this->notify_disabled($agent, "Usuario creador {$reason}.");
                    $fail++;
                    continue;
                }

                // ... ── 2) ¿El creador aún tiene acceso al contexto del agente? ──
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
                        $logline = "[AGENT] {$agentlabel} — creator {$agent->userid} lost access"
                            . " to context {$agent->context_type}/{$agent->context_id} — DISABLING";
                        mtrace($logline);
                        \local_jedomi_log($logline);
                        $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                        $this->notify_disabled($agent, 'Ya no tienes acceso al contexto del agente.');
                        $fail++;
                        continue;
                    }
                }
                // ... ═══════════════════════════════════════════════════════════════
                // END PRE-EXECUTION GATE
                // ═══════════════════════════════════════════════════════════════

                // ... ── Build context hash ────────────────────────────────────────
                $contextdata = [
                    'prompt' => $agent->prompt,
                    'context_type' => $agent->context_type,
                    'context_id' => $agent->context_id,
                    'role_filter' => $agent->role_filter,
                ];
                $contexthash = hash('sha256', serialize($contextdata));

                // ... ── Pre-cache init (needed by both cache hit and miss paths) ──
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

                // ... ── Check cache ──────────────────────────────────────────────
                $cached = \local_jedomi\agent\agent_manager::get_cached_result(
                    $agent->id,
                    $contexthash
                );

                if ($cached !== null) {
                    $finalcontent = '';

                    // ... ── Try semantic plan replay first ────────────────────────────
                    $cachedplan = \local_jedomi\agent\agent_manager::get_cached_plan(
                        $agent->id,
                        $contexthash
                    );

                    if ($cachedplan && !empty($cachedplan['plan'])) {
                        mtrace("[AGENT] {$agentlabel} — cache HIT plan ({$contexthash}) — replaying "
                            . count($cachedplan['plan']) . " tools");
                        \local_jedomi_log("[AGENT] {$agentlabel} — cache HIT plan ({$contexthash}) — replaying tools");

                        // Initialize tool instances with correct user context before replay.
                        \local_jedomi\agent\tool_executor::get_definitions(
                            $agent->id,
                            (int) ($agent->userid ?? 0)
                        );

                        // Re-execute all tools from plan.
                        $freshresults = [];
                        $replayfailed = false;
                        foreach ($cachedplan['plan'] as $i => $step) {
                            $toolresult = \local_jedomi\agent\tool_executor::execute(
                                $step['tool'],
                                $step['arguments']
                            );
                            $decoded = json_decode($toolresult, true);
                            if (!empty($decoded['error'])) {
                                $logline = "[AGENT] {$agentlabel} — replay tool {$step['tool']}"
                                    . " FAILED: {$decoded['error']}";
                                mtrace($logline);
                                \local_jedomi_log($logline);
                                $replayfailed = true;
                                break;
                            }
                            if (strlen($toolresult) > 64000) {
                                $toolresult = mb_substr($toolresult, 0, 64000)
                                    . "\n\n[Result truncated at 64000 characters]";
                            }
                            $freshresults[] = [
                                'tool' => $step['tool'],
                                'result' => $toolresult,
                            ];
                        }

                        if (!$replayfailed) {
                            // ... ── Generate answer from fresh data ────────────────────
                            // Replay succeeded — we have fresh tool results. Build a
                            // single-round payload with the ORIGINAL context + fresh
                            // data, so the AI answers based on current data without
                            // needing to discover the approach again.
                            $datactx = "Current data from the system:\n";
                            foreach ($freshresults as $fr) {
                                $datactx .= "\n--- {$fr['tool']} ---\n{$fr['result']}\n";
                            }

                            $replaymessages = [
                                $system,
                                [
                                    'role' => 'user',
                                    'content' => $agent->prompt . "\n\n" . $datactx,
                                ],
                            ];

                            $replaystart = microtime(true);
                            try {
                                $replayanswer = \local_jedomi\ai\client::instance()->complete(
                                    $replaymessages,
                                    (int) $agent->userid,
                                    [],
                                    self::AI_TIMEOUT
                                );
                                $replaycontent = $replayanswer['content'];
                                $replayduration = microtime(true) - $replaystart;
                                if (!empty(trim($replaycontent))) {
                                    $finalcontent = $replaycontent;
                                    $logline = "[AGENT] {$agentlabel} — replay answer generated"
                                        . " ({$replayduration}s, " . strlen($finalcontent) . "B)";
                                    mtrace($logline);
                                    \local_jedomi_log($logline);
                                }
                            } catch (\Throwable $e) {
                                $replayerror = \local_jedomi\ai\error_message::from_exception($e)['code'];
                                mtrace("[AGENT] {$agentlabel} — replay answer AI error ({$replayerror})");
                                \local_jedomi_log("[AGENT] {$agentlabel} — replay answer AI error ({$replayerror})");
                            }
                        }
                    }

                    // ... ── Fallback: use cached answer text ────────────────────────
                    if (empty($finalcontent)) {
                        mtrace("[AGENT] {$agentlabel} — cache HIT text ({$contexthash}) — using cached text");
                        \local_jedomi_log("[AGENT] {$agentlabel} — cache HIT text ({$contexthash}) — using cached text");
                        $finalcontent = $cached;
                    }
                    $totalduration = 0.0;
                    $totalrounds = 0;
                    // Guard: strip any leaked tool call blocks from cached content.
                    $stripped = trim(preg_replace([
                        '/(?:TOOL_CALL|call):\s*\w+\s*\n.*?\nEND_TOOL_CALL[^\n]*/si',
                    ], '', $finalcontent ?? ''));
                    if (!empty($stripped)) {
                        $finalcontent = $stripped;
                    }
                } else {
                    mtrace("[AGENT] {$agentlabel} — cache MISS ({$contexthash})");
                    \local_jedomi_log("[AGENT] {$agentlabel} — cache MISS ({$contexthash})");

                    // ... ── Tool definitions (reuse web version's lang string descriptions) ──
                    $tools = \local_jedomi_get_tool_definitions($role);

                    // ... ── Build messages (same rich context as web chat) ──────
                    $messages = [
                        $system,
                        ['role' => 'user', 'content' => $agent->prompt],
                    ];

                    // ... ── Multi-turn AI loop ───────────────────────────────────
                    $finalcontent = '';
                    $totalduration = 0.0;
                    $totalrounds = 0;
                    $plan = [];
                    $toolcallcounts = [];

                    for ($round = 1; $round <= self::MAX_TOOL_ITERATIONS; $round++) {
                        $totalrounds = $round;

                        // ... ── AI call ──────────────────────────────────────────
                        $aistart = microtime(true);
                        mtrace("[AGENT] {$agentlabel} — round {$round} AI call");
                        \local_jedomi_log("[AGENT] {$agentlabel} — round {$round} AI call");

                        try {
                            $answer = \local_jedomi\ai\client::instance()->complete(
                                $messages,
                                (int) $agent->userid,
                                $tools,
                                self::AI_TIMEOUT
                            );
                        } catch (\Throwable $e) {
                            $roundduration = microtime(true) - $aistart;
                            $totalduration += $roundduration;
                            $error = \local_jedomi\ai\error_message::from_exception($e);
                            $durationfmt = number_format($roundduration, 2);
                            mtrace("[AGENT] {$agentlabel} — round {$round} FAILED ({$error['code']}, {$durationfmt}s)");
                            \local_jedomi_log("[AGENT] {$agentlabel} — round {$round} FAILED ({$error['code']}, {$durationfmt}s)");
                            if ($round === 1) {
                                throw new \RuntimeException($error['message']);
                            }
                            break;
                        }
                        $roundduration = microtime(true) - $aistart;
                        $totalduration += $roundduration;

                        $nativetoolcalls = $answer['tool_calls'];
                        $content = $answer['content'];

                        // ... ── Parse text-based tool calls (only if tools are available) ──
                        $texttoolcalls = [];
                        if (!empty($tools)) {
                            $texttoolcalls = $this->parse_text_tool_calls($content);
                        }
                        $alltoolcalls = array_merge($nativetoolcalls, $texttoolcalls);

                        if (empty($alltoolcalls)) {
                            $finalcontent = $content;
                            $durationfmt = number_format($roundduration, 2);
                            $bytes = strlen($content);
                            $logline = "[AGENT] {$agentlabel} — round {$round} final response"
                                . " ({$durationfmt}s, {$bytes}B)";
                            mtrace($logline);
                            \local_jedomi_log($logline);
                            break;
                        }

                        $toolcount = count($alltoolcalls);
                        $via = !empty($nativetoolcalls) ? 'native' : 'text';
                        mtrace("[AGENT] {$agentlabel} — round {$round} {$toolcount} tool call(s) via {$via}");
                        \local_jedomi_log("[AGENT] {$agentlabel} — round {$round} {$toolcount} tool call(s) via {$via}");

                        // The assistant message carries every tool call (native or parsed from text)
                        // before the tool results: strict OpenAI backends reject orphan tool messages.
                        $messages[] = [
                            'role' => 'assistant',
                            'content' => $content !== '' ? $content : null,
                            'tool_calls' => $alltoolcalls,
                        ];

                        foreach ($alltoolcalls as $tc) {
                            $funcname = $tc['function']['name'] ?? '?';
                            $funcargs = $tc['function']['arguments'] ?? '{}';
                            $tcid = $tc['id'] ?? 'tc_' . uniqid();

                            // ... ── Loop detection: hash by tool name + stable args ──
                            $decodedargs = is_string($funcargs) ? json_decode($funcargs, true) : $funcargs;
                            $stableargs = is_array($decodedargs) ? self::stable_args_signature($decodedargs) : md5($funcargs);
                            $callkey = $funcname . '|' . $stableargs;
                            $toolcallcounts[$callkey] = ($toolcallcounts[$callkey] ?? 0) + 1;

                            if ($toolcallcounts[$callkey] > 5) {
                                $logline = "[AGENT] {$agentlabel} — LOOP DETECTED: {$funcname}"
                                    . " repeated {$toolcallcounts[$callkey]}x";
                                mtrace($logline);
                                \local_jedomi_log($logline);
                                $toolresult = json_encode([
                                    'error' => 'loop_detected',
                                    'tool' => $funcname,
                                    'detail' => 'Same tool with same arguments called '
                                        . $toolcallcounts[$callkey]
                                        . ' times. Try different parameters or a different tool.',
                                ]);
                            } else {
                                mtrace("[AGENT] {$agentlabel} — executing tool: {$funcname}()");
                                \local_jedomi_log("[AGENT] {$agentlabel} — executing tool: {$funcname}()");

                                $plan[] = [
                                    'tool' => $funcname,
                                    'arguments' => $funcargs,
                                ];

                                $toolresult = \local_jedomi\agent\tool_executor::execute($funcname, $funcargs);

                                if (strlen($toolresult) > 64000) {
                                    $toolresult = mb_substr($toolresult, 0, 64000)
                                        . "\n\n[Result truncated at 64000 characters]";
                                }
                            }

                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $tcid,
                                'content' => $toolresult,
                            ];
                        }
                    }

                    // ... ── Safety net: if the for loop exhausted without a clean answer ──
                    // This can happen if:
                    // - The model returns empty content (some models do when they get tool results)
                    // - The model keeps returning TOOL_CALL text even without tools
                    // Loop detection errors are fed back to the model as tool results so it
                    // can self-correct (same behavior as the web proxy handler).
                    if (empty($finalcontent)) {
                        // Try last loop iteration content first.
                        if (!empty($content)) {
                            $finalcontent = $content;
                        } else {
                            // Fallback: extract content from the last non-error tool result.
                            foreach (array_reverse($messages) as $msg) {
                                if (($msg['role'] ?? '') === 'tool' && !empty($msg['content'])) {
                                    $decoded = json_decode($msg['content'], true);
                                    if (!empty($decoded['error'])) {
                                        continue;
                                    }
                                    $finalcontent = is_string($msg['content'])
                                        ? $msg['content']
                                        : json_encode($msg['content'], JSON_UNESCAPED_UNICODE);
                                    break;
                                }
                            }
                        }
                        if (!empty($finalcontent)) {
                            $logline = "[AGENT] {$agentlabel} — fallback final"
                                . " ({$totalrounds}r, " . strlen($finalcontent) . "B)";
                            mtrace($logline);
                            \local_jedomi_log($logline);
                        }
                    }

                    // ... ── Strip any TOOL_CALL or <|tool_call|> blocks that may have leaked ──
                    $hadtoolcallblocks = preg_match(
                        '/(?:TOOL_CALL|call):\s*\w+/si',
                        $finalcontent ?? ''
                    );
                    $stripped = trim(preg_replace([
                        '/(?:TOOL_CALL|call):\s*\w+\s*\n.*?\nEND_TOOL_CALL[^\n]*/si',
                        '/<\|(tool_call|python_tag)\|>.*?<\|\\/\1\|>/si',
                    ], '', $finalcontent ?? ''));

                    if (!empty($stripped)) {
                        $finalcontent = $stripped;
                    } else if ($hadtoolcallblocks) {
                        // Content was ONLY tool call blocks — fallback to last tool result (skip errors).
                        $finalcontent = '';
                        foreach (array_reverse($messages ?? []) as $msg) {
                            if (($msg['role'] ?? '') === 'tool' && !empty($msg['content'])) {
                                $decoded = json_decode($msg['content'], true);
                                if (!empty($decoded['error'])) {
                                    continue;
                                }
                                $finalcontent = is_string($msg['content'])
                                    ? $msg['content']
                                    : json_encode($msg['content'], JSON_UNESCAPED_UNICODE);
                                break;
                            }
                        }
                    }

                    if (empty($finalcontent)) {
                        $totaldurationfmt = number_format($totalduration, 2);
                        mtrace("[AGENT] {$agentlabel} — empty final ({$totaldurationfmt}s, {$totalrounds}r)");
                        \local_jedomi_log("[AGENT] {$agentlabel} — empty final ({$totaldurationfmt}s, {$totalrounds}r)");
                        \local_jedomi\agent\agent_manager::mark_run($agent->id, false);
                        $fail++;
                        continue;
                    }

                    // ... ── Save cache ──────────────────────────────────────────
                    $allreadonly = true;
                    if (!empty($plan)) {
                        foreach ($plan as $p) {
                            if (!\local_jedomi\agent\agent_manager::is_tool_readonly($p['tool'])) {
                                $allreadonly = false;
                                break;
                            }
                        }
                    }
                    if (!empty($plan) && $allreadonly) {
                        \local_jedomi\agent\agent_manager::set_cached_plan(
                            $agent->id,
                            $contexthash,
                            $plan,
                            $finalcontent
                        );
                        $logline = "[AGENT] {$agentlabel} — cached plan (" . count($plan)
                            . " tools, " . strlen($finalcontent) . "B answer)";
                        mtrace($logline);
                        \local_jedomi_log($logline);
                    } else {
                        \local_jedomi\agent\agent_manager::set_cached_result(
                            $agent->id,
                            $contexthash,
                            $finalcontent
                        );
                        $why = empty($plan) ? 'no tools' : 'write tool detected';
                        mtrace("[AGENT] {$agentlabel} — cached text ({$why}, " . strlen($finalcontent) . "B)");
                        \local_jedomi_log("[AGENT] {$agentlabel} — cached text ({$why}, " . strlen($finalcontent) . "B)");
                    }
                }

                // ... ── Mark run ────────────────────────────────────────────────
                \local_jedomi\agent\agent_manager::mark_run($agent->id, true);
                $reloaded = \local_jedomi\agent\agent_manager::get_agent($agent->id);
                $nextrunstr = $reloaded ? userdate($reloaded->nextrun) : 'unknown';
                mtrace("[AGENT] {$agentlabel} — next {$nextrunstr}");
                \local_jedomi_log("[AGENT] {$agentlabel} — next {$nextrunstr}");

                // ... ── Telegram notification ────────────────────────────────────
                try {
                    $telegramlink = $DB->get_record(
                        'local_jedomi_telegram_links',
                        ['userid' => $agent->userid]
                    );

                    if ($telegramlink && !empty($telegramlink->chatid)) {
                        $sent = \local_jedomi_send_agent_notification(
                            $telegramlink->chatid,
                            $agentlabel,
                            $finalcontent
                        );

                        if ($sent) {
                            mtrace("[AGENT] {$agentlabel} — Telegram sent");
                            \local_jedomi_log("[AGENT] {$agentlabel} — Telegram sent");
                        } else {
                            mtrace("[AGENT] {$agentlabel} — Telegram send FAILED");
                            \local_jedomi_log("[AGENT] {$agentlabel} — Telegram send FAILED");
                        }
                    } else {
                        mtrace("[AGENT] {$agentlabel} — no Telegram for user {$agent->userid}");
                        \local_jedomi_log("[AGENT] {$agentlabel} — no Telegram for user {$agent->userid}");
                    }
                } catch (\Exception $e) {
                    mtrace("[AGENT] {$agentlabel} — Telegram error: " . $e->getMessage());
                    \local_jedomi_log("[AGENT] {$agentlabel} — Telegram error: " . $e->getMessage());
                }

                $ok++;
            } catch (\Exception $e) {
                $aid = $agent->id ?? '?';
                $alabel = ($agent->name ?? "Agent #{$aid}");
                mtrace("[AGENT] {$alabel} — ERROR: " . $e->getMessage());
                \local_jedomi_log("[AGENT] {$alabel} — ERROR: " . $e->getMessage());
                try {
                    \local_jedomi\agent\agent_manager::mark_run($aid, false);
                    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
                } catch (\Exception $ignored) {
                    // Best-effort: agent already failed, do not mask the original error.
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
            // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
        } catch (\Exception $ignored) {
            // Best-effort: the agent is being disabled anyway.
        }

        try {
            $telegramlink = $DB->get_record(
                'local_jedomi_telegram_links',
                ['userid' => $agent->userid]
            );
            if ($telegramlink && !empty($telegramlink->chatid)) {
                $msg = "🤖 <b>{$label}</b>\n";
                $msg .= "⛔ Agent disabled\n";
                $msg .= "📅 " . userdate(time()) . "\n\n";
                $msg .= "<i>" . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . "</i>";
                $client = new \local_jedomi\telegram\api_client();
                $client->send_message($telegramlink->chatid, $msg);
            }
        } catch (\Exception $e) {
            mtrace("[AGENT] {$label} — Telegram notify disabled error: " . $e->getMessage());
            \local_jedomi_log("[AGENT] {$label} — Telegram notify disabled error: " . $e->getMessage());
        }
    }
}
