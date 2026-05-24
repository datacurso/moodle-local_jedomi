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
 * Jedomi advanced admin settings — tabbed UI.
 * Tabs: General · Course availability · Privacy
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$tab     = optional_param('tab', 'general', PARAM_ALPHA);
$pageurl = new moodle_url('/local/jedomi/admin_settings.php');

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/jedomi/admin_settings.php', ['tab' => $tab]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'local_jedomi'));
$PAGE->set_heading(get_string('pluginname', 'local_jedomi'));

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $savetype = optional_param('jedomi_save', '', PARAM_ALPHA);

    switch ($savetype) {

        case 'general':
            set_config('enabled',               optional_param('enabled',               0, PARAM_INT),  'local_jedomi');
            set_config('gateway_url',           required_param('gateway_url',           PARAM_URL),     'local_jedomi');
            set_config('api_key',               required_param('api_key',               PARAM_TEXT),    'local_jedomi');
            set_config('model',                 required_param('model',                 PARAM_TEXT),    'local_jedomi');
            set_config('docservice_url',        optional_param('docservice_url',        '',             PARAM_TEXT),    'local_jedomi');
            set_config('spt_secret',            required_param('spt_secret',            PARAM_TEXT),    'local_jedomi');
            set_config('collect_training_data', optional_param('collect_training_data', 0, PARAM_INT),  'local_jedomi');

            redirect(new moodle_url($pageurl, ['tab' => 'general']),
                get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'courses':
            $mode = required_param('course_mode', PARAM_ALPHA);
            if (!in_array($mode, ['all', 'include', 'exclude'], true)) {
                $mode = 'all';
            }
            set_config('course_mode', $mode, 'local_jedomi');

            $list = optional_param('course_list', '', PARAM_TEXT);
            $ids  = array_filter(array_map('intval', explode(',', $list)));
            set_config('course_list', implode(',', $ids), 'local_jedomi');

            redirect(new moodle_url($pageurl, ['tab' => 'courses']),
                get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'privacy':
            set_config('hide_from_guests',    optional_param('hide_from_guests',    0, PARAM_INT), 'local_jedomi');
            set_config('anonymization_level', required_param('anonymization_level', PARAM_ALPHA),  'local_jedomi');

            // Save hidden roles: collect all role IDs that were toggled OFF.
            $all_r   = $DB->get_records('role', null, 'sortorder ASC', 'id');
            $hidden  = [];
            foreach ($all_r as $r) {
                $visible = optional_param('role_visible_' . $r->id, 0, PARAM_INT);
                if (!$visible) {
                    $hidden[] = (int) $r->id;
                }
            }
            set_config('hidden_roles', implode(',', $hidden), 'local_jedomi');

            redirect(new moodle_url($pageurl, ['tab' => 'privacy']),
                get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'integrations':
            // Load roles here — $all_roles is defined later for the render phase
            $all_roles_post = $DB->get_records('role', null, 'sortorder ASC', 'id');

            $enabled = optional_param('telegram_enabled', 0, PARAM_INT);
            set_config('telegram_enabled', $enabled, 'local_jedomi');

            $token = optional_param('telegram_bot_token', '', PARAM_TEXT);
            if ($token !== '') {
                \local_jedomi\telegram\manager::set_bot_token($token);
                // Verify token immediately via getMe and cache the bot username
                $client = new \local_jedomi\telegram\api_client($token);
                $bot_info = $client->get_me();
                if ($bot_info) {
                    set_config('telegram_bot_username',  $bot_info['username'],   'local_jedomi');
                    set_config('telegram_bot_firstname', $bot_info['first_name'], 'local_jedomi');
                    set_config('telegram_bot_verified',  1,                       'local_jedomi');
                } else {
                    set_config('telegram_bot_verified',  0, 'local_jedomi');
                }
            }

            // Regenerate secret if requested
            if (optional_param('telegram_regenerate_secret', 0, PARAM_INT)) {
                \local_jedomi\telegram\manager::regenerate_webhook_secret();
            }

            set_config('telegram_rate_limit', optional_param('telegram_rate_limit', 30, PARAM_INT), 'local_jedomi');
            set_config('telegram_cooldown',   optional_param('telegram_cooldown',   5,  PARAM_INT), 'local_jedomi');

            // Save per-role visibility for Telegram (reuse same hidden_roles logic with prefix)
            $telegram_hidden = [];
            foreach ($all_roles_post as $r) {
                $visible = optional_param('telegram_role_visible_' . $r->id, 0, PARAM_INT);
                if (!$visible) {
                    $telegram_hidden[] = (int) $r->id;
                }
            }
            set_config('telegram_hidden_roles', implode(',', $telegram_hidden), 'local_jedomi');

            redirect(new moodle_url($pageurl, ['tab' => 'integrations']),
                get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;

        case 'apis':
            $ap_roles = ['admin', 'manager', 'teacher', 'student'];
            foreach ($ap_roles as $ar) {
                if (optional_param('ws_' . $ar . '_submitted', 0, PARAM_INT)) {
                    $checked = optional_param_array('ws_' . $ar, [], PARAM_ALPHANUMEXT);
                    set_config('ws_allowlist_' . $ar, json_encode(array_values($checked)), 'local_jedomi');
                }
            }

            redirect(new moodle_url($pageurl, ['tab' => 'apis', 'role' => optional_param('api_role', 'admin', PARAM_ALPHA)]),
                get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
            break;
    }
}

// ── Load values ──────────────────────────────────────────────────────────────
$cfg = [
    // general
    'enabled'               => (int)(get_config('local_jedomi', 'enabled') ?? 1),
    'gateway_url'           => get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765',
    'api_key'               => get_config('local_jedomi', 'api_key')     ?: '',
    'model'                 => get_config('local_jedomi', 'model')        ?: 'jedomi-e4b',
    'docservice_url'        => get_config('local_jedomi', 'docservice_url') ?: '',
    'spt_secret'            => get_config('local_jedomi', 'spt_secret')   ?: '',
    'collect_training_data' => (function() {
        $v = get_config('local_jedomi', 'collect_training_data');
        return ($v === false) ? 1 : (int)$v;  // default ON
    })(),
    // courses
    'course_mode'           => get_config('local_jedomi', 'course_mode')  ?: 'all',
    'course_list'           => get_config('local_jedomi', 'course_list')  ?: '',
    // privacy
    'hide_from_guests'      => (int)(get_config('local_jedomi', 'hide_from_guests') ?? 1),
    'anonymization_level'   => get_config('local_jedomi', 'anonymization_level') ?: 'basic',
    'hidden_roles'          => get_config('local_jedomi', 'hidden_roles') ?: '',
];

// Load all roles for the role-visibility toggles.
$all_roles = $DB->get_records('role', null, 'sortorder ASC', 'id,shortname,name,description');
foreach ($all_roles as $role) {
    $role->localname = role_get_name($role, context_system::instance());
}
$hidden_role_ids = array_filter(array_map('intval', explode(',', $cfg['hidden_roles'])));

// Pre-load course chips.
$courseids      = array_filter(array_map('intval', explode(',', $cfg['course_list'])));
$selectedcourses = [];
if (!empty($courseids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
    $records = $DB->get_records_sql(
        "SELECT c.id, c.shortname, c.fullname, cc.name AS categoryname
           FROM {course} c
           JOIN {course_categories} cc ON cc.id = c.category
          WHERE c.id {$insql}
       ORDER BY c.shortname ASC",
        $inparams
    );
    foreach ($records as $r) {
        $selectedcourses[] = [
            'id'           => (int) $r->id,
            'shortname'    => $r->shortname,
            'fullname'     => $r->fullname,
            'categoryname' => $r->categoryname,
        ];
    }
}

// ── Render ───────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<style>
.jedomi-adm .card            { border-radius:.5rem; margin-bottom:1.25rem; border:1px solid #e5e7eb; }
.jedomi-adm .card-header     { font-weight:600; background:#f9fafb; border-bottom:1px solid #e5e7eb;
                                padding:1rem 1.25rem; display:flex; align-items:center; gap:.625rem; }
.jedomi-adm .card-header .card-icon {
    width:32px; height:32px; border-radius:.375rem; display:flex;
    align-items:center; justify-content:center; flex-shrink:0; }
.jedomi-adm .card-header h5  { margin:0; font-size:1rem; color:#111827; }
.jedomi-adm .card-header small { color:#6b7280; font-size:.8rem; font-weight:400; display:block; margin-top:1px; }
.jedomi-adm .card-body       { padding:1.25rem; }
.jedomi-adm .save-row        { margin-top:1.25rem; padding-top:1rem; border-top:1px solid #e5e7eb; }
.jedomi-adm .form-label-main { font-weight:600; font-size:.875rem; color:#374151; margin-bottom:.375rem; }
.jedomi-adm .form-text       { font-size:.8rem; color:#6b7280; margin-top:.25rem; }
.jedomi-adm .jedomi-mode-opt { border:2px solid #e5e7eb; border-radius:.75rem; cursor:pointer;
                                padding:.75rem 1rem; margin-bottom:.5rem; display:flex;
                                align-items:flex-start; gap:.75rem; transition:all .2s; background:#fff; }
.jedomi-adm .jedomi-mode-opt input[type=radio] { margin-top:3px; width:18px; height:18px; cursor:pointer; flex-shrink:0; }
.jedomi-adm .jedomi-mode-opt strong { font-size:.9375rem; display:block; margin-bottom:2px; }
.jedomi-adm .jedomi-mode-opt small  { color:#6b7280; font-weight:400; }
.jedomi-adm .toggle-row      { display:flex; align-items:center; justify-content:space-between;
                                padding:.875rem 0; border-bottom:1px solid #f3f4f6; }
.jedomi-adm .toggle-row:last-child { border-bottom:none; }
.jedomi-adm .toggle-row .toggle-info strong { display:block; font-size:.9375rem; color:#111827; }
.jedomi-adm .toggle-row .toggle-info small  { color:#6b7280; font-size:.8rem; }
@keyframes jedomi-chip-in { from{opacity:0;transform:scale(.9)} to{opacity:1;transform:scale(1)} }
.jedomi-chip:hover { background:#e2e8f0!important; border-color:#cbd5e1!important; }
</style>

<div class="jedomi-adm" style="max-width:780px; margin:0 auto; padding-bottom:2rem">

<?php
// ── Tab navigation ─────────────────────────────────────────────────────────
$tabs = [
    'general'      => ['icon' => 'fa-cog',            'label' => get_string('tab_general',      'local_jedomi')],
    'courses'    => ['icon' => 'fa-graduation-cap', 'label' => get_string('course_availability_heading', 'local_jedomi')],
    'privacy'    => ['icon' => 'fa-shield',         'label' => get_string('tab_privacy',      'local_jedomi')],
    'integrations'=> ['icon' => 'fa-plug',           'label' => get_string('tab_integrations', 'local_jedomi')],
    'apis'        => ['icon' => 'fa-cloud',          'label' => get_string('tab_apis',          'local_jedomi')],
];

echo '<ul class="nav nav-tabs mb-4" role="tablist">';
foreach ($tabs as $key => $info) {
    $active = ($tab === $key) ? ' active' : '';
    $url    = new moodle_url($pageurl, ['tab' => $key]);
    echo '<li class="nav-item">';
    echo '<a class="nav-link' . $active . '" href="' . $url->out(false) . '">';
    echo '<i class="fa ' . $info['icon'] . ' mr-1"></i> ' . $info['label'];
    echo '</a></li>';
}
echo '</ul>';
?>

<?php // ─────────────────────────────────── TAB: GENERAL ─────────────────── ?>
<?php if ($tab === 'general'): ?>
<form method="post" action="<?php echo (new moodle_url($pageurl, ['tab' => 'general']))->out(false); ?>">
    <input type="hidden" name="sesskey"     value="<?php echo sesskey(); ?>">
    <input type="hidden" name="jedomi_save" value="general">

    <!-- Card: Conexión al Gateway -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon" style="background:#ede9fe"><i class="fa fa-plug" style="color:#7c3aed"></i></div>
            <div>
                <h5><?php echo get_string('gateway_heading', 'local_jedomi'); ?></h5>
                <small><?php echo get_string('gateway_heading_help', 'local_jedomi'); ?></small>
            </div>
        </div>
        <div class="card-body">

            <!-- Enabled toggle -->
            <div class="toggle-row">
                <div class="toggle-info">
                    <strong><?php echo get_string('enabled', 'local_jedomi'); ?></strong>
                    <small><?php echo get_string('enabled_help', 'local_jedomi'); ?></small>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="enabled"
                           value="1" <?php echo $cfg['enabled'] ? 'checked' : ''; ?>
                           style="width:2.5rem;height:1.25rem;cursor:pointer">
                </div>
            </div>

            <!-- Gateway URL -->
            <div class="mt-3">
                <label class="form-label-main" for="jedomi-gateway-url">
                    <?php echo get_string('gateway_url', 'local_jedomi'); ?>
                </label>
                <input type="url" id="jedomi-gateway-url" name="gateway_url"
                       class="form-control" style="max-width:480px"
                       value="<?php echo s($cfg['gateway_url']); ?>"
                       placeholder="http://localhost:8765">
                <p class="form-text"><?php echo get_string('gateway_url_help', 'local_jedomi'); ?></p>
            </div>

            <!-- API Key -->
            <div class="mt-3">
                <label class="form-label-main" for="jedomi-api-key">
                    <?php echo get_string('api_key', 'local_jedomi'); ?>
                </label>
                <div style="position:relative;max-width:480px">
                    <input type="password" id="jedomi-api-key" name="api_key"
                           class="form-control"
                           value="<?php echo s($cfg['api_key']); ?>"
                           style="padding-right:2.5rem">
                    <button type="button" onclick="var i=document.getElementById('jedomi-api-key');i.type=i.type==='password'?'text':'password'"
                            style="position:absolute;right:.625rem;top:50%;transform:translateY(-50%);
                                   background:none;border:none;color:#9ca3af;cursor:pointer">
                        <i class="fa fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Model -->
            <div class="mt-3">
                <label class="form-label-main" for="jedomi-model">
                    <?php echo get_string('model', 'local_jedomi'); ?>
                </label>
                    <input type="text" id="jedomi-model" name="model"
                           class="form-control" style="max-width:280px"
                           value="<?php echo s($cfg['model']); ?>">
                </div>

                <div class="mt-3">
                    <label class="form-label-main" for="jedomi-docservice">
                        <?php echo get_string('docservice_url', 'local_jedomi'); ?>
                    </label>
                    <input type="url" id="jedomi-docservice" name="docservice_url"
                           class="form-control" style="max-width:480px"
                           value="<?php echo s($cfg['docservice_url'] ?? ''); ?>"
                           placeholder="http://localhost:8000">
                    <p class="form-text"><?php echo get_string('docservice_url_help', 'local_jedomi'); ?></p>
                </div>

        </div>
    </div>

    <!-- Card: Seguridad -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon" style="background:#fef3c7"><i class="fa fa-key" style="color:#d97706"></i></div>
            <div>
                <h5><?php echo get_string('security_heading', 'local_jedomi'); ?></h5>
                <small><?php echo get_string('security_heading_help', 'local_jedomi'); ?></small>
            </div>
        </div>
        <div class="card-body">

            <!-- SPT Secret -->
            <div>
                <label class="form-label-main" for="jedomi-spt">
                    <?php echo get_string('spt_secret', 'local_jedomi'); ?>
                </label>
                <div style="position:relative;max-width:480px">
                    <input type="password" id="jedomi-spt" name="spt_secret"
                           class="form-control"
                           value="<?php echo s($cfg['spt_secret']); ?>"
                           style="padding-right:2.5rem">
                    <button type="button" onclick="var i=document.getElementById('jedomi-spt');i.type=i.type==='password'?'text':'password'"
                            style="position:absolute;right:.625rem;top:50%;transform:translateY(-50%);
                                   background:none;border:none;color:#9ca3af;cursor:pointer">
                        <i class="fa fa-eye"></i>
                    </button>
                </div>
                <p class="form-text"><?php echo get_string('spt_secret_help', 'local_jedomi'); ?></p>
            </div>

        </div>
    </div>

    <!-- Card: Mejora continua -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon" style="background:#dcfce7"><i class="fa fa-chart-line" style="color:#16a34a"></i></div>
            <div>
                <h5><?php echo get_string('improvement_heading', 'local_jedomi'); ?></h5>
                <small><?php echo get_string('improvement_heading_help', 'local_jedomi'); ?></small>
            </div>
        </div>
        <div class="card-body">
            <div class="toggle-row">
                <div class="toggle-info">
                    <strong><?php echo get_string('collect_training_data', 'local_jedomi'); ?></strong>
                    <small><?php echo get_string('collect_training_data_help', 'local_jedomi'); ?></small>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="collect_training_data"
                           value="1" <?php echo $cfg['collect_training_data'] ? 'checked' : ''; ?>
                           style="width:2.5rem;height:1.25rem;cursor:pointer">
                </div>
            </div>
        </div>
    </div>

    <div class="save-row">
        <button type="submit" class="btn btn-primary">
            <i class="fa fa-save mr-1"></i><?php echo get_string('savechanges'); ?>
        </button>
    </div>
</form>
<?php endif; ?>


<?php // ──────────────────────────────── TAB: COURSES ──────────────────────── ?>
<?php if ($tab === 'courses'): ?>
<form method="post" action="<?php echo (new moodle_url($pageurl, ['tab' => 'courses']))->out(false); ?>">
    <input type="hidden" name="sesskey"     value="<?php echo sesskey(); ?>">
    <input type="hidden" name="jedomi_save" value="courses">
    <input type="hidden" name="course_list" id="jedomi-course-list-input"
           value="<?php echo implode(',', array_column($selectedcourses, 'id')); ?>">

    <div class="card">
        <div class="card-header">
            <div class="card-icon" style="background:#ede9fe"><i class="fa fa-graduation-cap" style="color:#7c3aed"></i></div>
            <div>
                <h5><?php echo get_string('course_availability_heading', 'local_jedomi'); ?></h5>
                <small><?php echo get_string('course_availability_heading_help', 'local_jedomi'); ?></small>
            </div>
        </div>
        <div class="card-body">

            <!-- Mode radios -->
            <div class="mb-4">
                <label class="form-label-main d-block mb-2">
                    <?php echo get_string('course_mode', 'local_jedomi'); ?>
                </label>
                <?php
                $modes = [
                    'all'     => ['color' => '#10b981', 'icon' => 'fa-globe',       'label' => 'course_mode_all',     'desc' => 'course_mode_all_desc'],
                    'exclude' => ['color' => '#f59e0b', 'icon' => 'fa-minus-circle', 'label' => 'course_mode_exclude', 'desc' => 'course_mode_exclude_desc'],
                    'include' => ['color' => '#7c3aed', 'icon' => 'fa-check-circle', 'label' => 'course_mode_include', 'desc' => 'course_mode_include_desc'],
                ];
                foreach ($modes as $mval => $mdef):
                    $checked = ($cfg['course_mode'] === $mval);
                    $bc = $checked ? $mdef['color'] : '#e5e7eb';
                    $bg = $checked ? $mdef['color'] . '14' : '#fff';
                ?>
                <label class="jedomi-mode-opt" data-color="<?php echo $mdef['color']; ?>"
                       style="border-color:<?php echo $bc; ?>;background:<?php echo $bg; ?>">
                    <input type="radio" name="course_mode" value="<?php echo $mval; ?>"
                           <?php echo $checked ? 'checked' : ''; ?>>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa <?php echo $mdef['icon']; ?>" style="color:<?php echo $mdef['color']; ?>"></i>
                            <strong><?php echo get_string($mdef['label'], 'local_jedomi'); ?></strong>
                        </div>
                        <small><?php echo get_string($mdef['desc'], 'local_jedomi'); ?></small>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Course chip selector -->
            <div id="jedomi-course-selector"
                 <?php echo $cfg['course_mode'] === 'all' ? 'style="display:none"' : ''; ?>>

                <label class="form-label-main">
                    <?php echo get_string('course_availability_courses', 'local_jedomi'); ?>
                </label>
                <div class="mb-3" style="position:relative">
                    <input type="text" id="jedomi-course-search" class="form-control"
                           placeholder="<?php echo get_string('course_search_placeholder', 'local_jedomi'); ?>"
                           autocomplete="off" style="padding-left:2.25rem">
                    <i class="fa fa-search"
                       style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);
                              color:#9ca3af;pointer-events:none"></i>
                    <div id="jedomi-course-dropdown"
                         style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1000;
                                background:#fff;border:1px solid #e5e7eb;border-top:0;
                                border-radius:0 0 .5rem .5rem;max-height:280px;overflow-y:auto;
                                box-shadow:0 4px 12px rgba(0,0,0,.08)"></div>
                </div>
                <p class="form-text"><?php echo get_string('course_search_hint', 'local_jedomi'); ?></p>

                <div id="jedomi-course-chips" class="d-flex flex-wrap gap-2 mb-2">
                    <?php if (empty($selectedcourses)): ?>
                    <span id="jedomi-no-courses" class="text-muted" style="font-size:.875rem;font-style:italic">
                        <?php echo get_string('course_none_selected', 'local_jedomi'); ?>
                    </span>
                    <?php else: ?>
                    <span id="jedomi-no-courses" style="display:none" class="text-muted" style="font-size:.875rem;font-style:italic">
                        <?php echo get_string('course_none_selected', 'local_jedomi'); ?>
                    </span>
                    <?php foreach ($selectedcourses as $sc): ?>
                    <span class="jedomi-chip d-inline-flex align-items-center gap-2" data-id="<?php echo $sc['id']; ?>"
                          style="padding:.375rem .75rem;border-radius:2rem;background:#f1f5f9;
                                 border:1px solid #e2e8f0;font-size:.8125rem;transition:all .15s">
                        <strong><?php echo s($sc['shortname']); ?></strong>
                        <span class="text-muted"><?php echo s($sc['fullname']); ?></span>
                        <span style="color:#94a3b8;font-size:.75rem">(<?php echo s($sc['categoryname']); ?>)</span>
                        <button type="button" class="jedomi-remove-course btn btn-sm p-0"
                                style="line-height:1;color:#ef4444;font-size:1.1rem;background:none;border:none">&times;</button>
                    </span>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <div class="save-row">
        <button type="submit" class="btn btn-primary">
            <i class="fa fa-save mr-1"></i><?php echo get_string('savechanges'); ?>
        </button>
    </div>
</form>
<?php endif; ?>


<?php // ──────────────────────────────── TAB: PRIVACY ──────────────────────── ?>
<?php if ($tab === 'privacy'): ?>
<form method="post" action="<?php echo (new moodle_url($pageurl, ['tab' => 'privacy']))->out(false); ?>">
    <input type="hidden" name="sesskey"     value="<?php echo sesskey(); ?>">
    <input type="hidden" name="jedomi_save" value="privacy">

    <!-- Card: Acceso por rol -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon" style="background:#fee2e2"><i class="fa fa-users" style="color:#dc2626"></i></div>
            <div>
                <h5><?php echo get_string('privacy_access_heading', 'local_jedomi'); ?></h5>
                <small><?php echo get_string('privacy_access_heading_help', 'local_jedomi'); ?></small>
            </div>
        </div>
        <div class="card-body">

            <!-- Guests toggle -->
            <div class="toggle-row">
                <div class="toggle-info">
                    <strong><?php echo get_string('hide_from_guests', 'local_jedomi'); ?></strong>
                    <small><?php echo get_string('hide_from_guests_help', 'local_jedomi'); ?></small>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" name="hide_from_guests"
                           value="1" <?php echo $cfg['hide_from_guests'] ? 'checked' : ''; ?>
                           style="width:2.5rem;height:1.25rem;cursor:pointer">
                </div>
            </div>

            <!-- Per-role toggles -->
            <p class="mt-3 mb-2" style="font-weight:600;font-size:.875rem;color:#374151">
                <?php echo get_string('role_visibility_label', 'local_jedomi'); ?>
            </p>
            <p class="form-text mb-3"><?php echo get_string('role_visibility_help', 'local_jedomi'); ?></p>

            <?php foreach ($all_roles as $role):
                $is_visible = !in_array((int)$role->id, $hidden_role_ids, true);
                $label      = !empty($role->localname) ? $role->localname : $role->name;
                if (empty($label)) $label = $role->shortname;
            ?>
            <div class="toggle-row">
                <!-- Hidden field ensures value=0 when checkbox is unchecked -->
                <input type="hidden" name="role_visible_<?php echo $role->id; ?>" value="0">
                <div class="toggle-info">
                    <strong><?php echo s($label); ?></strong>
                    <small class="text-muted" style="font-family:monospace"><?php echo s($role->shortname); ?></small>
                </div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox"
                           name="role_visible_<?php echo $role->id; ?>"
                           value="1"
                           <?php echo $is_visible ? 'checked' : ''; ?>
                           style="width:2.5rem;height:1.25rem;cursor:pointer">
                </div>
            </div>
            <?php endforeach; ?>

        </div>
    </div>

    <!-- Card: Anonimización -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon" style="background:#e0f2fe"><i class="fa fa-mask" style="color:#0284c7"></i></div>
            <div>
                <h5><?php echo get_string('privacy_anon_heading', 'local_jedomi'); ?></h5>
                <small><?php echo get_string('privacy_anon_heading_help', 'local_jedomi'); ?></small>
            </div>
        </div>
        <div class="card-body">
            <div>
                <label class="form-label-main">
                    <?php echo get_string('anonymization_level', 'local_jedomi'); ?>
                </label>
                <p class="form-text mb-3"><?php echo get_string('anonymization_level_help', 'local_jedomi'); ?></p>

                <?php
                $anonmodes = [
                    'basic'    => ['icon' => 'fa-user',        'color' => '#10b981', 'label' => 'anon_basic',         'desc' => 'anon_basic_desc'],
                    'strict'   => ['icon' => 'fa-user-secret', 'color' => '#7c3aed', 'label' => 'anon_strict',        'desc' => 'anon_strict_desc'],
                    'feedback' => ['icon' => 'fa-comment',     'color' => '#f59e0b', 'label' => 'anon_feedback_only', 'desc' => 'anon_feedback_only_desc'],
                ];
                foreach ($anonmodes as $aval => $adef):
                    $checked = ($cfg['anonymization_level'] === $aval);
                    $bc = $checked ? $adef['color'] : '#e5e7eb';
                    $bg = $checked ? $adef['color'] . '14' : '#fff';
                ?>
                <label class="jedomi-mode-opt anon-opt" data-color="<?php echo $adef['color']; ?>"
                       style="border-color:<?php echo $bc; ?>;background:<?php echo $bg; ?>">
                    <input type="radio" name="anonymization_level" value="<?php echo $aval; ?>"
                           <?php echo $checked ? 'checked' : ''; ?>>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa <?php echo $adef['icon']; ?>" style="color:<?php echo $adef['color']; ?>"></i>
                            <strong><?php echo get_string($adef['label'], 'local_jedomi'); ?></strong>
                        </div>
                        <small><?php echo get_string($adef['desc'], 'local_jedomi'); ?></small>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="save-row">
        <button type="submit" class="btn btn-primary">
            <i class="fa fa-save mr-1"></i><?php echo get_string('savechanges'); ?>
        </button>
    </div>
</form>
<?php endif; ?>


<?php // ─────────────────────────── TAB: INTEGRATIONS ────────────────────────── ?>
<?php if ($tab === 'integrations'): ?>
<?php
    $telegram_cfg = [
        'enabled'         => (int)(get_config('local_jedomi', 'telegram_enabled') ?? 0),
        'bot_token_set'   => !empty(get_config('local_jedomi', 'telegram_bot_token')),
        'bot_username'    => get_config('local_jedomi', 'telegram_bot_username')  ?: '',
        'bot_firstname'   => get_config('local_jedomi', 'telegram_bot_firstname') ?: '',
        'bot_verified'    => (int)(get_config('local_jedomi', 'telegram_bot_verified') ?? 0),
        'webhook_url'     => \local_jedomi\telegram\manager::get_webhook_url(),
        'rate_limit'      => \local_jedomi\telegram\manager::get_rate_limit(),
        'cooldown'        => \local_jedomi\telegram\manager::get_cooldown(),
    ];
    $telegram_hidden_role_ids = array_filter(
        array_map('intval', explode(',', get_config('local_jedomi', 'telegram_hidden_roles') ?: ''))
    );
    $is_configured = $telegram_cfg['enabled'] && $telegram_cfg['bot_token_set'] && $telegram_cfg['bot_verified'];
?>

<!-- ═══ INTEGRATIONS TAB — accordion, extensible ═══════════════════════════ -->
<style>
/* ── Integrations accordion ─────────────────────────────────────── */
.jd-int-list { display:flex; flex-direction:column; gap:.625rem; }

.jd-int-item { border:1.5px solid #e5e7eb; border-radius:.625rem; overflow:hidden;
               transition:border-color .25s, box-shadow .25s; background:#fff; }
.jd-int-item.active { border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.08); }
.jd-int-item.enabled { border-color:#22c55e; }

.jd-int-header { display:flex; align-items:center; justify-content:space-between;
                  padding:.875rem 1.125rem; background:#f9fafb; cursor:default;
                  user-select:none; }
.jd-int-meta   { display:flex; align-items:center; gap:.875rem; }
.jd-int-logo   { width:2.25rem; height:2.25rem; border-radius:.5rem; display:flex;
                  align-items:center; justify-content:center; font-size:1.125rem; flex-shrink:0; }
.jd-int-name   { font-weight:600; font-size:.9375rem; color:#111827; display:block; }
.jd-int-desc   { font-size:.8rem; color:#6b7280; display:block; margin-top:.0625rem; }
.jd-int-badge  { font-size:.75rem; font-weight:600; padding:.25rem .6rem; border-radius:2rem;
                  background:#dcfce7; color:#166534; }
.jd-int-controls { display:flex; align-items:center; gap:.75rem; }
.jd-int-arrow  { color:#9ca3af; font-size:.875rem; transition:transform .2s; }
.jd-int-arrow.open { transform:rotate(180deg); }

/* Body slides in */
.jd-int-body   { border-top:1px solid #e5e7eb; }

/* Sub-sections */
.jd-sub        { border-bottom:1px solid #f1f5f9; }
.jd-sub:last-child { border-bottom:none; }
.jd-sub-hdr    { display:flex; align-items:center; justify-content:space-between;
                  padding:.75rem 1.125rem; cursor:pointer; background:#fff;
                  user-select:none; transition:background .15s; }
.jd-sub-hdr:hover { background:#f8fafc; }
.jd-sub-hdr strong { font-size:.875rem; color:#374151; }
.jd-sub-icon   { width:1.625rem; height:1.625rem; border-radius:.3rem; display:inline-flex;
                  align-items:center; justify-content:center; margin-right:.5rem;
                  font-size:.75rem; }
.jd-sub-arrow  { color:#9ca3af; font-size:.8rem; transition:transform .2s; }
.jd-sub-arrow.open { transform:rotate(90deg); }
.jd-sub-body   { padding:1rem 1.125rem; background:#fafafa; }

/* Save row inside accordion */
.jd-int-save   { padding:.875rem 1.125rem; border-top:1px solid #e5e7eb; background:#f9fafb; }
</style>

<form method="post" action="<?php echo (new moodle_url($pageurl, ['tab' => 'integrations']))->out(false); ?>">
<input type="hidden" name="sesskey"     value="<?php echo sesskey(); ?>">
<input type="hidden" name="jedomi_save" value="integrations">

<div class="jd-int-list">

<?php // ────────────────────────────────────────────────────────────────────────
      // TELEGRAM INTEGRATION ITEM
      // ─────────────────────────────────────────────────────────────────────── ?>
<div class="jd-int-item<?php echo $telegram_cfg['enabled'] ? ' enabled' : ''; ?>"
     id="jd-int-telegram">

    <!-- Header row: always visible -->
    <div class="jd-int-header">
        <div class="jd-int-meta">
            <div class="jd-int-logo" style="background:#e0f2fe">💬</div>
            <div>
                <span class="jd-int-name">
                    <?php echo get_string('telegram_heading', 'local_jedomi'); ?>
                    <?php if ($telegram_cfg['bot_verified'] && $telegram_cfg['bot_username']): ?>
                        <span class="jd-int-badge ml-2">@<?php echo s($telegram_cfg['bot_username']); ?></span>
                    <?php endif; ?>
                </span>
                <span class="jd-int-desc"><?php echo get_string('telegram_heading_help', 'local_jedomi'); ?></span>
            </div>
        </div>
        <div class="jd-int-controls">
            <!-- Toggle: controls visibility of body -->
            <div class="form-check form-switch mb-0"
                 title="<?php echo get_string('telegram_enabled', 'local_jedomi'); ?>">
                <input class="form-check-input" type="checkbox" id="jd-tg-master"
                       name="telegram_enabled" value="1"
                       <?php echo $telegram_cfg['enabled'] ? 'checked' : ''; ?>
                       style="width:2.75rem;height:1.375rem;cursor:pointer"
                       onchange="jdIntToggle('telegram', this.checked)">
            </div>
            <!-- Expand/collapse arrow (only clickable when enabled) -->
            <i class="fa fa-chevron-down jd-int-arrow<?php echo $telegram_cfg['enabled'] ? ' open' : ''; ?>"
               id="jd-arrow-telegram"
               onclick="if(document.getElementById('jd-tg-master').checked) jdIntExpand('telegram')"
               style="cursor:pointer"></i>
        </div>
    </div>

    <!-- Body: shown only when toggle is ON -->
    <div class="jd-int-body" id="jd-body-telegram"
         style="display:<?php echo $telegram_cfg['enabled'] ? 'block' : 'none'; ?>">

        <?php // ── Sub-section 1: Bot Connection (expanded by default) ──────── ?>
        <div class="jd-sub" id="jd-sub-bot">
            <div class="jd-sub-hdr" onclick="jdSubToggle('sub-bot')">
                <div>
                    <span class="jd-sub-icon" style="background:#ede9fe;color:#7c3aed">🔑</span>
                    <strong><?php echo get_string('security_heading', 'local_jedomi'); ?></strong>
                </div>
                <i class="fa fa-chevron-right jd-sub-arrow open" id="jd-sarrow-sub-bot"></i>
            </div>
            <div class="jd-sub-body" id="jd-sbody-sub-bot">

                <!-- Bot token -->
                <div class="mb-3">
                    <label class="form-label-main" for="jd-tg-token">
                        <?php echo get_string('telegram_bot_token', 'local_jedomi'); ?>
                    </label>
                    <div style="position:relative;max-width:480px">
                        <input type="password" id="jd-tg-token" name="telegram_bot_token"
                               class="form-control"
                               placeholder="<?php echo $telegram_cfg['bot_token_set'] ? '●●●●●● (saved — leave blank to keep)' : 'Paste token from @BotFather'; ?>"
                               style="padding-right:2.5rem">
                        <button type="button"
                                onclick="var i=document.getElementById('jd-tg-token');i.type=i.type==='password'?'text':'password'"
                                style="position:absolute;right:.625rem;top:50%;transform:translateY(-50%);
                                       background:none;border:none;color:#9ca3af;cursor:pointer">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                    <p class="form-text"><?php echo get_string('telegram_bot_token_help', 'local_jedomi'); ?></p>

                    <?php if ($telegram_cfg['bot_token_set']): ?>
                    <div class="d-flex align-items-center gap-2 mt-1" style="font-size:.8125rem;padding:.4rem .7rem;border-radius:.375rem;
                         background:<?php echo $telegram_cfg['bot_verified'] ? '#f0fdf4' : '#fff7ed'; ?>;
                         border:1px solid <?php echo $telegram_cfg['bot_verified'] ? '#bbf7d0' : '#fed7aa'; ?>">
                        <i class="fa <?php echo $telegram_cfg['bot_verified'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"
                           style="color:<?php echo $telegram_cfg['bot_verified'] ? '#16a34a' : '#ea580c'; ?>"></i>
                        <?php if ($telegram_cfg['bot_verified']): ?>
                            <span style="color:#166534">
                                <?php echo get_string('telegram_status_connected', 'local_jedomi'); ?> —
                                <strong>@<?php echo s($telegram_cfg['bot_username']); ?></strong>
                                (<?php echo s($telegram_cfg['bot_firstname']); ?>)
                            </span>
                        <?php else: ?>
                            <span style="color:#9a3412">
                                <?php echo get_string('telegram_status_disconnected', 'local_jedomi'); ?> — invalid token, re-paste and save.
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Webhook URL -->
                <div>
                    <label class="form-label-main">
                        <?php echo get_string('telegram_webhook_url', 'local_jedomi'); ?>
                    </label>
                    <div class="input-group" style="max-width:520px">
                        <input type="text" id="jd-webhook-url" class="form-control" readonly
                               value="<?php echo s($telegram_cfg['webhook_url']); ?>">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="navigator.clipboard.writeText(document.getElementById('jd-webhook-url').value);this.innerHTML='<i class=&quot;fa fa-check&quot;></i>';setTimeout(()=>{this.innerHTML='<i class=&quot;fa fa-copy&quot;></i>'},1500)"
                                title="<?php echo get_string('copytoclipboard', 'local_jedomi'); ?>">
                            <i class="fa fa-copy"></i>
                        </button>
                    </div>
                    <p class="form-text"><?php echo get_string('telegram_webhook_url_help', 'local_jedomi'); ?></p>
                </div>

                <!-- Regenerate secret -->
                <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                    <button type="submit" name="telegram_regenerate_secret" value="1"
                            class="btn btn-outline-warning btn-sm"
                            onclick="return confirm('<?php echo get_string('telegram_webhook_secret_help', 'local_jedomi'); ?> Continue?')">
                        <i class="fa fa-refresh mr-1"></i><?php echo get_string('regenerate', 'local_jedomi'); ?>
                    </button>
                    <span class="form-text"><?php echo get_string('telegram_webhook_secret_help', 'local_jedomi'); ?></span>
                </div>

            </div>
        </div>

        <?php // ── Sub-section 2: Rate Limiting (collapsed by default) ─────── ?>
        <div class="jd-sub" id="jd-sub-rate">
            <div class="jd-sub-hdr" onclick="jdSubToggle('sub-rate')">
                <div>
                    <span class="jd-sub-icon" style="background:#fef3c7;color:#d97706">⏱</span>
                    <strong><?php echo get_string('telegram_rate_limit', 'local_jedomi'); ?></strong>
                </div>
                <i class="fa fa-chevron-right jd-sub-arrow" id="jd-sarrow-sub-rate"></i>
            </div>
            <div class="jd-sub-body" id="jd-sbody-sub-rate" style="display:none">
                <div class="d-flex gap-4 flex-wrap">
                    <div>
                        <label class="form-label-main">
                            <?php echo get_string('telegram_rate_limit', 'local_jedomi'); ?>
                        </label>
                        <input type="number" name="telegram_rate_limit" class="form-control" min="0" max="1000"
                               value="<?php echo $telegram_cfg['rate_limit']; ?>" style="width:90px">
                        <p class="form-text"><?php echo get_string('telegram_rate_limit_help', 'local_jedomi'); ?></p>
                    </div>
                    <div>
                        <label class="form-label-main">
                            <?php echo get_string('telegram_cooldown', 'local_jedomi'); ?>
                        </label>
                        <input type="number" name="telegram_cooldown" class="form-control" min="0" max="1440"
                               value="<?php echo $telegram_cfg['cooldown']; ?>" style="width:90px">
                        <p class="form-text"><?php echo get_string('telegram_cooldown_help', 'local_jedomi'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php // ── Sub-section 3: Access Control (collapsed by default) ──────── ?>
        <div class="jd-sub" id="jd-sub-access">
            <div class="jd-sub-hdr" onclick="jdSubToggle('sub-access')">
                <div>
                    <span class="jd-sub-icon" style="background:#dcfce7;color:#16a34a">👥</span>
                    <strong><?php echo get_string('privacy_access_heading', 'local_jedomi'); ?></strong>
                </div>
                <i class="fa fa-chevron-right jd-sub-arrow" id="jd-sarrow-sub-access"></i>
            </div>
            <div class="jd-sub-body" id="jd-sbody-sub-access" style="display:none">
                <p class="form-text mb-3"><?php echo get_string('telegram_role_visibility_help', 'local_jedomi'); ?></p>
                <?php foreach ($all_roles as $role):
                    $is_tg_visible = !in_array((int)$role->id, $telegram_hidden_role_ids, true);
                    $label = !empty($role->localname) ? $role->localname : ($role->name ?: $role->shortname);
                ?>
                <div class="toggle-row">
                    <input type="hidden" name="telegram_role_visible_<?php echo $role->id; ?>" value="0">
                    <div class="toggle-info">
                        <strong><?php echo s($label); ?></strong>
                        <small class="text-muted" style="font-family:monospace"><?php echo s($role->shortname); ?></small>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox"
                               name="telegram_role_visible_<?php echo $role->id; ?>" value="1"
                               <?php echo $is_tg_visible ? 'checked' : ''; ?>
                               style="width:2.5rem;height:1.25rem;cursor:pointer">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php // ── Sub-section 4: Setup Guide (collapsed by default) ───────── ?>
        <div class="jd-sub" id="jd-sub-guide">
            <div class="jd-sub-hdr" onclick="jdSubToggle('sub-guide')">
                <div>
                    <span class="jd-sub-icon" style="background:#eff6ff;color:#0284c7">📋</span>
                    <strong><?php echo get_string('telegram_setup_guide', 'local_jedomi'); ?></strong>
                </div>
                <i class="fa fa-chevron-right jd-sub-arrow" id="jd-sarrow-sub-guide"></i>
            </div>
            <div class="jd-sub-body" id="jd-sbody-sub-guide" style="display:none">

                <p class="mb-3" style="font-size:.8125rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">
                    🔧 <?php echo get_string('telegram_step_admin_title', 'local_jedomi'); ?>
                </p>
                <?php
                $guide = [
                    ['1','#7c3aed','telegram_step1_title','telegram_step1_desc',false],
                    ['2','#0284c7','telegram_step2_title','telegram_step2_desc',false],
                    ['3','#0891b2','telegram_step3_title','telegram_step3_desc',true],
                    ['4','#16a34a','telegram_step4_title','telegram_step4_desc',false],
                ];
                foreach ($guide as [$n,$clr,$tk,$dk,$curl]):
                ?>
                <div class="d-flex align-items-start mb-3" style="gap:.6rem">
                    <div style="min-width:1.625rem;height:1.625rem;border-radius:50%;background:<?php echo $clr; ?>;
                                color:#fff;display:flex;align-items:center;justify-content:center;
                                font-weight:700;font-size:.7rem;flex-shrink:0"><?php echo $n; ?></div>
                    <div>
                        <strong style="font-size:.8375rem;color:#111827;display:block">
                            <?php echo get_string($tk, 'local_jedomi'); ?>
                        </strong>
                        <p style="font-size:.8rem;color:#374151;margin:.125rem 0 0;line-height:1.5">
                            <?php echo get_string($dk, 'local_jedomi'); ?>
                        </p>
                        <?php if ($curl): ?>
                        <div style="background:#1e293b;border-radius:.3rem;padding:.4rem .6rem;margin-top:.375rem;
                                    font-size:.7rem;color:#7dd3fc;word-break:break-all;font-family:monospace">
                            curl "https://api.telegram.org/botTOKEN/setWebhook?url=<?php echo s($telegram_cfg['webhook_url']); ?>"
                            <button type="button"
                                    onclick="navigator.clipboard.writeText('curl &quot;https://api.telegram.org/botTOKEN/setWebhook?url=<?php echo s($telegram_cfg['webhook_url']); ?>&quot;')"
                                    style="background:none;border:none;color:#94a3b8;cursor:pointer;padding:.1rem .25rem">
                                <i class="fa fa-copy" style="font-size:.75rem"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <hr style="margin:.625rem 0;border-color:#e5e7eb">

                <p class="mb-3" style="font-size:.8125rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">
                    👤 <?php echo get_string('telegram_step_user_title', 'local_jedomi'); ?>
                </p>
                <?php
                $uguide = [
                    ['U1','#0369a1','telegram_stepU1_title','telegram_stepU1_desc'],
                    ['U2','#0891b2','telegram_stepU2_title','telegram_stepU2_desc'],
                    ['U3','#7c3aed','telegram_stepU3_title','telegram_stepU3_desc'],
                    ['U4','#16a34a','telegram_stepU4_title','telegram_stepU4_desc'],
                ];
                foreach ($uguide as [$n,$clr,$tk,$dk]):
                ?>
                <div class="d-flex align-items-start mb-2" style="gap:.6rem">
                    <div style="min-width:1.625rem;height:1.625rem;border-radius:.3rem;background:<?php echo $clr; ?>;
                                color:#fff;display:flex;align-items:center;justify-content:center;
                                font-weight:700;font-size:.65rem;flex-shrink:0"><?php echo $n; ?></div>
                    <div>
                        <strong style="font-size:.8rem;color:#111827;display:block">
                            <?php echo get_string($tk, 'local_jedomi'); ?>
                        </strong>
                        <p style="font-size:.775rem;color:#374151;margin:.1rem 0 0;line-height:1.4">
                            <?php echo get_string($dk, 'local_jedomi'); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>

            </div>
        </div>

        <!-- Save row -->
        <div class="jd-int-save">
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-save mr-1"></i><?php echo get_string('savechanges'); ?>
            </button>
        </div>

    </div><!-- /.jd-int-body telegram -->
</div><!-- /.jd-int-item telegram -->


<?php // ────────────────────────────────────────────────────────────────────────
      // FUTURE INTEGRATIONS — WhatsApp, Slack, etc.
      // Same .jd-int-item structure, add below when needed.
      // ─────────────────────────────────────────────────────────────────────── ?>
<!--
<div class="jd-int-item" id="jd-int-whatsapp">
    <div class="jd-int-header"> ... </div>
    <div class="jd-int-body" id="jd-body-whatsapp" style="display:none"> ... </div>
</div>
-->

</div><!-- /.jd-int-list -->
</form>
<?php endif; ?>


<?php // ─────────────────────────────────── TAB: APIS ──────────────────────── ?>
<?php if ($tab === 'apis'):
    $ap_role = optional_param('role', 'admin', PARAM_ALPHA);
    $valid_roles = ['admin', 'manager', 'teacher', 'student'];
    if (!in_array($ap_role, $valid_roles, true)) {
        $ap_role = 'admin';
    }

    // Load allowlist for the active role.
    $ap_raw_config  = get_config('local_jedomi', 'ws_allowlist_' . $ap_role);
    $ap_first_visit = ($ap_role === 'admin' && $ap_raw_config === false);
    $ap_allowlist   = local_jedomi_get_role_allowlist($ap_role);

    // Filters from URL.
    $ap_page      = max(1, optional_param('ap_page', 1, PARAM_INT));
    $ap_search    = optional_param('ap_search', '', PARAM_TEXT);
    $ap_component = optional_param('ap_component', '', PARAM_ALPHAEXT);
    $ap_type      = optional_param('ap_type', 'all', PARAM_ALPHA);
    $ap_perpage   = max(10, min(200, optional_param('ap_perpage', 25, PARAM_INT)));
    $valid_types  = ['all', 'Lectura', 'Escritura'];

    // Build SQL WHERE for search + component (type is post-filtered in PHP).
    $ap_where  = [];
    $ap_params = [];
    if (!empty($ap_search)) {
        $ap_where[] = $DB->sql_like('ef.name', ':ap_search', false, false);
        $ap_params['ap_search'] = '%' . $DB->sql_like_escape($ap_search) . '%';
    }
    if (!empty($ap_component)) {
        $ap_where[] = 'ef.component = :ap_component';
        $ap_params['ap_component'] = $ap_component;
    }
    $ap_where_sql = !empty($ap_where) ? 'WHERE ' . implode(' AND ', $ap_where) : '';

    // Load ALL matching WS (type classification is PHP-only, can't be done in SQL).
    $ap_sql = "SELECT ef.name, '' AS description, ef.component
                 FROM {external_functions} ef
                 $ap_where_sql
             ORDER BY ef.component, ef.name";
    $ap_all_rows = $DB->get_records_sql($ap_sql, $ap_params);

    // Classify each WS and filter by type.
    $ap_filtered = [];
    foreach ($ap_all_rows as $ws) {
        $type = local_jedomi_classify_ws_access($ws->name);
        if ($ap_type !== 'all' && $type !== $ap_type) {
            continue;
        }
        $ap_filtered[] = (object) [
            'name'        => $ws->name,
            'description' => $ws->description ?? '',
            'component'   => $ws->component,
            'type'        => $type,
        ];
    }

    // Paginate the filtered result.
    $ap_total  = count($ap_filtered);
    $ap_pages  = max(1, (int) ceil($ap_total / $ap_perpage));
    $ap_page   = min($ap_page, $ap_pages);
    $ap_offset = ($ap_page - 1) * $ap_perpage;
    $ap_all_ws = array_slice($ap_filtered, $ap_offset, $ap_perpage);

    // Distinct components for filter dropdown.
    $ap_components = $DB->get_fieldset_sql("SELECT DISTINCT component FROM {external_functions} ORDER BY component");

    // Pre-calculate counts for type filter pills.
    $ap_count_read  = 0;
    $ap_count_write = 0;
    foreach ($ap_all_rows as $ws) {
        $t = local_jedomi_classify_ws_access($ws->name);
        if ($t === 'Lectura')  { $ap_count_read++;  }
        if ($t === 'Escritura') { $ap_count_write++; }
    }
?>
<form method="post" action="<?php echo (new moodle_url($pageurl, ['tab' => 'apis', 'role' => $ap_role]))->out(false); ?>">
    <input type="hidden" name="sesskey"     value="<?php echo sesskey(); ?>">
    <input type="hidden" name="jedomi_save" value="apis">
    <input type="hidden" name="api_role"    value="<?php echo $ap_role; ?>">
    <input type="hidden" name="ws_<?php echo $ap_role; ?>_submitted" value="1">

    <div class="card">
        <div class="card-header">
            <div class="card-icon" style="background:#e0f2fe"><i class="fa fa-cloud" style="color:#0284c7"></i></div>
            <div>
                <h5><?php echo get_string('tab_apis_heading', 'local_jedomi'); ?></h5>
                <small><?php echo get_string('tab_apis_heading_help', 'local_jedomi'); ?></small>
            </div>
        </div>
        <div class="card-body">

            <!-- ── Role pills ────────────────────────────────────────────── -->
            <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted" style="font-size:.8125rem;font-weight:600;margin-right:.5rem">
                    <?php echo get_string('filter_type', 'local_jedomi'); ?>
                </span>
                <?php foreach ($valid_roles as $vr):
                    $is_active = ($ap_role === $vr);
                    $pill_url  = new moodle_url($pageurl, [
                        'tab' => 'apis', 'role' => $vr, 'ap_page' => 1,
                        'ap_search' => $ap_search, 'ap_component' => $ap_component, 'ap_type' => $ap_type,
                    ]);
                ?>
                <a href="<?php echo $pill_url->out(false); ?>"
                   class="btn btn-sm <?php echo $is_active ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                   style="border-radius:2rem;padding:.25rem 1rem">
                    <?php echo get_string('api_role_' . $vr, 'local_jedomi'); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- ── Type filter pills ──────────────────────────────────────── -->
            <div class="mb-3 d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted" style="font-size:.8125rem;font-weight:600;margin-right:.5rem">
                    <?php echo get_string('ws_type', 'local_jedomi'); ?>:
                </span>
                <?php foreach ($valid_types as $vt):
                    $is_active = ($ap_type === $vt);
                    $label = $vt === 'all' ? get_string('ws_type_all', 'local_jedomi')
                           : ($vt === 'Lectura' ? get_string('ws_type_read', 'local_jedomi')
                                                : get_string('ws_type_write', 'local_jedomi'));
                    $count = $vt === 'Lectura' ? $ap_count_read : ($vt === 'Escritura' ? $ap_count_write : count($ap_all_rows));
                    $count_id = 'ap-count-' . $vt;
                ?>
                <a href="#" class="btn btn-sm ap-type-pill <?php echo $is_active ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                   data-type="<?php echo $vt; ?>"
                   style="border-radius:2rem;padding:.25rem 1rem">
                    <?php echo $label; ?>
                    <span class="badge badge-light ml-1" id="<?php echo $count_id; ?>"><?php echo $count; ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- ── Filters ────────────────────────────────────────────────── -->
            <div class="mb-3 d-flex align-items-center gap-3 flex-wrap">
                <!-- Search -->
                <div style="position:relative;flex:1;min-width:180px;max-width:320px">
                    <i class="fa fa-search"
                       style="position:absolute;left:.625rem;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none"></i>
                    <input type="text" name="ap_search" class="form-control form-control-sm ap-search-input"
                           placeholder="<?php echo get_string('search_ws_placeholder', 'local_jedomi'); ?>"
                           value="<?php echo s($ap_search); ?>"
                           style="padding-left:1.75rem"
                           autocomplete="off">
                </div>

                <!-- Component dropdown -->
                <select name="ap_component" class="form-control form-control-sm ap-filter-trigger" style="max-width:200px">
                    <option value=""><?php echo get_string('component_all', 'local_jedomi'); ?></option>
                    <?php foreach ($ap_components as $comp): ?>
                    <option value="<?php echo s($comp); ?>" <?php echo $ap_component === $comp ? 'selected' : ''; ?>>
                        <?php echo s($comp); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- Per-page selector -->
                <select name="ap_perpage" class="form-control form-control-sm ap-filter-trigger" style="max-width:100px">
                    <?php foreach ([25, 50, 100, 200] as $pp): ?>
                    <option value="<?php echo $pp; ?>" <?php echo $ap_perpage == $pp ? 'selected' : ''; ?>>
                        <?php echo $pp; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ── Table ──────────────────────────────────────────────────── -->
            <div style="overflow-x:auto">
            <table class="table table-sm table-hover" style="font-size:.875rem">
                <thead>
                    <tr>
                        <th style="width:2rem">
                            <input type="checkbox" id="ap-check-all"
                                   onchange="document.querySelectorAll('.ap-ws-check').forEach(function(c){c.checked=this.checked}.bind(this))">
                        </th>
                        <th><?php echo get_string('ws_name', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('ws_description', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('ws_component', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('ws_type', 'local_jedomi'); ?></th>
                    </tr>
                </thead>
                <tbody id="ap-table-body">
                    <?php if (empty($ap_all_ws)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">
                        <?php echo get_string('ws_none_found', 'local_jedomi'); ?>
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($ap_all_ws as $ap_ws):
                        $is_write   = $ap_ws->type === 'Escritura';
                        $is_checked = in_array($ap_ws->name, $ap_allowlist) || ($ap_first_visit && !$is_write);
                        $row_style  = $is_write ? 'background:#fef2f2;border-left:3px solid #dc2626' : '';
                    ?>
                    <tr style="<?php echo $row_style; ?>">
                        <td>
                            <input type="checkbox" class="ap-ws-check"
                                   name="ws_<?php echo $ap_role; ?>[]"
                                   value="<?php echo s($ap_ws->name); ?>"
                                   <?php echo $is_checked ? 'checked' : ''; ?>>
                        </td>
                        <td><code style="font-size:.8125rem"><?php echo s($ap_ws->name); ?></code></td>
                        <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?php echo s(shorten_text($ap_ws->description, 80)); ?>
                        </td>
                        <td><span class="badge badge-secondary"><?php echo s($ap_ws->component); ?></span></td>
                        <td>
                            <?php if ($is_write): ?>
                            <span class="badge badge-danger"><?php echo get_string('ws_type_write', 'local_jedomi'); ?></span>
                            <?php else: ?>
                            <span class="badge badge-success"><?php echo get_string('ws_type_read', 'local_jedomi'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <!-- ── Pagination ─────────────────────────────────────────────── -->
            <div id="ap-pagination" class="d-flex align-items-center justify-content-between flex-wrap gap-2" style="overflow-x:auto">
                <span class="text-muted" style="font-size:.8125rem">
                    <?php echo get_string('ws_pagination', 'local_jedomi', ['page' => $ap_page, 'pages' => $ap_pages, 'total' => $ap_total]); ?>
                </span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $ap_pages; $p++): ?>
                        <li class="page-item <?php echo $p === $ap_page ? 'active' : ''; ?>">
                            <a class="page-link ap-page-link" href="#" data-page="<?php echo $p; ?>"><?php echo $p; ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>

        </div>
    </div>

    <div class="save-row">
        <button type="submit" class="btn btn-primary">
            <i class="fa fa-save mr-1"></i><?php echo get_string('savechanges'); ?>
        </button>
    </div>
</form>
<?php endif; ?>


</div><!-- /.jedomi-adm -->

<script>
// ── Integrations accordion ─────────────────────────────────────────────────
function jdIntToggle(platform, enabled) {
    var body  = document.getElementById('jd-body-' + platform);
    var arrow = document.getElementById('jd-arrow-' + platform);
    var item  = document.getElementById('jd-int-'  + platform);
    if (enabled) {
        body.style.display = 'block';
        arrow.classList.add('open');
        item.classList.add('active');
        item.classList.remove('enabled');
        // slight delay then add enabled class
        setTimeout(function(){ item.classList.add('enabled'); }, 50);
    } else {
        body.style.display = 'none';
        arrow.classList.remove('open');
        item.classList.remove('active', 'enabled');
    }
}

function jdIntExpand(platform) {
    var body  = document.getElementById('jd-body-' + platform);
    var arrow = document.getElementById('jd-arrow-' + platform);
    if (body.style.display === 'none') {
        body.style.display = 'block';
        arrow.classList.add('open');
    } else {
        body.style.display = 'none';
        arrow.classList.remove('open');
    }
}

function jdSubToggle(subId) {
    var body  = document.getElementById('jd-sbody-' + subId);
    var arrow = document.getElementById('jd-sarrow-' + subId);
    if (!body || !arrow) return;
    if (body.style.display === 'none') {
        body.style.display = 'block';
        arrow.classList.add('open');
    } else {
        body.style.display = 'none';
        arrow.classList.remove('open');
    }
}
</script>

<script>
(function(){
    // ── Mode radio highlight (courses + anon tabs) ──────────────────────────
    function wireRadioHighlight(selector) {
        document.querySelectorAll(selector).forEach(function(radio) {
            radio.addEventListener('change', function() {
                var name = this.name;
                document.querySelectorAll('input[name="' + name + '"]').forEach(function(r) {
                    var opt = r.closest('.jedomi-mode-opt');
                    if (!opt) return;
                    var col = opt.dataset.color;
                    if (r.checked) {
                        opt.style.borderColor = col;
                        opt.style.background  = col + '14';
                    } else {
                        opt.style.borderColor = '#e5e7eb';
                        opt.style.background  = '#fff';
                    }
                });
            });
        });
    }
    wireRadioHighlight('input[name="course_mode"]');
    wireRadioHighlight('input[name="anonymization_level"]');

    // ── Course selector show/hide ───────────────────────────────────────────
    var selectorWrap = document.getElementById('jedomi-course-selector');
    if (selectorWrap) {
        document.querySelectorAll('input[name="course_mode"]').forEach(function(r) {
            r.addEventListener('change', function() {
                selectorWrap.style.display = this.value === 'all' ? 'none' : '';
            });
        });
    }

    // ── Course AJAX chip selector ───────────────────────────────────────────
    var searchInput  = document.getElementById('jedomi-course-search');
    if (!searchInput) return;

    var dropdown     = document.getElementById('jedomi-course-dropdown');
    var chipsWrap    = document.getElementById('jedomi-course-chips');
    var hiddenInput  = document.getElementById('jedomi-course-list-input');
    var noCoursesMsg = document.getElementById('jedomi-no-courses');
    var debounce     = null;

    function getSelectedIds() {
        var v = hiddenInput.value.trim();
        return v ? v.split(',').map(Number).filter(Boolean) : [];
    }

    function syncHidden() {
        var chips = chipsWrap.querySelectorAll('.jedomi-chip');
        var ids   = [];
        chips.forEach(function(c){ ids.push(c.dataset.id); });
        hiddenInput.value = ids.join(',');
        if (noCoursesMsg) noCoursesMsg.style.display = ids.length ? 'none' : '';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function addChip(course) {
        if (noCoursesMsg) noCoursesMsg.style.display = 'none';
        var span = document.createElement('span');
        span.className  = 'jedomi-chip d-inline-flex align-items-center gap-2';
        span.dataset.id = course.id;
        span.style.cssText = 'padding:.375rem .75rem;border-radius:2rem;background:#f1f5f9;'
            + 'border:1px solid #e2e8f0;font-size:.8125rem;transition:all .15s;'
            + 'animation:jedomi-chip-in .2s ease';
        span.innerHTML =
            '<strong>' + esc(course.shortname) + '</strong> '
            + '<span class="text-muted">' + esc(course.fullname) + '</span> '
            + '<span style="color:#94a3b8;font-size:.75rem">(' + esc(course.categoryname) + ')</span> '
            + '<button type="button" class="jedomi-remove-course btn btn-sm p-0" '
            + 'style="line-height:1;color:#ef4444;font-size:1.1rem;background:none;border:none">&times;</button>';
        span.querySelector('.jedomi-remove-course')
            .addEventListener('click', function(){ span.remove(); syncHidden(); });
        chipsWrap.appendChild(span);
        syncHidden();
    }

    chipsWrap.querySelectorAll('.jedomi-remove-course').forEach(function(btn){
        btn.addEventListener('click', function(){
            this.closest('.jedomi-chip').remove();
            syncHidden();
        });
    });

    searchInput.addEventListener('input', function(){
        clearTimeout(debounce);
        var q = this.value.trim();
        if (q.length < 2) { dropdown.style.display = 'none'; dropdown.innerHTML = ''; return; }
        debounce = setTimeout(function(){
            require(['core/ajax'], function(Ajax){
                Ajax.call([{
                    methodname: 'local_jedomi_search_courses',
                    args: { query: q }
                }])[0].then(function(result){
                    var selected = getSelectedIds();
                    var courses  = result.courses.filter(function(c){ return selected.indexOf(c.id) === -1; });
                    if (!courses.length) {
                        dropdown.innerHTML = '<div style="padding:.75rem;color:#94a3b8;font-size:.875rem"><?php echo get_string('course_search_noresults', 'local_jedomi'); ?></div>';
                    } else {
                        dropdown.innerHTML = '';
                        courses.forEach(function(c){
                            var row = document.createElement('div');
                            row.style.cssText = 'padding:.625rem .75rem;cursor:pointer;border-bottom:1px solid #f1f5f9;transition:background .1s';
                            row.innerHTML = '<strong style="font-size:.875rem">' + esc(c.shortname) + '</strong> '
                                + '<span style="color:#64748b;font-size:.8125rem">' + esc(c.fullname) + '</span>'
                                + '<br><span style="color:#94a3b8;font-size:.75rem">' + esc(c.categoryname) + '</span>';
                            row.addEventListener('mouseenter', function(){ this.style.background = '#f8fafc'; });
                            row.addEventListener('mouseleave', function(){ this.style.background = ''; });
                            row.addEventListener('click', function(){
                                addChip(c);
                                dropdown.style.display = 'none';
                                searchInput.value = '';
                            });
                            dropdown.appendChild(row);
                        });
                    }
                    dropdown.style.display = 'block';
                }).catch(function(){ dropdown.style.display = 'none'; });
            });
        }, 300);
    });

    document.addEventListener('click', function(e){
        if (!e.target.closest('#jedomi-course-search') && !e.target.closest('#jedomi-course-dropdown')) {
            dropdown.style.display = 'none';
        }
    });
})();
</script>

<!-- ── WS table AJAX (filters, type pills, pagination) ─────────────────── -->
<script>
(function(){
    var baseUrl  = '<?php echo (new moodle_url('/local/jedomi/ajax/ws_table.php'))->out(false); ?>';
    var role     = '<?php echo $ap_role; ?>';
    var sesskey  = (document.querySelector('input[name="sesskey"]') || {}).value || '';

    function getState() {
        var typePill = document.querySelector('.ap-type-pill.btn-primary');
        var pagLink  = document.querySelector('.ap-page-link.active');
        return {
            role:     role,
            page:     parseInt(pagLink ? pagLink.dataset.page : '1', 10),
            search:   (document.querySelector('input[name="ap_search"]')      || {}).value || '',
            component:(document.querySelector('select[name="ap_component"]')  || {}).value || '',
            type:     typePill ? typePill.dataset.type : 'all',
            perpage:  parseInt((document.querySelector('select[name="ap_perpage"]') || {}).value || '25', 10),
        };
    }

    function qsUrl(s) {
        return baseUrl + '?sesskey=' + encodeURIComponent(sesskey)
            + '&role='     + encodeURIComponent(s.role)
            + '&page='     + s.page
            + '&search='   + encodeURIComponent(s.search)
            + '&component='+ encodeURIComponent(s.component)
            + '&type='     + encodeURIComponent(s.type)
            + '&perpage='  + s.perpage;
    }

    function pushState(s) {
        var p = new URLSearchParams();
        p.set('tab',         'apis');
        p.set('role',        s.role);
        p.set('ap_search',   s.search);
        p.set('ap_component',s.component);
        p.set('ap_type',     s.type);
        p.set('ap_page',     s.page);
        p.set('ap_perpage',  s.perpage);
        history.replaceState(null, '', window.location.pathname + '?' + p.toString());
    }

    function reload(s, cb) {
        if (!s) s = getState();
        var tbody = document.getElementById('ap-table-body');
        if (tbody) tbody.style.opacity = '0.4';
        fetch(qsUrl(s)).then(function(r){
            return r.text().then(function(text){
                try { return JSON.parse(text); }
                catch(parseErr) {
                    console.error('WS table non-JSON response:', text.substring(0, 500));
                    throw new Error('Server returned HTML instead of JSON. Check browser console for details.');
                }
            });
        }).then(function(d){
            if (d.error) {
                throw new Error(d.message || 'Unknown server error');
            }
            if (tbody) tbody.innerHTML = d.rows;
            // Use real page from server (caps to max pages).
            s.page = d.page;
            var pw = document.getElementById('ap-pagination');
            if (pw) {
                var info = '<?php echo get_string('ws_pagination', 'local_jedomi'); ?>'
                    .replace('{$a->page}', d.page).replace('{$a->pages}', d.pages).replace('{$a->total}', d.total);
                pw.innerHTML = '<span class="text-muted" style="font-size:.8125rem">' + info + '</span>'
                    + (d.pagination || '');
            }
            // Update type counts.
            ['all','Lectura','Escritura'].forEach(function(t){
                var el = document.getElementById('ap-count-' + t);
                if (!el) return;
                el.textContent = t === 'all' ? d.count_all : (t === 'Lectura' ? d.count_read : d.count_write);
            });
            // Re-bind select-all checkbox.
            var ca = document.getElementById('ap-check-all');
            if (ca) ca.onchange = function(){ document.querySelectorAll('.ap-ws-check').forEach(function(c){c.checked=ca.checked;}); };
            if (tbody) tbody.style.opacity = '';
            if (cb) cb(s);
        }).catch(function(e){
            console.error('WS table AJAX error', e);
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">'
                    + 'Error: ' + e.message + '</td></tr>';
                tbody.style.opacity = '';
            }
        });
    }

    // ── Search: input event + debounce + prevent Enter form submit ─────────
    var searchDebounce = null;
    var searchInput = document.querySelector('.ap-search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function(){
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(function(){
                var s = getState(); s.page = 1; reload(s, function(st){ pushState(st); });
            }, 350);
        });
        searchInput.addEventListener('keydown', function(e){
            if (e.key === 'Enter') { e.preventDefault(); }
        });
    }

    // ── Component & per-page: change events ───────────────────────────────
    document.querySelectorAll('.ap-filter-trigger').forEach(function(el){
        el.addEventListener('change', function(){
            var s = getState(); s.page = 1; reload(s, function(st){ pushState(st); });
        });
    });

    // ── Type pills ────────────────────────────────────────────────────────
    document.querySelectorAll('.ap-type-pill').forEach(function(el){
        el.addEventListener('click', function(e){
            e.preventDefault();
            document.querySelectorAll('.ap-type-pill').forEach(function(p){
                p.classList.remove('btn-primary');
                p.classList.add('btn-outline-secondary');
            });
            this.classList.remove('btn-outline-secondary');
            this.classList.add('btn-primary');
            var s = getState(); s.type = this.dataset.type; s.page = 1;
            reload(s, function(st){ pushState(st); });
        });
    });

    // ── Pagination links (delegated) ──────────────────────────────────────
    var pw = document.getElementById('ap-pagination');
    if (pw) pw.addEventListener('click', function(e){
        var link = e.target.closest('.ap-page-link');
        if (!link) return;
        e.preventDefault();
        var page = parseInt(link.dataset.page, 10);
        if (!page) return;
        var s = getState(); s.page = page;
        reload(s, function(st){ pushState(st); });
    });
})();
</script>
<?php
echo $OUTPUT->footer();
