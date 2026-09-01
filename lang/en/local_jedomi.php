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
 * English language strings for local_jedomi.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// ── Plugin identity ───────────────────────────────────────────────────────────

$string['pluginname']      = 'Jedomi AI Sidebar';

// ── Sidebar UI ────────────────────────────────────────────────────────────────
$string['sidebar_title']   = 'Jedomi AI';
$string['open_sidebar']    = 'Open Jedomi AI';
$string['close']           = 'Close (Esc)';
$string['new_conversation'] = 'New conversation';
$string['export_conversation'] = 'Export conversation';
$string['toggle_thinking'] = 'Toggle thinking animation';
$string['half_width']      = 'Half width';
$string['full_width']      = 'Full width';

// ── Input area ────────────────────────────────────────────────────────────────
$string['attach']           = 'Attach';
$string['attach_file']      = 'Attach PDF/CSV';
$string['attach_image']     = 'Attach image';
$string['chat_placeholder'] = 'Ask Jedomi...';
$string['file']             = 'File';
$string['image']            = 'Image';
$string['message_input']    = 'Message Jedomi';
$string['send']             = 'Send';
$string['voice_input']      = 'Voice input';

// ── Greeting ──────────────────────────────────────────────────────────────────
$string['greeting']        = 'Hi, {name}!';
$string['hello']           = "I'm Jedomi, your Moodle AI assistant. Ask me about courses, users, grades or settings.";
$string['thinking']        = 'Thinking...';

// ── New conversation ──────────────────────────────────────────────────────────
$string['new_conversation_msg'] = 'New conversation started. How can I help you?';

// ── Image / file attachment ───────────────────────────────────────────────────
$string['analyze_image']       = 'Analyze this image.';
$string['attached_file_info']  = '[Attached file: {$a}]';
$string['attached_image']      = 'Attached image';
$string['csv_download']        = '📥 Download CSV';
$string['file_csv']            = 'CSV: {rows} rows, {columns} columns';
$string['file_loaded']         = 'loaded';
$string['file_loaded_hint']    = 'You can ask questions about its content.';
$string['file_pdf']            = 'PDF: {pages} pages';
$string['file_process_error']  = 'Could not process';
$string['file_text']           = 'Text: {chars} characters';
$string['processing_file']     = 'Processing {$a}...';

// ── Tool execution status ─────────────────────────────────────────────────────
$string['tool_sql_read']          = 'Querying database...';
$string['tool_api_call']          = 'Executing Moodle action...';
$string['tool_schema_lookup']     = 'Looking up table...';
$string['tool_webservice_lookup'] = 'Looking up API function...';
$string['tool_get_site_config']    = 'Getting site configuration...';
$string['tool_execute_sql']       = 'Querying database...';
$string['tool_search_tables']     = 'Searching tables...';
$string['tool_describe_table']    = 'Analyzing table...';
$string['tool_ws_search']         = 'Looking up webservice functions...';
$string['tool_ws_describe']       = 'Getting webservice function details...';
$string['tool_ws_execute']        = 'Executing web service...';
$string['tool_unknown']           = 'Running tool...';

// ── Message actions ───────────────────────────────────────────────────────────
$string['action_copy']         = 'Copy';
$string['action_helpful']      = 'Helpful';
$string['action_improve']      = 'Improve';
$string['action_improve_title'] = 'Needs work';
$string['action_edit']         = 'Edit';
$string['action_edit_title']   = 'Edit message';

// ── Bulk message confirmation ─────────────────────────────────────────────────
$string['confirm_send_bulk']       = 'Send this message to {count} student(s)?';
$string['yes_send']                = 'Yes, send';
$string['cancel']                  = 'Cancel';
$string['sending']                 = 'Sending...';
$string['cancelled']               = 'Cancelled.';
$string['messages_sent']           = '{sent} message(s) sent as Moodle internal messages.';
$string['messages_sent_fail']      = '({failed} failed)';
$string['messages_recipients_note'] = 'Recipients will see them in their Moodle message inbox.';
$string['no_messages_sent']        = 'No messages sent.';
$string['unknown_error']           = 'Unknown error';
$string['connection_error']        = 'Connection error:';

// ── Gateway error ─────────────────────────────────────────────────────────────
$string['gateway_error_title'] = 'Could not connect to the Jedomi Gateway.';
$string['gateway_error_hint']  = 'Make sure the service is running:';

// ── Session expired ───────────────────────────────────────────────────────────
$string['session_expired_title'] = 'Session expired.';
$string['session_expired_hint']  = 'Your Moodle session has expired. Please reload the page to continue.';

// ── Microphone ────────────────────────────────────────────────────────────────
$string['listening']        = 'Listening...';
$string['mic_error']        = 'Could not access the microphone. Please check your browser permissions.';
$string['silence_detected'] = 'Silence...';
$string['transcribing']     = 'Transcribing...';
$string['waiting_voice']    = 'Waiting for voice...';

// ── Confirmation system (destructive actions) ────────────────────────────────
$string['confirm_type_to_proceed'] = 'Type CONFIRM to proceed:';
$string['confirm_btn_yes']         = 'Confirm';
$string['confirm_btn_no']          = 'Cancel';
$string['confirm_executing']       = 'Executing...';
$string['confirm_done']            = 'Action executed successfully.';
$string['confirm_cancelled']       = 'Action cancelled.';

// ── Context labels (sidebar badge) ───────────────────────────────────────────
$string['ctx_activity']  = 'Activity';
$string['ctx_admin']     = 'Administration';
$string['ctx_calendar']  = 'Calendar';
$string['ctx_course']    = 'Course';
$string['ctx_dashboard'] = 'Dashboard';
$string['ctx_files']     = 'Files';
$string['ctx_grades']    = 'Grades';
$string['ctx_messages']  = 'Messages';
$string['ctx_profile']   = 'Profile';

