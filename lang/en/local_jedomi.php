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
$string['agent_approve_writes_owner_only'] = 'Only the owner of the agent can approve writes for it. You can keep or remove the approved writes, but not add new ones.';
$string['agent_approved_writes'] = 'Writes run without confirmation';
$string['agent_approved_writes_dropped'] = 'These approved writes were removed from the agent because its owner may no longer approve them: {$a}';
$string['agent_approved_writes_empty'] = 'None: every write waits for the approval of the creator';
$string['agent_approved_writes_help'] = 'Web service functions that change data and that this agent may run without your confirmation, only inside its course or context and only for you and the users of that context. Any other write waits for your approval in the Jedomi assistant. Each run makes at most {$a->writes} approved writes to at most {$a->recipients} different users; going over stops the run.';
$string['agent_approved_writes_none'] = 'There are no write functions to approve: save a web service list with write functions for your role in the Web services tab.';
$string['agent_approved_writes_notice'] = 'Since version 1.6.1 a scheduled agent only runs the writes approved for it in its form, inside its course or context. Existing agents have no approved writes: their writes wait for the approval of their creator, who is notified.';
$string['agent_by_role'] = 'Agents by role';
$string['agent_by_status'] = 'Agents by status';
$string['agent_chart_no_data'] = 'No data';
$string['agent_context'] = 'Context';
$string['agent_context_id'] = 'Context ID';
$string['agent_context_id_help'] = 'ID of the Moodle context, not of the course, category or user: for example, the context '
    . 'number shown in the address of the Permissions page of the course (contextid).';
$string['agent_context_id_placeholder'] = 'e.g. 42';
$string['agent_context_label'] = '{$a->type}: {$a->name}';
$string['agent_context_missing'] = '{$a->type} #{$a->id} (not found)';
$string['agent_context_type_category'] = 'Category';
$string['agent_context_type_course'] = 'Course';
$string['agent_context_type_site'] = 'Site';
$string['agent_context_type_user'] = 'User';
$string['agent_create'] = 'Create agent';
$string['agent_created_message'] = '✅ Agent "{$a}" created.';
$string['agent_created_no_name'] = 'Agent created but no name was set. Edit the agent to add a name.';
$string['agent_created_with_name'] = 'Agent "{$a}" created successfully.';
$string['agent_creator'] = 'Creator';
$string['agent_cron_custom'] = 'Custom: {$a}';
$string['agent_cron_daily_at'] = 'Every day at {$a}';
$string['agent_cron_hourly_at'] = 'Every hour at minute {$a}';
$string['agent_cron_monthly_at'] = 'Every month on day {$a->day} at {$a->time}';
$string['agent_cron_weekly_at'] = 'Every {$a->day} at {$a->time}';
$string['agent_custom_cron'] = 'Custom (cron expression)';
$string['agent_daily'] = 'Daily';
$string['agent_delete'] = 'Delete';
$string['agent_delete_confirm'] = 'Delete this agent? This action cannot be undone.';
$string['agent_deleted_message'] = 'Agent #{$a} deleted.';
$string['agent_detail_already_done'] = 'This action was not run again: a previous attempt of this scheduled run already made it successfully. Do not repeat it; continue with the rest of the instruction.';
$string['agent_detail_held'] = 'This action was not run: it waits for the approval of the agent creator. Do not repeat it; continue with the rest of the instruction.';
$string['agent_detail_held_cap'] = 'This write was neither held nor made: the run already holds {$a} writes waiting for approval. Do not propose more writes in this run.';
$string['agent_detail_out_of_scope'] = 'This action was not run: its arguments are outside the course or context of the agent or its allowed users.';
$string['agent_disable'] = 'Disable';
$string['agent_disabled'] = 'Disabled';
$string['agent_disabled_notice'] = 'Agent disabled';
$string['agent_disabled_reason_creator_deleted'] = 'The creator of the agent was deleted.';
$string['agent_disabled_reason_creator_missing'] = 'The creator of the agent no longer exists.';
$string['agent_disabled_reason_creator_suspended'] = 'The creator of the agent is suspended.';
$string['agent_disabled_reason_failures'] = 'The agent failed {$a} times in a row and was disabled. Review it and enable it again.';
$string['agent_disabled_reason_no_access'] = 'The creator no longer has access to the context of the agent.';
$string['agent_edit'] = 'Edit';
$string['agent_enable'] = 'Enable';
$string['agent_enabled'] = 'Enabled';
$string['agent_enabled_help'] = 'Enabled agents run on their schedule. A disabled agent does not run, not even with Run now.';
$string['agent_error_create'] = 'Error creating agent: {$a}';
$string['agent_error_empty_answer'] = 'The AI did not write an answer, also after being asked again. Try a shorter instruction or a model with a larger answer limit.';
$string['agent_error_load'] = 'Error loading agents.';
$string['agent_error_nopermission'] = 'You cannot manage the agents of users with more permissions than yours.';
$string['agent_error_run_failed'] = 'The run failed because of an unexpected error (reference {$a}). The details are in the '
    . 'Jedomi technical log.';
$string['agent_error_update'] = 'Error updating agent: {$a}';
$string['agent_every_15min'] = 'Every 15 minutes';
$string['agent_every_1h'] = 'Every hour';
$string['agent_every_30min'] = 'Every 30 minutes';
$string['agent_every_5min'] = 'Every 5 minutes';
$string['agent_every_6h'] = 'Every 6 hours';
$string['agent_executions_30d'] = 'Executions (30 days)';
$string['agent_failing'] = 'Failing';
$string['agent_failing_count'] = '{$a} consecutive failed runs: it is retried later and disabled if it keeps failing.';
$string['agent_filter_context'] = 'Context';
$string['agent_filter_role'] = 'Role';
$string['agent_filter_status'] = 'Status';
$string['agent_invalid'] = '{$a}';
$string['agent_invalid_approved_write'] = 'the write function "{$a}" cannot be approved for this agent: it is not a write function of the web service list of the role of its owner';
$string['agent_invalid_context_excluded'] = 'the course of this context is excluded from Jedomi in the Course availability tab';
$string['agent_invalid_context_id'] = 'the context ID is not the ID of an existing Moodle context of type {$a}';
$string['agent_invalid_context_type'] = 'invalid context type';
$string['agent_invalid_cron'] = 'invalid custom schedule: use five fields (minute, hour, day of the month, month, day of the '
    . 'week) with values in range, for example "30 6 * * 1-5"';
$string['agent_invalid_prompt'] = 'the instruction cannot be empty';
$string['agent_lasterror'] = 'Last error';
$string['agent_lasterror_hidden'] = 'The last run failed.';
$string['agent_lastresult'] = 'Last result';
$string['agent_lastresult_none'] = 'The agent has not produced a result yet.';
$string['agent_lastrun'] = 'Last run';
$string['agent_name'] = 'Name';
$string['agent_name_placeholder'] = 'e.g. Daily report';
$string['agent_nextrun'] = 'Next run';
$string['agent_no_functions_subject'] = 'Jedomi agent "{$a}" cannot use web services';
$string['agent_no_functions_text'] = 'This agent cannot use any web service function, not even to read data: agents never get access to every function, and no web service list is saved for the administrator role. Save that list in the Jedomi settings so the agent can use the functions in it.';
$string['agent_not_found'] = 'Agent not found.';
$string['agent_notice_success'] = 'Executed successfully';
$string['agent_number'] = 'Agent #{$a}';
$string['agent_preview'] = 'Preview';
$string['agent_preview_arguments'] = 'Arguments';
$string['agent_preview_cached'] = 'Cached result from';
$string['agent_preview_clear_cache'] = 'Clear cache';
$string['agent_preview_error'] = 'Preview failed. Check the prompt and try again.';
$string['agent_preview_prompt_required'] = 'Write a prompt first.';
$string['agent_preview_result'] = 'Agent preview result';
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
$string['agent_purge'] = 'Purge agents';
$string['agent_purge_btn'] = 'Purge context agents';
$string['agent_purge_confirm'] = 'This will delete all matching agents and their cached results. Continue?';
$string['agent_purge_context'] = 'Context type';
$string['agent_purge_context_help'] = 'Delete all agents belonging to a specific context.';
$string['agent_purge_id_required'] = 'Enter the Moodle context ID of the course, category or user to purge.';
$string['agent_purge_invalid_type'] = 'Invalid purge type: {$a}';
$string['agent_purge_role_not_found'] = 'Role not found.';
$string['agent_purge_success'] = '{$a} agent(s) deleted successfully.';
$string['agent_roles_help'] = 'The agent only runs while its creator holds at least one of the checked roles in the context of '
    . 'the agent. Leave every box unchecked to run it always.';
$string['agent_run_disabled'] = 'This agent is disabled, so it was not queued. Enable it to run it.';
$string['agent_run_now'] = 'Run now';
$string['agent_run_queued'] = 'Agent queued for execution (next cron cycle).';
$string['agent_runner_task'] = 'Jedomi — Execute scheduled AI agents';
$string['agent_schedule'] = 'Schedule';
$string['agent_skipped_course_excluded'] = 'Not run: the course of its context is excluded from Jedomi. It runs again when the '
    . 'course is back in Jedomi.';
