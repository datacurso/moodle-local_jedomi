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
 * AJAX endpoint — POST purge agents by context or role.
 *
 * Accepts: type (course/category/site/role) and optional id.
 * Requires admin + sesskey.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_login(null, false);
require_capability('local/jedomi:manageagents', context_system::instance());
require_sesskey();

use local_jedomi\agent\agent_manager;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'ok'    => false,
        'error' => get_string('error_post_required', 'local_jedomi'),
    ]);
    exit;
}

$type = required_param('type', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$actorid = (int) $USER->id;

try {
    // Only the agents the user may manage are purged: never those of users with more permissions.
    switch ($type) {
        case 'site':
            $deleted = agent_manager::purge_by_context('site', null, $actorid);
            break;

        case 'course':
        case 'category':
        case 'user':
            if ($id <= 0) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_purge_id_required', 'local_jedomi'),
                ]);
                exit;
            }
            $deleted = agent_manager::purge_by_context($type, $id, $actorid);
            break;

        case 'role':
            // The role is chosen from the list of roles of the site.
            $role = $DB->get_field('role', 'shortname', ['id' => optional_param('roleid', 0, PARAM_INT)]);
            if (!$role) {
                echo json_encode([
                    'ok'    => false,
                    'error' => get_string('agent_purge_role_not_found', 'local_jedomi'),
                ]);
                exit;
            }
            $deleted = agent_manager::purge_by_role($role, $actorid);
            break;

        default:
            echo json_encode([
                'ok'    => false,
                'error' => get_string('agent_purge_invalid_type', 'local_jedomi', $type),
            ]);
            exit;
    }

    echo json_encode([
        'ok'      => true,
        'deleted' => $deleted,
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ]);
}