// ── Quick actions: Admin ──────────────────────────────────────────────────────
$string['qa_admin_active_users_label']    = 'Active users';
$string['qa_admin_active_users_msg']      = 'How many active users are there in the system?';
$string['qa_admin_alerts_label']          = 'Alerts';
$string['qa_admin_alerts_msg']            = 'What requires immediate attention today?';
$string['qa_admin_at_risk_label']         = 'At risk';
$string['qa_admin_at_risk_msg']           = 'Which students are at risk of failing in course {$a}?';
$string['qa_admin_empty_cohorts_label']   = 'Empty cohorts';
$string['qa_admin_empty_cohorts_msg']     = 'Are there cohorts with no members assigned?';
$string['qa_admin_external_emails_label'] = 'External emails';
$string['qa_admin_external_emails_msg']   = 'Are there users with non-institutional emails?';
$string['qa_admin_gdpr_pending_label']    = 'GDPR pending';
$string['qa_admin_gdpr_pending_msg']      = 'Are there pending GDPR deletion requests?';
$string['qa_admin_inactive_label']        = 'Inactive';
$string['qa_admin_inactive_msg']          = 'Which students haven\'t accessed course {$a} in the last 30 days?';
$string['qa_admin_missing_work_label']    = 'Missing work';
$string['qa_admin_missing_work_msg']      = 'Which students haven\'t submitted activities in course {$a}?';
$string['qa_admin_overview_label']        = 'Overview';
$string['qa_admin_overview_msg']          = 'Give me a summary of the overall platform status';
$string['qa_admin_performance_label']     = 'Performance';
$string['qa_admin_performance_msg']       = 'Show me the grade ranking for course {$a}';
$string['qa_admin_security_label']        = 'Security';
$string['qa_admin_security_msg']          = 'Are there users with suspicious characters in their names?';
$string['qa_admin_storage_label']         = 'Storage';
$string['qa_admin_storage_msg']           = 'Which course has the heaviest files uploaded?';
$string['qa_admin_top_courses_label']     = 'Top courses';
$string['qa_admin_top_courses_msg']       = 'Which courses have the best performance?';
$string['qa_admin_top_student_label']     = 'Top student';
$string['qa_admin_top_student_msg']       = 'Who is the top student in course {$a}?';

// ── Quick actions: Manager ────────────────────────────────────────────────────
$string['qa_manager_at_risk_label']       = 'At risk';
$string['qa_manager_at_risk_msg']         = 'Which students are at risk in course {$a}?';
$string['qa_manager_enrolled_label']      = 'Enrolled';
$string['qa_manager_enrolled_msg']        = 'How many students are enrolled in course {$a}?';
$string['qa_manager_global_risk_label']   = 'Global risk';
$string['qa_manager_global_risk_msg']     = 'Are there students at risk of failing in any course?';
$string['qa_manager_inactive_label']      = 'Inactive';
$string['qa_manager_inactive_msg']        = 'Which students haven\'t accessed course {$a} in the last 14 days?';
$string['qa_manager_inactive_users_label'] = 'Inactive users';
$string['qa_manager_inactive_users_msg']  = 'Which users haven\'t accessed the system in the last 30 days?';
$string['qa_manager_no_teacher_label']    = 'No teacher';
$string['qa_manager_no_teacher_msg']      = 'Are there courses with no teacher assigned?';
$string['qa_manager_overview_label']      = 'Overview';
$string['qa_manager_overview_msg']        = 'Give me a summary of course {$a}';
$string['qa_manager_top_courses_label']   = 'Top courses';
$string['qa_manager_top_courses_msg']     = 'Which courses have the most activity this week?';

// ── Quick actions: Teacher ────────────────────────────────────────────────────
$string['qa_teacher_at_risk_label']       = 'At risk';
$string['qa_teacher_at_risk_msg']         = 'Which students are at risk in course {$a}?';
$string['qa_teacher_my_alerts_label']     = 'My alerts';
$string['qa_teacher_my_alerts_msg']       = 'Are there students at risk in any of my courses?';
$string['qa_teacher_my_courses_label']    = 'My courses';
$string['qa_teacher_my_courses_msg']      = 'In which courses am I currently a teacher?';
$string['qa_teacher_missing_work_label']  = 'Missing work';
$string['qa_teacher_missing_work_msg']    = 'Who hasn\'t submitted the latest assignment in course {$a}?';
$string['qa_teacher_never_logged_label']  = 'Never logged in';
$string['qa_teacher_never_logged_msg']    = 'Which students never accessed course {$a}?';
$string['qa_teacher_notify_group_label']  = 'Notify group';
$string['qa_teacher_notify_group_msg']    = 'Draft a message for students in course {$a} reminding them of pending activities';
$string['qa_teacher_performance_label']   = 'Performance';
$string['qa_teacher_performance_msg']     = 'What is the average grade in course {$a}?';
$string['qa_teacher_top_students_label']  = 'Top students';
$string['qa_teacher_top_students_msg']    = 'Who has more than 90 points in course {$a}?';

// ── Quick actions: Student ────────────────────────────────────────────────────
$string['qa_student_help_label']          = 'Help';
$string['qa_student_help_msg']            = 'I don\'t understand the content of course {$a}. Can you explain?';
$string['qa_student_my_courses_label']    = 'My courses';
$string['qa_student_my_courses_msg']      = 'Which courses am I currently enrolled in?';
$string['qa_student_my_grade_label']      = 'My grade';
$string['qa_student_my_grade_msg']        = 'What is my average in course {$a}?';
$string['qa_student_my_grades_label']     = 'My grades';
$string['qa_student_my_grades_msg']       = 'What is my overall average across all my courses?';
$string['qa_student_my_progress_label']   = 'My progress';
$string['qa_student_my_progress_msg']     = 'How many activities have I completed in course {$a}?';
$string['qa_student_my_tasks_label']      = 'My tasks';
$string['qa_student_my_tasks_msg']        = 'What assignments do I have pending in course {$a}?';
$string['qa_student_this_week_label']     = 'This week';
$string['qa_student_this_week_msg']       = 'What activities and deadlines do I have coming up?';

