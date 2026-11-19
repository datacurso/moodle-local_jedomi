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
 * AJAX endpoint — GET the data of the charts of the Agents tab.
 *
 * The charts count the agents that match the filters the user saved in the agents report, so they follow the report.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_login(null, false);
require_capability('local/jedomi:manageagents', context_system::instance());
require_sesskey();

use core_reportbuilder\system_report_factory;
use local_jedomi\agent\agent_manager;
use local_jedomi\reportbuilder\local\helpers\filtered_ids;
use local_jedomi\reportbuilder\local\systemreports\agents;

header('Content-Type: application/json');

try {
    $report = system_report_factory::create(agents::class, context_system::instance());
    echo json_encode([
        'ok'     => true,
        'charts' => agent_manager::get_chart_stats(['idsql' => filtered_ids::sql($report)]),
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'ok'    => false,
        'error' => \local_jedomi\local\safe_error::for_user($e, 'ajax/agent_stats'),
    ]);
}