$string['agent_skipped_role'] = 'Not run: the creator holds none of the roles chosen for the agent in its context.';
$string['agent_status'] = 'Status';
$string['agent_summary'] = 'Summary';
$string['agent_table'] = 'All agents';
$string['agent_unnamed'] = 'Agent';
$string['agent_update_success'] = 'Agent updated successfully.';
$string['agent_user_id'] = 'User #{$a}';
$string['agent_view_details'] = 'View details';
$string['agent_weekly'] = 'Weekly';
$string['agent_write_cap_recipients'] = 'The run was stopped: its approved writes reached the limit of {$a} different recipients per run.';
$string['agent_write_cap_writes'] = 'The run was stopped: it reached the limit of {$a} approved writes per run.';
$string['agent_writes_blocked_heading'] = 'Writes rejected because they were outside the scope of the agent: {$a}';
$string['agent_writes_done_heading'] = 'Approved writes run without confirmation: {$a}';
$string['agent_writes_failed'] = 'failed, {$a} recipients';
$string['agent_writes_held_cap'] = 'The run proposed more writes than the limit of {$a} writes waiting for approval per run: the extra writes were neither held nor made. This does not count as a failed run.';
$string['agent_writes_held_heading'] = 'Writes waiting for your approval: {$a}';
$string['agent_writes_held_help'] = 'Open the Jedomi assistant to approve or reject them. They expire after 7 days.';
$string['agent_writes_ok'] = 'done, {$a} recipients';
$string['agent_writes_open_chat'] = 'Open the Jedomi assistant';
$string['agent_writes_subject'] = 'Jedomi agent "{$a}": writes of the last run';
$string['agents_menu_link'] = 'Jedomi agents';
$string['ai_disclaimer'] = '{$a} is an AI and can make mistakes.';
$string['ai_error_connection'] = 'Could not reach Jedomi. Check your connection and try again.';
$string['ai_error_generic'] = 'Jedomi could not answer this time. Please try again in a moment.';
$string['ai_error_invalidanswer'] = 'The AI service returned an answer that could not be read.';
$string['ai_error_license'] = 'The AI license of this site is missing or not valid. Contact your site administrator.';
$string['ai_error_nocredit'] = 'Your organisation has run out of AI credit. Contact your site administrator to add more.';
$string['ai_error_quota'] = 'The AI service has reached its usage limit. Contact the site administrator.';
$string['ai_error_ratelimit'] = 'You have reached the AI usage limit for now. You can try again after {$a}.';
$string['ai_error_ratelimit_noretry'] = 'You have reached the AI usage limit for now. Please try again later.';
$string['ai_error_timeout'] = 'The assistant took too long to answer. Please try again with a shorter request.';
$string['ai_error_tools_unsupported'] = 'The model of the custom AI service does not support tool calling, which Jedomi needs to answer. Ask the site administrator to choose a model that supports it.';
$string['ai_error_unavailable'] = 'The AI service is not available right now. Please try again in a few minutes.';
$string['ai_heading'] = 'AI service';
$string['ai_heading_help'] = 'Jedomi sends its AI requests through the Datacurso AI provider, using its license key and usage limits.';
$string['ai_service'] = 'AI service';
$string['ai_service_help'] = 'Datacurso service: Jedomi uses the Datacurso AI service with the models below, with the credits and limits of the Datacurso licence.<br>Custom service: Jedomi calls an OpenAI-compatible service of your choice directly (DeepInfra, OpenAI, a local model such as Ollama, LM Studio or vLLM). The calls use no Datacurso credits and are not recorded in the consumption history. Images are read by the image model. PDF and text files (.txt, .csv, .md) are read only with "Documents" on and a document service, which turns the PDF pages into images for the image model; Word and PowerPoint files cannot be read. Personal data is anonymised as with the Datacurso service.<br>To switch Jedomi off, untick "Enabled".';
$string['ai_timeout'] = 'AI request timeout (seconds)';
$string['ai_timeout_help'] = 'How long Jedomi waits for each answer of the AI service in the web chat, for the Datacurso service and the custom service, from 10 to 600 seconds (default: 180). A question of the web chat stops after three times this value in total (9 minutes by default), with a message for the user. Telegram and the scheduled agents keep their own timeouts. While Jedomi waits for the AI, the web chat sends a signal of life every 15 seconds, so a proxy that closes idle requests (Cloudflare closes them after 100 seconds) keeps the connection; the max_execution_time of PHP or the timeout of the web server can still end the request earlier: raise those too, or keep this value below them.';
$string['analyze_file'] = 'Summarize this file.';
$string['analyze_image'] = 'Analyze this image.';
$string['anon_basic'] = 'Basic (names, emails and identifiers)';
$string['anon_basic_desc'] = 'Replaces names, emails and identifiers (user name, ID number, phones, address, IP address, messaging ids, web page URL). Keeps most context intact.';
$string['anon_feedback_only'] = 'Feedback only (no conversation text stored)';
$string['anon_feedback_only_desc'] = 'Stores only ratings and feedback buttons. No conversation text.';
$string['anon_strict'] = 'Strict (names, emails, identifiers, free text and codes)';
$string['anon_strict_desc'] = 'Replaces what Basic does (names, emails and identifiers), plus long free text and codes mixing letters and digits inside texts. Maximum privacy.';
$string['anonymization_level'] = 'Anonymization level';
$string['anonymization_level_help'] = 'Personal data is replaced by placeholders before it reaches the AI service: the messages of the user, the text of attached files, images and audio, the page context, the earlier messages of the conversation and the results of the tools, in the web chat, Telegram, the scheduled agents and the agent preview. The user still sees the real values in the answer.<br>Basic: names of site users, email addresses and identifiers (user name, ID number, phone, address, IP address, messaging ids, web page).<br>Strict: also long free texts and codes mixing letters and digits inside texts.<br>Feedback only: the AI receives the same as with Basic, and the ratings are saved without the text of the conversation.';
$string['answer_stopped'] = 'Answer stopped.';
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
$string['audio_error_too_short'] = 'The audio is too short to be transcribed. Record at least one full sentence.';
$string['audio_error_unreadable'] = 'The audio could not be understood. Try again, speaking closer to the microphone.';
$string['audio_error_unsupported'] = 'This audio format is not supported. Use MP3, WAV, M4A, OGG or WEBM.';
$string['audio_file'] = 'Audio';
$string['audio_model'] = 'Audio model';
$string['audio_model_help'] = 'Model that transcribes the audio. The default one, Whisper large-v3 turbo, is fast; Qwen3-ASR 1.7B writes names and accents best in the tests.';
$string['audio_processing'] = 'Enable audio processing';
$string['cachedef_chat_rate'] = 'Web chat messages per user and minute';
$string['cachedef_companion_signatures'] = 'Signatures already used by the Jedomi companion web services';
$string['cachedef_speech_rate'] = 'Voice conversation requests per user and minute';
$string['cachedef_telegram_updates'] = 'Telegram updates already received, per bot';
$string['cachedef_verify_rate'] = 'Verifications of the custom AI service per administrator and minute';
$string['cancel'] = 'Cancel';
$string['cancelled'] = 'Cancelled.';
$string['chat_error_course_access'] = 'You cannot use Jedomi in this course.';
$string['chat_error_course_mismatch'] = 'The course of the request is not the course of the page. Reload the page and try again.';
$string['chat_error_course_unavailable'] = 'Jedomi is not available in this course.';
$string['chat_error_guest'] = 'Jedomi is only available to users logged in with their own account.';
$string['chat_error_rate_limit_busy'] = 'Your previous request is still being processed. Please try again in a moment.';
$string['chat_error_rate_limited'] = 'You have sent too many questions in a short time. Please wait {$a} seconds before sending another one.';
$string['chat_error_request_too_large'] = 'The message is too large to be sent. Remove some attached files or start a new conversation.';
$string['chat_error_role_hidden'] = 'Jedomi is not available for your role.';
$string['chat_limit_heading'] = 'Web chat usage';
$string['chat_limit_heading_help'] = 'Limits that protect the AI credits and the server from a user who sends too many questions.';
$string['chat_placeholder'] = 'Ask {$a}...';
$string['chat_rate_limit'] = 'Messages per user per minute (web chat)';
$string['chat_rate_limit_help'] = 'Most requests a user can make from the chat panel within one minute, from 0 to 120 (default: 10; 0 means no limit). Each question counts one, and so does each attached file, image or voice message that is read or transcribed, because each one is a separate AI request: a question with two attached files counts three. Requests refused because the user cannot use Jedomi there do not count. It applies with the Datacurso AI service and with the custom AI service. A user over the limit is told how many seconds to wait, and no AI request is made. Site administrators are never limited. Telegram keeps its own limit, and the scheduled agents and the companion web service are not affected.';
$string['chat_rate_limit_invalid'] = 'Enter a whole number from 0 to 120.';
$string['check_webhook'] = 'Check webhook';
$string['cleanup_telegram_sessions'] = 'Jedomi — Clean up expired Telegram sessions';
$string['cli_backup_destination_outside_dataroot'] = 'The backup can only be stored in an existing folder inside the Moodle data directory (dataroot). Give a folder relative to dataroot, or leave the destination empty to keep the backup in the backup files of Moodle.';
$string['close'] = 'Close (Esc)';
$string['component_all'] = 'All components';
$string['config_key_blocked_msg'] = 'Key is blocked for security reasons.';
$string['config_key_not_allowed_msg'] = 'This setting is not in the list of settings Jedomi may change. Change it on its settings page. Settings Jedomi may change: {$a}.';
$string['config_key_secret_msg'] = 'This setting holds a secret (a password, key or token) and cannot be changed from here: change it on its settings page, where it is kept encrypted.';
$string['config_load_error'] = 'The Jedomi panel could not be loaded. Try again, or contact the site administrator if the problem continues.';
$string['config_load_retry'] = 'Try again';
$string['config_updated_msg'] = 'Config \'{$a}\' updated successfully.';
$string['config_value_invalid_msg'] = 'The value is not valid for the setting \'{$a}\'. Nothing was changed.';
$string['confirm_btn_no'] = 'Cancel';
$string['confirm_btn_yes'] = 'Confirm';
$string['confirm_cancelled'] = 'Action cancelled.';
$string['confirm_done'] = 'Action executed successfully.';
$string['confirm_executing'] = 'Executing...';
$string['confirm_keyword_required'] = 'Type the confirmation keyword to run this action.';
$string['confirm_send_bulk'] = 'Send this message to {count} recipient(s)?';
$string['confirm_type_to_proceed'] = 'Type CONFIRM to proceed:';
$string['connection_error'] = 'Connection error:';
$string['conversation_cleared'] = 'Conversation cleared.';
$string['copytoclipboard'] = 'Copy to clipboard';
$string['course_availability_courses'] = 'Selected courses';
$string['course_availability_courses_help'] = 'Courses that are in Jedomi ("Only the selected courses") or outside it '
    . '("All courses except the selected ones"). An excluded course is outside Jedomi, not only without the panel: it gives '
    . 'no teacher role on the site home, in Telegram or in the agents, the teacher tools, web service calls and teacher SQL '
    . 'never return or accept it, and its agents do not run. Site-wide '
    . 'administrator SQL is not limited by this list. Search by course name or short name.';
$string['course_availability_heading'] = 'Course availability';
$string['course_availability_heading_help'] = 'Control which courses are in Jedomi. An excluded course is outside Jedomi in every '
    . 'channel: the web chat, Telegram and the agents.';
$string['course_mode_all'] = 'All courses (default)';
$string['course_mode_all_desc'] = 'Every course is in Jedomi.';
$string['course_mode_exclude'] = 'All courses except the selected ones';
$string['course_mode_exclude_desc'] = 'The courses you choose below are outside Jedomi: no panel, and no teacher role, tools, '
    . 'SQL or agents for them.';
$string['course_mode_include'] = 'Only the selected courses';
$string['course_mode_include_desc'] = 'Every course you do not choose below is outside Jedomi: no panel, and no teacher role, '
    . 'tools, SQL or agents for it. The pages that do not belong to a course (Dashboard, profile, calendar, site '
    . 'administration) keep the panel.';
