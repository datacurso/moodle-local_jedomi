<?php
/**
 * PRUEBA DEFINITIVA: replica exactamente lo que el proxy hace,
 * incluyendo validación SPT idéntica, llamando al modelo,
 * y mostrando si genera tool calls o no.
 */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
global $DB, $CFG, $USER;

$admin = get_admin();
$USER = $admin;
\core\session\manager::set_user($admin);

echo "========================================\n";
echo "  PRUEBA DEFINITIVA: Proxy exact flow\n";
echo "========================================\n\n";

// ── 1. Replicate EXACTLY what the frontend sends ──
$incomingSpt = local_jedomi_generate_spt('admin');
$incomingSesskey = sesskey();
$incomingMessages = [
    ['role' => 'user', 'content' => 'cuantos cursos hay en total'],
];

echo "User: {$USER->username} (id={$USER->id})\n";
echo "SPT role: {$incomingSpt['role']}\n";
echo "SPT expires: " . date('H:i:s', $incomingSpt['expires']) . "\n";
echo "SPT signature: " . substr($incomingSpt['signature'], 0, 16) . "...\n";
echo "Sesskey: {$incomingSesskey}\n\n";

// ── 2. Replicate proxy's validate_spt EXACTLY ──
function validate_spt_exact(array $spt): string {
    $expectedHash = md5(session_id() . ($spt['role'] ?? ''));
    echo "    validate_spt: sesskey_hash check:\n";
    echo "      expectedHash = md5('" . session_id() . "' + '{$spt['role']}') = {$expectedHash}\n";
    echo "      spt[sesskey_hash] = {$spt['sesskey_hash']}\n";
    
    if (!hash_equals($expectedHash, $spt['sesskey_hash'] ?? '')) {
        echo "      ⚠️  sesskey_hash MISMATCH!\n";
        return 'student';
    }
    echo "      ✅ sesskey_hash OK\n";
    
    if (time() >= ($spt['expires'] ?? 0)) {
        echo "      ⚠️  SPT EXPIRED!\n";
        return 'student';
    }
    echo "      ✅ SPT not expired\n";
    
    $secret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';
    $payload = $spt;
    unset($payload['signature']);
    ksort($payload);
    $expected = hash_hmac('sha256', json_encode($payload), $secret);
    echo "      signature check:\n";
    echo "        expected = {$expected}\n";
    echo "        spt[signature] = {$spt['signature']}\n";
    
    if (!hash_equals($expected, $spt['signature'] ?? '')) {
        echo "      ⚠️  SIGNATURE MISMATCH!\n";
        return 'student';
    }
    echo "      ✅ signature OK\n";
    
    return $spt['role'] ?? 'student';
}

echo "--- validate_spt ---\n";
$role = validate_spt_exact($incomingSpt);
echo "Result role: {$role}\n\n";

// ── 3. Build system message ──
$sitename  = get_config('core', 'sitename') ?: 'Moodle';
$release   = $CFG->release ?? 'unknown';
$location  = 'dashboard';

$systemContent = "You are Jedomi, an AI assistant integrated into Moodle ({$sitename}).\n";
$systemContent .= "Your function is to help users (role: {$role}) answer questions about the site using the available tools.\n\n";
$systemContent .= "AVAILABLE TOOLS:\n";
$systemContent .= "- execute_sql: Run SELECT queries on the Moodle database. Parameters: query (SQL with ? placeholders), params (array of values).\n";
$systemContent .= "- describe_table: Describe a table's columns. Parameter: table name.\n";
$systemContent .= "- search_tables: Find tables by keyword. Parameter: keyword.\n";
$systemContent .= "- get_site_config: Get Moodle configuration values. Parameter: key.\n";
$systemContent .= "- ws_discover: List available web service functions for your role.\n";
$systemContent .= "- ws_execute: Execute a web service function. Parameters: ws_name, params.\n\n";
$systemContent .= "ANTI-HALLUCINATION RULES (CRITICAL — NEVER violate):\n";
$systemContent .= "1. CRITICAL: You MUST call a tool BEFORE answering ANY question about site data (user counts, grades, courses, activity, config, etc.). NEVER guess numbers.\n";
$systemContent .= "2. If a tool returns 0 rows or an error, say \"I couldn't find that data\" — do NOT fabricate results.\n";
$systemContent .= "3. If you don't know the answer, say \"I don't know\" — do NOT make up information.\n";
$systemContent .= "4. Only use execute_sql for SELECT queries. Never modify data.\n";
$systemContent .= "5. Use bound parameters (?) instead of concatenating values in SQL.\n";
$systemContent .= "6. Prefer WS tools (ws_discover + ws_execute) over SQL when possible.\n";
$systemContent .= "7. Respect privacy: do not reveal personal data of other users unless authorized.\n\n";
$systemContent .= "Current context:\n";
$systemContent .= "- Moodle version: {$release}\n";
$systemContent .= "- Location: {$location}\n";
$systemContent .= "- User role: {$role}\n";

