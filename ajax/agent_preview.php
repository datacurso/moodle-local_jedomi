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
$gatewayurl = rtrim(get_config('local_jedomi', 'gateway_url'), '/');
$apikey    = get_config('local_jedomi', 'api_key');
$model      = get_config('local_jedomi', 'model');

if (empty($gatewayurl)) {
    $gatewayurl = 'http://localhost:8765';
}
if (empty($apikey)) {
    $apikey     = 'jedomi-local-key';
}
if (empty($model)) {
    $model       = 'jedomi-e4b';
}

$maxtokens = (int) get_config('local_jedomi', 'max_tokens');
if ($maxtokens < 256) {
    $maxtokens = 16384;
}

// ── Build messages (same rich context as web chat) ─────────────────────
require_once(__DIR__ . '/../lib.php');
$role = \local_jedomi_get_user_role_for_user((int) $USER->id);
$tools = \local_jedomi_get_tool_definitions($role);

$spt = ['course_id' => 0];
$context = ['location' => 'site', 'course_name' => ''];
$system = \local_jedomi\proxy\system_message::build($role, $spt, $context);

if (!empty($tools)) {
    $system['content'] .= "\n\n=== AVAILABLE TOOLS ===\n";
    foreach ($tools as $t) {
        $fn = $t['function'] ?? [];
        $system['content'] .= '- ' . ($fn['name'] ?? '?') . ': ' . ($fn['description'] ?? '') . "\n";
    }
}

$messages = [
    $system,
    ['role' => 'user', 'content' => $prompt],
];

