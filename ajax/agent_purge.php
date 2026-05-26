<?php
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
        'error' => 'POST required',
    ]);
    exit;
}

$type = required_param('type', PARAM_ALPHA);
$id = optional_param('id', null, PARAM_INT);

try {
    $deleted = 0;

    switch ($type) {
        case 'site':
            $deleted = agent_manager::purge_all();
            break;

        case 'course':
        case 'category':
            if ($id === null) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'id required for course/category purge',
                ]);
                exit;
            }
            $deleted = agent_manager::purge_by_context($type, $id);
            break;

        case 'role':
            if ($id === null) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'id required for role purge',
                ]);
                exit;
            }
            // Get role shortname from ID to pass to purge_by_role.
            $role = $DB->get_field('role', 'shortname', ['id' => $id]);
            if (!$role) {
                echo json_encode([
                    'ok'    => false,
                    'error' => 'Role not found',
                ]);
                exit;
            }
            $deleted = agent_manager::purge_by_role($role);
            break;

        default:
            echo json_encode([
                'ok'    => false,
                'error' => 'Invalid type: ' . $type,
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