// ── Admin settings ────────────────────────────────────────────────────────────
$string['settings']        = 'Jedomi Settings';
// ── Admin tabs ────────────────────────────────────────────────────────────────
$string['settings_redirect_help']     = 'Jedomi settings are managed in a dedicated page with a tabbed interface.';
$string['open_admin_settings']        = 'Open Jedomi Settings';
$string['tab_general']                = 'General';
$string['tab_privacy']                = 'Privacy';
// ── General tab headings ──────────────────────────────────────────────────────
$string['gateway_heading']            = 'Gateway connection';
$string['gateway_heading_help']       = 'Connection settings for the Jedomi AI backend.';
$string['security_heading']           = 'Security';
$string['security_heading_help']      = 'Tokens and secrets shared between Moodle and the Gateway.';
$string['improvement_heading']        = 'Continuous improvement';
$string['improvement_heading_help']   = 'Optional data collection to improve Jedomi over time.';
$string['enabled_help']               = 'Show or hide the Jedomi sidebar across the entire site.';
// ── Privacy tab headings ──────────────────────────────────────────────────────
$string['privacy_access_heading']     = 'Access control';
$string['privacy_access_heading_help'] = 'Who can see and use the Jedomi sidebar.';
$string['role_visibility_label']      = 'Show sidebar by role';
$string['role_visibility_help']       = 'Toggle OFF to hide the Jedomi sidebar from users with that role. Site admins always see it.';
$string['privacy_anon_heading']       = 'Data anonymization';
$string['privacy_anon_heading_help']  = 'Control how conversation data is anonymized before storage.';
$string['hide_from_guests_help']      = 'When enabled, guests and unauthenticated users will not see the sidebar.';
// ── Anonymization descriptions ────────────────────────────────────────────────
$string['anon_basic_desc']            = 'Replaces names and emails. Keeps most context intact.';
$string['anon_strict_desc']           = 'Replaces names, emails, IDs and free text. Maximum privacy.';
$string['anon_feedback_only_desc']    = 'Stores only ratings and feedback buttons. No conversation text.';
$string['gateway_url']     = 'Gateway URL';
$string['gateway_url_help'] = 'URL of the Jedomi Gateway. Example: http://localhost:8765';
$string['api_key']         = 'API Key';
$string['model']                     = 'Model';
$string['max_tokens']                = 'Max tokens';
$string['max_tokens_help']           = 'Maximum output tokens per response (default: 16384). Increase for longer responses, decrease for speed & cost.';
$string['enabled']         = 'Enable Jedomi sidebar';
$string['spt_secret']      = 'Permission token secret';
$string['spt_secret_help'] = 'Shared secret between Moodle and the Jedomi Gateway for signing permission tokens. Must match JEDOMI_SPT_SECRET in your .env file.';
$string['collect_training_data']     = 'Collect anonymized conversations for training';
$string['collect_training_data_help'] = 'When enabled, conversations are stored anonymously to improve future model versions. No personal data is retained.';
$string['anonymization_level']       = 'Anonymization level';
$string['anonymization_level_help']  = 'How aggressively to anonymize stored conversations.';
$string['anon_basic']                = 'Basic (names and emails)';
$string['anon_strict']               = 'Strict (names, emails, IDs, free text)';
$string['anon_feedback_only']        = 'Feedback only (no conversation text stored)';
$string['training_data_title']       = 'Jedomi Training Data';
$string['course_availability_heading']     = 'Course availability';
$string['course_availability_heading_help'] = 'Control which courses show the Jedomi sidebar.';
$string['course_mode']               = 'Availability mode';
$string['course_mode_help']          = 'Select how to control course availability.';
$string['course_mode_all']           = 'All courses (default)';
$string['course_mode_whitelist']     = 'Only selected courses (whitelist)';
$string['course_mode_blacklist']     = 'All courses except selected (blacklist)';
$string['course_list']               = 'Course IDs';
$string['course_list_help']          = 'Enter course IDs separated by commas or line breaks. Example: 5, 10, 23. Leave empty for no restriction.';
$string['hide_from_guests']          = 'Hide from guest users';
$string['category_list']             = 'Category IDs';
$string['category_list_help']        = 'Enter category IDs separated by commas. All courses in these categories (including subcategories) will be included. Example: 2, 5, 8';

// ── WS / security errors ──────────────────────────────────────────────────────
$string['non_select_rejected']    = 'Only SELECT queries are allowed.';
$string['query_timeout']          = 'Query timed out.';
$string['execute_sql_error']      = 'Database error executing query.';
$string['unauthorized']           = 'Unauthorized.';
$string['read_file_blocked']      = 'Access to this file is not allowed.';
$string['read_file_not_allowed']  = 'This path is not in the allowed list.';
$string['file_not_found']         = 'File not found or not readable.';
$string['config_key_blocked']     = 'This configuration key is blocked for security reasons.';
$string['path_not_allowed']       = 'This path is not allowed.';
$string['drop_files_here']            = 'Drop files here';
$string['manage_course_availability'] = 'Manage course availability';
$string['course_mode_include']        = 'Show only in selected courses';
$string['course_mode_include_desc']   = 'The sidebar will appear only in the courses you choose below.';
$string['course_mode_exclude']        = 'Hide in selected courses';
$string['course_mode_exclude_desc']   = 'The sidebar will appear everywhere except the courses you choose below.';
$string['course_mode_all_desc']       = 'The sidebar will appear on all courses site-wide.';
$string['course_availability_courses'] = 'Selected courses';
$string['course_search_placeholder']  = 'Search by name or shortname...';
$string['course_search_hint']         = 'Type at least 2 characters to search.';
$string['course_none_selected']       = 'No courses selected yet.';
$string['course_search_noresults']    = 'No courses found.';
$string['course_remove']              = 'Remove course';
$string['ai_disclaimer']          = 'Jedomi is an AI and can make mistakes.';

