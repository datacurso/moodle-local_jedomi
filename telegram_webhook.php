<?php
/**
 * Telegram webhook public endpoint.
 *
 * Validates secret, decodes JSON body, and delegates to webhook_handler.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_jedomi\telegram\manager;
use local_jedomi\telegram\webhook_handler;

// ── Validate webhook secret ──────────────────────────────────────────────────
$secret_param = optional_param('secret', '', PARAM_ALPHANUMEXT);
$expected_secret = manager::get_webhook_secret();

if (!hash_equals($expected_secret, $secret_param)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// ── Decode Telegram payload ──────────────────────────────────────────────────
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!is_array($update)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// ── Process ────────────────────────────────────────────────────────────────────
webhook_handler::handle($update);

// Always return 200 OK to Telegram (otherwise it retries)
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
