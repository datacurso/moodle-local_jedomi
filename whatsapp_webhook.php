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
 * WhatsApp (Meta Cloud API) webhook public endpoint.
 *
 * GET answers the verification handshake; POST notifications are checked with the X-Hub-Signature-256 header over the
 * raw body. Everything else is done by \local_jedomi\whatsapp\intake.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The webhook has no browser session: Meta authenticates with the verify token and the signature of the body.
define('NO_MOODLE_COOKIES', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- webhook auth uses the Meta verify token and body signature.
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
// PHP names the hub.* parameters of the handshake with underscores.
$query = [
    'hub_mode' => optional_param('hub_mode', '', PARAM_ALPHA),
    'hub_verify_token' => optional_param('hub_verify_token', '', PARAM_RAW),
    'hub_challenge' => optional_param('hub_challenge', '', PARAM_RAW),
];
// The raw bytes are read before anything decodes them: the signature covers exactly these bytes.
$rawbody = $method === 'POST' ? (string) file_get_contents('php://input') : '';
$signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? (string) $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : null;

if ($method === 'POST') {
    // Processing goes on after the early response to Meta.
    ignore_user_abort(true);
    core_php_time_limit::raise();

    register_shutdown_function(function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            local_jedomi_log('WhatsApp webhook: FATAL at ' . basename($error['file']) . ':' . $error['line']);
        }
    });
}

try {
    (new \local_jedomi\whatsapp\intake())->handle($method, $query, $rawbody, $signature);
} catch (\Throwable $e) {
    // JEDOMI-SEC-009: the class, the error code and the line, never the message (it can quote a query or a user).
    local_jedomi_log('WhatsApp webhook: UNCAUGHT ' . \local_jedomi\local\safe_error::describe($e) . ' at '
        . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
}
