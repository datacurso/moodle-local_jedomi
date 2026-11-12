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

$string['action_copy'] = 'Copy';
$string['action_edit'] = 'Edit';
$string['action_edit_title'] = 'Edit message';
$string['action_helpful'] = 'Helpful';
$string['action_improve'] = 'Improve';
$string['action_improve_title'] = 'Needs work';
$string['agent'] = 'Agent';
$string['agent_actions'] = 'Actions';
$string['agent_actions_menu'] = 'Agent actions';
$string['agent_apply_filters'] = 'Apply Filters';
$string['agent_by_role'] = 'Agents by Role';
$string['agent_by_status'] = 'Agents by Status';
$string['agent_chart_no_data'] = 'No data';
$string['agent_context'] = 'Context';
$string['agent_context_id'] = 'Context ID';
$string['agent_context_id_placeholder'] = 'e.g. 42';
$string['agent_context_type_category'] = 'Category';
$string['agent_context_type_course'] = 'Course';
$string['agent_context_type_site'] = 'Site';
$string['agent_context_type_user'] = 'User';
$string['agent_count'] = '{$a} agents';
$string['agent_create'] = 'Create Agent';
$string['agent_create_success'] = 'Agent created successfully.';
$string['agent_created_no_name'] = 'Agent created but no name was set. Edit the agent to add a name.';
$string['agent_created_with_name'] = 'Agent "{$a}" created successfully.';
$string['agent_creator'] = 'Creator';
$string['agent_custom_cron'] = 'Custom (cron expression)';
$string['agent_daily'] = 'Daily';
$string['agent_delete'] = 'Delete';
$string['agent_delete_confirm'] = 'Delete this agent? This action cannot be undone.';
$string['agent_disable'] = 'Disable';
$string['agent_disabled'] = 'Disabled';
$string['agent_disabled_notice'] = 'Agent disabled';
$string['agent_disabled_reason_creator_deleted'] = 'The creator of the agent was deleted.';
$string['agent_disabled_reason_creator_missing'] = 'The creator of the agent no longer exists.';
$string['agent_disabled_reason_creator_suspended'] = 'The creator of the agent is suspended.';
$string['agent_disabled_reason_no_access'] = 'The creator no longer has access to the context of the agent.';
$string['agent_edit'] = 'Edit';
$string['agent_enable'] = 'Enable';
$string['agent_enabled'] = 'Enabled';
$string['agent_error_create'] = 'Error creating agent: {$a}';
$string['agent_error_delete'] = 'Error deleting agent: {$a}';
$string['agent_error_load'] = 'Error loading agents.';
$string['agent_error_nopermission'] = 'You cannot manage the agents of users with more permissions than yours.';
$string['agent_error_update'] = 'Error updating agent: {$a}';
$string['agent_every_15min'] = 'Every 15 minutes';
$string['agent_every_1h'] = 'Every hour';
$string['agent_every_30min'] = 'Every 30 minutes';
$string['agent_every_5min'] = 'Every 5 minutes';
$string['agent_every_6h'] = 'Every 6 hours';
$string['agent_executions_30d'] = 'Executions (30 days)';
$string['agent_failing'] = 'Failing';
$string['agent_filter_apply'] = 'Apply';
$string['agent_filter_context'] = 'Context';
$string['agent_filter_reset'] = 'Reset';
$string['agent_filter_role'] = 'Role';
$string['agent_filter_status'] = 'Status';
$string['agent_filters'] = 'Filters';
$string['agent_heading'] = 'Scheduled AI Agents';
$string['agent_heading_help'] = 'Configure scheduled AI agents that execute automatically and send results via Telegram.';
$string['agent_invalid_context_type'] = 'invalid context type';
$string['agent_invalid_cron'] = 'invalid cron expression';
$string['agent_lastrun'] = 'Last Run';
$string['agent_name'] = 'Name';
$string['agent_name_placeholder'] = 'e.g. Daily Report';
$string['agent_nextrun'] = 'Next Run';
$string['agent_no_results'] = 'No agents found matching your filters.';
$string['agent_not_found'] = 'Agent not found.';
$string['agent_number'] = 'Agent #{$a}';
$string['agent_preview'] = 'Preview';
$string['agent_preview_arguments'] = 'Arguments';
$string['agent_preview_cached'] = 'Cached result from';
$string['agent_preview_clear_cache'] = 'Clear cache';
$string['agent_preview_error'] = 'Preview failed. Check the prompt and try again.';
$string['agent_preview_prompt_required'] = 'Write a prompt first.';
$string['agent_preview_result'] = 'Agent Preview Result';
$string['agent_preview_result_n'] = 'Result {$a}';
$string['agent_preview_result_preview'] = 'Result preview';
$string['agent_preview_role_assistant'] = 'Assistant';
$string['agent_preview_role_system'] = 'System';
$string['agent_preview_role_tool'] = 'Tool';
$string['agent_preview_role_user'] = 'User';
$string['agent_preview_round'] = 'Round {$a}';
$string['agent_preview_timeline'] = 'Conversation timeline';
$string['agent_preview_tool_stats'] = '{$a->duration} ms · {$a->rows} rows';
$string['agent_preview_total_time'] = 'Total: {$a} ms';
$string['agent_prompt'] = 'Prompt';
$string['agent_prompt_hidden'] = 'Hidden: only its creator, site administrators and users with a higher role can see this instruction.';
$string['agent_prompt_placeholder'] = 'e.g. "Summarize new site activity and notify me"';
$string['agent_purge'] = 'Purge Agents';
$string['agent_purge_btn'] = 'Purge';
$string['agent_purge_confirm'] = 'This will delete all matching agents and their cached results. Continue?';
$string['agent_purge_context'] = 'Context type';
$string['agent_purge_context_help'] = 'Delete all agents belonging to a specific context.';
$string['agent_purge_id_required'] = 'Enter the id of the course, category or user to purge.';
$string['agent_purge_invalid_type'] = 'Invalid purge type: {$a}';
$string['agent_purge_role_not_found'] = 'Role not found.';
$string['agent_purge_success'] = '{$a} agent(s) deleted successfully.';
$string['agent_run_now'] = 'Run Now';
$string['agent_run_queued'] = 'Agent queued for execution (next cron cycle).';
$string['agent_runner_task'] = 'Jedomi — Execute scheduled AI agents';
$string['agent_schedule'] = 'Schedule';
$string['agent_schedule_presets'] = 'Schedule presets';
$string['agent_search'] = 'Search prompt...';
$string['agent_status'] = 'Status';
$string['agent_status_all'] = 'All';
$string['agent_summary'] = 'Summary';
$string['agent_table'] = 'All Agents';
$string['agent_unnamed'] = 'Agent';
$string['agent_update_success'] = 'Agent updated successfully.';
$string['agent_user_id'] = 'User #{$a}';
$string['agent_view_details'] = 'View details';
$string['agent_weekly'] = 'Weekly';
$string['agent_ws_allowlist'] = 'WebService allowlist for agents';
$string['agent_ws_allowlist_help'] = 'Comma-separated list of Moodle web service function names that agents are allowed to call. '
    . 'Leave empty for the default set.';
