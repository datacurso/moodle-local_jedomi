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
 * Audio of a sentence of a Jedomi answer, for the voice conversation of the sidebar.
 *
 * POST JSON {text, sesskey, spt}. Answers the MP3 read by the speech model of the settings, or
 * {"error": {"code", "message"}} with a message ready to show. Every check (session, session key,
 * signed permissions, plugin, voice conversation switch, length and rate limit) is made before the AI
 * service is called; see \local_jedomi\local\service\speech_service::handle_request().
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// An error of the login check or a debugging message must never answer an HTML page or corrupt the MP3 body.
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_jedomi\local\service\speech_service;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    die('Method Not Allowed');
}

if (!isloggedin() || isguestuser()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => ['code' => 'guest_not_allowed', 'message' => get_string('chat_error_guest', 'local_jedomi')]]));
}
try {
    require_login(null, false);
    $loginerror = null;
} catch (\moodle_exception $e) {
    // Answered with the JSON error the sidebar reads, like every other refusal.
    $loginerror = speech_service::login_error($e);
}
// Nothing is written to the session: release its lock, so the sentences requested in a row do not wait for each other.
\core\session\manager::write_close();

if ($loginerror !== null) {
    $response = $loginerror;
} else {
    $input = json_decode((string) file_get_contents('php://input'), true);
    $response = speech_service::handle_request(is_array($input) ? $input : []);
}

http_response_code($response['status']);
header('Content-Type: ' . $response['contenttype']);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($response['body']));
echo $response['body'];