$string['course_none_selected'] = 'No courses selected yet.';
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
$string['custom_allow_http'] = 'Allow HTTP for local models';
$string['custom_allow_http_help'] = 'Allows plain HTTP, and skips the blocked hosts and allowed ports of the site, only for a model on this server or the local network (localhost, 127.0.0.1, 10.x, 172.16-31.x, 192.168.x), such as Ollama, LM Studio or vLLM. Any other address needs HTTPS. Off by default.';
$string['custom_api_key'] = 'API key';
$string['custom_api_key_help'] = 'Key of the service, sent as a Bearer token. It is never shown again once saved: leave the field empty to keep the saved key. Local models usually need none. The saved key is only sent to the saved address; if you change the address, enter the key again.';
$string['custom_api_key_placeholder'] = 'Enter the API key (optional for local models)';
$string['custom_api_key_saved'] = 'A key is saved. Leave the field empty to keep it.';
$string['custom_api_key_unreadable'] = 'The saved API key could not be decrypted: the encryption key of the site changed. Enter the API key again.';
$string['custom_audio_enabled'] = 'Enable audio processing';
$string['custom_audio_enabled_help'] = 'Lets users dictate with the microphone and attach audio files in the web chat, and send voice and audio messages in Telegram. The audio is transcribed by the audio model of the custom service, which is required while this is on.';
$string['custom_audio_model'] = 'Audio model (custom service)';
$string['custom_audio_model_help'] = 'Id of the model that transcribes audio, as the service lists it (for example openai/whisper-large-v3).';
$string['custom_audio_processing'] = 'Audio processing (custom service)';
$string['custom_context_window'] = 'Context window (tokens)';
$string['custom_context_window_detected'] = '{$a} (auto)';
$string['custom_context_window_help'] = 'Tokens the chat model accepts in one request, question and answer together. Verify connection reads it from the service when it tells it (max_model_len of vLLM, context_length or context_window) and shows it as the automatic value; a value typed here overrides it. Before each request of the web chat, Jedomi estimates the tokens (about four characters each), keeps the max tokens free for the answer, and drops the oldest messages of the conversation that do not fit; the instructions and the latest question always stay. Leave it empty when nothing is detected to never drop messages.';
$string['custom_context_window_unknown'] = 'No context window is known for the chat model, so the conversation is never trimmed and a long one may be refused by the model. Enter the context window, or verify the connection if the service tells it.';
$string['custom_docservice_url'] = 'Document service URL';
$string['custom_docservice_url_help'] = 'Address of a service that turns PDF files into page images (POST /api/v1/pdf/to-images), such as https://docs.example.com. The pages are then read by the image model, so image processing must be on. It follows the same rules as the service URL: HTTPS, or HTTP only for a local service with "Allow HTTP for local models"; no user, password, query or fragment. Text files (.txt, .csv, .md) are read on the site, but they also need "Documents" on and this address; Word and PowerPoint files cannot be read with the custom service. A whole PDF is read within the AI request timeout: the pages left when it runs out are skipped, so on a site behind a proxy (Cloudflare closes requests after 100 seconds) ask for fewer pages of long documents.';
$string['custom_documents_enabled'] = 'Enable documents';
$string['custom_documents_enabled_help'] = 'Lets users attach PDF and text files (.txt, .csv, .md) in the web chat and send PDF files in Telegram, and lets the AI read the PDF files of course activities. PDF files are sent to the document service, which turns their pages into images (at most 20 pages, the ones asked first), and the image model reads them one by one within the AI request timeout; a page that fails is skipped and the others are kept. Text files are read on the site. Needs image processing and the document service URL.';
$string['custom_documents_processing'] = 'Documents (custom service)';
$string['custom_error_http'] = 'The custom AI service answered with HTTP status {$a}.';
$string['custom_error_unreachable'] = 'The custom AI service did not answer.';
$string['custom_error_url'] = 'The address of the custom AI service is not allowed ({$a}).';
$string['custom_files_enabled'] = 'Enable image processing';
$string['custom_files_enabled_help'] = 'Lets users attach images in the web chat and send photos in Telegram. The images are read by the image model of the custom service, which is required while this is on.';
$string['custom_files_processing'] = 'Image processing (custom service)';
$string['custom_gateway_url'] = 'Service URL';
$string['custom_gateway_url_help'] = 'Enter the base URL of the OpenAI-compatible API exactly as the provider documents it (for example https://api.openai.com/v1, https://api.deepinfra.com/v1/openai or http://localhost:11434 for Ollama). Without a path, /v1 is added. HTTPS is required, except for local models with "Allow HTTP for local models".';
$string['custom_max_tokens'] = 'Max tokens (custom service)';
$string['custom_max_tokens_help'] = 'Maximum output tokens per response (default: 16384). With a context window, it must be smaller than the window: it is kept free for the answer.';
$string['custom_model'] = 'Chat model (custom service)';
$string['custom_model_help'] = 'Id of the chat model, as the service lists it (for example meta-llama/Llama-3.3-70B-Instruct). It must support tool calling, which Jedomi uses to read the data of Moodle.';
$string['custom_speech_model'] = 'Voice model (custom service)';
$string['custom_speech_model_help'] = 'Id of the model that reads the answers aloud. The voice conversation also needs audio processing.';
$string['custom_speech_voice'] = 'Voice (custom service)';
$string['custom_speech_voice_help'] = 'Voice of the voice model, as the service names it (for example alloy).';
$string['custom_verify'] = 'Verify connection';
$string['custom_verify_blocked_host'] = 'This address is blocked by the HTTP security settings of the site (cURL blocked hosts and allowed ports).';
$string['custom_verify_checking'] = 'Verifying the connection...';
$string['custom_verify_http_error'] = 'The service answered with HTTP status {$a}.';
$string['custom_verify_http_not_allowed'] = 'Plain HTTP is only allowed for a local model with "Allow HTTP for local models" on. Use HTTPS.';
$string['custom_verify_invalid_url'] = 'Enter a valid http or https address, without a user, password, query or fragment.';
$string['custom_verify_key_required'] = 'Enter the API key again: the saved key is only sent to the saved address.';
$string['custom_verify_models'] = 'Models of the service:';
$string['custom_verify_models_shown'] = 'Showing {$a->shown} of {$a->total} models.';
$string['custom_verify_no_response'] = 'The service did not answer. Check the address and that the service is running.';
$string['custom_verify_not_openai'] = 'The service answered, but not as an OpenAI-compatible API: GET /models must return a list of models.';
$string['custom_verify_ok'] = 'Connection verified: the service lists {$a} models. Choose or enter the ids of the models below.';
$string['custom_verify_rate_limited'] = 'Too many verifications. Wait a minute and try again.';
$string['custom_verify_unauthorized'] = 'The service refused the API key (HTTP 401 or 403).';
$string['custom_verify_warning_model_failed'] = 'The chat model {$a} did not answer a test request. Check its id: the chat fails while the model does not answer.';
$string['custom_verify_warning_no_tools'] = 'The chat model {$a} cannot call tools on this service, and Jedomi needs them to read Moodle data. Choose a model with tool calling (on vLLM, start the server with --enable-auto-tool-choice and a tool call parser).';
$string['custom_verify_warning_no_window'] = 'No context window is known for this chat model: the service does not tell it. Enter it in "Context window (tokens)"; until then the conversation is never trimmed, and a long one may be refused by the model.';
$string['custom_vision_model'] = 'Image model (custom service)';
$string['custom_vision_model_help'] = 'Id of the model that reads images and the pages of PDF files, as the service lists it (for example Qwen/Qwen2.5-VL-7B-Instruct).';
$string['custom_voice_conversation'] = 'Voice conversation (custom service)';
$string['custom_voice_enabled'] = 'Enable voice conversation';
$string['custom_voice_enabled_help'] = 'Adds a voice conversation button to the web chat: the user speaks, Jedomi transcribes it with the audio model, answers and reads the answer aloud with the voice model and voice of the custom service. Needs audio processing.';
$string['dblabel_mysql'] = 'MySQL/MariaDB';
$string['dblabel_pgsql'] = 'PostgreSQL';
$string['decryption_failed'] = 'Decryption failed. The token may have been corrupted or the encryption key of the site changed.';
$string['docservice_error_http'] = 'The document service answered with HTTP status {$a}.';
$string['docservice_error_invalid'] = 'The document service did not answer with page images.';
$string['docservice_error_too_large'] = 'The answer of the document service is too large.';
$string['docservice_error_unreachable'] = 'The document service did not answer.';
$string['docservice_error_url'] = 'The address of the document service is not allowed ({$a}).';
$string['document_page'] = 'Page {$a}:';
$string['documents_error_custom'] = 'Documents (PDF, Word, PowerPoint and text files) cannot be read with the custom AI service of this site.';
$string['documents_error_custom_office'] = 'Word and PowerPoint files cannot be read with the custom AI service of this site. Save the file as PDF and attach it again.';
$string['documents_error_deadline'] = 'The document took too long: the time limit ran out before any page was read. Ask for fewer pages (for example 1-3), or ask the administrator to raise the AI request timeout.';
$string['documents_error_pages'] = 'The pages asked are not in the document, which has {$a} pages.';
$string['documents_error_service'] = 'The document could not be read: the document service did not convert it. Try again later.';
$string['drop_files_here'] = 'Drop files here';
$string['drop_pending_fail'] = '❌ Failed to drop pending updates — check the Telegram API response.';
$string['drop_pending_running'] = 'Dropping...';
$string['drop_pending_success'] = '✅ Pending updates dropped successfully.';
$string['drop_pending_updates'] = 'Drop pending updates';
$string['drop_pending_updates_help'] = 'Clears the Telegram update queue so stale messages are not processed when the webhook '
    . 'reconnects. Useful during testing.';
$string['encryption_failed'] = 'Encryption failed. Check that the encryption key of the site can be read in dataroot.';
$string['entity_agent'] = 'Agent';
$string['entity_query_log'] = 'Profiled query';
$string['error_argument_unplaced'] = 'This call names a record that Jedomi cannot place in a course ({$a}) while some '
    . 'courses are outside Jedomi, so it was not run. Ask an administrator, or use a more specific tool or arguments that '
    . 'name the course.';
