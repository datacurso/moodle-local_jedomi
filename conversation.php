<?php
/**
 * Jedomi Conversation Cache API
 *
 * Almacena el contexto de conversación en el cache de sesión de Moodle.
 * Cada usuario solo accede a SU propio cache (MODE_SESSION).
 * El admin no puede ver conversaciones de otros usuarios.
 *
 * Acciones:
 *   GET  ?action=get       → retorna el historial de mensajes
 *   POST action=append     → agrega un mensaje al historial
 *   POST action=clear      → borra la conversación (botón "Nueva conversación")
 *
 * Seguridad:
 *   - Requiere sesskey en CADA request (CSRF)
 *   - Solo usuarios logueados
 *   - MODE_SESSION = nadie más puede leer este cache
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();  // CSRF: valida que el request viene de una página Moodle real

$action = required_param('action', PARAM_ALPHA);
$cache  = cache::make('local_jedomi', 'conversation');
$key    = 'conv_' . $USER->id;  // clave única por usuario

header('Content-Type: application/json; charset=utf-8');

switch ($action) {

    case 'get':
        // Devuelve el historial actual (array de mensajes)
        $data = $cache->get($key);
        echo json_encode([
            'ok'       => true,
            'messages' => $data ?: [],
        ]);
        break;

    case 'append':
        // Agrega un mensaje al historial
        $role    = required_param('role', PARAM_ALPHA);       // 'user' o 'assistant'
        $content = required_param('content', PARAM_RAW_TRIMMED);

        if (!in_array($role, ['user', 'assistant'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'role inválido']);
            exit;
        }

        $history = $cache->get($key) ?: [];

        // Límite: máx 40 mensajes (20 intercambios) para no crecer indefinido
        if (count($history) >= 40) {
            // Mantener el system prompt (si existe) + los últimos 38 mensajes
            $system = array_filter($history, fn($m) => $m['role'] === 'system');
            $rest   = array_filter($history, fn($m) => $m['role'] !== 'system');
            $history = array_merge(array_values($system), array_slice(array_values($rest), -38));
        }

        $history[] = ['role' => $role, 'content' => $content];
        $cache->set($key, $history);

        echo json_encode(['ok' => true, 'count' => count($history)]);
        break;

    case 'clear':
        // El usuario borra su conversación (botón "Nueva conversación")
        $cache->delete($key);
        echo json_encode(['ok' => true, 'message' => 'Conversación borrada']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'acción desconocida']);
}
