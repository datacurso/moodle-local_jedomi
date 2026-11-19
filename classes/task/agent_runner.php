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

use aiprovider_datacurso\local\plugin_settings;

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
     * Conditions an agent runs with: the role of its creator, the instructions for its context and the tools of that role.
     *
     * The scheduled run and the preview use the same conditions, so the preview reproduces the real run.
     *
     * @param int $userid Agent creator.
     * @param string $contexttype Context type of the agent (site, course, category or user).
     * @param int|null $contextid Id of the Moodle context of the agent (for a course agent, the course context).
     * @return array{0: string, 1: array, 2: array} Role, system message and tool definitions.
     */
    public static function run_conditions(int $userid, string $contexttype, ?int $contextid): array {
        $role = \local_jedomi_get_user_role_for_user($userid);
        // The agent stores the id of its Moodle context; the instructions need the course that context belongs to.
        $course = null;
        if ($contexttype === 'course' && !empty($contextid)) {
            $agentcontext = \context::instance_by_id((int) $contextid, IGNORE_MISSING);
            $coursecontext = $agentcontext ? $agentcontext->get_course_context(false) : false;
            if ($coursecontext) {
                $course = get_course((int) $coursecontext->instanceid);
            }
        }
        $spt = [
            'course_id' => $course ? (int) $course->id : 0,
        ];
        $context = [
            'location' => $contexttype !== '' ? $contexttype : 'site',
            'course_name' => $course ? format_string($course->fullname, true, ['context' => $coursecontext]) : '',
            'output' => 'telegram',
            'channel' => 'agent',
        ];
        $system = \local_jedomi\proxy\system_message::build($role, $spt, $context);
        return [$role, $system, \local_jedomi_get_tool_definitions($role, $userid, 'agent')];
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

        if (!plugin_settings::is_enabled('local_jedomi')) {
            mtrace(get_string('task_skipped_plugin_disabled', 'local_jedomi'));
            return;
        }

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
            $guard = null;
            try {
                // ... ── Log agent info ────────────────────────────────────────────
                // The logs never keep the name of the agent (it may repeat its instruction) nor the user id of its
                // creator: they keep the agent id and a pseudonym of the creator. The name is only the Telegram title.
                $agenttitle = $agent->name ?? "Agent #{$agent->id}";
                $agentlabel = "Agent #{$agent->id}";
                $creatorref = \local_jedomi_log_pseudonym('user', $agent->userid);
                mtrace("[AGENT] {$agentlabel} (prompt " . strlen($agent->prompt) . "B)");
                \local_jedomi_log("[AGENT] {$agentlabel} (prompt " . strlen($agent->prompt) . "B)");

                // ... ═══════════════════════════════════════════════════════════════
                // PRE-EXECUTION GATE — checks that the creator of the agent
                // is still a valid user with access to the context.
                // If not → disables the agent, notifies and skips it.
                // ═══════════════════════════════════════════════════════════════

                // ... ── 1) Does the creator exist and is not suspended/deleted? ──
                $creator = \core_user::get_user($agent->userid);
                if (!$creator || !empty($creator->deleted)) {
                    mtrace("[AGENT] {$agentlabel} — creator user {$creatorref} not found — DISABLING");
                    \local_jedomi_log("[AGENT] {$agentlabel} — creator user {$creatorref} not found — DISABLING");
                    $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                    $this->notify_disabled($agent, 'agent_disabled_reason_creator_missing');
                    $fail++;
                    continue;
                }
                if (!empty($creator->suspended) || !empty($creator->deleted)) {
                    $reason = !empty($creator->suspended) ? 'suspended' : 'deleted';
                    mtrace("[AGENT] {$agentlabel} — creator user {$creatorref} {$reason} — DISABLING");
                    \local_jedomi_log("[AGENT] {$agentlabel} — creator user {$creatorref} {$reason} — DISABLING");
                    $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                    $this->notify_disabled($agent, 'agent_disabled_reason_creator_' . $reason);
                    $fail++;
                    continue;
                }

                // ... ── 2) Does the creator still have access to the agent context? ──
                if (
                    !empty($agent->context_type) && $agent->context_type !== 'site'
                    && !empty($agent->context_id)
                ) {
                    $hasaccess = self::creator_can_access_context($creator, (int) $agent->context_id);
                    if (!$hasaccess) {
                        $logline = "[AGENT] {$agentlabel} — creator {$creatorref} lost access"
                            . " to context {$agent->context_type}/{$agent->context_id} — DISABLING";
                        mtrace($logline);
                        \local_jedomi_log($logline);
                        $DB->set_field('local_jedomi_agents', 'enabled', 0, ['id' => $agent->id]);
                        $this->notify_disabled($agent, 'agent_disabled_reason_no_access');
                        $fail++;
                        continue;
                    }
                }

                // ... ── 3) Is the course of the agent context in Jedomi? ──
                // An excluded course is outside Jedomi: the run is skipped until the next scheduled hour
                // and the agent stays enabled, so it runs again when the course is back in Jedomi.
                $agentcourseid = \local_jedomi\course_access::context_course_id((int) ($agent->context_id ?? 0));
                if (!\local_jedomi\course_access::is_course_in_jedomi($agentcourseid)) {
                    mtrace("[AGENT] {$agentlabel} — the course of its context is excluded from Jedomi — SKIPPED");
                    \local_jedomi_log("[AGENT] {$agentlabel} — the course of its context is excluded from Jedomi — SKIPPED");
                    self::skip_until_next_run($agent, 'agent_skipped_course_excluded');
                    $fail++;
                    continue;
                }

                // ... ── 4) Does the creator hold one of the roles chosen for the agent? ──
                // The run is skipped until the next scheduled hour; the agent stays enabled and says why.
                if (!self::creator_has_agent_role($creator, $agent)) {
                    mtrace("[AGENT] {$agentlabel} — creator {$creatorref} holds none of the agent roles — SKIPPED");
                    \local_jedomi_log("[AGENT] {$agentlabel} — creator {$creatorref} holds none of the agent roles — SKIPPED");
                    self::skip_until_next_run($agent, 'agent_skipped_role');
                    $fail++;
                    continue;
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
                [, $system, $tools] = self::run_conditions(
                    (int) ($agent->userid ?? 0),
                    (string) ($agent->context_type ?? 'site'),
                    isset($agent->context_id) ? (int) $agent->context_id : null
                );

                // Every tool of this agent runs with the permissions of its creator, from the first run and
                // whatever agent ran before it in the same cycle.
                \local_jedomi\agent\tool_executor::get_definitions((int) $agent->id, (int) $agent->userid);
                // Writes run only when approved for the agent and inside its scope; the rest wait for the creator.
                $guard = new \local_jedomi\agent\write_guard($agent);
                // Tool results and content read from Moodle reach the AI as untrusted data.
                $untrusted = new \local_jedomi\local\untrusted();

                // ... ── Replay a cached read-only plan ─────────────────────────────
                // Only a plan of read-only tools is replayed: its tools run again and the AI answers from their
                // current results. Agents without tools or with a write tool run the whole loop every time, so a
                // run never sends the answer of a previous one.
                $finalcontent = '';
                $totalduration = 0.0;
                $totalrounds = 0;
                $replayed = false;
                $cachedplan = \local_jedomi\agent\agent_manager::get_cached_plan($agent->id, $contexthash);

                if ($cachedplan && !empty($cachedplan['plan'])) {
                    mtrace("[AGENT] {$agentlabel} — cache HIT plan ({$contexthash}) — replaying "
                        . count($cachedplan['plan']) . " tools");
                    \local_jedomi_log("[AGENT] {$agentlabel} — cache HIT plan ({$contexthash}) — replaying tools");

                    // Re-execute all tools from plan; the AI gets their results anonymised.
                    $replayanonymizer = new \local_jedomi\local\anonymizer();
                    $freshresults = [];
                    $replayfailed = false;
                    foreach ($cachedplan['plan'] as $step) {
                        // The guard runs the reading tools of the plan; a write (a cache that does not match what was
                        // saved) is held or rejected and its error discards the plan.
                        $stepargs = json_decode((string) $step['arguments'], true);
                        $toolresult = $guard->run(
                            (string) $step['tool'],
                            is_array($stepargs) ? $stepargs : [],
                            static fn(?array $checked = null): string => \local_jedomi\agent\tool_executor::execute(
                                (string) $step['tool'],
                                $checked === null ? (string) $step['arguments'] : json_encode($checked, JSON_UNESCAPED_UNICODE)
                            )
                        );
                        $decoded = json_decode($toolresult, true);
                        if (!empty($decoded['error'])) {
                            $logline = "[AGENT] {$agentlabel} — replay tool {$step['tool']}"
                                . " FAILED — discarding the plan and running the agent again";
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
                            'result' => $replayanonymizer->anonymize_tool_result($toolresult),
                        ];
                    }

                    if ($replayfailed) {
                        // The plan no longer works (a table or a service changed): the loop below finds a new one.
                        \local_jedomi\agent\agent_manager::clear_agent_cache((int) $agent->id);
                    } else {
                        // ... ── Generate answer from fresh data ────────────────────
                        // Single-round payload with the ORIGINAL instruction + fresh data, so the AI answers from
                        // current data without discovering the approach again.
                        $datactx = "Current data from the system:\n";
                        foreach ($freshresults as $fr) {
                            $datactx .= "\n" . $untrusted->wrap('tool ' . $fr['tool'], $fr['result']) . "\n";
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
                        } catch (\Throwable $e) {
                            // A failed answer is reported as a failure: the previous answer is never sent again.
                            $replayerror = \local_jedomi\ai\error_message::from_exception($e);
                            mtrace("[AGENT] {$agentlabel} — replay answer AI error ({$replayerror['code']})");
                            \local_jedomi_log("[AGENT] {$agentlabel} — replay answer AI error ({$replayerror['code']})");
                            throw new \local_jedomi\agent\run_failure($replayerror['message']);
                        }
                        $totalduration = microtime(true) - $replaystart;
                        $totalrounds = 1;
                        $finalcontent = trim(preg_replace(
                            '/(?:TOOL_CALL|call):\s*\w+\s*\n.*?\nEND_TOOL_CALL[^\n]*/si',
                            '',
                            $replayanonymizer->restore((string) $replayanswer['content'])
                        ));
                        if ($finalcontent === '') {
                            throw new \local_jedomi\agent\run_failure(get_string('agent_error_empty_answer', 'local_jedomi'));
                        }
                        $replayed = true;
                        $logline = "[AGENT] {$agentlabel} — replay answer generated"
                            . " (" . number_format($totalduration, 2) . "s, " . strlen($finalcontent) . "B)";
                        mtrace($logline);
                        \local_jedomi_log($logline);
                    }
                }

                if (!$replayed) {
                    mtrace("[AGENT] {$agentlabel} — cache MISS ({$contexthash})");
                    \local_jedomi_log("[AGENT] {$agentlabel} — cache MISS ({$contexthash})");

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
                    // The same call failing with the same error ends the run (the loop detector cuts identical calls
                    // later), and an answer without text is asked again once.
                    $toolerrors = new \local_jedomi\local\repeated_tool_error();
                    $retriedempty = false;
                    // Personal data returned by the tools reaches the AI anonymised with the level of the Privacy
                    // tab; the arguments it proposes and the final answer get the real values back.
                    $anonymizer = new \local_jedomi\local\anonymizer();

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
                                throw new \local_jedomi\agent\run_failure($error['message']);
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

                        if (empty($alltoolcalls) && !$retriedempty && \local_jedomi\ai\empty_answer::is_empty($answer)) {
                            $retriedempty = true;
                            $logline = "[AGENT] {$agentlabel} — round {$round} empty answer"
                                . " ({$answer['finish_reason']}) — asking again";
                            mtrace($logline);
                            \local_jedomi_log($logline);
                            $messages[] = \local_jedomi\ai\empty_answer::retry_message((string) $answer['finish_reason']);
                            continue;
                        }

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
                            $realargs = is_string($funcargs) ? json_decode($funcargs, true) : $funcargs;
                            $unrestored = null;
                            if (is_array($realargs) && !empty($realargs)) {
                                try {
                                    $funcargs = json_encode(
                                        $anonymizer->restore_tool_args($funcname, $realargs),
                                        JSON_UNESCAPED_UNICODE
                                    );
                                } catch (\local_jedomi\local\unrestored_placeholder_exception $e) {
                                    // The query is not run against the text of a placeholder (RISK-001).
                                    $unrestored = $e->tool_result();
                                }
                            }

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
                            } else if ($unrestored !== null) {
                                $toolresult = $unrestored;
                            } else {
                                mtrace("[AGENT] {$agentlabel} — executing tool: {$funcname}()");
                                \local_jedomi_log("[AGENT] {$agentlabel} — executing tool: {$funcname}()");

                                $plan[] = [
                                    'tool' => $funcname,
                                    'arguments' => $funcargs,
                                ];

                                if (\local_jedomi\agent\tool_executor::is_offered($funcname)) {
                                    $toolresult = $guard->run(
                                        $funcname,
                                        is_array($decodedargs) ? $decodedargs : [],
                                        // An approved write runs with the validated arguments the guard checked.
                                        static fn(?array $checked = null): string => \local_jedomi\agent\tool_executor::execute(
                                            $funcname,
                                            $checked === null ? $funcargs : json_encode($checked, JSON_UNESCAPED_UNICODE)
                                        )
                                    );
                                } else {
                                    // A tool that is not offered to the role of the creator is rejected, never held.
                                    $toolresult = \local_jedomi\agent\tool_executor::execute($funcname, $funcargs);
                                }

                                if (strlen($toolresult) > 64000) {
                                    $toolresult = mb_substr($toolresult, 0, 64000)
                                        . "\n\n[Result truncated at 64000 characters]";
                                }
                            }

                            $messages[] = [
                                'role' => 'tool',
                                'tool_call_id' => $tcid,
                                'content' => $untrusted->wrap('tool ' . $funcname, $anonymizer->anonymize_tool_result($toolresult)),
                            ];

                            if ($toolerrors->record($callkey, $toolresult)) {
                                $logline = "[AGENT] {$agentlabel} — {$funcname} failed with the same error"
                                    . " " . \local_jedomi\local\repeated_tool_error::LIMIT . "x — stopping";
                                mtrace($logline);
                                \local_jedomi_log($logline);
                                throw new \local_jedomi\agent\run_failure(
                                    get_string('error_tool_repeated', 'local_jedomi', $funcname)
                                );
                            }
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
                                    $toolcontent = $untrusted->unwrap((string) $msg['content']);
                                    $decoded = json_decode($toolcontent, true);
                                    if (!empty($decoded['error'])) {
                                        continue;
                                    }
                                    $finalcontent = $toolcontent;
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
                                $toolcontent = $untrusted->unwrap((string) $msg['content']);
                                $decoded = json_decode($toolcontent, true);
                                if (!empty($decoded['error'])) {
                                    continue;
                                }
                                $finalcontent = $toolcontent;
                                break;
                            }
                        }
                    }

                    // The answer was written from anonymised data: the creator gets the real values back.
                    $finalcontent = $anonymizer->restore((string) $finalcontent);

                    if (empty($finalcontent)) {
                        $totaldurationfmt = number_format($totalduration, 2);
                        mtrace("[AGENT] {$agentlabel} — empty final ({$totaldurationfmt}s, {$totalrounds}r)");
                        \local_jedomi_log("[AGENT] {$agentlabel} — empty final ({$totaldurationfmt}s, {$totalrounds}r)");
                        throw new \local_jedomi\agent\run_failure(get_string('agent_error_empty_answer', 'local_jedomi'));
                    }

                    // ... ── Save cache ──────────────────────────────────────────
                    // Only a plan of read-only tools is kept, to be replayed with current data. An answer without
                    // tools or after a write action is never stored as a cached text.
                    $allreadonly = true;
                    foreach ($plan as $p) {
                        if (!\local_jedomi\agent\agent_manager::is_tool_readonly($p['tool'])) {
                            $allreadonly = false;
                            break;
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
                        \local_jedomi\agent\agent_manager::clear_agent_cache((int) $agent->id);
                        $why = empty($plan) ? 'no tools' : 'write tool detected';
                        mtrace("[AGENT] {$agentlabel} — not cached ({$why})");
                        \local_jedomi_log("[AGENT] {$agentlabel} — not cached ({$why})");
                    }
                }

                // ... ── Mark run ────────────────────────────────────────────────
                \local_jedomi\agent\agent_manager::mark_run($agent->id, true, $finalcontent);
                $reloaded = \local_jedomi\agent\agent_manager::get_agent($agent->id);
                $nextrunstr = $reloaded ? userdate($reloaded->nextrun) : 'unknown';
                mtrace("[AGENT] {$agentlabel} — next {$nextrunstr}");
                \local_jedomi_log("[AGENT] {$agentlabel} — next {$nextrunstr}");

                // ... ── Telegram notification ────────────────────────────────────
                try {
                    $telegramlink = $DB->get_record(
                        'local_jedomi_telegram_links',
                        ['userid' => $agent->userid, 'confirmed' => 1]
                    );

                    if (!self::can_notify_by_telegram((int) $agent->userid)) {
                        mtrace("[AGENT] {$agentlabel} — Telegram not allowed for user {$creatorref}");
                        \local_jedomi_log("[AGENT] {$agentlabel} — Telegram not allowed for user {$creatorref}");
                    } else if ($telegramlink && !empty($telegramlink->chatid)) {
                        // The notice goes out in the language of the creator, not in the language of the task.
                        $creatorid = (int) $agent->userid;
                        $sent = \local_jedomi\local\user_language::run(
                            $creatorid,
                            static function () use ($telegramlink, $agenttitle, $finalcontent, $creatorid): bool {
                                return \local_jedomi_send_agent_notification(
                                    $telegramlink->chatid,
                                    $agenttitle,
                                    $finalcontent,
                                    \local_jedomi\agent\agent_manager::creator_timezone($creatorid)
                                );
                            }
                        );

                        if ($sent) {
                            mtrace("[AGENT] {$agentlabel} — Telegram sent");
                            \local_jedomi_log("[AGENT] {$agentlabel} — Telegram sent");
                        } else {
                            mtrace("[AGENT] {$agentlabel} — Telegram send FAILED");
                            \local_jedomi_log("[AGENT] {$agentlabel} — Telegram send FAILED");
                        }
                    } else {
                        mtrace("[AGENT] {$agentlabel} — no Telegram for user {$creatorref}");
                        \local_jedomi_log("[AGENT] {$agentlabel} — no Telegram for user {$creatorref}");
                    }
                } catch (\Throwable $e) {
                    mtrace("[AGENT] {$agentlabel} — Telegram error: " . $e->getMessage());
                    \local_jedomi_log("[AGENT] {$agentlabel} — Telegram error: " . $e->getMessage());
                }

                // The creator gets the writes of the run: run, waiting for approval and rejected.
                $guard->notify_creator();

                $ok++;
            } catch (\Throwable $e) {
                $aid = $agent->id ?? '?';
                $alabel = "Agent #{$aid}";
                // The agent keeps only a safe message as its last error: managers who may not read the instruction see
                // the agent, and a raw message can quote it. The details stay in the technical log, anonymised.
                $lasterror = self::last_error_for($e);
                mtrace("[AGENT] {$alabel} — ERROR ({$lasterror['reference']}): " . $lasterror['message']);
                \local_jedomi_log("[AGENT] {$alabel} — ERROR ({$lasterror['reference']}): " . get_class($e) . ': '
                    . (new \local_jedomi\local\anonymizer(\local_jedomi\local\anonymizer::LEVEL_BASIC))->anonymize_text(
                        \core_text::substr($e->getMessage(), 0, 500)
                    ));
                if ($guard !== null) {
                    // The writes made before the failure (or the cap that stopped the run) are reported too.
                    $guard->notify_creator();
                }
                try {
                    $disabled = \local_jedomi\agent\agent_manager::mark_run((int) $aid, false, $lasterror['message']);
                    if ($disabled) {
                        $this->send_disabled_notice(
                            $agent,
                            'agent_disabled_reason_failures',
                            \local_jedomi\agent\agent_manager::MAX_FAILURES
                        );
                    }
                    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
                } catch (\Throwable $ignored) {
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
     * Safe last error of a failed run and the reference that finds its details in the technical log.
     *
     * A run_failure carries a message written for the user (an AI error or an empty answer) and is kept. Any other
     * failure (database, tool, unexpected error) can quote the instruction, a query or personal data, so the agent
     * only stores a generic message with the reference.
     *
     * @param \Throwable $e Failure of the run.
     * @return array ['message' => string, 'reference' => string of 8 hexadecimal characters].
     */
    public static function last_error_for(\Throwable $e): array {
        $reference = bin2hex(random_bytes(4));
        if ($e instanceof \local_jedomi\agent\run_failure) {
            return ['message' => $e->getMessage(), 'reference' => $reference];
        }
        return ['message' => get_string('agent_error_run_failed', 'local_jedomi', $reference), 'reference' => $reference];
    }

    /**
     * Whether the creator of an agent holds one of the roles chosen for it.
     *
     * The roles of the agent limit when it runs: it only runs while its creator holds at least one of them in the
     * context of the agent or in its parent contexts. Without chosen roles, and for site administrators, it always runs.
     * A creator without any assigned role holds the authenticated user role.
     *
     * @param \stdClass $creator Creator user record.
     * @param \stdClass $agent Agent record (role_filter, context_id).
     * @return bool
     */
    public static function creator_has_agent_role(\stdClass $creator, \stdClass $agent): bool {
        global $CFG, $DB;
        $chosen = !empty($agent->role_filter) ? json_decode((string) $agent->role_filter, true) : [];
        if (!is_array($chosen) || empty($chosen) || is_siteadmin($creator)) {
            return true;
        }
        $context = !empty($agent->context_id) ? \context::instance_by_id((int) $agent->context_id, IGNORE_MISSING) : false;
        $held = [];
        foreach (get_user_roles($context ?: \context_system::instance(), (int) $creator->id, true) as $role) {
            $held[] = (string) $role->shortname;
        }
        if (empty($held)) {
            // Without an assigned role the creator holds the authenticated user role.
            $held[] = (string) $DB->get_field('role', 'shortname', ['id' => (int) ($CFG->defaultuserroleid ?? 0)]);
        }
        return !empty(array_intersect($held, array_map('strval', $chosen)));
    }

    /**
     * Skip the run of an agent until its next scheduled hour: the agent stays enabled and its last error says why.
     *
     * @param \stdClass $agent Agent record.
     * @param string $reasonkey Language string of the reason.
     * @return void
     */
    private static function skip_until_next_run(\stdClass $agent, string $reasonkey): void {
        global $DB;
        $DB->update_record('local_jedomi_agents', (object) [
            'id' => $agent->id,
            'lasterror' => get_string($reasonkey, 'local_jedomi'),
            'nextrun' => \local_jedomi\agent\agent_manager::calculate_next_run(
                (string) $agent->schedule_cron,
                time(),
                \local_jedomi\agent\agent_manager::creator_timezone((int) $agent->userid)
            ),
            'timemodified' => time(),
        ]);
    }

    /**
     * Whether the creator of an agent still has access to the Moodle context the agent runs in.
     *
     * Each context type is checked with what a user of that context normally holds, so an agent of a teacher keeps
     * running: a course needs an enrolment or the right to view it, a category the right to see its course list
     * (and to see hidden categories when it is hidden), a user context is the creator's own or one of a user whose
     * profile the creator may see (a mentor role on that user or a shared course), and a module or block follows its
     * course.
     *
     * @param \stdClass $creator Creator user record.
     * @param int $contextid Moodle context id of the agent.
     * @return bool
     */
    public static function creator_can_access_context(\stdClass $creator, int $contextid): bool {
        try {
            $context = \context::instance_by_id($contextid);
        } catch (\Exception $e) {
            // Invalid or deleted context.
            return false;
        }

        if ($context instanceof \context_system) {
            return true;
        }
        if ($context instanceof \context_coursecat) {
            if (!has_capability('moodle/category:viewcourselist', $context, $creator)) {
                return false;
            }
            $category = \core_course_category::get($context->instanceid, IGNORE_MISSING, true);
            return $category && ($category->visible || has_capability('moodle/category:viewhiddencategories', $context, $creator));
        }
        if ($context instanceof \context_user) {
            if ((int) $context->instanceid === (int) $creator->id) {
                return true;
            }
            if (has_capability('moodle/user:viewdetails', $context, $creator)) {
                return true;
            }
            foreach (enrol_get_shared_courses((int) $creator->id, (int) $context->instanceid, true) as $course) {
                if (has_capability('moodle/user:viewdetails', \context_course::instance($course->id), $creator)) {
                    return true;
                }
            }
            return false;
        }
        $coursecontext = $context->get_course_context(false);
        if (!$coursecontext) {
            return false;
        }
        return is_enrolled($coursecontext, $creator) || is_viewing($coursecontext, $creator);
    }

    /**
     * Whether the results of the agents of a user may be sent to Telegram: the integration is enabled and the roles of
     * the user are allowed to use it.
     *
     * @param int $userid Agent creator.
     * @return bool
     */
    private static function can_notify_by_telegram(int $userid): bool {
        return \local_jedomi\telegram\manager::is_enabled()
            && \local_jedomi\telegram\webhook_handler::is_user_role_allowed($userid);
    }

    /**
     * Record the run of an agent that the pre-execution gate disabled and tell its creator.
     *
     * @param \stdClass $agent The agent record (needs ->id, ->name, ->userid).
     * @param string    $reasonkey Language string of the reason.
     * @param mixed     $a Placeholder of the reason string.
     */
    private function notify_disabled(\stdClass $agent, string $reasonkey, $a = null): void {
        try {
            \local_jedomi\agent\agent_manager::mark_run((int) $agent->id, false, get_string($reasonkey, 'local_jedomi', $a));
            // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best-effort.
        } catch (\Exception $ignored) {
            // Best-effort: the agent is being disabled anyway.
        }
        $this->send_disabled_notice($agent, $reasonkey, $a);
    }

    /**
     * Log that an agent was disabled and send a Telegram notification to its creator when allowed.
     *
     * The notice is written in the language of the creator, not in the language of the scheduled task.
     *
     * @param \stdClass $agent The agent record (needs ->id, ->name, ->userid).
     * @param string    $reasonkey Language string of the reason.
     * @param mixed     $a Placeholder of the reason string.
     */
    private function send_disabled_notice(\stdClass $agent, string $reasonkey, $a = null): void {
        global $DB;

        $label = $agent->name ?? "Agent #{$agent->id}";
        $reason = get_string($reasonkey, 'local_jedomi', $a);
        mtrace("[AGENT] Agent #{$agent->id} — DISABLED: {$reason}");
        \local_jedomi_log("[AGENT] Agent #{$agent->id} — DISABLED: {$reason}");

        try {
            $telegramlink = $DB->get_record(
                'local_jedomi_telegram_links',
                ['userid' => $agent->userid, 'confirmed' => 1]
            );
            if ($telegramlink && !empty($telegramlink->chatid) && self::can_notify_by_telegram((int) $agent->userid)) {
                $msg = self::disabled_notice_text($agent, $label, $reasonkey, $a);
                \local_jedomi\telegram\api_client::instance()->send_message($telegramlink->chatid, $msg);
            }
        } catch (\Exception $e) {
            mtrace("[AGENT] Agent #{$agent->id} — Telegram notify disabled error: " . $e->getMessage());
            \local_jedomi_log("[AGENT] Agent #{$agent->id} — Telegram notify disabled error: " . $e->getMessage());
        }
    }

    /**
     * Text of the notice of a disabled agent, in the language of its creator.
     *
     * @param \stdClass $agent The agent record (needs ->userid).
     * @param string    $label Name of the agent.
     * @param string    $reasonkey Language string of the reason.
     * @param mixed     $a Placeholder of the reason string.
     * @return string HTML for Telegram.
     */
    public static function disabled_notice_text(\stdClass $agent, string $label, string $reasonkey, $a = null): string {
        $userid = (int) $agent->userid;
        return \local_jedomi\local\user_language::run($userid, static function () use ($userid, $label, $reasonkey, $a): string {
            $msg = "🤖 <b>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</b>\n";
            $msg .= "⛔ " . get_string('agent_disabled_notice', 'local_jedomi') . "\n";
            $msg .= "📅 " . userdate(time(), '', \local_jedomi\agent\agent_manager::creator_timezone($userid)) . "\n\n";
            $msg .= "<i>" . htmlspecialchars(get_string($reasonkey, 'local_jedomi', $a), ENT_QUOTES, 'UTF-8') . "</i>";
            return $msg;
        });
    }
}
