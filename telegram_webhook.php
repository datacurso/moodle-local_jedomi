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
 * Telegram webhook public endpoint.
 *
 * Validates the secret header, decodes JSON body, and delegates to webhook_handler.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- webhook auth uses the Telegram secret token.
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_jedomi\telegram\manager;
use local_jedomi\telegram\webhook_handler;

// ... ── Validate HTTP method — Telegram only sends POST ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ... ── Validate webhook secret ──────────────────────────────────────────────────
// Telegram sends it in the X-Telegram-Bot-Api-Secret-Token header; a secret in the address is never accepted.
if (!manager::is_valid_webhook_request($_SERVER)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid webhook secret (E007)']);
    local_jedomi_log('E007: webhook secret mismatch');
    exit;
}

// ... ── Internal self-ping (continue checkpointed work) ──
$internalping = optional_param('internal_ping', 0, PARAM_INT);
$pingchatid = optional_param('chat_id', '', PARAM_RAW);

// Telegram chat ids are signed integers; drop anything else before logging or use.
if ($pingchatid !== '' && !preg_match('/^-?\d{1,20}$/', $pingchatid)) {
    $pingchatid = '';
}

if ($internalping && !empty($pingchatid)) {
    local_jedomi_log('SELF-PING: continuing for chat ' . local_jedomi_log_pseudonym('chat', $pingchatid));
    // Send 200 OK immediately so the caller cURL returns fast.
    webhook_handler::acknowledge();

    ignore_user_abort(true);
    set_time_limit(0);

    webhook_handler::handle_internal_ping($pingchatid);
    exit;
}

// ... ── Decode Telegram payload ──────────────────────────────────────────────────
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!is_array($update)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Log incoming webhook request for debugging. The chat id is attacker
// controllable and personal data, so only a pseudonym of a numeric value is logged.
$rawchatid = (string) ($update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? '');
local_jedomi_log('Telegram webhook: update arrived', [
    'chat' => preg_match('/^-?\d{1,20}$/', $rawchatid) ? local_jedomi_log_pseudonym('chat', $rawchatid)
        : 'invalid(len=' . strlen($rawchatid) . ')',
    'type' => isset($update['message']) ? 'message' : (isset($update['callback_query']) ? 'callback_query' : 'unknown'),
    'length' => strlen($input),
]);

// ... ── Let PHP keep running after we respond ──
ignore_user_abort(true);
set_time_limit(0);

// ... ── Send 200 OK FIRST so Telegram doesn't retry ──
// The tool loop can take 30-120s for complex tasks. If we process first and
// then send 200 OK, Telegram will retry before we finish, piling up requests.
// By sending 200 OK immediately, Telegram considers it delivered and won't
// retry, while we continue processing in the background.
webhook_handler::acknowledge();

// ... ── Shutdown handler catches fatal errors ──
register_shutdown_function(function () use ($update): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $msg = 'FATAL: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
        local_jedomi_log($msg);
    }
});

// ... ── Process message ──
try {
    webhook_handler::handle($update);
} catch (\Throwable $e) {
    $msg = 'UNCAUGHT: ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine();
    local_jedomi_log($msg);
}
