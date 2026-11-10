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
 * Content of an image attached in the Jedomi sidebar, read by the vision model.
 *
 * POST multipart with the field "file" (jpg, png or webp), the optional "question" of the user and
 * the session key. The image goes through the Datacurso provider to POST /jedomi/v1/files/read; the
 * answer is JSON with the text read and the "message" for the chat model (the question followed by
 * that text), or {"error": {"code", "message"}} with a message ready to show. Refused when file and
 * image processing is off.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_jedomi\local\service\vision_service;

require_login(null, false);
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

/**
 * Answer with an error and stop.
 *
 * @param int $status HTTP status.
 * @param array $error Error code and message.
 */
function local_jedomi_read_file_error(int $status, array $error): void {
    http_response_code($status);
    echo json_encode(['error' => $error], JSON_UNESCAPED_UNICODE);
    die();
}

$disabled = local_jedomi_plugin_disabled_error();
if ($disabled !== null) {
    local_jedomi_read_file_error(403, $disabled);
}

// Same access rules as the panel: guests, an unavailable course or hidden roles are refused.
$denied = local_jedomi_chat_access_error(optional_param('courseid', 0, PARAM_INT));
if ($denied !== null) {
    local_jedomi_read_file_error(403, $denied);
}

$question = optional_param('question', '', PARAM_TEXT);
$upload = $_FILES['file'] ?? null;
if (
    !is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file($upload['tmp_name'] ?? '')
) {
    $toolarge = in_array($upload['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    local_jedomi_read_file_error(400, [
        'code' => $toolarge ? 'file_too_large' : 'file_unsupported',
        'message' => get_string($toolarge ? 'file_error_too_large' : 'image_error_unsupported', 'local_jedomi'),
    ]);
}

$filename = clean_param((string) $upload['name'], PARAM_FILE);
try {
    $result = vision_service::read_upload((int) $USER->id, $upload['tmp_name'], $filename, $question);
} catch (\Throwable $e) {
    local_jedomi_read_file_error(400, vision_service::error_for($e));
}
echo json_encode([
    'text' => $result['text'],
    'message' => vision_service::compose_message($question, $filename, $result['text']),
], JSON_UNESCAPED_UNICODE);
