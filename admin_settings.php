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
 * Tabs: general, courses, privacy, integrations, apis, agents, profiler and personalization.
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use core_reportbuilder\system_report_factory;
use local_jedomi\form\course_settings_form;
use local_jedomi\form\general_settings_form;
use local_jedomi\form\personalization_settings_form;
use local_jedomi\form\privacy_settings_form;
use local_jedomi\form\telegram_settings_form;
use local_jedomi\local\service\admin_settings_service;
use local_jedomi\output\admin\agents_tab;
use local_jedomi\output\admin\apis_tab;
use local_jedomi\output\admin\courses_tab;
use local_jedomi\output\admin\general_tab;
use local_jedomi\output\admin\integrations_tab;
use local_jedomi\output\admin\personalization_tab;
use local_jedomi\output\admin\privacy_tab;
use local_jedomi\output\admin\profiler_tab;
use local_jedomi\reportbuilder\local\helpers\filtered_ids;
use local_jedomi\reportbuilder\local\systemreports\agents as agents_report;
use local_jedomi\reportbuilder\local\systemreports\query_log as query_log_report;
use local_jedomi\reportbuilder\local\systemreports\query_log_aggregate as query_log_aggregate_report;

// Tab key => lang string of its label.
$tabs = [
    'general' => 'tab_general',
    'courses' => 'course_availability_heading',
    'privacy' => 'tab_privacy',
    'integrations' => 'tab_integrations',
    'apis' => 'tab_apis',
    'agents' => 'tab_agents',
    'profiler' => 'tab_profiler',
    'personalization' => 'tab_personalization',
];
$tab = optional_param('tab', 'general', PARAM_ALPHA);
if (!array_key_exists($tab, $tabs)) {
    $tab = 'general';
}

$context = context_system::instance();
require_login(null, false);
// The permission to manage agents gives access to the Agents tab, the same permission its actions require.
// Users who manage agents but cannot configure the site only get that tab.
$agentsonly = !has_capability('moodle/site:config', $context) && has_capability('local/jedomi:manageagents', $context);
if ($agentsonly) {
    $tabs = ['agents' => $tabs['agents']];
    $tab = 'agents';
    $PAGE->set_context($context);
    $PAGE->set_url(new moodle_url('/local/jedomi/admin_settings.php', ['tab' => $tab]));
    $PAGE->set_pagelayout('admin');
    $PAGE->set_title(get_string('tab_agents', 'local_jedomi'));
} else {
    admin_externalpage_setup('local_jedomi', '', ['tab' => $tab]);
    require_capability('moodle/site:config', $context);
}
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
                $data->anonymization_level,
                privacy_settings_form::visible_role_ids($data, $roles)
            );
            redirect($pageurl, $saved, null, notification::NOTIFY_SUCCESS);
        }
        $privacy = admin_settings_service::get_privacy_settings();
        $defaults = ['anonymization_level' => $privacy['anonymization_level']];
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
        if ($form->no_submit_button_pressed() && optional_param('regeneratesecret', '', PARAM_RAW) !== '') {
            // Regenerate secret is independent of the form: nothing else typed in the form is saved.
            require_sesskey();
            \local_jedomi\telegram\manager::regenerate_webhook_secret();
            $registered = \local_jedomi\telegram\manager::get_last_webhook_error() === '';
            $key = $registered ? 'telegram_secret_regenerated' : 'telegram_secret_regenerated_unregistered';
            redirect(
                $pageurl,
                get_string($key, 'local_jedomi'),
                null,
                $registered ? notification::NOTIFY_SUCCESS : notification::NOTIFY_WARNING
            );
        }
        if ($data = $form->get_data()) {
            require_sesskey();
            $message = $saved;
            $type = notification::NOTIFY_SUCCESS;
            $token = trim((string) ($data->telegram_bot_token ?? ''));
            if (!empty($data->telegram_remove_token)) {
                if (!admin_settings_service::remove_telegram_token()) {
                    // The token could not be decrypted, so its webhook could not be deleted in Telegram.
                    $message = get_string('telegram_msg_token_removed_webhook_kept', 'local_jedomi');
                    $type = notification::NOTIFY_WARNING;
                } else {
                    $message = get_string('telegram_msg_token_removed', 'local_jedomi');
                }
            } else if ($token !== '' && !admin_settings_service::verify_and_save_telegram_token($token)) {
                // The rejected token does not leave the previous one in use; the other changes are still saved.
                $message = get_string('telegram_msg_token_invalid', 'local_jedomi');
                $type = notification::NOTIFY_ERROR;
            }
            admin_settings_service::save_telegram_settings(
                (int) $data->telegram_rate_limit,
                (int) $data->telegram_cooldown,
                (int) $data->telegram_max_turns,
                (int) $data->telegram_gateway_timeout,
                telegram_settings_form::visible_role_ids($data, $roles),
                !empty($data->telegram_require_pin)
            );
            redirect($pageurl, $message, null, $type);
        }
        $defaults = [
            'telegram_rate_limit' => $telegram['rate_limit'],
            'telegram_cooldown' => $telegram['cooldown'],
            'telegram_max_turns' => $telegram['max_turns'],
            'telegram_gateway_timeout' => $telegram['gateway_timeout'],
            'telegram_require_pin' => $telegram['require_pin'],
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
            // Only the functions shown when saving change; the ones allowed on other pages or filters are kept.
            admin_settings_service::save_ws_allowlist(
                $role,
                optional_param_array('ws_' . $role, [], PARAM_ALPHANUMEXT),
                optional_param_array('ws_' . $role . '_shown', [], PARAM_ALPHANUMEXT)
            );
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
        $report = system_report_factory::create(agents_report::class, $context);
        $content = new agents_tab(
            admin_settings_service::get_roles(),
            optional_param('edit_agent', 0, PARAM_INT),
            $report->output()
        );
        break;

    case 'profiler':
        // The indicators follow the filters of the recent queries report, as the profiler module does when they change.
        $recent = system_report_factory::create(query_log_report::class, $context);
        $slowest = system_report_factory::create(query_log_aggregate_report::class, $context);
        $content = new profiler_tab(
            \local_jedomi\agent\query_profiler::get_aggregate(null, ['idsql' => filtered_ids::sql($recent)]),
            $recent->output(),
            $slowest->output()
        );
        break;

    case 'personalization':
        $form = new personalization_settings_form($pageurl);
        if ($data = $form->get_data()) {
            require_sesskey();
            admin_settings_service::save_personalization_settings(
                (string) $data->avatar,
                personalization_settings_form::position($data),
                (string) $data->assistant_name,
                (string) $data->welcome_message,
                (string) $data->custom_prompt,
                (int) $data->customavatar
            );
            redirect($pageurl, $saved, null, notification::NOTIFY_SUCCESS);
        }
        $form->set_data(personalization_settings_form::defaults(admin_settings_service::get_personalization_settings()));
        $content = new personalization_tab($form->render());
        break;

    default:
        $form = new general_settings_form($pageurl);
        if ($data = $form->get_data()) {
            require_sesskey();
            admin_settings_service::save_general_settings(
                (string) $data->spt_secret,
                (int) $data->profiler_slow_ms,
                (int) $data->profiler_retention_days,
                (int) $data->sql_row_limit_web,
                (int) $data->chat_rate_limit
            );
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
