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
 * local_jedomi: AI sidebar for Moodle.
 *
 * Theme-proof injection strategy:
 *  1. The standard before_footer hook tries to inject the sidebar.
 *  2. The AMD module sidebar.js checks whether the HTML exists and otherwise injects it straight into
 *     document.body, which avoids any theme problem.
 *  3. The CSS uses position:fixed !important and z-index:99999 to survive any theme.
 *  4. It fixes body overflow:hidden, which breaks fixed positioning.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Legacy before_footer callback — now handled by the hook system.
 *
 * Kept as an empty function so Moodle's get_plugins_with_function('before_footer')
 * finds it and does NOT log a missing-migration warning.
 *
 * All sidebar injection logic moved to:
 *   \local_jedomi\hook_callbacks::before_footer()
 * registered via db/hooks.php for the core\hook\output\before_footer_html_generation hook.
 *
 * @return string Always empty — sidebar is injected via AMD module.
 */
function local_jedomi_before_footer(): string {
    // Logic moved to \local_jedomi\hook_callbacks::before_footer.
    return '';
}

/**
 * Build the full sidebar config array (strings, connection info, SPT, etc.).
 *
 * @return array
 */
function local_jedomi_get_sidebar_config(): array {
    global $USER, $CFG;

    $context = local_jedomi_get_page_context();

    return [
        'gateway_url'   => get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765',
        // SECURITY: the backend API key is NOT sent to the browser. The main chat goes through chatproxy.php
        // (server side); the features that called the gateway from the client (transcription, file extraction,
        // direct request) stay disabled because the client has no key.
        'api_key'       => '',
        'model'         => get_config('local_jedomi', 'model') ?: 'jedomi-e4b',
        'chatproxy_url' => $CFG->wwwroot . '/local/jedomi/chatproxy.php',
        'user_name'     => fullname($USER),
        'user_role'     => local_jedomi_get_user_role(),
        'wwwroot'       => $CFG->wwwroot,
        'context'       => $context,
        'sesskey'       => sesskey(),
        'strings'       => [
            // UI chrome.
            'title'             => get_string('sidebar_title', 'local_jedomi'),
            'open_sidebar'      => get_string('open_sidebar', 'local_jedomi'),
            'close'             => get_string('close', 'local_jedomi'),
            'new_conversation'  => get_string('new_conversation', 'local_jedomi'),
            'export_conversation' => get_string('export_conversation', 'local_jedomi'),
            'toggle_thinking'   => get_string('toggle_thinking', 'local_jedomi'),
            'half_width'        => get_string('half_width', 'local_jedomi'),
            'full_width'        => get_string('full_width', 'local_jedomi'),
            // Input.
            'attach'            => get_string('attach', 'local_jedomi'),
            'attach_file'       => get_string('attach_file', 'local_jedomi'),
            'attach_image'      => get_string('attach_image', 'local_jedomi'),
            'file'              => get_string('file', 'local_jedomi'),
            'image'             => get_string('image', 'local_jedomi'),
            'message_input'     => get_string('message_input', 'local_jedomi'),
            'chat_placeholder'  => get_string('chat_placeholder', 'local_jedomi'),
            'send'              => get_string('send', 'local_jedomi'),
            'voice_input'       => get_string('voice_input', 'local_jedomi'),
            // Greeting.
            'greeting'          => get_string('greeting', 'local_jedomi'),
            'hello'             => get_string(local_jedomi_get_user_role() === 'student' ? 'hello_student' : 'hello', 'local_jedomi'),
            'thinking'          => get_string('thinking', 'local_jedomi'),
            'new_conversation_msg' => get_string('new_conversation_msg', 'local_jedomi'),
            // Files.
            'analyze_image'        => get_string('analyze_image', 'local_jedomi'),
            'attached_file_info'   => get_string('attached_file_info', 'local_jedomi'),
            'attached_image'       => get_string('attached_image', 'local_jedomi'),
            'csv_download'         => get_string('csv_download', 'local_jedomi'),
            'file_csv'             => get_string('file_csv', 'local_jedomi'),
            'file_loaded'          => get_string('file_loaded', 'local_jedomi'),
            'file_loaded_hint'     => get_string('file_loaded_hint', 'local_jedomi'),
            'file_pdf'             => get_string('file_pdf', 'local_jedomi'),
            'file_process_error'   => get_string('file_process_error', 'local_jedomi'),
            'file_text'            => get_string('file_text', 'local_jedomi'),
            'processing_file'      => get_string('processing_file', 'local_jedomi'),
            // Footer / disclaimer.
            'ai_disclaimer'     => get_string('ai_disclaimer', 'local_jedomi'),
            'drop_files_here'   => get_string('drop_files_here', 'local_jedomi'),
            // Tools.
            'tool_sql_read'          => get_string('tool_sql_read', 'local_jedomi'),
            'tool_api_call'          => get_string('tool_api_call', 'local_jedomi'),
            'tool_schema_lookup'     => get_string('tool_schema_lookup', 'local_jedomi'),
            'tool_webservice_lookup' => get_string('tool_webservice_lookup', 'local_jedomi'),
            // Message actions.
            'action_copy'          => get_string('action_copy', 'local_jedomi'),
            'action_helpful'       => get_string('action_helpful', 'local_jedomi'),
            'action_improve'       => get_string('action_improve', 'local_jedomi'),
            'action_improve_title' => get_string('action_improve_title', 'local_jedomi'),
            'action_edit'          => get_string('action_edit', 'local_jedomi'),
            'action_edit_title'    => get_string('action_edit_title', 'local_jedomi'),
            // Bulk message.
            'confirm_send_bulk'        => get_string('confirm_send_bulk', 'local_jedomi'),
            'yes_send'                 => get_string('yes_send', 'local_jedomi'),
            'cancel'                   => get_string('cancel', 'local_jedomi'),
            'sending'                  => get_string('sending', 'local_jedomi'),
            'cancelled'                => get_string('cancelled', 'local_jedomi'),
            'messages_sent'            => get_string('messages_sent', 'local_jedomi'),
            'messages_sent_fail'       => get_string('messages_sent_fail', 'local_jedomi'),
            'messages_recipients_note' => get_string('messages_recipients_note', 'local_jedomi'),
            'no_messages_sent'         => get_string('no_messages_sent', 'local_jedomi'),
            'unknown_error'            => get_string('unknown_error', 'local_jedomi'),
            'connection_error'         => get_string('connection_error', 'local_jedomi'),
            // Context labels.
            'ctx_activity'  => get_string('ctx_activity', 'local_jedomi'),
            'ctx_admin'     => get_string('ctx_admin', 'local_jedomi'),
            'ctx_calendar'  => get_string('ctx_calendar', 'local_jedomi'),
            'ctx_course'    => get_string('ctx_course', 'local_jedomi'),
            'ctx_dashboard' => get_string('ctx_dashboard', 'local_jedomi'),
            'ctx_files'     => get_string('ctx_files', 'local_jedomi'),
            'ctx_grades'    => get_string('ctx_grades', 'local_jedomi'),
            'ctx_messages'  => get_string('ctx_messages', 'local_jedomi'),
            'ctx_profile'   => get_string('ctx_profile', 'local_jedomi'),
            // Errors.
            'gateway_error_title'   => get_string('gateway_error_title', 'local_jedomi'),
            'gateway_error_hint'    => get_string('gateway_error_hint', 'local_jedomi'),
            'listening'             => get_string('listening', 'local_jedomi'),
            'mic_error'             => get_string('mic_error', 'local_jedomi'),
            'silence_detected'      => get_string('silence_detected', 'local_jedomi'),
            'transcribing'          => get_string('transcribing', 'local_jedomi'),
            'waiting_voice'         => get_string('waiting_voice', 'local_jedomi'),
            // Confirmation system.
            'confirm_type_to_proceed' => get_string('confirm_type_to_proceed', 'local_jedomi'),
            'confirm_btn_yes'         => get_string('confirm_btn_yes', 'local_jedomi'),
            'confirm_btn_no'          => get_string('confirm_btn_no', 'local_jedomi'),
            'confirm_executing'       => get_string('confirm_executing', 'local_jedomi'),
            'confirm_done'            => get_string('confirm_done', 'local_jedomi'),
            'confirm_cancelled'       => get_string('confirm_cancelled', 'local_jedomi'),
            // Quick actions: Admin.
            'qa_admin_active_users_label'    => get_string('qa_admin_active_users_label', 'local_jedomi'),
            'qa_admin_active_users_msg'      => get_string('qa_admin_active_users_msg', 'local_jedomi'),
            'qa_admin_alerts_label'          => get_string('qa_admin_alerts_label', 'local_jedomi'),
            'qa_admin_alerts_msg'            => get_string('qa_admin_alerts_msg', 'local_jedomi'),
            'qa_admin_at_risk_label'         => get_string('qa_admin_at_risk_label', 'local_jedomi'),
            'qa_admin_at_risk_msg'           => get_string('qa_admin_at_risk_msg', 'local_jedomi'),
            'qa_admin_empty_cohorts_label'   => get_string('qa_admin_empty_cohorts_label', 'local_jedomi'),
            'qa_admin_empty_cohorts_msg'     => get_string('qa_admin_empty_cohorts_msg', 'local_jedomi'),
            'qa_admin_external_emails_label' => get_string('qa_admin_external_emails_label', 'local_jedomi'),
            'qa_admin_external_emails_msg'   => get_string('qa_admin_external_emails_msg', 'local_jedomi'),
            'qa_admin_gdpr_pending_label'    => get_string('qa_admin_gdpr_pending_label', 'local_jedomi'),
            'qa_admin_gdpr_pending_msg'      => get_string('qa_admin_gdpr_pending_msg', 'local_jedomi'),
            'qa_admin_inactive_label'        => get_string('qa_admin_inactive_label', 'local_jedomi'),
            'qa_admin_inactive_msg'          => get_string('qa_admin_inactive_msg', 'local_jedomi'),
            'qa_admin_missing_work_label'    => get_string('qa_admin_missing_work_label', 'local_jedomi'),
            'qa_admin_missing_work_msg'      => get_string('qa_admin_missing_work_msg', 'local_jedomi'),
            'qa_admin_overview_label'        => get_string('qa_admin_overview_label', 'local_jedomi'),
            'qa_admin_overview_msg'          => get_string('qa_admin_overview_msg', 'local_jedomi'),
            'qa_admin_performance_label'     => get_string('qa_admin_performance_label', 'local_jedomi'),
            'qa_admin_performance_msg'       => get_string('qa_admin_performance_msg', 'local_jedomi'),
            'qa_admin_security_label'        => get_string('qa_admin_security_label', 'local_jedomi'),
            'qa_admin_security_msg'          => get_string('qa_admin_security_msg', 'local_jedomi'),
            'qa_admin_storage_label'         => get_string('qa_admin_storage_label', 'local_jedomi'),
            'qa_admin_storage_msg'           => get_string('qa_admin_storage_msg', 'local_jedomi'),
            'qa_admin_top_courses_label'     => get_string('qa_admin_top_courses_label', 'local_jedomi'),
            'qa_admin_top_courses_msg'       => get_string('qa_admin_top_courses_msg', 'local_jedomi'),
            'qa_admin_top_student_label'     => get_string('qa_admin_top_student_label', 'local_jedomi'),
            'qa_admin_top_student_msg'       => get_string('qa_admin_top_student_msg', 'local_jedomi'),
            // Quick actions: Manager.
            'qa_manager_at_risk_label'       => get_string('qa_manager_at_risk_label', 'local_jedomi'),
            'qa_manager_at_risk_msg'         => get_string('qa_manager_at_risk_msg', 'local_jedomi'),
            'qa_manager_enrolled_label'      => get_string('qa_manager_enrolled_label', 'local_jedomi'),
            'qa_manager_enrolled_msg'        => get_string('qa_manager_enrolled_msg', 'local_jedomi'),
            'qa_manager_global_risk_label'   => get_string('qa_manager_global_risk_label', 'local_jedomi'),
            'qa_manager_global_risk_msg'     => get_string('qa_manager_global_risk_msg', 'local_jedomi'),
            'qa_manager_inactive_label'      => get_string('qa_manager_inactive_label', 'local_jedomi'),
            'qa_manager_inactive_msg'        => get_string('qa_manager_inactive_msg', 'local_jedomi'),
            'qa_manager_inactive_users_label' => get_string('qa_manager_inactive_users_label', 'local_jedomi'),
            'qa_manager_inactive_users_msg'  => get_string('qa_manager_inactive_users_msg', 'local_jedomi'),
            'qa_manager_no_teacher_label'    => get_string('qa_manager_no_teacher_label', 'local_jedomi'),
            'qa_manager_no_teacher_msg'      => get_string('qa_manager_no_teacher_msg', 'local_jedomi'),
            'qa_manager_overview_label'      => get_string('qa_manager_overview_label', 'local_jedomi'),
            'qa_manager_overview_msg'        => get_string('qa_manager_overview_msg', 'local_jedomi'),
            'qa_manager_top_courses_label'   => get_string('qa_manager_top_courses_label', 'local_jedomi'),
            'qa_manager_top_courses_msg'     => get_string('qa_manager_top_courses_msg', 'local_jedomi'),
            // Quick actions: Teacher.
            'qa_teacher_at_risk_label'       => get_string('qa_teacher_at_risk_label', 'local_jedomi'),
            'qa_teacher_at_risk_msg'         => get_string('qa_teacher_at_risk_msg', 'local_jedomi'),
            'qa_teacher_my_alerts_label'     => get_string('qa_teacher_my_alerts_label', 'local_jedomi'),
            'qa_teacher_my_alerts_msg'       => get_string('qa_teacher_my_alerts_msg', 'local_jedomi'),
            'qa_teacher_my_courses_label'    => get_string('qa_teacher_my_courses_label', 'local_jedomi'),
            'qa_teacher_my_courses_msg'      => get_string('qa_teacher_my_courses_msg', 'local_jedomi'),
            'qa_teacher_missing_work_label'  => get_string('qa_teacher_missing_work_label', 'local_jedomi'),
            'qa_teacher_missing_work_msg'    => get_string('qa_teacher_missing_work_msg', 'local_jedomi'),
            'qa_teacher_never_logged_label'  => get_string('qa_teacher_never_logged_label', 'local_jedomi'),
            'qa_teacher_never_logged_msg'    => get_string('qa_teacher_never_logged_msg', 'local_jedomi'),
            'qa_teacher_notify_group_label'  => get_string('qa_teacher_notify_group_label', 'local_jedomi'),
            'qa_teacher_notify_group_msg'    => get_string('qa_teacher_notify_group_msg', 'local_jedomi'),
            'qa_teacher_performance_label'   => get_string('qa_teacher_performance_label', 'local_jedomi'),
            'qa_teacher_performance_msg'     => get_string('qa_teacher_performance_msg', 'local_jedomi'),
            'qa_teacher_top_students_label'  => get_string('qa_teacher_top_students_label', 'local_jedomi'),
            'qa_teacher_top_students_msg'    => get_string('qa_teacher_top_students_msg', 'local_jedomi'),
            // Quick actions: Student.
            'qa_student_help_label'          => get_string('qa_student_help_label', 'local_jedomi'),
            'qa_student_help_msg'            => get_string('qa_student_help_msg', 'local_jedomi'),
            'qa_student_my_courses_label'    => get_string('qa_student_my_courses_label', 'local_jedomi'),
            'qa_student_my_courses_msg'      => get_string('qa_student_my_courses_msg', 'local_jedomi'),
            'qa_student_my_grade_label'      => get_string('qa_student_my_grade_label', 'local_jedomi'),
            'qa_student_my_grade_msg'        => get_string('qa_student_my_grade_msg', 'local_jedomi'),
            'qa_student_my_grades_label'     => get_string('qa_student_my_grades_label', 'local_jedomi'),
            'qa_student_my_grades_msg'       => get_string('qa_student_my_grades_msg', 'local_jedomi'),
            'qa_student_my_progress_label'   => get_string('qa_student_my_progress_label', 'local_jedomi'),
            'qa_student_my_progress_msg'     => get_string('qa_student_my_progress_msg', 'local_jedomi'),
            'qa_student_my_tasks_label'      => get_string('qa_student_my_tasks_label', 'local_jedomi'),
            'qa_student_my_tasks_msg'        => get_string('qa_student_my_tasks_msg', 'local_jedomi'),
            'qa_student_this_week_label'     => get_string('qa_student_this_week_label', 'local_jedomi'),
            'qa_student_this_week_msg'       => get_string('qa_student_this_week_msg', 'local_jedomi'),
        ],
    ];
}