$string['error_course_excluded'] = 'That course is excluded from Jedomi.';
$string['error_course_include_empty'] = 'Select at least one course, or choose another mode: with no course selected, every course would be outside Jedomi.';
$string['error_custom_model_invalid'] = 'Enter a model id without spaces, as the service lists it.';
$string['error_custom_model_required'] = 'Enter the id of the chat model.';
$string['error_custom_model_required_switch'] = 'Enter the id of the model, or turn its switch off.';
$string['error_custom_not_verified'] = 'Verify the connection with this address and key before saving.';
$string['error_custom_voice_required'] = 'Enter the voice of the voice model.';
$string['error_docservice_url_required'] = 'Enter the address of the document service, or turn documents off.';
$string['error_documents_require_vision'] = 'Documents need image processing, which reads their pages: enable it too.';
$string['error_empty_answer'] = 'The AI did not write an answer, also after being asked again. Try a shorter or more specific question.';
$string['error_fallback_iterations'] = 'Sorry, I could not complete the operation after several attempts.';
$string['error_generic_reference'] = 'Something went wrong. If it happens again, give the administrator this reference: {$a}.';
$string['error_invalid_ai_timeout'] = 'Enter a whole number of seconds from 10 to 600.';
$string['error_invalid_context_window'] = 'Enter a whole number of tokens from 1024 to 10000000, or leave it empty.';
$string['error_invalid_hash'] = 'Invalid cache key.';
$string['error_invalid_max_tokens'] = 'Enter a whole number of tokens.';
$string['error_invalid_model'] = 'Select one of the listed models.';
$string['error_invalid_voice'] = 'Select one of the voices of the chosen model.';
$string['error_max_tokens_exceed_context'] = 'The max tokens must be smaller than the context window ({$a} tokens), which also holds the question.';
$string['error_not_own_course'] = 'That course is not one of your courses.';
$string['error_own_data_arguments'] = 'Unsupported argument(s): {$a}. These tools only read the data of the current user.';
$string['error_post_required'] = 'This request must be sent with POST.';
$string['error_question_time_limit'] = 'Jedomi stopped working on this question after {$a} minutes. Try a shorter or more specific question.';
$string['error_search_tables_failed'] = 'search_tables failed: {$a}';
$string['error_telegram_e006'] = '❌ Invalid response from Jedomi service (E006). Please try again.';
$string['error_telegram_e007'] = '❌ Invalid webhook secret (E007). Contact your administrator.';
$string['error_tool_loop_detected'] = 'Repeated identical tool call blocked. Use a different tool or execute with new parameters.';
$string['error_tool_repeated'] = 'Jedomi stopped because the tool {$a} failed several times with the same error. Try rephrasing the question or asking for something more specific.';
$string['error_tool_unexpected'] = 'The tool stopped with an unexpected error ({$a}).';
$string['error_unknown_action'] = 'Unknown action: {$a}';
$string['error_unknown_tool'] = 'Unknown tool: {$a}';
$string['error_value_range'] = 'Enter a value between {$a->min} and {$a->max}.';
$string['error_voice_requires_audio'] = 'The voice conversation needs audio processing: enable it too.';
$string['error_ws_capability'] = 'You do not have any of the capabilities that the web service function {$a} requires.';
$string['error_ws_describe_failed'] = 'ws_describe failed: {$a}';
$string['error_ws_execute_failed'] = 'ws_execute failed: {$a}';
$string['error_ws_restricted'] = 'While some courses are outside Jedomi, only a reduced set of web service functions is '
    . 'available. {$a} is not one of them.';
$string['error_ws_search_failed'] = 'ws_search failed: {$a}';
$string['event_action_cancelled'] = 'Assistant action cancelled';
$string['event_action_confirmed'] = 'Assistant action confirmed';
$string['event_agent_created'] = 'Agent created';
$string['event_agent_deleted'] = 'Agent deleted';
$string['event_agent_run'] = 'Agent run';
$string['event_agent_updated'] = 'Agent updated';
$string['event_chat_answered'] = 'Assistant answer sent';
$string['event_settings_updated'] = 'Jedomi settings updated';
$string['event_telegram_linked'] = 'Telegram account linked';
$string['event_telegram_unlinked'] = 'Telegram account unlinked';
$string['execute_sql_error'] = 'Database error executing query.';
$string['export_conversation'] = 'Export conversation';
$string['export_csv_filename'] = 'jedomi_data';
$string['export_filename'] = 'jedomi-conversation';
$string['export_role_assistant'] = 'Jedomi';
$string['export_role_user'] = 'User';
$string['export_title'] = 'Jedomi conversation';
$string['feedback_comment'] = 'What could be improved? (optional)';
$string['feedback_comment_saved'] = 'Thank you for your comment.';
$string['feedback_comment_send'] = 'Send comment';
$string['file'] = 'File';
$string['file_attach_limit'] = 'Up to {$a} files can be attached to one message; the other files were not attached.';
$string['file_audio'] = 'Audio: {seconds} seconds';
$string['file_csv'] = 'CSV: {rows} rows, {columns} columns';
$string['file_error_no_text'] = 'No text could be read from this file.';
$string['file_error_too_large'] = 'The file is too large to be read.';
$string['file_error_unsupported'] = 'Only PDF, CSV, TXT, MD, Word (DOC, DOCX) and PowerPoint (PPT, PPTX) files, '
    . 'and audio files when audio processing is enabled, can be attached.';
$string['file_error_upload_too_large'] = 'The file is larger than {$a} MB, the largest file that can be attached.';
$string['file_pdf'] = 'PDF: {pages} pages';
$string['file_process_error'] = 'Could not process';
$string['file_remove'] = 'Remove {$a}';
$string['file_text'] = 'Text: {chars} characters';
$string['file_truncated'] = 'The text of {$a} was too long: only its first part was sent.';
$string['files_enabled'] = 'Enable file and image processing';
$string['files_enabled_help'] = 'Lets users attach images and files in the web chat, send photos and documents in Telegram, and lets the AI read the PDF files of course activities. Images and scanned PDF pages are read by the file and image model, which consumes credits.';
$string['files_error_disabled'] = 'File and image processing is not enabled on this site.';
$string['files_processing'] = 'Enable file and image processing';
$string['filter_type'] = 'Type:';
$string['full_width'] = 'Full width';
$string['half_width'] = 'Half width';
$string['hint_sql_braces'] = ' | HINT: In Moodle SQL, table names should use braces (e.g. {course}, {user}).';
$string['image'] = 'Image';
$string['image_attach_limit'] = 'Only one image can be attached to a message; the other images were not attached.';
$string['image_error_no_text'] = 'No content could be read from this file.';
$string['image_error_unsupported'] = 'Only PDF, JPG, PNG and WEBP files can be read.';
$string['image_not_kept'] = 'Attached image (not kept after reloading the page)';
$string['invalid_session'] = 'Your session has changed. Reload the page to keep using Jedomi.';
$string['invalid_signature'] = 'The chat permissions are not valid. Reload the page to keep using Jedomi.';
$string['jedomi:companion_api'] = 'Use the Jedomi companion service functions';
$string['jedomi:manageagents'] = 'Manage Jedomi agents';
$string['keyboard_shortcuts'] = 'Keyboard shortcuts: Alt+Shift+J opens or closes Jedomi, Alt+Shift+N starts a new conversation and Escape closes the panel.';
$string['link_external'] = 'External site, opens in a new window';
$string['link_type_admin'] = 'Administration';
$string['link_type_assign'] = 'Assignment';
$string['link_type_course'] = 'Course';
$string['link_type_grades'] = 'Grades';
$string['link_type_messages'] = 'Messages';
$string['link_type_moodle'] = 'Moodle';
$string['link_type_profile'] = 'Profile';
$string['link_type_quiz'] = 'Quiz';
$string['listening'] = 'Listening...';
$string['max_tokens'] = 'Max tokens';
$string['max_tokens_help'] = 'Maximum output tokens per response (default: 16384). Increase for longer responses, decrease for '
    . 'speed & cost.';
$string['message_input'] = 'Message Jedomi';
$string['messageprovider:agentwrites'] = 'Writes of your Jedomi scheduled agents';
$string['messageprovider:telegramwebhook'] = 'Jedomi Telegram webhook registration failures';
$string['messages_recipients_note'] = 'Recipients will see them in their Moodle message inbox.';
$string['messages_sent'] = '{sent} message(s) sent as Moodle internal messages.';
$string['messages_sent_fail'] = '({failed} failed)';
$string['mic_error'] = 'Could not access the microphone. Please check your browser permissions.';
$string['model'] = 'Chat model';
$string['model_help'] = 'Model that answers in the web chat, scheduled agents, agent preview, Telegram and the CLI. Only models with a registered price are listed.';
$string['new_conversation'] = 'New conversation';
$string['new_conversation_confirm'] = 'Start a new conversation? The current conversation will be cleared.';
$string['new_conversation_msg'] = 'New conversation started. How can I help you?';
$string['no_messages_sent'] = 'No messages sent.';
$string['non_select_rejected'] = 'Only SELECT queries are allowed.';
$string['note_tables_found'] = 'Use the exact "name" with describe_table or execute_sql. Table names shown as provided — use '
    . '{tablename} in SQL queries.';
$string['note_tables_not_found'] = 'No matching tables found. Try different keywords.';
$string['note_ws_found'] = 'Use the exact "name" field with ws_execute. Check "params" for required(*) parameters.';
$string['note_ws_none_configured'] = 'No web services configured for your role';
$string['note_ws_not_found'] = 'No matching functions found. Try different keywords.';
$string['open_sidebar'] = 'Open {$a}';
$string['pending_action_denied'] = 'The action was not run: you do not have permission to do it.';
$string['pending_action_expired'] = 'This action has expired. Ask Jedomi again.';
$string['pending_action_failed'] = 'The action could not be completed.';
$string['pending_action_needed'] = 'This action needs your confirmation.';
$string['pending_action_not_creator'] = 'Only the current creator of the agent that proposed this action can approve it. You can still cancel it.';
$string['pending_action_not_found'] = 'This action does not exist or was already resolved.';
$string['pending_action_others_not_run'] = '{$a} more action(s) proposed in this answer were not run. Ask for them again once this one is '
    . 'confirmed or cancelled.';