// ── Telegram integration ──────────────────────────────────────────────────────
$string['tab_integrations']                = 'Integrations';
$string['telegram_heading']                = 'Telegram';
$string['telegram_heading_help']           = 'Connect Jedomi to a Telegram bot so users can chat via Telegram.';
$string['telegram_webhook_status']         = 'Webhook Status';
$string['check_webhook']                   = 'Check webhook';
$string['webhook_error']                   = 'Last webhook error:';
$string['webhook_ok']                      = 'Webhook is configured and healthy.';
$string['webhook_error_alert_title']       = 'Webhook registration issue';
$string['webhook_error_alert_desc']        = 'The bot token was saved and validated, but the webhook could not be registered with Telegram.';
$string['webhook_error_detail']            = 'Error details:';
$string['webhook_not_checked']             = 'Not checked yet. Click "Check webhook" to verify.';
$string['drop_pending_updates']            = 'Drop pending updates';
$string['drop_pending_updates_help']       = 'Clears the Telegram update queue so stale messages are not processed when the webhook reconnects. Useful during testing.';
$string['drop_pending_success']            = '✅ Pending updates dropped successfully.';
$string['drop_pending_fail']               = '❌ Failed to drop pending updates — check the Telegram API response.';
$string['telegram_enabled']                = 'Enable Telegram integration';
$string['telegram_bot_token']              = 'Bot token';
$string['telegram_bot_token_help']         = 'Paste the token from @BotFather. It is encrypted before storage.';
$string['telegram_webhook_url']            = 'Webhook URL';
$string['telegram_webhook_url_help']      = 'Copy this URL into @BotFather with /setwebhook.';
$string['telegram_webhook_secret']         = 'Webhook secret';
$string['telegram_webhook_secret_help']    = 'Random secret appended to the webhook URL for security. Regenerate if compromised.';
$string['telegram_connection_status']     = 'Connection status';
$string['telegram_status_connected']      = 'Connected';
$string['telegram_status_disconnected']    = 'Disconnected';
$string['telegram_status_pending']         = 'Pending — paste webhook URL in @BotFather';
$string['telegram_rate_limit']             = 'Rate limit';
$string['telegram_rate_limit_help']        = 'Maximum messages per minute a user can send. 0 = unlimited.';
$string['telegram_cooldown']              = 'Cooldown';
$string['telegram_cooldown_help']           = 'Minutes to block a user after exceeding the rate limit.';
$string['telegram_role_visible']          = 'Allow Telegram for role: {role}';
$string['telegram_role_visibility_help']   = 'Toggle OFF to prevent users with this role from linking or receiving Telegram messages. Admins always have access.';
$string['telegram_link_title']            = 'Link Telegram';
$string['telegram_link_generate']         = 'Generate code';
$string['telegram_link_code_expiry']      = 'This code expires in 10 minutes.';
$string['telegram_linked_as']              = 'Linked as @{username}';
$string['telegram_not_linked']            = 'Not linked';
$string['telegram_link_instructions']      = 'Open Telegram, find your bot, and send /start followed by the code.';
$string['telegram_msg_link_first']       = 'Link your account first: go to your Moodle profile and click "Link Telegram".';
$string['telegram_msg_rate_limited']     = 'Too many messages. Please wait {minutes} minutes.';
$string['telegram_msg_role_blocked']     = 'Your role does not have access to Jedomi Telegram.';
$string['telegram_msg_confirm']          = 'Confirm';
$string['telegram_msg_cancel']            = 'Cancel';
$string['telegram_msg_executing']         = 'Executing...';
$string['telegram_msg_done']              = 'Done.';
$string['telegram_msg_expired_code']      = 'Invalid or expired code. Generate a new one from your Moodle profile.';
$string['telegram_msg_unlinked']          = 'Unlinked. Send /start to link again.';
$string['telegram_msg_linked_success']    = '✅ Linked to Moodle as <b>{$a->fullname}</b> ({$a->username})';
$string['telegram_msg_provide_code']      = '🔗 Please provide your link code.\n\nExample: /start ABC123';
$string['telegram_msg_token_invalid']      = 'Invalid bot token. Check the token from @BotFather and try again.';
$string['telegram_msg_max_iterations']     = '⚠️ Too many steps. Please simplify your request.';
$string['telegram_still_working']          = '🔄 Still working on it, hang tight…';
$string['telegram_msg_timeout']            = '⏳ This task is too complex for the time limit. Try splitting it into smaller steps or send it again and I\'ll continue where I left off.';
$string['telegram_msg_new_conversation']   = '🗑️ Conversation cleared. Start fresh!';

// ── Telegram help / commands ──────────────────────────────────
$string['telegram_help_heading']          = '🤖 <b>Jedomi Bot — Available Commands</b>';
$string['telegram_help_start']            = '🔗 <b>/start CODE</b> — Link your Moodle account to Telegram';
$string['telegram_help_help']             = '❓ <b>/help</b> — Show this help message';
$string['telegram_help_new']              = '🗑️ <b>/new</b> or <b>/nuevo</b> — Clear conversation and start fresh';
$string['telegram_help_unlink']           = '🚫 <b>/desvincular</b> or <b>/unlink</b> — Unlink your Telegram from Moodle';
$string['telegram_help_footer']           = 'After linking, send any message and I\'ll forward it to Jedomi AI for a response.';

// ── Telegram inline keyboard labels ───────────────────────────
$string['telegram_keyboard_new']          = '📝 New conversation';
$string['telegram_keyboard_help']         = '❓ Help';
$string['telegram_keyboard_unlink']       = '🚫 Unlink';
$string['telegram_keyboard_howto_link']   = '🔗 How to link?';

// ── Telegram error codes ──────────────────────────────────────
$string['error_telegram_e001']            = '❌ Chatbot not configured (E001). Contact your administrator.';
$string['error_telegram_e002']            = '❌ Failed to send message to Telegram (E002). Please try again.';
$string['error_telegram_e003']            = '❌ Unexpected Telegram response (E003). Please try again.';
$string['error_telegram_e004']            = '❌ Connection to Jedomi failed (E004). Please try again later.';
$string['error_telegram_e005']            = '❌ Jedomi service returned an error (E005). Please try again.';
$string['error_telegram_e006']            = '❌ Invalid response from Jedomi service (E006). Please try again.';
$string['error_telegram_e007']            = '❌ Invalid webhook secret (E007). Contact your administrator.';
$string['error_telegram_e008']            = '❌ You do not have permission to query course ID {$a}. Only courses where you have editing permissions are allowed.';

