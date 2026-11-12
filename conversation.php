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
 * Jedomi Conversation Cache API
 *
 * Stores the conversation context in the Moodle session cache.
 * Each user only reaches their own cache (MODE_SESSION).
 * An admin cannot see other users' conversations.
 *
 * Actions:
 *   POST action=get        → returns the message history
 *   POST action=append     → adds a message to the history
 *   POST action=clear      → clears the conversation ("New conversation" button)
 *
 * Security:
 *   - Requires the sesskey on EVERY request (CSRF)
 *   - Requires the signed permissions of the panel (spt): the course of the request is the signed one
 *   - Logged-in users only
 *   - MODE_SESSION: nobody else can read this cache
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Guests and requests without a session are refused before anything else.
if (!isloggedin() || isguestuser()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => get_string('chat_error_guest', 'local_jedomi')], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login(null, false);
require_sesskey();  // CSRF: valida que el request viene de una página Moodle real.

$disabled = local_jedomi_plugin_disabled_error();
if ($disabled !== null) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $disabled['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

// Same access rules as the panel, for the course signed in the permissions of the panel (never the course the
// browser names: a different one is refused).
$signed = local_jedomi_request_signed_course();
$denied = $signed['error'];
if ($denied !== null) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $denied['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = required_param('action', PARAM_ALPHA);
$cache  = cache::make('local_jedomi', 'conversation');
$key    = 'conv_' . $USER->id;  // Clave única por usuario.

header('Content-Type: application/json; charset=utf-8');

switch ($action) {
    case 'get':
        // Return the current history (array of messages).
        $data = $cache->get($key);
        echo json_encode([
            'ok'       => true,
            'messages' => $data ?: [],
        ]);
        break;

    case 'append':
        // Add a message to the history.
        $role    = required_param('role', PARAM_ALPHA);       // Rol permitido: user o assistant.
        $content = required_param('content', PARAM_RAW_TRIMMED);

        if (!in_array($role, ['user', 'assistant'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'role inválido']);
            exit;
        }

        $history = $cache->get($key) ?: [];

        // Limit: at most 40 messages (20 exchanges), so the history does not grow forever.
        if (count($history) >= 40) {
            // Keep the system prompt (if any) and the last 38 messages.
            $system = array_filter($history, fn($m) => $m['role'] === 'system');
            $rest   = array_filter($history, fn($m) => $m['role'] !== 'system');
            $history = array_merge(array_values($system), array_slice(array_values($rest), -38));
        }

        $history[] = ['role' => $role, 'content' => $content];
        $cache->set($key, $history);

        echo json_encode(['ok' => true, 'count' => count($history)]);
        break;

    case 'clear':
        // The user clears their conversation ("New conversation" button).
        $cache->delete($key);
        echo json_encode(['ok' => true, 'message' => get_string('conversation_cleared', 'local_jedomi')]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'acción desconocida']);
}
