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