/**
 * Global navigation callback, kept empty because some themes expect it to exist.
 *
 * @param global_navigation $nav Global navigation.
 * @return void
 */
function local_jedomi_extend_navigation(\global_navigation $nav): void {
    // Intentionally empty.
}

/**
 * Adds a "Jedomi Telegram" link to the user profile navigation.
 * Only shown when Telegram is enabled and the user's role is allowed.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass $course
 * @return void
 */
function local_jedomi_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course): void {
    global $USER;

    if (!$iscurrentuser) {
        return;
    }

    // Require Telegram enabled AND bot token verified by getMe().
    // If the admin hasn't pasted a valid token yet, don't expose the link.
    if (!\local_jedomi\telegram\manager::is_enabled()) {
        return;
    }

    if (!get_config('local_jedomi', 'telegram_bot_verified')) {
        return;
    }

    if (!local_jedomi_is_telegram_role_allowed()) {
        return;
    }

    $url = new moodle_url('/local/jedomi/telegram_profile.php', ['userid' => $user->id]);
    $node = new core_user\output\myprofile\node(
        'jedomi',
        'telegram',
        get_string('telegram_link_title', 'local_jedomi'),
        null,
        $url
    );

    $category = new core_user\output\myprofile\category(
        'jedomi',
        get_string('pluginname', 'local_jedomi'),
        null
    );

    $tree->add_category($category);
    $tree->add_node($node);
}

