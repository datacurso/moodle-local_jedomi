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
 * AJAX endpoint: deletes one cached agent preview, keyed by the MD5 of its prompt.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_login(null, false);
require_capability('local/jedomi:manageagents', context_system::instance());
require_sesskey();

use local_jedomi\agent\agent_manager;

header('Content-Type: application/json');

if (!data_submitted()) {
    echo json_encode(['ok' => false, 'error' => get_string('error_post_required', 'local_jedomi')]);
    exit;
}

$hash = required_param('hash', PARAM_ALPHANUM);
if (strlen($hash) !== 32) {
    echo json_encode(['ok' => false, 'error' => get_string('error_invalid_hash', 'local_jedomi')]);
    exit;
}

// Count first: the deletion itself only reports success, not how many entries it removed.
$cached = $DB->count_records('local_jedomi_agent_cache', ['agentid' => 0, 'context_hash' => $hash]);
agent_manager::clear_preview_cache($hash);
echo json_encode([
    'ok' => true,
    'deleted' => $cached > 0,
]);
