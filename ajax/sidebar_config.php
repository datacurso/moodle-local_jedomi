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
 * AJAX endpoint — returns the full Jedomi sidebar config as JSON.
 *
 * Called from local_jedomi/sidebar AMD module at init time.
 * Replaces inline js_call_amd data to avoid >1024 char warning.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use aiprovider_datacurso\local\plugin_settings;

header('Content-Type: application/json; charset=utf-8');

// Guests and requests without a session never get chat permissions.
if (!isloggedin() || isguestuser()) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => get_string('chat_error_guest', 'local_jedomi')]);
    die();
}

try {
    require_login(null, false);
    plugin_settings::require_enabled('local_jedomi');

    // Set page URL to prevent a Moodle debugging notice; the page context comes from the parameters below.
    $PAGE->set_url('/local/jedomi/ajax/sidebar_config.php');

    // The course of the page the panel is on: the chat permissions are bound to it. It comes from the browser, so
    // it must be a course the user can enter; otherwise no permissions are signed for it.
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $courseerror = local_jedomi_course_request_error($courseid);
    if ($courseerror !== null) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => $courseerror['message']]);
        die();
    }
    if ($courseid > SITEID) {
        $PAGE->set_course(get_course($courseid));
    }

    // Same access rules as the panel: an unavailable course or hidden roles get no chat permissions.
    $denied = local_jedomi_chat_access_error((int) $COURSE->id);
    if ($denied !== null) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => $denied['message']]);
        die();
    }

    // A signed permission token (SPT) already given to the panel is reused only when it is valid for this session and
    // signed for the same course; otherwise a new one is signed for the course checked above.
    $sptraw = optional_param('spt', '', PARAM_RAW);
    $spt = $sptraw === '' ? null : json_decode($sptraw, true);
    if (is_array($spt)) {
        $signed = local_jedomi_signed_request_course($spt, (int) $COURSE->id);
        if ($signed['error'] !== null) {
            http_response_code(403);
            echo json_encode(['error' => true, 'message' => $signed['error']['message']]);
            die();
        }
    } else {
        require_sesskey();
        $spt = local_jedomi_generate_spt(local_jedomi_get_user_role());
    }

    // The page the panel is on, as the page gave it when it was rendered: checked and cleaned before it is used (an
    // activity of another course or hidden from the user is ignored).
    $config = local_jedomi_get_sidebar_config([
        'cmid' => optional_param('cmid', 0, PARAM_INT),
        'pagetype' => optional_param('pagetype', '', PARAM_ALPHANUMEXT),
        'path' => optional_param('pagepath', '', PARAM_RAW),
    ]);
    $config['spt'] = $spt;

    echo json_encode($config);
} catch (\Exception $e) {
    echo json_encode([
        'error'   => true,
        'message' => \local_jedomi\local\safe_error::for_user($e, 'ajax/sidebar_config'),
    ]);
}