$string['pending_action_too_long'] = 'This action has more data than can be shown, so it cannot be confirmed. You can cancel it.';
$string['pending_action_truncated'] = 'This action has more data than can be shown, so it can only be cancelled.';
$string['pending_desc_admin_execute'] = 'Run the administration operation "{$a}".';
$string['pending_desc_create_agent'] = 'Create the scheduled agent "{$a}".';
$string['pending_desc_delete_agent'] = 'Delete the scheduled agent #{$a}.';
$string['pending_desc_plugin_tool'] = 'Run the action "{$a}" of another plugin, which changes data.';
$string['pending_desc_send_messages'] = 'Send a Moodle message to {$a} recipient(s).';
$string['pending_desc_ws_execute'] = 'Run the web service function "{$a}".';
$string['pending_from_agent'] = 'Agent "{$a->agent}": {$a->description}';
$string['personalization_assistant_name'] = 'Assistant name';
$string['personalization_assistant_name_help'] = 'Name shown on the button, in the panel and in the welcome message, and given to the AI as its own name in the web chat, the voice conversation and Telegram. Up to 50 characters; empty uses Jedomi.';
$string['personalization_avatar'] = 'Avatar';
$string['personalization_avatar_default'] = 'Jedomi icon';
$string['personalization_avatar_help'] = 'Image shown on the floating button and in the header of the panel. The Jedomi icon is the one the panel had so far. A custom avatar, when uploaded, is shown instead.';
$string['personalization_avatar_option'] = 'Avatar {$a}';
$string['personalization_corner'] = 'Button corner';
$string['personalization_corner_bottom_left'] = 'Bottom left';
$string['personalization_corner_bottom_right'] = 'Bottom right';
$string['personalization_corner_help'] = 'Corner of the page where the floating button is shown, and its distance in pixels from the two edges of that corner (0 to 500). On screens up to 767 px wide the button always stays in the bottom corner, so it does not cover the page.';
$string['personalization_corner_top_left'] = 'Top left';
$string['personalization_corner_top_right'] = 'Top right';
$string['personalization_custom_prompt'] = 'Site instructions for the AI';
$string['personalization_custom_prompt_help'] = 'Instructions added to the end of the instructions of the AI, for example a tone or the name of the institution, in the web chat, the voice conversation, Telegram and the agents. They have a lower priority than the rules of Jedomi: they cannot change the security, permission, privacy, language or confirmation rules. Up to 2000 characters.';
$string['personalization_custom_prompt_toolong'] = 'The site instructions can have at most {$a} characters.';
$string['personalization_customavatar'] = 'Custom avatar';
$string['personalization_customavatar_help'] = 'One image (JPG, PNG, GIF, SVG or WebP) of up to 512 KB, preferably square. It replaces the selected avatar while it is uploaded; remove it to use the selected avatar again.';
$string['personalization_heading_assistant'] = 'Assistant';
$string['personalization_heading_assistant_help'] = 'Name, welcome message and site instructions the assistant uses.';
$string['personalization_heading_avatar'] = 'Avatar';
$string['personalization_heading_avatar_help'] = 'Image that represents the assistant on the button and in the chat.';
$string['personalization_heading_position'] = 'Button position';
$string['personalization_heading_position_help'] = 'Where the button that opens the Jedomi sidebar is placed on the page.';
$string['personalization_offset_invalid'] = 'Enter a whole number of pixels between 0 and {$a}.';
$string['personalization_offset_x'] = 'Horizontal distance (px)';
$string['personalization_offset_y'] = 'Vertical distance (px)';
$string['personalization_preview'] = 'Preview';
$string['personalization_preview_help'] = 'Drag the button in the preview, or focus it and use the arrow keys (Shift for steps of 10 px), to change its distances.';
$string['personalization_side'] = 'Panel opens from';
$string['personalization_side_left'] = 'Left side';
$string['personalization_side_right'] = 'Right side';
$string['personalization_welcome_message'] = 'Welcome message';
$string['personalization_welcome_message_help'] = 'Text shown at the start of a new conversation in the panel. You can use {assistantname}, {firstname}, {username} (full name of the user) and {coursename} (empty outside a course). Up to 1000 characters; empty uses the default message in the language of each user.';
$string['personalization_welcome_message_toolong'] = 'The welcome message can have at most {$a} characters.';
$string['pluginname'] = 'Jedomi AI Sidebar';
$string['preview_detail_confirmation'] = 'The preview does not run actions that change data; they need the confirmation of the user.';
$string['preview_detail_loop'] = 'The same tool was called {$a} times with the same arguments.';
$string['preview_error_fetch_failed'] = 'The agent could not get the data it needed. Try again later.';
$string['preview_error_no_answer'] = 'The AI returned no answer for this instruction. Try again or reword the instruction.';
$string['privacy:metadata:jedomi_ai_service'] = 'Conversations are sent through the Datacurso AI provider to its AI service to generate the answers.';
$string['privacy:metadata:jedomi_ai_service:lang'] = 'The language of the user.';
$string['privacy:metadata:jedomi_ai_service:messages'] = 'The conversation messages and the results of the tools used to answer them.';
$string['privacy:metadata:jedomi_ai_service:site_id'] = 'An identifier of the site, used by the AI service to keep the requests of each site apart.';
$string['privacy:metadata:jedomi_ai_service:site_url'] = 'The address of the site.';
$string['privacy:metadata:jedomi_ai_service:userid'] = 'A pseudonymous identifier of the user, derived by the Datacurso AI provider.';
$string['privacy:metadata:jedomi_custom_ai_service'] = 'When the site administrator chooses a custom AI service, the conversations, the answers read aloud, and the images and audio attached in the web chat or sent in Telegram are sent directly to that service, outside Datacurso, to generate the answers. No user or site identifier is sent.';
$string['privacy:metadata:jedomi_custom_ai_service:filename'] = 'The name of the audio file or recording to transcribe.';
$string['privacy:metadata:jedomi_custom_ai_service:files'] = 'The images and audio recordings attached to the chat or sent in Telegram, and the page images of the PDF files read when documents are on, to be read or transcribed. They are sent as they are, not anonymised, so they can include personal data shown or spoken in them.';
$string['privacy:metadata:jedomi_custom_ai_service:language'] = 'The language of the user, given to the audio model to transcribe the recording.';
$string['privacy:metadata:jedomi_custom_ai_service:messages'] = 'The conversation messages and the results of the tools used to answer them, anonymised with the level of the Privacy tab of Jedomi.';
$string['privacy:metadata:jedomi_custom_ai_service:mimetype'] = 'The type of the image, such as image/png.';
$string['privacy:metadata:jedomi_custom_ai_service:question'] = 'The question the user asks about an image or a file, or the caption of a Telegram photo, sent with it, with the personal data replaced by placeholders (anonymised with the level of the Privacy tab of Jedomi). The image or file itself is still sent as it is.';
$string['privacy:metadata:jedomi_custom_ai_service:text'] = 'The text of the answer read aloud in the voice conversation, anonymised on the site before it is sent.';
$string['privacy:metadata:jedomi_custom_document_service'] = 'When the site administrator chooses a custom AI service and turns its documents on, the PDF files attached to the chat, sent in Telegram or read from course activities are sent to the document service set by the administrator, outside Datacurso, to be turned into page images. No user or site identifier is sent.';
$string['privacy:metadata:jedomi_custom_document_service:filename'] = 'The name of the PDF file.';
$string['privacy:metadata:jedomi_custom_document_service:files'] = 'The PDF files to turn into page images, sent as they are, not anonymised, so they can include data of the users.';
$string['privacy:metadata:jedomi_file_service'] = 'With the Datacurso service, the files a user attaches in the web chat or sends in Telegram (documents, PDF files, image files and audio files or recordings) and the files of course activities read for the user are sent through the Datacurso AI provider to its AI service, to read their text, describe an image or transcribe the audio.';
$string['privacy:metadata:jedomi_file_service:file'] = 'The content of the file, image or audio recording. It is sent as it is, not anonymised, so it can include personal data shown or spoken in it.';
$string['privacy:metadata:jedomi_file_service:filename'] = 'The name of the file.';
$string['privacy:metadata:jedomi_file_service:lang'] = 'The language of the user.';
$string['privacy:metadata:jedomi_file_service:language'] = 'The language of the user, given to the audio model to transcribe the recording.';
$string['privacy:metadata:jedomi_file_service:mimetype'] = 'The type of the file, such as application/pdf or image/png.';
$string['privacy:metadata:jedomi_file_service:question'] = 'The question the user asks about an image or a file, or the caption of a Telegram photo, sent with it, with the personal data replaced by placeholders (anonymised with the level of the Privacy tab of Jedomi). The image or file itself is still sent as it is.';
$string['privacy:metadata:jedomi_file_service:site_id'] = 'An identifier of the site, used by the AI service to keep the requests of each site apart.';
$string['privacy:metadata:jedomi_file_service:site_url'] = 'The address of the site.';
$string['privacy:metadata:jedomi_file_service:userid'] = 'A pseudonymous identifier of the user, derived by the Datacurso AI provider.';
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
$string['privacy:metadata:local_jedomi_agents:lasterror'] = 'The reason of the last failed run of the agent.';
$string['privacy:metadata:local_jedomi_agents:lastresult'] = 'The answer of the last run of the agent.';
$string['privacy:metadata:local_jedomi_agents:name'] = 'The name of the agent.';
$string['privacy:metadata:local_jedomi_agents:prompt'] = 'The request the agent runs.';
$string['privacy:metadata:local_jedomi_agents:runwrites'] = 'The writes the current run of the agent already attempted: a fingerprint of each one, whether it succeeded and the ids of the users it was addressed to. They are kept until a run succeeds.';
$string['privacy:metadata:local_jedomi_agents:schedule_cron'] = 'When the agent runs.';
$string['privacy:metadata:local_jedomi_agents:timecreated'] = 'When the agent was created.';
$string['privacy:metadata:local_jedomi_agents:userid'] = 'The user who owns the agent.';
$string['privacy:metadata:local_jedomi_feedback'] = 'Ratings users give to Jedomi answers.';
$string['privacy:metadata:local_jedomi_feedback:feedbackcomment'] = 'The optional comment given with an answer rated as in need of improvement.';
$string['privacy:metadata:local_jedomi_feedback:messageid'] = 'The id the browser gives to the rated answer, so each answer keeps a single rating.';
$string['privacy:metadata:local_jedomi_feedback:messagetext'] = 'An excerpt of the rated answer.';
$string['privacy:metadata:local_jedomi_feedback:rating'] = 'Whether the answer was rated helpful or in need of improvement.';
$string['privacy:metadata:local_jedomi_feedback:sessionid'] = 'The chat session of the rated answer.';
$string['privacy:metadata:local_jedomi_feedback:timecreated'] = 'When the rating was given.';
$string['privacy:metadata:local_jedomi_feedback:timemodified'] = 'When the rating or its comment last changed.';
$string['privacy:metadata:local_jedomi_feedback:userid'] = 'The user who rated the answer.';
$string['privacy:metadata:local_jedomi_pending_actions'] = 'Actions of the web chat that wait for the user\'s confirmation for up to 15 minutes.';
$string['privacy:metadata:local_jedomi_pending_actions:argsjson'] = 'Arguments of the action, such as the message to send and its recipients.';
$string['privacy:metadata:local_jedomi_pending_actions:severity'] = 'How careful the confirmation must be.';
$string['privacy:metadata:local_jedomi_pending_actions:timecreated'] = 'When the action was requested.';
$string['privacy:metadata:local_jedomi_pending_actions:tool'] = 'Tool the action runs.';
$string['privacy:metadata:local_jedomi_pending_actions:userid'] = 'User who must confirm the action.';
$string['privacy:metadata:local_jedomi_query_log'] = 'Database queries run by the agents, their previews and the chat channels, kept for the query profiler.';
$string['privacy:metadata:local_jedomi_query_log:agentid'] = 'The agent that ran the query.';
$string['privacy:metadata:local_jedomi_query_log:channel'] = 'Where the query ran: a scheduled agent, an agent preview, the web chat, Telegram or the companion web service.';
$string['privacy:metadata:local_jedomi_query_log:query'] = 'The text of the query, which can include names or other data of users.';
$string['privacy:metadata:local_jedomi_query_log:timecreated'] = 'When the query ran.';
$string['privacy:metadata:local_jedomi_query_log:userid'] = 'The user the query ran for.';
$string['privacy:metadata:local_jedomi_telegram_links'] = 'The link between the Moodle account and a Telegram chat.';
$string['privacy:metadata:local_jedomi_telegram_links:chatid'] = 'The Telegram chat linked to the account.';
$string['privacy:metadata:local_jedomi_telegram_links:lastused'] = 'When the linked chat was last used; the link expires 30 days after it.';
$string['privacy:metadata:local_jedomi_telegram_links:pinfailures'] = 'Wrong Telegram PINs typed in a row.';
$string['privacy:metadata:local_jedomi_telegram_links:pinhash'] = 'The hash of the Telegram PIN of an administrator or manager; the PIN itself is never stored.';
$string['privacy:metadata:local_jedomi_telegram_links:pinlockeduntil'] = 'Until when the changes through Telegram are locked after too many wrong PINs.';
$string['privacy:metadata:local_jedomi_telegram_links:pinverifiedat'] = 'When the right Telegram PIN was last typed.';
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
$string['privacy:metadata:technical_log'] = 'The technical log of the plugin, the file local/jedomi/jedomi.log in the data '
    . 'directory of the site, with its operational and security trace. It never keeps user ids, Telegram chat ids or the texts of '
    . 'the conversations: users and chats appear as pseudonyms (a keyed hash with a random key of the site), and the source IP '
    . 'address is kept only for requests rejected for security reasons. The file is limited by size: it rotates when it reaches 5 '
    . 'MB and keeps the five previous files (about 30 MB in total), so the oldest entries are deleted as new ones are written; an '
    . 'administrator can delete the files at any time. The entries are not exported or deleted per user because they hold no '
    . 'identifier of the user.';
