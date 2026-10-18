<?php
/**
 * Jedomi CLI Chat — Full pipeline debug tool.
 *
 * Replica EXACTAMENTE el pipeline del chat web (chatproxy.php → handler.php),
 * incluyendo system_message::build(), tool definitions, y native function calling.
 *
 * Uso: php cli/chat_cli.php
 * Comandos: help, debug on/off, exit
 */
define('CLI_SCRIPT', true);
define('AJAX_SCRIPT', false);

// ── Seguridad: solo CLI ───────────────────────────────────────────────────
if (isset($_SERVER['REMOTE_ADDR']) || PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Acceso denegado. Este script solo puede ejecutarse desde l&iacute;nea de comandos.');
}

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/proxy/system_message.php');

global $DB, $CFG, $USER;

// ── Loguear como admin ────────────────────────────────────────────────────
$admin = get_admin();
if ($admin) {
    $USER = $admin;
    \core\session\manager::set_user($admin);
}

// ── Config API ────────────────────────────────────────────────────────────
$API_URL = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://192.168.1.28:8080', '/');
$API_KEY = get_config('local_jedomi', 'api_key');
$MODEL   = get_config('local_jedomi', 'model') ?: 'peg-gemma4';

// ── State ─────────────────────────────────────────────────────────────────
$debug = true;
$messages = [];

// ── Header ────────────────────────────────────────────────────────────────
echo "\n" . str_repeat("=", 60) . "\n";
echo "  JEDOMI CLI CHAT — FULL PIPELINE DEBUG\n";
echo "  User: " . ($USER->id ?? 'none') . " | Model: {$MODEL}\n";
echo "  Gateway: {$API_URL}/v1/chat/completions\n";
echo str_repeat("=", 60) . "\n";
echo "  Comandos: help, debug on/off, exit\n\n";

// ── Build system message + tools (EXACTAMENTE como chatproxy) ─────────────
$role = 'admin';
$spt  = ['user_id' => $USER->id, 'course_id' => 0, 'role' => $role];
$context = ['location' => 'cli', 'course_name' => '', 'page_url' => '', 'page_title' => ''];

$systemMsg = \local_jedomi\proxy\system_message::build($role, $spt, $context);
$messages[] = $systemMsg;

$tools = \local_jedomi_get_tool_definitions($role);
// Convertir formato tool definition → OpenAI tools array
$openaiTools = [];
foreach ($tools as $t) {
    $openaiTools[] = [
        'type' => 'function',
        'function' => $t['function'],
    ];
}

if ($debug) {
    echo "  Tools disponibles: " . count($openaiTools) . "\n";
    foreach ($openaiTools as $t) {
        echo "    - {$t['function']['name']}\n";
    }
    echo "\n";
}