$string['ai_disclaimer'] = 'Jedomi is an AI and can make mistakes.';
$string['ai_error_connection'] = 'Could not reach Jedomi. Check your connection and try again.';
$string['ai_error_generic'] = 'Jedomi could not answer this time. Please try again in a moment.';
$string['ai_error_invalidanswer'] = 'The AI service returned an answer that could not be read.';
$string['ai_error_license'] = 'The AI license of this site is missing or not valid. Contact your site administrator.';
$string['ai_error_nocredit'] = 'Your organisation has run out of AI credit. Contact your site administrator to add more.';
$string['ai_error_ratelimit'] = 'You have reached the AI usage limit for now. You can try again after {$a}.';
$string['ai_error_ratelimit_noretry'] = 'You have reached the AI usage limit for now. Please try again later.';
$string['ai_error_timeout'] = 'The assistant took too long to answer. Please try again with a shorter request.';
$string['ai_error_unavailable'] = 'The AI service is not available right now. Please try again in a few minutes.';
$string['ai_heading'] = 'AI service';
$string['ai_heading_help'] = 'Jedomi sends its AI requests through the Datacurso AI provider, using its license key and usage limits.';
$string['analyze_file'] = 'Summarize this file.';
$string['analyze_image'] = 'Analyze this image.';
$string['anon_basic'] = 'Basic (names and emails)';
$string['anon_basic_desc'] = 'Replaces names and emails. Keeps most context intact.';
$string['anon_feedback_only'] = 'Feedback only (no conversation text stored)';
$string['anon_feedback_only_desc'] = 'Stores only ratings and feedback buttons. No conversation text.';
$string['anon_strict'] = 'Strict (names, emails, IDs, free text)';
$string['anon_strict_desc'] = 'Replaces names, emails, IDs and free text. Maximum privacy.';
$string['anonymization_level'] = 'Anonymization level';
$string['anonymization_level_help'] = 'How aggressively to anonymize stored conversations.';
$string['api_role_admin'] = 'Admin';
$string['api_role_manager'] = 'Manager';
$string['api_role_student'] = 'Student';
$string['api_role_teacher'] = 'Teacher';
$string['attach'] = 'Attach';
$string['attach_audio'] = 'Attach audio file';
$string['attach_file'] = 'Attach PDF, CSV, TXT, MD, Word or PowerPoint file';
$string['attach_image'] = 'Attach image';
$string['attached_file_info'] = '[Attached file: {$a}]';
$string['attached_image'] = 'Attached image';
$string['attached_image_content'] = '[Content of the attached image: {$a}]';
$string['audio_enabled'] = 'Enable audio processing';
$string['audio_enabled_help'] = 'Lets users dictate with the microphone and attach audio files in the web chat, and send voice and audio messages in Telegram. The audio is transcribed by the audio model, which consumes credits per minute of audio.';
$string['audio_error_disabled'] = 'Audio processing is not enabled on this site.';
$string['audio_error_too_long'] = 'The audio is too long to be transcribed. Send a shorter one.';
$string['audio_error_unreadable'] = 'The audio could not be understood. Try again, speaking closer to the microphone.';
$string['audio_error_unsupported'] = 'This audio format is not supported. Use MP3, WAV, M4A, OGG or WEBM.';
$string['audio_file'] = 'Audio';
$string['audio_model'] = 'Audio model';
$string['audio_model_help'] = 'Model that transcribes the audio. Qwen3-ASR 1.7B writes names and accents best in the tests.';
$string['audio_processing'] = 'Enable audio processing';
$string['cachedef_speech_rate'] = 'Voice conversation requests per user and minute';
$string['cancel'] = 'Cancel';
$string['cancelled'] = 'Cancelled.';
$string['category_list'] = 'Category IDs';
$string['category_list_help'] = 'Enter category IDs separated by commas. All courses in these categories (including '
    . 'subcategories) will be included. Example: 2, 5, 8';
$string['chat_error_course_unavailable'] = 'Jedomi is not available in this course.';
$string['chat_error_guest'] = 'Jedomi is only available to users logged in with their own account.';
$string['chat_error_role_hidden'] = 'Jedomi is not available for your role.';
$string['chat_placeholder'] = 'Ask Jedomi...';
$string['check_webhook'] = 'Check webhook';
$string['cleanup_telegram_sessions'] = 'Jedomi — Clean up expired Telegram sessions';
$string['close'] = 'Close (Esc)';
$string['collect_training_data'] = 'Collect anonymized conversations for training';
$string['collect_training_data_help'] = 'When enabled, conversations are stored anonymously to improve future model versions. No '
    . 'personal data is retained.';
$string['component_all'] = 'All components';
$string['config_key_blocked'] = 'This configuration key is blocked for security reasons.';
$string['config_key_blocked_msg'] = 'Key is blocked for security reasons.';
$string['config_updated_msg'] = 'Config \'{$a}\' updated successfully.';
$string['confirm_btn_no'] = 'Cancel';
$string['confirm_btn_yes'] = 'Confirm';
$string['confirm_cancelled'] = 'Action cancelled.';
$string['confirm_done'] = 'Action executed successfully.';
$string['confirm_executing'] = 'Executing...';
$string['confirm_keyword_required'] = 'Type the confirmation keyword to run this action.';
$string['confirm_send_bulk'] = 'Send this message to {count} student(s)?';
$string['confirm_type_to_proceed'] = 'Type CONFIRM to proceed:';
$string['connection_error'] = 'Connection error:';
$string['conversation_cleared'] = 'Conversation cleared.';
$string['copytoclipboard'] = 'Copy to clipboard';
$string['course_availability_courses'] = 'Selected courses';
$string['course_availability_courses_help'] = 'Courses where the chat is shown (include mode) or hidden (exclude mode). Search by course name or short name.';
$string['course_availability_heading'] = 'Course availability';
$string['course_availability_heading_help'] = 'Control which courses show the Jedomi sidebar.';
$string['course_list'] = 'Course IDs';
$string['course_list_help'] = 'Enter course IDs separated by commas or line breaks. Example: 5, 10, 23. Leave empty for no '
    . 'restriction.';
$string['course_mode'] = 'Availability mode';
$string['course_mode_all'] = 'All courses (default)';
$string['course_mode_all_desc'] = 'The sidebar will appear on all courses site-wide.';
$string['course_mode_blacklist'] = 'All courses except selected (blacklist)';
$string['course_mode_exclude'] = 'Hide in selected courses';
$string['course_mode_exclude_desc'] = 'The sidebar will appear everywhere except the courses you choose below.';
$string['course_mode_help'] = 'Select how to control course availability.';
$string['course_mode_include'] = 'Show only in selected courses';
$string['course_mode_include_desc'] = 'The sidebar will appear only in the courses you choose below.';
$string['course_mode_whitelist'] = 'Only selected courses (whitelist)';
$string['course_none_selected'] = 'No courses selected yet.';
$string['course_remove'] = 'Remove course';
$string['course_search_placeholder'] = 'Search by name or shortname...';
$string['csv_download'] = '📥 Download CSV';
$string['ctx_activity'] = 'Activity';
$string['ctx_admin'] = 'Administration';
$string['ctx_calendar'] = 'Calendar';
$string['ctx_course'] = 'Course';
$string['ctx_dashboard'] = 'Dashboard';
$string['ctx_files'] = 'Files';
$string['ctx_grades'] = 'Grades';
$string['ctx_loc_activity'] = 'Currently in an activity of type';
$string['ctx_loc_activity_suffix'] = '".';
$string['ctx_loc_admin'] = 'Currently in the Moodle administration panel.';
$string['ctx_loc_calendar'] = 'Currently viewing the calendar.';
$string['ctx_loc_course'] = 'Currently viewing a course';
$string['ctx_loc_course_id'] = ' (ID: {$a}).';
$string['ctx_loc_dashboard'] = 'Currently on the Moodle dashboard.';
$string['ctx_loc_files'] = 'Currently in the files area.';
$string['ctx_loc_gradebook'] = 'Currently viewing the gradebook.';
$string['ctx_loc_messages'] = 'Currently in the messages section.';
$string['ctx_loc_profile'] = 'Currently viewing a user profile.';
$string['ctx_messages'] = 'Messages';
$string['ctx_profile'] = 'Profile';
$string['dblabel_mysql'] = 'MySQL/MariaDB';
$string['dblabel_pgsql'] = 'PostgreSQL';
$string['decryption_failed'] = 'Decryption failed. The token may have been corrupted or the site salt changed.';
$string['drop_files_here'] = 'Drop files here';
$string['drop_pending_fail'] = '❌ Failed to drop pending updates — check the Telegram API response.';
$string['drop_pending_running'] = 'Dropping...';
$string['drop_pending_success'] = '✅ Pending updates dropped successfully.';
$string['drop_pending_updates'] = 'Drop pending updates';
$string['drop_pending_updates_help'] = 'Clears the Telegram update queue so stale messages are not processed when the webhook '
    . 'reconnects. Useful during testing.';
$string['encryption_failed'] = 'Encryption failed. Check your server OpenSSL configuration.';
$string['error_fallback_iterations'] = 'Sorry, I could not complete the operation after several attempts.';
$string['error_invalid_hash'] = 'Invalid cache key.';
$string['error_invalid_max_tokens'] = 'Enter a whole number of tokens.';
$string['error_invalid_model'] = 'Select one of the listed models.';
$string['error_invalid_voice'] = 'Select one of the voices of the chosen model.';
$string['error_not_own_course'] = 'That course is not one of your courses.';
$string['error_own_data_arguments'] = 'Unsupported argument(s): {$a}. These tools only read the data of the current user.';
$string['error_permission_denied'] = 'permission_denied';
$string['error_permission_denied_ws'] = 'permission_denied: function not in allowlist';
$string['error_post_required'] = 'This request must be sent with POST.';
$string['error_query_required'] = 'query is required';
$string['error_search_tables_failed'] = 'search_tables failed: {$a}';
$string['error_telegram_e001'] = '❌ Chatbot not configured (E001). Contact your administrator.';
$string['error_telegram_e002'] = '❌ Failed to send message to Telegram (E002). Please try again.';
$string['error_telegram_e003'] = '❌ Unexpected Telegram response (E003). Please try again.';
$string['error_telegram_e004'] = '❌ Connection to Jedomi failed (E004). Please try again later.';
$string['error_telegram_e005'] = '❌ Jedomi service returned an error (E005). Please try again.';
$string['error_telegram_e006'] = '❌ Invalid response from Jedomi service (E006). Please try again.';
$string['error_telegram_e007'] = '❌ Invalid webhook secret (E007). Contact your administrator.';
$string['error_telegram_e008'] = '❌ You do not have permission to query course ID {$a}. Only courses where you have editing '
    . 'permissions are allowed.';