/**
 * Describes the current page for the assistant (URL, page type, course and module).
 *
 * @return array Page context.
 */
function local_jedomi_get_page_context(): array {
    global $PAGE, $COURSE, $USER;

    $url = '';
    try {
        $url = $PAGE->url->out(false);
    } catch (Exception $e) {
        $url = qualified_me() ?: '';
    }

    $pagetype = $PAGE->pagetype ?? '';
    $context  = [
        'page_url'      => $url,
        'page_type'     => $pagetype,
        'page_title'    => $PAGE->title ?? '',
        'user_id'       => $USER->id ?? 0,
        'user_fullname' => fullname($USER),
        'user_role'     => local_jedomi_get_user_role(),
        'course_id'     => 0,
        'course_name'   => '',
        'activity_id'   => 0,
        'activity_type' => '',
        'location'      => 'unknown',
    ];

    if (!empty($COURSE->id) && $COURSE->id > 1) {
        $context['course_id']   = $COURSE->id;
        $context['course_name'] = $COURSE->fullname ?? '';
        $context['location']    = 'course';
    }

    if (preg_match('/[?&]id=(\d+)/', $url, $m) && strpos($url, '/mod/') !== false) {
        $context['activity_id'] = (int)$m[1];
        $context['location']    = 'activity';
        if (preg_match('/^mod-(\w+)-/', $pagetype, $mt)) {
            $context['activity_type'] = $mt[1];
        }
    }

    $locationmap = [
        '/admin/'   => 'admin',
        '/my/'      => 'dashboard',
        '/grade/'   => 'grades',
        '/message/' => 'messages',
        '/user/'    => 'profile',
    ];
    foreach ($locationmap as $path => $loc) {
        if (strpos($url, $path) !== false) {
            $context['location'] = $loc;
            break;
        }
    }
    if ($pagetype === 'my-index') {
        $context['location'] = 'dashboard';
    }

    return $context;
}

/**
 * Whether Jedomi is available in the current course according to the settings.
 *
 * Supports the modes all, whitelist (only listed courses) and blacklist (all but listed courses).
 *
 * @return bool True when available.
 */
function local_jedomi_is_available_in_course(): bool {
    global $COURSE;
    return \local_jedomi\course_access::is_enabled((int)($COURSE->id ?? 0));
}

/**
 * Returns true when the sidebar should be shown to the current user
 * based on admin-configured per-role visibility.
 *
 * Site admins are always allowed.
 * A user is blocked only if ALL of their active roles are in the hidden list.
 *
 * @return bool True when the sidebar is shown.
 */
function local_jedomi_is_role_allowed(): bool {
    global $USER, $PAGE;

    // Site admins always see the sidebar.
    if (is_siteadmin()) {
        return true;
    }

    $hidden = get_config('local_jedomi', 'hidden_roles') ?: '';
    if (empty($hidden)) {
        return true;
    }

    $hiddenids = array_filter(array_map('intval', explode(',', $hidden)));
    if (empty($hiddenids)) {
        return true;
    }

    // Collect the user's roles in the current page context + system context.
    $contextstocheck = [context_system::instance()];
    if (!empty($PAGE->context)) {
        $contextstocheck[] = $PAGE->context;
    }

    $userroleids = [];
    foreach ($contextstocheck as $ctx) {
        $roles = get_user_roles($ctx, $USER->id, false);
        foreach ($roles as $r) {
            $userroleids[] = (int) $r->roleid;
        }
    }
    $userroleids = array_unique($userroleids);

    if (empty($userroleids)) {
        return true; // No roles assigned — show sidebar.
    }

    // If EVERY role the user has is in the hidden list → block.
    // If AT LEAST ONE role is not hidden → allow.
    foreach ($userroleids as $rid) {
        if (!in_array($rid, $hiddenids, true)) {
            return true; // Has at least one non-hidden role → allowed.
        }
    }

    return false; // All roles are hidden.
}


/**
 * Secret that signs the SPTs. It is generated (256 bits) the first time; there is NEVER a known default
 * (with a fixed default any user could forge an SPT with the admin role).
 *
 * @return string
 */
function local_jedomi_spt_secret(): string {
    $secret = (string) get_config('local_jedomi', 'spt_secret');
    if ($secret === '' || $secret === 'jedomi-spt-secret' || strlen($secret) < 32) {
        $secret = bin2hex(random_bytes(32));
        set_config('spt_secret', $secret, 'local_jedomi');
    }
    return $secret;
}

/**
 * Tool definition (OpenAI function format) of ws_search: keyword search over the allowed web services.
 *
 * @return array Tool definition.
 */
