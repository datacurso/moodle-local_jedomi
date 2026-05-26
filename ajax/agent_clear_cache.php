<?php
/**
 * AJAX endpoint — POST clear preview cache: delete a single cached preview entry.
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
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$hash = required_param('hash', PARAM_ALPHANUM);
if (strlen($hash) !== 32) {
    echo json_encode(['ok' => false, 'error' => 'Invalid hash']);
    exit;
}

$deleted = agent_manager::clear_preview_cache($hash);
echo json_encode([
    'ok' => true,
    'deleted' => $deleted,
]);