$string['error_tool_failed'] = 'tool_failed';
$string['error_tool_loop_detected'] = 'Repeated identical tool call blocked. Use a different tool or execute with new parameters.';
$string['error_unknown_action'] = 'Unknown action: {$a}';
$string['error_unknown_tool'] = 'Unknown tool: {$a}';
$string['error_value_range'] = 'Enter a value between {$a->min} and {$a->max}.';
$string['error_voice_requires_audio'] = 'The voice conversation needs audio processing: enable it too.';
$string['error_ws_describe_failed'] = 'ws_describe failed: {$a}';
$string['error_ws_execute_failed'] = 'ws_execute failed: {$a}';
$string['error_ws_search_failed'] = 'ws_search failed: {$a}';
$string['error_wsname_required'] = 'wsname is required';
$string['execute_sql_error'] = 'Database error executing query.';
$string['export_conversation'] = 'Export conversation';
$string['file'] = 'File';
$string['file_audio'] = 'Audio: {seconds} seconds';
$string['file_csv'] = 'CSV: {rows} rows, {columns} columns';
$string['file_error_no_text'] = 'No text could be read from this file.';
$string['file_error_too_large'] = 'The file is too large to be read.';
$string['file_error_unsupported'] = 'Only PDF, CSV, TXT, MD, Word (DOC, DOCX) and PowerPoint (PPT, PPTX) files, '
    . 'and audio files when audio processing is enabled, can be attached.';
$string['file_loaded'] = 'loaded';
$string['file_loaded_hint'] = 'You can ask questions about its content.';
$string['file_not_found'] = 'File not found or not readable.';
$string['file_pdf'] = 'PDF: {pages} pages';
$string['file_process_error'] = 'Could not process';
$string['file_text'] = 'Text: {chars} characters';
$string['files_enabled'] = 'Enable file and image processing';
$string['files_enabled_help'] = 'Lets users attach images and files in the web chat, send photos and documents in Telegram, and lets the AI read the PDF files of course activities. Images and scanned PDF pages are read by the file and image model, which consumes credits.';
$string['files_error_disabled'] = 'File and image processing is not enabled on this site.';
$string['files_processing'] = 'Enable file and image processing';
$string['filter_all'] = 'All';
$string['filter_read'] = 'Read only';
$string['filter_type'] = 'Type:';
$string['filter_write'] = 'Write only';
$string['full_width'] = 'Full width';
$string['greeting'] = 'Hi, {name}!';
$string['half_width'] = 'Half width';
$string['hello'] = 'I\'m Jedomi, your Moodle AI assistant. Ask me about courses, users, grades or settings.';
$string['hide_from_guests'] = 'Hide from guest users';
$string['hide_from_guests_help'] = 'Guests and users who are not logged in never see Jedomi and can never use it: they are always excluded, '
    . 'whatever this option says.';
$string['hint_sql_braces'] = ' | HINT: In Moodle SQL, table names should use braces (e.g. {course}, {user}).';
$string['image'] = 'Image';
$string['image_error_no_text'] = 'No content could be read from this file.';
$string['image_error_unsupported'] = 'Only PDF, JPG, PNG and WEBP files can be read.';
$string['improvement_heading'] = 'Continuous improvement';
$string['improvement_heading_help'] = 'Optional data collection to improve Jedomi over time.';
$string['invalid_session'] = 'Your session has changed. Reload the page to keep using Jedomi.';
$string['invalid_signature'] = 'The chat permissions are not valid. Reload the page to keep using Jedomi.';
$string['jedomi:companion_api'] = 'Use the Jedomi companion service functions';
$string['jedomi:manageagents'] = 'Manage Jedomi agents';
$string['jedomi:managetrainingdata'] = 'Manage Jedomi training data';
$string['jedomi:viewtrainingdata'] = 'View Jedomi training data';
$string['listening'] = 'Listening...';
$string['manage_course_availability'] = 'Manage course availability';
$string['max_tokens'] = 'Max tokens';
$string['max_tokens_help'] = 'Maximum output tokens per response (default: 16384). Increase for longer responses, decrease for '
    . 'speed & cost.';
$string['message_input'] = 'Message Jedomi';
$string['messages_recipients_note'] = 'Recipients will see them in their Moodle message inbox.';
$string['messages_sent'] = '{sent} message(s) sent as Moodle internal messages.';
$string['messages_sent_fail'] = '({failed} failed)';
$string['mic_error'] = 'Could not access the microphone. Please check your browser permissions.';
$string['model'] = 'Chat model';
$string['model_help'] = 'Model that answers in the web chat, scheduled agents, agent preview, Telegram and the CLI. Only models with a registered price are listed.';
$string['new_conversation'] = 'New conversation';
$string['new_conversation_msg'] = 'New conversation started. How can I help you?';
$string['no_messages_sent'] = 'No messages sent.';
$string['non_select_rejected'] = 'Only SELECT queries are allowed.';
$string['note_tables_found'] = 'Use the exact "name" with describe_table or execute_sql. Table names shown as provided — use '
    . '{tablename} in SQL queries.';