function local_jedomi_tool_def_ws_search(): array {
    return [
        'type' => 'function',
        'function' => [
            'name'        => 'ws_search',
            'description' => get_string('tool_ws_search_desc', 'local_jedomi'),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => get_string('tool_ws_search_query', 'local_jedomi'),
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => get_string('tool_ws_search_limit', 'local_jedomi'),
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ];
}

/**
 * Tool definition (OpenAI function format) of ws_describe: full documentation of one web service.
 *
 * @return array Tool definition.
 */
function local_jedomi_tool_def_ws_describe(): array {
    return [
        'type' => 'function',
        'function' => [
            'name'        => 'ws_describe',
            'description' => get_string('tool_ws_describe_desc', 'local_jedomi'),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'wsname' => [
                        'type'        => 'string',
                        'description' => get_string('tool_ws_describe_wsname', 'local_jedomi'),
                    ],
                ],
                'required' => ['wsname'],
            ],
        ],
    ];
}

/**
 * Get tool definitions for a given role in OpenAI function-calling format.
 * These are embedded in the SPT so the gateway can configure the model.
 *
 * @param  string $role User role: admin, manager, teacher, student.
 * @param  string $mode Assistant mode: assistant, socratic or guided.
 * @return array        Array of OpenAI-style tool definitions.
 */
