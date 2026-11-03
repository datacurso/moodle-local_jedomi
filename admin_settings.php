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
 * Jedomi settings page: one tab per settings area, each rendered from a renderable and a template.
 *
 * Tabs: general, courses, privacy, integrations, apis, agents and profiler.
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use local_jedomi\form\course_settings_form;
use local_jedomi\form\general_settings_form;
use local_jedomi\form\privacy_settings_form;
use local_jedomi\form\telegram_settings_form;
use local_jedomi\local\service\admin_settings_service;
use local_jedomi\output\admin\agents_tab;
use local_jedomi\output\admin\apis_tab;
use local_jedomi\output\admin\courses_tab;
use local_jedomi\output\admin\general_tab;
use local_jedomi\output\admin\integrations_tab;
use local_jedomi\output\admin\privacy_tab;
use local_jedomi\output\admin\profiler_tab;

// Tab key => lang string of its label.
$tabs = [
    'general' => 'tab_general',
    'courses' => 'course_availability_heading',
    'privacy' => 'tab_privacy',
    'integrations' => 'tab_integrations',
    'apis' => 'tab_apis',
    'agents' => 'tab_agents',
    'profiler' => 'tab_profiler',
];
$tab = optional_param('tab', 'general', PARAM_ALPHA);
if (!array_key_exists($tab, $tabs)) {
    $tab = 'general';
}

admin_externalpage_setup('local_jedomi', '', ['tab' => $tab]);
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$PAGE->set_heading(get_string('pluginname', 'local_jedomi'));

$pageurl = new moodle_url('/local/jedomi/admin_settings.php', ['tab' => $tab]);
$saved = get_string('changessaved');

