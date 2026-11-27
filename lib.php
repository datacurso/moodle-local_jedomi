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
 * @copyright  2026 Datacurso <developer@datacurso.com>
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
 * Serve the files of the plugin: the custom avatar of the panel, to logged-in users only.
 *
 * @param stdClass|null $course Course of the request (unused: the avatar is a site file).
 * @param stdClass|null $cm Course module of the request (unused).
 * @param context $context Context of the file; only the system context has files.
 * @param string $filearea File area; only "customavatar" is served.
 * @param array $args Revision (in place of the item id, ignored) followed by the path and name of the file.
 * @param bool $forcedownload Whether the file must be downloaded.
 * @param array $options Options for send_stored_file().
 * @return bool False when the file is not served.
 */
function local_jedomi_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM || $filearea !== \local_jedomi\local\personalization::FILEAREA) {
        return false;
    }
    // The panel is only shown to users logged in with their own account, so its avatar is too.
    if (!isloggedin() || isguestuser()) {
        return false;
    }
    // The item id of the address is the revision of the avatar (see personalization::custom_avatar_url()): it is
    // ignored, as the file is always stored with item id 0, so an address written before a change still works.
    array_shift($args);
    $filename = (string) array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_jedomi', $filearea, 0, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
    return true;
}

/**
 * Build the full sidebar config array (strings, endpoints, SPT, etc.).
 *
 * Never includes AI service URLs or keys: every AI call is made server-side.
 *
 * @param array $page Page the panel is on, as read when the page was rendered: see local_jedomi_get_page_context().
 * @return array
 */