function local_jedomi_get_tool_definitions(string $role, string $mode = 'assistant'): array {
    $tools = [];

    // Student: TUTOR tools only, read-only and limited to their course. Never database schema or SQL.
    if ($role === 'student') {
        $tools[] = ['type' => 'function', 'function' => ['name' => 'get_course_outline',
            'description' => get_string('tool_get_course_outline_desc', 'local_jedomi'),
            'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []]]];
        $tools[] = ['type' => 'function', 'function' => ['name' => 'get_activity_content',
            'description' => get_string('tool_get_activity_content_desc', 'local_jedomi'),
            'parameters' => ['type' => 'object', 'properties' => ['cmid' => ['type' => 'integer',
                'description' => get_string('tool_param_cmid_desc', 'local_jedomi')]], 'required' => ['cmid']]]];
        $tools[] = ['type' => 'function', 'function' => ['name' => 'get_my_progress',
            'description' => get_string('tool_get_my_progress_desc', 'local_jedomi'),
            'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []]]];
        $tools[] = ['type' => 'function', 'function' => ['name' => 'get_hint_state',
            'description' => get_string('tool_get_hint_state_desc', 'local_jedomi'),
            'parameters' => ['type' => 'object', 'properties' => ['cmid' => ['type' => 'integer',
                'description' => get_string('tool_param_cmid_desc', 'local_jedomi')]], 'required' => ['cmid']]]];
        $tools[] = ['type' => 'function', 'function' => ['name' => 'advance_hint_level',
            'description' => get_string('tool_advance_hint_level_desc', 'local_jedomi'),
            'parameters' => ['type' => 'object', 'properties' => ['cmid' => ['type' => 'integer',
                'description' => get_string('tool_param_cmid_desc', 'local_jedomi')]], 'required' => ['cmid']]]];
        if ($mode === 'assistant') {
            // Classic assistant mode: it can also browse the web services of its allowlist (without running them).
            $tools[] = local_jedomi_tool_def_ws_search();
            $tools[] = local_jedomi_tool_def_ws_describe();
        }
        return $tools;
    }

    // Tool describe_table: every role.
    $tools[] = [
        'type' => 'function',
        'function' => [
            'name'        => 'describe_table',
            'description' => get_string('tool_describe_table_desc', 'local_jedomi'),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'tablename' => [
                        'type'        => 'string',
                        'description' => get_string('tool_describe_table_tablename', 'local_jedomi'),
                    ],
                ],
                'required' => ['tablename'],
            ],
        ],
    ];

    // Tool search_tables: every role.
    $tools[] = [
        'type' => 'function',
        'function' => [
            'name'        => 'search_tables',
            'description' => get_string('tool_search_tables_desc', 'local_jedomi'),
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'query' => [
                        'type'        => 'string',
                        'description' => get_string('tool_search_tables_query', 'local_jedomi'),
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => get_string('tool_search_tables_limit', 'local_jedomi'),
                    ],
                ],
                'required' => ['query'],
            ],
        ],
    ];

    // Tool execute_sql: admin, manager, teacher.
    if (in_array($role, ['admin', 'manager', 'teacher'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'execute_sql',
                'description' => get_string('tool_execute_sql_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'sql' => [
                            'type'        => 'string',
                            'description' => get_string('tool_execute_sql_sql', 'local_jedomi'),
                        ],
                        'params' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => get_string('tool_execute_sql_params', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['sql'],
                ],
            ],
        ];
    }

    // Tool get_site_config: admin only.
    if ($role === 'admin') {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'get_site_config',
                'description' => get_string('tool_get_site_config_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'keys' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => get_string('tool_get_site_config_keys', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['keys'],
                ],
            ],
        ];
    }

    // Tool ws_search: every role. Semantic keyword search returning full function docs.
    $tools[] = local_jedomi_tool_def_ws_search();

    // Tool ws_describe: every role. Full docs for a single known WS function.
    $tools[] = local_jedomi_tool_def_ws_describe();

    // Tool ws_execute: admin, manager, teacher.
    if (in_array($role, ['admin', 'manager', 'teacher'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'ws_execute',
                'description' => get_string('tool_ws_execute_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'wsname' => [
                            'type'        => 'string',
                            'description' => get_string('tool_ws_execute_wsname', 'local_jedomi'),
                        ],
                        'params' => [
                            'type'        => 'string',
                            'description' => get_string('tool_ws_execute_params', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['wsname', 'params'],
                ],
            ],
        ];
    }

    // Agent tools — create, list, and delete scheduled AI agents.
    if (in_array($role, ['admin', 'manager'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'create_agent',
                'description' => get_string('tool_create_agent_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'prompt' => [
                            'type'        => 'string',
                            'description' => get_string('tool_create_agent_prompt', 'local_jedomi'),
                        ],
                        'schedule_cron' => [
                            'type'        => 'string',
                            'description' => get_string('tool_create_agent_schedule', 'local_jedomi'),
                        ],
                        'context_type' => [
                            'type'        => 'string',
                            'enum'        => ['site', 'course', 'category', 'user'],
                            'description' => get_string('tool_create_agent_context_type', 'local_jedomi'),
                        ],
                        'context_id' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_create_agent_context_id', 'local_jedomi'),
                        ],
                        'enabled' => [
                            'type'        => 'boolean',
                            'description' => get_string('tool_create_agent_enabled', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['prompt', 'schedule_cron'],
                ],
            ],
        ];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'list_agents',
                'description' => get_string('tool_list_agents_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [
                            'type'        => 'string',
                            'enum'        => ['enabled', 'disabled'],
                            'description' => get_string('tool_list_agents_status', 'local_jedomi'),
                        ],
                    ],
                ],
            ],
        ];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'delete_agent',
                'description' => get_string('tool_delete_agent_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_delete_agent_id', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
        ];
    }

    // Admin CLI tools — search, describe, execute admin operations not exposed via WS.
    if (in_array($role, ['admin', 'manager'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'admin_search',
                'description' => get_string('tool_admin_search_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => get_string('tool_admin_search_query', 'local_jedomi'),
                        ],
                        'limit' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_admin_search_limit', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'admin_describe',
                'description' => get_string('tool_admin_describe_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => get_string('tool_admin_describe_name', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'admin_execute',
                'description' => get_string('tool_admin_execute_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => get_string('tool_admin_execute_name', 'local_jedomi'),
                        ],
                        'params' => [
                            'type'        => 'object',
                            'description' => get_string('tool_admin_execute_params', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];
    }

    // Document reader — available for teacher / editingteacher and above.
    if (in_array($role, ['teacher', 'editingteacher', 'manager', 'admin'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'document_read',
                'description' => get_string('tool_document_read_desc', 'local_jedomi'),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'cmid' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_document_read_cmid', 'local_jedomi'),
                        ],
                        'dpi' => [
                            'type'        => 'integer',
                            'description' => get_string('tool_document_read_dpi', 'local_jedomi'),
                        ],
                        'fmt' => [
                            'type'        => 'string',
                            'enum'        => ['JPEG', 'PNG'],
                            'description' => get_string('tool_document_read_fmt', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['cmid'],
                ],
            ],
        ];
    }

    return $tools;
}

/**
 * Tutor settings of an activity (row per cmid), falling back to the course row (cmid = 0).
 *
 * @param int $courseid Course id.
 * @param int $cmid Course module id, or 0 for the course row.
 * @return stdClass|null Row of local_jedomi_stem_cfg, or null when there are no settings.
 */
function local_jedomi_stem_cfg(int $courseid, int $cmid = 0): ?stdClass {
    global $DB;
    if ($courseid <= 1) {
        return null;
    }
    if ($cmid > 0) {
        $row = $DB->get_record('local_jedomi_stem_cfg', ['courseid' => $courseid, 'cmid' => $cmid]);
        if ($row) {
            return $row;
        }
    }
    return $DB->get_record('local_jedomi_stem_cfg', ['courseid' => $courseid, 'cmid' => 0]) ?: null;
}

/**
 * Resolves the assistant mode of a user in a course or activity. It is ALWAYS computed on the server.
 *
 * Precedence: the site switch `stem_enabled` (template) applies first; a row of the activity in
 * local_jedomi_stem_cfg wins next; then the mode the activity's module sets through the activity_context hook
 * ('inherit' meaning the template's `stem_tutor_mode`, and the course row not consulted); otherwise the course row
 * (cmid = 0). It only applies to students.
 *
 * @param int $courseid Course id.
 * @param int $cmid Course module id.
 * @param string $role Role already resolved on the server.
 * @return string 'assistant' | 'socratic' | 'guided'
 */
function local_jedomi_resolve_mode(int $courseid, int $cmid, string $role): string {
    if ($role !== 'student' || $courseid <= 1) {
        return 'assistant';
    }
    if ((int) get_config('local_jedomi', 'stem_enabled') !== 1) {
        return 'assistant';
    }
    $cfg = local_jedomi_stem_cfg($courseid, $cmid);
    $modulemode = local_jedomi_activity_hook($courseid, $cmid)?->get_mode();
    if ($modulemode !== null && !($cfg && (int) $cfg->cmid === $cmid)) {
        // The activity's module sets the mode; an own stem_cfg row of the activity, if any, wins.
        $mode = $modulemode === 'inherit' ? (string) get_config('local_jedomi', 'stem_tutor_mode') : $modulemode;
        return in_array($mode, ['socratic', 'guided'], true) ? $mode : 'assistant';
    }
    if (!$cfg || (int) $cfg->enabled !== 1) {
        return 'assistant';
    }
    $mode = $cfg->tutor_mode === 'inherit' ? (string) get_config('local_jedomi', 'stem_tutor_mode') : $cfg->tutor_mode;
    return in_array($mode, ['socratic', 'guided'], true) ? $mode : 'assistant';
}

/**
 * Dispatches the activity_context hook of an activity: its module sets the tutor mode and adds teaching context
 * there (\local_jedomi\hook\activity_context).
 *
 * @param int $courseid Course id.
 * @param int $cmid Course module id.
 * @return \local_jedomi\hook\activity_context|null The dispatched hook, or null outside an activity.
 */
function local_jedomi_activity_hook(int $courseid, int $cmid): ?\local_jedomi\hook\activity_context {
    global $USER;
    if ($cmid <= 0 || $courseid <= 1) {
        return null;
    }
    try {
        $cm = get_fast_modinfo($courseid)->get_cm($cmid);
    } catch (\Throwable $e) {
        return null;
    }
    $hook = new \local_jedomi\hook\activity_context($cm, (int) $USER->id);
    \core\di::get(\core\hook\manager::class)->dispatch($hook);
    return $hook;
}

/**
 * Teaching context of the activity for the tutor: its description (plain text, capped) and the context its module
 * adds through the activity_context hook (for a 3D activity, the object and its parts as the teacher described them).
 *
 * @param int $courseid Course id.
 * @param int $cmid Course module id.
 * @return string Plain text for the system message; empty when there is nothing to add.
 */
function local_jedomi_activity_context(int $courseid, int $cmid): string {
    global $DB;
    if ($cmid <= 0 || $courseid <= 1) {
        return '';
    }
    try {
        $cm = get_fast_modinfo($courseid)->get_cm($cmid);
    } catch (\Throwable $e) {
        return '';
    }
    $out = [];
    $intro = $DB->get_field($cm->modname, 'intro', ['id' => $cm->instance], IGNORE_MISSING);
    if ($intro) {
        $intro = html_entity_decode(strip_tags((string) $intro), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $intro = trim(preg_replace('/\s+/u', ' ', $intro));
    } else {
        $intro = '';
    }
    if ($intro !== '') {
        $out[] = get_string('prompt_tutor_activity_desc', 'local_jedomi') . ' ' . core_text::substr($intro, 0, 2500);
    }
    foreach (local_jedomi_activity_hook($courseid, $cmid)?->get_context() ?? [] as $line) {
        $out[] = $line;
    }
    return implode("\n", $out);
}

/**
 * Adds the "AI tutor" settings link to the course navigation for users who can configure it.
 *
 * @param navigation_node $navigation Course navigation node.
 * @param stdClass $course Course.
 * @param context_course $context Course context.
 * @return void
 */
function local_jedomi_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    if (!has_capability('local/jedomi:configuretutor', $context)) {
        return;
    }
    $url = new moodle_url('/local/jedomi/tutor_settings.php', ['id' => $course->id]);
    $navigation->add(
        get_string('tutor_settings_nav', 'local_jedomi'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_jedomi_stem',
        new pix_icon('i/settings', '')
    );
}

/**
 * Builds the signed session payload token (SPT) the gateway uses to authorise the current user.
 *
 * @param string $role Role archetype (admin|manager|teacher|student).
 * @param int $cmid Course module the chat runs in, 0 for none.
 * @param string $mode Assistant mode (assistant|socratic|guided).
 * @return array Payload with its HMAC signature.
 */
function local_jedomi_generate_spt(string $role, int $cmid = 0, string $mode = 'assistant'): array {
    global $USER, $COURSE, $CFG, $DB;

    $sysctx    = context_system::instance();
    $coursectx = (!empty($COURSE->id) && $COURSE->id > 1)
                    ? context_course::instance($COURSE->id)
                    : $sysctx;

    // Real Moodle capabilities, inherited from the role system.
    $capabilities = [
        'can_view_all_grades'    => has_capability('moodle/grade:viewall', $coursectx),
        'can_manage_users'       => has_capability('moodle/user:update', $sysctx),
        'can_send_messages'      => has_capability('moodle/site:sendmessage', $sysctx),
        'can_manage_courses'     => has_capability('moodle/course:update', $coursectx),
        'can_view_reports'       => has_capability('moodle/course:viewhiddenactivities', $coursectx),
        'can_enrol_users'        => has_capability('enrol/manual:enrol', $coursectx),
        'can_access_all_groups'  => has_capability('moodle/site:accessallgroups', $coursectx),
    ];

    // Courses by role (RBAC scope in the gateway).
    $courseidsowned   = [];   // Courses where the user has power (teacher / admin / manager).
    $courseidsenrolled = [];  // Courses where the user is enrolled (student).

    if ($role === 'teacher' || $role === 'manager') {
        $courses = enrol_get_users_courses($USER->id, true);
        foreach ($courses as $c) {
            $ctx = context_course::instance($c->id);
            if (has_capability('moodle/course:manageactivities', $ctx)) {
                $courseidsowned[] = (int)$c->id;
            }
        }
    } else if ($role === 'admin') {
        // Admin needs no course list: full access.
        $courseidsowned = [];
    } else if ($role === 'student') {
        // Student: enrolled courses (the gateway filters with them).
        $courses = enrol_get_users_courses($USER->id, true);
        foreach ($courses as $c) {
            $courseidsenrolled[] = (int)$c->id;
        }
    }

    $tools = local_jedomi_get_tool_definitions($role, $mode);

    $payload = [
        'userid'              => (int) $USER->id,
        'cmid'                => $cmid,
        'mode'                => $mode,
        'username'            => $USER->username,
        'role'                => $role,
        'course_id'           => (int) ($COURSE->id ?? 0),
        'course_ids_owned'    => $courseidsowned, // Teacher/manager: their courses.
        'course_ids_enrolled' => $courseidsenrolled, // Student: enrolled courses.
        'capabilities'        => $capabilities,
        'tools'               => $tools, // Tool definitions in OpenAI format; the gateway configures the model with them.
        'moodle_url'          => $CFG->wwwroot,
        'expires'             => time() + 1800, // 30 minutes (conversations with several tool calls).
    ];

    // HMAC signature with the static secret, the same one the Python gateway verifies with.
    // The sesskey is part of the payload (not the key) to bind the session:
    // if the session changes, the payload changes and the SPT becomes invalid anyway.
    $staticsecret = local_jedomi_spt_secret();
    $payload['sesskey_hash'] = substr(hash('sha256', sesskey()), 0, 16); // Binding without exposing the sesskey.

    // Tool definitions are NOT signed: they are informative (the server recomputes role, mode and allowlist) and
    // their serialisation is not stable through the browser JSON ({} vs []), which broke the signature.
    $tosignpayload = $payload;
    unset($tosignpayload['tools']);
    ksort($tosignpayload);  // Sort keys before serialising (consistent with Python sort_keys).
    $tosign   = json_encode($tosignpayload);
    $signature = hash_hmac('sha256', $tosign, $staticsecret);

    $payload['signature'] = $signature;
    return $payload;
}

/**
 * Generate an SPT (Secure Payload Token) for a specific user (not global $USER).
 *
 * Used by the Telegram webhook where there is no page context or global $USER.
 * Creates a self-signed payload with user identity, capabilities, course lists,
 * and HMAC signature for the gateway to verify.
 *
 * @param  int    $userid The user ID to generate the SPT for.
 * @param  string $role   The Jedomi role shortname (admin|manager|teacher|student).
 * @return array          The signed SPT payload, or empty array if user not found/deleted.
 */
function local_jedomi_generate_spt_for_user(int $userid, string $role): array {
    global $CFG, $DB;

    $sysctx = context_system::instance();
    $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, username, firstname, lastname');
    if (!$user) {
        return [];
    }

    // Capabilities for this specific user (not global $USER).
    $capabilities = [
        'can_view_all_grades'   => has_capability('moodle/grade:viewall', $sysctx, $userid),
        'can_manage_users'      => has_capability('moodle/user:update', $sysctx, $userid),
        'can_send_messages'     => has_capability('moodle/site:sendmessage', $sysctx, $userid),
        'can_manage_courses'    => has_capability('moodle/course:update', $sysctx, $userid),
        'can_view_reports'      => has_capability('moodle/course:viewhiddenactivities', $sysctx, $userid),
        'can_enrol_users'       => has_capability('enrol/manual:enrol', $sysctx, $userid),
        'can_access_all_groups' => has_capability('moodle/site:accessallgroups', $sysctx, $userid),
    ];

    // Course lists based on role (same logic as generate_spt but for specific user).
    $courseidsowned = [];
    $courseidsenrolled = [];

    if ($role === 'teacher' || $role === 'manager') {
        $courses = enrol_get_users_courses($userid, true, ['id']);
        foreach ($courses as $c) {
            $ctx = context_course::instance($c->id);
            if (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
                $courseidsowned[] = (int)$c->id;
            }
        }
    } else if ($role === 'student') {
        $courses = enrol_get_users_courses($userid, true, ['id']);
        foreach ($courses as $c) {
            $courseidsenrolled[] = (int)$c->id;
        }
    }
    // Admin: no course list (full access).

    $tools = local_jedomi_get_tool_definitions($role);

    $payload = [
        'userid'              => $userid,
        'username'            => $user->username,
        'role'                => $role,
        'course_id'           => 0,
        'course_ids_owned'    => $courseidsowned,
        'course_ids_enrolled' => $courseidsenrolled,
        'capabilities'        => $capabilities,
        'tools'               => $tools,
        'moodle_url'          => $CFG->wwwroot,
        'expires'             => time() + 1800,
    ];

    // Same HMAC signing as the existing generate_spt function (tools excluded from the signature).
    $staticsecret = local_jedomi_spt_secret();

    $tosignpayload = $payload;
    unset($tosignpayload['tools']);
    ksort($tosignpayload);
    $tosign = json_encode($tosignpayload);
    $signature = hash_hmac('sha256', $tosign, $staticsecret);

    $payload['signature'] = $signature;
    return $payload;
}

/**
 * Role archetype of the current user in the current course: admin, manager, teacher or student.
 *
 * @return string Role archetype.
 */
function local_jedomi_get_user_role(): string {
    global $USER, $COURSE;

    // 1. Site admin: full access (archetype admin).
    if (is_siteadmin()) {
        return 'admin';
    }

    $sysctx = context_system::instance();

    // 2. Manager: has moodle/user:update at system level but is NOT a site admin.
    // This covers the Moodle 'manager' archetype and any custom role
    // with equivalent privileges (managing users and courses without being admin).
    if (has_capability('moodle/user:update', $sysctx)) {
        return 'manager';
    }

    // 3. Teacher: can manage activities in the current course.
    // Covers the 'editingteacher' archetype and any custom role with that capability.
    if (!empty($COURSE->id) && $COURSE->id > 1) {
        $ctx = context_course::instance($COURSE->id);
        if (has_capability('moodle/course:manageactivities', $ctx)) {
            return 'teacher';
        }
        return 'student';
    }

    // 4. Outside a specific course: student with its own tools (grades, calendar, enrolled courses).
    // A teacher browsing outside a course gets student access, which is safe and acceptable.
    return 'student';
}

/**
 * Determine a user's Jedomi role WITHOUT depending on $COURSE or $PAGE globals.
 *
 * Used by the Telegram webhook where there is no page context or current course.
 * Checks admin status via get_admins(), manager via system-level capability,
 * teacher via manageactivities in any enrolled course, and falls back to student.
 *
 * @param  int    $userid The user ID to check.
 * @return string         'admin', 'manager', 'teacher', or 'student'.
 */
function local_jedomi_get_user_role_for_user(int $userid): string {

    // 1. Check if user is site admin via get_admins().
    $admins = get_admins();
    foreach ($admins as $admin) {
        if ((int) $admin->id === $userid) {
            return 'admin';
        }
    }

    $sysctx = context_system::instance();

    // 2. Manager: has moodle/user:update at system level.
    if (has_capability('moodle/user:update', $sysctx, $userid)) {
        return 'manager';
    }

    // 3. Teacher: has moodle/course:manageactivities in ANY of their enrolled courses.
    $courses = enrol_get_users_courses($userid, true, ['id']);
    foreach ($courses as $c) {
        $ctx = context_course::instance($c->id);
        if (has_capability('moodle/course:manageactivities', $ctx, $userid)) {
            return 'teacher';
        }
    }

    // 4. Fallback: student.
    return 'student';
}

/**
 * Returns true when the Telegram integration should be shown/available to the current user.
 * Reuses the same per-role visibility config pattern as the sidebar (hidden_roles),
 * but with a separate config key: telegram_hidden_roles.
 *
 * Site admins always see it.
 *
 * @return bool
 */
function local_jedomi_is_telegram_role_allowed(): bool {
    global $USER, $PAGE;

    // Site admins always have access.
    if (is_siteadmin()) {
        return true;
    }

    $hidden = get_config('local_jedomi', 'telegram_hidden_roles') ?: '';
    if (empty($hidden)) {
        return true; // No restrictions configured yet.
    }

    $hiddenids = array_filter(array_map('intval', explode(',', $hidden)));
    if (empty($hiddenids)) {
        return true;
    }

    // Collect the user's roles in the current page context + system context.
    $contextstocheck = [context_system::instance()];
    if (!empty($PAGE->context)) {
        $contextstocheck[] = $PAGE->context;
    }

    $userroleids = [];
    foreach ($contextstocheck as $ctx) {
        $roles = get_user_roles($ctx, $USER->id, false);
        foreach ($roles as $r) {
            $userroleids[] = (int) $r->roleid;
        }
    }
    $userroleids = array_unique($userroleids);

    if (empty($userroleids)) {
        return true; // No roles assigned — allow by default.
    }

    // If EVERY role the user has is in the hidden list → block.
    // If AT LEAST ONE role is not hidden → allow.
    foreach ($userroleids as $rid) {
        if (!in_array($rid, $hiddenids, true)) {
            return true; // Has at least one non-hidden role.
        }
    }

    return false; // All roles are hidden.
}

if (!function_exists('local_jedomi_get_role_allowlist')) {
    /**
     * Get the allowlist of web services for a given role.
     *
     * @param  string $role The role shortname (admin|manager|teacher|student).
     * @return array        List of allowed WS names.
     */
    function local_jedomi_get_role_allowlist(string $role): array {
        $json = get_config('local_jedomi', 'ws_allowlist_' . $role);
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}

/**
 * Get ALL registered Moodle web services.
 *
 * @return array Records with name, description, component, type.
 */
function local_jedomi_get_all_ws(): array {
    global $DB;
    $sql = "SELECT DISTINCT ef.name, '' AS description, ef.component
              FROM {external_functions} ef
          ORDER BY ef.component, ef.name";
    return $DB->get_records_sql($sql);
}

/**
 * Resolve the FQCN for a web service function name.
 *
 * @param  string      $wsname The WS function name (e.g. core_course_get_courses).
 * @return string|null         The fully qualified class name, or null.
 */
function local_jedomi_resolve_ws_classname(string $wsname): ?string {
    global $DB, $CFG;
    $record = $DB->get_record('external_functions', ['name' => $wsname], 'classname, classpath, component');
    if (!$record) {
        return null;
    }
    // Try class autoloading first (namespaced classes in Moodle 4.x+).
    if (class_exists($record->classname)) {
        return $record->classname;
    }
    // Fallback: load externallib.php via classpath.
    if (!empty($record->classpath)) {
        $path = "{$CFG->dirroot}/{$record->classpath}";
    } else {
        $path = core_component::get_component_directory($record->component) . '/externallib.php';
    }
    if (file_exists($path)) {
        require_once($path);
    }
    return class_exists($record->classname) ? $record->classname : null;
}

/**
 * Recursively convert a Moodle external structure to a plain JSON-serializable array.
 *
 * @param  mixed  $struct An external_value / external_single_structure / external_multiple_structure.
 * @return array|null
 */
function local_jedomi_convert_external_structure($struct): ?array {
    if ($struct instanceof \core_external\external_value) {
        return [
            'type' => $struct->type,
            'required' => ($struct->required == VALUE_REQUIRED),
            'default' => ($struct->required != VALUE_REQUIRED) ? $struct->default : null,
        ];
    }
    if ($struct instanceof \core_external\external_single_structure) {
        $keys = [];
        foreach ($struct->keys as $key => $val) {
            $keys[$key] = local_jedomi_convert_external_structure($val);
        }
        return ['type' => 'object', 'keys' => $keys];
    }
    if ($struct instanceof \core_external\external_multiple_structure) {
        return [
            'type' => 'array',
            'content' => local_jedomi_convert_external_structure($struct->content),
        ];
    }
    return null;
}

/**
 * Get the parameter schema for a web service function via reflection.
 *
 * @param  string     $wsname The WS function name.
 * @return array|null         JSON-serializable schema, or null on failure.
 */
function local_jedomi_get_ws_schema(string $wsname): ?array {
    global $DB;
    $classname = local_jedomi_resolve_ws_classname($wsname);
    if (!$classname || !class_exists($classname)) {
        return null;
    }
    try {
        // Try execute_parameters() first (single-method WS classes like
        // local_jedomi\external\ws_execute).
        if (method_exists($classname, 'execute_parameters')) {
            $ref = new ReflectionMethod($classname, 'execute_parameters');
            if ($ref->isStatic()) {
                $params = @$ref->invoke(null);
                if ($params instanceof \core_external\external_function_parameters) {
                    return local_jedomi_convert_external_structure($params);
                }
            }
        }

        // Fallback: try {methodname}_parameters() for multi-method classes
        // like core_course_external where the WS name maps to create_categories()
        // and the schema method is create_categories_parameters().
        $record = $DB->get_record('external_functions', ['name' => $wsname], 'methodname');
        if ($record && !empty($record->methodname)) {
            $methodname = $record->methodname . '_parameters';
            if (method_exists($classname, $methodname)) {
                $ref = new ReflectionMethod($classname, $methodname);
                if ($ref->isStatic()) {
                    $params = @$ref->invoke(null);
                    if ($params instanceof \core_external\external_function_parameters) {
                        return local_jedomi_convert_external_structure($params);
                    }
                }
            }
        }

        return null;
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Classify a web service function as Lectura (read) or Escritura (write) based on its name.
 *
 * Uses the last word of the function name and compound patterns to determine the access type.
 * Returns empty string if classification is ambiguous.
 *
 * @param  string $wsname The web service function name (e.g. 'core_course_get_courses').
 * @return string         Internal token 'Lectura', 'Escritura' or '' (shown through the ws_type_* strings).
 */
function local_jedomi_classify_ws_access(string $wsname): string {
    // Extract the action part after the component prefix.
    $parts = explode('_', $wsname);
    $action = end($parts);

    // Read keywords (lectura).
    if (preg_match('/^(get|view|search|list|is|check|validate|export|download|fetch|retrieve|count|exists)$/i', $action)) {
        return 'Lectura';
    }

    // Also check the full name for compound read patterns.
    if (preg_match('/_(get|view|search|list|check|export|download|fetch|retrieve)_/i', $wsname)) {
        return 'Lectura';
    }

    // Write keywords (escritura).
    $writeverbs = implode('|', [
        'create|update|delete|add|remove|set|edit|save|insert|upload|import|purge|reset|restore',
        'reorder|toggle|approve|reject|block|unblock|lock|unlock|archive|trash|publish|unpublish',
        'submit|enrol|unenrol|assign|unassign|mark|flag|clear|move|copy|duplicate|switch|change',
        'revoke|grant',
    ]);
    if (preg_match('/^(' . $writeverbs . ')$/i', $action)) {
        return 'Escritura';
    }
    if (preg_match('/_(create|update|delete|add|remove|save|insert|upload|import|purge|enrol|assign|grant|revoke)_/i', $wsname)) {
        return 'Escritura';
    }

    return '';
}

if (!function_exists('local_jedomi_log')) {
    /**
     * Write an entry to the Jedomi debug log.
     *
     * Logs are stored in {$CFG->dataroot}/local/jedomi/jedomi.log
     * in a human-readable format with timestamp and structured context.
     *
     * @param string $message Short description of what happened.
     * @param mixed  $data    Optional context data (array, string, or anything json-serializable).
     * @return void
     */
    function local_jedomi_log(string $message, mixed $data = null): void {
        global $CFG;

        static $logdir = null;
        static $logfile = null;

        if ($logdir === null) {
            $logdir = $CFG->dataroot . '/local/jedomi';
            check_dir_exists($logdir, true, true);
            $logfile = $logdir . '/jedomi.log';
        }

        $timestamp = date('Y-m-d H:i:s.v');
        $entry = "[{$timestamp}] {$message}";

        if ($data !== null) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);
            $entry .= ' | ' . $encoded;
        }

        $entry .= "\n";
        file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Convert HTML tables in a string to pipe-delimited plain text.
 *
 * Telegram's sendMessage HTML mode does NOT support <table>, <tr>, <td>, <th>.
 * When the LLM returns a table, strip_tags removes all table tags and the
 * data becomes unreadable (e.g. "IDName1Admin2buendata...").
 *
 * This function finds every <table>...</table> block, converts it to a
 * pipe-delimited format, and wraps it in a <pre> block so the user sees
 * a readable monospace table.
 *
 * @param  string $html The HTML content (may contain tables).
 * @return string       Content with tables converted to pipe-delimited <pre> blocks.
 */
function local_jedomi_sanitize_html_tables(string $html): string {
    return preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', function ($tablematch) {
        $tablehtml = $tablematch[1];

        // Extract headers.
        $headers = [];
        if (preg_match('/<tr[^>]*>(.*?)<\/tr>/is', $tablehtml, $headerrow)) {
            preg_match_all('/<th[^>]*>(.*?)<\/th>/is', $headerrow[1], $headercells);
            foreach ($headercells[1] as $cell) {
                $headers[] = trim(strip_tags($cell));
            }
        }

        // Extract rows.
        $rows = [];
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tablehtml, $rowmatches);
        foreach ($rowmatches[1] as $rowhtml) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $rowhtml, $cellmatches);
            $row = [];
            foreach ($cellmatches[1] as $cell) {
                $row[] = trim(strip_tags($cell));
            }
            if (!empty($row)) {
                $rows[] = $row;
            }
        }

        // If no <th> found, use first row as header.
        if (empty($headers) && !empty($rows)) {
            $headers = array_shift($rows);
        }

        // Build pipe-delimited output.
        $allrows = [];
        if (!empty($headers)) {
            $allrows[] = $headers;
        }
        foreach ($rows as $row) {
            $allrows[] = $row;
        }

        if (empty($allrows)) {
            return ''; // Empty table → remove it.
        }

        // Calculate column widths.
        $colcount = max(array_map('count', $allrows));
        $widths = array_fill(0, $colcount, 0);
        foreach ($allrows as $row) {
            for ($i = 0; $i < $colcount; $i++) {
                $cell = $row[$i] ?? '';
                $widths[$i] = max($widths[$i], mb_strlen($cell));
            }
        }

        // Format with padding.
        $lines = [];
        foreach ($allrows as $ri => $row) {
            $parts = [];
            for ($i = 0; $i < $colcount; $i++) {
                $cell = $row[$i] ?? '';
                $parts[] = str_pad($cell, $widths[$i]);
            }
            $lines[] = '| ' . implode(' | ', $parts) . ' |';

            // Separator after header row.
            if ($ri === 0 && !empty($headers)) {
                $sepparts = [];
                for ($i = 0; $i < $colcount; $i++) {
                    $sepparts[] = str_repeat('-', $widths[$i]);
                }
                $lines[] = '|-' . implode('-|-', $sepparts) . '-|';
            }
        }

        return '<pre>' . htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8') . '</pre>';
    }, $html) ?? $html;
}

/**
 * Send an agent execution notification to Telegram.
 *
 * Decides automatically:
 * - If text ≤ 3800 chars → sendMessage (HTML formatted).
 * - If text > 3800 chars → write to a .txt file in dataroot/jedomi/telegram/,
 *   send via sendDocument, then delete the temp file.
 *
 * @param  int|string $chatid    Telegram chat ID.
 * @param  string     $agent     Agent label (name or "Agent #N").
 * @param  string     $content   The AI's response text (raw, may contain HTML).
 * @return bool                  True if sent successfully.
 */
function local_jedomi_send_agent_notification(int|string $chatid, string $agent, string $content): bool {
    global $CFG;

    // Sanitize HTML tables before any strip_tags call.
    $content = \local_jedomi_sanitize_html_tables($content);

    // Build caption (fits within Telegram's 1024-char limit).
    // Header with agent name + status + date.
    $agentlabel = strip_tags($agent);
    $datestr = userdate(time());
    $header = "🤖 {$agentlabel}\n" . get_string('agent_notification_success', 'local_jedomi') . "\n📅 {$datestr}\n\n";

    // Plain-text summary (no HTML) — truncate so header + wrapper fits ≤ 1024.
    $summary = mb_substr(strip_tags($content), 0, 1500);
    $maxsummarylen = 1024 - mb_strlen($header) - 7; // 7 = <i></i>
    if ($maxsummarylen < 0) {
        $maxsummarylen = 0;
    }
    $summary = mb_substr($summary, 0, $maxsummarylen);

    // Decision: message vs file.
    $client = new \local_jedomi\telegram\api_client();

    if (mb_strlen($content) < 3800) {
        // Full content fits in a single message — send it inline.
        $body = strip_tags($content);
        $msg = $header . "<i>" . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . "</i>";
        return $client->send_message($chatid, $msg);
    }

    // Large result → send as .txt file.
    // Extract a brief one-line description from the AI response (first meaningful line,
    // not a code fence or empty line). This goes in the caption so the user knows what
    // the result is about without seeing the actual data.
    $plaintext = strip_tags($content);
    $lines = explode("\n", $plaintext);
    $oneliner = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line) && !str_starts_with($line, str_repeat(chr(96), 3))) {
            $oneliner = $line;
            break;
        }
    }
    // Keep it short — truncate at first sentence boundary (~200 chars max).
    if (mb_strlen($oneliner) > 200) {
        $truncated = mb_substr($oneliner, 0, 197);
        $lastsentence = max(
            mb_strrpos($truncated, '.'),
            mb_strrpos($truncated, '!'),
            mb_strrpos($truncated, '?')
        );
        if ($lastsentence > 20) {
            $oneliner = mb_substr($truncated, 0, $lastsentence + 1);
        } else {
            $oneliner = $truncated . '...';
        }
    }

    // Caption: header + one-liner. No data preview. Full result goes in the file.
    $caption = $header . htmlspecialchars($oneliner, ENT_QUOTES, 'UTF-8');

    $tmpdir = $CFG->dataroot . '/jedomi/telegram';
    check_dir_exists($tmpdir, true, true);

    $filename = 'agent_' . time() . '_' . bin2hex(random_bytes(4)) . '.txt';
    $filepath = $tmpdir . '/' . $filename;

    // If the response has a fenced code block (```csv, ```sql, ```, etc.) or a
    // <pre> block, extract just the content inside it for the file.
    // Otherwise use the full stripped content.
    $filecontent = strip_tags($content);
    if (preg_match('/' . str_repeat(chr(96), 3) . '(?:\w+)?\s*\n(.+?)\n' . str_repeat(chr(96), 3) . '/s', $content, $m)) {
        $filecontent = trim($m[1]);
    } else if (preg_match('/<pre>(.+?)<\/pre>/s', $content, $m)) {
        $filecontent = trim($m[1]);
    }
    $bytes = file_put_contents($filepath, $filecontent);

    $sent = $client->send_document($chatid, $filepath, $caption);

    // Clean up — file was sent, no longer needed.
    @unlink($filepath);

    return $sent;
}
