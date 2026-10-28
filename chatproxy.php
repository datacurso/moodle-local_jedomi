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

// ... ── Method check ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    die('Method Not Allowed');
}

// ... ── Auth ──
require_login();

// ... ── Parse input ──
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['messages'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'invalid_request']));
}

$sesskey = $input['sesskey'] ?? '';
if (!confirm_sesskey($sesskey)) {
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
    die(json_encode(['error' => $e->getMessage()]));
}

// ... ── SSE headers ──
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
ob_implicit_flush(true);

// ... ── Run tool loop ──
handler::run_from_request($input, $role, $spt, (int) $USER->id);