$string['note_tables_not_found'] = 'No matching tables found. Try different keywords.';
$string['note_ws_found'] = 'Use the exact "name" field with ws_execute. Check "params" for required(*) parameters.';
$string['note_ws_none_configured'] = 'No web services configured for your role';
$string['note_ws_not_found'] = 'No matching functions found. Try different keywords.';
$string['open_sidebar'] = 'Open Jedomi AI';
$string['path_not_allowed'] = 'This path is not allowed.';
$string['pending_action_denied'] = 'The action was not run: you do not have permission to do it.';
$string['pending_action_expired'] = 'This action has expired. Ask Jedomi again.';
$string['pending_action_failed'] = 'The action could not be completed.';
$string['pending_action_needed'] = 'This action needs your confirmation.';
$string['pending_action_not_found'] = 'This action does not exist or was already resolved.';
$string['pending_desc_admin_execute'] = 'Run the administration operation "{$a}".';
$string['pending_desc_create_agent'] = 'Create the scheduled agent "{$a}".';
$string['pending_desc_delete_agent'] = 'Delete the scheduled agent #{$a}.';
$string['pending_desc_send_messages'] = 'Send a Moodle message to {$a} user(s).';
$string['pending_desc_ws_execute'] = 'Run the web service function "{$a}".';
$string['pluginname'] = 'Jedomi AI Sidebar';
$string['privacy:metadata:jedomi_ai_service'] = 'Conversations are sent through the Datacurso AI provider to its AI service to generate the answers.';
$string['privacy:metadata:jedomi_ai_service:lang'] = 'The language of the user.';
$string['privacy:metadata:jedomi_ai_service:messages'] = 'The conversation messages and the results of the tools used to answer them.';
$string['privacy:metadata:jedomi_ai_service:site_id'] = 'An identifier of the site, used by the AI service to keep the requests of each site apart.';
$string['privacy:metadata:jedomi_ai_service:site_url'] = 'The address of the site.';
$string['privacy:metadata:jedomi_ai_service:userid'] = 'A pseudonymous identifier of the user, derived by the Datacurso AI provider.';
$string['privacy:metadata:jedomi_speech_service'] = 'In the voice conversation of the web chat, the answers are sent sentence by sentence through the Datacurso AI provider to its AI service to be read aloud. The text is not stored by the AI service.';
$string['privacy:metadata:jedomi_speech_service:lang'] = 'The language of the user.';
$string['privacy:metadata:jedomi_speech_service:site_id'] = 'An identifier of the site, used by the AI service to keep the requests of each site apart.';
$string['privacy:metadata:jedomi_speech_service:site_url'] = 'The address of the site.';
$string['privacy:metadata:jedomi_speech_service:text'] = 'The text of the answer read aloud, anonymised on the site with the level of the Privacy tab of Jedomi before it is sent: names and email addresses of site users (and, at the Strict level, codes such as student numbers) are replaced by generic words such as "a person".';
$string['privacy:metadata:jedomi_speech_service:userid'] = 'A pseudonymous identifier of the user, derived by the Datacurso AI provider.';
$string['privacy:metadata:local_jedomi_agent_cache'] = 'Results of the runs of the scheduled agents, kept to be delivered and reused.';
$string['privacy:metadata:local_jedomi_agent_cache:agentid'] = 'The agent the result belongs to.';
$string['privacy:metadata:local_jedomi_agent_cache:result_json'] = 'The result produced by the agent, which can include data read from the site.';
$string['privacy:metadata:local_jedomi_agent_cache:timecreated'] = 'When the result was produced.';
$string['privacy:metadata:local_jedomi_agents'] = 'Scheduled agents created by the user, which run a request to the AI periodically.';
$string['privacy:metadata:local_jedomi_agents:name'] = 'The name of the agent.';
$string['privacy:metadata:local_jedomi_agents:prompt'] = 'The request the agent runs.';
$string['privacy:metadata:local_jedomi_agents:schedule_cron'] = 'When the agent runs.';
$string['privacy:metadata:local_jedomi_agents:timecreated'] = 'When the agent was created.';
$string['privacy:metadata:local_jedomi_agents:userid'] = 'The user who owns the agent.';
$string['privacy:metadata:local_jedomi_feedback'] = 'Ratings users give to Jedomi answers.';
$string['privacy:metadata:local_jedomi_feedback:messagetext'] = 'An excerpt of the rated answer.';
$string['privacy:metadata:local_jedomi_feedback:rating'] = 'Whether the answer was rated helpful or in need of improvement.';
$string['privacy:metadata:local_jedomi_feedback:sessionid'] = 'The chat session of the rated answer.';
$string['privacy:metadata:local_jedomi_feedback:timecreated'] = 'When the rating was given.';
$string['privacy:metadata:local_jedomi_feedback:userid'] = 'The user who rated the answer.';
$string['privacy:metadata:local_jedomi_pending_actions'] = 'Actions of the web chat that wait for the user\'s confirmation for up to 15 minutes.';
$string['privacy:metadata:local_jedomi_pending_actions:argsjson'] = 'Arguments of the action, such as the message to send and its recipients.';
$string['privacy:metadata:local_jedomi_pending_actions:severity'] = 'How careful the confirmation must be.';
$string['privacy:metadata:local_jedomi_pending_actions:timecreated'] = 'When the action was requested.';
$string['privacy:metadata:local_jedomi_pending_actions:tool'] = 'Tool the action runs.';
$string['privacy:metadata:local_jedomi_pending_actions:userid'] = 'User who must confirm the action.';
$string['privacy:metadata:local_jedomi_query_log'] = 'Database queries run by the agents, kept for the query profiler.';
$string['privacy:metadata:local_jedomi_query_log:agentid'] = 'The agent that ran the query.';
$string['privacy:metadata:local_jedomi_query_log:query'] = 'The text of the query, which can include names or other data of users.';
$string['privacy:metadata:local_jedomi_query_log:timecreated'] = 'When the query ran.';
$string['privacy:metadata:local_jedomi_telegram_links'] = 'The link between the Moodle account and a Telegram chat.';
$string['privacy:metadata:local_jedomi_telegram_links:chatid'] = 'The Telegram chat linked to the account.';
$string['privacy:metadata:local_jedomi_telegram_links:timecreated'] = 'When the link was created.';
$string['privacy:metadata:local_jedomi_telegram_links:userid'] = 'The linked Moodle user.';
$string['privacy:metadata:local_jedomi_telegram_links:username'] = 'The Telegram user name.';
$string['privacy:metadata:local_jedomi_telegram_pending_actions'] = 'Actions requested from Telegram that wait for the user\'s confirmation.';
$string['privacy:metadata:local_jedomi_telegram_pending_actions:actionjson'] = 'The action and its arguments.';
$string['privacy:metadata:local_jedomi_telegram_pending_actions:chatid'] = 'The Telegram chat that requested the action.';
$string['privacy:metadata:local_jedomi_telegram_rate_limit'] = 'Counters of the messages sent to the bot, used to limit their number.';
$string['privacy:metadata:local_jedomi_telegram_rate_limit:blockeduntil'] = 'Until when the chat cannot send messages.';
$string['privacy:metadata:local_jedomi_telegram_rate_limit:chatid'] = 'The Telegram chat.';
$string['privacy:metadata:local_jedomi_telegram_rate_limit:msgcount'] = 'How many messages were sent in the current period.';
$string['privacy:metadata:local_jedomi_telegram_sessions'] = 'The recent conversation with the bot in Telegram.';
$string['privacy:metadata:local_jedomi_telegram_sessions:chatid'] = 'The Telegram chat.';
$string['privacy:metadata:local_jedomi_telegram_sessions:lastactivity'] = 'When the last message was exchanged.';
$string['privacy:metadata:local_jedomi_telegram_sessions:messages'] = 'The messages of the conversation and the results of the tools used to answer them.';
$string['privacy:metadata:preference:local_jedomi_grade_notifications'] = 'Assignments whose grade was recently notified to the user in Telegram, with the time of each notification.';
$string['privacy:metadata:telegram'] = 'The answers of the bot, the notifications and the results of the agents are delivered to the linked Telegram chat.';
$string['privacy:metadata:telegram:chatid'] = 'The Telegram chat the data is sent to.';
$string['privacy:metadata:telegram:messages'] = 'The answers, notifications and agent results, which can include data of the site.';
$string['privacy_access_heading'] = 'Access control';
$string['privacy_access_heading_help'] = 'Who can see and use the Jedomi sidebar.';
$string['privacy_agents'] = 'Scheduled agents';
$string['privacy_anon_heading'] = 'Data anonymization';
$string['privacy_anon_heading_help'] = 'Control how conversation data is anonymized before storage.';
$string['privacy_feedback'] = 'Answer ratings';
$string['privacy_pending_actions'] = 'Actions waiting for confirmation';
$string['privacy_telegram'] = 'Telegram';
$string['processing_file'] = 'Processing {$a}...';
$string['provider_settings_info'] = 'The Enabled switch, the chat model, the file and image processing, the audio processing and the max tokens of Jedomi are set on its settings page in the Datacurso AI provider.';
$string['provider_settings_link'] = 'Open the Jedomi settings in the Datacurso AI provider';
$string['qa_admin_active_users_label'] = 'Active users';
$string['qa_admin_active_users_msg'] = 'How many active users are there in the system?';
$string['qa_admin_alerts_label'] = 'Alerts';
$string['qa_admin_alerts_msg'] = 'What requires immediate attention today?';
$string['qa_admin_at_risk_label'] = 'At risk';
$string['qa_admin_at_risk_msg'] = 'Which students are at risk of failing in course {$a}?';
$string['qa_admin_empty_cohorts_label'] = 'Empty cohorts';
$string['qa_admin_empty_cohorts_msg'] = 'Are there cohorts with no members assigned?';
$string['qa_admin_external_emails_label'] = 'External emails';
$string['qa_admin_external_emails_msg'] = 'Are there users with non-institutional emails?';
$string['qa_admin_gdpr_pending_label'] = 'GDPR pending';
$string['qa_admin_gdpr_pending_msg'] = 'Are there pending GDPR deletion requests?';
$string['qa_admin_inactive_label'] = 'Inactive';
$string['qa_admin_inactive_msg'] = 'Which students haven\'t accessed course {$a} in the last 30 days?';
$string['qa_admin_missing_work_label'] = 'Missing work';
$string['qa_admin_missing_work_msg'] = 'Which students haven\'t submitted activities in course {$a}?';
$string['qa_admin_overview_label'] = 'Overview';
$string['qa_admin_overview_msg'] = 'Give me a summary of the overall platform status';
$string['qa_admin_performance_label'] = 'Performance';
$string['qa_admin_performance_msg'] = 'Show me the grade ranking for course {$a}';
$string['qa_admin_security_label'] = 'Security';
$string['qa_admin_security_msg'] = 'Are there users with suspicious characters in their names?';
$string['qa_admin_storage_label'] = 'Storage';
$string['qa_admin_storage_msg'] = 'Which course has the heaviest files uploaded?';
$string['qa_admin_top_courses_label'] = 'Top courses';
$string['qa_admin_top_courses_msg'] = 'Which courses have the best performance?';
$string['qa_admin_top_student_label'] = 'Top student';
$string['qa_admin_top_student_msg'] = 'Who is the top student in course {$a}?';
$string['qa_course_current'] = 'current';
$string['qa_course_with_id'] = 'with ID {$a}';
$string['qa_manager_at_risk_label'] = 'At risk';
$string['qa_manager_at_risk_msg'] = 'Which students are at risk in course {$a}?';
$string['qa_manager_enrolled_label'] = 'Enrolled';
$string['qa_manager_enrolled_msg'] = 'How many students are enrolled in course {$a}?';
$string['qa_manager_global_risk_label'] = 'Global risk';
$string['qa_manager_global_risk_msg'] = 'Are there students at risk of failing in any course?';
$string['qa_manager_inactive_label'] = 'Inactive';
$string['qa_manager_inactive_msg'] = 'Which students haven\'t accessed course {$a} in the last 14 days?';
$string['qa_manager_inactive_users_label'] = 'Inactive users';
$string['qa_manager_inactive_users_msg'] = 'Which users haven\'t accessed the system in the last 30 days?';
$string['qa_manager_no_teacher_label'] = 'No teacher';
$string['qa_manager_no_teacher_msg'] = 'Are there courses with no teacher assigned?';
$string['qa_manager_overview_label'] = 'Overview';
$string['qa_manager_overview_msg'] = 'Give me a summary of course {$a}';
$string['qa_manager_top_courses_label'] = 'Top courses';
$string['qa_manager_top_courses_msg'] = 'Which courses have the most activity this week?';
$string['qa_student_help_label'] = 'Help';
$string['qa_student_help_msg'] = 'I don\'t understand the content of course {$a}. Can you explain?';
$string['qa_student_my_courses_label'] = 'My courses';
$string['qa_student_my_courses_msg'] = 'Which courses am I currently enrolled in?';
$string['qa_student_my_grade_label'] = 'My grade';
$string['qa_student_my_grade_msg'] = 'What is my average in course {$a}?';
$string['qa_student_my_grades_label'] = 'My grades';
$string['qa_student_my_grades_msg'] = 'What is my overall average across all my courses?';
$string['qa_student_my_progress_label'] = 'My progress';
$string['qa_student_my_progress_msg'] = 'How many activities have I completed in course {$a}?';
$string['qa_student_my_tasks_label'] = 'My tasks';
$string['qa_student_my_tasks_msg'] = 'What assignments do I have pending in course {$a}?';
$string['qa_student_this_week_label'] = 'This week';
$string['qa_student_this_week_msg'] = 'What activities and deadlines do I have coming up?';
$string['qa_teacher_at_risk_label'] = 'At risk';
$string['qa_teacher_at_risk_msg'] = 'Which students are at risk in course {$a}?';
$string['qa_teacher_missing_work_label'] = 'Missing work';
$string['qa_teacher_missing_work_msg'] = 'Who hasn\'t submitted the latest assignment in course {$a}?';
$string['qa_teacher_my_alerts_label'] = 'My alerts';
$string['qa_teacher_my_alerts_msg'] = 'Are there students at risk in any of my courses?';
$string['qa_teacher_my_courses_label'] = 'My courses';
$string['qa_teacher_my_courses_msg'] = 'In which courses am I currently a teacher?';
$string['qa_teacher_never_logged_label'] = 'Never logged in';
$string['qa_teacher_never_logged_msg'] = 'Which students never accessed course {$a}?';
$string['qa_teacher_notify_group_label'] = 'Notify group';
$string['qa_teacher_notify_group_msg'] = 'Draft a message for students in course {$a} reminding them of pending activities, '
    . 'and send it to them as a Moodle message with send_messages';
