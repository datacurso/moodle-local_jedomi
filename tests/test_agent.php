<?php
/**
 * TEST: Scheduled Agent Execution Debug
 *
 * CLI + HTTP. Reproduce EXACTLY what agent_runner.php does when executing a
 * scheduled agent, with FULL debugging output. ONLY for testing — remove.
 *
 * HTTP: GET
 *   https://jedomi.com/local/jedomi/tests/test_agent.php?action=test&prompt=Lista+cursos
 *   https://jedomi.com/local/jedomi/tests/test_agent.php?action=execute&agent_id=1
 *   https://jedomi.com/local/jedomi/tests/test_agent.php?action=full-test&agent_id=1
 *
 * CLI:
 *   php tests/test_agent.php execute --agent-id=1
 *   php tests/test_agent.php test --prompt="lista cursos" --context=site
 *   php tests/test_agent.php clear-cache --agent-id=1
 *
 * @package    local_jedomi
 */

// ── Detect mode ─────────────────────────────────────────────────────────
$isCli = (php_sapi_name() === 'cli');
if ($isCli) {
    define('CLI_SCRIPT', true);
}

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/classes/proxy/system_message.php');
require_once($CFG->dirroot . '/local/jedomi/classes/task/agent_runner.php');

// ── Auth ────────────────────────────────────────────────────────────────
// Bypass login — this is a test file that will be deleted
$admin = get_admin();
if ($admin) {
    global $USER;
    $USER = $admin;
    $USER->ignoresesskey = true;
    $USER->ajax_updatable_user_prefs = [];
}

// HTTP: set content type
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Mode detection ─────────────────────────────────────────────────────
// HTTP: no auth — test file, will be deleted after testing
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Parse params ───────────────────────────────────────────────────────
$action = '';
$agentId = 0;
$prompt = '';
$context = 'site';
$contextId = 0;
$maxRounds = 10;

if ($isCli) {
    // CLI args
    $action = $argv[1] ?? '';
    $agentId = (int) (getenv('AGENT_ID') ?: 0);
    $prompt = getenv('PROMPT') ?: '';
    $context = getenv('CONTEXT') ?: 'site';
    $contextId = (int) (getenv('CONTEXT_ID') ?: 0);
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--agent-id=')) $agentId = (int) substr($arg, 11);
        if (str_starts_with($arg, '--prompt=')) $prompt = substr($arg, 9);
        if (str_starts_with($arg, '--context=')) $context = substr($arg, 10);
        if (str_starts_with($arg, '--context-id=')) $contextId = (int) substr($arg, 13);
        if (str_starts_with($arg, '--rounds=')) $maxRounds = (int) substr($arg, 9);
    }
    if (empty($action) && isset($argv[1])) $action = $argv[1];
} else {
    // HTTP params
    $action = $_REQUEST['action'] ?? '';
    $agentId = (int) ($_REQUEST['agent_id'] ?? 0);
    $prompt = $_REQUEST['prompt'] ?? '';
    $context = $_REQUEST['context'] ?? 'site';
    $contextId = (int) ($_REQUEST['context_id'] ?? 0);
    $maxRounds = (int) ($_REQUEST['rounds'] ?? 10);
}

if (empty($action)) {
    die("Usage:\n  HTTP: ?token=KEY&action=test&prompt=...\n  CLI: php test_agent.php test --prompt=...\n");
}

// ── Output helper ──────────────────────────────────────────────────────
$out = function(string $msg): void {
    echo $msg . "\n";
};

$out(str_repeat('=', 60));
$out("  JEDOMI AGENT DEBUG — " . date('Y-m-d H:i:s') . " [" . ($isCli ? 'CLI' : 'HTTP') . "]");
$out(str_repeat('=', 60));