$string['privacy:metadata:technical_log:chat'] = 'A pseudonym of the Telegram chat, never the chat id.';
$string['privacy:metadata:technical_log:ipaddress'] = 'The IP address of the request, only when it was rejected for security '
    . 'reasons.';
$string['privacy:metadata:technical_log:user'] = 'A pseudonym of the user who created an agent or uses the Telegram bot, never the '
    . 'user id.';
$string['privacy:metadata:telegram'] = 'The answers of the bot, the notifications and the results of the agents are delivered to the linked Telegram chat.';
$string['privacy:metadata:telegram:chatid'] = 'The Telegram chat the data is sent to.';
$string['privacy:metadata:telegram:messages'] = 'The answers, notifications and agent results, which can include data of the site.';
$string['privacy_access_heading'] = 'Access control';
$string['privacy_access_heading_help'] = 'Who can see and use the Jedomi sidebar.';
$string['privacy_agents'] = 'Scheduled agents';
$string['privacy_anon_heading'] = 'Data anonymization';
$string['privacy_anon_heading_help'] = 'How personal data is protected before it reaches the AI service.';
$string['privacy_feedback'] = 'Answer ratings';
$string['privacy_pending_actions'] = 'Actions waiting for confirmation';
$string['privacy_queries'] = 'Database queries';
$string['privacy_telegram'] = 'Telegram';
$string['processing_file'] = 'Processing {$a}...';
$string['profiler_heading'] = 'Query profiler';
$string['profiler_heading_help'] = 'When a query counts as slow and how long the query log is kept.';
$string['profiler_retention_days'] = 'Keep query records (days)';
$string['profiler_retention_days_help'] = 'Records of the query profiler older than this number of days are removed once a day by the scheduled task "Purge old query profiler records". Default: 30.';
$string['profiler_retention_days_invalid'] = 'Enter a number of days between 1 and 3650.';
$string['profiler_slow_ms'] = 'Slow query threshold (ms)';
$string['profiler_slow_ms_help'] = 'Queries that take at least this many milliseconds count as slow in the Query profiler tab: the indicator counts them and the list highlights them. Default: 1000. On SQL Server and Oracle the queries of the AI have no time limit, so watch the slow queries there.';
$string['profiler_slow_ms_invalid'] = 'Enter a number of milliseconds between 1 and 600000.';
$string['provider_settings_info'] = 'The Enabled switch, the chat model, the file and image processing, the audio processing, the voice conversation, the voice model, the voice and the max tokens of Jedomi are set on its settings page in the Datacurso AI provider.';
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
$string['query_profiler'] = 'Query profiler';
$string['query_profiler_avg'] = 'Avg duration';
$string['query_profiler_channel'] = 'Channel';
$string['query_profiler_count'] = 'Executions';
$string['query_profiler_desc'] = 'Logs the SQL queries of every channel (agents, agent previews, web chat, Telegram and the '
    . 'companion web service), including execution time and row count. Use the filters below to find slow queries.';
$string['query_profiler_heading'] = 'SQL query profiler';
$string['query_profiler_max'] = 'Max duration';
$string['query_profiler_min_ms'] = 'Minimum duration (ms)';
$string['query_profiler_ms'] = '{$a} ms';
$string['query_profiler_origin'] = 'Origin';
$string['query_profiler_origin_agent'] = 'Agent';
$string['query_profiler_origin_companion'] = 'Companion web service';
$string['query_profiler_origin_preview'] = 'Preview';
$string['query_profiler_origin_preview_agent'] = 'Preview of agent #{$a}';
$string['query_profiler_origin_telegram'] = 'Telegram';
$string['query_profiler_origin_web'] = 'Web chat';
$string['query_profiler_recent'] = 'Recent queries';
$string['query_profiler_rows'] = 'Rows';
$string['query_profiler_slow'] = 'Slow queries (>1s)';
$string['query_profiler_slowest'] = 'Slowest queries (avg)';
$string['query_profiler_sort_duration'] = 'Duration';
$string['query_profiler_sort_query'] = 'Query';
$string['query_profiler_sort_time'] = 'Time';
$string['query_profiler_total'] = 'Total queries';
$string['query_profiler_user'] = 'User';
$string['query_timeout'] = 'Query timed out.';
$string['read_file_blocked'] = 'Access to this file is not allowed.';
$string['read_file_not_allowed'] = 'This path is not in the allowed list.';
$string['reading_image'] = 'Reading the image...';
$string['regenerate'] = 'Regenerate secret';
$string['role_visibility_help'] = 'Turn a role off to hide the Jedomi sidebar from users with that role. A user is blocked only '
    . 'when every role they hold on the page (in the activity, the course, its categories and the site) is turned off; a user '
    . 'with another role that is on keeps the sidebar. A user without any assigned role is judged by the Authenticated user '
    . 'role. Site admins always see it. Guests and users who are not logged in never see it, so the guest roles are not listed.';
$string['role_visibility_label'] = 'Show sidebar by role';
$string['search_ws_placeholder'] = 'Search web services...';
$string['security_heading'] = 'Security';
$string['security_heading_help'] = 'Secrets used to sign the permission tokens of the Jedomi sidebar.';
$string['send'] = 'Send';
$string['sending'] = 'Sending...';
$string['service_custom'] = 'Custom service';
$string['service_datacurso'] = 'Datacurso service';
$string['session_expired_hint'] = 'Your Moodle session has expired. Please reload the page to continue.';
$string['session_expired_title'] = 'Session expired.';
$string['settings'] = 'Jedomi settings';
$string['sidebar_title'] = 'Jedomi AI';
$string['silence_detected'] = 'Silence...';
$string['speech_error_disabled'] = 'The voice conversation is not enabled on this site.';
$string['speech_error_empty'] = 'There is no text to read aloud.';
$string['speech_error_rate_limited'] = 'Too many answers were read aloud in the last minute. Wait a moment and try again.';
$string['speech_error_too_long'] = 'The text is too long to read aloud (at most {$a} characters).';
$string['speech_model'] = 'Voice model';
$string['speech_model_help'] = 'Model that reads the answers aloud in the voice conversation. The default one, Inworld TTS 1.5 mini (voice Rafael), is the fastest; Qwen3-TTS has the best Spanish quality in the tests. It consumes credits per character read.';
$string['speech_placeholder_email'] = 'an email address';
$string['speech_placeholder_id'] = 'an identifier';
$string['speech_placeholder_person'] = 'a person';
$string['speech_placeholder_text'] = 'some text';
$string['speech_voice'] = 'Voice';
$string['speech_voice_help'] = 'Voice of the chosen voice model that reads the answers. Each model has its own voices.';
$string['spt_secret'] = 'Permission token secret';
$string['spt_secret_help'] = 'Secret used to sign the chat permission tokens. It is generated automatically for this site and '
    . 'stored in Moodle, encrypted, so it does not need to be set. It is never shown: leave the field empty to keep the current '
    . 'secret, or type a new one to replace it. Changing it invalidates the tokens already issued: users must reload the page '
    . 'to keep chatting.';
$string['sql_activity_rejected'] = 'The query reads activities, grades or logs of a course that your permissions in Moodle '
    . 'do not let you see (such as a hidden activity, an activity you may not view, or the grades without the grade report '
    . 'permissions), so it was not run. Read the tables of the other activity types, or ask an administrator.';
$string['sql_blind_marking_rejected'] = 'The query reads who submitted or was graded in an assignment with anonymous '
    . 'submissions whose identities are not revealed, so it was not run. Read the assignment without the student ids, or '
    . 'reveal the identities in the assignment first.';
$string['sql_carriage_return_rejected'] = 'The query contains a carriage return (\\r), so it was not run. '
    . 'Write line breaks as \\n only.';
$string['sql_category_rejected'] = 'The query reads course categories, and some are hidden from you, so it was not run. '
    . 'Limit the categories to the visible ones (for example WHERE cc.visible = 1).';
$string['sql_construct_rejected'] = 'The query uses syntax outside the allowed read-only subset, so it was not run. Write a single '
    . 'SELECT with FROM, [LEFT] JOIN ... ON, WHERE conditions joined only by AND (=, <>, <, >, IN (numbers), IS [NOT] NULL, '
    . 'LIKE \'text\'), GROUP BY, HAVING, ORDER BY and LIMIT, and only the functions COUNT, SUM, AVG, MIN, MAX, ROUND and '
    . 'COALESCE. '
    . 'Do not use CASE, OR, NOT, subqueries, UNION, comments, quoted names, variables or semicolons.';
$string['sql_course_rejected'] = 'The query names a course you do not manage, so it was not run.';
$string['sql_course_unfiltered_rejected'] = 'The query reads course data without limiting it to the courses you manage, so it was '
    . 'not run. Limit every course table to the id of one of your courses (for example WHERE c.id = 12 or WHERE e.courseid IN '
    . '(12, 15)), and do not use OR, UNION, BETWEEN or subqueries.';
$string['sql_function_rejected'] = 'The query uses a function or clause that can read or write server files, pause or lock '
    . 'the database, reach other databases or reveal server variables (such as LOAD_FILE, SLEEP, BENCHMARK, INTO OUTFILE, '
    . '@@ or FOR UPDATE), so it was not run.';
$string['sql_heading'] = 'Web chat queries';
$string['sql_heading_help'] = 'Limits of the database queries the AI runs in the web chat. The time limit per query applies on MySQL, MariaDB and PostgreSQL; SQL Server and Oracle keep the SQL tool with the row limit only.';
$string['sql_params_rejected'] = 'The query parameters do not match its placeholders one to one, so it was not run. Use a list '
    . 'with one value per ? or one key per :name, written without the colon, and no other values.';
$string['sql_row_limit_web'] = 'Maximum rows per query (web chat)';
$string['sql_row_limit_web_help'] = 'Most rows a database query of the web chat returns to the AI, from 10 to 1000 (default: 100). More rows give more complete answers to large questions but make each answer slower and larger. Telegram, the companion web service and the scheduled agents keep their own limits. On SQL Server and Oracle only the row limit applies: Moodle cannot set a time limit per query there, so a slow query of the AI runs on the database of the site until the database or PHP stops it. Keep the limit low on those databases.';
$string['sql_row_limit_web_invalid'] = 'Enter a whole number from 10 to 1000.';
$string['sql_sensitive_rejected'] = 'The query reads credentials, keys, tokens, site configuration or sessions, so it was not run.';
$string['sql_separate_groups_rejected'] = 'The query reads students, grades or submissions of a course or activity in separate '
    . 'groups mode, and you may not access all groups, so it was not run. Read the course structure only, or ask an '
    . 'administrator.';