$string['qa_teacher_performance_label'] = 'Performance';
$string['qa_teacher_performance_msg'] = 'What is the average grade in course {$a}?';
$string['qa_teacher_top_students_label'] = 'Top students';
$string['qa_teacher_top_students_msg'] = 'Who has more than 90 points in course {$a}?';
$string['query_profiler'] = 'Query Profiler';
$string['query_profiler_avg'] = 'Avg duration';
$string['query_profiler_desc'] = 'Logs all SQL queries executed by agents, including execution time and row count. Use the filter '
    . 'below to find slow queries.';
$string['query_profiler_empty'] = 'No queries logged yet. Agents will log queries here when they use the SQL tool.';
$string['query_profiler_heading'] = 'SQL Query Profiler';
$string['query_profiler_max'] = 'Max duration';
$string['query_profiler_min_ms'] = 'Minimum duration (ms)';
$string['query_profiler_ms'] = '{$a} ms';
$string['query_profiler_recent'] = 'Recent queries';
$string['query_profiler_rows'] = 'Rows';
$string['query_profiler_slow'] = 'Slow queries (>1s)';
$string['query_profiler_slowest'] = 'Slowest queries (avg)';
$string['query_profiler_sort_duration'] = 'Duration';
$string['query_profiler_sort_query'] = 'Query';
$string['query_profiler_sort_time'] = 'Time';
$string['query_profiler_threshold'] = 'Slow query threshold (ms)';
$string['query_profiler_total'] = 'Total queries';
$string['query_timeout'] = 'Query timed out.';
$string['read_file_blocked'] = 'Access to this file is not allowed.';
$string['read_file_not_allowed'] = 'This path is not in the allowed list.';
$string['reading_image'] = 'Reading the image...';
$string['regenerate'] = 'Regenerate secret';
$string['role_visibility_help'] = 'Toggle OFF to hide the Jedomi sidebar from users with that role. Site admins always see it.';
$string['role_visibility_label'] = 'Show sidebar by role';
$string['search_ws_placeholder'] = 'Search web services...';
$string['security_heading'] = 'Security';
$string['security_heading_help'] = 'Secrets used to sign the permission tokens of the Jedomi sidebar.';
$string['send'] = 'Send';
$string['sending'] = 'Sending...';
$string['session_expired_hint'] = 'Your Moodle session has expired. Please reload the page to continue.';
$string['session_expired_title'] = 'Session expired.';
$string['settings'] = 'Jedomi Settings';
$string['settings_redirect_help'] = 'Jedomi settings are managed in a dedicated page with a tabbed interface.';
$string['sidebar_title'] = 'Jedomi AI';
$string['silence_detected'] = 'Silence...';
$string['speech_error_disabled'] = 'The voice conversation is not enabled on this site.';
$string['speech_error_empty'] = 'There is no text to read aloud.';
$string['speech_error_rate_limited'] = 'Too many answers were read aloud in the last minute. Wait a moment and try again.';
$string['speech_error_too_long'] = 'The text is too long to read aloud (at most {$a} characters).';
$string['speech_model'] = 'Voice model';
$string['speech_model_help'] = 'Model that reads the answers aloud in the voice conversation. Qwen3-TTS has the best Spanish quality in the tests; Inworld TTS 1.5 mini is faster. It consumes credits per character read.';
$string['speech_placeholder_email'] = 'an email address';
$string['speech_placeholder_id'] = 'an identifier';
$string['speech_placeholder_person'] = 'a person';
$string['speech_placeholder_text'] = 'some text';
$string['speech_voice'] = 'Voice';
$string['speech_voice_help'] = 'Voice of the chosen voice model that reads the answers. Each model has its own voices.';
$string['spt_secret'] = 'Permission token secret';
$string['spt_secret_help'] = 'Secret used to sign the chat permission tokens. It is generated automatically for this site and '
    . 'stored in Moodle, so it does not need to be set. Changing it invalidates the tokens already issued: users must reload '
    . 'the page to keep chatting.';
$string['sql_construct_rejected'] = 'The query uses syntax outside the allowed read-only subset, so it was not run. Write a single '
    . 'SELECT with FROM, [LEFT] JOIN ... ON, WHERE conditions joined only by AND (=, <>, <, >, IN (numbers), IS [NOT] NULL, '
    . 'LIKE \'text\'), GROUP BY, ORDER BY and LIMIT, and only the functions COUNT, SUM, AVG, MIN, MAX, ROUND and COALESCE. '
    . 'Do not use CASE, OR, NOT, subqueries, UNION, comments, quoted names, variables or semicolons.';
$string['sql_course_rejected'] = 'The query names a course you do not manage, so it was not run.';
$string['sql_course_unfiltered_rejected'] = 'The query reads course data without limiting it to the courses you manage, so it was '
    . 'not run. Limit every course table to the id of one of your courses (for example WHERE c.id = 12 or WHERE e.courseid IN '
    . '(12, 15)), and do not use OR, UNION, BETWEEN or subqueries.';
$string['sql_function_rejected'] = 'The query uses a function or clause that can read or write server files, pause or lock '
    . 'the database, reach other databases or reveal server variables (such as LOAD_FILE, SLEEP, BENCHMARK, INTO OUTFILE or '
    . '@@), so it was not run.';
$string['sql_sensitive_rejected'] = 'The query reads credentials, keys, tokens, site configuration or sessions, so it was not run.';
$string['sql_table_not_allowed'] = 'The query reads a table that is not available for the courses you manage (such as messages, '
    . 'notifications, user preferences or the whole user list), so it was not run. Read course tables limited to your courses, '
    . 'and read user names only by joining {user} to the enrolments, roles or grades of one of your courses (for example JOIN '
    . '{user_enrolments} ue ON ue.userid = u.id JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = 12).';
$string['sql_user_column_rejected'] = 'The query reads user columns other than the id and the names (such as the e-mail, phone, '
    . 'address or last IP), or every column with *, so it was not run. List only u.id, u.firstname, u.lastname, u.middlename, '
    . 'u.alternatename, u.firstnamephonetic or u.lastnamephonetic.';