// ── Check agent cache ─────────────────────────────────────────────────────
$cachehash = md5(trim($prompt));
$cached = $DB->get_record('local_jedomi_agent_cache', [
    'agentid' => 0,
    'context_hash' => $cachehash,
]);
if ($cached && !empty($cached->result_json)) {
    // ── Try semantic cache plan replay ──────────────────────────────────────
    if (!empty($cached->plan_json)) {
        $plan = json_decode($cached->plan_json, true);
        if (is_array($plan) && !empty($plan)) {
            $replayprofiler = [];
            $replayfailed = false;
            foreach ($plan as $step) {
                $toolstart = microtime(true);
                $toolresult = tool_executor::execute($step['tool'], $step['arguments']);
                $toolduration = (microtime(true) - $toolstart) * 1000;
                $resultinfo = json_decode($toolresult, true);
                if (!empty($resultinfo['error'])) {
                    $replayfailed = true;
                    break;
                }
                $replayprofiler[] = [
                    'tool' => $step['tool'],
                    'arguments' => $step['arguments'],
                    'result_preview' => mb_substr($toolresult, 0, 5000),
                    'duration_ms' => round($resultinfo['duration_ms'] ?? $toolduration, 2),
                    'row_count' => $resultinfo['row_count'] ?? 0,
                    'error' => $resultinfo['error'] ?? null,
                ];
            }

            if (!$replayfailed && !empty($replayprofiler)) {
                // Build fresh context for reformulation.
                $freshctx = "Updated tool results:\n";
                foreach ($replayprofiler as $fr) {
                    $freshctx .= "\n--- {$fr['tool']} ---\n{$fr['result_preview']}\n";
                }

                $cachedresult = json_decode($cached->result_json, true);
                $cachedanswer = $cachedresult['answer'] ?? $cached->result_json;

                $reformmessages = [
                    ['role' => 'system', 'content' => 'You previously answered a question. Below are updated tool results.'
                        . ' Reformulate your answer using the new data. Keep the same style and structure.'],
                    ['role' => 'user', 'content' => "Previous answer:\n{$cachedanswer}\n\n{$freshctx}"],
                ];

                $ch = curl_init($gatewayurl . '/v1/chat/completions');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode([
                        'model' => $model,
                        'messages' => $reformmessages,
                        'stream' => false,
                        'max_tokens' => $maxtokens,
                    ], JSON_UNESCAPED_UNICODE),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 120,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apikey,
                    ],
                ]);
                $reformresponse = curl_exec($ch);
                $reformhttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($reformhttp === 200) {
                    $reformresult = json_decode($reformresponse, true);
                    $reformanswer = $reformresult['choices'][0]['message']['content'] ?? '';
                    if (!empty(trim($reformanswer))) {
                        echo json_encode([
                            'ok' => true,
                            'answer' => $reformanswer,
                            'rounds' => 0,
                            'profiler' => $replayprofiler,
                            'total_time_ms' => round(array_sum(array_column($replayprofiler, 'duration_ms')), 2),
                            'cached' => true,
                            'cached_at' => $cached->timecreated,
                            'reformulated' => true,
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
            }
        }
    }

    // ── Fallback: plain text cache ──────────────────────────────────────────
    $cachedplain = json_decode($cached->result_json, true);
    if ($cachedplain && is_array($cachedplain)) {
        echo json_encode([
            'ok' => true,
            'answer' => $cachedplain['answer'] ?? $cached->result_json,
            'rounds' => 0,
            'profiler' => $cachedplain['profiler'] ?? [],
            'conversation' => $cachedplain['conversation'] ?? [],
            'total_time_ms' => 0,
            'cached' => true,
            'cached_at' => $cached->timecreated,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Multi-turn loop (same as agent_runner) ────────────────────────────────
$finalanswer = '';
$maxrounds = 15;
$profilerentries = [];
$plan = [];
$toolcallcounts = [];
$loopbroken = false;

for ($round = 1; $round <= $maxrounds; $round++) {
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'stream' => false,
        'max_tokens' => $maxtokens,
    ];
    if (!empty($tools)) {
        $payload['tools'] = $tools;
    }

    // ── Gateway call ─────────────────────────────────────────────────────
    $ch = curl_init($gatewayurl . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apikey,
        ],
    ]);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlerrno = curl_errno($ch);
    $curlerror = curl_error($ch);
    curl_close($ch);

    if ($curlerrno !== 0 || $httpcode !== 200) {
        $err = $curlerror ?: "HTTP {$httpcode}";
        echo json_encode(['ok' => false, 'error' => "Gateway error: {$err}"]);
        exit;
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON from gateway']);
        exit;
    }

    $message = $result['choices'][0]['message'] ?? [];
    $nativetoolcalls = $message['tool_calls'] ?? [];
    $content = $message['content'] ?? '';

    // ── Parse text-based TOOL_CALL ──────────────────────────────────────
    $texttoolcalls = [];
    if (!empty($tools)) {
        $texttoolcalls = parse_text_tool_calls($content);
    }
    $alltoolcalls = array_merge($nativetoolcalls, $texttoolcalls);

    if (empty($alltoolcalls)) {
        $finalanswer = $content;
        break;
    }

    $messages[] = [
        'role' => 'assistant',
        'content' => $content ?: '[Tool call requested]',
    ];

    foreach ($alltoolcalls as $tc) {
        $funcname = $tc['function']['name'] ?? '?';
        $funcargs = $tc['function']['arguments'] ?? '{}';
        $tcid = $tc['id'] ?? 'tc_' . uniqid();

        // ── Loop detection ──────────────────────────────────────────────
        $decodedargs = is_string($funcargs) ? json_decode($funcargs, true) : $funcargs;
        $stableargs = is_array($decodedargs)
            ? hash('sha256', json_encode($decodedargs, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE))
            : md5($funcargs);
        $callkey = $funcname . '|' . $stableargs;
        $toolcallcounts[$callkey] = ($toolcallcounts[$callkey] ?? 0) + 1;

        $loopdetected = $toolcallcounts[$callkey] > 5;
        if ($loopdetected) {
            $toolresult = json_encode([
                'error' => 'loop_detected',
                'tool' => $funcname,
                'detail' => 'Same tool with same arguments called ' . $toolcallcounts[$callkey] . ' times.',
            ]);
            $loopbroken = true;
        } else {
            $plan[] = [
                'tool' => $funcname,
                'arguments' => $funcargs,
            ];

            $toolstart = microtime(true);
            $toolresult = tool_executor::execute($funcname, $funcargs);
            $toolduration = (microtime(true) - $toolstart) * 1000;

            if (strlen($toolresult) > 64000) {
                $toolresult = mb_substr($toolresult, 0, 64000)
                    . "\n\n[Result truncated at 64000 characters]";
            }
        }

        // Profile every call (including loop_detected for visibility).
        $resultinfo = json_decode($toolresult, true);
        $resultpreview = is_string($toolresult)
            ? mb_substr($toolresult, 0, 5000)
            : mb_substr(json_encode($toolresult, JSON_UNESCAPED_UNICODE), 0, 5000);
        $profilerentries[] = [
            'tool' => $funcname,
            'arguments' => $funcargs,
            'result_preview' => $resultpreview,
            'duration_ms' => round($resultinfo['duration_ms'] ?? ($toolduration ?? 0), 2),
            'row_count' => $resultinfo['row_count'] ?? 0,
            'error' => $resultinfo['error'] ?? null,
        ];

        $messages[] = [
            'role' => 'tool',
            'tool_call_id' => $tcid,
            'content' => $toolresult,
        ];
    }

    if ($loopbroken) {
        break;
    }
}

// ── Safety net + cleanup ─────────────────────────────────────────────────
if (empty($finalanswer)) {
    if ($loopbroken) {
        $finalanswer = 'The agent encountered an issue while fetching data. Please try again later.';
    } else if (!empty($content)) {
        $finalanswer = $content;
    } else {
        // Fallback: last non-error tool result.
        foreach (array_reverse($messages ?? []) as $msg) {
            if (($msg['role'] ?? '') === 'tool' && !empty($msg['content'])) {
                $decoded = json_decode($msg['content'], true);
                if (!empty($decoded['error'])) {
                    continue;
                }
                $finalanswer = is_string($msg['content'])
                    ? $msg['content']
                    : json_encode($msg['content'], JSON_UNESCAPED_UNICODE);
                break;
            }
        }
    }
}

// Strip any TOOL_CALL blocks that leaked into final answer.
$finalanswer = trim(preg_replace(
    '/(?:TOOL_CALL|call):\s*\w+\s*\n.*?\nEND_TOOL_CALL[^\n]*/si',
    '',
    $finalanswer ?? ''
));

if (empty($finalanswer)) {
    echo json_encode(['ok' => false, 'error' => 'No response from AI']);
    exit;
}

// ── Save to cache ─────────────────────────────────────────────────────────
// Strip tool payload from messages before caching (not needed for display).
$cachablemessages = $messages;
array_walk($cachablemessages, function (&$msg) {
    unset($msg['tool_calls']);
});
$cachedata = json_encode([
    'answer' => $finalanswer,
    'profiler' => $profilerentries,
    'conversation' => $cachablemessages,
]);

// Only save semantic plan if all tools are readonly.
$allreadonly = true;
if (!empty($plan)) {
    foreach ($plan as $p) {
        if (!\local_jedomi\agent\agent_manager::is_tool_readonly($p['tool'])) {
            $allreadonly = false;
            break;
        }
    }
}
$planjson = (!empty($plan) && $allreadonly) ? json_encode($plan, JSON_UNESCAPED_UNICODE) : null;

$existingcache = $DB->get_record('local_jedomi_agent_cache', [
    'agentid' => 0,
    'context_hash' => $cachehash,
], 'id');
if ($existingcache) {
    $DB->update_record('local_jedomi_agent_cache', (object) [
        'id' => $existingcache->id,
        'result_json' => $cachedata,
        'plan_json' => $planjson,
        'timecreated' => time(),
    ]);
} else {
    $DB->insert_record('local_jedomi_agent_cache', (object) [
        'agentid' => 0,
        'context_hash' => $cachehash,
        'result_json' => $cachedata,
        'plan_json' => $planjson,
        'timecreated' => time(),
    ]);
}

echo json_encode([
    'ok' => true,
    'answer' => $finalanswer,
    'rounds' => $round ?? 0,
    'profiler' => $profilerentries,
    'conversation' => $cachablemessages,
    'total_time_ms' => round(array_sum(array_column($profilerentries, 'duration_ms')), 2),
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
            $decoded = json_decode($inner, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['name'])) {
                $toolname = $decoded['name'];
                $args = $decoded['arguments'] ?? '{}';
                if (is_array($args)) {
                    $args = json_encode($args, JSON_UNESCAPED_UNICODE);
                }
                $parsed = tool_call_from_match($toolname, $args);
            } else {
                $parts = explode("\n", $inner, 2);
                $parsed = tool_call_from_match(trim($parts[0]), $parts[1] ?? '{}');
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
 * Build a tool call entry from name + JSON string with validation.
 */
function tool_call_from_match(string $name, string $argsjson): ?array {
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
