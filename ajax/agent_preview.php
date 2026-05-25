<?php
/**
 * AJAX endpoint — POST agent preview: test a prompt against the gateway + tools.
 *
 * Takes a raw prompt, runs it through the gateway with tool support,
 * and returns the AI's final response (with real data from tools).
 *
 * This is the same logic as agent_runner but synchronous & for testing.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_login(null, false);
require_capability('local/jedomi:manageagents', context_system::instance());
require_sesskey();

use local_jedomi\agent\tool_executor;

global $DB;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$prompt = required_param('prompt', PARAM_RAW);
if (trim($prompt) === '') {
    echo json_encode(['ok' => false, 'error' => get_string('required')]);
    exit;
}

// ── Gateway config ───────────────────────────────────────────────────────
$gateway_url = rtrim(get_config('local_jedomi', 'gateway_url'), '/');
$api_key    = get_config('local_jedomi', 'api_key');
$model      = get_config('local_jedomi', 'model');

if (empty($gateway_url)) { $gateway_url = 'http://localhost:8765'; }
if (empty($api_key))     { $api_key     = 'jedomi-local-key'; }
if (empty($model))       { $model       = 'jedomi-e4b'; }

$max_tokens = (int) get_config('local_jedomi', 'max_tokens');
if ($max_tokens < 256) { $max_tokens = 16384; }

// ── Build messages ───────────────────────────────────────────────────────
$tools = tool_executor::get_definitions(0, (int) $USER->id);

$systemContent = $prompt;
if (!empty($tools)) {
    $systemContent .= "\n\n=== TOOLS ===\nYou have tools to get REAL data.\n";
    foreach ($tools as $t) {
        $fn = $t['function'] ?? [];
        $systemContent .= '- ' . ($fn['name'] ?? '?') . ': ' . ($fn['description'] ?? '') . "\n";
    }
    $systemContent .= "\n=== HOW TO USE A TOOL ===\n"
        . "If you need data, output EXACTLY:\n"
        . "TOOL_CALL: tool_name\n{\"param\": \"value\"}\nEND_TOOL_CALL\n"
        . "Then I'll run the tool and show you the result.\n"
        . "When you have the data, answer with the FINAL RESULT.";
}

$messages = [
    ['role' => 'system', 'content' => $systemContent],
];

// ── Check agent cache ─────────────────────────────────────────────────────
$cache_hash = md5($prompt);
$cached = $DB->get_record('local_jedomi_agent_cache', [
    'agentid' => 0,
    'context_hash' => $cache_hash,
]);
if ($cached && !empty($cached->result_json)) {
    $cached_result = json_decode($cached->result_json, true);
    if ($cached_result && is_array($cached_result)) {
        echo json_encode([
            'ok' => true,
            'answer' => $cached_result['answer'] ?? $cached->result_json,
            'rounds' => 0,
            'profiler' => $cached_result['profiler'] ?? [],
            'total_time_ms' => 0,
            'cached' => true,
            'cached_at' => $cached->timecreated,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Multi-turn loop (same as agent_runner) ────────────────────────────────
$finalAnswer = '';
$maxRounds = 10;
$profilerEntries = []; // Collect profiler data for the UI.

for ($round = 1; $round <= $maxRounds; $round++) {
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'stream' => false,
        'max_tokens' => $max_tokens,
    ];
    if (!empty($tools)) {
        $payload['tools'] = $tools;
    }

    // ── Gateway call ─────────────────────────────────────────────────────
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
    curl_close($ch);

    if ($curl_errno !== 0 || $http_code !== 200) {
        $err = $curl_error ?: "HTTP {$http_code}";
        echo json_encode(['ok' => false, 'error' => "Gateway error: {$err}"]);
        exit;
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON from gateway']);
        exit;
    }

    $message = $result['choices'][0]['message'] ?? [];
    $nativeToolCalls = $message['tool_calls'] ?? [];
    $content = $message['content'] ?? '';

    // ── Parse text-based TOOL_CALL ──────────────────────────────────────
    $textToolCalls = [];
    if (!empty($tools)) {
        $textToolCalls = parse_text_tool_calls($content);
    }
    $allToolCalls = array_merge($nativeToolCalls, $textToolCalls);

    if (empty($allToolCalls)) {
        $finalAnswer = $content;
        break;
    }

    $messages[] = [
        'role' => 'assistant',
        'content' => $content ?: '[Tool call requested]',
    ];

    foreach ($allToolCalls as $tc) {
        $funcName = $tc['function']['name'] ?? '?';
        $funcArgs = $tc['function']['arguments'] ?? '{}';

        $toolStart = microtime(true);
        $toolResult = tool_executor::execute($funcName, $funcArgs);
        $toolDuration = (microtime(true) - $toolStart) * 1000;

        // Extract profiler info from tool result JSON.
        $resultInfo = json_decode($toolResult, true);
        $profilerEntries[] = [
            'tool' => $funcName,
            'arguments' => $funcArgs,
            'duration_ms' => round($resultInfo['duration_ms'] ?? $toolDuration, 2),
            'row_count' => $resultInfo['row_count'] ?? 0,
            'error' => $resultInfo['error'] ?? null,
        ];

        if (strlen($toolResult) > 64000) {
            $toolResult = mb_substr($toolResult, 0, 64000)
                . "\n\n[Result truncated at 64000 characters]";
        }

        $messages[] = [
            'role' => 'tool',
            'tool_call_id' => $tc['id'] ?? 'tc_' . uniqid(),
            'content' => $toolResult,
        ];
    }

    // After first tool execution, remove tools so model must respond.
    $tools = [];
    $messages[] = [
        'role' => 'system',
        'content' => 'You now have the data. Provide your FINAL ANSWER now.',
    ];
}

// ── Safety net + cleanup ─────────────────────────────────────────────────
if (empty($finalAnswer) && !empty($content)) {
    $finalAnswer = $content;
}

// Strip any TOOL_CALL blocks that leaked into final answer.
$finalAnswer = trim(preg_replace(
    '/(?:TOOL_CALL|call):\s*\w+\s*\n.*?\nEND_TOOL_CALL[^\n]*/si',
    '', $finalAnswer ?? ''
));

