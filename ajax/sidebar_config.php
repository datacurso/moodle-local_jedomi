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
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

header('Content-Type: application/json; charset=utf-8');

try {
    require_login();

    // Set page URL to prevent Moodle debugging notice in lib.php::local_jedomi_get_page_context().
    $PAGE->set_url('/local/jedomi/ajax/sidebar_config.php');

    // Accept sesskey OR a pre-generated SPT (signed permission token).
    $spt_raw = optional_param('spt', '', PARAM_RAW);
    if (empty($spt_raw)) {
        require_sesskey();
        $spt = local_jedomi_generate_spt(local_jedomi_get_user_role());
    } else {
        $spt = json_decode($spt_raw, true);
        if (!is_array($spt)) {
            $spt = local_jedomi_generate_spt(local_jedomi_get_user_role());
        }
    }

    $config = local_jedomi_get_sidebar_config();
    $config['spt'] = $spt;

    echo json_encode($config);
} catch (\Exception $e) {
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage(),
    ]);
}
