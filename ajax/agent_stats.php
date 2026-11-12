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
// The search only matches the instructions the user may read; never taken from the request.
$filters['actorid'] = (int) $USER->id;

try {
    $stats = agent_manager::get_stats($filters);
    // Each row keeps its instruction only for the users who may read it, and says whether they may manage it.
    $rows = array_map(fn(\stdClass $row): \stdClass => agent_manager::agent_for_actor($row, (int) $USER->id), $stats->rows);

    echo json_encode([
        'ok'     => true,
        'charts' => $stats->charts,
        'rows'   => $rows,
        'total'  => $stats->total,
        'page'   => $stats->page,
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ]);
}
