<?php
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
    require_sesskey();

    // Contexto de curso/actividad REAL (QA r7 / diseño 3.3.1): se valida el acceso del usuario al curso y, si
    // procede, al módulo; así $COURSE y $PAGE->cm quedan correctos para el rol, el contexto y el SPT firmado.
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $cmid     = optional_param('cmid', 0, PARAM_INT);
    $PAGE->set_url('/local/jedomi/ajax/sidebar_config.php', ['courseid' => $courseid, 'cmid' => $cmid]);
    if ($courseid > 1) {
        $course = get_course($courseid);
        $cm = null;
        if ($cmid > 0) {
            $cm = get_coursemodule_from_id('', $cmid, $courseid, false, IGNORE_MISSING) ?: null;
            if (!$cm) {
                $cmid = 0;
            }
        }
        require_login($course, false, $cm);
    } else {
        $cmid = 0;
    }

    $role = local_jedomi_get_user_role();
    $mode = local_jedomi_resolve_mode((int) ($COURSE->id ?? 0), $cmid, $role);
    $spt  = local_jedomi_generate_spt($role, $cmid, $mode);

    $config = local_jedomi_get_sidebar_config();
    $config['spt']  = $spt;
    $config['mode'] = $mode;

    echo json_encode($config);

} catch (\Exception $e) {
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage(),
    ]);
}