// ── Función: LLamar a la API (sin streaming) ──────────────────────────────
function call_ai(array $messages, array $tools = []): ?array {
    global $API_URL, $API_KEY, $MODEL;
    
    $payload = [
        'model'       => $MODEL,
        'messages'    => $messages,
        'stream'      => false,
        'max_tokens'  => 16384,
    ];
    if (!empty($tools)) {
        $payload['tools']       = $tools;
        $payload['tool_choice'] = 'auto';
    }
    
    $ch = curl_init($API_URL . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => \local_jedomi\gateway_headers::build($API_KEY),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    
    $buf = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($http !== 200 || empty($buf)) {
        echo "  ⚠️  API ERROR: HTTP {$http}" . ($error ? " - {$error}" : '') . "\n";
        if (!empty($buf)) echo "  Body: " . substr($buf, 0, 500) . "\n";
        return null;
    }
    
    $data = json_decode($buf, true);
    if (!$data || !isset($data['choices'][0])) {
        echo "  ⚠️  API respuesta inválida\n";
        return null;
    }
    
    return $data['choices'][0]['message'];
}

// ── Función: ejecutar tool (EXACTAMENTE como handler.php) ─────────────────
function execute_tool(string $name, array $args): string {
    return \local_jedomi\proxy\handler::run_tool($name, $args, 'admin');
}

// ── Main Loop ─────────────────────────────────────────────────────────────
while (true) {
    echo "\n\e[1;33m>>> \e[0m";
    $input = readline();
    if ($input === false || $input === 'exit') break;
    $input = trim($input);
    if (empty($input)) continue;
    
    if ($input === 'debug on') { $debug = true; echo "  Debug ON\n"; continue; }
    if ($input === 'debug off') { $debug = false; echo "  Debug OFF\n"; continue; }
    if ($input === 'help') {
        echo "\n";
        echo "  ─── COMANDOS ─────────────────────────────────────\n";
        echo "  help              Mostrá esta ayuda\n";
        echo "  debug on/off      Mostrá u ocultá los tool calls crudos\n";
        echo "  exit              Salí\n";
        echo "\n";
        echo "  Cualquier otra cosa se envía como mensaje al AI.\n";
        echo "  El AI tiene acceso a " . count($openaiTools) . " herramientas:\n";
        foreach ($openaiTools as $t) {
            echo "    - {$t['function']['name']}\n";
        }
        echo "\n";
        echo "  FLUJO TÍPICO:\n";
        echo "  El AI primero busca (ws_search), describe (ws_describe) y\n";
        echo "  luego ejecuta (ws_execute). Si un WS falla por parámetros\n";
        echo "  opcionales faltantes, reintenta automáticamente con defaults.\n";
        echo "  ──────────────────────────────────────────────────\n";
        continue;
    }
    
    $messages[] = ['role' => 'user', 'content' => $input];
    
    $turn = 0;
    while ($turn < 25) {
        $turn++;
        echo "\n  \e[2m⏳ Turno {$turn}/25 — AI pensando...\e[0m\n";
        
        $response = call_ai($messages, $openaiTools);
        if ($response === null) break;
        
        $messages[] = $response;
        
        // ── Text response ──────────────────────────────────────────────
        if (!empty($response['content'])) {
            echo "\n  \e[1;32m🤖:\e[0m {$response['content']}\n";
        }
        
        // ── Tool calls ─────────────────────────────────────────────────
        if (!empty($response['tool_calls'])) {
            foreach ($response['tool_calls'] as $tc) {
                $tcId   = $tc['id'];
                $tcName = $tc['function']['name'];
                $tcArgs = json_decode($tc['function']['arguments'], true) ?? [];
                
                if ($debug) {
                    echo "  \e[36m🔧 Tool call: {$tcName}(" .
                         json_encode($tcArgs, JSON_UNESCAPED_UNICODE) . ")\e[0m\n";
                }
                
                // Ejecutar
                echo "  \e[35m⚡ Ejecutando {$tcName}...\e[0m\n";
                $result = execute_tool($tcName, $tcArgs);
                
                // Parsear resultado para mostrar resumen
                $resultParsed = json_decode($result, true);
                $isError = isset($resultParsed['error']);
                
                // Mostrar resultado resumido
                if ($isError) {
                    echo "  \e[31m❌ Error: " . mb_substr($resultParsed['error'], 0, 200) . "\e[0m\n";
                } else {
                    // Intentar mostrar resumen útil
                    $summary = '';
                    if (isset($resultParsed['result'])) {
                        $inner = json_decode($resultParsed['result'], true);
                        if (is_array($inner)) {
                            $summary = count($inner) . ' resultado(s)';
                            if (count($inner) > 0 && is_array($inner[0])) {
                                $summary .= ' (' . implode(', ', array_slice(array_keys($inner[0]), 0, 4)) . ')';
                            }
                        } else {
                            $summary = mb_substr($resultParsed['result'], 0, 80);
                        }
                    } elseif (isset($resultParsed['success'])) {
                        $summary = 'success: ' . (isset($resultParsed['data']) ? 'data received' : 'true');
                    } elseif (isset($resultParsed['rows'])) {
                        $summary = count($resultParsed['rows']) . ' filas';
                    } else {
                        $summary = 'OK (' . mb_substr($result, 0, 80) . ')';
                    }
                    echo "  \e[32m✅ {$summary}\e[0m\n";
                }
                
                if ($debug && $result) {
                    echo "  \e[2m  Result raw: " . mb_substr($result, 0, 300) . "\e[0m\n";
                }
                
                // Agregar resultado a messages
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tcId,
                    'content' => $result,
                ];
            }
        } else {
            // Sin tool calls → respuesta final
            break;
        }
    }
    
    if ($turn >= 25) {
        echo "  \e[31m✗ Máximo de 25 turnos alcanzado\e[0m\n";
    }
}

echo "\n¡Chau!\n";