// ── Telegram config ───────────────────────────────────────────
$string['telegram_progress']              = '🤔 Jedomi is thinking…';
$string['telegram_max_turns']             = 'Maximum conversation turns';
$string['telegram_max_turns_help']        = 'Number of messages kept in Telegram conversation history (1-100). Lower values save tokens.';
$string['telegram_gateway_timeout']       = 'Gateway timeout (seconds)';
$string['telegram_gateway_timeout_help']  = 'Maximum seconds to wait for the Jedomi Gateway to respond (5-300).';
$string['unlink']                        = 'Unlink';
$string['regenerate']                    = 'Regenerate secret';
$string['cleanup_telegram_sessions']     = 'Jedomi — Clean up expired Telegram sessions';
$string['encryption_failed']             = 'Encryption failed. Check your server OpenSSL configuration.';
$string['decryption_failed']             = 'Decryption failed. The token may have been corrupted or the site salt changed.';

// ── Telegram setup guide ──────────────────────────────────────────────────────
// ── Agent Scheduled Tasks ──────────────────────────────────────────────────────
$string['tab_profiler']              = 'Query Profiler';
$string['tab_agents']                = 'Agents';
$string['agent_heading']             = 'Scheduled AI Agents';
$string['agent_heading_help']        = 'Configure scheduled AI agents that execute automatically and send results via Telegram.';
$string['agent_summary']             = 'Summary';
$string['agent_by_role']             = 'Agents by Role';
$string['agent_by_status']           = 'Agents by Status';
$string['agent_executions_30d']      = 'Executions (30 days)';
$string['agent_filters']             = 'Filters';
$string['agent_filter_role']         = 'Role';
$string['agent_filter_status']       = 'Status';
$string['agent_filter_context']      = 'Context';
$string['agent_search']              = 'Search prompt...';
$string['agent_apply_filters']       = 'Apply Filters';
$string['agent_table']               = 'All Agents';
$string['agent_creator']             = 'Creator';
$string['agent_prompt']              = 'Prompt';
$string['agent_context']             = 'Context';
$string['agent_schedule']            = 'Schedule';
$string['agent_status']              = 'Status';
$string['agent_lastrun']             = 'Last Run';
$string['agent_nextrun']             = 'Next Run';
$string['agent_actions']             = 'Actions';
$string['agent_edit']                = 'Edit';
$string['agent_disable']             = 'Disable';
$string['agent_delete']              = 'Delete';
$string['agent_create']              = 'Create Agent';
$string['agent_purge']               = 'Purge Agents';
$string['agent_purge_confirm']       = 'This will delete all matching agents and their cached results. Continue?';
$string['agent_no_results']          = 'No agents found matching your filters.';
$string['agent_create_success']      = 'Agent created successfully.';
$string['agent_created_with_name']   = 'Agent &quot;{name}&quot; created successfully.';
$string['agent_created_no_name']     = 'Agent created but no name was set. Edit the agent to add a name.';
$string['agent_preview']             = 'Preview';
$string['agent_preview_result']      = 'Agent Preview Result';
$string['agent_preview_error']       = 'Preview failed. Check the gateway configuration and prompt.';
$string['agent_update_success']      = 'Agent updated successfully.';
$string['agent_delete_confirm']      = 'Delete this agent? This action cannot be undone.';
$string['agent_disabled']            = 'Disabled';
$string['agent_enabled']             = 'Enabled';
$string['agent_failing']             = 'Failing';
$string['agent_run_now']             = 'Run Now';
$string['agent_schedule_presets']    = 'Schedule presets';
$string['agent_every_5min']          = 'Every 5 minutes';
$string['agent_every_15min']         = 'Every 15 minutes';
$string['agent_every_30min']         = 'Every 30 minutes';
$string['agent_every_1h']            = 'Every hour';
$string['agent_every_6h']            = 'Every 6 hours';
$string['agent_daily']               = 'Daily';
$string['agent_weekly']              = 'Weekly';
$string['agent_custom_cron']         = 'Custom (cron expression)';
$string['agent_context_type_site']   = 'Site';
$string['agent_context_type_course'] = 'Course';
$string['agent_context_type_category'] = 'Category';
$string['agent_context_type_user']   = 'User';
$string['agent_prompt_placeholder']  = 'e.g. "Summarize new site activity and notify me"';
$string['agent_status_all']          = 'All';
$string['agent_filter_apply']        = 'Apply';
$string['agent_filter_reset']        = 'Reset';
$string['agent_purge_context']       = 'Context type';
$string['agent_purge_context_help']  = 'Delete all agents belonging to a specific context.';
$string['agent_purge_btn']           = 'Purge';
$string['agent_purge_success']       = '{$a} agent(s) deleted successfully.';
$string['agent_error_load']          = 'Error loading agents.';
$string['agent_error_create']        = 'Error creating agent: {$a}';
$string['agent_error_update']        = 'Error updating agent: {$a}';
$string['agent_error_delete']        = 'Error deleting agent: {$a}';
$string['agent_not_found']           = 'Agent not found.';
$string['agent_run_queued']          = 'Agent queued for execution (next cron cycle).';
$string['agent_runner_task']         = 'Jedomi — Execute scheduled AI agents';
$string['agent_enable']              = 'Enable';

$string['copytoclipboard']               = 'Copy to clipboard';
$string['telegram_setup_guide']          = 'Setup Guide';
$string['telegram_setup_guide_help']     = 'Follow these steps to connect Jedomi to Telegram. Once configured, users can link their accounts from their profile.';
$string['telegram_setup_done']           = 'Integration configured';
$string['telegram_setup_done_help']      = 'Telegram is active. Users with permitted roles can link their accounts from their Moodle profile.';
$string['telegram_setup_show']           = 'Show setup guide';
$string['telegram_setup_hide']           = 'Hide guide';