$string['summary_config_data'] = 'config data retrieved';
$string['summary_done'] = 'done';
$string['summary_error_prefix'] = 'error: ';
$string['summary_function_details'] = 'function details retrieved';
$string['summary_functions_found'] = ' functions found';
$string['summary_invalid_json'] = 'error: invalid JSON response';
$string['summary_matching_tables'] = ' matching tables';
$string['summary_rows_returned'] = ' rows returned';
$string['summary_table_structure'] = 'table structure retrieved';
$string['summary_ws_executed'] = 'web service executed successfully';
$string['tab_agents'] = 'Agents';
$string['tab_apis'] = 'APIs';
$string['tab_apis_heading'] = 'Web Service Allowlists';
$string['tab_apis_heading_help'] = 'Configure which Moodle web services each role can access through Jedomi.';
$string['tab_general'] = 'General';
$string['tab_integrations'] = 'Integrations';
$string['tab_privacy'] = 'Privacy';
$string['tab_profiler'] = 'Query Profiler';
$string['table_not_found'] = 'Table not found: {$a}';
$string['task_instantiation_failed_msg'] = 'task_instantiation_failed';
$string['task_not_found_msg'] = 'task_not_found';
$string['task_skipped_plugin_disabled'] = 'Jedomi is disabled in the Datacurso AI provider; skipping.';
$string['telegram_admin_not_configured_desc'] = 'Set up the Telegram bot in the Jedomi settings. Once it is configured, users see the Telegram linking option here.';
$string['telegram_admin_not_configured_title'] = 'Telegram is not configured (only administrators see this message)';
$string['telegram_admin_settings_link'] = 'Jedomi settings → Integrations';
$string['telegram_bot_token'] = 'Bot token';
$string['telegram_bot_token_help'] = 'Paste the token from @BotFather. It is encrypted before storage.';
$string['telegram_bot_token_placeholder'] = 'Paste the token from @BotFather';
$string['telegram_bot_token_placeholder_saved'] = 'Saved. Leave blank to keep it.';
$string['telegram_code_expired'] = 'Expired';
$string['telegram_confirm_action'] = '⚠️ This action changes data and runs only if you confirm it: {$a}';
$string['telegram_connection_status'] = 'Connection status';
$string['telegram_cooldown'] = 'Cooldown';
$string['telegram_cooldown_help'] = 'Minutes to block a user after exceeding the rate limit.';
$string['telegram_drop_pending_failed'] = 'The pending updates could not be dropped. Check the Telegram API response.';
$string['telegram_enabled'] = 'Enable Telegram integration';
$string['telegram_enabled_save_failed'] = 'The Telegram integration switch could not be saved. Please try again.';
$string['telegram_gateway_timeout'] = 'AI response timeout (seconds)';
$string['telegram_gateway_timeout_help'] = 'Maximum seconds to wait for each AI request of the Telegram bot, sent through the '
    . 'Datacurso AI provider (5-300).';
$string['telegram_heading'] = 'Telegram';
$string['telegram_heading_help'] = 'Connect Jedomi to a Telegram bot so users can chat via Telegram.';
$string['telegram_help_footer'] = 'After linking, send any message and I\'ll forward it to Jedomi AI for a response.';
$string['telegram_help_heading'] = '🤖 <b>Jedomi Bot — Available Commands</b>';
$string['telegram_help_help'] = '❓ <b>/help</b> — Show this help message';
$string['telegram_help_new'] = '🗑️ <b>/new</b> or <b>/nuevo</b> — Clear conversation and start fresh';
$string['telegram_help_start'] = '🔗 <b>/start CODE</b> — Link your Moodle account to Telegram';
$string['telegram_help_unlink'] = '🚫 <b>/desvincular</b> or <b>/unlink</b> — Unlink your Telegram from Moodle';
$string['telegram_howto_confirm'] = 'Dangerous actions show confirmation buttons before running.';
$string['telegram_howto_question'] = 'Send any question and Jedomi answers with live Moodle data.';
$string['telegram_howto_screenshot'] = 'Send a screenshot of an error and Jedomi analyses it (AI vision).';
$string['telegram_howto_title'] = 'How to use Jedomi from Telegram';
$string['telegram_howto_unlink'] = 'Send <code>/desvincular</code> to unlink this Telegram account.';
$string['telegram_keyboard_help'] = '❓ Help';
$string['telegram_keyboard_howto_link'] = '🔗 How to link?';
$string['telegram_keyboard_new'] = '📝 New conversation';
$string['telegram_keyboard_unlink'] = '🚫 Unlink';
$string['telegram_link_code_expiry'] = 'This code expires in 10 minutes.';
$string['telegram_link_generate'] = 'Generate code';
$string['telegram_link_instructions'] = 'Open Telegram, find your bot, and send /start followed by the code.';
$string['telegram_link_new_code'] = 'Generate a new code';
$string['telegram_link_open_bot'] = 'Open @{$a} in Telegram';
$string['telegram_link_step1_desc'] = 'Tap the button below to open the bot directly, or search for <strong>@{$a}</strong> in Telegram.';
$string['telegram_link_step1_title'] = 'Open the bot in Telegram';
$string['telegram_link_step2_desc'] = 'Send this command to <strong>@{$a}</strong>:';
$string['telegram_link_step3_desc'] = 'The bot confirms the link at once. After that, send any message to start using Jedomi from Telegram.';
$string['telegram_link_step3_title'] = 'Done!';
$string['telegram_link_title'] = 'Link Telegram';
$string['telegram_linked_as'] = 'Linked as @{username}';
$string['telegram_linked_chatid'] = 'chat_id: {$a}';
$string['telegram_linked_on'] = 'Linked on {$a}';
$string['telegram_max_turns'] = 'Maximum conversation turns';
$string['telegram_max_turns_help'] = 'Number of messages kept in Telegram conversation history (1-100). Lower values save tokens.';
$string['telegram_msg_account_inactive'] = 'Your Moodle account is not active, so the bot cannot serve it. Please contact the site administrator.';
$string['telegram_msg_audio_disabled'] = 'Voice and audio messages are not enabled on this site. Please send your question as text.';
$string['telegram_msg_done'] = 'Done.';
$string['telegram_msg_executing'] = 'Executing...';
$string['telegram_msg_expired_code'] = 'Invalid or expired code. Generate a new one from your Moodle profile.';
$string['telegram_msg_file_unsupported'] = 'Only PDF, Word (DOC, DOCX), PowerPoint (PPT, PPTX), JPG, PNG and WEBP files can be read.';
$string['telegram_msg_files_disabled'] = 'Photos and files are not enabled on this site. Please send your question as text.';
$string['telegram_msg_heard'] = '🎤 {$a}';
$string['telegram_msg_link_first'] = 'Link your account first: go to your Moodle profile and click "Link Telegram".';
$string['telegram_msg_linked_success'] = '✅ Linked to Moodle as <b>{$a->fullname}</b> ({$a->username})';
$string['telegram_msg_max_iterations'] = '⚠️ Too many steps. Please simplify your request.';
$string['telegram_msg_new_conversation'] = '🗑️ Conversation cleared. Start fresh!';
$string['telegram_msg_private_only'] = 'The bot only works in a private chat with each user. Please write to it in a private chat.';
$string['telegram_msg_provide_code'] = '🔗 Please provide your link code.

Example: /start ABC123';
$string['telegram_msg_rate_check_failed'] = 'Your message could not be processed right now. Please send it again in a moment.';
$string['telegram_msg_rate_limited'] = 'Too many messages. Please wait {$a->minutes} minutes.';
$string['telegram_msg_role_blocked'] = 'Your role does not have access to Jedomi Telegram.';
$string['telegram_msg_text_only'] = 'I can only read text, photos, documents and voice messages. Please send your question as text.';
$string['telegram_msg_timeout'] = '⏳ This task is too complex for the time limit. Try splitting it into smaller steps or send '
    . 'it again and I\'ll continue where I left off.';
$string['telegram_msg_token_invalid'] = 'Invalid bot token. Check the token from @BotFather and try again.';
$string['telegram_msg_unlinked'] = 'Unlinked. Send /start to link again.';
$string['telegram_no_app_desc'] = 'If you are on a computer without the app, open Telegram on your phone, search for <strong>@{$a}</strong> and send this message:';
$string['telegram_no_app_title'] = 'No Telegram app?';
$string['telegram_not_linked'] = 'Not linked';
$string['telegram_open_auto'] = 'Open in Telegram (automatic)';
$string['telegram_open_auto_help'] = 'Takes you straight to @{$a} with your code ready to link.';
$string['telegram_progress'] = '🤔 Jedomi is thinking…';
$string['telegram_quick_step1_desc'] = 'a 6-character code appears, valid for 10 minutes.';
$string['telegram_quick_step1_title'] = 'Click "Generate code" below';
$string['telegram_quick_step2_desc'] = 'send <code>/start YOUR_CODE</code>.';
$string['telegram_quick_step2_title'] = 'Open in Telegram:';
$string['telegram_quick_step3_desc'] = '{$a} confirms at once. Ask anything about your Moodle.';
$string['telegram_quick_step3_title'] = 'That\'s it!';
$string['telegram_rate_limit'] = 'Rate limit';
$string['telegram_rate_limit_help'] = 'Maximum messages per minute a user can send. 0 = unlimited.';
$string['telegram_regenerate_confirm'] = 'The webhook URL changes and is registered again in Telegram. Continue?';
$string['telegram_role_visibility_help'] = 'Toggle OFF to prevent users with this role from linking or receiving Telegram '
    . 'messages. Admins always have access.';
$string['telegram_role_visible'] = 'Allow Telegram for role: {role}';
$string['telegram_setup_done'] = 'Integration configured';
$string['telegram_setup_done_help'] = 'Telegram is active. Users with permitted roles can link their accounts from their Moodle '
    . 'profile.';
$string['telegram_setup_guide'] = 'Setup Guide';
$string['telegram_setup_guide_help'] = 'Follow these steps to connect Jedomi to Telegram. Once configured, users can link their '
    . 'accounts from their profile.';
