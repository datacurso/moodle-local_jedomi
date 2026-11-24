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
 * AJAX endpoint: one page of the web service table of the APIs tab, as JSON data.
 *
 * Called by the local_jedomi/admin/apis module when the filters, the type pills or the page change.
 * The rows are rendered in the browser with the local_jedomi/admin/ws_table template.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

use local_jedomi\local\service\admin_settings_service;
use local_jedomi\output\admin\apis_tab;

require_login(null, false);
require_sesskey();
require_capability('moodle/site:config', context_system::instance());

$role = admin_settings_service::normalise_ws_role(optional_param('role', 'admin', PARAM_ALPHA));
$table = admin_settings_service::get_ws_table(
    $role,
    optional_param('search', '', PARAM_TEXT),
    optional_param('component', '', PARAM_COMPONENT),
    optional_param('type', 'all', PARAM_ALPHA),
    optional_param('page', 1, PARAM_INT),
    optional_param('perpage', 25, PARAM_INT)
);

echo json_encode([
    'role' => $role,
    'rows' => $table['rows'],
    'hasrows' => !empty($table['rows']),
    'pagination' => apis_tab::export_pagination($table['page'], $table['pages'], $table['total']),
    'counts' => ['all' => $table['count_all'], 'Lectura' => $table['count_read'], 'Escritura' => $table['count_write']],
]);