$string['telegram_step_admin_title']     = 'Administrator Setup';
$string['telegram_step_admin_help']      = 'Only needs to be done once. Requires access to Telegram.';
$string['telegram_step1_title']          = 'Create a bot in @BotFather';
$string['telegram_step1_desc']           = 'Open Telegram, search for <strong>@BotFather</strong>, send <code>/newbot</code>, choose a name and username. Copy the token it gives you (format: <code>123456:ABC-DEF...</code>).';
$string['telegram_step2_title']          = 'Paste the token and save';
$string['telegram_step2_desc']           = 'Paste the token in the <strong>Bot token</strong> field below, enable the integration toggle, and click <strong>Save changes</strong>.';
$string['telegram_step3_title']          = 'Register the webhook in Telegram';
$string['telegram_step3_desc']           = 'Copy the <strong>Webhook URL</strong> shown after saving. Then go to @BotFather and run: <code>/setwebhook</code>. Or use this curl command:';
$string['telegram_step3_note']           = 'For local development (DDEV), use ngrok to get a public HTTPS URL first: <code>ngrok http https://moodle2.ddev.site --host-header=moodle2.ddev.site</code>';
$string['telegram_step4_title']          = 'Configure role access (optional)';
$string['telegram_step4_desc']           = 'Use the role toggles below to control which Moodle roles can use Telegram. Site admins always have access regardless of this setting.';

$string['telegram_step_user_title']      = 'User Setup (each user does this once)';
$string['telegram_step_user_help']       = 'After the admin configures the bot, users link their own Telegram accounts.';
$string['telegram_stepU1_title']         = 'Go to your Moodle profile';
$string['telegram_stepU1_desc']          = 'Click your name in the top bar → <strong>Profile</strong>. You will see a <strong>Jedomi AI</strong> section with the <em>Link Telegram</em> link.';
$string['telegram_stepU2_title']         = 'Generate a linking code';
$string['telegram_stepU2_desc']          = 'Click <strong>Generate code</strong>. A 6-character code appears, valid for 10 minutes.';
$string['telegram_stepU3_title']         = 'Send /start to the bot';
$string['telegram_stepU3_desc']          = 'In Telegram, open your institution\'s bot and send: <code>/start YOUR_CODE</code>. The bot confirms the link instantly.';
$string['telegram_stepU4_title']         = 'Start chatting';
$string['telegram_stepU4_desc']          = 'Send any message to the bot. Jedomi answers with real data from your Moodle. To unlink, send <code>/desvincular</code> or use your profile page.';

// ── WS Discovery / Execution ────────────────────────────────────────────────
$string['tab_apis']                = 'APIs';
$string['tab_apis_heading']        = 'Web Service Allowlists';
$string['tab_apis_heading_help']   = 'Configure which Moodle web services each role can access through Jedomi.';
$string['api_role_admin']          = 'Admin';
$string['api_role_manager']        = 'Manager';
$string['api_role_teacher']        = 'Teacher';
$string['api_role_student']        = 'Student';
$string['filter_all']              = 'All';
$string['filter_read']             = 'Read only';
$string['filter_write']            = 'Write only';
$string['filter_type']             = 'Type:';
$string['component_all']           = 'All components';
$string['ws_name']                 = 'Name';
$string['ws_description']          = 'Description';
$string['ws_component']            = 'Component';
$string['ws_type']                 = 'Type';
$string['ws_type_read']            = 'Read';
$string['ws_type_write']           = 'Write';
$string['ws_type_all']             = 'All types';
$string['search_ws_placeholder']   = 'Search web services...';
$string['ws_not_in_allowlist']     = 'This web service is not in your role\'s allowlist.';
$string['ws_not_found']            = 'Web service function not found.';
$string['ws_execute_error']        = 'Error executing web service: {$a}';
$string['ws_no_services']          = 'No web services configured for this role.';
$string['ws_pagination']           = 'Page {$a->page} of {$a->pages} ({$a->total} services)';
$string['ws_none_found']           = 'No web services match your filters.';
$string['tool_ws_search_desc']     = 'Search web service functions by intent or keywords and return the most relevant ones with full parameter and return type descriptions.';
$string['tool_ws_describe_desc']   = 'Get complete documentation (description, parameters, return type) for a specific web service function by exact name.';
$string['tool_ws_execute_desc']    = 'Execute a Moodle web service function on behalf of the current user. Use AFTER ws_search to get the exact function name and parameters. If the first attempt fails with "Missing required key" or "invalid parameter", retry with default values for optional fields. For batch operations (same action on multiple items), call this function MULTIPLE times — once per item.';

// ── Tool execution errors ────────────────────────────────────────────
$string['error_tool_loop_detected'] = 'Repeated identical tool call blocked. Use a different tool or execute with new parameters.';
$string['error_fallback_iterations'] = 'Sorry, I could not complete the operation after several attempts.';
$string['error_unknown_tool'] = 'Unknown tool: {$a}';
$string['error_permission_denied'] = 'permission_denied';
$string['error_query_required'] = 'query is required';
$string['error_search_tables_failed'] = 'search_tables failed: {$a}';
$string['error_ws_search_failed'] = 'ws_search failed: {$a}';
$string['error_ws_describe_failed'] = 'ws_describe failed: {$a}';
$string['error_ws_execute_failed'] = 'ws_execute failed: {$a}';
$string['error_wsname_required'] = 'wsname is required';
$string['error_permission_denied_ws'] = 'permission_denied: function not in allowlist';
$string['error_tool_failed'] = 'tool_failed';

// ── Tool hints ───────────────────────────────────────────────────────
$string['hint_sql_braces'] = ' | HINT: In Moodle SQL, table names should use braces (e.g. {course}, {user}).';

