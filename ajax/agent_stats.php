<?php
/**
 * AJAX endpoint — GET agent stats for charts + table.
 *
 * Returns aggregated stats (by_role, by_status, executions_30d) and
 * paginated agent rows matching the optional filters.
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

$filters = [];
$raw = optional_param('filters', '', PARAM_RAW);

if (!empty($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $filters = $decoded;
    }
}

$filters['page'] = optional_param('page', 1, PARAM_INT);
$filters['perpage'] = optional_param('perpage', 20, PARAM_INT);

try {
    $stats = agent_manager::get_stats($filters);

    echo json_encode([
        'ok'     => true,
        'charts' => $stats->charts,
        'rows'   => $stats->rows,
        'total'  => $stats->total,
        'page'   => $stats->page,
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ]);
}