if (empty($finalAnswer)) {
    echo json_encode(['ok' => false, 'error' => 'No response from AI']);
    exit;
}

// ── Save to cache ─────────────────────────────────────────────────────────
$cache_data = json_encode([
    'answer' => $finalAnswer,
    'profiler' => $profilerEntries,
]);
$existing_cache = $DB->get_record('local_jedomi_agent_cache', [
    'agentid' => 0,
    'context_hash' => $cache_hash,
], 'id');
if ($existing_cache) {
    $DB->update_record('local_jedomi_agent_cache', (object) [
        'id' => $existing_cache->id,
        'result_json' => $cache_data,
        'timecreated' => time(),
    ]);
} else {
    $DB->insert_record('local_jedomi_agent_cache', (object) [
        'agentid' => 0,
        'context_hash' => $cache_hash,
        'result_json' => $cache_data,
        'timecreated' => time(),
    ]);
}

echo json_encode([
    'ok' => true,
    'answer' => $finalAnswer,
    'rounds' => $round ?? 0,
    'profiler' => $profilerEntries,
    'total_time_ms' => round(array_sum(array_column($profilerEntries, 'duration_ms')), 2),
], JSON_UNESCAPED_UNICODE);

// ── Helper: parse TOOL_CALL blocks from text ──────────────────────────────
/**
 * Parse text-based TOOL_CALL blocks from AI response content.
 * Supports TOOL_CALL:/call: format AND <|tool_call|> special token format.
 *
 * @param  string &$content The assistant response text (modified by reference).
 * @return array            Parsed tool calls in native format.
 */
function parse_text_tool_calls(string &$content): array {
    $calls = [];

    if (empty(trim($content))) {
        return $calls;
    }

    // ── Pattern 1: TOOL_CALL: tool_name\n{json}\nEND_TOOL_CALL ──────────
    $pattern1 = '/(?:TOOL_CALL|call):\s*(\w+)\s*\n(.+?)\nEND_TOOL_CALL[^\n]*/si';

    // ── Pattern 2: <|tool_call|> or <|python_tag|> special tokens ──────
    $pattern2 = '/<\|(?:tool_call|python_tag)\|>(.+?)<\|\\/\1\|>/si';

    // Try pattern 1 first.
    if (preg_match_all($pattern1, $content, $matches, PREG_SET_ORDER)) {
        $content = trim(preg_replace($pattern1, '', $content));
        foreach ($matches as $m) {
            $parsed = tool_call_from_match($m[1], $m[2]);
            if ($parsed) { $calls[] = $parsed; }
        }
        return $calls;
    }

    // Try pattern 2 (<|tool_call|>).
    if (preg_match_all($pattern2, $content, $matches, PREG_SET_ORDER)) {
        $content = trim(preg_replace($pattern2, '', $content));
        foreach ($matches as $m) {
            $inner = trim($m[1]);
            $decoded = json_decode($inner, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['name'])) {
                $toolName = $decoded['name'];
                $args = $decoded['arguments'] ?? '{}';
                if (is_array($args)) { $args = json_encode($args, JSON_UNESCAPED_UNICODE); }
                $parsed = tool_call_from_match($toolName, $args);
            } else {
                $parts = explode("\n", $inner, 2);
                $parsed = tool_call_from_match(trim($parts[0]), $parts[1] ?? '{}');
            }
            if ($parsed) { $calls[] = $parsed; }
        }
        return $calls;
    }

    return $calls;
}

/**
 * Build a tool call entry from name + JSON string with validation.
 */
function tool_call_from_match(string $name, string $argsJson): ?array {
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