// ── Tool result notes ────────────────────────────────────────────────
$string['note_tables_not_found'] = 'No matching tables found. Try different keywords.';
$string['note_tables_found'] = 'Use the exact "name" with describe_table or execute_sql. Table names shown as provided — use {tablename} in SQL queries.';
$string['note_ws_none_configured'] = 'No web services configured for your role';
$string['note_ws_not_found'] = 'No matching functions found. Try different keywords.';
$string['note_ws_found'] = 'Use the exact "name" field with ws_execute. Check "params" for required(*) parameters.';

// ── SSE tool result summaries ────────────────────────────────────────
$string['summary_invalid_json'] = 'error: invalid JSON response';
$string['summary_error_prefix'] = 'error: ';
$string['summary_rows_returned'] = ' rows returned';
$string['summary_table_structure'] = 'table structure retrieved';
$string['summary_matching_tables'] = ' matching tables';
$string['summary_config_data'] = 'config data retrieved';
$string['summary_functions_found'] = ' functions found';
$string['summary_function_details'] = 'function details retrieved';
$string['summary_ws_executed'] = 'web service executed successfully';
$string['summary_done'] = 'done';

// ── Context labels (system prompt) ───────────────────────────────────
$string['ctx_loc_course'] = 'Currently viewing a course';
$string['ctx_loc_course_id'] = ' (ID: {$a}).';
$string['ctx_loc_activity'] = 'Currently in an activity of type';
$string['ctx_loc_activity_suffix'] = '".';
$string['ctx_loc_gradebook'] = 'Currently viewing the gradebook.';
$string['ctx_loc_admin'] = 'Currently in the Moodle administration panel.';
$string['ctx_loc_dashboard'] = 'Currently on the Moodle dashboard.';
$string['ctx_loc_messages'] = 'Currently in the messages section.';
$string['ctx_loc_profile'] = 'Currently viewing a user profile.';
$string['ctx_loc_calendar'] = 'Currently viewing the calendar.';
$string['ctx_loc_files'] = 'Currently in the files area.';
$string['dblabel_mysql'] = 'MySQL/MariaDB';
$string['dblabel_pgsql'] = 'PostgreSQL';

// ── Tool descriptions (lib.php) ──────────────────────────────────────
$string['tool_describe_table_desc'] = 'Obtiene la estructura (columnas, tipos, claves, indices, foreign keys) de una tabla de la base de datos de Moodle. Cacheado 24h.';
$string['tool_describe_table_tablename'] = 'Nombre de la tabla (ej: course, user, grade_grades)';
$string['tool_search_tables_desc'] = 'Search Moodle tables by intent or keywords in ENGLISH. FALLBACK for when web services cannot provide the needed data. Only use this AFTER confirming no relevant web service exists. Searches across table names AND their COMMENT descriptions from install.xml. Returns name, comment, field names, and field comments.';
$string['tool_search_tables_query'] = 'Intent or keywords in ENGLISH (e.g. "user grades", "course enrolment", "student")';
$string['tool_search_tables_limit'] = 'Maximum results (default: 8, max: 15)';
$string['tool_execute_sql_desc'] = 'Ejecuta una consulta SELECT en la base de datos de Moodle y devuelve las filas como JSON. SOLO lectura. Limite: 100 filas, timeout 5 segundos.';
$string['tool_execute_sql_sql'] = 'Consulta SELECT a ejecutar. Usa ? como placeholder para parametros. Ejemplo: SELECT id, fullname FROM {course} WHERE category = ?';
$string['tool_execute_sql_params'] = 'Parametros vinculados para la consulta (opcional)';
$string['tool_get_site_config_desc'] = 'Obtiene configuracion del sitio Moodle (sitename, lang, timezone, etc.). Solo disponible para administradores.';
$string['tool_get_site_config_keys'] = 'Lista de claves de configuracion. Validas: sitename, siteurl, lang, country, timezone, maxbytes, passwordpolicy, enrol_plugins_enabled, auth_plugins_enabled';
$string['tool_ws_search_desc'] = 'Search Moodle web service functions by intent or keywords in ENGLISH. Returns the most relevant matches with full description, parameters (* = required), and return structure. If results include what you need: STOP searching entirely — call ws_describe on the exact name, then ws_execute. Do NOT call SQL tools (search_tables, describe_table, execute_sql) when WS already covers your need. Do NOT call ws_search again for the same task.';
$string['tool_ws_search_query'] = 'Intent or keywords in ENGLISH (e.g. "create user", "enrol student", "get course grades")';
$string['tool_ws_search_limit'] = 'Máximo de resultados a devolver (default: 6, max: 15)';
$string['tool_ws_describe_desc'] = 'Get full documentation (description, parameters with * = required, return type) for a specific web service function when you already know its exact name. ALWAYS call this AFTER ws_search and BEFORE ws_execute to verify required fields.';
$string['tool_ws_describe_wsname'] = 'Exact function name (e.g. core_user_create_users)';
$string['tool_ws_execute_wsname'] = 'Web service function name (e.g. core_course_get_courses)';
$string['tool_ws_execute_params'] = 'JSON-encoded parameters for the web service function. MUST include every * (required) field. IMPORTANT: If the first attempt fails with "Detectado valor de parámetro no válido" or "Missing required key", the instance requires fields that the schema marks as optional. In that case, RETRY by adding default values for missing optional fields (e.g. description="", enrolmentkey="", idnumber="", visibility=0, participation=true).';

