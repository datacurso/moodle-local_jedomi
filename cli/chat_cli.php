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
 * Jedomi CLI Chat — Full pipeline debug tool.
 *
 * Replica EXACTAMENTE el pipeline del chat web (chatproxy.php → handler.php),
 * incluyendo system_message::build(), tool definitions, y native function calling.
 *
 * Uso: php cli/chat_cli.php
 * Comandos: help, debug on/off, exit
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
$apiurl = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://192.168.1.28:8080', '/');
$apikey = get_config('local_jedomi', 'api_key');
$model   = get_config('local_jedomi', 'model') ?: 'peg-gemma4';

// ── State ─────────────────────────────────────────────────────────────────
$debug = true;
$messages = [];

// ── Header ────────────────────────────────────────────────────────────────
echo "\n" . str_repeat("=", 60) . "\n";
echo "  JEDOMI CLI CHAT — FULL PIPELINE DEBUG\n";
echo "  User: " . ($USER->id ?? 'none') . " | Model: {$model}\n";
echo "  Gateway: {$apiurl}/v1/chat/completions\n";
echo str_repeat("=", 60) . "\n";
echo "  Comandos: help, debug on/off, exit\n\n";

// ── Build system message + tools (EXACTAMENTE como chatproxy) ─────────────
$role = 'admin';
$spt  = ['user_id' => $USER->id, 'course_id' => 0, 'role' => $role];
$context = ['location' => 'cli', 'course_name' => '', 'page_url' => '', 'page_title' => ''];

$systemmsg = \local_jedomi\proxy\system_message::build($role, $spt, $context);
$messages[] = $systemmsg;

$tools = \local_jedomi_get_tool_definitions($role);
// Convertir formato tool definition → OpenAI tools array
$openaitools = [];
foreach ($tools as $t) {
    $openaitools[] = [
        'type' => 'function',
        'function' => $t['function'],
    ];
}

if ($debug) {
    echo "  Tools disponibles: " . count($openaitools) . "\n";
    foreach ($openaitools as $t) {
        echo "    - {$t['function']['name']}\n";
    }
    echo "\n";
}

// ── Función: LLamar a la API (sin streaming) ──────────────────────────────
/**
 * Sends the conversation to the AI gateway chat completions endpoint and returns the assistant message.
 *
 * @param array $messages full conversation history in OpenAI chat format
 * @param array $tools OpenAI-format tool definitions to offer the model (empty for none)
 * @return array|null the first choice's message array, or null on HTTP or decoding failure
 */
function call_ai(array $messages, array $tools = []): ?array {
    global $apiurl, $apikey, $model;

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'stream'      => false,
        'max_tokens'  => 16384,
    ];
    if (!empty($tools)) {
        $payload['tools']       = $tools;
        $payload['tool_choice'] = 'auto';
    }

    $ch = curl_init($apiurl . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apikey,
        ],
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
        if (!empty($buf)) {
            echo "  Body: " . substr($buf, 0, 500) . "\n";
        }
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
/**
 * Executes a tool call through the proxy handler using the admin role.
 *
 * @param string $name tool name requested by the model
 * @param array $args decoded tool call arguments
 * @return string JSON-encoded tool result
 */
function execute_tool(string $name, array $args): string {
    return \local_jedomi\proxy\handler::run_tool($name, $args, 'admin');
}

// ── Main Loop ─────────────────────────────────────────────────────────────
while (true) {
    echo "\n\e[1;33m>>> \e[0m";
    $input = readline();
    if ($input === false || $input === 'exit') {
        break;
    }
    $input = trim($input);
    if (empty($input)) {
        continue;
    }

    if ($input === 'debug on') {
        $debug = true;
        echo "  Debug ON\n";
        continue;
    }
    if ($input === 'debug off') {
        $debug = false;
        echo "  Debug OFF\n";
        continue;
    }
    if ($input === 'help') {
        echo "\n";
        echo "  ─── COMANDOS ─────────────────────────────────────\n";
        echo "  help              Mostrá esta ayuda\n";
        echo "  debug on/off      Mostrá u ocultá los tool calls crudos\n";
        echo "  exit              Salí\n";
        echo "\n";
        echo "  Cualquier otra cosa se envía como mensaje al AI.\n";
        echo "  El AI tiene acceso a " . count($openaitools) . " herramientas:\n";
        foreach ($openaitools as $t) {
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

        $response = call_ai($messages, $openaitools);
        if ($response === null) {
            break;
        }

        $messages[] = $response;

        // ── Text response ──────────────────────────────────────────────
        if (!empty($response['content'])) {
            echo "\n  \e[1;32m🤖:\e[0m {$response['content']}\n";
        }

        // ── Tool calls ─────────────────────────────────────────────────
        if (!empty($response['tool_calls'])) {
            foreach ($response['tool_calls'] as $tc) {
                $tcid   = $tc['id'];
                $tcname = $tc['function']['name'];
                $tcargs = json_decode($tc['function']['arguments'], true) ?? [];

                if ($debug) {
                    echo "  \e[36m🔧 Tool call: {$tcname}(" .
                         json_encode($tcargs, JSON_UNESCAPED_UNICODE) . ")\e[0m\n";
                }

                // Ejecutar
                echo "  \e[35m⚡ Ejecutando {$tcname}...\e[0m\n";
                $result = execute_tool($tcname, $tcargs);

                // Parsear resultado para mostrar resumen
                $resultparsed = json_decode($result, true);
                $iserror = isset($resultparsed['error']);

                // Mostrar resultado resumido
                if ($iserror) {
                    echo "  \e[31m❌ Error: " . mb_substr($resultparsed['error'], 0, 200) . "\e[0m\n";
                } else {
                    // Intentar mostrar resumen útil
                    $summary = '';
                    if (isset($resultparsed['result'])) {
                        $inner = json_decode($resultparsed['result'], true);
                        if (is_array($inner)) {
                            $summary = count($inner) . ' resultado(s)';
                            if (count($inner) > 0 && is_array($inner[0])) {
                                $summary .= ' (' . implode(', ', array_slice(array_keys($inner[0]), 0, 4)) . ')';
                            }
                        } else {
                            $summary = mb_substr($resultparsed['result'], 0, 80);
                        }
                    } else if (isset($resultparsed['success'])) {
                        $summary = 'success: ' . (isset($resultparsed['data']) ? 'data received' : 'true');
                    } else if (isset($resultparsed['rows'])) {
                        $summary = count($resultparsed['rows']) . ' filas';
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
                    'tool_call_id' => $tcid,
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