$string['sql_table_not_allowed'] = 'The query reads a table that is not available for the courses you manage (such as messages, '
    . 'notifications, user preferences or the whole user list), so it was not run. Read course tables limited to your courses, '
    . 'and read user names only by joining {user} to the enrolments, roles or grades of one of your courses (for example JOIN '
    . '{user_enrolments} ue ON ue.userid = u.id JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = 12).';
$string['sql_too_long_rejected'] = 'The query is longer than 8000 characters, so it was not run. Write a shorter query.';
$string['sql_user_column_rejected'] = 'The query reads user columns other than the id and the names (such as the e-mail, phone, '
    . 'address or last IP), or every column with *, so it was not run. List only u.id, u.firstname, u.lastname, u.middlename, '
    . 'u.alternatename, u.firstnamephonetic or u.lastnamephonetic.';
$string['still_working'] = 'Jedomi is still working on your answer…';
$string['stop_answer'] = 'Stop the answer';
$string['summary_config_data'] = 'config data retrieved';
$string['summary_done'] = 'done';
$string['summary_error_generic'] = 'the tool could not complete the request';
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
$string['tab_apis_heading'] = 'Web service allowlists';
$string['tab_apis_heading_help'] = 'Configure which Moodle web services each role can access through Jedomi.';
$string['tab_general'] = 'General';
$string['tab_integrations'] = 'Integrations';
$string['tab_personalization'] = 'Personalization';
$string['tab_privacy'] = 'Privacy';
$string['tab_profiler'] = 'Query profiler';
$string['table_not_found'] = 'Table not found: {$a}';
$string['task_instantiation_failed_msg'] = 'task_instantiation_failed';
$string['task_not_found_msg'] = 'task_not_found';
$string['task_purge_query_profiler'] = 'Purge old query profiler records';
$string['task_register_telegram_webhook'] = 'Register the Telegram webhook again';
$string['task_skipped_plugin_disabled'] = 'Jedomi is disabled in the Datacurso AI provider; skipping.';
$string['telegram_access_heading'] = 'Telegram access by role';
$string['telegram_admin_not_configured_desc'] = 'Set up the Telegram bot in the Jedomi settings. Once it is configured, users see the Telegram linking option here.';
$string['telegram_admin_not_configured_title'] = 'Telegram is not configured (only administrators see this message)';
$string['telegram_admin_settings_link'] = 'Jedomi settings → Integrations';
$string['telegram_bot_heading'] = 'Telegram bot';
$string['telegram_bot_token'] = 'Bot token';
$string['telegram_bot_token_help'] = 'Paste the token from @BotFather. It is encrypted before storage.';
$string['telegram_bot_token_placeholder'] = 'Paste the token from @BotFather';
$string['telegram_bot_token_placeholder_saved'] = 'Saved. Leave blank to keep it.';
$string['telegram_code_expired'] = 'Expired';
$string['telegram_confirm_action'] = '⚠️ This action changes data and runs only if you confirm it: {$a}';
$string['telegram_confirm_keyword'] = 'This action cannot be undone. To run it, type {$a} in this chat.';
$string['telegram_cooldown'] = 'Block time (minutes)';
$string['telegram_cooldown_help'] = 'Minutes to block a user after exceeding the rate limit.';
$string['telegram_drop_pending_failed'] = 'The pending updates could not be dropped. Check the Telegram API response.';
$string['telegram_enabled'] = 'Enable Telegram integration';
$string['telegram_enabled_save_failed'] = 'The Telegram integration switch could not be saved. Please try again.';
$string['telegram_gateway_timeout'] = 'AI response timeout (seconds)';
$string['telegram_gateway_timeout_help'] = 'Maximum seconds to wait for each AI request of the Telegram bot, sent through the '
    . 'Datacurso AI provider (5-50). Each request has to fit in one run of the bot, so longer waits cannot apply.';
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
$string['telegram_limits_heading'] = 'Telegram limits and response time';
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
$string['telegram_link_subtitle'] = 'Link your Telegram account to chat with Jedomi from Telegram, with the same permissions you have in Moodle.';
$string['telegram_link_title'] = 'Link Telegram';
$string['telegram_linked_chatid'] = 'chat_id: {$a}';
$string['telegram_linked_on'] = 'Linked on {$a}';
$string['telegram_max_turns'] = 'Maximum conversation turns';
$string['telegram_max_turns_help'] = 'Number of messages kept in Telegram conversation history (1-100). Lower values save tokens.';
$string['telegram_msg_account_inactive'] = 'Your Moodle account is not active, so the bot cannot serve it. Please contact the site administrator.';
$string['telegram_msg_audio_disabled'] = 'Voice and audio messages are not enabled on this site. Please send your question as text.';
$string['telegram_msg_done'] = '✅ Done.';
$string['telegram_msg_expired_code'] = 'Invalid or expired code. Generate a new one from your Moodle profile.';
$string['telegram_msg_file_unsupported'] = 'Only PDF, Word (DOC, DOCX), PowerPoint (PPT, PPTX), JPG, PNG and WEBP files can be read.';
$string['telegram_msg_files_disabled'] = 'Photos and files are not enabled on this site. Please send your question as text.';
$string['telegram_msg_heard'] = '🎤 {$a}';
$string['telegram_msg_keepalive'] = '🔄 Still working on your request…';
$string['telegram_msg_link_expired'] = 'Your Telegram link expired after 30 days without use. Generate a new link code on the Jedomi Telegram page of your Moodle profile to link this chat again.';
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
$string['telegram_msg_state_save_failed'] = '⚠️ Sorry, the progress of your request could not be saved. Please try again with /new.';
$string['telegram_msg_still_processing'] = '⏳ I am still working on your previous request, so this message was not processed. Please send it again once I answer.';
$string['telegram_msg_text_only'] = 'I can only read text, photos, documents and voice messages. Please send your question as text.';
$string['telegram_msg_token_invalid'] = 'The bot token is not valid, so it was not saved. The previous token was removed and the bot was '
    . 'disconnected; the other changes were saved. Check the token from @BotFather and save it again.';
$string['telegram_msg_token_removed'] = 'The bot token was removed and its webhook was deleted in Telegram. The bot no longer answers.';
$string['telegram_msg_token_removed_webhook_kept'] = 'Bot token removed. It could not be decrypted, so its webhook could not be deleted in Telegram and may remain registered: to delete it, enter the token again and remove it, or revoke the token in @BotFather.';
$string['telegram_msg_unavailable'] = 'The Jedomi bot is not available right now. Please try again later.';
$string['telegram_msg_unlinked'] = 'Unlinked. Send /start to link again.';
$string['telegram_new_conversation_greeting'] = 'Hello, I am {$a}';
$string['telegram_no_app_desc'] = 'If you are on a computer without the app, open Telegram on your phone, search for <strong>@{$a}</strong> and send this message:';
$string['telegram_no_app_title'] = 'No Telegram app?';
$string['telegram_no_tables_found'] = 'No tables found matching: {$a}';
$string['telegram_not_linked'] = 'Not linked';
$string['telegram_notify_graded'] = '📝 Your assignment <b>{$a->assignment}</b> in <b>{$a->course}</b> has been graded.';
$string['telegram_open_auto'] = 'Open in Telegram (automatic)';
$string['telegram_open_auto_help'] = 'Takes you straight to @{$a} with your code ready to link.';
$string['telegram_permission_denied'] = '❌ You do not have permission to do this.';
$string['telegram_pin_info'] = 'Changes you ask Jedomi for in Telegram are confirmed with this PIN instead of the Confirm button. After the right PIN it is not asked again for 15 minutes, but each change still shows its card and runs only when you confirm it with the button or the confirmation word; 3 wrong PINs lock your changes through Telegram for 15 minutes. Queries do not need the PIN.';
$string['telegram_pin_invalid'] = 'The PIN is numeric only: {$a->min} to {$a->max} digits, with no letters, spaces or symbols.';
$string['telegram_pin_is_set'] = 'A PIN is set. Saving a new one replaces it.';
$string['telegram_pin_locked'] = 'Too many wrong PINs: your changes through Telegram are locked for {$a} more minute(s). Queries keep working.';
$string['telegram_pin_mismatch'] = 'The two PINs do not match.';
$string['telegram_pin_missing'] = 'This change needs your Telegram PIN, and you have not set one yet. Set it on the Jedomi Telegram page of your profile: {$a}. Queries keep working without it.';
$string['telegram_pin_new'] = 'New PIN';
$string['telegram_pin_not_set'] = 'No PIN is set: your changes through Telegram are refused until you set one.';
$string['telegram_pin_prompt'] = 'Type your Telegram PIN to confirm.';
$string['telegram_pin_repeat'] = 'Repeat the PIN';
$string['telegram_pin_save'] = 'Save PIN';
$string['telegram_pin_saved'] = 'The Telegram PIN was saved.';
$string['telegram_pin_title'] = 'Telegram PIN';
$string['telegram_pin_wrong'] = 'Wrong PIN. Attempts left: {$a}.';
$string['telegram_quick_step1_desc'] = 'a 6-character code appears, valid for 10 minutes.';
$string['telegram_quick_step1_title'] = 'Click "Generate code" below';
$string['telegram_quick_step2_desc'] = 'send <code>/start YOUR_CODE</code>.';
$string['telegram_quick_step2_title'] = 'Open in Telegram:';
$string['telegram_quick_step3_desc'] = '{$a} confirms at once. Ask anything about your Moodle.';
$string['telegram_quick_step3_title'] = 'That\'s it!';
$string['telegram_rate_limit'] = 'Rate limit';
$string['telegram_rate_limit_help'] = 'Maximum messages per minute a user can send. 0 = unlimited.';
$string['telegram_regenerate_confirm'] = 'The webhook URL changes and is registered again in Telegram. Continue?';
$string['telegram_remove_token'] = 'Remove the bot token';
$string['telegram_remove_token_desc'] = 'Disconnect the bot: the token is deleted and the webhook is removed from Telegram.';
$string['telegram_require_pin'] = 'Require a PIN in Telegram for actions of administrators and managers';
$string['telegram_require_pin_desc'] = 'Administrators and managers confirm their changes in Telegram with a PIN they set on their profile.';
$string['telegram_require_pin_help'] = 'When checked, each administrator and manager sets a PIN of 6 to 8 digits on the Jedomi Telegram page of their profile. Their changes through Telegram are confirmed with the PIN instead of the Confirm button; after the right PIN it is not asked again for 15 minutes, but each change still shows its card and runs only when they confirm it with the button or the confirmation word, and 3 wrong PINs lock their changes through Telegram for 15 minutes. Until they set the PIN their changes through Telegram are refused, while queries keep working. Teachers and students are not affected.';
$string['telegram_role_visibility_help'] = 'Toggle OFF to prevent users with this role from linking or receiving Telegram '
    . 'messages. Admins always have access.';