function local_jedomi_get_sidebar_config(array $page = []): array {
    global $USER, $CFG;

    $context = local_jedomi_get_page_context($page);
    $voiceenabled = \local_jedomi\ai\model_settings::is_voice_enabled();
    $assistantname = \local_jedomi\local\personalization::assistant_name();

    $config = [
        'chatproxy_url' => $CFG->wwwroot . '/local/jedomi/chatproxy.php',
        'extract_file_url' => $CFG->wwwroot . '/local/jedomi/extract_file.php',
        'read_file_url' => $CFG->wwwroot . '/local/jedomi/read_file.php',
        // Hidden features are also refused by the server.
        'files_enabled' => \local_jedomi\ai\model_settings::is_files_enabled(),
        // With the custom service, documents need its documents switch and its document service.
        'documents_enabled' => \local_jedomi\ai\model_settings::is_documents_enabled(),
        'audio_enabled' => \local_jedomi\ai\model_settings::is_audio_enabled(),
        // Largest attached file: a larger one is refused before it is uploaded (the server refuses it too).
        'max_upload_bytes' => \local_jedomi\local\service\provider_upload_service::MAX_UPLOAD_BYTES,
        'voice_enabled' => $voiceenabled,
        'feedback_url'  => $CFG->wwwroot . '/local/jedomi/ajax/feedback.php',
        'user_name'     => fullname($USER),
        // Name, avatar and position of the assistant set in the Personalization tab.
        'assistant'     => local_jedomi_sidebar_assistant(),
        // Plain text: the panel template escapes it.
        'welcome_message' => \local_jedomi\local\personalization::welcome_message($USER, local_jedomi_page_course_name()),
        'user_role'     => local_jedomi_get_user_role(),
        'wwwroot'       => $CFG->wwwroot,
        'context'       => $context,
        'sesskey'       => sesskey(),
        // Time of the server, so the panel knows when its signed permissions expire on its own clock and renews them
        // before they do.
        'server_time'   => time(),
        'strings'       => [
            // UI chrome.
            'title'             => get_string('sidebar_title', 'local_jedomi'),
            'open_sidebar'      => get_string('open_sidebar', 'local_jedomi', $assistantname),
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
            'image_not_kept'    => get_string('image_not_kept', 'local_jedomi'),
            'message_input'     => get_string('message_input', 'local_jedomi'),
            'placeholder'       => get_string('chat_placeholder', 'local_jedomi', $assistantname),
            'send'              => get_string('send', 'local_jedomi'),
            'voice_input'       => get_string('voice_input', 'local_jedomi'),
            // Greeting.
            'thinking'          => get_string('thinking', 'local_jedomi'),
            'new_conversation_msg' => get_string('new_conversation_msg', 'local_jedomi'),
            'new_conversation_confirm' => get_string('new_conversation_confirm', 'local_jedomi'),
            'keyboard_shortcuts'   => get_string('keyboard_shortcuts', 'local_jedomi'),
            'stop_answer'          => get_string('stop_answer', 'local_jedomi'),
            'answer_stopped'       => get_string('answer_stopped', 'local_jedomi'),
            // Files.
            'analyze_file'         => get_string('analyze_file', 'local_jedomi'),
            'analyze_image'        => get_string('analyze_image', 'local_jedomi'),
            'attached_file_info'   => get_string('attached_file_info', 'local_jedomi'),
            'attached_image'       => get_string('attached_image', 'local_jedomi'),
            'csv_download'         => get_string('csv_download', 'local_jedomi'),
            // Export of the conversation and of the tables, and the labels of the Moodle links.
            'export_csv_filename'    => get_string('export_csv_filename', 'local_jedomi'),
            'export_filename'        => get_string('export_filename', 'local_jedomi'),
            'export_role_assistant'  => get_string('export_role_assistant', 'local_jedomi'),
            'export_role_user'       => get_string('export_role_user', 'local_jedomi'),
            'export_title'           => get_string('export_title', 'local_jedomi'),
            'link_external'          => get_string('link_external', 'local_jedomi'),
            'link_type_admin'        => get_string('link_type_admin', 'local_jedomi'),
            'link_type_assign'       => get_string('link_type_assign', 'local_jedomi'),
            'link_type_course'       => get_string('link_type_course', 'local_jedomi'),
            'link_type_grades'       => get_string('link_type_grades', 'local_jedomi'),
            'link_type_messages'     => get_string('link_type_messages', 'local_jedomi'),
            'link_type_moodle'       => get_string('link_type_moodle', 'local_jedomi'),
            'link_type_profile'      => get_string('link_type_profile', 'local_jedomi'),
            'link_type_quiz'         => get_string('link_type_quiz', 'local_jedomi'),
            'file_audio'           => get_string('file_audio', 'local_jedomi'),
            'file_csv'             => get_string('file_csv', 'local_jedomi'),
            'audio_error_disabled'   => get_string('audio_error_disabled', 'local_jedomi'),
            'file_attach_limit'      => get_string('file_attach_limit', 'local_jedomi'),
            'file_error_unsupported' => get_string('file_error_unsupported', 'local_jedomi'),
            'file_error_upload_too_large' => get_string(
                'file_error_upload_too_large',
                'local_jedomi',
                \local_jedomi\local\service\provider_upload_service::max_upload_mb()
            ),
            'file_remove'            => get_string('file_remove', 'local_jedomi'),
            'file_truncated'         => get_string('file_truncated', 'local_jedomi'),
            'files_error_disabled'   => get_string('files_error_disabled', 'local_jedomi'),
            'documents_error_custom' => get_string('documents_error_custom', 'local_jedomi'),
            'image_attach_limit'     => get_string('image_attach_limit', 'local_jedomi'),
            'image_error_unsupported' => get_string('image_error_unsupported', 'local_jedomi'),
            'file_pdf'             => get_string('file_pdf', 'local_jedomi'),
            'file_process_error'   => get_string('file_process_error', 'local_jedomi'),
            'file_text'            => get_string('file_text', 'local_jedomi'),
            'processing_file'      => get_string('processing_file', 'local_jedomi'),
            'reading_image'        => get_string('reading_image', 'local_jedomi'),
            // Footer / disclaimer.
            'ai_disclaimer'     => get_string('ai_disclaimer', 'local_jedomi', $assistantname),
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
            'tool_site_overview'     => get_string('tool_site_overview', 'local_jedomi'),
            'tool_ws_search'         => get_string('tool_ws_search', 'local_jedomi'),
            'tool_ws_describe'       => get_string('tool_ws_describe', 'local_jedomi'),
            'tool_ws_execute'        => get_string('tool_ws_execute', 'local_jedomi'),
            'tool_unknown'           => get_string('tool_unknown', 'local_jedomi'),
            'still_working'          => get_string('still_working', 'local_jedomi'),
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
            'feedback_comment'       => get_string('feedback_comment', 'local_jedomi'),
            'feedback_comment_saved' => get_string('feedback_comment_saved', 'local_jedomi'),
            'feedback_comment_send'  => get_string('feedback_comment_send', 'local_jedomi'),
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
            'voice_too_long'        => get_string('voice_too_long', 'local_jedomi'),
            'voice_too_short'       => get_string('voice_too_short', 'local_jedomi'),
            'waiting_voice'         => get_string('waiting_voice', 'local_jedomi'),
            // Voice conversation.
            'voice_mode'                     => get_string('voice_mode', 'local_jedomi'),
            'voice_mode_busy_pending'        => get_string('voice_mode_busy_pending', 'local_jedomi'),
            'voice_mode_busy_recording'      => get_string('voice_mode_busy_recording', 'local_jedomi'),
            'voice_mode_busy_sending'        => get_string('voice_mode_busy_sending', 'local_jedomi'),
            'voice_mode_confirm_notice'      => get_string('voice_mode_confirm_notice', 'local_jedomi'),
            'voice_mode_exit'                => get_string('voice_mode_exit', 'local_jedomi'),
            'voice_mode_idle_exit'           => get_string('voice_mode_idle_exit', 'local_jedomi'),
            'voice_mode_listening'           => get_string('voice_mode_listening', 'local_jedomi'),
            'voice_mode_paused'              => get_string('voice_mode_paused', 'local_jedomi'),
            'voice_mode_speaking'            => get_string('voice_mode_speaking', 'local_jedomi'),
            'voice_mode_speech_error'        => get_string('voice_mode_speech_error', 'local_jedomi'),
            // Reasons a reading aloud is refused, by the code of speech.php (shown when its answer has no message).
            'speech_error_disabled'          => get_string('speech_error_disabled', 'local_jedomi'),
            'speech_error_empty'             => get_string('speech_error_empty', 'local_jedomi'),
            'speech_error_rate_limited'      => get_string('speech_error_rate_limited', 'local_jedomi'),
            'speech_error_too_long'          => get_string(
                'speech_error_too_long',
                'local_jedomi',
                \local_jedomi\local\service\speech_service::MAX_CHARS
            ),
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
            'pending_action_truncated' => get_string('pending_action_truncated', 'local_jedomi'),
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
 * Name, avatar and position of the assistant for the panel, from the Personalization tab.
 *
 * @return array{name: string, avatar_url: string|null, corner: string, side: string, x: int, y: int}
 */
function local_jedomi_sidebar_assistant(): array {
    $position = \local_jedomi\local\personalization::position();
    return [
        'name' => \local_jedomi\local\personalization::assistant_name(),
        'avatar_url' => \local_jedomi\local\personalization::avatar_url(),
        'corner' => $position['corner'],
        'side' => $position['side'],
        'x' => $position['x'],
        'y' => $position['y'],
    ];
}

/**
 * Name of the course the panel is on, as plain text for the welcome message; '' outside a course.
 *
 * @return string
 */
function local_jedomi_page_course_name(): string {
    global $COURSE;
    $courseid = (int) ($COURSE->id ?? 0);
    if ($courseid <= SITEID) {
        return '';
    }
    return format_string($COURSE->fullname ?? '', true, ['context' => context_course::instance($courseid), 'escape' => false]);
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
 * Adds the "Jedomi Telegram" and "Jedomi on WhatsApp" links to the user profile navigation.
 * Each one is shown when its channel is available and the role of the user is allowed, or the user is linked.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass $course
 */
function local_jedomi_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course): void {
    global $DB, $USER;

    if (!$iscurrentuser) {
        return;
    }

    if (!plugin_settings::is_enabled('local_jedomi')) {
        return;
    }

    $nodes = [];

    // Require Telegram enabled AND bot token verified by getMe().
    // If the admin hasn't pasted a valid token yet, don't expose the link.
    if (\local_jedomi\telegram\manager::is_enabled() && get_config('local_jedomi', 'telegram_bot_verified')) {
        // A linked user keeps the way to the link page, where the link can always be removed.
        $linked = \local_jedomi\local\service\telegram_link_service::get_confirmed_link((int) $USER->id) !== null;
        if ($linked || local_jedomi_is_telegram_role_allowed()) {
            $nodes[] = new core_user\output\myprofile\node(
                'jedomi',
                'telegram',
                get_string('telegram_link_title', 'local_jedomi'),
                null,
                new moodle_url('/local/jedomi/telegram_profile.php', ['userid' => $user->id])
            );
        }
    }

    // WhatsApp needs the channel on, its settings saved and the number students write to.
    if (
        \local_jedomi\whatsapp\manager::is_enabled()
            && \local_jedomi\whatsapp\manager::is_configured()
            && \local_jedomi\whatsapp\manager::get_display_number() !== ''
    ) {
        $linked = $DB->record_exists(\local_jedomi\local\service\whatsapp_link_service::LINKS_TABLE, ['userid' => $USER->id]);
        if ($linked || local_jedomi_channel_role_allowed_for('whatsapp', (int) $USER->id)) {
            $nodes[] = new core_user\output\myprofile\node(
                'jedomi',
                'whatsapp',
                get_string('whatsapp_link_title', 'local_jedomi'),
                null,
                new moodle_url('/local/jedomi/whatsapp_profile.php', ['userid' => $user->id])
            );
        }
    }

    if ($nodes === []) {
        return;
    }
    $tree->add_category(new core_user\output\myprofile\category(
        'jedomi',
        get_string('pluginname', 'local_jedomi'),
        null
    ));
    foreach ($nodes as $node) {
        $tree->add_node($node);
    }
}

/**
 * Context of the page the panel is on, sent to the AI with every question.
 *
 * The configuration of the panel is requested by AJAX, where the current page is the endpoint, so the page is described
 * by the values read when it was rendered (see \local_jedomi\hook_callbacks::before_footer()). They come back from the
 * browser, so they are checked: the activity must belong to the course of the request and be visible to the user,
 * the page type is a Moodle page type name and the path a plain path of the site. The course is the current course,
 * already checked by the caller.
 *
 * @param array $page Page data: cmid (course module), pagetype (Moodle page type) and path (path relative to the site).
 * @return array Page context: location, course and activity details.
 */
function local_jedomi_get_page_context(array $page = []): array {
    global $COURSE, $USER;

    $courseid = (int) ($COURSE->id ?? 0);
    $path = local_jedomi_clean_page_path((string) ($page['path'] ?? ''));
    $pagetype = clean_param((string) ($page['pagetype'] ?? ''), PARAM_ALPHANUMEXT);
    $context  = [
        'page_url'      => $path,
        'page_type'     => $pagetype,
        'page_title'    => '',
        'user_id'       => $USER->id ?? 0,
        'user_fullname' => fullname($USER),
        'user_role'     => local_jedomi_get_user_role(),
        'course_id'     => 0,
        'course_name'   => '',
        'activity_id'   => 0,
        'activity_type' => '',
        'activity_name' => '',
        'location'      => 'unknown',
    ];

    if ($courseid > SITEID) {
        $context['course_id']   = $courseid;
        $context['course_name'] = format_string($COURSE->fullname ?? '', true, ['context' => context_course::instance($courseid)]);
    }

    $cm = local_jedomi_page_activity($courseid, (int) ($page['cmid'] ?? 0));
    if ($cm !== null) {
        $context['activity_id']   = (int) $cm->id;
        $context['activity_type'] = $cm->modname;
        $context['activity_name'] = $cm->get_formatted_name();
    }

    $context['location'] = local_jedomi_page_location($path, $pagetype, $courseid > SITEID, $cm !== null);
    return $context;
}

/**
 * An activity of a page, when it belongs to the course of the request and the user can see it.
 *
 * @param int $courseid Course of the request (the site course on the site home).
 * @param int $cmid Course module of the page; 0 for none.
 * @return cm_info|null The activity, or null when there is none or it cannot be used.
 */
function local_jedomi_page_activity(int $courseid, int $cmid): ?cm_info {
    if ($cmid <= 0) {
        return null;
    }
    try {
        $cm = get_fast_modinfo($courseid > SITEID ? $courseid : SITEID)->get_cm($cmid);
    } catch (\moodle_exception $e) {
        return null;
    }
    return $cm->uservisible ? $cm : null;
}

/**
 * Path of a page of the site, cleaned: only a plain path such as "/user/files.php", without query string or fragment.
 *
 * @param string $path Path sent with the request.
 * @return string The path, or '' when it is not a plain path of the site.
 */
function local_jedomi_clean_page_path(string $path): string {
    $path = (string) preg_replace('/[?#].*$/s', '', trim($path));
    if (strlen($path) > 255 || !preg_match('#^/[A-Za-z0-9_./-]*$#', $path) || strpos($path, '..') !== false) {
        return '';
    }
    return $path;
}

/**
 * Location of a page for the AI and the quick actions of the panel.
 *
 * The sections of the site win (administration, grades, private files, calendar, messages, dashboard and profile),
 * then the activity and then the course. Any other page is "unknown".
 *
 * @param string $path Path of the page relative to the site, already cleaned.
 * @param string $pagetype Moodle page type, already cleaned.
 * @param bool $incourse Whether the page is inside a course (not the site home).
 * @param bool $inactivity Whether the page is an activity the user can see.
 * @return string course, activity, grades, admin, dashboard, messages, profile, calendar, files or unknown.
 */
function local_jedomi_page_location(string $path, string $pagetype, bool $incourse, bool $inactivity): string {
    $sections = [
        'admin'     => ['paths' => ['/admin/'], 'types' => ['admin-']],
        'grades'    => ['paths' => ['/grade/'], 'types' => ['grade-']],
        'files'     => ['paths' => ['/user/files.php'], 'types' => ['user-files']],
        'calendar'  => ['paths' => ['/calendar/'], 'types' => ['calendar-']],
        'messages'  => ['paths' => ['/message/'], 'types' => ['message-']],
        'dashboard' => ['paths' => ['/my/'], 'types' => ['my-']],
        'profile'   => ['paths' => ['/user/profile.php', '/user/view.php', '/user/edit.php', '/user/editadvanced.php',
            '/user/preferences.php'], 'types' => ['user-profile', 'user-view', 'user-edit', 'user-preferences']],
    ];
    foreach ($sections as $location => $rules) {
        foreach ($rules['paths'] as $prefix) {
            if ($path !== '' && strpos($path, $prefix) === 0) {
                return $location;
            }
        }
        foreach ($rules['types'] as $prefix) {
            if ($pagetype !== '' && strpos($pagetype, $prefix) === 0) {
                return $location;
            }
        }
    }
    if ($inactivity) {
        return 'activity';
    }
    return $incourse ? 'course' : 'unknown';
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
        return ['courseid' => 0, 'error' => [
            'code' => 'invalid_spt',
            'message' => \local_jedomi\local\safe_error::for_user($e, 'spt'),
        ]];
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
    $real = local_jedomi_user_role($userid, $courseid);

    if (!isset($rank[$presented]) || $rank[$presented] > $rank[$real]) {
        return $real;
    }
    return $presented;
}

/**
 * Get tool definitions for a given role in OpenAI function-calling format.
 * These are embedded in the SPT so the gateway can configure the model.
 *
 * The administration operations (admin_search, admin_describe, admin_execute) are offered only to a user who can run
 * them (see \local_jedomi\schema\cli_registry::can_execute()); searching and describing operations that cannot be run
 * would only lead to an action refused after its confirmation.
 *
 * @param  string   $role User role: admin, manager, teacher, student.
 * @param  int|null $userid User the tools are offered to; null for the current user.
 * @param  string   $channel Channel the tools are offered in (web, telegram or agent): the query tool tells its limits.
 * @return array        Array of OpenAI-style tool definitions.
 */
function local_jedomi_get_tool_definitions(string $role, ?int $userid = null, string $channel = 'web'): array {
    global $USER;
    $tools = [];
    $userid = $userid ?? (int) ($USER->id ?? 0);

    // Describe_table and search_tables — only the roles that can query the database (the same rule as Telegram):
    // table structures and names are of no use to who cannot query them.
    if (\local_jedomi\local\query_runner::role_can_query($role)) {
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
    }

    // Execute_sql — admin, manager, teacher.
    if (in_array($role, ['admin', 'manager', 'teacher'], true)) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => 'execute_sql',
                'description' => get_string('tool_execute_sql_desc', 'local_jedomi', [
                    'rows' => \local_jedomi\local\query_runner::row_limit($channel),
                    'seconds' => (int) (\local_jedomi\local\query_runner::time_limit_ms($channel) / 1000),
                ]),
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

    // Site_overview — admin, manager. The overall state of the platform in one call.
    if (\local_jedomi\agent\tool\site_overview_tool::is_offered($role)) {
        $tools[] = \local_jedomi\agent\tool\site_overview_tool::definition();
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

    // Admin CLI tools — search, describe, execute admin operations not exposed via WS; only for who can run them.
    if (in_array($role, ['admin', 'manager'], true) && \local_jedomi\schema\cli_registry::can_execute($userid)) {
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
 * The secret is kept encrypted with the key of the site (JEDOMI-SEC-010); one saved in clear text
 * before 1.8.0 is still read.
 *
 * @return string The site secret, at least 64 characters when generated.
 */
function local_jedomi_get_spt_secret(): string {
    return \local_jedomi\local\secret_store::get_or_generate('spt_secret', 32, ['jedomi-spt-secret']);
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
    global $USER, $COURSE;

    $coursectx = (!empty($COURSE->id) && $COURSE->id > 1)
                    ? context_course::instance($COURSE->id)
                    : context_system::instance();
    [$courseidsowned, $courseidsenrolled] = \local_jedomi\spt::course_ids((int) $USER->id, $role);

    $payload = \local_jedomi\spt::payload(
        (int) $USER->id,
        $USER->username,
        $role,
        (int) ($COURSE->id ?? 0),
        $courseidsowned,
        $courseidsenrolled,
        \local_jedomi\spt::capabilities($coursectx),
        local_jedomi_get_tool_definitions($role)
    );
    // The sesskey() is part of the payload (not the key) to bind the token to the session:
    // if the session changes, the payload changes and the SPT is invalid anyway.
    $payload['sesskey_hash'] = substr(hash('sha256', sesskey()), 0, 16); // Binding without exposing the sesskey.
    return \local_jedomi\spt::sign($payload);
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
    global $DB;

    $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, username, firstname, lastname');
    if (!$user) {
        return [];
    }
    [$courseidsowned, $courseidsenrolled] = \local_jedomi\spt::course_ids($userid, $role);

    return \local_jedomi\spt::sign(\local_jedomi\spt::payload(
        $userid,
        $user->username,
        $role,
        0,
        $courseidsowned,
        $courseidsenrolled,
        // Capabilities for this specific user (not global $USER), at system level.
        \local_jedomi\spt::capabilities(context_system::instance(), $userid),
        local_jedomi_get_tool_definitions($role, (int) $userid)
    ));
}

/**
 * The Jedomi role of a user, the same in every channel (web chat, Telegram, confirmation of actions and agents).
 *
 * Site admins are 'admin' and users who can update users at system level 'manager'. Inside a course a user who can
 * manage its activities is 'teacher'; outside a course (the site home, the dashboard, a profile, Telegram) a user who
 * can manage the activities of any course in Jedomi they are enrolled in is 'teacher': excluded courses are ignored
 * ({@see \local_jedomi\course_access::is_course_in_jedomi()}). Everyone else is 'student'.
 *
 * @param int $userid User.
 * @param int $courseid Course of the request; 0 or the site course outside a course.
 * @return string 'admin', 'manager', 'teacher' or 'student'.
 */
function local_jedomi_user_role(int $userid, int $courseid): string {
    if ($userid <= 0) {
        return 'student';
    }
    if (is_siteadmin($userid)) {
        return 'admin';
    }
    if (has_capability('moodle/user:update', context_system::instance(), $userid)) {
        return 'manager';
    }

    if ($courseid > SITEID) {
        $context = context_course::instance($courseid, IGNORE_MISSING);
        return $context && has_capability('moodle/course:manageactivities', $context, $userid) ? 'teacher' : 'student';
    }

    foreach (enrol_get_users_courses($userid, true, ['id']) as $course) {
        if (!\local_jedomi\course_access::is_course_in_jedomi((int) $course->id)) {
            // An excluded course is outside Jedomi: it gives no role outside a course.
            continue;
        }
        if (has_capability('moodle/course:manageactivities', context_course::instance($course->id), $userid)) {
            return 'teacher';
        }
    }
    return 'student';
}

/**
 * Determine the Jedomi role of the current user in the current course: see local_jedomi_user_role().
 *
 * @return string 'admin', 'manager', 'teacher', or 'student'.
 */
function local_jedomi_get_user_role(): string {
    global $USER, $COURSE;
    return local_jedomi_user_role((int) ($USER->id ?? 0), (int) ($COURSE->id ?? 0));
}

/**
 * Determine a user's Jedomi role outside a course, without depending on $COURSE or $PAGE globals.
 *
 * Used by the Telegram webhook, the confirmation of actions and the agents, where there is no current course: see
 * local_jedomi_user_role().
 *
 * @param  int    $userid The user ID to check.
 * @return string         'admin', 'manager', 'teacher', or 'student'.
 */
function local_jedomi_get_user_role_for_user(int $userid): string {
    return local_jedomi_user_role($userid, 0);
}

/**
 * Whether the Telegram role rule lets the current user link the account and use the bot.
 *
 * The profile link and the bot apply the same rule: see local_jedomi_telegram_role_allowed_for().
 *
 * @return bool
 */
function local_jedomi_is_telegram_role_allowed(): bool {
    global $USER;
    return local_jedomi_telegram_role_allowed_for((int) ($USER->id ?? 0));
}

/**
 * Whether the Telegram role rule lets a user use the bot and receive its notifications.
 *
 * The profile link, the bot and the notifications all use this rule: local_jedomi_channel_role_allowed_for() for the
 * telegram channel.
 *
 * @param int $userid Moodle user id.
 * @return bool
 */
function local_jedomi_telegram_role_allowed_for(int $userid): bool {
    return local_jedomi_channel_role_allowed_for('telegram', $userid);
}

/**
 * Whether the role rule of a messaging channel lets a user use the channel.
 *
 * Site administrators are always allowed. Otherwise the user is blocked only when every role they hold, at system
 * level or in any course they are enrolled in, is in the {channel}_hidden_roles setting. A user without roles is
 * allowed.
 *
 * @param string $channel Channel name, the prefix of its settings, e.g. telegram.
 * @param int $userid Moodle user id.
 * @return bool
 */
function local_jedomi_channel_role_allowed_for(string $channel, int $userid): bool {
    if ($userid <= 0) {
        return false;
    }
    if (is_siteadmin($userid)) {
        return true;
    }

    $hidden = (string) (get_config('local_jedomi', $channel . '_hidden_roles') ?: '');
    $hiddenids = array_filter(array_map('intval', explode(',', $hidden)));
    if (empty($hiddenids)) {
        return true; // No restriction configured.
    }

    $contexts = [context_system::instance()];
    foreach (enrol_get_all_users_courses($userid) as $course) {
        $contexts[] = context_course::instance($course->id);
    }
    $roleids = [];
    foreach ($contexts as $context) {
        foreach (get_user_roles($context, $userid, false) as $role) {
            $roleids[(int) $role->roleid] = true;
        }
    }
    if (empty($roleids)) {
        return true; // No role anywhere: allowed.
    }

    // Allowed when at least one of the roles is not hidden.
    return !empty(array_diff(array_keys($roleids), $hiddenids));
}

/**
 * The account of a linked Telegram user when it can still be served: it exists, is not suspended or deleted, can log
 * in and is not the guest user.
 *
 * @param int $userid Moodle user id.
 * @return stdClass|null The user record, or null when the account can no longer be served.
 */
function local_jedomi_telegram_active_user(int $userid): ?stdClass {
    return (new \local_jedomi\messaging\access_guard('telegram'))->active_user($userid);
}

/**
 * The confirmed Telegram link of a user who may use the bot and receive its notifications now.
 *
 * Jedomi and the Telegram integration must be enabled, the user must have a confirmed link used within the last 30
 * days, an active account and a role allowed by the Telegram role rule. The bot checks it on every message and during a
 * request in progress, and the notifications check it before each message.
 *
 * @param int $userid Moodle user id.
 * @return stdClass|null The confirmed link, or null when the user may not be served.
 */
function local_jedomi_telegram_recipient(int $userid): ?stdClass {
    global $DB;
    $guard = new \local_jedomi\messaging\access_guard('telegram');
    if ($guard->channel_denial() !== null) {
        return null;
    }
    $link = $DB->get_record('local_jedomi_telegram_links', ['userid' => $userid, 'confirmed' => 1]);
    if (
        !$link || \local_jedomi\local\service\telegram_link_service::is_expired($link)
            || $guard->served_user($userid) === null
    ) {
        return null;
    }
    return $link;
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
 * Pseudonym of a personal identifier for the technical log.
 *
 * The log never keeps Moodle user ids or Telegram chat ids: it keeps a keyed hash of them (HMAC-SHA256 with a random
 * key of the site, truncated), so the entries of one user or chat can still be followed in the log but cannot be
 * linked back to a person from the log alone, nor matched between sites. Every log entry uses this one helper.
 *
 * @param string $kind What the identifier is ("user" or "chat"), part of the hash and of the result.
 * @param int|string|null $value The identifier.
 * @return string Pseudonym such as "user#3f9a0c12b7de".
 */
function local_jedomi_log_pseudonym(string $kind, int|string|null $value): string {
    $value = trim((string) $value);
    if ($value === '' || $value === '0') {
        return $kind . '#none';
    }
    return $kind . '#' . substr(hash_hmac('sha256', $kind . '|' . $value, local_jedomi_log_key()), 0, 12);
}

/**
 * Random key of the site used to pseudonymise the identifiers of the technical log, created the first time it is needed.
 *
 * @return string
 */
function local_jedomi_log_key(): string {
    $key = (string) get_config('local_jedomi', 'log_key');
    if ($key === '') {
        $key = bin2hex(random_bytes(32));
        set_config('log_key', $key, 'local_jedomi');
    }
    return $key;
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
    // The error of a tool result can quote a query or the database error (JEDOMI-SEC-009). The "detail" of the AI client
    // is kept: it only holds an HTTP status or a cURL error number (error_message::log_detail()).
    $sensitive = ['args', 'arguments', 'sql', 'query', 'params', 'parameters', 'prompt', 'messages', 'content', 'text', 'error'];
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
     * authentication failures, rejected SQL statements and blocked file paths
     * (these security failures keep the source IP), plugin configuration
     * writes, agent runs and Telegram webhook activity (tool-loop checkpoints,
     * gateway errors). Moodle user ids and Telegram chat ids are never written:
     * callers pass them through local_jedomi_log_pseudonym().
     *
     * WHERE AND RETENTION: entries go to {$CFG->dataroot}/local/jedomi/jedomi.log
     * in a human-readable format with timestamp and structured context. The
     * active file rotates by size once it exceeds LOCAL_JEDOMI_LOG_MAX_BYTES
     * (5 MB, overridable with the plugin setting logmaxbytes, or the older
     * $CFG->local_jedomi_logmaxbytes) and up to
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
        // The cap is the plugin setting logmaxbytes (for example in config.php:
        // $CFG->forced_plugin_settings['local_jedomi']['logmaxbytes']); the older
        // $CFG->local_jedomi_logmaxbytes is still read when the setting is not set.
        try {
            $configured = get_config('local_jedomi', 'logmaxbytes');
        } catch (\Throwable $e) {
            $configured = false; // Logging must never break the caller.
        }
        if ($configured === false || $configured === null || $configured === '') {
            $configured = $CFG->local_jedomi_logmaxbytes ?? LOCAL_JEDOMI_LOG_MAX_BYTES;
        }
        $maxbytes = (int) $configured;
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
    $header = "🤖 {$agentlabel}\n✅ " . get_string('agent_notice_success', 'local_jedomi') . "\n📅 {$datestr}\n\n";

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

    // The file keeps the whole answer in order: every code or preformatted block and the text between them.
    // Only HTML tags are removed, so a comparison such as "x <5" stays.
    $filecontent = preg_replace('/<\/?[a-zA-Z][^<>]*>/', '', $content) ?? strip_tags($content);
    $filecontent = trim(html_entity_decode($filecontent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $bytes = file_put_contents($filepath, $filecontent);

    $sent = $client->send_document($chatid, $filepath, $caption);

    // Clean up — file was sent, no longer needed.
    @unlink($filepath);

    return $sent;
}
