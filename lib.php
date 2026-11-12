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
 * local_jedomi — AI Sidebar for Moodle
 * Theme-proof injection strategy:
 *
 *  1. before_footer() → tries to inject through the standard hook
 *  2. The sidebar.js AMD module checks whether the HTML exists and, if not, injects it itself
 *     straight into document.body (bypassing any theme problem)
 *  3. The CSS uses position:fixed !important and z-index:99999 to survive any theme
 *  4. Fixes body overflow:hidden, which breaks fixed positioning
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use aiprovider_datacurso\local\plugin_settings;
use aiprovider_datacurso\local\suite_plugins;

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
 * Build the full sidebar config array (strings, endpoints, SPT, etc.).
 *
 * Never includes AI service URLs or keys: every AI call is made server-side.
 *
 * @return array
 */
function local_jedomi_get_sidebar_config(): array {
    global $USER, $CFG;

    $context = local_jedomi_get_page_context();
    $voiceenabled = \local_jedomi\ai\model_settings::is_voice_enabled();

    $config = [
        'chatproxy_url' => $CFG->wwwroot . '/local/jedomi/chatproxy.php',
        'extract_file_url' => $CFG->wwwroot . '/local/jedomi/extract_file.php',
        'read_file_url' => $CFG->wwwroot . '/local/jedomi/read_file.php',
        // Hidden features are also refused by the server.
        'files_enabled' => \local_jedomi\ai\model_settings::is_files_enabled(),
        'audio_enabled' => \local_jedomi\ai\model_settings::is_audio_enabled(),
        'voice_enabled' => $voiceenabled,
        'feedback_url'  => $CFG->wwwroot . '/local/jedomi/ajax/feedback.php',
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
            'attach_audio'      => get_string('attach_audio', 'local_jedomi'),
            'audio_file'        => get_string('audio_file', 'local_jedomi'),
            'attach_file'       => get_string('attach_file', 'local_jedomi'),
            'attach_image'      => get_string('attach_image', 'local_jedomi'),
            'file'              => get_string('file', 'local_jedomi'),
            'image'             => get_string('image', 'local_jedomi'),
            'message_input'     => get_string('message_input', 'local_jedomi'),
            'placeholder'       => get_string('chat_placeholder', 'local_jedomi'),
            'send'              => get_string('send', 'local_jedomi'),
            'voice_input'       => get_string('voice_input', 'local_jedomi'),
            // Greeting.
            'greeting'          => get_string('greeting', 'local_jedomi'),
            'hello'             => get_string('hello', 'local_jedomi'),
            'thinking'          => get_string('thinking', 'local_jedomi'),
            'new_conversation_msg' => get_string('new_conversation_msg', 'local_jedomi'),
            // Files.
            'analyze_file'         => get_string('analyze_file', 'local_jedomi'),
            'analyze_image'        => get_string('analyze_image', 'local_jedomi'),
            'attached_file_info'   => get_string('attached_file_info', 'local_jedomi'),
            'attached_image'       => get_string('attached_image', 'local_jedomi'),
            'csv_download'         => get_string('csv_download', 'local_jedomi'),
            'file_audio'           => get_string('file_audio', 'local_jedomi'),
            'file_csv'             => get_string('file_csv', 'local_jedomi'),
            'file_error_unsupported' => get_string('file_error_unsupported', 'local_jedomi'),
            'file_pdf'             => get_string('file_pdf', 'local_jedomi'),
            'file_process_error'   => get_string('file_process_error', 'local_jedomi'),
            'file_text'            => get_string('file_text', 'local_jedomi'),
            'processing_file'      => get_string('processing_file', 'local_jedomi'),
            'reading_image'        => get_string('reading_image', 'local_jedomi'),
            // Footer / disclaimer.
            'ai_disclaimer'     => get_string('ai_disclaimer', 'local_jedomi'),
            'drop_files_here'   => get_string('drop_files_here', 'local_jedomi'),
            // Tools.
            'tool_sql_read'          => get_string('tool_sql_read', 'local_jedomi'),
            'tool_api_call'          => get_string('tool_api_call', 'local_jedomi'),
            'tool_schema_lookup'     => get_string('tool_schema_lookup', 'local_jedomi'),
            'tool_webservice_lookup' => get_string('tool_webservice_lookup', 'local_jedomi'),
            'tool_search_tables'     => get_string('tool_search_tables', 'local_jedomi'),
            'tool_execute_sql'       => get_string('tool_execute_sql', 'local_jedomi'),
            'tool_describe_table'    => get_string('tool_describe_table', 'local_jedomi'),
            'tool_get_site_config'   => get_string('tool_get_site_config', 'local_jedomi'),
            'tool_ws_search'         => get_string('tool_ws_search', 'local_jedomi'),
            'tool_ws_describe'       => get_string('tool_ws_describe', 'local_jedomi'),
            'tool_ws_execute'        => get_string('tool_ws_execute', 'local_jedomi'),
            'tool_unknown'           => get_string('tool_unknown', 'local_jedomi'),
            // Session.
            'session_expired_title'  => get_string('session_expired_title', 'local_jedomi'),
            'session_expired_hint'   => get_string('session_expired_hint', 'local_jedomi'),
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
            'ai_error_connection'   => get_string('ai_error_connection', 'local_jedomi'),
            'listening'             => get_string('listening', 'local_jedomi'),
            'mic_error'             => get_string('mic_error', 'local_jedomi'),
            'silence_detected'      => get_string('silence_detected', 'local_jedomi'),
            'transcribing'          => get_string('transcribing', 'local_jedomi'),
            'audio_error_unreadable' => get_string('audio_error_unreadable', 'local_jedomi'),
            // Voice message bar.
            'voice_cancel'          => get_string('voice_cancel', 'local_jedomi'),
            'voice_delete'          => get_string('voice_delete', 'local_jedomi'),
            'voice_message'         => get_string('voice_message', 'local_jedomi'),
            'voice_pause'           => get_string('voice_pause', 'local_jedomi'),
            'voice_play'            => get_string('voice_play', 'local_jedomi'),
            'voice_recording'       => get_string('voice_recording', 'local_jedomi'),
            'voice_send'            => get_string('voice_send', 'local_jedomi'),
            'voice_stop'            => get_string('voice_stop', 'local_jedomi'),
            'voice_too_short'       => get_string('voice_too_short', 'local_jedomi'),
            'waiting_voice'         => get_string('waiting_voice', 'local_jedomi'),
            // Voice conversation.
            'voice_mode'                     => get_string('voice_mode', 'local_jedomi'),
            'voice_mode_confirm_notice'      => get_string('voice_mode_confirm_notice', 'local_jedomi'),
            'voice_mode_exit'                => get_string('voice_mode_exit', 'local_jedomi'),
            'voice_mode_listening'           => get_string('voice_mode_listening', 'local_jedomi'),
            'voice_mode_paused'              => get_string('voice_mode_paused', 'local_jedomi'),
            'voice_mode_speaking'            => get_string('voice_mode_speaking', 'local_jedomi'),
            'voice_mode_speech_error'        => get_string('voice_mode_speech_error', 'local_jedomi'),
            'voice_mode_thinking'            => get_string('voice_mode_thinking', 'local_jedomi'),
            'voice_mode_transcription_error' => get_string('voice_mode_transcription_error', 'local_jedomi'),
            // Confirmation system.
            'confirm_type_to_proceed' => get_string('confirm_type_to_proceed', 'local_jedomi'),
            'confirm_btn_yes'         => get_string('confirm_btn_yes', 'local_jedomi'),
            'confirm_btn_no'          => get_string('confirm_btn_no', 'local_jedomi'),
            'confirm_executing'       => get_string('confirm_executing', 'local_jedomi'),
            'confirm_done'            => get_string('confirm_done', 'local_jedomi'),
            'confirm_cancelled'       => get_string('confirm_cancelled', 'local_jedomi'),
            'pending_action_needed'   => get_string('pending_action_needed', 'local_jedomi'),
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
            'qa_course_current'              => get_string('qa_course_current', 'local_jedomi'),
            'qa_course_with_id'              => get_string('qa_course_with_id', 'local_jedomi'),
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
    if ($voiceenabled) {
        // The endpoint of the spoken answers is only handed out while the voice conversation is on; it refuses otherwise.
        $config['speech_url'] = $CFG->wwwroot . '/local/jedomi/speech.php';
    }
    return $config;
}

/**
 * Extend the global navigation.
 * Intentionally empty; the callback must exist because some themes invoke it.
 *
 * @param \global_navigation $nav
 */
function local_jedomi_extend_navigation(\global_navigation $nav): void {
    // Empty hook required by some themes.
}

/**
 * Adds a "Jedomi Telegram" link to the user profile navigation.
 * Only shown when Telegram is enabled and the user's role is allowed.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass $course
 */
function local_jedomi_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course): void {
    global $USER;

    if (!$iscurrentuser) {
        return;
    }

    if (!plugin_settings::is_enabled('local_jedomi')) {
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
 * Detect the context of the current page.
 *
 * @return array Page context: location, course and activity details.
 */
function local_jedomi_get_page_context(): array {
    global $PAGE, $COURSE, $USER;

    $url = '';
    try {
        $url = $PAGE->url->out(false);
    } catch (Exception $e) {
        $url = $_SERVER['REQUEST_URI'] ?? '';
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
 * Error to answer when Jedomi is disabled in the Datacurso AI provider.
 *
 * Shared by the endpoints that answer JSON or SSE ({"error": {"code", "message"}}) and by the Telegram
 * bot. The message is the provider's plain-text "not enabled" string (plugindisabled), without the
 * administrator link that plugin_settings::require_enabled() adds for HTML pages.
 *
 * @return array|null ['code' => 'plugindisabled', 'message' => ...], or null when Jedomi is enabled.
 */
function local_jedomi_plugin_disabled_error(): ?array {
    if (plugin_settings::is_enabled('local_jedomi')) {
        return null;
    }
    $name = suite_plugins::get_display_name('local_jedomi');
    return ['code' => 'plugindisabled', 'message' => get_string('plugindisabled', 'aiprovider_datacurso', $name)];
}

/**
 * Check whether Jedomi is available in the current course according to the settings.
 * Supports the modes all, whitelist (only the listed courses) and blacklist (all but the listed courses).
 *
 * @return bool Whether the chat is available in the course.
 */
function local_jedomi_is_available_in_course(): bool {
    global $COURSE;
    return \local_jedomi\course_access::is_enabled((int)($COURSE->id ?? 0));
}

/**
 * Returns true when the sidebar should be shown to the current user
 * based on admin-configured per-role visibility, in the context of the current page.
 *
 * @return bool Whether the role can use the chat.
 */
function local_jedomi_is_role_allowed(): bool {
    global $USER, $PAGE;

    // The page context is a magic property: empty() would always see it as unset and fall back to system.
    try {
        $context = $PAGE->context;
    } catch (\coding_exception $e) {
        $context = context_system::instance();
    }
    return local_jedomi_is_role_allowed_for_user((int) ($USER->id ?? 0), $context);
}

/**
 * Whether the per-role visibility lets a user use Jedomi in a context.
 *
 * Site admins are always allowed. Every role of the user in the context and in its parent
 * contexts (course, category, system) counts. A user without any assigned role is judged by
 * the authenticated user role. A user is blocked only if ALL of those roles are hidden.
 *
 * @param int $userid User to check.
 * @param \context $context Context of the page or request.
 * @return bool Whether the user can use the chat.
 */
function local_jedomi_is_role_allowed_for_user(int $userid, \context $context): bool {
    global $CFG;

    if (is_siteadmin($userid)) {
        return true;
    }

    $hiddenids = array_filter(array_map('intval', explode(',', (string) get_config('local_jedomi', 'hidden_roles'))));
    if (empty($hiddenids)) {
        return true;
    }

    $userroleids = [];
    foreach (get_user_roles($context, $userid, true) as $r) {
        $userroleids[] = (int) $r->roleid;
    }
    if (empty($userroleids) && !empty($CFG->defaultuserroleid)) {
        // No assigned role: the user only has the authenticated user role.
        $userroleids[] = (int) $CFG->defaultuserroleid;
    }

    if (empty($userroleids)) {
        return true;
    }

    // If AT LEAST ONE role is not hidden → allow; if EVERY role is hidden → block.
    return !empty(array_diff(array_unique($userroleids), $hiddenids));
}

/**
 * Error to answer a chat request (configuration, query, history or file) the server must refuse.
 *
 * Applies the same rules as the panel: guests and users not logged in never use the chat, the
 * course must be available and the user must have a visible role in it.
 *
 * @param int $courseid Course the request comes from (0 or the site course outside a course).
 * @return array|null ['code' => ..., 'message' => ...], or null when the request is allowed.
 */
function local_jedomi_chat_access_error(int $courseid): ?array {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return ['code' => 'guest_not_allowed', 'message' => get_string('chat_error_guest', 'local_jedomi')];
    }
    if (!\local_jedomi\course_access::is_enabled($courseid)) {
        return ['code' => 'course_unavailable', 'message' => get_string('chat_error_course_unavailable', 'local_jedomi')];
    }
    $context = $courseid > SITEID ? context_course::instance($courseid, IGNORE_MISSING) : false;
    if (!local_jedomi_is_role_allowed_for_user((int) $USER->id, $context ?: context_system::instance())) {
        return ['code' => 'role_hidden', 'message' => get_string('chat_error_role_hidden', 'local_jedomi')];
    }
    return null;
}

/**
 * Error for a course named by a chat request that the user cannot use: it does not exist or the user cannot enter it.
 *
 * The course comes from the browser, so it is checked with the same rules as the course page: the user must be
 * enrolled, be allowed to view it, or have entered it as a guest in this session. The site (0 or the site course)
 * is always allowed here; its own rules are applied by {@see local_jedomi_chat_access_error()}.
 *
 * @param int $courseid Course named by the request (0 or the site course outside a course).
 * @return array|null ['code' => ..., 'message' => ...], or null when the course can be used.
 */
function local_jedomi_course_request_error(int $courseid): ?array {
    global $DB;

    if ($courseid <= SITEID) {
        return null;
    }
    $course = $DB->get_record('course', ['id' => $courseid]);
    if (!$course || !can_access_course($course)) {
        return ['code' => 'course_access_denied', 'message' => get_string('chat_error_course_access', 'local_jedomi')];
    }
    return null;
}

/**
 * Course of a chat request sent after the sidebar got its signed permissions (SPT): the course signed in them.
 *
 * The course is never taken from the request: the signed permissions must be valid for this session and, when the
 * browser still sends a course, it must be the signed one. The course is checked again with the access rules of the
 * panel, as the user may have lost access since the permissions were signed.
 *
 * @param mixed $spt Signed permissions sent with the request, decoded; anything else is refused.
 * @param int|null $requestcourseid Course sent by the browser, or null when none was sent.
 * @return array ['courseid' => int, 'error' => array|null], the error with keys code and message.
 */
function local_jedomi_signed_request_course($spt, ?int $requestcourseid): array {
    if (!is_array($spt)) {
        $message = get_string('invalid_session', 'local_jedomi');
        return ['courseid' => 0, 'error' => ['code' => 'invalid_spt', 'message' => $message]];
    }
    try {
        \local_jedomi\proxy\spt_validator::validate($spt);
    } catch (\moodle_exception $e) {
        return ['courseid' => 0, 'error' => ['code' => 'invalid_spt', 'message' => $e->getMessage()]];
    }

    $courseid = (int) ($spt['course_id'] ?? 0);
    return ['courseid' => $courseid, 'error' => local_jedomi_signed_course_error($courseid, $requestcourseid)];
}

/**
 * Course of a chat request read from its parameters: the signed permissions (spt, JSON) and the course of the page
 * the panel is on (courseid, optional).
 *
 * The same check as {@see local_jedomi_signed_request_course()}, for the endpoints that receive their fields as
 * request parameters (history, attachments).
 *
 * @return array ['courseid' => int, 'error' => array|null], the error with keys code and message.
 */
function local_jedomi_request_signed_course(): array {
    return local_jedomi_signed_request_course(
        json_decode(optional_param('spt', '', PARAM_RAW), true),
        optional_param('courseid', null, PARAM_INT)
    );
}

/**
 * Error for the course signed in some already validated chat permissions: a different course of the page, a course
 * the user cannot enter any more, or the access rules of the panel.
 *
 * Design note: the course of the page reaches the server only from the browser, which asks the panel configuration
 * for the permissions of a course and sends that course with every request. The guarantees are that permissions are
 * signed only for a course the user can enter, that the signed course is checked again on every request and that a
 * course of the page different from the signed one is refused. A client that asks for the permissions of the site
 * home while on the page of an excluded course cannot be told apart from a panel on the site home, so it gets only
 * what the site home grants. The course availability settings and the hidden roles therefore control where the panel
 * is shown, not what a request may do: the role and the tools always come from the capabilities of the user in the
 * signed course ({@see local_jedomi_effective_chat_role()}).
 *
 * @param int $signedcourseid Course signed in the permissions (0 or the site course outside a course).
 * @param int|null $requestcourseid Course of the page sent by the browser, or null when none is compared.
 * @return array|null ['code' => ..., 'message' => ...], or null when the request is allowed.
 */
function local_jedomi_signed_course_error(int $signedcourseid, ?int $requestcourseid): ?array {
    if ($requestcourseid !== null && !local_jedomi_is_same_course($signedcourseid, $requestcourseid)) {
        return ['code' => 'course_mismatch', 'message' => get_string('chat_error_course_mismatch', 'local_jedomi')];
    }
    return local_jedomi_course_request_error($signedcourseid) ?? local_jedomi_chat_access_error($signedcourseid);
}

/**
 * Whether two course ids name the same place for the chat: the site course and 0 (outside a course) are the same.
 *
 * @param int $first Course id.
 * @param int $second Course id.
 * @return bool
 */
function local_jedomi_is_same_course(int $first, int $second): bool {
    return ($first > SITEID ? $first : 0) === ($second > SITEID ? $second : 0);
}

/**
 * The role a chat request runs with: the role presented, but never above the real role of the user.
 *
 * The real role is computed on the server from the user in the course of the request, so the
 * content presented by the browser can never grant tools of a higher role.
 *
 * @param string $presented Role presented with the request.
 * @param int $userid Session user.
 * @param int $courseid Course of the request (0 or the site course outside a course).
 * @return string 'admin', 'manager', 'teacher' or 'student'.
 */
function local_jedomi_effective_chat_role(string $presented, int $userid, int $courseid): string {
    $rank = ['student' => 1, 'teacher' => 2, 'manager' => 3, 'admin' => 4];

    if (is_siteadmin($userid)) {
        $real = 'admin';
    } else if (has_capability('moodle/user:update', context_system::instance(), $userid)) {
        $real = 'manager';
    } else {
        $real = 'student';
        $context = $courseid > SITEID ? context_course::instance($courseid, IGNORE_MISSING) : false;
        if ($context && has_capability('moodle/course:manageactivities', $context, $userid)) {
            $real = 'teacher';
        }
    }

    if (!isset($rank[$presented]) || $rank[$presented] > $rank[$real]) {
        return $real;
    }
    return $presented;
}

/**
 * Get tool definitions for a given role in OpenAI function-calling format.
 * These are embedded in the SPT so the gateway can configure the model.
 *
 * @param  string $role User role: admin, manager, teacher, student.
 * @return array        Array of OpenAI-style tool definitions.
 */
function local_jedomi_get_tool_definitions(string $role): array {
    $tools = [];

    // Describe_table — every role.
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

    // Search_tables — every role.
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

    // Execute_sql — admin, manager, teacher.
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

    // Get_site_config — admin only.
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

    // Ws_search — every role. Semantic keyword search returning full function docs.
    $tools[] = [
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

    // Ws_describe — every role. Full docs for a single known WS function.
    $tools[] = [
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

    // Ws_execute — admin, manager, teacher.
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

    // Own data (courses, visible grades, assignments and deadlines) — students; the user is never an argument.
    if (\local_jedomi\agent\tool\own_data_tool::is_offered($role)) {
        $tools = array_merge($tools, \local_jedomi\agent\tool\own_data_tool::definitions());
    }

    // Document reader — teacher / editingteacher and above, while file and image processing is on.
    if (\local_jedomi\agent\tool\document_read_tool::is_offered($role)) {
        $tools[] = \local_jedomi\agent\tool\document_read_tool::definition();
    }

    return $tools;
}

/**
 * Secret of this site that signs the chat permissions (SPT).
 *
 * There is no built-in key: when no secret is saved (or the legacy built-in key is), a random
 * one is generated and saved, so permissions can never be signed with a key known from the code.
 *
 * @return string The site secret, at least 64 characters when generated.
 */
function local_jedomi_get_spt_secret(): string {
    $secret = (string) get_config('local_jedomi', 'spt_secret');
    if ($secret === '' || $secret === 'jedomi-spt-secret') {
        $secret = bin2hex(random_bytes(32));
        set_config('spt_secret', $secret, 'local_jedomi');
    }
    return $secret;
}

/**
 * Text an SPT is signed over: the payload without its signature, in the form it has after a JSON round trip.
 *
 * The browser sends the SPT back as JSON and the validator decodes it into arrays, so an empty object
 * (for example the "properties" of a tool without parameters) comes back as an empty array. Signing the
 * payload after the same round trip keeps the signature valid for every tool definition.
 *
 * @param array $payload SPT payload, with or without its signature.
 * @return string JSON text to sign or verify.
 */
function local_jedomi_spt_signing_string(array $payload): string {
    unset($payload['signature']);
    $canonical = json_decode(json_encode($payload), true);
    ksort($canonical);
    return json_encode($canonical);
}

/**
 * Generate a signed SPT (Secure Payload Token) for the current user and course.
 *
 * Builds a payload with the user's identity, effective capabilities, role-scoped
 * course lists, and tool definitions, then signs it with an HMAC so the Python
 * gateway can verify it.
 *
 * @param  string $role The Jedomi role shortname (admin|manager|teacher|student).
 * @return array        The signed SPT payload including the 'signature' key.
 */
function local_jedomi_generate_spt(string $role): array {
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
    $courseidsowned   = [];   // Cursos donde el usuario tiene poder (teacher / admin / manager).
    $courseidsenrolled = [];  // Cursos donde el usuario está inscrito (student).

    if ($role === 'teacher' || $role === 'manager') {
        $courses = enrol_get_users_courses($USER->id, true);
        foreach ($courses as $c) {
            $ctx = context_course::instance($c->id);
            if (has_capability('moodle/course:manageactivities', $ctx)) {
                $courseidsowned[] = (int)$c->id;
            }
        }
    } else if ($role === 'admin') {
        // An admin needs no course list: they have full access.
        $courseidsowned = [];
    } else if ($role === 'student') {
        // For students: the enrolled courses (used by the gateway to filter).
        $courses = enrol_get_users_courses($USER->id, true);
        foreach ($courses as $c) {
            $courseidsenrolled[] = (int)$c->id;
        }
    }

    $tools = local_jedomi_get_tool_definitions($role);

    $payload = [
        'userid'              => (int) $USER->id,
        'username'            => $USER->username,
        'role'                => $role,
        'course_id'           => (int) ($COURSE->id ?? 0),
        'course_ids_owned'    => $courseidsowned, // Teacher/manager: sus cursos.
        'course_ids_enrolled' => $courseidsenrolled, // Student: cursos inscritos.
        'capabilities'        => $capabilities,
        'tools'               => $tools, // Tool defs en OpenAI format — gateway las usa para configurar el modelo.
        'moodle_url'          => $CFG->wwwroot,
        'expires'             => time() + 1800, // 30 minutos (conversaciones con múltiples tool calls)
    ];

    // HMAC signature with the static secret, the same one the Python gateway uses to verify it.
    // sesskey() is part of the payload (not the key) to bind the token to the session:
    // if the session changes, the payload changes and the SPT is invalid anyway.
    $staticsecret = local_jedomi_get_spt_secret();
    $payload['sesskey_hash'] = substr(hash('sha256', sesskey()), 0, 16); // Binding sin exponer sesskey.

    ksort($payload);
    $signature = hash_hmac('sha256', local_jedomi_spt_signing_string($payload), $staticsecret);

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

    // Same HMAC signing as the existing generate_spt function.
    $staticsecret = local_jedomi_get_spt_secret();

    ksort($payload);
    $signature = hash_hmac('sha256', local_jedomi_spt_signing_string($payload), $staticsecret);

    $payload['signature'] = $signature;
    return $payload;
}

/**
 * Determine the Jedomi role of the current user from Moodle capabilities.
 *
 * Site admins map to 'admin', system-level user managers to 'manager',
 * users who can manage activities in the current course to 'teacher',
 * and everyone else to 'student'.
 *
 * @return string 'admin', 'manager', 'teacher', or 'student'.
 */
function local_jedomi_get_user_role(): string {
    global $USER, $COURSE;

    // 1. Siteadmin → acceso total (arquetipo: admin)
    if (is_siteadmin()) {
        return 'admin';
    }

    $sysctx = context_system::instance();

    // 2. Manager: has moodle/user:update at system level but is NOT a site admin.
    // This covers Moodle's 'manager' archetype and any custom role
    // with equivalent privileges (managing users/courses without being an admin).
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

    // 4. Outside a specific course: student with their own tools (grades, calendar, enrolled courses).
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
 * Whether a role may run every web service function: only the administrator, and only while no list
 * has been saved for it. A saved list (even an empty one) limits the role to the functions in it.
 *
 * @param string $role The role shortname (admin|manager|teacher|student).
 * @return bool
 */
function local_jedomi_role_has_full_ws_access(string $role): bool {
    return $role === 'admin' && get_config('local_jedomi', 'ws_allowlist_admin') === false;
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
 * @return string         'Lectura', 'Escritura', or ''.
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
    $writepattern = '/^(create|update|delete|add|remove|set|edit|save|insert|upload|import'
        . '|purge|reset|restore|reorder|toggle|approve|reject|block|unblock|lock|unlock'
        . '|archive|trash|publish|unpublish|submit|enrol|unenrol|assign|unassign|mark|flag'
        . '|clear|move|copy|duplicate|switch|change|revoke|grant)$/i';
    if (preg_match($writepattern, $action)) {
        return 'Escritura';
    }
    if (preg_match('/_(create|update|delete|add|remove|save|insert|upload|import|purge|enrol|assign|grant|revoke)_/i', $wsname)) {
        return 'Escritura';
    }

    return '';
}

if (!defined('LOCAL_JEDOMI_LOG_MAX_BYTES')) {
    /** Size cap of the active jedomi.log before it rotates (5 MB). */
    define('LOCAL_JEDOMI_LOG_MAX_BYTES', 5 * 1024 * 1024);
    /** Rotated log files kept (jedomi.log.1 … .N); older content is dropped. */
    define('LOCAL_JEDOMI_LOG_MAX_FILES', 5);
}

if (!function_exists('local_jedomi_rotate_log')) {
    /**
     * Rotate the jedomi log: shift jedomi.log.N-1 → .N and jedomi.log → .1.
     *
     * The size check and the whole rename chain run under a non-blocking
     * inter-process lock (flock on jedomi.log.lock): a worker that does not
     * get the lock simply skips rotation — another worker is already doing
     * it — and continues appending. The size is re-checked under the lock
     * so the loser of a previous race never rotates a freshly rotated file.
     *
     * The oldest file (jedomi.log.N) is dropped. Every filesystem operation
     * is error-suppressed: a failed lock or rotation simply falls through to
     * the normal append and must never interrupt the calling flow.
     *
     * @param string $logfile  Absolute path of the active log file.
     * @param int    $maxfiles Number of rotated files to retain.
     * @param int    $maxbytes Size threshold re-checked under the lock.
     */
    function local_jedomi_rotate_log(string $logfile, int $maxfiles, int $maxbytes): void {
        $lockhandle = @fopen($logfile . '.lock', 'c');
        if ($lockhandle === false) {
            return; // Cannot lock: skip rotation rather than risk interleaving.
        }
        if (!@flock($lockhandle, LOCK_EX | LOCK_NB)) {
            @fclose($lockhandle);
            return; // Another worker is rotating: continue without rotating.
        }

        // Re-check under the lock: a concurrent worker may have just rotated.
        clearstatcache(true, $logfile);
        if ((int) @filesize($logfile) > $maxbytes) {
            @unlink($logfile . '.' . $maxfiles);
            for ($i = $maxfiles - 1; $i >= 1; $i--) {
                if (@file_exists($logfile . '.' . $i)) {
                    @rename($logfile . '.' . $i, $logfile . '.' . ($i + 1));
                }
            }
            @rename($logfile, $logfile . '.1');
        }

        @flock($lockhandle, LOCK_UN);
        @fclose($lockhandle);
    }
}

/**
 * Remove from the data of a log entry the values that can hold personal data.
 *
 * Tool arguments, queries, web service parameters, prompts and messages are replaced by their size, so the
 * technical log never keeps them even if a caller passes them.
 *
 * @param mixed $data Data of the log entry.
 * @return mixed Data with the sensitive values replaced.
 */
function local_jedomi_redact_log_data(mixed $data): mixed {
    if (!is_array($data)) {
        return $data;
    }
    $sensitive = ['args', 'arguments', 'sql', 'query', 'params', 'parameters', 'prompt', 'messages', 'content', 'text'];
    foreach ($data as $key => $value) {
        if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
            $size = is_string($value) ? strlen($value) : strlen((string) json_encode($value));
            $data[$key] = '[redacted ' . $size . 'B]';
        } else if (is_array($value)) {
            $data[$key] = local_jedomi_redact_log_data($value);
        }
    }
    return $data;
}

if (!function_exists('local_jedomi_log')) {
    /**
     * Write an entry to the Jedomi debug log.
     *
     * WHAT IS LOGGED: the plugin's operational and security trace — HMAC
     * authentication failures (with source IP), rejected SQL statements and
     * blocked file paths, plugin configuration writes, and Telegram webhook
     * activity (chat ids, tool-loop checkpoints, gateway errors).
     *
     * WHERE AND RETENTION: entries go to {$CFG->dataroot}/local/jedomi/jedomi.log
     * in a human-readable format with timestamp and structured context. The
     * active file rotates by size once it exceeds LOCAL_JEDOMI_LOG_MAX_BYTES
     * (5 MB, overridable via $CFG->local_jedomi_logmaxbytes) and up to
     * LOCAL_JEDOMI_LOG_MAX_FILES (5) rotated files are kept — a deterministic
     * cap of ~30 MB. There is no time-based retention: entries survive until
     * newer traffic displaces them, oldest first.
     *
     * @param string $message Short description of what happened.
     * @param mixed  $data    Optional context data (array, string, or anything json-serializable).
     */
    function local_jedomi_log(string $message, mixed $data = null): void {
        global $CFG;

        // Re-check on every call: the directory can vanish mid-process
        // (dataroot cleanup, PHPUnit reset) and logging must self-heal.
        $logdir = $CFG->dataroot . '/local/jedomi';
        if (!check_dir_exists($logdir, true, true)) {
            return; // Logging must never break the caller.
        }
        $logfile = $logdir . '/jedomi.log';

        // Size-based rotation keeps dataroot growth bounded (~30 MB max).
        // Fast path: only touch the lock file when the size looks over the
        // cap; the helper re-checks and rotates under an inter-process lock.
        $maxbytes = (int) ($CFG->local_jedomi_logmaxbytes ?? LOCAL_JEDOMI_LOG_MAX_BYTES);
        if ($maxbytes > 0 && (int) @filesize($logfile) > $maxbytes) {
            local_jedomi_rotate_log($logfile, LOCAL_JEDOMI_LOG_MAX_FILES, $maxbytes);
        }

        $timestamp = date('Y-m-d H:i:s.v');
        $entry = "[{$timestamp}] {$message}";

        if ($data !== null) {
            $encoded = json_encode(
                local_jedomi_redact_log_data($data),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE
            );
            $entry .= ' | ' . $encoded;
        }

        $entry .= "\n";
        // A broken log write must never interrupt the calling flow, hence the
        // error suppression. check_dir_exists() relies on the stat cache, so a
        // directory removed mid-process can pass the check above and still fail
        // here: retry once after clearing the cache and recreating it.
        if (@file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX) === false) {
            clearstatcache(true, $logdir);
            if (check_dir_exists($logdir, true, true)) {
                @file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
            }
        }
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

        // ... ── Extract headers ────────────────────────────────────────────
        $headers = [];
        if (preg_match('/<tr[^>]*>(.*?)<\/tr>/is', $tablehtml, $headerrow)) {
            preg_match_all('/<th[^>]*>(.*?)<\/th>/is', $headerrow[1], $headercells);
            foreach ($headercells[1] as $cell) {
                $headers[] = trim(strip_tags($cell));
            }
        }

        // ... ── Extract rows ───────────────────────────────────────────────
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

        // ... ── Build pipe-delimited output ────────────────────────────────
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
 * @param  string     $timezone  Time zone of the date shown (the agent creator's); 99 for the current user's.
 * @return bool                  True if sent successfully.
 */
function local_jedomi_send_agent_notification(int|string $chatid, string $agent, string $content, string $timezone = '99'): bool {
    global $CFG;

    // Sanitize HTML tables before any strip_tags call.
    $content = \local_jedomi_sanitize_html_tables($content);

    // ... ── Build caption (fits within Telegram's 1024-char limit) ─────────
    // Header with agent name + status + date.
    $agentlabel = strip_tags($agent);
    $datestr = userdate(time(), '', $timezone);
    $header = "🤖 {$agentlabel}\n✅ Executed successfully\n📅 {$datestr}\n\n";

    // Plain-text summary (no HTML) — truncate so header + wrapper fits ≤ 1024.
    $summary = mb_substr(strip_tags($content), 0, 1500);
    $maxsummarylen = 1024 - mb_strlen($header) - 7; // 7 = <i></i>
    if ($maxsummarylen < 0) {
        $maxsummarylen = 0;
    }
    $summary = mb_substr($summary, 0, $maxsummarylen);

    // ... ── Decision: message vs file ──────────────────────────────────────
    $client = \local_jedomi\telegram\api_client::instance();

    if (mb_strlen($content) < 3800) {
        // Full content fits in a single message — send it inline.
        $body = strip_tags($content);
        $msg = $header . "<i>" . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . "</i>";
        return $client->send_message($chatid, $msg);
    }

    // ... ── Large result → send as .txt file ──────────────────────────────
    // Extract a brief one-line description from the AI response (first meaningful line,
    // not a code fence or empty line). This goes in the caption so the user knows what
    // the result is about without seeing the actual data.
    $plaintext = strip_tags($content);
    $lines = explode("\n", $plaintext);
    $oneliner = '';
    foreach ($lines as $line) {
        $line = trim($line);
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
        if (!empty($line) && !str_starts_with($line, '```')) {
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
    // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- literal Markdown fences.
    if (preg_match('/```(?:\w+)?\s*\n(.+?)\n```/s', $content, $m)) {
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