$string['telegram_search_query_required'] = 'Search query required.';
$string['telegram_secret_regenerated'] = 'The webhook secret was regenerated and the new webhook URL was registered in Telegram.';
$string['telegram_secret_regenerated_unregistered'] = 'The webhook secret was regenerated, but the new webhook URL could not be registered in '
    . 'Telegram. Check the webhook status.';
$string['telegram_setup_guide'] = 'Setup guide';
$string['telegram_sql_query_required'] = 'SQL query required.';
$string['telegram_status_connected'] = 'Connected';
$string['telegram_status_disconnected'] = 'Disconnected';
$string['telegram_status_invalid_token'] = 'invalid token, paste it again and save.';
$string['telegram_step1_desc'] = 'Open Telegram, search for <strong>@BotFather</strong>, send <code>/newbot</code>, choose a name '
    . 'and username. Copy the token it gives you (format: <code>123456:ABC-DEF...</code>).';
$string['telegram_step1_title'] = 'Create a bot in @BotFather';
$string['telegram_step2_desc'] = 'Paste the token in the <strong>Bot token</strong> field below and click <strong>Save changes</strong>. '
    . 'The integration switch is saved as soon as it is changed.';
$string['telegram_step2_title'] = 'Paste the token and save';
$string['telegram_step3_desc'] = 'Nothing to do: the plugin registers the <strong>Webhook URL</strong> in Telegram '
    . 'automatically when the bot token is saved. If the site URL changes, save the bot token again to register the new URL.';
$string['telegram_step3_title'] = 'Register the webhook in Telegram';
$string['telegram_step4_desc'] = 'Use the role toggles below to control which Moodle roles can use Telegram. Site admins always '
    . 'have access regardless of this setting.';
$string['telegram_step4_title'] = 'Configure role access (optional)';
$string['telegram_stepU1_desc'] = 'Click your name in the top bar → <strong>Profile</strong>. You will see a <strong>{$a}</strong> '
    . 'section with the <em>Link Telegram</em> link.';
$string['telegram_stepU1_title'] = 'Go to your Moodle profile';
$string['telegram_stepU2_desc'] = 'Click <strong>Generate code</strong>. A 6-character code appears, valid for 10 minutes.';
$string['telegram_stepU2_title'] = 'Generate a linking code';
$string['telegram_stepU3_desc'] = 'In Telegram, open your institution\'s bot and send: <code>/start YOUR_CODE</code>. The bot '
    . 'confirms the link instantly.';
$string['telegram_stepU3_title'] = 'Send /start to the bot';
$string['telegram_stepU4_desc'] = 'Send any message to the bot. Jedomi answers with real data from your Moodle. To unlink, send '
    . '<code>/desvincular</code> or use your profile page.';
$string['telegram_stepU4_title'] = 'Start chatting';
$string['telegram_step_admin_title'] = 'Administrator setup';
$string['telegram_step_user_title'] = 'User setup (each user does this once)';
$string['telegram_table_name_required'] = 'Table name required.';
$string['telegram_task_alert_failing'] = '{$a->name}: failing (next attempt in {$a->delay} s)';
$string['telegram_task_alert_footer'] = 'Review them on the Scheduled tasks page: {$a}';
$string['telegram_task_alert_heading'] = '⚠️ <b>Scheduled tasks with problems</b>';
$string['telegram_token_not_configured'] = 'The bot token is not configured.';
$string['telegram_token_unreadable'] = 'The saved bot token could not be decrypted: the encryption key of the site changed. The bot does not receive messages until you enter the token again.';
$string['telegram_webhook_auto_missing_desc'] = 'Try saving the token again.';
$string['telegram_webhook_auto_missing_title'] = 'Webhook not registered';
$string['telegram_webhook_auto_ok_desc'] = 'When the token was saved, the webhook was registered automatically.';
$string['telegram_webhook_auto_ok_title'] = 'Webhook registered automatically';
$string['telegram_webhook_failed_body'] = 'The Telegram webhook of Jedomi could not be registered with its new secret, so the bot '
    . 'does not receive messages. The site keeps trying for about 17 hours. Check that the site is reachable over HTTPS and '
    . 'can reach api.telegram.org, or save the bot token again in the Integrations tab of Jedomi.';
$string['telegram_webhook_failed_final_body'] = 'The Telegram webhook of Jedomi could still not be registered after '
    . 'about 17 hours of attempts, and the site stopped trying, so the bot does not receive messages. Save the bot token '
    . 'again in the Integrations tab of Jedomi to register it.';
$string['telegram_webhook_failed_open'] = 'Open the Integrations tab';
$string['telegram_webhook_failed_subject'] = 'Jedomi: the Telegram bot does not receive messages';
$string['telegram_webhook_info_failed'] = 'getWebhookInfo failed. Check the bot token and outbound HTTPS access.';
$string['telegram_webhook_needs_registration'] = 'The update could not register the Telegram webhook again with its new secret, so the bot '
    . 'does not receive messages. The site retries it in the background for about 17 hours; you can also save the bot token '
    . 'again to register it.';
$string['telegram_webhook_retry_failed'] = 'The Telegram webhook could not be registered; the task will try again.';
$string['telegram_webhook_secret'] = 'Webhook secret';
$string['telegram_webhook_secret_help'] = 'Random secret that Telegram sends in a header with every message, never in the webhook '
    . 'URL. Regenerate it if it is compromised.';
$string['telegram_webhook_set_failed'] = 'Telegram did not accept the webhook. Check that the site URL uses HTTPS and is reachable from the internet.';
$string['telegram_webhook_status'] = 'Webhook status';
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
$string['tool_create_agent_context_id'] = 'The ID of the Moodle context (course, category or user context), not the ID of the '
    . 'course, category or user. Required when context_type is not "site".';
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
$string['tool_execute_sql_desc'] = 'Run a SELECT query on the Moodle database and return the rows as JSON. Read only. Limits in this channel: at most {$a->rows} rows (the result says when it was truncated) and {$a->seconds} seconds per query on MySQL, MariaDB and PostgreSQL.';
$string['tool_execute_sql_params'] = 'Bound parameters of the query, in the order of the ? placeholders (optional)';
$string['tool_execute_sql_sql'] = 'SELECT query to run. Use ? as the placeholder of each parameter. Example: SELECT id, fullname FROM {course} WHERE category = ?';
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
    . 'cut-off dates as they apply to the user (with their overrides, and the extended due date when one was granted) and the '
    . 'submission status, and the upcoming deadlines of the user calendar. Use it when a student asks about '
    . 'their assignments, homework or deadlines.';
$string['tool_my_courses_desc'] = 'Read-only. The courses in which the current user has an active enrolment. Use it when a '
    . 'student asks about their courses.';
$string['tool_my_grades_desc'] = 'Read-only. The grades the current user can see in their courses (hidden or unreleased grades '
    . 'are not included). Use it when a student asks about their grades.';
$string['tool_own_data_courseid'] = 'Optional: id of one of the courses of the user, to read only that course.';
$string['tool_progress_create_agent'] = 'Creating agent';
$string['tool_progress_create_assign'] = 'Creating assignments';
$string['tool_progress_create_book'] = 'Creating books';
$string['tool_progress_create_categories'] = 'Creating categories';
$string['tool_progress_create_courses'] = 'Creating courses';
$string['tool_progress_create_forum'] = 'Creating forums';
$string['tool_progress_create_group'] = 'Creating groups';
$string['tool_progress_create_grouping'] = 'Creating groupings';
$string['tool_progress_create_groupings_groups'] = 'Adding groups to groupings';
$string['tool_progress_create_interactive'] = 'Creating interactive content';
$string['tool_progress_create_label'] = 'Creating text and media areas';
$string['tool_progress_create_lesson'] = 'Creating lessons';
$string['tool_progress_create_page'] = 'Creating pages';
$string['tool_progress_create_quiz'] = 'Creating quizzes';
$string['tool_progress_create_users'] = 'Creating users';
$string['tool_progress_delete_agent'] = 'Deleting agent';
$string['tool_progress_enrol_users'] = 'Enrolling users';
$string['tool_progress_list_agents'] = 'Listing agents';
$string['tool_progress_set_course_sections'] = 'Organising course sections';
$string['tool_progress_upload_content_files'] = 'Uploading files';
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
$string['tool_site_overview'] = 'Getting the platform overview...';
$string['tool_site_overview_days'] = 'Days of the activity window used to count recently active users (1-365, default 30).';
$string['tool_site_overview_desc'] = 'Get in ONE call an overview of the whole platform: site (name, host, '
    . 'Moodle release, language, time zone, last cron run), users (total, active, suspended, recently active, never logged '
    . 'in), courses (total, visible, hidden, categories, without a teacher), active enrolments, activities (total and most '
    . 'used types), scheduled Jedomi agents and file storage. Call it FIRST for a general summary or the overall state of '
    . 'the platform and answer from it; use other tools only for details it does not give.';
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
$string['voice_mode_busy_pending'] = 'The voice conversation cannot start while a voice message waits to be sent. Send or discard it first.';
$string['voice_mode_busy_recording'] = 'The voice conversation cannot start while a voice message is being recorded. Stop the recording first.';
$string['voice_mode_busy_sending'] = 'The voice conversation cannot start while a message is being sent. Wait for the answer and try again.';
$string['voice_mode_confirm_notice'] = 'Please confirm the action on screen.';
$string['voice_mode_exit'] = 'End voice conversation';
$string['voice_mode_idle_exit'] = 'The voice conversation ended after a minute without hearing you.';
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
$string['voice_too_long'] = 'The recording stopped at the maximum duration of {$a} minutes. You can listen to it and send it.';
$string['voice_too_short'] = 'The voice message was too short and was discarded. Hold the recording a little longer.';
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
$string['welcome_message_default'] = "Hi, {username}!\nI'm {assistantname}, your Moodle AI assistant. "
    . 'Ask me about courses, users, grades or settings.';
$string['ws_agent_allowlist_required'] = 'Agents run no web service function until a web service list is saved for the role of their creator in the Web services tab.';
$string['ws_cannot_load_info'] = 'Cannot load function info: {$a}';
$string['ws_component'] = 'Component';
$string['ws_description'] = 'Description';
$string['ws_execute_error'] = 'Error executing web service: {$a}';
$string['ws_function_not_found'] = 'Function not found: {$a}';
$string['ws_invalid_parameters'] = 'Invalid parameters for the web service: {$a}';
$string['ws_name'] = 'Name';
$string['ws_none_found'] = 'No web services match your filters.';
$string['ws_not_found'] = 'Web service function not found.';
$string['ws_not_in_allowlist'] = 'This web service is not in your role\'s allowlist.';
$string['ws_pagination'] = 'Page {$a->page} of {$a->pages} ({$a->total} services)';
$string['ws_role_filter'] = 'Role:';
$string['ws_type'] = 'Type';
$string['ws_type_all'] = 'All types';
$string['ws_type_read'] = 'Read';
$string['ws_type_write'] = 'Write';
$string['yes_send'] = 'Yes, send';