// ── Agent tools ───────────────────────────────────────────────────────────────
$string['tool_create_agent_desc'] = 'Create a scheduled AI agent. The agent will execute the given prompt on a recurring schedule, cache results, and send notifications via Telegram (if linked). Only available to admins and managers. Use this when a user wants to automate recurring AI tasks like daily summaries, monitoring, or periodic reports. Returns the new agent ID.';
$string['tool_create_agent_prompt'] = 'The task description or instruction the AI agent will execute each time it runs. Be specific about what data to fetch, analyze, or report. Example: "Summarize new enrollments and activity from the last 24 hours" or "Check for students at risk of failing in course 42 and notify me".';
$string['tool_create_agent_schedule'] = 'Cron expression defining how often the agent runs. Common presets: "*/5 * * * *" = every 5 minutes, "*/30 * * * *" = every 30 minutes, "0 * * * *" = hourly, "0 0 * * *" = daily at midnight, "0 0 * * 0" = weekly on Sunday.';
$string['tool_create_agent_context_type'] = 'Scope of execution: "site" for global, "course" to limit to a specific course, "category" for a course category, "user" for a specific user. Defaults to "site".';
$string['tool_create_agent_context_id'] = 'The ID of the course, category, or user context. Required when context_type is not "site".';
$string['tool_create_agent_enabled'] = 'Whether the agent starts enabled. Default: true. Set to false to create but not activate yet.';
$string['tool_list_agents_desc'] = 'List all configured scheduled AI agents, optionally filtered by status or context. Returns agent ID, prompt excerpt, schedule, context, status, last run, and next run.';
$string['tool_list_agents_status'] = 'Optional filter: "enabled" to show only active agents, "disabled" for inactive ones. Leave empty to show all.';
$string['tool_delete_agent_desc'] = 'Delete a scheduled AI agent by its ID. This permanently removes the agent and all its cached results. Cannot be undone.';
$string['tool_delete_agent_id'] = 'The numeric ID of the agent to delete. Use list_agents to find the ID.';

// ── Admin CLI tools (non-WS actions) ─────────────────────────────────
$string['tool_admin_search_desc'] = 'Search for CLI operations available to administrators (e.g. backup, purge caches, run scheduled tasks). Use this to discover what operations exist before describing or executing them.';
$string['tool_admin_search_query'] = 'Search keywords to find relevant admin operations.';
$string['tool_admin_search_limit'] = 'Maximum number of results to return (default: 8, max: 20).';
$string['tool_admin_describe_desc'] = 'Get full documentation (parameters, types, required fields) for a specific admin CLI operation by name. Use this AFTER admin_search and BEFORE admin_execute to verify required parameters.';
$string['tool_admin_describe_name'] = 'Operation name (e.g. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_desc'] = 'Execute an admin CLI operation to create or modify Moodle data. Requires admin or manager role. USE WITH CAUTION — these operations modify the database. COMBINE with WS tools: first use ws_search/ws_execute to discover courses, sections, users, or verify data, then call admin_execute with the gathered info. Example flow: ws_execute(core_course_get_courses_by_field) to find course → admin_execute(create_mod, ...) to create an activity.';
$string['tool_admin_execute_name'] = 'Operation name (e.g. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_params'] = 'JSON object of parameters for the operation (use admin_describe first to see required fields).';

// ── External WS errors ───────────────────────────────────────────────
$string['ws_function_not_found'] = 'Function not found: {$a}';
$string['ws_cannot_load_info'] = 'Cannot load function info: {$a}';

// ── Table descriptor errors ──────────────────────────────────────────
$string['table_not_found'] = 'Table not found: {$a}';

// ── Set site config ──────────────────────────────────────────────────
$string['config_key_blocked_msg'] = 'Key is blocked for security reasons.';
$string['config_updated_msg'] = 'Config \'{$a}\' updated successfully.';

// ── Run task ─────────────────────────────────────────────────────────
$string['task_not_found_msg'] = 'task_not_found';
$string['task_instantiation_failed_msg'] = 'task_instantiation_failed';

// ── WS execute errors ────────────────────────────────────────────────
$string['ws_method_not_exists'] = 'Method {$a} does not exist';
$string['ws_debug_prefix'] = ' | debug: ';

// ── Agent SQL profiler ───────────────────────────────────────────────
$string['query_profiler'] = 'Query Profiler';
$string['query_profiler_heading'] = 'SQL Query Profiler';
$string['query_profiler_desc'] = 'Logs all SQL queries executed by agents, including execution time and row count. Use the filter below to find slow queries.';
$string['query_profiler_total'] = 'Total queries';
$string['query_profiler_slow'] = 'Slow queries (>1s)';
$string['query_profiler_avg'] = 'Avg duration';
$string['query_profiler_max'] = 'Max duration';
$string['query_profiler_sort_duration'] = 'Duration';
$string['query_profiler_sort_time'] = 'Time';
$string['query_profiler_sort_query'] = 'Query';
$string['query_profiler_empty'] = 'No queries logged yet. Agents will log queries here when they use the SQL tool.';
$string['query_profiler_threshold'] = 'Slow query threshold (ms)';
$string['query_profiler_recent'] = 'Recent queries';
$string['query_profiler_slowest'] = 'Slowest queries (avg)';

// ── Agent WS allowlist ───────────────────────────────────────────────
$string['agent_ws_allowlist'] = 'WebService allowlist for agents';
$string['agent_ws_allowlist_help'] = 'Comma-separated list of Moodle web service function names that agents are allowed to call. Leave empty for the default set.';

// ── Agent name ───────────────────────────────────────────────────────
$string['agent_name'] = 'Name';
$string['agent_name_placeholder'] = 'e.g. Daily Report';
$string['agent_unnamed'] = 'Agent';
// ── Document reader ─────────────────────────────────────────────────
$string['tool_document_read_desc'] = 'Read a PDF file from ANY course activity using its cmid (course module ID). Converts PDF pages to base64 images so you see the EXACT original layout, tables, images, and formatting. Use this when the user asks about a document. The cmid is available in the context when viewing an activity page.';
$string['tool_document_read_cmid'] = 'Course module ID (cmid) of the activity. Get it from the page context when viewing an activity, or discover it via ws_search/ws_execute first.';
$string['tool_document_read_dpi'] = 'Image resolution (default: 150). Higher values = better quality but more tokens.';
$string['tool_document_read_fmt'] = 'Image format: JPEG (smaller) or PNG (lossless). Default: JPEG.';
$string['docservice_url'] = 'Document-to-image service URL';
$string['docservice_url_help'] = 'URL of the document-to-image service that converts PDFs to base64 images (e.g. http://localhost:8000).';
