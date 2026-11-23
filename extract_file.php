<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Text of a file attached in the Jedomi sidebar (.pdf, .csv, .txt, .md, .doc, .docx, .ppt or .pptx), or
 * the transcription of an audio file or a microphone recording.
 *
 * POST multipart with the field "file" and the session key. The file is read by the AI service
 * through the Datacurso provider; the answer is JSON with the text and its data, or
 * {"error": {"code", "message"}} with a message ready to show. Documents are refused when file and
 * image processing is off, and audio when audio processing is off.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_jedomi\local\service\chat_rate_limit_service;
use local_jedomi\local\service\file_extraction_service;

require_login(null, false);
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

/**
 * Answer with an error and stop.
 *
 * @param int $status HTTP status.
 * @param array $error Error code and message.
 */
function local_jedomi_extract_file_error(int $status, array $error): void {
    http_response_code($status);
    echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
    die();
}

$disabled = local_jedomi_plugin_disabled_error();
if ($disabled !== null) {
    local_jedomi_extract_file_error(403, $disabled);
}

// Same access rules as the panel: guests, an unavailable course or hidden roles are refused. The course is the one
// signed in the permissions of the panel, never the course the browser names (a different one is refused).
$signed = local_jedomi_request_signed_course();
if ($signed['error'] !== null) {
    local_jedomi_extract_file_error(403, $signed['error']);
}

// Every request counts toward the message limit of the web chat, after the access checks and before the file is read
// (JEDOMI-SEC-006, RISK-001).
$limited = chat_rate_limit_service::refusal((int) $USER->id, 'upload');
if ($limited !== null) {
    local_jedomi_extract_file_error(429, $limited);
}

$upload = $_FILES['file'] ?? null;
if (
    !is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file($upload['tmp_name'] ?? '')
) {
    $toolarge = in_array($upload['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    local_jedomi_extract_file_error(400, [
        'code' => $toolarge ? 'file_too_large' : 'file_unsupported',
        'message' => get_string($toolarge ? 'file_error_too_large' : 'file_error_unsupported', 'local_jedomi'),
    ]);
}

try {
    $result = file_extraction_service::extract_upload((int) $USER->id, $upload['tmp_name'], (string) $upload['name']);
} catch (\Throwable $e) {
    local_jedomi_extract_file_error(400, file_extraction_service::error_for($e));
}
// The panel sends the name and the text of the file to the chat model between untrusted data markers (JEDOMI-SEC-005).
$filename = clean_param((string) $upload['name'], PARAM_FILE);
echo json_encode(file_extraction_service::extraction_response($filename, $result), JSON_UNESCAPED_UNICODE);
