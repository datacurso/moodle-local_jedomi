<?php
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
use local_jedomi\proxy\system_message;
use local_jedomi\proxy\handler;

// ── Method check ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    die('Method Not Allowed');
}

// ── Auth ──
require_login();

// ── Parse input ──
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

$model    = $input['model'] ?? get_config('local_jedomi', 'model') ?: 'jedomi-e4b';
$messages = $input['messages'];
$spt      = $input['spt'] ?? null;
$context  = $input['context'] ?? [];

// ── Validate SPT ──
if (!$spt || !is_array($spt)) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'invalid_spt']));
}

try {
    spt_validator::validate($spt); // sesskey + userid + TTL + firma. El rol que trae NO se usa para autorizar.
} catch (\moodle_exception $e) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => $e->getMessage()]));
}
// Contexto de curso/actividad firmado en el SPT: se vuelve a comprobar el acceso del usuario (require_login)
// para que $COURSE/$PAGE->cm sean los reales; el cliente no puede elegir otro curso.
require_once(__DIR__ . '/lib.php');
$spt_courseid = (int) ($spt['course_id'] ?? 0);
$spt_cmid     = (int) ($spt['cmid'] ?? 0);
$PAGE->set_url('/local/jedomi/chatproxy.php', ['courseid' => $spt_courseid, 'cmid' => $spt_cmid]);
if ($spt_courseid > 1) {
    try {
        $course = get_course($spt_courseid);
        $cm = $spt_cmid > 0 ? (get_coursemodule_from_id('', $spt_cmid, $spt_courseid, false, IGNORE_MISSING) ?: null) : null;
        require_login($course, false, $cm);
    } catch (\Throwable $e) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'course_access_denied']));
    }
}
// El rol y el modo se recalculan SIEMPRE en servidor a partir de las capacidades del usuario real (QA r7).
$role = local_jedomi_get_user_role();
$spt['role'] = $role;
$spt['mode'] = local_jedomi_resolve_mode($spt_courseid, $spt_cmid, $role);
$spt['cmid'] = $spt_cmid;

// Sólo se aceptan turnos user/assistant del cliente: nunca mensajes system/tool/developer inyectados.
$messages = array_values(array_filter($messages, function ($m) {
    return is_array($m) && isset($m['role']) && in_array($m['role'], ['user', 'assistant'], true);
}));

// ── Build system message ──
$system   = system_message::build($role, $spt, $context);
$messages = array_merge([$system], $messages);

// ── SSE headers ──
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
ob_implicit_flush(true);

// ── Run tool loop ──
handler::run($model, $messages, $role, $spt);