$system = ['role' => 'system', 'content' => $systemContent];
$allMessages = array_merge([$system], $incomingMessages);

echo "--- System prompt ---\n";
echo "Length: " . strlen($systemContent) . " chars\n";
echo "Messages: " . count($allMessages) . "\n\n";

// ── 4. Call AI model EXACTLY like proxy ──
echo "--- Call AI model (streaming, buffered) ---\n";
$model  = get_config('local_jedomi', 'model') ?: 'jedomi-e4b';
$apiUrl = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://192.168.1.28:8080', '/');
$apiKey = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';
echo "Model: {$model}\n";
echo "API: {$apiUrl}\n\n";

$payload = json_encode([
    'model' => $model,
    'messages' => $allMessages,
    'stream' => true,
    'max_tokens' => 4096,
], JSON_UNESCAPED_UNICODE);

$buffer  = '';
$textBuf = '';
$ch = curl_init($apiUrl . '/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer) {
        $buffer .= $data;
        return strlen($data);
    },
]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP: {$httpCode}\n";
echo "Buffer size: " . strlen($buffer) . " bytes\n";

// Parse SSE like proxy
$lines = explode("\n", $buffer);
foreach ($lines as $line) {
    $line = trim($line);
    if (!str_starts_with($line, 'data: ')) continue;
    $raw = trim(substr($line, 6));
    if ($raw === '[DONE]') break;
    $json = json_decode($raw, true);
    if (!$json) continue;
    if (isset($json['choices'][0]['delta']['content']) && $json['choices'][0]['delta']['content'] !== null) {
        $textBuf .= $json['choices'][0]['delta']['content'];
    }
}

echo "Extracted text length: " . strlen($textBuf) . "\n";
echo "Extracted text:\n{$textBuf}\n\n";

// ── 5. Parse tool call (like proxy's parse_toolcall_marker) ──
echo "--- Parse tool call ---\n";
$hasToolCall = str_contains($textBuf, '<|tool');
echo "Has <|tool: " . ($hasToolCall ? 'YES ✅' : 'NO ❌') . "\n";

if ($hasToolCall) {
    $patterns = [
        '/<\|tool_?call\|?>\s*call:(\w+)\s*(\{.*\})\s*<tool_?call\|>/s',
        '/<\|tool_?call\|?>\s*call:(\w+)/s',
    ];
    foreach ($patterns as $pat) {
        if (preg_match($pat, $textBuf, $m)) {
            echo "Tool: {$m[1]}\n";
            echo "Args: " . ($m[2] ?? 'none') . "\n";
            break;
        }
    }
}

echo "\n========================================\n";
echo "  VEREDICTO:\n";
if ($hasToolCall) {
    echo "  ✅ PROXY GENERA TOOL CALLS CORRECTAMENTE\n";
    echo "  El problema está en la capa HTTP o Frontend\n";
} else {
    echo "  ❌ EL PROXY NO GENERA TOOL CALLS\n";
    echo "  El modelo devuelve texto plano sin <|tool_call|>\n";
}
echo "========================================\n";