switch ($tab) {
    case 'courses':
        $form = new course_settings_form($pageurl);
        if ($data = $form->get_data()) {
            require_sesskey();
            admin_settings_service::save_course_settings($data->course_mode, (array) ($data->course_ids ?? []));
            redirect($pageurl, $saved, null, notification::NOTIFY_SUCCESS);
        }
        $form->set_data(admin_settings_service::get_course_settings());
        $content = new courses_tab($form->render());
        break;

    case 'privacy':
        $roles = admin_settings_service::get_roles();
        $form = new privacy_settings_form($pageurl, ['roles' => $roles]);
        if ($data = $form->get_data()) {
            require_sesskey();
            admin_settings_service::save_privacy_settings(
                !empty($data->hide_from_guests),
                $data->anonymization_level,
                privacy_settings_form::visible_role_ids($data, $roles)
            );
            redirect($pageurl, $saved, null, notification::NOTIFY_SUCCESS);
        }
        $privacy = admin_settings_service::get_privacy_settings();
        $defaults = ['hide_from_guests' => $privacy['hide_from_guests'], 'anonymization_level' => $privacy['anonymization_level']];
        foreach ($roles as $role) {
            $defaults['role_visible_' . $role['id']] = (int) !in_array($role['id'], $privacy['hidden_role_ids'], true);
        }
        $form->set_data($defaults);
        $content = new privacy_tab($form->render());
        break;

    case 'integrations':
        $roles = admin_settings_service::get_roles();
        $telegram = admin_settings_service::get_telegram_settings();
        $form = new telegram_settings_form($pageurl, ['roles' => $roles, 'tokenset' => $telegram['bot_token_set']]);
        if ($data = $form->get_data()) {
            require_sesskey();
            $token = trim((string) ($data->telegram_bot_token ?? ''));
            if ($token !== '' && !admin_settings_service::verify_and_save_telegram_token($token)) {
                redirect($pageurl, get_string('telegram_msg_token_invalid', 'local_jedomi'), null, notification::NOTIFY_ERROR);
            }
            if (!empty($data->regeneratesecret)) {
                // Rotates the secret and registers the new webhook URL in Telegram.
                \local_jedomi\telegram\manager::regenerate_webhook_secret();
            }
            admin_settings_service::save_telegram_settings(
                (int) $data->telegram_rate_limit,
                (int) $data->telegram_cooldown,
                (int) $data->telegram_max_turns,
                (int) $data->telegram_gateway_timeout,
                telegram_settings_form::visible_role_ids($data, $roles)
            );
            redirect($pageurl, $saved, null, notification::NOTIFY_SUCCESS);
        }
        $defaults = [
            'telegram_rate_limit' => $telegram['rate_limit'],
            'telegram_cooldown' => $telegram['cooldown'],
            'telegram_max_turns' => $telegram['max_turns'],
            'telegram_gateway_timeout' => $telegram['gateway_timeout'],
        ];
        foreach ($roles as $role) {
            $defaults['telegram_role_visible_' . $role['id']] = (int) !in_array($role['id'], $telegram['hidden_role_ids'], true);
        }
        $form->set_data($defaults);
        $content = new integrations_tab($telegram, $form->render());
        break;

    case 'apis':
        if (optional_param('action', '', PARAM_ALPHA) === 'saveallowlist') {
            require_sesskey();
            $role = admin_settings_service::normalise_ws_role(required_param('api_role', PARAM_ALPHA));
            admin_settings_service::save_ws_allowlist($role, optional_param_array('ws_' . $role, [], PARAM_ALPHANUMEXT));
            redirect(new moodle_url($pageurl, ['role' => $role]), $saved, null, notification::NOTIFY_SUCCESS);
        }
        $role = admin_settings_service::normalise_ws_role(optional_param('role', 'admin', PARAM_ALPHA));
        $search = optional_param('ap_search', '', PARAM_TEXT);
        $component = optional_param('ap_component', '', PARAM_COMPONENT);
        $type = optional_param('ap_type', 'all', PARAM_ALPHA);
        $type = in_array($type, admin_settings_service::WS_TYPES, true) ? $type : 'all';
        $perpage = max(10, min(200, optional_param('ap_perpage', 25, PARAM_INT)));
        $table = admin_settings_service::get_ws_table(
            $role,
            $search,
            $component,
            $type,
            optional_param('ap_page', 1, PARAM_INT),
            $perpage
        );
        $content = new apis_tab($role, $table, admin_settings_service::get_ws_components(), $search, $component, $type, $perpage);
        break;

    case 'agents':
        $content = new agents_tab(admin_settings_service::get_roles(), optional_param('edit_agent', 0, PARAM_INT));
        break;

    case 'profiler':
        $minms = max(0, optional_param('qp_min_ms', 0, PARAM_INT));
        $sort = optional_param('qp_sort', 'timecreated', PARAM_ALPHAEXT) === 'duration_ms' ? 'duration_ms' : 'timecreated';
        $content = new profiler_tab(
            \local_jedomi\agent\query_profiler::get_aggregate(),
            admin_settings_service::get_recent_profiler_entries($minms, $sort),
            $minms,
            $sort
        );
        break;

    default:
        $form = new general_settings_form($pageurl);
        if ($data = $form->get_data()) {
            require_sesskey();
            admin_settings_service::save_general_settings($data->spt_secret, !empty($data->collect_training_data));
            redirect($pageurl, $saved, null, notification::NOTIFY_SUCCESS);
        }
        $form->set_data(admin_settings_service::get_general_settings());
        $providerurl = new moodle_url('/ai/provider/datacurso/admin/plugin_settings.php', ['component' => 'local_jedomi']);
        $content = new general_tab($providerurl->out(false), $form->render());
        break;
}

$tabobjects = [];
foreach ($tabs as $key => $labelkey) {
    $tabobjects[] = new tabobject(
        $key,
        new moodle_url('/local/jedomi/admin_settings.php', ['tab' => $key]),
        get_string($labelkey, 'local_jedomi')
    );
}

$renderer = $PAGE->get_renderer('local_jedomi');
echo $OUTPUT->header();
echo $OUTPUT->tabtree($tabobjects, $tab);
echo $renderer->render($content);
echo $OUTPUT->footer();