$string['telegram_setup_hide'] = 'Hide guide';
$string['telegram_setup_show'] = 'Show setup guide';
$string['telegram_status_connected'] = 'Connected';
$string['telegram_status_disconnected'] = 'Disconnected';
$string['telegram_status_invalid_token'] = 'invalid token, paste it again and save.';
$string['telegram_status_pending'] = 'Pending — save the bot token to register the webhook';
$string['telegram_step1_desc'] = 'Open Telegram, search for <strong>@BotFather</strong>, send <code>/newbot</code>, choose a name '
    . 'and username. Copy the token it gives you (format: <code>123456:ABC-DEF...</code>).';
$string['telegram_step1_title'] = 'Create a bot in @BotFather';
$string['telegram_step2_desc'] = 'Paste the token in the <strong>Bot token</strong> field below, enable the integration toggle, '
    . 'and click <strong>Save changes</strong>.';
$string['telegram_step2_title'] = 'Paste the token and save';
$string['telegram_step3_desc'] = 'Nothing to do: the plugin registers the <strong>Webhook URL</strong> in Telegram '
    . 'automatically when the bot token is saved. If the site URL changes, save the bot token again to register the new URL.';
$string['telegram_step3_note'] = 'Telegram only delivers messages to a public HTTPS URL. For local development, expose the site '
    . 'with a tunnel such as ngrok and use that URL as the site URL.';
$string['telegram_step3_title'] = 'Register the webhook in Telegram';
$string['telegram_step4_desc'] = 'Use the role toggles below to control which Moodle roles can use Telegram. Site admins always '
    . 'have access regardless of this setting.';
$string['telegram_step4_title'] = 'Configure role access (optional)';
$string['telegram_stepU1_desc'] = 'Click your name in the top bar → <strong>Profile</strong>. You will see a <strong>Jedomi '
    . 'AI</strong> section with the <em>Link Telegram</em> link.';
$string['telegram_stepU1_title'] = 'Go to your Moodle profile';
$string['telegram_stepU2_desc'] = 'Click <strong>Generate code</strong>. A 6-character code appears, valid for 10 minutes.';
$string['telegram_stepU2_title'] = 'Generate a linking code';
$string['telegram_stepU3_desc'] = 'In Telegram, open your institution\'s bot and send: <code>/start YOUR_CODE</code>. The bot '
    . 'confirms the link instantly.';
$string['telegram_stepU3_title'] = 'Send /start to the bot';
$string['telegram_stepU4_desc'] = 'Send any message to the bot. Jedomi answers with real data from your Moodle. To unlink, send '
    . '<code>/desvincular</code> or use your profile page.';
$string['telegram_stepU4_title'] = 'Start chatting';
$string['telegram_step_admin_help'] = 'Only needs to be done once. Requires access to Telegram.';
$string['telegram_step_admin_title'] = 'Administrator Setup';
$string['telegram_step_user_help'] = 'After the admin configures the bot, users link their own Telegram accounts.';
$string['telegram_step_user_title'] = 'User Setup (each user does this once)';
$string['telegram_still_working'] = '🔄 Still working on it, hang tight…';
$string['telegram_token_not_configured'] = 'The bot token is not configured.';
$string['telegram_webhook_auto_missing_desc'] = 'Try saving the token again.';
$string['telegram_webhook_auto_missing_title'] = 'Webhook not registered';
$string['telegram_webhook_auto_ok_desc'] = 'When the token was saved, the webhook was registered automatically.';
$string['telegram_webhook_auto_ok_title'] = 'Webhook registered automatically';
$string['telegram_webhook_info_failed'] = 'getWebhookInfo failed. Check the bot token and outbound HTTPS access.';
$string['telegram_webhook_secret'] = 'Webhook secret';
$string['telegram_webhook_secret_help'] = 'Random secret appended to the webhook URL for security. Regenerate if compromised.';
$string['telegram_webhook_status'] = 'Webhook Status';
$string['telegram_webhook_url'] = 'Webhook URL';
$string['telegram_webhook_url_help'] = 'Registered in Telegram automatically when the bot token is saved. If the site URL '
    . 'changes, save the bot token again to register the new URL.';
$string['thinking'] = 'Thinking...';
$string['toggle_thinking'] = 'Toggle thinking animation';
$string['token_expired'] = 'The chat permissions have expired. Reload the page to keep using Jedomi.';
$string['tool_admin_describe_desc'] = 'Get full documentation (parameters, types, required fields) for a specific admin CLI '
    . 'operation by name. Use this AFTER admin_search and BEFORE admin_execute to verify required parameters.';
$string['tool_admin_describe_name'] = 'Operation name (e.g. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_desc'] = 'Execute an admin CLI operation to create or modify Moodle data. Requires admin or manager '
    . 'role. USE WITH CAUTION — these operations modify the database. COMBINE with WS tools: first use ws_search/ws_execute to '
    . 'discover courses, sections, users, or verify data, then call admin_execute with the gathered info. Example flow: '
    . 'ws_execute(core_course_get_courses_by_field) to find course → admin_execute(create_mod, ...) to create an activity.';
$string['tool_admin_execute_name'] = 'Operation name (e.g. backup, scheduled_task, purge_caches).';
$string['tool_admin_execute_params'] = 'JSON object of parameters for the operation (use admin_describe first to see required '
    . 'fields).';
$string['tool_admin_search_desc'] = 'Search for CLI operations available to administrators (e.g. backup, purge caches, run '
    . 'scheduled tasks). Use this to discover what operations exist before describing or executing them.';
$string['tool_admin_search_limit'] = 'Maximum number of results to return (default: 8, max: 20).';
$string['tool_admin_search_query'] = 'Search keywords to find relevant admin operations.';
$string['tool_api_call'] = 'Executing Moodle action...';
$string['tool_create_agent_context_id'] = 'The ID of the course, category, or user context. Required when context_type is not '
    . '"site".';
$string['tool_create_agent_context_type'] = 'Scope of execution: "site" for global, "course" to limit to a specific course, '
    . '"category" for a course category, "user" for a specific user. Defaults to "site".';
$string['tool_create_agent_desc'] = 'Create a scheduled AI agent. The agent will execute the given prompt on a recurring '
    . 'schedule, cache results, and send notifications via Telegram (if linked). Only available to admins and managers. Use this '
    . 'when a user wants to automate recurring AI tasks like daily summaries, monitoring, or periodic reports. Returns the new '
    . 'agent ID.';
$string['tool_create_agent_enabled'] = 'Whether the agent starts enabled. Default: true. Set to false to create but not activate '
    . 'yet.';
$string['tool_create_agent_prompt'] = 'The task description or instruction the AI agent will execute each time it runs. Be '
    . 'specific about what data to fetch, analyze, or report. Example: "Summarize new enrollments and activity from the last 24 '
    . 'hours" or "Check for students at risk of failing in course 42 and notify me".';
$string['tool_create_agent_schedule'] = 'Cron expression defining how often the agent runs. Common presets: "*/5 * * * *" = every '
    . '5 minutes, "*/30 * * * *" = every 30 minutes, "0 * * * *" = hourly, "0 0 * * *" = daily at midnight, "0 0 * * 0" = weekly '
    . 'on Sunday.';
$string['tool_delete_agent_desc'] = 'Delete a scheduled AI agent by its ID. This permanently removes the agent and all its cached '
    . 'results. Cannot be undone.';
$string['tool_delete_agent_id'] = 'The numeric ID of the agent to delete. Use list_agents to find the ID.';
$string['tool_describe_table'] = 'Analyzing table...';
$string['tool_describe_table_desc'] = 'Obtiene la estructura (columnas, tipos, claves, indices, foreign keys) de una tabla de la '
    . 'base de datos de Moodle. Cacheado 24h.';
$string['tool_describe_table_tablename'] = 'Nombre de la tabla (ej: course, user, grade_grades)';
$string['tool_document_read_cmid'] = 'Course module ID (cmid) of the activity. Get it from the page context when viewing an '
    . 'activity, or discover it via ws_search/ws_execute first.';
$string['tool_document_read_desc'] = 'Read the PDF published in a course activity (its description or content, never student submissions or '
    . 'feedback) using its cmid (course module ID). Returns the text of the pages; '
    . 'scanned pages, tables and diagrams are read by a vision model. Use this when the user asks to read, analyze, summarize '
    . 'or explain a document of an activity.';
$string['tool_document_read_pages'] = 'Optional pages to read, for example "1,3-5". Leave it out to read the whole document.';
$string['tool_execute_sql'] = 'Querying database...';
$string['tool_execute_sql_desc'] = 'Ejecuta una consulta SELECT en la base de datos de Moodle y devuelve las filas como JSON. '
    . 'SOLO lectura. Limite: 100 filas, timeout 5 segundos.';
$string['tool_execute_sql_params'] = 'Parametros vinculados para la consulta (opcional)';
$string['tool_execute_sql_sql'] = 'Consulta SELECT a ejecutar. Usa ? como placeholder para parametros. Ejemplo: SELECT id, '
    . 'fullname FROM {course} WHERE category = ?';
$string['tool_get_site_config'] = 'Getting site configuration...';
$string['tool_get_site_config_desc'] = 'Obtiene configuracion del sitio Moodle (sitename, lang, timezone, etc.). Solo disponible '
    . 'para administradores.';
