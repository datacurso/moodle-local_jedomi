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
 * Local AI Chat Proxy — SSE endpoint for function-calling tool loop.
 *
 * Thin entry point: validates input and delegates to dedicated classes under
 * classes/proxy/. No in-memory caching, no set_time_limit overrides.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

use local_jedomi\proxy\spt_validator;
use local_jedomi\proxy\handler;
use local_jedomi\proxy\request_body;

// ... ── Method check ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    die('Method Not Allowed');
}

// ... ── Auth ──
// Guests and requests without a session never use the chat: a controlled refusal, not a redirect to the login page.
if (!isloggedin() || isguestuser()) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => ['code' => 'guest_not_allowed', 'message' => get_string('chat_error_guest', 'local_jedomi')]]));
}
require_login(null, false);

// ... ── Size cap ──
// The body is read only up to request_body::MAX_BYTES and refused above it, before it is decoded (JEDOMI-SEC-006).
$body = request_body::read('php://input', isset($_SERVER['CONTENT_LENGTH']) ? (string) $_SERVER['CONTENT_LENGTH'] : null);
if ($body === null) {
    http_response_code(413);
    header('Content-Type: application/json');
    die(json_encode(['error' => ['code' => 'request_too_large',
        'message' => get_string('chat_error_request_too_large', 'local_jedomi')]], JSON_UNESCAPED_UNICODE));
}

// ... ── Parse input ──
$input = json_decode($body, true);
if (!$input || !isset($input['messages'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'invalid_request']));
}

$sesskey = $input['sesskey'] ?? '';
if (!is_string($sesskey) || $sesskey === '' || !confirm_sesskey($sesskey)) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'invalid_sesskey']));
}

// The model is a site setting: any "model" sent by the browser is ignored.
$spt = $input['spt'] ?? null;

// ... ── Validate SPT ──
if (!$spt || !is_array($spt)) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'invalid_spt']));
}

try {
    $role = spt_validator::validate($spt);
} catch (\moodle_exception $e) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => \local_jedomi\local\safe_error::for_user($e, 'chatproxy_spt')]));
}

// The user can stop an answer: the browser then closes the request. The script is not killed halfway through a tool
// or a held action; the tool loop sees the closed connection and stops before the next AI round.
ignore_user_abort(true);

// ... ── SSE headers ──
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
ob_implicit_flush(true);

// ... ── Run tool loop ──
handler::run_from_request($input, $role, $spt, (int) $USER->id);