// ── Route ──────────────────────────────────────────────────────────────
try {
    switch ($action) {

        case 'view-log':
            $logfile = $CFG->dataroot . '/local/jedomi/jedomi.log';
            if (file_exists($logfile)) {
                $lines = file($logfile);
                // Show last 50 lines.
                $start = max(0, count($lines) - 50);
                for ($i = $start; $i < count($lines); $i++) {
                    echo $lines[$i];
                }
            } else {
                echo "Log file not found at: " . $logfile;
            }
            die();

        case 'fix-telegram':
            if (!$agentId) throw new \Exception('agent_id (userid) required');
            if (empty($prompt)) throw new \Exception('chatid required as prompt param');
            $chatid = (int) $prompt;
            $DB = $GLOBALS['DB'];
            $existing = $DB->get_record('local_jedomi_telegram_links', ['userid' => $agentId]);
            if ($existing) {
                $existing->chatid = $chatid;
                $existing->verified = 1;
                $DB->update_record('local_jedomi_telegram_links', $existing);
                $out("Updated Telegram link for user {$agentId}: chatid={$chatid}");
            } else {
                $DB->insert_record('local_jedomi_telegram_links', (object)[
                    'userid' => $agentId,
                    'chatid' => $chatid,
                    'verified' => 1,
                    'timecreated' => time(),
                ]);
                $out("Created Telegram link for user {$agentId}: chatid={$chatid}");
            }
            break;

        case 'transfer-agent':
            if (!$agentId) throw new \Exception('agent_id required');
            if (empty($prompt)) throw new \Exception('target userid required as prompt');
            $targetUserId = (int) $prompt;
            $DB = $GLOBALS['DB'];
            $agent = $DB->get_record('local_jedomi_agents', ['id' => $agentId]);
            if (!$agent) throw new \Exception("Agent {$agentId} not found");
            $oldUserId = $agent->userid;
            $agent->userid = $targetUserId;
            $DB->update_record('local_jedomi_agents', $agent);
            $out("Agent {$agentId} transferred from user {$oldUserId} to user {$targetUserId}");
            break;

        case 'create-agent':
            if (empty($prompt)) throw new \Exception('prompt required');
            $DB = $GLOBALS['DB'];
            $data = (object)[
                'userid'       => $agentId ?: $GLOBALS['USER']->id,
                'name'         => $context ?: 'CSV Generator',
                'prompt'       => $prompt,
                'schedule_cron' => '0 * * * *',
                'context_type' => 'site',
                'context_id'   => null,
                'enabled'      => 1,
            ];
            $newId = \local_jedomi\agent\agent_manager::create_agent($data);
            $out("Agent #{$newId} created for user {$data->userid}");
            break;

        case 'clear-cache':
            if (!$agentId) throw new \Exception('agent_id required');
            $DB = $GLOBALS['DB'];
            $deleted = $DB->delete_records('local_jedomi_agent_cache', ['agentid' => $agentId]);
            $out("Cache borrada para agente {$agentId} ({$deleted} registro(s))");
            break;

        case 'full-test':
        case 'execute':
            if (!$agentId) throw new \Exception('agent_id required');

            $agent = $GLOBALS['DB']->get_record('local_jedomi_agents', ['id' => $agentId]);
            if (!$agent) throw new \Exception("Agent {$agentId} not found");

            $out("Agent #{$agent->id}: {$agent->name}");
            $out("Prompt: {$agent->prompt}");
            $out("Context: {$agent->context_type} #{$agent->context_id}");
            $out("Created by user: {$agent->userid}");
            $out("");

            // Execute agent — returns the final AI response separately from debug output
            $finalContent = execute_agent_debug($agent, $out, $maxRounds);

            // ── Telegram (only for full-test) ──────────────────────────────
            if ($action === 'full-test') {
                $out("");
                $out(str_repeat('─', 40));
                $out("TELEGRAM");
                $out(str_repeat('─', 40));

                $telegram_link = $GLOBALS['DB']->get_record(
                    'local_jedomi_telegram_links',
                    ['userid' => $agent->userid]
                );

                if ($telegram_link && !empty($telegram_link->chatid) && $telegram_link->chatid !== '0') {
                    $agentLabel = $agent->name ?? "Agent #{$agent->id}";

                    $out("Sending to Telegram chat {$telegram_link->chatid}...");
                    $sent = \local_jedomi_send_agent_notification(
                        $telegram_link->chatid,
                        $agentLabel,
                        $finalContent
                    );
                    $out($sent ? "TELEGRAM SENT OK" : "TELEGRAM SEND FAILED");
                } else {
                    $out("No Telegram link for user {$agent->userid}");
                    $out("The user must send /start to the bot first.");
                }
            }
            break;

        case 'test':
            if (empty($prompt)) throw new \Exception('prompt required');

            $agent = (object) [
                'id' => 0,
                'name' => '[TEST]',
                'prompt' => $prompt,
                'userid' => $GLOBALS['USER']->id,
                'context_type' => $context,
                'context_id' => $contextId,
                'role_filter' => '',
            ];

            $out("TEST MODE (no agent saved)");
            $out("Prompt: {$prompt}");
            $out("Context: {$context} #{$contextId}");
            $out("");

            execute_agent_debug($agent, $out, $maxRounds);
            break;

        default:
            throw new \Exception("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out("ERROR: " . get_class($e) . ': ' . $e->getMessage());
}

$out("");
$out(str_repeat('=', 60));
$out("  FIN — " . date('Y-m-d H:i:s'));
$out(str_repeat('=', 60));

// ══════════════════════════════════════════════════════════════════════════
function execute_agent_debug(\stdClass $agent, \Closure $out, int $maxRounds = 10): string {
    global $DB, $CFG, $argv;

    // ── Resolve role ────────────────────────────────────────────────────
    $role = \local_jedomi_get_user_role_for_user((int) $agent->userid);
    $out("Role resolved: {$role}");

    // ── Context hash (for cache) ────────────────────────────────────────
    $contextData = [
        'prompt' => $agent->prompt,
        'context_type' => $agent->context_type,
        'context_id' => $agent->context_id,
        'role_filter' => $agent->role_filter,
    ];
    $contextHash = hash('sha256', serialize($contextData));
    $out("Context hash: {$contextHash}");

    // ── Check cache ────────────────────────────────────────────────────
    $cached = \local_jedomi\agent\agent_manager::get_cached_result($agent->id, $contextHash);
    if ($cached) {
        $out("CACHE HIT (" . strlen($cached) . "B)");

        $cachedPlan = \local_jedomi\agent\agent_manager::get_cached_plan($agent->id, $contextHash);
        if ($cachedPlan && !empty($cachedPlan['plan'])) {
            $out("Cached plan: " . count($cachedPlan['plan']) . " step(s)");
            \local_jedomi\agent\tool_executor::get_definitions($agent->id, (int) $agent->userid);

            // Replay all tools and collect fresh results.
            $freshResults = [];
            $replayOk = true;
            foreach ($cachedPlan['plan'] as $i => $step) {
                $result = \local_jedomi\agent\tool_executor::execute($step['tool'], $step['arguments']);
                $decoded = json_decode($result, true);
                if (!empty($decoded['error'])) {
                    $out("  Replay #{$i} FAILED: {$decoded['error']}");
                    $replayOk = false;
                    break;
                }
                $out("  Replay #{$i} OK");
                if (strlen($result) > 64000) {
                    $result = mb_substr($result, 0, 64000) . "\n\n[Result truncated at 64000 characters]";
                }
                $freshResults[] = [
                    'tool' => $step['tool'],
                    'result' => $result,
                ];
            }
            $out($replayOk ? "Replay SUCCESS" : "Replay FAILED — using cached text");

            // If replay succeeded, generate answer from fresh data (1 AI call).
            if ($replayOk) {
                $dataCtx = "Current data from the system:\n";
                foreach ($freshResults as $fr) {
                    $dataCtx .= "\n--- {$fr['tool']} ---\n{$fr['result']}\n";
                }

                $system = \local_jedomi\proxy\system_message::build($role, [
                    'course_id' => (!empty($agent->context_type) && $agent->context_type === 'course')
                        ? (int) $agent->context_id : 0,
                ], [
                    'location' => $agent->context_type ?? 'site',
                    'course_name' => '',
                    'output' => 'telegram',
                ]);

                $gatewayUrl = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765', '/');
                $apiKey = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';
                $model = get_config('local_jedomi', 'model') ?: 'jedomi-e4b';
                $maxTokens = (int) get_config('local_jedomi', 'max_tokens');
                if ($maxTokens < 256) { $maxTokens = 16384; }

                $ch = curl_init($gatewayUrl . '/v1/chat/completions');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode([
                        'model' => $model,
                        'messages' => [
                            $system,
                            ['role' => 'user', 'content' => $agent->prompt . "\n\n" . $dataCtx],
                        ],
                        'stream' => false,
                        'max_tokens' => $maxTokens,
                    ], JSON_UNESCAPED_UNICODE),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 120,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey,
                    ],
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    $out("Replay answer failed: {$curlError}");
                } else if ($httpCode === 200) {
                    $result = json_decode($response, true);
                    $finalContent = $result['choices'][0]['message']['content'] ?? '';
                    if (!empty(trim($finalContent))) {
                        $out("Replay answer generated (" . strlen($finalContent) . "B)");
                        $out("");
                        $out("FINAL RESULT:");
                        $out($finalContent);
                        return $finalContent;
                    }
                }
            }
        }

        $force = in_array('--force', $argv ?? []);
        if (!$force && php_sapi_name() !== 'cli') {
            $force = ($_REQUEST['force'] ?? '0') === '1';
        }
        if (!$force) {
            $out("Use &force=1 (HTTP) or --force (CLI) to re-execute");
            return '';
        }
        $out("Force re-execute...");
    } else {
        $out("CACHE MISS");
    }

    // ── Build tool definitions ──────────────────────────────────────────
    $tools = \local_jedomi_get_tool_definitions($role);
    $out("Tool definitions for API (" . count($tools) . " tools):");
    foreach ($tools as $t) {
        $fn = $t['function'];
        $params = implode(', ', array_keys($fn['parameters']['properties'] ?? []));
        $out("  - {$fn['name']}({$params})");
    }

    // ── Build system message (SAME as web chat — NO extra tool instructions) ──
    $spt = [
        'course_id' => (!empty($agent->context_type) && $agent->context_type === 'course')
            ? (int) $agent->context_id : 0,
    ];
    $system = \local_jedomi\proxy\system_message::build($role, $spt, [
        'location' => $agent->context_type ?? 'site',
        'course_name' => '',
        'output' => 'telegram',
    ]);

    $out("System message (" . strlen($system['content']) . " chars)");

    // ── User message ────────────────────────────────────────────────────
    $messages = [
        $system,
        ['role' => 'user', 'content' => $agent->prompt],
    ];
    $out("User message: {$agent->prompt}");

    // ── Gateway config ──────────────────────────────────────────────────
    $gatewayUrl = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765', '/');
    $apiKey = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';
    $model = get_config('local_jedomi', 'model') ?: 'jedomi-e4b';
    $maxTokens = (int) get_config('local_jedomi', 'max_tokens');
    if ($maxTokens < 256) { $maxTokens = 16384; }

    $out("Gateway: {$gatewayUrl}");
    $out("Model: {$model}");
    $out("Max tokens: {$maxTokens}");

    // ── Multi-turn loop ────────────────────────────────────────────────
    $finalContent = '';
    $plan = [];
    $toolcallCounts = [];

    \local_jedomi\agent\tool_executor::get_definitions($agent->id, (int) $agent->userid);

    for ($round = 1; $round <= $maxRounds; $round++) {
        $out("");
        $out("--- ROUND {$round} ---");

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

        $start = microtime(true);
        $out("Calling gateway...");

        $ch = curl_init($gatewayUrl . '/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $duration = microtime(true) - $start;
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            $out("Gateway error: HTTP {$httpCode}" . ($curlError ? " - {$curlError}" : ''));
            if ($round === 1) throw new \Exception("Gateway failed");
            break;
        }

        $result = json_decode($response, true);
        $message = $result['choices'][0]['message'] ?? [];
        $nativeCalls = $message['tool_calls'] ?? [];
        $content = $message['content'] ?? '';

        $out("{$duration}s | Tokens: " . ($result['usage']['total_tokens'] ?? '?'));

        if (empty($nativeCalls)) {
            $finalContent = $content;
            $out("FINAL RESPONSE: " . mb_substr($content, 0, 500));
            break;
        }

        $out(count($nativeCalls) . " tool call(s) via native");

        $assistantMsg = [
            'role' => 'assistant',
        ];
        if (!empty($nativeCalls)) {
            $assistantMsg['content'] = null;
            $assistantMsg['tool_calls'] = $nativeCalls;
        } else {
            $assistantMsg['content'] = $content ?: '[Tool call requested]';
        }
        $messages[] = $assistantMsg;

        foreach ($nativeCalls as $tc) {
            $funcName = $tc['function']['name'] ?? '?';
            $funcArgs = $tc['function']['arguments'] ?? '{}';
            $tcId = $tc['id'] ?? 'tc_' . uniqid();

            $decodedArgs = is_string($funcArgs) ? json_decode($funcArgs, true) : $funcArgs;
            $stableArgs = is_array($decodedArgs) ? stable_args_debug($decodedArgs) : md5($funcArgs);
            $callKey = $funcName . '|' . $stableArgs;
            $toolcallCounts[$callKey] = ($toolcallCounts[$callKey] ?? 0) + 1;

            if ($toolcallCounts[$callKey] > 5) {
                $out("LOOP DETECTED: {$funcName} x{$toolcallCounts[$callKey]}");
                $toolResult = json_encode(['error' => 'loop_detected']);
            } else {
                $out("{$funcName}(" . mb_substr($funcArgs, 0, 200) . ")");

                $toolStart = microtime(true);
                $toolResult = \local_jedomi\agent\tool_executor::execute($funcName, $funcArgs);
                $toolDur = microtime(true) - $toolStart;

                $decodedResult = json_decode($toolResult, true);
                $isError = !empty($decodedResult['error']);

                $out("  → " . ($isError ? 'ERROR' : 'OK') . " [" . round($toolDur, 3) . "s]");

                if ($isError) {
                    $out("  Error: {$decodedResult['error']}");
                } else {
                    $out("  Result: " . mb_substr($toolResult, 0, 200));
                    $plan[] = [
                        'tool' => $funcName,
                        'arguments' => $funcArgs,
                        'result_preview' => mb_substr($toolResult, 0, 100),
                    ];
                }
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tcId,
                'content' => $toolResult,
            ];
        }
    }

    $out("");
    if ($round >= $maxRounds) {
        $out("Max rounds ({$maxRounds}) reached");
    }

    $out("");
    $out("FINAL RESULT:");
    if (!empty($finalContent)) {
        $out($finalContent);
    } else {
        $out("(empty)");
    }

    // ── Save cache (only for real agents, not test mode) ───────────
    if (!empty($plan) && !empty($finalContent) && $agent->id > 0) {
        \local_jedomi\agent\agent_manager::set_cached_plan(
            $agent->id,
            $contextHash,
            $plan,
            $finalContent
        );
        \local_jedomi\agent\agent_manager::set_cached_result(
            $agent->id,
            $contextHash,
            $finalContent
        );
        $out("Cache saved (" . count($plan) . " tools, " . strlen($finalContent) . "B)");
    }

    return $finalContent;
}

// ══════════════════════════════════════════════════════════════════════════
function stable_args_debug(array $args): string {
    ksort($args);
    $normalized = [];
    foreach ($args as $k => $v) {
        $normalized[$k] = is_array($v) ? md5(serialize($v)) : (string) $v;
    }
    return md5(serialize($normalized));
}