$string['tool_get_site_config_keys'] = 'Lista de claves de configuracion. Validas: sitename, siteurl, lang, country, timezone, '
    . 'maxbytes, passwordpolicy, enrol_plugins_enabled, auth_plugins_enabled';
$string['tool_list_agents_desc'] = 'List all configured scheduled AI agents, optionally filtered by status or context. Returns '
    . 'agent ID, prompt excerpt, schedule, context, status, last run, and next run.';
$string['tool_list_agents_status'] = 'Optional filter: "enabled" to show only active agents, "disabled" for inactive ones. Leave '
    . 'empty to show all.';
$string['tool_my_assignments_desc'] = 'Read-only. The assignments of the courses of the current user, with their opening, due and '
    . 'cut-off dates and the submission status, and the upcoming deadlines of the user calendar. Use it when a student asks about '
    . 'their assignments, homework or deadlines.';
$string['tool_my_courses_desc'] = 'Read-only. The courses in which the current user has an active enrolment. Use it when a '
    . 'student asks about their courses.';
$string['tool_my_grades_desc'] = 'Read-only. The grades the current user can see in their courses (hidden or unreleased grades '
    . 'are not included). Use it when a student asks about their grades.';
$string['tool_own_data_courseid'] = 'Optional: id of one of the courses of the user, to read only that course.';
$string['tool_schema_lookup'] = 'Looking up table...';
$string['tool_search_tables'] = 'Searching tables...';
$string['tool_search_tables_desc'] = 'Search Moodle tables by intent or keywords in ENGLISH. FALLBACK for when web services '
    . 'cannot provide the needed data. Only use this AFTER confirming no relevant web service exists. Searches across table names '
    . 'AND their COMMENT descriptions from install.xml. Returns name, comment, field names, and field comments.';
$string['tool_search_tables_limit'] = 'Maximum results (default: 8, max: 15)';
$string['tool_search_tables_query'] = 'Intent or keywords in ENGLISH (e.g. "user grades", "course enrolment", "student")';
$string['tool_send_messages_desc'] = 'Send a Moodle internal message to one or more users. '
    . 'Only when the user explicitly asks to send or notify a message; never to answer a question or analyse data. '
    . 'Find the user ids first '
    . '(ws_search/ws_execute or execute_sql). The user confirms before the messages are sent: call this tool once '
    . 'and do not ask for confirmation in text.';
$string['tool_send_messages_message'] = 'Plain text of the message, in the user\'s language.';
$string['tool_send_messages_user_ids'] = 'Moodle user ids of the recipients.';
$string['tool_sql_read'] = 'Querying database...';
$string['tool_unknown'] = 'Running tool...';
$string['tool_webservice_lookup'] = 'Looking up API function...';
$string['tool_ws_describe'] = 'Getting webservice function details...';
$string['tool_ws_describe_desc'] = 'Get full documentation (description, parameters with * = required, return type) for a '
    . 'specific web service function when you already know its exact name. ALWAYS call this AFTER ws_search and BEFORE ws_execute '
    . 'to verify required fields.';
$string['tool_ws_describe_wsname'] = 'Exact function name (e.g. core_user_create_users)';
$string['tool_ws_execute'] = 'Executing web service...';
$string['tool_ws_execute_desc'] = 'Execute a Moodle web service function on behalf of the current user. Use AFTER ws_search to '
    . 'get the exact function name and parameters. If the first attempt fails with "Missing required key" or "invalid parameter", '
    . 'retry with default values for optional fields. For batch operations (same action on multiple items), call this function '
    . 'MULTIPLE times — once per item.';
$string['tool_ws_execute_params'] = 'JSON-encoded parameters for the web service function. MUST include every * (required) field. '
    . 'IMPORTANT: If the first attempt fails with "Detectado valor de parámetro no válido" or "Missing required key", the '
    . 'instance requires fields that the schema marks as optional. In that case, RETRY by adding default values for missing '
    . 'optional fields (e.g. description="", enrolmentkey="", idnumber="", visibility=0, participation=true).';
$string['tool_ws_execute_wsname'] = 'Web service function name (e.g. core_course_get_courses)';
$string['tool_ws_search'] = 'Looking up webservice functions...';
$string['tool_ws_search_desc'] = 'Search Moodle web service functions by intent or keywords in ENGLISH. Returns the most relevant '
    . 'matches with full description, parameters (* = required), and return structure. If results include what you need: STOP '
    . 'searching entirely — call ws_describe on the exact name, then ws_execute. Do NOT call SQL tools (search_tables, '
    . 'describe_table, execute_sql) when WS already covers your need. Do NOT call ws_search again for the same task.';
$string['tool_ws_search_limit'] = 'Máximo de resultados a devolver (default: 6, max: 15)';
$string['tool_ws_search_query'] = 'Intent or keywords in ENGLISH (e.g. "create user", "enrol student", "get course grades")';
$string['training_data_title'] = 'Jedomi Training Data';
$string['transcribing'] = 'Transcribing...';
$string['unauthorized'] = 'Unauthorized.';
$string['unknown_error'] = 'Unknown error';
$string['unlink'] = 'Unlink';
$string['vision_model'] = 'File and image model';
$string['vision_model_help'] = 'Model that reads images and the PDF pages without text (scanned pages). PDF pages with text are read without it. Qwen3-VL 30B A3B is the fastest and cheapest in the tests.';
$string['voice_cancel'] = 'Cancel recording';
$string['voice_conversation'] = 'Voice conversation';
$string['voice_delete'] = 'Delete voice message';
$string['voice_enabled'] = 'Enable voice conversation';
$string['voice_enabled_help'] = 'Adds a voice conversation button to the web chat: the user speaks, Jedomi transcribes it with the audio model, answers and reads the answer aloud with the voice model, then listens again. Needs audio processing. Reading aloud consumes credits per character; actions that change data still need the confirmation on screen.';
$string['voice_input'] = 'Voice input';
$string['voice_message'] = '🎤 {$a}';
$string['voice_mode'] = 'Voice conversation';
$string['voice_mode_confirm_notice'] = 'Please confirm the action on screen.';
$string['voice_mode_exit'] = 'End voice conversation';
$string['voice_mode_listening'] = 'Listening...';
$string['voice_mode_paused'] = 'Waiting for the confirmation on screen';
$string['voice_mode_speaking'] = 'Speaking...';
$string['voice_mode_speech_error'] = 'The answer could not be read aloud; it is shown in the chat.';
$string['voice_mode_thinking'] = 'Thinking...';
$string['voice_mode_transcription_error'] = 'What you said could not be understood. Please speak again.';
$string['voice_pause'] = 'Pause voice message';
$string['voice_play'] = 'Play voice message';
$string['voice_recording'] = 'Recording...';
$string['voice_send'] = 'Send voice message';
$string['voice_stop'] = 'Stop recording';
$string['waiting_voice'] = 'Waiting for voice...';
$string['webhook_checking'] = 'Checking...';
$string['webhook_error'] = 'Last webhook error:';
$string['webhook_error_alert_desc'] = 'The bot token was saved and validated, but the webhook could not be registered with '
    . 'Telegram.';
$string['webhook_error_alert_title'] = 'Webhook registration issue';
$string['webhook_error_detail'] = 'Error details:';
$string['webhook_live_api_error'] = 'API error';
$string['webhook_live_healthy'] = 'Webhook is healthy.';
$string['webhook_live_last_registration'] = 'Last registration result:';
$string['webhook_live_network_error'] = 'Network error';
$string['webhook_live_pending'] = 'Pending updates:';
$string['webhook_live_telegram_error'] = 'Telegram error:';
$string['webhook_live_unknown_error'] = 'Unknown error';
$string['webhook_live_url'] = 'Webhook URL:';
$string['webhook_not_checked'] = 'Not checked yet. Click "Check webhook" to verify.';
$string['webhook_ok'] = 'Webhook is configured and healthy.';
$string['ws_cannot_load_info'] = 'Cannot load function info: {$a}';
$string['ws_component'] = 'Component';
$string['ws_debug_prefix'] = ' | debug: ';
$string['ws_description'] = 'Description';
$string['ws_execute_error'] = 'Error executing web service: {$a}';
$string['ws_function_not_found'] = 'Function not found: {$a}';
$string['ws_method_not_exists'] = 'Method {$a} does not exist';
$string['ws_name'] = 'Name';
$string['ws_no_services'] = 'No web services configured for this role.';
$string['ws_none_found'] = 'No web services match your filters.';
$string['ws_not_found'] = 'Web service function not found.';
$string['ws_not_in_allowlist'] = 'This web service is not in your role\'s allowlist.';
$string['ws_pagination'] = 'Page {$a->page} of {$a->pages} ({$a->total} services)';
$string['ws_type'] = 'Type';
$string['ws_type_all'] = 'All types';
$string['ws_type_read'] = 'Read';
$string['ws_type_write'] = 'Write';
$string['yes_send'] = 'Yes, send';
