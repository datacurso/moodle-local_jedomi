<?php
/**
 * JEDOMI DIAGNÓSTICO COMPLETO — accedé via http://localhost/moodle456/local/jedomi/diag.php
 */
define('CLI_SCRIPT', false); // Forzar modo web
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB, $CFG, $USER;

header('Content-Type: text/html; charset=utf-8');
echo "<html><head><title>Jedomi Diagnóstico</title>";
echo "<style>
body{font-family:monospace;padding:20px;background:#1e1e2e;color:#cdd6f4}
h1{color:#cba6f7}
h2{color:#89b4fa;margin-top:30px}
pre{background:#313244;padding:15px;border-radius:8px;overflow:auto}
.pass{color:#a6e3a1}
.fail{color:#f38ba8}
.warn{color:#f9e2af}
code{background:#45475a;padding:2px 6px;border-radius:4px}
</style></head><body>";
echo "<h1>🔧 Jedomi — Diagnóstico completo</h1>";

$tests = 0;
$passed = 0;

function test(string $name, bool $condition, string $detail = ''): void {
    global $tests, $passed;
    $tests++;
    $icon = $condition ? '✅' : '❌';
    $cls = $condition ? 'pass' : 'fail';
    $passed += $condition ? 1 : 0;
    echo "<div><span class='{$cls}'>{$icon} {$name}</span>";
    if ($detail) echo " <code>{$detail}</code>";
    echo "</div>";
}

// ═══════════════════════════════════════════════
echo "<h2>1. Entorno Moodle</h2>";

test("Usuario logueado", $USER->id > 0, "ID: {$USER->id}");
test("DB conectada", isset($DB), $CFG->dbname);
test("Plugin instalado", get_config('local_jedomi', 'version') ? true : false, "v" . get_config('local_jedomi', 'version'));

$gatewayUrl = get_config('local_jedomi', 'gateway_url');
$apiKey     = get_config('local_jedomi', 'api_key');
$model      = get_config('local_jedomi', 'model');

test("gateway_url configurado", !empty($gatewayUrl), $gatewayUrl ?: '(usando default)');
test("api_key configurado", !empty($apiKey), $apiKey ? substr($apiKey, 0, 8) . '...' : '(usando default)');
test("model configurado", !empty($model), $model ?: '(usando default)');

// ═══════════════════════════════════════════════
echo "<h2>2. Clases del plugin</h2>";

$classes = [
    'local_jedomi\schema\table_descriptor',
    'local_jedomi\external\execute_sql',
    'local_jedomi\external\ws_execute',
];
foreach ($classes as $cls) {
    test("Class {$cls}", class_exists($cls));
}

// ═══════════════════════════════════════════════
echo "<h2>3. Conectividad con el modelo AI</h2>";

$aiUrl = rtrim($gatewayUrl ?: 'http://192.168.1.28:8080', '/');
$token = $apiKey ?: '016e2b8b6aec7c18155842d6ef68894cc4f6b52f988eefae6bfd1cb06e549fd2';

test("URL del modelo", true, $aiUrl . '/v1/chat/completions');

// Test HTTP connection
$ch = curl_init($aiUrl . '/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'model' => $model ?: 'peg-gemma4',
        'messages' => [['role' => 'user', 'content' => 'Hola']],
        'max_tokens' => 10,
        'stream' => false,
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($http === 200) {
    $data = json_decode($resp, true);
    $hasContent = !empty($data['choices'][0]['message']['content']);
    $preview = $hasContent ? substr($data['choices'][0]['message']['content'], 0, 100) : '(no content)';
    test("Modelo responde (HTTP 200)", true, "HTTP {$http}");
    test("Modelo genera contenido", $hasContent, $preview);
} else {
    test("Modelo responde", false, "HTTP {$http}: " . substr($resp ?: $error, 0, 100));
}

// ═══════════════════════════════════════════════
echo "<h2>4. Herramientas contra BD real</h2>";

// 4a. search_tables
try {
    $tables = \local_jedomi\schema\table_descriptor::search_tables('course');
    $count = $tables['total'] ?? 0;
    test("search_tables('course')", $count > 0, "{$count} tablas encontradas");
} catch (\Exception $e) {
    test("search_tables('course')", false, $e->getMessage());
}

// 4b. describe_table
try {
    $desc = \local_jedomi\schema\table_descriptor::describe($CFG->prefix . 'user');
    $cols = count($desc['columns'] ?? []);
    test("describe_table('user')", $cols > 0, "{$cols} columnas");
} catch (\Exception $e) {
    test("describe_table('user')", false, $e->getMessage());
}

// 4c. execute_sql via external class
try {
    require_once($CFG->dirroot . '/local/jedomi/classes/external/execute_sql.php');
    $sqlResult = \local_jedomi\external\execute_sql::execute(
        'SELECT COUNT(*) as cnt FROM {user}', [], '', 0, '', true
    );
    $hasRows = !empty($sqlResult);
    test("execute_sql (COUNT)", $hasRows, json_encode($sqlResult));
} catch (\Exception $e) {
    test("execute_sql (COUNT)", false, $e->getMessage());
}

// ═══════════════════════════════════════════════
echo "<h2>5. Pipeline AI → Tool (ciclo completo)</h2>";

// Simula lo que hace run_tool_loop
$systemMsg = [
    'role' => 'system',
    'content' => "You are Jedomi, a Moodle AI assistant.\n"
        . "AVAILABLE TOOLS:\n"
        . "- execute_sql: Run SELECT queries. Parameters: query, params.\n"
        . "- describe_table: Describe a table's columns. Parameter: tablename.\n"
        . "- search_tables: Find tables by keyword. Parameter: keyword.\n"
        . "- get_site_config: Get Moodle configuration. Parameter: key.\n"
        . "RULES: You MUST call a tool BEFORE answering about site data."
];

$messages = [$systemMsg, ['role' => 'user', 'content' => '¿qué cursos hay?']];
$TOOL_NAME_MAP = [
    'searchtables' => 'search_tables', 'search_tables' => 'search_tables',
    'executesql' => 'execute_sql', 'execute_sql' => 'execute_sql',
    'describetable' => 'describe_table', 'describe_table' => 'describe_table',
    'getsiteconfig' => 'get_site_config', 'get_site_config' => 'get_site_config',
];

function parse_marker(string $content): array|null {
    global $TOOL_NAME_MAP;
    $patterns = [
        '/<\|tool_?call\|?>\s*call:(\w+)\s*(\{.*\})\s*<tool_?call\|>/s',
        '/<\|tool_?call\|?>\s*call:(\w+)/s',
    ];
    foreach ($patterns as $pat) {
        if (preg_match($pat, $content, $m)) {
            $rawName = $m[1];
            $canonical = $TOOL_NAME_MAP[$rawName] ?? $rawName;
            $args = [];
            if (isset($m[2]) && $m[2] !== '{}') {
                $argsJson = str_replace('<|"|>', '"', $m[2]);
                $args = json_decode($argsJson, true);
                if ($args === null) {
                    $fixed = preg_replace('/(\w+)(?=\s*:)/', '"$1"', $argsJson);
                    $args = json_decode($fixed, true);
                }
                if ($args === null) {
                    $fixed2 = str_replace("'", '"', $fixed ?? $argsJson);
                    $args = json_decode($fixed2, true);
                }
                if ($args === null) $args = ['raw' => $argsJson];
            }
            return ['name' => $canonical, 'args' => is_array($args) ? $args : [], 'raw' => $m[0]];
        }
    }
    return null;
}

// Call AI (non-streaming for test)
$payload = json_encode([
    'model' => $model ?: 'peg-gemma4',
    'messages' => $messages,
    'max_tokens' => 300,
    'stream' => false,
]);

$ch = curl_init($aiUrl . '/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http !== 200) {
    test("Llamada AI", false, "HTTP {$http}: " . substr($resp ?: 'timeout', 0, 100));
} else {
    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    test("AI respondió", !empty($content), strlen($content) . " chars");

    $toolCall = parse_marker($content);
    if ($toolCall) {
        test("Tool call detectada", true, "{$toolCall['name']}(" . json_encode($toolCall['args']) . ")");
    } else {
        test("Tool call NO detectada", false, "Respuesta: " . substr($content, 0, 150));
    }
}

// ═══════════════════════════════════════════════
echo "<h2>6. SPT</h2>";

try {
    $spt = local_jedomi_generate_spt('admin');
    test("SPT generado", !empty($spt), 'keys: ' . implode(', ', array_keys($spt)));
    test("SPT tiene signature", !empty($spt['signature']));
} catch (\Exception $e) {
    test("SPT generado", false, $e->getMessage());
}

// ═══════════════════════════════════════════════
echo "<h2>7. PHP modules</h2>";
test("curl extension", function_exists('curl_version'), curl_version()['version'] ?? '?');
test("json extension", function_exists('json_encode'));
test("mbstring extension", function_exists('mb_str_split') || function_exists('mb_split'));

// ═══════════════════════════════════════════════
echo "<hr>";
$pct = round($passed / max($tests, 1) * 100);
echo "<h2>Resultado: {$passed}/{$tests} pruebas ({$pct}%)</h2>";

if ($passed === $tests) {
    echo "<p style='color:#a6e3a1;font-size:18px'>✅ TODO OK — el pipeline debería funcionar.</p>";
} else {
    echo "<p style='color:#f38ba8;font-size:18px'>❌ Hay fallos que corregir.</p>";
}

echo "</body></html>";
