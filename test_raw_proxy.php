<?php
/**
 * Proxy MÍNIMO para debug — replica chatproxy.php exactamente
 * pero devuelve el RAW output del modelo como JSON simple.
 */
define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_login();

global $DB, $CFG, $USER;

header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['messages'])) {
    die(json_encode(['error' => 'invalid_request']));
}

$sesskey = $input['sesskey'] ?? '';
if (!confirm_sesskey($sesskey)) {
    die(json_encode(['error' => 'invalid_sesskey', 'sesskey_sent' => $sesskey, 'expected' => sesskey()]));
}

$model    = $input['model'] ?? get_config('local_jedomi', 'model') ?: 'jedomi-e4b';
$messages = $input['messages'];
$spt      = $input['spt'] ?? null;
$context  = $input['context'] ?? [];

if (!$spt || !is_array($spt)) {
    die(json_encode(['error' => 'invalid_spt']));
}

// ── Validate SPT (same as proxy) ──
function validate_spt_min(array $spt): string {
    $expectedHash = md5(session_id() . ($spt['role'] ?? ''));
    if (!hash_equals($expectedHash, $spt['sesskey_hash'] ?? '')) {
        return 'student';
    }
    if (time() >= ($spt['expires'] ?? 0)) {
        return 'student';
    }
    $secret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';
    $payload = $spt;
    unset($payload['signature']);
    ksort($payload);
    $expected = hash_hmac('sha256', json_encode($payload), $secret);
    if (!hash_equals($expected, $spt['signature'] ?? '')) {
        return 'student';
    }
    return $spt['role'] ?? 'student';
}

$role = validate_spt_min($spt);

// ── Build system message (exact proxy version) ──
$sitename  = get_config('core', 'sitename') ?: 'Moodle';
$release   = $CFG->release ?? 'unknown';
$courseId  = $spt['course_id'] ?? 0;
$courseName = $context['course_name'] ?? '';
$pageUrl   = $context['page_url'] ?? '';
$pageTitle = $context['page_title'] ?? '';
$location  = $context['location'] ?? 'dashboard';

$locLabels = [
    'course'    => 'Currently viewing a course' . ($courseName ? " \"{$courseName}\"" : '') . " (ID: {$courseId}).",
    'activity'  => 'Currently in an activity of type "' . ($context['activity_type'] ?? 'unknown') . '".',
    'grades'    => 'Currently viewing the gradebook.',
    'admin'     => 'Currently in the Moodle administration panel.',
    'dashboard' => 'Currently on the Moodle dashboard.',
    'messages'  => 'Currently in the messages section.',
];

$content = "You are Jedomi, an AI assistant integrated into Moodle ({$sitename}).\n";
$content .= "Your function is to help users (role: {$role}) answer questions about the site using the available tools.\n\n";
$content .= "AVAILABLE TOOLS:\n";
$content .= "- execute_sql: Run SELECT queries on the Moodle database. Parameters: query (SQL with ? placeholders), params (array of values).\n";
$content .= "- describe_table: Describe a table's columns. Parameter: table name.\n";
$content .= "- search_tables: Find tables by keyword. Parameter: keyword.\n";
$content .= "- get_site_config: Get Moodle configuration values. Parameter: key.\n";
$content .= "- ws_discover: List available web service functions for your role.\n";
$content .= "- ws_execute: Execute a web service function. Parameters: ws_name, params.\n\n";
$content .= "ANTI-HALLUCINATION RULES (CRITICAL — NEVER violate):\n";
$content .= "1. CRITICAL: You MUST call a tool BEFORE answering ANY question about site data (user counts, grades, courses, activity, config, etc.). NEVER guess numbers.\n";
$content .= "2. If a tool returns 0 rows or an error, say \"I couldn't find that data\" — do NOT fabricate results.\n";
$content .= "3. If you don't know the answer, say \"I don't know\" — do NOT make up information.\n";
$content .= "4. Only use execute_sql for SELECT queries. Never modify data.\n";
$content .= "5. Use bound parameters (?) instead of concatenating values in SQL.\n";
$content .= "6. Prefer WS tools (ws_discover + ws_execute) over SQL when possible.\n";
$content .= "7. Respect privacy: do not reveal personal data of other users unless authorized.\n\n";
$content .= "Current context:\n";
$content .= "- Moodle version: {$release}\n";
$content .= "- Location: {$location}\n";
$content .= "- Page: {$pageTitle} ({$pageUrl})\n";
$content .= "- User role: {$role}\n";

$system = ['role' => 'system', 'content' => $content];
$allMessages = array_merge([$system], $messages);

// ── Call AI model ──
$apiUrl  = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://192.168.1.28:8080', '/');
$apiKey  = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';

// TEST 1: Non-streaming (exactly what the model returns)
$payload1 = json_encode([
    'model' => $model,
    'messages' => $allMessages,
    'stream' => false,
    'max_tokens' => 4096,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($apiUrl . '/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload1,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
]);
$result1 = curl_exec($ch);
$http1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// TEST 2: Streaming (like the proxy does)
$payload2 = json_encode([
    'model' => $model,
    'messages' => $allMessages,
    'stream' => true,
    'max_tokens' => 4096,
], JSON_UNESCAPED_UNICODE);

$buffer2 = '';
$ch2 = curl_init($apiUrl . '/v1/chat/completions');
curl_setopt_array($ch2, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload2,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$buffer2) {
        $buffer2 .= $data;
        return strlen($data);
    },
]);
curl_exec($ch2);
$http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

// Parse SSE
$textBuf2 = '';
$lines = explode("\n", $buffer2);
foreach ($lines as $line) {
    $line = trim($line);
    if (!str_starts_with($line, 'data: ')) continue;
    $raw = trim(substr($line, 6));
    if ($raw === '[DONE]') break;
    $json = json_decode($raw, true);
    if (!$json) continue;
    if (isset($json['choices'][0]['delta']['content']) && $json['choices'][0]['delta']['content'] !== null) {
        $textBuf2 .= $json['choices'][0]['delta']['content'];
    }
}

// ── Return results ──
$data1 = json_decode($result1, true);
$response1 = $data1['choices'][0]['message']['content'] ?? 'NO CONTENT';

$result = [
    'session_valid' => true,
    'user' => $USER->username,
    'role_from_spt' => $role,
    'spt_valid' => $role !== 'student',
    'system_prompt_length' => strlen($content),
    'total_messages' => count($allMessages),
    'api_model' => $model,
    'api_url' => $apiUrl,
    'test1_non_streaming' => [
        'http' => $http1,
        'response' => $response1,
        'has_tool_call' => str_contains($response1, '<|tool'),
    ],
    'test2_streaming' => [
        'http' => $http2,
        'response' => $textBuf2,
        'has_tool_call' => str_contains($textBuf2, '<|tool'),
    ],
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
