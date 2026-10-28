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

// phpcs:disable moodle.Commenting.MissingDocblock.File -- False positive: the sniff re-runs on every
// ...inline PHP open tag of this mixed PHP/HTML page; the file docblock above is present and complete.
// phpcs:disable moodle.Commenting.FileExpectedTags.CopyrightTagMissing -- Same false positive; the
// ...@copyright tag is in the file docblock above.
// phpcs:disable moodle.Commenting.FileExpectedTags.LicenseTagMissing -- Same false positive; the
// ...@license tag is in the file docblock above.

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

// Handle POST ─────────────────────────────────────────────────────────────────.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $savetype = optional_param('jedomi_save', '', PARAM_ALPHA);

    switch ($savetype) {
        case 'general':
            set_config('enabled', optional_param('enabled', 0, PARAM_INT), 'local_jedomi');
            // Only a listed model can be saved.
            $model = required_param('model', PARAM_TEXT);
            if (array_key_exists($model, \local_jedomi\ai\model_settings::get_available_models())) {
                set_config('model', $model, 'local_jedomi');
            }
            set_config('max_tokens', optional_param('max_tokens', 16384, PARAM_INT), 'local_jedomi');
            set_config('docservice_url', optional_param('docservice_url', '', PARAM_TEXT), 'local_jedomi');
            set_config('spt_secret', required_param('spt_secret', PARAM_TEXT), 'local_jedomi');
            set_config('collect_training_data', optional_param('collect_training_data', 0, PARAM_INT), 'local_jedomi');

            redirect(
                new moodle_url($pageurl, ['tab' => 'general']),
                get_string('changessaved'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
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

            redirect(
                new moodle_url($pageurl, ['tab' => 'courses']),
                get_string('changessaved'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'privacy':
            set_config('hide_from_guests', optional_param('hide_from_guests', 0, PARAM_INT), 'local_jedomi');
            set_config('anonymization_level', required_param('anonymization_level', PARAM_ALPHA), 'local_jedomi');

            // Save hidden roles: collect all role IDs that were toggled OFF.
            $allr   = $DB->get_records('role', null, 'sortorder ASC', 'id');
            $hidden  = [];
            foreach ($allr as $r) {
                $visible = optional_param('role_visible_' . $r->id, 0, PARAM_INT);
                if (!$visible) {
                    $hidden[] = (int) $r->id;
                }
            }
            set_config('hidden_roles', implode(',', $hidden), 'local_jedomi');

            redirect(
                new moodle_url($pageurl, ['tab' => 'privacy']),
                get_string('changessaved'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'integrations':
            // Load roles here — $allroles is defined later for the render phase.
            $allrolespost = $DB->get_records('role', null, 'sortorder ASC', 'id');

            $enabled = optional_param('telegram_enabled', 0, PARAM_INT);
            set_config('telegram_enabled', $enabled, 'local_jedomi');

            $token = optional_param('telegram_bot_token', '', PARAM_TEXT);
            if ($token !== '') {
                // Verify token FIRST via getMe before saving anything.
                $client = new \local_jedomi\telegram\api_client($token);
                $botinfo = $client->get_me();
                if ($botinfo) {
                    // Token validated — save everything.
                    \local_jedomi\telegram\manager::set_bot_token($token);
                    set_config('telegram_bot_username', $botinfo['username'], 'local_jedomi');
                    set_config('telegram_bot_firstname', $botinfo['first_name'], 'local_jedomi');
                    set_config('telegram_bot_verified', 1, 'local_jedomi');

                    // Auto-register webhook — delete old first, then set new.
                    $client->delete_webhook();
                    $webhookurl = \local_jedomi\telegram\manager::get_webhook_url();
                    $webhookok = $client->set_webhook($webhookurl);
                    if ($webhookok) {
                        \local_jedomi\telegram\manager::clear_last_webhook_error();
                    } else {
                        \local_jedomi\telegram\manager::set_last_webhook_error(
                            'setWebhook failed after token save — check the URL is HTTPS and reachable'
                        );
                    }
                } else {
                    // Token invalid — show error, do NOT save.
                    set_config('telegram_bot_verified', 0, 'local_jedomi');
                    redirect(
                        new moodle_url($pageurl, ['tab' => 'integrations']),
                        get_string('telegram_msg_token_invalid', 'local_jedomi'),
                        null,
                        \core\output\notification::NOTIFY_ERROR
                    );
                }
            }

            // Regenerate secret if requested.
            // manager::regenerate_webhook_secret() handles both local rotation
            // AND Telegram API update automatically.
            if (optional_param('telegram_regenerate_secret', 0, PARAM_INT)) {
                \local_jedomi\telegram\manager::regenerate_webhook_secret();
            }

            set_config('telegram_rate_limit', optional_param('telegram_rate_limit', 30, PARAM_INT), 'local_jedomi');
            set_config('telegram_cooldown', optional_param('telegram_cooldown', 5, PARAM_INT), 'local_jedomi');
            set_config('telegram_max_turns', optional_param('telegram_max_turns', 10, PARAM_INT), 'local_jedomi');
            set_config('telegram_gateway_timeout', optional_param('telegram_gateway_timeout', 120, PARAM_INT), 'local_jedomi');

            // Save per-role visibility for Telegram (reuse same hidden_roles logic with prefix).
            $telegramhidden = [];
            foreach ($allrolespost as $r) {
                $visible = optional_param('telegram_role_visible_' . $r->id, 0, PARAM_INT);
                if (!$visible) {
                    $telegramhidden[] = (int) $r->id;
                }
            }
            set_config('telegram_hidden_roles', implode(',', $telegramhidden), 'local_jedomi');

            redirect(
                new moodle_url($pageurl, ['tab' => 'integrations']),
                get_string('changessaved'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'apis':
            $aproles = ['admin', 'manager', 'teacher', 'student'];
            foreach ($aproles as $ar) {
                if (optional_param('ws_' . $ar . '_submitted', 0, PARAM_INT)) {
                    $checked = optional_param_array('ws_' . $ar, [], PARAM_ALPHANUMEXT);
                    set_config('ws_allowlist_' . $ar, json_encode(array_values($checked)), 'local_jedomi');
                }
            }

            redirect(
                new moodle_url($pageurl, ['tab' => 'apis', 'role' => optional_param('api_role', 'admin', PARAM_ALPHA)]),
                get_string('changessaved'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
    }
}

// Load values ─────────────────────────────────────────────────────────────────.
$cfg = [
    // General.
    'enabled'               => (int)(get_config('local_jedomi', 'enabled') ?? 1),
    'model'                 => \local_jedomi\ai\model_settings::get_model(),
    'max_tokens'            => \local_jedomi\ai\model_settings::get_max_tokens(),
    'docservice_url'        => get_config('local_jedomi', 'docservice_url') ?: '',
    'spt_secret'            => get_config('local_jedomi', 'spt_secret') ?: '',
    'collect_training_data' => (function () {
        $v = get_config('local_jedomi', 'collect_training_data');
        return ($v === false) ? 1 : (int)$v;  // Default ON.
    })(),
    // Courses.
    'course_mode'           => get_config('local_jedomi', 'course_mode') ?: 'all',
    'course_list'           => get_config('local_jedomi', 'course_list') ?: '',
    // Privacy.
    'hide_from_guests'      => (int)(get_config('local_jedomi', 'hide_from_guests') ?? 1),
    'anonymization_level'   => get_config('local_jedomi', 'anonymization_level') ?: 'basic',
    'hidden_roles'          => get_config('local_jedomi', 'hidden_roles') ?: '',
];

// Load all roles for the role-visibility toggles.
$allroles = $DB->get_records('role', null, 'sortorder ASC', 'id,shortname,name,description');
foreach ($allroles as $role) {
    $role->localname = role_get_name($role, context_system::instance());
}
$hiddenroleids = array_filter(array_map('intval', explode(',', $cfg['hidden_roles'])));

// Pre-load course chips.
$courseids      = array_filter(array_map('intval', explode(',', $cfg['course_list'])));
$selectedcourses = [];
if (!empty($courseids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
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

// Render ──────────────────────────────────────────────────────────────────────.
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
// Tab navigation ────────────────────────────────────────────────────────────.
$tabs = [
    'general'      => ['icon' => 'fa-cog', 'label' => get_string('tab_general', 'local_jedomi')],
    'courses'      => ['icon' => 'fa-graduation-cap', 'label' => get_string('course_availability_heading', 'local_jedomi')],
    'privacy'      => ['icon' => 'fa-shield', 'label' => get_string('tab_privacy', 'local_jedomi')],
    'integrations' => ['icon' => 'fa-plug', 'label' => get_string('tab_integrations', 'local_jedomi')],
    'apis'         => ['icon' => 'fa-cloud', 'label' => get_string('tab_apis', 'local_jedomi')],
    'agents'       => ['icon' => 'fa-robot', 'label' => get_string('tab_agents', 'local_jedomi')],
    'profiler'     => ['icon' => 'fa-chart-bar', 'label' => get_string('tab_profiler', 'local_jedomi')],
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

<?php // TAB: GENERAL ───────────────────────────────────────────────────────. ?>
<?php if ($tab === 'general') : ?>
<form method="post" action="<?php echo (new moodle_url($pageurl, ['tab' => 'general']))->out(false); ?>">
    <input type="hidden" name="sesskey"     value="<?php echo sesskey(); ?>">
    <input type="hidden" name="jedomi_save" value="general">

    <!-- Card: AI service -->
    <div class="card">
        <div class="card-header">
            <div class="card-icon" style="background:#ede9fe"><i class="fa fa-plug" style="color:#7c3aed"></i></div>
            <div>
                <h5><?php echo get_string('ai_heading', 'local_jedomi'); ?></h5>
                <small><?php echo get_string('ai_heading_help', 'local_jedomi'); ?></small>
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

            <!-- Model -->
            <div class="mt-3">
                <label class="form-label-main" for="jedomi-model">
                    <?php echo get_string('model', 'local_jedomi'); ?>
                </label>
                <select id="jedomi-model" name="model" class="form-select custom-select" style="max-width:480px">
                    <?php
                    // The model setting is always listed: get_model() falls back to the default one.
                    $modeloptions = \local_jedomi\ai\model_settings::get_available_models();
                    foreach ($modeloptions as $modelid => $modellabel) {
                        $selected = ($modelid === $cfg['model']) ? ' selected' : '';
                        echo '<option value="' . s($modelid) . '"' . $selected . '>' . s($modellabel) . '</option>';
                    }
                    ?>
                </select>
                <p class="form-text"><?php echo get_string('model_help', 'local_jedomi'); ?></p>
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

            <!-- Max Tokens -->
            <div class="mt-3">
                <label class="form-label-main" for="jedomi-max-tokens">
                    <?php echo get_string('max_tokens', 'local_jedomi'); ?>
                </label>
                <input type="number" id="jedomi-max-tokens" name="max_tokens"
                       class="form-control" style="max-width:140px"
                       value="<?php echo (int)($cfg['max_tokens'] ?? 16384); ?>"
                       min="256" max="128000" step="1">
                <small class="form-text text-muted">
                    <?php echo get_string('max_tokens_help', 'local_jedomi'); ?>
                </small>
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
                    <button type="button"
                            onclick="var i=document.getElementById('jedomi-spt');i.type=i.type==='password'?'text':'password'"
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


<?php // TAB: COURSES ──────────────────────────────────────────────────────────────. ?>
<?php if ($tab === 'courses') : ?>
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
                    'all'     => ['color' => '#10b981', 'icon' => 'fa-globe',
                                  'label' => 'course_mode_all', 'desc' => 'course_mode_all_desc'],
                    'exclude' => ['color' => '#f59e0b', 'icon' => 'fa-minus-circle',
                                  'label' => 'course_mode_exclude', 'desc' => 'course_mode_exclude_desc'],
                    'include' => ['color' => '#7c3aed', 'icon' => 'fa-check-circle',
                                  'label' => 'course_mode_include', 'desc' => 'course_mode_include_desc'],
                ];
                foreach ($modes as $mval => $mdef) :
                    $checked = ($cfg['course_mode'] === $mval);
                    $bc = $checked ? $mdef['color'] : '#e5e7eb';
                    $bg = $checked ? $mdef['color'] . '14' : '#fff';
                ?>
                <label class="jedomi-mode-opt" data-color="<?php echo $mdef['color']; ?>"
                       style="border-color:<?php echo $bc; ?>;background:<?php echo $bg; ?>">
                    <input type="radio" name="course_mode" value="<?php echo $mval; ?>"
                           <?php echo $checked ? 'checked' : ''; ?>>
                    <div>
                        <div class="d-flex align-items-center">
                            <i class="fa <?php echo $mdef['icon']; ?> mr-2" style="color:<?php echo $mdef['color']; ?>"></i>
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
                    <?php if (empty($selectedcourses)) : ?>
                    <span id="jedomi-no-courses" class="text-muted" style="font-size:.875rem;font-style:italic">
                        <?php echo get_string('course_none_selected', 'local_jedomi'); ?>
                    </span>
                    <?php else : ?>
                    <span id="jedomi-no-courses" style="display:none" class="text-muted"
                          style="font-size:.875rem;font-style:italic">
                        <?php echo get_string('course_none_selected', 'local_jedomi'); ?>
                    </span>
                        <?php foreach ($selectedcourses as $sc) : ?>
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


<?php // TAB: PRIVACY ──────────────────────────────────────────────────────────────. ?>
<?php if ($tab === 'privacy') : ?>
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

            <?php foreach ($allroles as $role) :
                $isvisible = !in_array((int)$role->id, $hiddenroleids, true);
                $label      = !empty($role->localname) ? $role->localname : $role->name;
                if (empty($label)) {
                    $label = $role->shortname;
                }
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
                           <?php echo $isvisible ? 'checked' : ''; ?>
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
                    'basic'    => ['icon' => 'fa-user', 'color' => '#10b981',
                                   'label' => 'anon_basic', 'desc' => 'anon_basic_desc'],
                    'strict'   => ['icon' => 'fa-user-secret', 'color' => '#7c3aed',
                                   'label' => 'anon_strict', 'desc' => 'anon_strict_desc'],
                    'feedback' => ['icon' => 'fa-comment', 'color' => '#f59e0b',
                                   'label' => 'anon_feedback_only', 'desc' => 'anon_feedback_only_desc'],
                ];
                foreach ($anonmodes as $aval => $adef) :
                    $checked = ($cfg['anonymization_level'] === $aval);
                    $bc = $checked ? $adef['color'] : '#e5e7eb';
                    $bg = $checked ? $adef['color'] . '14' : '#fff';
                ?>
                <label class="jedomi-mode-opt anon-opt" data-color="<?php echo $adef['color']; ?>"
                       style="border-color:<?php echo $bc; ?>;background:<?php echo $bg; ?>">
                    <input type="radio" name="anonymization_level" value="<?php echo $aval; ?>"
                           <?php echo $checked ? 'checked' : ''; ?>>
                    <div>
                        <div class="d-flex align-items-center">
                            <i class="fa <?php echo $adef['icon']; ?> mr-2" style="color:<?php echo $adef['color']; ?>"></i>
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


<?php // TAB: INTEGRATIONS ──────────────────────────────────────────────────────────────. ?>
<?php if ($tab === 'integrations') : ?>
    <?php
    $telegramcfg = [
        'enabled'         => (int)(get_config('local_jedomi', 'telegram_enabled') ?? 0),
        'bot_token_set'   => !empty(get_config('local_jedomi', 'telegram_bot_token')),
        'bot_username'    => get_config('local_jedomi', 'telegram_bot_username') ?: '',
        'bot_firstname'   => get_config('local_jedomi', 'telegram_bot_firstname') ?: '',
        'bot_verified'    => (int)(get_config('local_jedomi', 'telegram_bot_verified') ?? 0),
        'webhook_error'   => (int)(get_config('local_jedomi', 'telegram_webhook_error') ?? 0),
        'webhook_url'     => \local_jedomi\telegram\manager::get_webhook_url(),
        'rate_limit'      => \local_jedomi\telegram\manager::get_rate_limit(),
        'cooldown'        => \local_jedomi\telegram\manager::get_cooldown(),
        'max_turns'       => get_config('local_jedomi', 'telegram_max_turns') === false
            ? 10 : (int)get_config('local_jedomi', 'telegram_max_turns'),
        'gateway_timeout' => (int)(get_config('local_jedomi', 'telegram_gateway_timeout') ?: 120),
    ];
    $telegramhiddenroleids = array_filter(
        array_map('intval', explode(',', get_config('local_jedomi', 'telegram_hidden_roles') ?: ''))
    );
    $isconfigured = $telegramcfg['enabled'] && $telegramcfg['bot_token_set'] && $telegramcfg['bot_verified'];
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

    <?php // TELEGRAM INTEGRATION ITEM ──────────────────────────────────────────. ?>
<div class="jd-int-item<?php echo $telegramcfg['enabled'] ? ' enabled' : ''; ?>"
     id="jd-int-telegram">

    <!-- Header row: always visible -->
    <div class="jd-int-header">
        <div class="jd-int-meta">
            <div class="jd-int-logo" style="background:#e0f2fe">💬</div>
            <div>
                <span class="jd-int-name">
                    <?php echo get_string('telegram_heading', 'local_jedomi'); ?>
                    <?php if ($telegramcfg['bot_verified'] && $telegramcfg['bot_username']) : ?>
                        <span class="jd-int-badge ml-2">@<?php echo s($telegramcfg['bot_username']); ?></span>
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
                       <?php echo $telegramcfg['enabled'] ? 'checked' : ''; ?>
                       style="width:2.75rem;height:1.375rem;cursor:pointer"
                       onchange="jdIntToggle('telegram', this.checked)">
            </div>
            <!-- Expand/collapse arrow (only clickable when enabled) -->
            <i class="fa fa-chevron-down jd-int-arrow<?php echo $telegramcfg['enabled'] ? ' open' : ''; ?>"
               id="jd-arrow-telegram"
               onclick="if(document.getElementById('jd-tg-master').checked) jdIntExpand('telegram')"
               style="cursor:pointer"></i>
        </div>
    </div>

    <!-- Body: shown only when toggle is ON -->
    <div class="jd-int-body" id="jd-body-telegram"
         style="display:<?php echo $telegramcfg['enabled'] ? 'block' : 'none'; ?>">

        <?php // Sub-section 1: Bot Connection (expanded by default) ────────. ?>
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
                               placeholder="<?php echo $telegramcfg['bot_token_set']
                                   ? '●●●●●● (saved — leave blank to keep)' : 'Paste token from @BotFather'; ?>"
                               style="padding-right:2.5rem">
                        <button type="button"
                                onclick="var i=document.getElementById('jd-tg-token');i.type=i.type==='password'?'text':'password'"
                                style="position:absolute;right:.625rem;top:50%;transform:translateY(-50%);
                                       background:none;border:none;color:#9ca3af;cursor:pointer">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                    <p class="form-text"><?php echo get_string('telegram_bot_token_help', 'local_jedomi'); ?></p>

                    <?php if ($telegramcfg['bot_token_set']) : ?>
                    <div class="d-flex align-items-center gap-2 mt-1"
                         style="font-size:.8125rem;padding:.4rem .7rem;border-radius:.375rem;
                         background:<?php echo $telegramcfg['bot_verified'] ? '#f0fdf4' : '#fff7ed'; ?>;
                         border:1px solid <?php echo $telegramcfg['bot_verified'] ? '#bbf7d0' : '#fed7aa'; ?>">
                        <i class="fa <?php echo $telegramcfg['bot_verified'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"
                           style="color:<?php echo $telegramcfg['bot_verified'] ? '#16a34a' : '#ea580c'; ?>"></i>
                        <?php if ($telegramcfg['bot_verified']) : ?>
                            <span style="color:#166534">
                                <?php echo get_string('telegram_status_connected', 'local_jedomi'); ?> —
                                <strong>@<?php echo s($telegramcfg['bot_username']); ?></strong>
                                (<?php echo s($telegramcfg['bot_firstname']); ?>)
                            </span>
                        <?php else : ?>
                            <span style="color:#9a3412">
                                <?php echo get_string('telegram_status_disconnected', 'local_jedomi'); ?> —
                                invalid token, re-paste and save.
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Webhook URL -->
                <?php
                $copywebhookjs = "navigator.clipboard.writeText(document.getElementById('jd-webhook-url').value);"
                    . "this.innerHTML='<i class=&quot;fa fa-check&quot;></i>';"
                    . "setTimeout(()=>{this.innerHTML='<i class=&quot;fa fa-copy&quot;></i>'},1500)";
                ?>
                <div>
                    <label class="form-label-main">
                        <?php echo get_string('telegram_webhook_url', 'local_jedomi'); ?>
                    </label>
                    <div class="input-group" style="max-width:520px">
                        <input type="text" id="jd-webhook-url" class="form-control" readonly
                               value="<?php echo s($telegramcfg['webhook_url']); ?>">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="<?php echo $copywebhookjs; ?>"
                                title="<?php echo get_string('copytoclipboard', 'local_jedomi'); ?>">
                            <i class="fa fa-copy"></i>
                        </button>
                    </div>
                    <p class="form-text"><?php echo get_string('telegram_webhook_url_help', 'local_jedomi'); ?></p>
                </div>

                <!-- Regenerate secret -->
                <?php $confirmsecret = str_replace("'", "\'", get_string('telegram_webhook_secret_help', 'local_jedomi')); ?>
                <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                    <button type="submit" name="telegram_regenerate_secret" value="1"
                            class="btn btn-outline-warning btn-sm"
                            onclick="return confirm('<?php echo $confirmsecret; ?> Continue?')">
                        <i class="fa fa-refresh mr-1"></i><?php echo get_string('regenerate', 'local_jedomi'); ?>
                    </button>
                    <span class="form-text"><?php echo get_string('telegram_webhook_secret_help', 'local_jedomi'); ?></span>
                </div>

                <?php // Webhook status block ──────────────────────────────. ?>
                <?php if ($telegramcfg['bot_token_set']) : ?>
                <div class="mt-3" style="padding:.75rem 1rem;border-radius:.5rem;border:1px solid #e5e7eb;background:#fafafa">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <strong style="font-size:.875rem;color:#374151">
                            <i class="fa fa-plug mr-1" style="color:#7c3aed"></i>
                            <?php echo get_string('telegram_webhook_status', 'local_jedomi'); ?>
                        </strong>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                                onclick="jdCheckWebhook()"
                                id="jd-webhook-check-btn"
                                style="font-size:.75rem">
                            <i class="fa fa-sync mr-1"></i><?php echo get_string('check_webhook', 'local_jedomi'); ?>
                        </button>
                    </div>

                    <!-- Cached status (loaded from config) -->
                    <div id="jd-webhook-status">
                        <?php
                        $lasterror = \local_jedomi\telegram\manager::get_last_webhook_error();
                        $errortime = \local_jedomi\telegram\manager::get_last_webhook_error_time();
                        ?>
                        <?php if (!empty($lasterror)) : ?>
                        <div class="d-flex align-items-start gap-2" style="font-size:.8125rem;color:#9a3412">
                            <i class="fa fa-exclamation-triangle" style="color:#ea580c;flex-shrink:0;margin-top:2px"></i>
                            <div>
                                <strong style="display:block"><?php echo get_string('webhook_error', 'local_jedomi'); ?></strong>
                                <span><?php echo s($lasterror); ?></span>
                                <?php if ($errortime) : ?>
                                <span class="text-muted" style="display:block;font-size:.75rem;margin-top:2px">
                                    <?php echo userdate($errortime, get_string('strftimedatetime', 'langconfig')); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php // phpcs:ignore Squiz.ControlStructures.ElseIfDeclaration.NotAllowed -- alt syntax needs elseif. ?>
                        <?php elseif ($errortime > 0) : ?>
                        <div style="font-size:.8125rem;color:#166534">
                            <i class="fa fa-check-circle" style="color:#16a34a"></i>
                            <?php echo get_string('webhook_ok', 'local_jedomi'); ?>
                        </div>
                        <?php else : ?>
                        <div style="font-size:.8125rem;color:#6b7280">
                            <i class="fa fa-minus-circle" style="color:#9ca3af"></i>
                            <?php echo get_string('webhook_not_checked', 'local_jedomi'); ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Live check result (populated by JS) -->
                    <div id="jd-webhook-live"
                         style="display:none;margin-top:.5rem;padding-top:.5rem;border-top:1px dashed #d1d5db"></div>

                    <!-- Drop pending updates button -->
                    <div style="margin-top:.5rem;padding-top:.5rem;border-top:1px dashed #d1d5db">
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="jdDropPending()"
                                id="jd-drop-pending-btn"
                                style="font-size:.75rem">
                            <i class="fa fa-trash-alt mr-1"></i><?php echo get_string('drop_pending_updates', 'local_jedomi'); ?>
                        </button>
                        <span class="form-text"><?php echo get_string('drop_pending_updates_help', 'local_jedomi'); ?></span>
                        <div id="jd-drop-pending-result" style="display:none;font-size:.8125rem;margin-top:.375rem"></div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <?php // Sub-section 2: Rate Limiting (collapsed by default) ────────. ?>
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
                               value="<?php echo $telegramcfg['rate_limit']; ?>" style="width:90px">
                        <p class="form-text"><?php echo get_string('telegram_rate_limit_help', 'local_jedomi'); ?></p>
                    </div>
                    <div>
                        <label class="form-label-main">
                            <?php echo get_string('telegram_cooldown', 'local_jedomi'); ?>
                        </label>
                        <input type="number" name="telegram_cooldown" class="form-control" min="0" max="1440"
                               value="<?php echo $telegramcfg['cooldown']; ?>" style="width:90px">
                        <p class="form-text"><?php echo get_string('telegram_cooldown_help', 'local_jedomi'); ?></p>
                    </div>
                    <div>
                        <label class="form-label-main">
                            <?php echo get_string('telegram_max_turns', 'local_jedomi'); ?>
                        </label>
                        <input type="number" name="telegram_max_turns" class="form-control" min="1" max="100"
                               value="<?php echo $telegramcfg['max_turns']; ?>" style="width:90px">
                        <p class="form-text"><?php echo get_string('telegram_max_turns_help', 'local_jedomi'); ?></p>
                    </div>
                    <div>
                        <label class="form-label-main">
                            <?php echo get_string('telegram_gateway_timeout', 'local_jedomi'); ?>
                        </label>
                        <input type="number" name="telegram_gateway_timeout" class="form-control" min="5" max="300"
                               value="<?php echo $telegramcfg['gateway_timeout']; ?>" style="width:90px">
                        <p class="form-text"><?php echo get_string('telegram_gateway_timeout_help', 'local_jedomi'); ?></p>
                    </div>
                </div>

        <?php // Sub-section 3: Access Control (collapsed by default) ────────. ?>
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
                <?php foreach ($allroles as $role) :
                    $istgvisible = !in_array((int)$role->id, $telegramhiddenroleids, true);
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
                               <?php echo $istgvisible ? 'checked' : ''; ?>
                               style="width:2.5rem;height:1.25rem;cursor:pointer">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php // Sub-section 4: Setup Guide (collapsed by default) ────────. ?>
        <div class="jd-sub" id="jd-sub-guide">
            <div class="jd-sub-hdr" onclick="jdSubToggle('sub-guide')">
                <div>
                    <span class="jd-sub-icon" style="background:#eff6ff;color:#0284c7">📋</span>
                    <strong><?php echo get_string('telegram_setup_guide', 'local_jedomi'); ?></strong>
                </div>
                <i class="fa fa-chevron-right jd-sub-arrow" id="jd-sarrow-sub-guide"></i>
            </div>
            <div class="jd-sub-body" id="jd-sbody-sub-guide" style="display:none">

                <p class="mb-3"
                   style="font-size:.8125rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">
                    🔧 <?php echo get_string('telegram_step_admin_title', 'local_jedomi'); ?>
                </p>
                <?php
                $guide = [
                    ['1', '#7c3aed', 'telegram_step1_title', 'telegram_step1_desc', false],
                    ['2', '#0284c7', 'telegram_step2_title', 'telegram_step2_desc', false],
                    ['3', '#0891b2', 'telegram_step3_title', 'telegram_step3_desc', true],
                    ['4', '#16a34a', 'telegram_step4_title', 'telegram_step4_desc', false],
                ];
                foreach ($guide as [$n, $clr, $tk, $dk, $curl]) :
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
                        <?php if ($isconfigured) : ?>
                        <div class="d-flex align-items-center gap-2 mt-2"
                             style="font-size:.8125rem;padding:.5rem .75rem;border-radius:.375rem;
                                    background:#f0fdf4;border:1px solid #bbf7d0;color:#166534">
                            <i class="fa fa-check-circle" style="color:#16a34a"></i>
                            <span><strong>Webhook configurado automáticamente</strong> — al guardar el token,
                                el sistema registró el webhook automáticamente.</span>
                        </div>
                        <?php // phpcs:ignore Squiz.ControlStructures.ElseIfDeclaration.NotAllowed -- alt syntax needs elseif. ?>
                        <?php elseif ($telegramcfg['bot_token_set']) : ?>
                        <div class="d-flex align-items-center gap-2 mt-2"
                             style="font-size:.8125rem;padding:.5rem .75rem;border-radius:.375rem;
                                    background:#fff7ed;border:1px solid #fed7aa;color:#9a3412">
                            <i class="fa fa-exclamation-triangle" style="color:#ea580c"></i>
                            <span><strong>⚠️ Webhook no registrado</strong> — intente guardar el token nuevamente.</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <hr style="margin:.625rem 0;border-color:#e5e7eb">

                <p class="mb-3"
                   style="font-size:.8125rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">
                    👤 <?php echo get_string('telegram_step_user_title', 'local_jedomi'); ?>
                </p>
                <?php
                $uguide = [
                    ['U1', '#0369a1', 'telegram_stepU1_title', 'telegram_stepU1_desc'],
                    ['U2', '#0891b2', 'telegram_stepU2_title', 'telegram_stepU2_desc'],
                    ['U3', '#7c3aed', 'telegram_stepU3_title', 'telegram_stepU3_desc'],
                    ['U4', '#16a34a', 'telegram_stepU4_title', 'telegram_stepU4_desc'],
                ];
                foreach ($uguide as [$n, $clr, $tk, $dk]) :
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


    <?php // FUTURE INTEGRATIONS — WhatsApp, Slack, etc.
          // Same .jd-int-item structure, add below when needed. ───────────────. ?>
<!--
<div class="jd-int-item" id="jd-int-whatsapp">
    <div class="jd-int-header"> ... </div>
    <div class="jd-int-body" id="jd-body-whatsapp" style="display:none"> ... </div>
</div>
-->

</div><!-- /.jd-int-list -->
</form>

    <?php if ($telegramcfg['bot_token_set'] && $telegramcfg['webhook_error']) :
        $lastwebhookerror = \local_jedomi\telegram\manager::get_last_webhook_error();
        $lastwebhooktime  = \local_jedomi\telegram\manager::get_last_webhook_error_time();
        ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mt-3" style="border-radius:.5rem">
    <i class="fa fa-exclamation-triangle fa-lg" style="flex-shrink:0;color:#d97706;margin-top:2px"></i>
    <div>
        <strong>⚠️ <?php echo get_string('webhook_error_alert_title', 'local_jedomi'); ?></strong><br>
        <small>
            <?php echo get_string('webhook_error_alert_desc', 'local_jedomi'); ?>
            <?php if (!empty($lastwebhookerror)) : ?>
            <br><br>
            <strong><?php echo get_string('webhook_error_detail', 'local_jedomi'); ?></strong>
            <code style="display:block;margin-top:4px;padding:6px 8px;background:#fff3cd;border-radius:4px;font-size:.8125rem">
                <?php echo s($lastwebhookerror); ?>
            </code>
                <?php if ($lastwebhooktime) : ?>
                <span class="text-muted" style="display:block;font-size:.75rem;margin-top:2px">
                    <?php echo userdate($lastwebhooktime, get_string('strftimedatetime', 'langconfig')); ?>
                </span>
                <?php endif; ?>
            <?php endif; ?>
        </small>
    </div>
</div>
    <?php endif; ?>

<?php endif; ?>


<?php // TAB: APIS ──────────────────────────────────────────────────────────────. ?>
<?php if ($tab === 'apis') :
    $aprole = optional_param('role', 'admin', PARAM_ALPHA);
    $validroles = ['admin', 'manager', 'teacher', 'student'];
    if (!in_array($aprole, $validroles, true)) {
        $aprole = 'admin';
    }

    // Load allowlist for the active role.
    $aprawconfig  = get_config('local_jedomi', 'ws_allowlist_' . $aprole);
    $apfirstvisit = ($aprole === 'admin' && $aprawconfig === false);
    $apallowlist   = local_jedomi_get_role_allowlist($aprole);

    // Filters from URL.
    $appage      = max(1, optional_param('ap_page', 1, PARAM_INT));
    $apsearch    = optional_param('ap_search', '', PARAM_TEXT);
    $apcomponent = optional_param('ap_component', '', PARAM_ALPHAEXT);
    $aptype      = optional_param('ap_type', 'all', PARAM_ALPHA);
    $apperpage   = max(10, min(200, optional_param('ap_perpage', 25, PARAM_INT)));
    $validtypes  = ['all', 'Lectura', 'Escritura'];

    // Build SQL WHERE for search + component (type is post-filtered in PHP).
    $apwhere  = [];
    $apparams = [];
    if (!empty($apsearch)) {
        $apwhere[] = $DB->sql_like('ef.name', ':ap_search', false, false);
        $apparams['ap_search'] = '%' . $DB->sql_like_escape($apsearch) . '%';
    }
    if (!empty($apcomponent)) {
        $apwhere[] = 'ef.component = :ap_component';
        $apparams['ap_component'] = $apcomponent;
    }
    $apwheresql = !empty($apwhere) ? 'WHERE ' . implode(' AND ', $apwhere) : '';

    // Load ALL matching WS (type classification is PHP-only, can't be done in SQL).
    $apsql = "SELECT ef.name, '' AS description, ef.component
                 FROM {external_functions} ef
                 $apwheresql
             ORDER BY ef.component, ef.name";
    $apallrows = $DB->get_records_sql($apsql, $apparams);

    // Classify each WS and filter by type.
    $apfiltered = [];
    foreach ($apallrows as $ws) {
        $type = local_jedomi_classify_ws_access($ws->name);
        if ($aptype !== 'all' && $type !== $aptype) {
            continue;
        }
        $apfiltered[] = (object) [
            'name'        => $ws->name,
            'description' => $ws->description ?? '',
            'component'   => $ws->component,
            'type'        => $type,
        ];
    }

    // Paginate the filtered result.
    $aptotal  = count($apfiltered);
    $appages  = max(1, (int) ceil($aptotal / $apperpage));
    $appage   = min($appage, $appages);
    $apoffset = ($appage - 1) * $apperpage;
    $apallws = array_slice($apfiltered, $apoffset, $apperpage);

    // Distinct components for filter dropdown.
    $apcomponents = $DB->get_fieldset_sql("SELECT DISTINCT component FROM {external_functions} ORDER BY component");

    // Pre-calculate counts for type filter pills.
    $apcountread  = 0;
    $apcountwrite = 0;
    foreach ($apallrows as $ws) {
        $t = local_jedomi_classify_ws_access($ws->name);
        if ($t === 'Lectura') {
            $apcountread++;
        }
        if ($t === 'Escritura') {
            $apcountwrite++;
        }
    }
?>
<form method="post" action="<?php echo (new moodle_url($pageurl, ['tab' => 'apis', 'role' => $aprole]))->out(false); ?>">
    <input type="hidden" name="sesskey"     value="<?php echo sesskey(); ?>">
    <input type="hidden" name="jedomi_save" value="apis">
    <input type="hidden" name="api_role"    value="<?php echo $aprole; ?>">
    <input type="hidden" name="ws_<?php echo $aprole; ?>_submitted" value="1">

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
                <?php foreach ($validroles as $vr) :
                    $isactive = ($aprole === $vr);
                    $pillurl  = new moodle_url($pageurl, [
                        'tab' => 'apis', 'role' => $vr, 'ap_page' => 1,
                        'ap_search' => $apsearch, 'ap_component' => $apcomponent, 'ap_type' => $aptype,
                    ]);
                ?>
                <a href="<?php echo $pillurl->out(false); ?>"
                   class="btn btn-sm <?php echo $isactive ? 'btn-primary' : 'btn-outline-secondary'; ?>"
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
                <?php foreach ($validtypes as $vt) :
                    $isactive = ($aptype === $vt);
                    $label = $vt === 'all' ? get_string('ws_type_all', 'local_jedomi')
                           : ($vt === 'Lectura' ? get_string('ws_type_read', 'local_jedomi')
                                                : get_string('ws_type_write', 'local_jedomi'));
                    $count = $vt === 'Lectura' ? $apcountread : ($vt === 'Escritura' ? $apcountwrite : count($apallrows));
                    $countid = 'ap-count-' . $vt;
                ?>
                <a href="#" class="btn btn-sm ap-type-pill <?php echo $isactive ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                   data-type="<?php echo $vt; ?>"
                   style="border-radius:2rem;padding:.25rem 1rem">
                    <?php echo $label; ?>
                    <span class="badge badge-light ml-1" id="<?php echo $countid; ?>"><?php echo $count; ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- ── Filters ────────────────────────────────────────────────── -->
            <div class="mb-3 d-flex align-items-center gap-3 flex-wrap">
                <!-- Search -->
                <div style="position:relative;flex:1;min-width:180px;max-width:320px">
                    <i class="fa fa-search"
                       style="position:absolute;left:.625rem;top:50%;transform:translateY(-50%);
                              color:#9ca3af;pointer-events:none"></i>
                    <input type="text" name="ap_search" class="form-control form-control-sm ap-search-input"
                           placeholder="<?php echo get_string('search_ws_placeholder', 'local_jedomi'); ?>"
                           value="<?php echo s($apsearch); ?>"
                           style="padding-left:1.75rem"
                           autocomplete="off">
                </div>

                <!-- Component dropdown -->
                <select name="ap_component" class="form-control form-control-sm ap-filter-trigger" style="max-width:200px">
                    <option value=""><?php echo get_string('component_all', 'local_jedomi'); ?></option>
                    <?php foreach ($apcomponents as $comp) : ?>
                    <option value="<?php echo s($comp); ?>" <?php echo $apcomponent === $comp ? 'selected' : ''; ?>>
                        <?php echo s($comp); ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- Per-page selector -->
                <select name="ap_perpage" class="form-control form-control-sm ap-filter-trigger" style="max-width:100px">
                    <?php foreach ([25, 50, 100, 200] as $pp) : ?>
                    <option value="<?php echo $pp; ?>" <?php echo $apperpage == $pp ? 'selected' : ''; ?>>
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
                            <?php $checkalljs = "document.querySelectorAll('.ap-ws-check')"
                                . ".forEach(function(c){c.checked=this.checked}.bind(this))"; ?>
                            <input type="checkbox" id="ap-check-all"
                                   onchange="<?php echo $checkalljs; ?>">
                        </th>
                        <th><?php echo get_string('ws_name', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('ws_description', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('ws_component', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('ws_type', 'local_jedomi'); ?></th>
                    </tr>
                </thead>
                <tbody id="ap-table-body">
                    <?php if (empty($apallws)) : ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">
                        <?php echo get_string('ws_none_found', 'local_jedomi'); ?>
                    </td></tr>
                    <?php else : ?>
                        <?php foreach ($apallws as $apws) :
                            $iswrite   = $apws->type === 'Escritura';
                            $ischecked = in_array($apws->name, $apallowlist) || ($apfirstvisit && !$iswrite);
                            $rowstyle  = $iswrite ? 'background:#fef2f2;border-left:3px solid #dc2626' : '';
                            ?>
                    <tr style="<?php echo $rowstyle; ?>">
                        <td>
                            <input type="checkbox" class="ap-ws-check"
                                   name="ws_<?php echo $aprole; ?>[]"
                                   value="<?php echo s($apws->name); ?>"
                                   <?php echo $ischecked ? 'checked' : ''; ?>>
                        </td>
                        <td><code style="font-size:.8125rem"><?php echo s($apws->name); ?></code></td>
                        <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?php echo s(shorten_text($apws->description, 80)); ?>
                        </td>
                        <td><span class="badge badge-secondary"><?php echo s($apws->component); ?></span></td>
                        <td>
                            <?php if ($iswrite) : ?>
                            <span class="badge badge-danger"><?php echo get_string('ws_type_write', 'local_jedomi'); ?></span>
                            <?php else : ?>
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
            <div id="ap-pagination" class="d-flex align-items-center justify-content-between flex-wrap gap-2"
                 style="overflow-x:auto">
                <span class="text-muted" style="font-size:.8125rem">
                    <?php echo get_string(
                        'ws_pagination',
                        'local_jedomi',
                        ['page' => $appage, 'pages' => $appages, 'total' => $aptotal]
                    ); ?>
                </span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $appages; $p++) : ?>
                        <li class="page-item <?php echo $p === $appage ? 'active' : ''; ?>">
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
// ── Webhook live check ────────────────────────────────────────────────────.
function jdCheckWebhook() {
    var btn   = document.getElementById('jd-webhook-check-btn');
    var live  = document.getElementById('jd-webhook-live');
    if (!btn || !live) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-pulse mr-1"></i> Checking...';
    live.style.display = 'none';

    var url = '<?php echo (new moodle_url('/local/jedomi/ajax/telegram_webhook_status.php'))->out(false); ?>'
        + '?sesskey=<?php echo sesskey(); ?>';

    fetch(url).then(function(r){ return r.json(); }).then(function(d){
        if (!d.ok) {
            live.innerHTML = '<div class="d-flex align-items-start gap-2" style="font-size:.8125rem;color:#9a3412">'
                + '<i class="fa fa-times-circle" style="color:#dc2626;flex-shrink:0;margin-top:2px"></i>'
                + '<div><strong style="display:block">API Error</strong>'
                + '<span>' + (d.error || 'Unknown error') + '</span></div></div>';
        } else {
            var pending = d.pending_update_count || 0;
            var lastErr = d.last_error_message || '';
            var lastUrl = d.url || '';
            var stored  = d.stored_error || {};

            // Build the status display
            var html = '<div style="font-size:.8125rem">';

            // Webhook URL
            html += '<div class="mb-2"><strong style="color:#374151">Webhook URL:</strong><br>'
                + '<code style="font-size:.75rem;word-break:break-all">' + escHtml(lastUrl) + '</code></div>';

            // Pending updates
            var pendingColor = pending > 0 ? '#ea580c' : '#16a34a';
            html += '<div class="mb-2"><strong style="color:#374151">Pending updates:</strong> '
                + '<span style="font-weight:600;color:' + pendingColor + '">' + pending + '</span></div>';

            // Last error from Telegram
            if (lastErr) {
                html += '<div class="d-flex align-items-start gap-2 mb-2" '
                    + 'style="color:#9a3412;background:#fff7ed;padding:.5rem .75rem;'
                    + 'border-radius:.375rem;border:1px solid #fed7aa">'
                    + '<i class="fa fa-exclamation-triangle" style="color:#ea580c;flex-shrink:0;margin-top:2px"></i>'
                    + '<div><strong>Telegram error:</strong><br><span>' + escHtml(lastErr) + '</span></div></div>';
            }

            // Stored error from our last registration
            if (stored.message) {
                html += '<div class="d-flex align-items-start gap-2" '
                    + 'style="color:#9a3412;background:#fff7ed;padding:.5rem .75rem;'
                    + 'border-radius:.375rem;border:1px solid #fed7aa">'
                    + '<i class="fa fa-info-circle" style="color:#d97706;flex-shrink:0;margin-top:2px"></i>'
                    + '<div><strong>Last registration result:</strong><br><span>' + escHtml(stored.message) + '</span>'
                    + (stored.time ? '<br><span class="text-muted" style="font-size:.75rem">'
                        + (new Date(stored.time * 1000)).toLocaleString() + '</span>' : '')
                    + '</div></div>';
            }

            if (!lastErr && !stored.message) {
                html += '<div style="color:#166534"><i class="fa fa-check-circle" style="color:#16a34a"></i> '
                    + 'Webhook is healthy.</div>';
            }

            html += '</div>';
            live.innerHTML = html;
        }
        live.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-sync mr-1"></i>Check webhook';
    }).catch(function(err){
        live.innerHTML = '<div class="d-flex align-items-start gap-2" style="font-size:.8125rem;color:#9a3412">'
            + '<i class="fa fa-times-circle" style="color:#dc2626;flex-shrink:0;margin-top:2px"></i>'
            + '<div><strong>Network error</strong><br><span>' + escHtml(err.message) + '</span></div></div>';
        live.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-sync mr-1"></i>Check webhook';
    });
}

// ── Drop pending updates ───────────────────────────────────────────────────.
function jdDropPending() {
    var btn    = document.getElementById('jd-drop-pending-btn');
    var result = document.getElementById('jd-drop-pending-result');
    if (!btn || !result) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-pulse mr-1"></i> Dropping...';
    result.style.display = 'none';

    var url = '<?php echo (new moodle_url('/local/jedomi/ajax/telegram_drop_pending.php'))->out(false); ?>'
        + '?sesskey=<?php echo sesskey(); ?>';

    fetch(url).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok) {
            result.innerHTML = '<span style="color:#166534">'
                + '<?php echo get_string('drop_pending_success', 'local_jedomi'); ?>'
                + '</span>';
        } else {
            result.innerHTML = '<span style="color:#9a3412">'
                + '<?php echo get_string('drop_pending_fail', 'local_jedomi'); ?>'
                + (d.error ? '<br><span class="text-muted" style="font-size:.75rem">' + escHtml(d.error) + '</span>' : '')
                + '</span>';
        }
        result.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-trash-alt mr-1"></i><?php echo get_string('drop_pending_updates', 'local_jedomi'); ?>';
    }).catch(function(err){
        result.innerHTML = '<span style="color:#9a3412">'
            + '<?php echo get_string('drop_pending_fail', 'local_jedomi'); ?>'
            + '<br><span class="text-muted" style="font-size:.75rem">' + escHtml(err.message) + '</span></span>';
        result.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-trash-alt mr-1"></i><?php echo get_string('drop_pending_updates', 'local_jedomi'); ?>';
    });
}

function escHtml(s) {
    if (typeof s !== 'string') s = String(s || '');
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// ── Integrations accordion ─────────────────────────────────────────────────.
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
                        dropdown.innerHTML = '<div style="padding:.75rem;color:#94a3b8;font-size:.875rem">'
                            + '<?php echo get_string('course_search_noresults', 'local_jedomi'); ?></div>';
                    } else {
                        dropdown.innerHTML = '';
                        courses.forEach(function(c){
                            var row = document.createElement('div');
                            row.style.cssText = 'padding:.625rem .75rem;cursor:pointer;'
                                + 'border-bottom:1px solid #f1f5f9;transition:background .1s';
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

<?php if ($tab === 'apis') : ?>
<!-- ── WS table AJAX (filters, type pills, pagination) ─────────────────── -->
<script>
(function(){
    var baseUrl  = '<?php echo (new moodle_url('/local/jedomi/ajax/ws_table.php'))->out(false); ?>';
    var role     = '<?php echo $aprole; ?>';
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
            if (ca) ca.onchange = function(){
                document.querySelectorAll('.ap-ws-check').forEach(function(c){c.checked=ca.checked;});
            };
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
<?php endif; ?>

<?php // TAB: AGENTS ──────────────────────────────────────────────────────────────. ?>
<?php if ($tab === 'agents') : ?>
<style>
.agent-chart-wrap { position:relative; min-height:160px; }
.agent-role-scroll { max-height:150px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:.375rem; padding:.5rem; }
</style>

<div style="max-width:780px; margin:0 auto; padding-bottom:2rem">

<!-- ── Summary Card ──────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div class="card-icon" style="background:#e0e7ff"><i class="fa fa-chart-bar" style="color:#4f46e5"></i></div>
        <div>
            <h5><?php echo get_string('agent_summary', 'local_jedomi'); ?></h5>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="agent-chart-wrap"><canvas id="agent-by-role-chart"></canvas></div>
                <p class="text-center text-muted small mt-2"><?php echo get_string('agent_by_role', 'local_jedomi'); ?></p>
            </div>
            <div class="col-md-4">
                <div class="agent-chart-wrap"><canvas id="agent-by-status-chart"></canvas></div>
                <p class="text-center text-muted small mt-2"><?php echo get_string('agent_by_status', 'local_jedomi'); ?></p>
            </div>
            <div class="col-md-4">
                <div class="agent-chart-wrap"><canvas id="agent-executions-chart"></canvas></div>
                <p class="text-center text-muted small mt-2"><?php echo get_string('agent_executions_30d', 'local_jedomi'); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- ── Filters Card ──────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body">
        <div class="row align-items-end">
            <div class="col-md-3">
                <label class="form-label-main"><?php echo get_string('agent_filter_role', 'local_jedomi'); ?></label>
                <select id="agent-filter-role" class="form-control form-control-sm">
                    <option value=""><?php echo get_string('agent_status_all', 'local_jedomi'); ?></option>
                    <?php foreach ($allroles as $role) : ?>
                    <option value="<?php echo $role->id; ?>"><?php echo s($role->localname ?? $role->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-main"><?php echo get_string('agent_filter_status', 'local_jedomi'); ?></label>
                <select id="agent-filter-status" class="form-control form-control-sm">
                    <option value=""><?php echo get_string('agent_status_all', 'local_jedomi'); ?></option>
                    <option value="1"><?php echo get_string('agent_enabled', 'local_jedomi'); ?></option>
                    <option value="0"><?php echo get_string('agent_disabled', 'local_jedomi'); ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label-main"><?php echo get_string('agent_filter_context', 'local_jedomi'); ?></label>
                <select id="agent-filter-context" class="form-control form-control-sm">
                    <option value=""><?php echo get_string('agent_status_all', 'local_jedomi'); ?></option>
                    <option value="site"><?php echo get_string('agent_context_type_site', 'local_jedomi'); ?></option>
                    <option value="course"><?php echo get_string('agent_context_type_course', 'local_jedomi'); ?></option>
                    <option value="category"><?php echo get_string('agent_context_type_category', 'local_jedomi'); ?></option>
                    <option value="user"><?php echo get_string('agent_context_type_user', 'local_jedomi'); ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label-main"><?php echo get_string('agent_search', 'local_jedomi'); ?></label>
                <input type="text" id="agent-filter-search" class="form-control form-control-sm"
                       placeholder="<?php echo get_string('agent_search', 'local_jedomi'); ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm btn-block" id="agent-apply-filters" style="width:100%">
                    <?php echo get_string('agent_apply_filters', 'local_jedomi'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Agent Table ───────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div class="card-icon" style="background:#f3e8ff"><i class="fa fa-robot" style="color:#9333ea"></i></div>
        <div>
            <h5><?php echo get_string('agent_table', 'local_jedomi'); ?></h5>
        </div>
    </div>
    <div class="card-body">
        <div id="agent-table-msg" style="display:none" class="alert alert-danger"></div>
        <div id="agent-table-wrap" style="overflow-x:auto">
            <table class="table table-sm table-hover" style="font-size:.875rem">
                <thead>
                    <tr>
                        <th><?php echo get_string('agent_creator', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('agent_name', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('agent_schedule', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('agent_status', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('agent_lastrun', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('agent_actions', 'local_jedomi'); ?></th>
                    </tr>
                </thead>
                <tbody id="agent-table-body">
                    <tr><td colspan="6" class="text-center text-muted py-4"><?php echo get_string('loading', 'admin'); ?></td></tr>
                </tbody>
            </table>
        </div>
        <div id="agent-pagination" class="d-flex align-items-center justify-content-between flex-wrap"></div>
    </div>
</div>

<!-- ── Create Agent Card ─────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div class="card-icon" style="background:#dbeafe"><i class="fa fa-plus-circle" style="color:#2563eb"></i></div>
        <div>
            <h5 id="agent-create-heading"><?php echo get_string('agent_create', 'local_jedomi'); ?></h5>
        </div>
    </div>
    <div class="card-body">
        <div id="agent-create-msg" style="display:none" class="alert"></div>
        <form id="agent-create-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" id="agent-edit-id" name="id" value="">

            <div class="form-group">
                <label class="form-label-main" for="agent-create-prompt">
                    <?php echo get_string('agent_prompt', 'local_jedomi'); ?>
                </label>
                <textarea id="agent-create-prompt" name="prompt" class="form-control" rows="3" required
                          placeholder="<?php echo get_string('agent_prompt_placeholder', 'local_jedomi'); ?>"></textarea>
            </div>

            <div class="row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label-main" for="agent-create-name">
                            <?php echo get_string('agent_name', 'local_jedomi'); ?>
                        </label>
                        <input type="text" id="agent-create-name" name="name" class="form-control"
                               placeholder="<?php echo get_string('agent_name_placeholder', 'local_jedomi'); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label-main" for="agent-schedule-select">
                            <?php echo get_string('agent_schedule', 'local_jedomi'); ?>
                        </label>
                        <select id="agent-schedule-select" name="schedule_cron" class="form-control">
                            <option value="*/5 * * * *"><?php echo get_string('agent_every_5min', 'local_jedomi'); ?></option>
                            <option value="*/15 * * * *"><?php echo get_string('agent_every_15min', 'local_jedomi'); ?></option>
                            <option value="*/30 * * * *"><?php echo get_string('agent_every_30min', 'local_jedomi'); ?></option>
                            <option value="0 * * * *"><?php echo get_string('agent_every_1h', 'local_jedomi'); ?></option>
                            <option value="0 */6 * * *"><?php echo get_string('agent_every_6h', 'local_jedomi'); ?></option>
                            <option value="0 0 * * *"><?php echo get_string('agent_daily', 'local_jedomi'); ?></option>
                            <option value="0 0 * * 0"><?php echo get_string('agent_weekly', 'local_jedomi'); ?></option>
                            <option value="custom"><?php echo get_string('agent_custom_cron', 'local_jedomi'); ?></option>
                        </select>
                        <div id="agent-custom-cron-wrap" style="display:none;margin-top:.5rem">
                            <input type="text" name="custom_cron" class="form-control form-control-sm" placeholder="* * * * *">
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label-main" for="agent-create-context-type">
                            <?php echo get_string('agent_filter_context', 'local_jedomi'); ?>
                        </label>
                        <select id="agent-create-context-type" name="context_type" class="form-control">
                            <option value="site"><?php echo get_string('agent_context_type_site', 'local_jedomi'); ?></option>
                            <option value="course"><?php echo get_string('agent_context_type_course', 'local_jedomi'); ?></option>
                            <option value="category"><?php
                                echo get_string('agent_context_type_category', 'local_jedomi'); ?></option>
                            <option value="user"><?php echo get_string('agent_context_type_user', 'local_jedomi'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="col-md-4" id="agent-create-context-id-wrap" style="display:none">
                    <div class="form-group">
                        <label class="form-label-main" for="agent-create-context-id">
                            <?php echo get_string('agent_context', 'local_jedomi'); ?> ID
                        </label>
                        <input type="text" id="agent-create-context-id" name="context_id" class="form-control"
                               placeholder="e.g. 42">
                    </div>
                </div>
            </div>

            <div class="form-group mt-3">
                <label class="form-label-main"><?php echo get_string('agent_filter_role', 'local_jedomi'); ?></label>
                <div class="agent-role-scroll">
                    <?php foreach ($allroles as $role) : ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox"
                               name="role_filter[]" value="<?php echo $role->id; ?>"
                               id="agent-role-<?php echo $role->id; ?>">
                        <label class="form-check-label" for="agent-role-<?php echo $role->id; ?>">
                            <?php echo s($role->localname ?? $role->name); ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="save-row d-flex align-items-center gap-2">
                <button type="submit" class="btn btn-primary" id="agent-form-submit">
                    <i class="fa fa-plus-circle mr-1"></i><span id="agent-submit-label"><?php
                        echo get_string('agent_create', 'local_jedomi'); ?></span>
                </button>
                <button type="button" class="btn btn-outline-info" id="agent-preview-btn">
                    <i class="fa fa-eye mr-1"></i><?php echo get_string('agent_preview', 'local_jedomi'); ?>
                </button>
                <button type="button" class="btn btn-outline-secondary ml-2" id="agent-edit-cancel" style="display:none">
                    <?php echo get_string('cancel'); ?>
                </button>
            </div>
            <!-- Visual separator before preview -->
            <hr id="agent-preview-separator" style="display:none; border:none; border-top:2px solid #e5e7eb; margin:1rem 0">
            <!-- Preview result panel (hidden by default) -->
            <div id="agent-preview-panel" class="agent-preview-result"
                 style="display:none; margin-top:0; border:1px solid #e5e7eb; border-radius:.5rem; overflow:hidden">
                <div style="background:#f9fafb; padding:.625rem .75rem; font-weight:600;
                            border-bottom:1px solid #e5e7eb; font-size:.875rem">
                    <i class="fa fa-robot mr-1"></i><?php echo get_string('agent_preview_result', 'local_jedomi'); ?>
                    <span id="agent-preview-spinner" class="ml-2" style="display:none"><i class="fa fa-spinner fa-spin"></i></span>
                </div>
                <div id="agent-preview-body"
                     style="padding:.75rem; font-size:.8125rem; background:#fff; min-height:60px;
                            max-height:600px; overflow-y:auto; white-space:pre-wrap; word-break:break-word"></div>
            </div>
        </form>
    </div>
</div>

<!-- ── Purge Card ────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div class="card-icon" style="background:#fef2f2"><i class="fa fa-trash-alt" style="color:#dc2626"></i></div>
        <div>
            <h5><?php echo get_string('agent_purge', 'local_jedomi'); ?></h5>
        </div>
    </div>
    <div class="card-body">
        <form id="agent-purge-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <div class="row align-items-end">
                <div class="col-md-5">
                    <div class="form-group">
                        <label class="form-label-main" for="agent-purge-context-type">
                            <?php echo get_string('agent_purge_context', 'local_jedomi'); ?>
                        </label>
                        <select id="agent-purge-context-type" name="context_type" class="form-control">
                            <option value="site"><?php echo get_string('agent_context_type_site', 'local_jedomi'); ?></option>
                            <option value="course"><?php echo get_string('agent_context_type_course', 'local_jedomi'); ?></option>
                            <option value="category"><?php
                                echo get_string('agent_context_type_category', 'local_jedomi'); ?></option>
                            <option value="role"><?php echo get_string('agent_filter_role', 'local_jedomi'); ?></option>
                        </select>
                        <p class="form-text"><?php echo get_string('agent_purge_context_help', 'local_jedomi'); ?></p>
                    </div>
                </div>
                <div class="col-md-4" id="agent-purge-context-id-wrap">
                    <div class="form-group">
                        <label class="form-label-main" for="agent-purge-context-id">
                            <?php echo get_string('agent_context', 'local_jedomi'); ?> ID
                        </label>
                        <input type="text" id="agent-purge-context-id" name="context_id" class="form-control" placeholder="e.g. 42">
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-danger" id="agent-purge-btn">
                        <i class="fa fa-trash-alt mr-1"></i><?php echo get_string('agent_purge_btn', 'local_jedomi'); ?>
                    </button>
                </div>
            </div>
        </form>
        <div id="agent-purge-msg" style="display:none" class="alert mt-2"></div>
    </div>
</div>

</div>

<!-- ── Agents Inline JavaScript ──────────────────────────────────────── -->
<script>
(function(){
    var baseUrl = '<?php echo $CFG->wwwroot; ?>/local/jedomi/ajax/';
    var sesskey = '<?php echo sesskey(); ?>';
    var currentPage = 1;

    function escHtml(s) {
        if (typeof s !== 'string') s = String(s || '');
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    var esc = escHtml;

    function getFilters() {
        var f = {};
        var roleE = document.getElementById('agent-filter-role');
        var statusE = document.getElementById('agent-filter-status');
        var ctxE = document.getElementById('agent-filter-context');
        var searchE = document.getElementById('agent-filter-search');
        if (roleE && roleE.value) { f.role = roleE.value; }
        if (statusE && statusE.value !== '') { f.status = statusE.value; }
        if (ctxE && ctxE.value) { f.context_type = ctxE.value; }
        if (searchE && searchE.value.trim()) { f.search = searchE.value.trim(); }
        return f;
    }

    function qs(params) {
        var p = new URLSearchParams();
        p.set('sesskey', sesskey);
        Object.keys(params).forEach(function(k){
            if (params[k] !== undefined && params[k] !== '') p.set(k, params[k]);
        });
        return p.toString();
    }

    // ── Helpers: convert stats data to chart-friendly arrays ──────────────
    function objToPairs(obj) {
        var arr = [];
        Object.keys(obj).forEach(function(k){ arr.push({label: k, count: obj[k]}); });
        return arr;
    }

    // ── Charts ────────────────────────────────────────────────────────────
    function loadStats(filters, cb) {
        var params = {};
        if (filters) { Object.keys(filters).forEach(function(k){ params['filters['+k+']'] = filters[k]; }); }

        fetch(baseUrl + 'agent_stats.php?page=1&perpage=20&sesskey=' + sesskey
              + '&filters=' + encodeURIComponent(JSON.stringify(filters || {})))
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.ok) { console.warn('Stats error:', d.error); drawEmptyAll(); if (cb) cb(null); return; }
                var charts = d.charts || {};
                // by_role is an object {rolename: count}; by_status is {enabled:N,disabled:N}
                drawPie('agent-by-role-chart', objToPairs(charts.by_role || {}));
                drawBar('agent-by-status-chart', objToPairs(charts.by_status || {}));
                // executions_30d is already [{day, count}]
                drawBar('agent-executions-chart', charts.executions_30d || []);
                if (cb) cb(d);
            })
            .catch(function(err){
                console.error('Stats fetch failed:', err);
                drawEmptyAll();
                if (cb) cb(null);
            });
    }

    function drawEmptyAll() {
        ['agent-by-role-chart','agent-by-status-chart','agent-executions-chart'].forEach(function(id){
            var c = document.getElementById(id);
            if (!c) return;
            var ctx = c.getContext('2d');
            var p = c.parentElement;
            c.width = (p ? p.clientWidth : 200) || 200;
            c.height = (p ? p.clientHeight : 160) || 160;
            ctx.clearRect(0, 0, c.width, c.height);
            ctx.fillStyle = '#9ca3af';
            ctx.font = '12px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(<?php echo json_encode(get_string('agent_error_load', 'local_jedomi')); ?>, c.width/2, c.height/2);
        });
    }

    function drawPie(id, data) {
        var canvas = document.getElementById(id);
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var p = canvas.parentElement;
        canvas.width = (p ? p.clientWidth : 200) || 200;
        canvas.height = (p ? p.clientHeight : 160) || 160;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!data || !data.length) {
            ctx.fillStyle = '#9ca3af';
            ctx.font = '12px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('No data', canvas.width/2, canvas.height/2);
            return;
        }
        var cx = canvas.width / 2;
        var cy = canvas.height / 2 - 10;
        var r = Math.min(cx, cy) - 5;
        var colors = ['#4f46e5','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16'];
        var total = data.reduce(function(s, o){ return s + parseInt(o.count || 0, 10); }, 0);
        if (total === 0) {
            ctx.fillStyle = '#9ca3af';
            ctx.font = '12px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('No data', canvas.width/2, canvas.height/2);
            return;
        }
        var start = -Math.PI / 2;
        data.forEach(function(item, i){
            var angle = (item.count / total) * 2 * Math.PI;
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, r, start, start + angle);
            ctx.closePath();
            ctx.fillStyle = colors[i % colors.length];
            ctx.fill();
            if (angle > 0.3) {
                var mid = start + angle / 2;
                ctx.fillStyle = '#fff';
                ctx.font = 'bold 10px sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(Math.round(item.count / total * 100) + '%',
                    cx + Math.cos(mid) * r * 0.6, cy + Math.sin(mid) * r * 0.6);
            }
            start += angle;
        });
        var ly = 5;
        data.forEach(function(item, i){
            ctx.fillStyle = colors[i % colors.length];
            ctx.fillRect(4, ly, 8, 8);
            ctx.fillStyle = '#374151';
            ctx.font = '9px sans-serif';
            ctx.textAlign = 'left';
            ctx.textBaseline = 'top';
            ctx.fillText((item.label || '') + ' (' + item.count + ')', 15, ly);
            ly += 13;
        });
    }

    function drawBar(id, data) {
        var canvas = document.getElementById(id);
        if (!canvas) return;
        var ctx = canvas.getContext('2d');
        var p = canvas.parentElement;
        canvas.width = (p ? p.clientWidth : 200) || 200;
        canvas.height = (p ? p.clientHeight : 160) || 160;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!data || !data.length) {
            ctx.fillStyle = '#9ca3af';
            ctx.font = '12px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('No data', canvas.width/2, canvas.height/2);
            return;
        }
        var pad = { t: 20, b: 18, l: 5, r: 5 };
        var w = canvas.width - pad.l - pad.r;
        var h = canvas.height - pad.t - pad.b;
        var n = data.length;
        var bw = Math.min(28, (w / n) - 2);
        var max = 1;
        data.forEach(function(o){ if (parseInt(o.count, 10) > max) max = parseInt(o.count, 10); });
        var colors = ['#4f46e5','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16'];
        data.forEach(function(item, i){
            var val = parseInt(item.count, 10) || 0;
            var bh = (val / max) * h;
            var x = pad.l + (w / n) * i + ((w / n) - bw) / 2;
            var y = pad.t + h - bh;
            ctx.fillStyle = colors[i % colors.length];
            ctx.fillRect(x, y, bw, bh);
            ctx.fillStyle = '#374151';
            ctx.font = '8px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            var lbl = item.label || '';
            if (lbl.length > 8) lbl = lbl.substring(0, 8) + '\u2026';
            ctx.fillText(lbl, x + bw/2, canvas.height - pad.b + 3);
            ctx.fillStyle = '#6b7280';
            ctx.font = 'bold 9px sans-serif';
            ctx.textBaseline = 'bottom';
            ctx.fillText(val, x + bw/2, y - 1);
        });
    }

    // ── Table: render from agent_stats.php rows ──────────────────────────
    var agentRows = []; // Last page of rows, source of the details modal.

    // The scroll wrapper (overflow-x:auto) clips the absolutely-positioned
    // dropdown menus. Lift the clipping only while a menu is open. RequireJS
    // loads in Moodle's footer, so defer until the page has fully loaded.
    window.addEventListener('load', function() {
        if (typeof window.require !== 'function') {
            return;
        }
        require(['jquery'], function($) {
            var wrap = $('#agent-table-wrap');
            wrap.on('show.bs.dropdown', function() {
                wrap.css('overflow', 'visible');
            });
            wrap.on('hidden.bs.dropdown', function() {
                wrap.css('overflow', '');
            });
        });
    });

    function loadAgents(filters, page, cb) {
        var tbody = document.getElementById('agent-table-body');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading\u2026</td></tr>';
        }

        fetch(baseUrl + 'agent_stats.php?page=' + (page||1) + '&perpage=20&sesskey=' + sesskey
              + '&filters=' + encodeURIComponent(JSON.stringify(filters || {})))
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.ok) { showTableError(d.error || 'Error'); if (cb) cb(null); return; }
                agentRows = d.rows || [];
                if (tbody) tbody.innerHTML = renderTableRows(agentRows);
                renderPagination(d.total || 0, d.page || 1);
                if (cb) cb(d);
            })
            .catch(function(err){
                console.error('Agent list error:', err);
                showTableError(err.message || 'Network error');
                if (cb) cb(null);
            });
    }

    function renderTableRows(rows) {
        if (!rows || !rows.length) {
            return '<tr><td colspan="6" class="text-center text-muted py-4">'
                + <?php echo json_encode(get_string('agent_no_results', 'local_jedomi')); ?> + '</td></tr>';
        }
        var html = '';
        rows.forEach(function(r){
            var enabled = parseInt(r.enabled, 10) === 1;
            var agentName = r.name || <?php echo json_encode(get_string('agent_unnamed', 'local_jedomi')); ?> + ' #' + r.id;
            var statusLabel, statusClass;
            if (enabled) {
                statusLabel = <?php echo json_encode(get_string('agent_enabled', 'local_jedomi')); ?>;
                statusClass = 'badge badge-success';
            } else {
                statusLabel = <?php echo json_encode(get_string('agent_disabled', 'local_jedomi')); ?>;
                statusClass = 'badge badge-secondary';
            }
            var lastrunHtml = r.lastrun ? new Date(r.lastrun * 1000).toLocaleString() : '-';
            var toggleLabel = enabled
                ? <?php echo json_encode(get_string('agent_disable', 'local_jedomi')); ?>
                : <?php echo json_encode(get_string('agent_enable', 'local_jedomi')); ?>;
            html += '<tr>'
                + '<td>' + escHtml(r.user_fullname || 'User #' + r.userid) + '</td>'
                + '<td><strong>' + escHtml(agentName) + '</strong></td>'
                + '<td><code>' + escHtml(r.schedule_cron) + '</code></td>'
                + '<td><span class="' + statusClass + '">' + statusLabel + '</span></td>'
                + '<td>' + lastrunHtml + '</td>'
                + '<td>'
                + '<div class="dropdown">'
                + '<button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="dropdown" '
                + 'aria-haspopup="true" aria-expanded="false" '
                + 'aria-label="<?php echo get_string('agent_actions_menu', 'local_jedomi'); ?>" '
                + 'title="<?php echo get_string('agent_actions_menu', 'local_jedomi'); ?>">'
                + '<i class="fa fa-ellipsis-v"></i></button>'
                + '<div class="dropdown-menu dropdown-menu-right">'
                + '<a class="dropdown-item" href="#" data-agent-action="details" data-agent-id="' + r.id + '">'
                + '<i class="fa fa-eye mr-2"></i><?php echo get_string('agent_view_details', 'local_jedomi'); ?></a>'
                + '<a class="dropdown-item" href="#" data-agent-action="edit" data-agent-id="' + r.id + '">'
                + '<i class="fa fa-pen mr-2"></i><?php echo get_string('agent_edit', 'local_jedomi'); ?></a>'
                + '<a class="dropdown-item" href="#" data-agent-action="run" data-agent-id="' + r.id + '">'
                + '<i class="fa fa-play mr-2"></i><?php echo get_string('agent_run_now', 'local_jedomi'); ?></a>'
                + '<a class="dropdown-item" href="#" data-agent-action="toggle" data-agent-id="' + r.id + '">'
                + '<i class="fa ' + (enabled ? 'fa-toggle-off' : 'fa-toggle-on') + ' mr-2"></i>'
                + escHtml(toggleLabel) + '</a>'
                + '<div class="dropdown-divider"></div>'
                + '<a class="dropdown-item text-danger" href="#" data-agent-action="delete" data-agent-id="' + r.id + '">'
                + '<i class="fa fa-trash-alt mr-2"></i><?php echo get_string('agent_delete', 'local_jedomi'); ?></a>'
                + '</div>'
                + '</div>'
                + '</td>'
                + '</tr>';
        });
        return html;
    }

    // Details modal (Moodle core/modal API).
    function showAgentDetails(id) {
        var r = null;
        for (var i = 0; i < agentRows.length; i++) {
            if (String(agentRows[i].id) === String(id)) { r = agentRows[i]; break; }
        }
        if (!r) return;
        var enabled = parseInt(r.enabled, 10) === 1;
        var agentName = r.name || <?php echo json_encode(get_string('agent_unnamed', 'local_jedomi')); ?> + ' #' + r.id;
        var statusLabel = enabled
            ? <?php echo json_encode(get_string('agent_enabled', 'local_jedomi')); ?>
            : <?php echo json_encode(get_string('agent_disabled', 'local_jedomi')); ?>;
        var rowsdef = [
            [<?php echo json_encode(get_string('agent_name', 'local_jedomi')); ?>, '<strong>' + escHtml(agentName) + '</strong>'],
            [<?php echo json_encode(get_string('agent_creator', 'local_jedomi')); ?>,
                escHtml(r.user_fullname || 'User #' + r.userid)],
            [<?php echo json_encode(get_string('agent_status', 'local_jedomi')); ?>,
                '<span class="badge ' + (enabled ? 'badge-success' : 'badge-secondary') + '">' + statusLabel + '</span>'],
            [<?php echo json_encode(get_string('agent_context', 'local_jedomi')); ?>,
                escHtml(r.context_type || '-') + (r.context_id ? ' #' + r.context_id : '')],
            [<?php echo json_encode(get_string('agent_schedule', 'local_jedomi')); ?>,
                '<code>' + escHtml(r.schedule_cron || '-') + '</code>'],
            [<?php echo json_encode(get_string('agent_lastrun', 'local_jedomi')); ?>,
                r.lastrun ? new Date(r.lastrun * 1000).toLocaleString() : '-'],
            [<?php echo json_encode(get_string('agent_nextrun', 'local_jedomi')); ?>,
                r.nextrun ? new Date(r.nextrun * 1000).toLocaleString() : '-'],
            [<?php echo json_encode(get_string('agent_prompt', 'local_jedomi')); ?>,
                '<pre class="mb-0 p-2 bg-light border rounded" style="white-space:pre-wrap;max-height:16rem;overflow:auto">'
                + escHtml(r.prompt || '-') + '</pre>'],
        ];
        var body = '<table class="table table-sm mb-0"><tbody>';
        rowsdef.forEach(function(pair){
            body += '<tr><th class="text-nowrap align-top" style="width:11rem">' + pair[0] + '</th>'
                + '<td>' + pair[1] + '</td></tr>';
        });
        body += '</tbody></table>';

        require(['core/modal'], function(Modal) {
            Modal.create({
                title: escHtml(agentName),
                body: body,
                large: true,
                show: true,
                removeOnClose: true
            });
        });
    }

    function renderPagination(total, page) {
        var pw = document.getElementById('agent-pagination');
        if (!pw) return;
        var perpage = 20;
        var pages = Math.ceil(total / perpage);
        if (pages <= 1) { pw.innerHTML = ''; return; }
        var html = '<span class="text-muted small">' + total + ' agents</span><nav><ul class="pagination pagination-sm mb-0">';
        for (var i = 1; i <= pages; i++) {
            var active = i === page ? ' active' : '';
            html += '<li class="page-item' + active + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
        }
        html += '</ul></nav>';
        pw.innerHTML = html;
    }

    function showTableError(msg) {
        var tbody = document.getElementById('agent-table-body');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">'
                + escHtml(msg) + '</td></tr>';
        }
    }

    // ── Helpers: reset form to create mode ──────────────────────────────
    function resetForm() {
        document.getElementById('agent-create-form').reset();
        document.getElementById('agent-edit-id').value = '';
        document.getElementById('agent-edit-cancel').style.display = 'none';
        document.getElementById('agent-create-heading').textContent =
            <?php echo json_encode(get_string('agent_create', 'local_jedomi')); ?>;
        document.getElementById('agent-submit-label').textContent =
            <?php echo json_encode(get_string('agent_create', 'local_jedomi')); ?>;
    }

    // Moodle delete-confirmation modal (replaces the native confirm dialog).
    function confirmDeleteModal(title, question, ondelete) {
        require(['core/notification'], function(Notification) {
            Notification.deleteCancelPromise(title, question)
                .then(function() {
                    ondelete();
                    return;
                })
                .catch(function() {
                    return; // Cancelled.
                });
        });
    }

    // ── Delegated action handler (works for dynamically added buttons) ────
    document.body.addEventListener('click', function(e){
        var btn = e.target.closest && e.target.closest('[data-agent-action]');
        if (!btn) return;
        e.preventDefault();
        var action = btn.dataset.agentAction;
        var id = btn.dataset.agentId;
        if (!id) return;

        // ── Details: full agent configuration in a core/modal ───────────────
        if (action === 'details') {
            showAgentDetails(id);
            return;
        }

        // ── Edit: populate form ─────────────────────────────────────────────
        if (action === 'edit') {
            var body = new URLSearchParams();
            body.set('sesskey', sesskey);
            body.set('action', 'get');
            body.set('id', id);
            fetch(baseUrl + 'agent_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.ok || !d.agent) { showTableError(d.error || 'Error'); return; }
                var a = d.agent;
                // Scroll to form
                var heading = document.getElementById('agent-create-heading');
                if (heading) heading.scrollIntoView({behavior:'smooth', block:'start'});
                // Populate
                document.getElementById('agent-create-name').value = a.name || '';
                document.getElementById('agent-create-prompt').value = a.prompt || '';
                var sched = document.getElementById('agent-schedule-select');
                if (sched) sched.value = a.schedule_cron || '*/5 * * * *';
                var ctx = document.getElementById('agent-create-context-type');
                if (ctx) ctx.value = a.context_type || 'site';
                var ctxId = document.getElementById('agent-create-context-id');
                if (ctxId) ctxId.value = a.context_id || '';
                // Role checkboxes
                document.querySelectorAll('[name="role_filter[]"]').forEach(function(cb){
                    cb.checked = a.role_ids && a.role_ids.indexOf(parseInt(cb.value,10)) !== -1;
                });
                // Set edit mode
                document.getElementById('agent-edit-id').value = a.id;
                document.getElementById('agent-edit-cancel').style.display = 'inline-block';
                document.getElementById('agent-create-heading').textContent =
                    <?php echo json_encode(get_string('agent_edit', 'local_jedomi')); ?>;
                document.getElementById('agent-submit-label').textContent =
                    <?php echo json_encode(get_string('agent_edit', 'local_jedomi')); ?>;
                // Clear any previous message
                var msg = document.getElementById('agent-create-msg');
                if (msg) msg.style.display = 'none';
            })
            .catch(function(err){ showTableError(err.message); });
            return;
        }

        // ── Delete / run / toggle ───────────────────────────────────────────
        function performAgentAction() {
            var body = new URLSearchParams();
            body.set('sesskey', sesskey);
            body.set('action', action);
            body.set('id', id);

            fetch(baseUrl + 'agent_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.ok) { showTableError(d.error || 'Error'); return; }
                if (action === 'run') {
                    var msg = document.getElementById('agent-table-msg');
                    if (msg) {
                        msg.className = 'alert alert-success';
                        msg.textContent = <?php echo json_encode(get_string('agent_run_queued', 'local_jedomi')); ?>;
                        msg.style.display = 'block';
                        setTimeout(function(){ msg.style.display = 'none'; }, 4000);
                    }
                }
                loadAgents(getFilters(), currentPage);
                loadStats(getFilters());
            })
            .catch(function(err){
                showTableError(err.message);
            });
        }

        if (action === 'delete') {
            confirmDeleteModal(
                <?php echo json_encode(get_string('agent_delete', 'local_jedomi')); ?>,
                <?php echo json_encode(get_string('agent_delete_confirm', 'local_jedomi')); ?>,
                performAgentAction
            );
            return;
        }
        performAgentAction();
    });

    // ── Apply filters ─────────────────────────────────────────────────────
    var applyBtn = document.getElementById('agent-apply-filters');
    if (applyBtn) {
        applyBtn.addEventListener('click', function(){
            currentPage = 1;
            loadStats(getFilters());
            loadAgents(getFilters(), 1);
        });
    }

    // ── Pagination (delegated) ────────────────────────────────────────────
    var pagWrap = document.getElementById('agent-pagination');
    if (pagWrap) {
        pagWrap.addEventListener('click', function(e){
            var link = e.target.closest('[data-page]');
            if (!link) return;
            e.preventDefault();
            currentPage = parseInt(link.dataset.page, 10) || 1;
            loadAgents(getFilters(), currentPage);
        });
    }

    // ── Cancel edit button ────────────────────────────────────────────────
    var cancelBtn = document.getElementById('agent-edit-cancel');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function(){
            resetForm();
            var msg = document.getElementById('agent-create-msg');
            if (msg) msg.style.display = 'none';
        });
    }

    // ── Create / Update form ──────────────────────────────────────────────
    var createForm = document.getElementById('agent-create-form');
    if (createForm) {
        // PHP-resolved strings (once, at page load)
        var STR_AGENT_ERROR_UPDATE = <?php echo json_encode(get_string('agent_error_update', 'local_jedomi')); ?>;
        var STR_AGENT_ERROR_CREATE = <?php echo json_encode(get_string('agent_error_create', 'local_jedomi')); ?>;
        var STR_AGENT_UPDATE_SUCCESS = <?php echo json_encode(get_string('agent_update_success', 'local_jedomi')); ?>;
        var STR_AGENT_CREATE_SUCCESS = <?php echo json_encode(get_string('agent_create_success', 'local_jedomi')); ?>;

        createForm.addEventListener('submit', function(e){
            e.preventDefault();
            var fd = new FormData(this);
            fd.set('sesskey', sesskey);

            var editId = document.getElementById('agent-edit-id').value;
            var isUpdate = editId !== '';
            fd.set('action', isUpdate ? 'update' : 'create');
            if (isUpdate) fd.set('id', editId);

            fetch(baseUrl + 'agent_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(fd).toString()
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                var msg = document.getElementById('agent-create-msg');
                if (!msg) return;
                if (d.error) {
                    msg.className = 'alert alert-danger';
                    msg.textContent = d.error || (isUpdate ? STR_AGENT_ERROR_UPDATE : STR_AGENT_ERROR_CREATE);
                    msg.style.display = 'block';
                    return;
                }
                msg.className = 'alert alert-success';
                var label;
                if (isUpdate) {
                    label = STR_AGENT_UPDATE_SUCCESS;
                } else if (d.name && d.name.trim()) {
                    label = '✅ '
                        + <?php echo json_encode(get_string('agent_created_with_name', 'local_jedomi')); ?>
                            .replace('{name}', d.name.trim());
                } else {
                    label = '⚠️ ' + <?php echo json_encode(get_string('agent_created_no_name', 'local_jedomi')); ?>;
                }
                msg.textContent = label;
                msg.style.display = 'block';
                resetForm();
                loadStats(getFilters());
                loadAgents(getFilters(), currentPage);
                setTimeout(function(){ msg.style.display = 'none'; }, 4000);
            })
            .catch(function(err){
                var msg = document.getElementById('agent-create-msg');
                if (msg) { msg.className = 'alert alert-danger'; msg.textContent = err.message; msg.style.display = 'block'; }
            });
        });
    }

    // ── Preview button ──────────────────────────────────────────────────────
    // ── Preview: clear cache helper (exposed globally for inline onclick) ──
    window.clearPreviewCache = function(hash) {
        var fd = new URLSearchParams();
        fd.set('sesskey', sesskey);
        fd.set('hash', hash);
        return fetch(baseUrl + 'agent_clear_cache.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString()
        }).then(function(r){ return r.json(); });
    };

    // ── Preview: render conversation timeline ───────────────────────────────
    function renderTimeline(conversation) {
        if (!conversation || !conversation.length) return '';
        var html = '<div class="timeline" style="margin-bottom:10px;font-size:.8125rem">'
            + '<strong style="display:block;margin-bottom:6px;color:#374151">Conversation Timeline</strong>';
        conversation.forEach(function(msg, i){
            var role = msg.role || 'unknown';
            var roleLabel = role.charAt(0).toUpperCase() + role.slice(1);
            var roleColor = role === 'user' ? '#2563eb' : (role === 'assistant' ? '#7c3aed' : '#6b7280');
            var roleBg = role === 'user' ? '#eff6ff' : (role === 'assistant' ? '#f5f3ff' : '#f9fafb');
            var content = msg.content ? msg.content.trim() : '';
            html += '<div style="border:1px solid #e5e7eb;border-radius:6px;margin-bottom:4px;overflow:hidden">'
                + '<div style="background:' + roleBg + ';padding:4px 8px;font-weight:600;'
                + 'font-size:.75rem;color:' + roleColor + ';border-bottom:1px solid #e5e7eb">'
                + '<span class="badge" style="background:' + roleColor
                + ';color:#fff;padding:1px 6px;border-radius:3px;margin-right:4px">' + roleLabel + '</span>'
                + ' Round ' + ((msg.round || 0) + 1) + '</div>';
            if (content) {
                html += '<div style="padding:6px 8px;white-space:pre-wrap;word-break:break-word;max-height:200px;overflow-y:auto">'
                    + escHtml(content) + '</div>';
            }
            // ── Tool call arguments (if assistant message had tool_calls) ──
            if (msg.tool_calls && msg.tool_calls.length) {
                html += '<div style="padding:6px 8px;background:#fafafa;border-top:1px solid #e5e7eb">';
                msg.tool_calls.forEach(function(tc){
                    var toolName = tc.function && tc.function.name ? tc.function.name : (tc.name || 'tool');
                    var toolArgs = tc.function && tc.function.arguments ? tc.function.arguments : (tc.arguments || '{}');
                    html += '<details style="margin-bottom:4px">'
                        + '<summary style="cursor:pointer;font-weight:600;color:#2563eb;font-size:.75rem">'
                        + '🛠 ' + esc(toolName) + '</summary>'
                        + '<pre style="background:#f3f4f6;padding:6px;border-radius:4px;'
                        + 'font-size:11px;overflow-x:auto;margin:4px 0 0;max-height:150px"><code>'
                        + esc(typeof toolArgs === 'string' ? toolArgs : JSON.stringify(toolArgs, null, 2))
                        + '</code></pre></details>';
                });
                html += '</div>';
            }
            // ── Tool results ──────────────────────────────────────────────
            if (msg.tool_results && msg.tool_results.length) {
                html += '<div style="padding:6px 8px;border-top:1px solid #e5e7eb">';
                msg.tool_results.forEach(function(tr, ti){
                    html += '<details style="margin-bottom:4px">'
                        + '<summary style="cursor:pointer;font-weight:600;color:#059669;font-size:.75rem">'
                        + '📊 Result ' + (ti + 1) + '</summary>'
                        + '<pre style="background:#ecfdf5;padding:6px;border-radius:4px;'
                        + 'font-size:11px;overflow-x:auto;margin:4px 0 0;max-height:200px"><code>'
                        + esc(typeof tr === 'string' ? tr.substring(0, 5000) : JSON.stringify(tr, null, 2).substring(0, 5000))
                        + '</code></pre></details>';
                });
                html += '</div>';
            }
            html += '</div>';
        });
        html += '</div>';
        return html;
    }

    // ── Preview: render profiler entries ────────────────────────────────────
    function renderProfiler(profiler, totalTimeMs) {
        if (!profiler || !profiler.length) return '';
        var html = '<div class="profiler-entries" style="margin-top:10px">';
        profiler.forEach(function(e){
            var borderColor = e.error ? '#dc3545' : '#ddd';
            html += '<div class="profiler-entry" style="border:1px solid ' + borderColor
                + ';border-radius:6px;padding:8px;margin-bottom:6px;font-size:.8125rem">';
            html += '<div class="profiler-header" '
                + 'style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px">';
            html += '<strong>' + esc(e.tool) + '</strong>';
            html += '<span>' + (e.duration_ms || 0).toFixed(1) + 'ms &middot; ' + (e.row_count || 0) + ' rows</span>';
            if (e.error) {
                html += '<span style="color:#dc3545">❌ ' + esc(e.error) + '</span>';
            }
            html += '</div>';
            // ── Tool result preview (if available) ────────────────────
            if (e.result_preview) {
                html += '<details style="margin-top:4px">'
                    + '<summary style="cursor:pointer;font-size:.75rem;color:#059669">Result preview</summary>';
                html += '<pre style="background:#ecfdf5;padding:6px;border-radius:4px;'
                    + 'font-size:11px;overflow-x:auto;margin:4px 0 0;max-height:200px"><code>'
                    + esc(e.result_preview) + '</code></pre>';
                html += '</details>';
            }
            if (e.arguments) {
                html += '<details style="margin-top:4px">'
                    + '<summary style="cursor:pointer;font-size:.75rem;color:#6b7280">Arguments</summary>';
                html += '<pre style="background:#f5f5f5;padding:6px;border-radius:4px;'
                    + 'font-size:11px;overflow-x:auto;margin:4px 0 0;max-height:200px"><code>'
                    + esc(typeof e.arguments === 'string' ? e.arguments : JSON.stringify(e.arguments, null, 2))
                    + '</code></pre>';
                html += '</details>';
            }
            html += '</div>';
        });
        if (totalTimeMs) {
            html += '<div style="padding:4px 0;font-size:.8125rem;color:#059669;font-weight:600">'
                + '⚡ Total: ' + totalTimeMs.toFixed(1) + 'ms</div>';
        }
        html += '</div>';
        return html;
    }

    var previewBtn2 = document.getElementById('agent-preview-btn');
    if (previewBtn2) {
        var STR_PREVIEW_ERROR = <?php echo json_encode(get_string('agent_preview_error', 'local_jedomi')); ?>;
        var lastPreviewHash = '';
        previewBtn2.addEventListener('click', function(){
            var prompt = document.getElementById('agent-create-prompt');
            if (!prompt || !prompt.value.trim()) {
                var msg = document.getElementById('agent-create-msg');
                if (msg) {
                    msg.className = 'alert alert-warning';
                    msg.textContent = 'Write a prompt first.';
                    msg.style.display = 'block';
                }
                return;
            }
            var panel = document.getElementById('agent-preview-panel');
            var body = document.getElementById('agent-preview-body');
            var spinner = document.getElementById('agent-preview-spinner');
            var separator = document.getElementById('agent-preview-separator');
            if (!panel || !body) return;

            panel.style.display = 'block';
            if (separator) separator.style.display = 'block';
            body.textContent = '';
            if (spinner) spinner.style.display = 'inline';

            var fd = new URLSearchParams();
            fd.set('sesskey', sesskey);
            fd.set('prompt', prompt.value);

            // Compute hash from prompt (same as PHP md5).
            lastPreviewHash = md5(prompt.value.trim());

            fetch(baseUrl + 'agent_preview.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: fd.toString()
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (spinner) spinner.style.display = 'none';
                if (d.ok) {
                    var html = '';
                    // ── Cache indicator with "Clear" button ──────────
                    if (d.cached) {
                        html += '<div class="alert alert-info d-flex align-items-center justify-content-between" '
                            + 'style="padding:8px;margin-bottom:8px;font-size:.8125rem">'
                            + '<span>📦 Cached result from ' + new Date(d.cached_at * 1000).toLocaleString() + '</span>'
                            + '<button type="button" class="btn btn-sm btn-outline-danger" '
                            + 'onclick="clearPreviewCache(\'' + lastPreviewHash + '\')'
                            + '.then(function(r){if(r.ok){document.getElementById(\'agent-preview-btn\').click()}})">'
                            + 'Clear cache</button>'
                            + '</div>';
                    }
                    // ── Conversation timeline ────────────────────────
                    html += renderTimeline(d.conversation);
                    // ── Profiler entries as cards ────────────────────
                    html += renderProfiler(d.profiler, d.total_time_ms);
                    // ── Separator ────────────────────────────────────
                    if (d.profiler && d.profiler.length) {
                        html += '<hr style="border:none;border-top:1px solid #e5e7eb;margin:.5rem 0">';
                    }
                    // ── Answer (scrollable) ─────────────────────────
                    html += '<div class="preview-answer" style="max-height:400px;overflow-y:auto;'
                        + 'border:1px solid #ddd;border-radius:6px;padding:10px;margin-top:8px;'
                        + 'white-space:pre-wrap;word-break:break-word;font-size:.8125rem;color:#1f2937;line-height:1.5">'
                        + esc(d.answer) + '</div>';
                    body.innerHTML = html;
                } else {
                    body.innerHTML = '<div style="color:#dc2626">⚠️ ' + esc(d.error || STR_PREVIEW_ERROR) + '</div>';
                }
            })
            .catch(function(err){
                if (spinner) spinner.style.display = 'none';
                body.innerHTML = '<div style="color:#dc2626">⚠️ ' + esc(err.message) + '</div>';
            });
        });
    }

    // ── MD5 helper (simple for hashing preview prompt) ──────────────────────
    // https://css-tricks.com/snippets/javascript/javascript-md5/
    function md5(s){
        function L(k,d){return(k<<d)|(k>>>(32-d))}
        function K(G,k){var I,d,F,H,x;F=(G&2147483648)+(k&2147483648)|0;I=(G&1073741824)+(k&1073741824)|0;
            d=(G&1073741823)+(k&1073741823)|0;return F^I^(((d&1073741824)>>30)|((d&1073741823)<<2))|0}
        function V(G){var k,d='',F='0123456789abcdef';for(k=7;k>=0;k--){d+=F.charAt((G>>(k*4))&15)}return d}
        var C=Array(80),k=0,d=0,F=0,x=0,H=0,I=0,j=0,Q=0,w=0,l=0,o=0,p=0,t=0,u=0,A=0,N=0,M=-1,B=0,z=0;
        G=unescape(encodeURIComponent(s));s='';
        for(k=0;k<G.length;k++){s+=String.fromCharCode(G.charCodeAt(k))}
        var Y=s.length;var Z=[1732584193,-271733879,-1732584194,271733878];var a;
        for(k=0;k<=Y+72;k+=512){var c,Z2=Z[0],E=Z[1],m=Z[2],n=Z[3];
            for(j=0;j<16;j++){C[j]=(s.charCodeAt(B)||0)+(s.charCodeAt(B+1)||0)*256+(s.charCodeAt(B+2)||0)*65536
                +(s.charCodeAt(B+3)||0)*16777216;B+=4}
            var W;for(j=16;j<80;j++){C[j]=L(C[j-3]^C[j-8]^C[j-14]^C[j-16],1)}
            for(j=0;j<80;j++){if(j<20){a=(E&m)|((~E)&n)}else if(j<40){a=E^m^n}
                else if(j<60){a=(E&m)|(E&n)|(m&n)}else{a=E^m^n}
                W=K(K(L(Z2,5),a),K(K(0,C[j]),j<20?1518500249:j<40?1859775393:j<60?2400959708:-1894007588));
                a=n;n=m;m=L(E,30);E=Z2;Z2=W}
            Z[0]=K(Z2,Z[0]);Z[1]=K(E,Z[1]);Z[2]=K(m,Z[2]);Z[3]=K(n,Z[3])}
        return V(Z[0])+V(Z[1])+V(Z[2])+V(Z[3])}

    // ── Purge form ────────────────────────────────────────────────────────
    var purgeForm = document.getElementById('agent-purge-form');
    if (purgeForm) {
        purgeForm.addEventListener('submit', function(e){
            e.preventDefault();
            confirmDeleteModal(
                <?php echo json_encode(get_string('agent_purge', 'local_jedomi')); ?>,
                <?php echo json_encode(get_string('agent_purge_confirm', 'local_jedomi')); ?>,
                doPurge
            );
        });

        function doPurge() {
            var fd = new FormData(purgeForm);
            fd.set('sesskey', sesskey);

            fetch(baseUrl + 'agent_purge.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(fd).toString()
            })
            .then(function(r){ return r.json(); })
            .then(function(d){
                var msg = document.getElementById('agent-purge-msg');
                if (!msg) return;
                if (d.error) {
                    msg.className = 'alert alert-danger';
                    msg.textContent = d.error || <?php echo json_encode(get_string('agent_error_load', 'local_jedomi')); ?>;
                    msg.style.display = 'block';
                    return;
                }
                msg.className = 'alert alert-success';
                msg.textContent = <?php echo json_encode(get_string('agent_purge_success', 'local_jedomi')); ?>;
                msg.style.display = 'block';
                purgeForm.reset();
                loadStats(getFilters());
                loadAgents(getFilters(), currentPage);
                setTimeout(function(){ msg.style.display = 'none'; }, 4000);
            })
            .catch(function(err){
                var msg = document.getElementById('agent-purge-msg');
                if (msg) { msg.className = 'alert alert-danger'; msg.textContent = err.message; msg.style.display = 'block'; }
            });
        }
    }

    // ── Schedule: show/hide custom cron input ────────────────────────────
    var schedSel = document.getElementById('agent-schedule-select');
    if (schedSel) {
        schedSel.addEventListener('change', function(){
            var wrap = document.getElementById('agent-custom-cron-wrap');
            if (wrap) wrap.style.display = this.value === 'custom' ? 'block' : 'none';
        });
    }

    // ── Create context type: show/hide context_id input ──────────────────
    var ctxType = document.getElementById('agent-create-context-type');
    if (ctxType) {
        ctxType.addEventListener('change', function(){
            var wrap = document.getElementById('agent-create-context-id-wrap');
            if (wrap) wrap.style.display = this.value === 'site' ? 'none' : 'block';
        });
    }

    // ── Purge context type: show/hide context_id input ───────────────────
    var purgeCtx = document.getElementById('agent-purge-context-type');
    if (purgeCtx) {
        purgeCtx.addEventListener('change', function(){
            var wrap = document.getElementById('agent-purge-context-id-wrap');
            if (wrap) wrap.style.display = (this.value === 'site' || this.value === 'role') ? 'none' : 'block';
        });
    }

    // ── Handle ?edit_agent=X from profiler links ─────────────────────────
    (function(){
        var params = new URLSearchParams(window.location.search);
        var editId = params.get('edit_agent');
        if (editId) {
            // Wait for table to load, then trigger the edit button.
            var check = setInterval(function(){
                var btn = document.querySelector('[data-agent-action="edit"][data-agent-id="' + editId + '"]');
                if (btn) {
                    clearInterval(check);
                    btn.click();
                    btn.scrollIntoView({behavior:'smooth', block:'center'});
                    // Remove the param from URL without reloading.
                    params.delete('edit_agent');
                    var newUrl = window.location.pathname + '?' + params.toString();
                    window.history.replaceState(null, '', newUrl);
                }
            }, 500);
            // Safety: stop checking after 15 seconds.
            setTimeout(function(){ clearInterval(check); }, 15000);
        }
    })();

    // ── Initial load ─────────────────────────────────────────────────────
    loadStats(null, function(){ loadAgents(null, 1); });
})();
</script>
<?php endif; ?>

<?php // TAB: PROFILER ──────────────────────────────────────────────────────────────. ?>
<?php if ($tab === 'profiler') : ?>
<div style="max-width:780px; margin:0 auto; padding-bottom:2rem">

<!-- ── Query Profiler Card ──────────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div class="card-icon" style="background:#fef3c7"><i class="fa fa-tachometer-alt" style="color:#d97706"></i></div>
        <div>
            <h5><?php echo get_string('query_profiler_heading', 'local_jedomi'); ?></h5>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted small"><?php echo get_string('query_profiler_desc', 'local_jedomi'); ?></p>

        <!-- Aggregate stats -->
        <?php $qpagg = \local_jedomi\agent\query_profiler::get_aggregate(); ?>
        <div class="row text-center mb-3">
            <div class="col-md-3">
                <div class="badge badge-info" style="font-size:1rem;padding:.5rem 1rem">
                    <?php echo $qpagg->total_queries; ?>
                </div>
                <div class="small text-muted mt-1"><?php echo get_string('query_profiler_total', 'local_jedomi'); ?></div>
            </div>
            <div class="col-md-3">
                <div class="badge badge-warning" style="font-size:1rem;padding:.5rem 1rem">
                    <?php echo $qpagg->slow_queries; ?>
                </div>
                <div class="small text-muted mt-1"><?php echo get_string('query_profiler_slow', 'local_jedomi'); ?></div>
            </div>
            <div class="col-md-3">
                <div class="badge badge-secondary" style="font-size:1rem;padding:.5rem 1rem">
                    <?php echo $qpagg->avg_duration_ms; ?> ms
                </div>
                <div class="small text-muted mt-1"><?php echo get_string('query_profiler_avg', 'local_jedomi'); ?></div>
            </div>
            <div class="col-md-3">
                <div class="badge badge-danger" style="font-size:1rem;padding:.5rem 1rem">
                    <?php echo $qpagg->max_duration_ms; ?> ms
                </div>
                <div class="small text-muted mt-1"><?php echo get_string('query_profiler_max', 'local_jedomi'); ?></div>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="get" action="<?php echo $pageurl->out(false); ?>" class="form-inline mb-3">
            <input type="hidden" name="tab" value="profiler">
            <input type="hidden" name="qp_page" value="1">
            <div class="row w-100">
                <div class="col-md-4">
                    <input type="number" name="qp_min_ms" class="form-control form-control-sm" style="width:100%"
                           placeholder="Min duration (ms)" value="<?php echo optional_param('qp_min_ms', '', PARAM_INT); ?>">
                </div>
                <div class="col-md-2">
                    <select name="qp_sort" class="form-control form-control-sm" style="width:100%">
                        <option value="duration_ms" <?php echo (optional_param('qp_sort', 'duration_ms', PARAM_ALPHA)
                            === 'duration_ms') ? 'selected' : ''; ?>>
                            <?php echo get_string('query_profiler_sort_duration', 'local_jedomi'); ?>
                        </option>
                        <option value="timecreated" <?php echo (optional_param('qp_sort', 'duration_ms', PARAM_ALPHA)
                            === 'timecreated') ? 'selected' : ''; ?>>
                            <?php echo get_string('query_profiler_sort_time', 'local_jedomi'); ?>
                        </option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary" style="width:100%">
                        <i class="fa fa-filter mr-1"></i><?php echo get_string('agent_apply_filters', 'local_jedomi'); ?>
                    </button>
                </div>
            </div>
        </form>

        <!-- Slowest queries (top 10) -->
        <?php if (!empty($qpagg->slowest)) : ?>
        <h6 class="mt-3"><?php echo get_string('query_profiler_slowest', 'local_jedomi'); ?></h6>
        <div style="overflow-x:auto">
            <table class="table table-sm table-hover" style="font-size:.8rem">
                <thead>
                    <tr>
                        <th><?php echo get_string('query_profiler_sort_query', 'local_jedomi'); ?></th>
                        <th style="width:100px"><?php echo get_string('query_profiler_avg', 'local_jedomi'); ?></th>
                        <th style="width:100px"><?php echo get_string('query_profiler_max', 'local_jedomi'); ?></th>
                        <th style="width:80px">#</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($qpagg->slowest as $sl) : ?>
                    <tr>
                        <td><code style="font-size:.75rem;word-break:break-all"><?php
                            echo s(mb_substr($sl->query, 0, 120)); ?></code></td>
                        <td class="text-danger"><?php echo round($sl->avg_duration, 2); ?> ms</td>
                        <td><?php echo round($sl->max_duration, 2); ?> ms</td>
                        <td><?php echo $sl->exec_count; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else : ?>
        <div class="alert alert-info"><?php echo get_string('query_profiler_empty', 'local_jedomi'); ?></div>
        <?php endif; ?>

        <!-- Recent queries -->
        <?php
        $qprecent = \local_jedomi\agent\query_profiler::get_log(
            ['sort' => 'timecreated', 'dir' => 'DESC', 'perpage' => 20]
        );
        ?>
        <h6 class="mt-4"><?php echo get_string('query_profiler_recent', 'local_jedomi'); ?></h6>
        <?php if (!empty($qprecent['entries'])) : ?>
        <div style="overflow-x:auto">
            <table class="table table-sm table-hover" style="font-size:.8rem">
                <thead>
                    <tr>
                        <th style="width:60px">ID</th>
                        <th style="width:80px"><?php echo get_string('agent_creator', 'local_jedomi'); ?></th>
                        <th><?php echo get_string('query_profiler_sort_query', 'local_jedomi'); ?></th>
                        <th style="width:70px"><?php echo get_string('query_profiler_sort_duration', 'local_jedomi'); ?></th>
                        <th style="width:60px">Filas</th>
                        <th style="width:80px"><?php echo get_string('query_profiler_sort_time', 'local_jedomi'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($qprecent['entries'] as $qe) :
                        $qagent = $DB->get_record('local_jedomi_agents', ['id' => $qe->agentid]);
                        $quser = $qagent ? $DB->get_record('user', ['id' => $qagent->userid]) : null;
                        $agentlink = new \moodle_url(
                            '/local/jedomi/admin_settings.php',
                            ['tab' => 'agents', 'edit_agent' => $qe->agentid]
                        );
                    ?>
                    <tr>
                        <td><a href="<?php echo $agentlink->out(false); ?>" class="badge badge-info"
                               style="font-size:.75rem">Agent #<?php echo $qe->agentid; ?></a></td>
                        <td><?php echo $quser ? s(fullname($quser)) : '?'; ?></td>
                        <td><code style="font-size:.7rem;word-break:break-all"><?php
                            echo s(mb_substr($qe->query, 0, 100)); ?></code></td>
                        <td class="<?php echo $qe->duration_ms > 1000 ? 'text-danger' : ''; ?>"><?php
                            echo round($qe->duration_ms, 2); ?> ms</td>
                        <td><?php echo $qe->rowcount; ?></td>
                        <td><?php echo userdate($qe->timecreated, get_string('strftimedatetime', 'langconfig')); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else : ?>
        <div class="alert alert-info mt-3"><?php echo get_string('query_profiler_empty', 'local_jedomi'); ?></div>
        <?php endif; ?>
    </div>
</div>

</div>
<?php endif; ?>
<?php
echo $OUTPUT->footer();
