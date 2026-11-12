# Changes

## 1.5.0 (2026-11-11)

- Add a voice conversation to the web chat, off by default: a button next to the microphone opens the voice view (listening, thinking, speaking) with an exit button; Esc, closing the panel or 60 seconds without speech also end it. The end of what the user says is detected by its silence (about 1.2 seconds, with Web Audio), the recording is transcribed by the audio model and shown as a "🎤" message, and the answer is read aloud sentence by sentence (code blocks and tables are not read) while the next sentence is requested; speaking over Jedomi stops it and it listens again. An action that changes data still shows the confirmation card: Jedomi asks to confirm it on screen and stops listening until it is resolved. If a sentence cannot be read the text stays in the chat; if the transcription fails Jedomi says so and listens again
- Add the "Enable voice conversation" switch, the voice model and the voice to the Jedomi settings page of the Datacurso AI provider (`local_jedomi/voice_enabled`, `speech_model`, `speech_voice`). The switch needs audio processing; the voice model offers Qwen3-TTS (default, `jedomi/Qwen/Qwen3-TTS`, voices Vivian (default), Serena, Uncle Fu, Dylan, Eric, Ryan, Aiden, Ono Anna and Sohee) and Inworld TTS 1.5 mini (`jedomi/inworld-ai/inworld-tts-1.5-mini`, voices Diego (default), Lupita, Miguel and Rafael); each model has its own voice selector, shown only while it is chosen. A model or voice outside the list uses the default one. Speech models never appear in the chat, file and image or audio selectors
- Add `speech.php`, which reads a sentence aloud through the Datacurso AI provider at `POST /jedomi/v1/audio/speech` with the voice model and voice of the settings and answers the MP3. It refuses guests, a wrong session key, invalid or expired chat permissions, Jedomi disabled in the provider, the voice conversation (or audio processing) off, an empty text, more than 1000 characters and more than 30 requests per minute per user, without calling the AI service
- In the voice conversation the chat request carries `voice_mode`, and the instructions ask for short spoken answers in complete sentences, without tables, lists, code or markdown, offering the details on screen; the full answer is still shown in the chat. The browser cannot turn this on while the voice conversation is off
- Declare in the Privacy API the text of the answers sent to the AI service to be read aloud
- The 2026111100 upgrade leaves the voice conversation off with Qwen3-TTS and Vivian, and keeps values already saved
- Requires the Datacurso AI provider 1.8.0 (`aiprovider_datacurso` 2026111100): `speech.php` gets the MP3 through the provider's new `request_raw()`, which keeps the licence, rate-limit headers, pseudonymised user and error handling of every other call and returns the audio unchanged; the cURL wrapper that captured the audio body is removed

### Security

- The database query guard reads the query as the database does: a new parser (`local\sql_select_parser`) checks every piece of a teacher's query against a small grammar (SELECT, FROM, INNER/LEFT JOIN with ON, WHERE with AND, comparisons, IN, IS NULL, LIKE, GROUP BY, ORDER BY, LIMIT, COUNT/SUM/AVG/MIN/MAX/ROUND/COALESCE) before the course filter. This closes ways a teacher could read data of courses they do not manage: hiding the course filter inside CASE, a backquoted reserved word as an alias, a control character after `--`, and a `?` inside a comment that shifted the query parameters. For every user, control characters, executable comments (`/*!`, `/*M!`, `/*+`) and placeholders that the database would bind differently are rejected, and on PostgreSQL, SQL Server and Oracle the quoting the guard cannot follow is rejected. Teachers lose OR, NOT, CASE, subqueries, HAVING, arithmetic and functions other than the listed aggregates
- The document reading tool (`document_read`) reads only the PDF the activity publishes (its description, attachments and content areas of the module), never student submissions, feedback, forum post attachments or quiz responses, and checks course access first, so a suspended or hidden-course enrolment is refused before any other permission

## 1.4.1 (2026-11-10)

- Security: fix teachers (users who do not manage every course) being able to read data of the whole site with the database query of the web chat, Telegram and the agents, such as private messages, notifications, user preferences and the e-mail, phone and last IP of every user, administrators included. For them the query now only reads course data limited to the courses they manage, the `role`, `modules` and `course_categories` tables, and from `{user}` only the id and name columns of users tied to one of their courses (for example through `{user_enrolments}` and `{enrol}`); any other table is refused (`sql_table_not_allowed`), and so are other user columns or `*` (`sql_user_column_rejected`). Administrators and managers keep reading every table except the sensitive ones
- Security: refuse for every user, administrators included, the queries that call `LOAD_FILE`, `SLEEP`, `BENCHMARK`, `GET_LOCK`, `RELEASE_LOCK`, `pg_sleep`, `pg_read_file`, `pg_read_binary_file`, `lo_import`, `lo_export` or `dblink`, write files with `INTO OUTFILE` or `INTO DUMPFILE`, use `COPY`, read server variables with `@@` or hide code in MySQL executable comments (`sql_function_rejected`), whatever the case, spaces or comments; a quote inside a comment can no longer hide part of the query from the check
- Teachers can query the data of their own courses and run the web service functions of the teacher list from the web chat, as in Telegram: the web chat now uses the same role gate (administrator, manager or teacher for the database query, the web service list of the role for web services) and the same query and web service execution as Telegram, without needing `local/jedomi:companion_api`, which is still required by the `jedomi_companion` web services. Students are still refused the database query in both channels
- Fix managers (who are not site administrators) being unable to run database queries and web services from the web chat: the `local/jedomi:companion_api` capability no longer prohibits the default roles; only managers have it by default, and administrators can grant it to the `jedomi_companion` service account or other roles
- The 2026111000 upgrade removes the prohibit on `local/jedomi:companion_api` that older versions left at system level in the course creator, teacher, non-editing teacher, student, guest, authenticated user and authenticated user on frontpage roles; other values set by an administrator are kept
- Fix teachers being unable to read the PDF of an activity in their own course with `document_read` from the web chat (it required `moodle/course:view`, which only managers have): in the web chat and in the agents the user must now be able to manage the activity, or access its course, see the activity (visibility and availability) and hold its view capability; teachers of other courses are still refused
- Update the help of the permission token secret (generated automatically per site and stored in Moodle), of the Telegram AI response timeout (the AI request through the Datacurso AI provider) and of the Telegram webhook (registered automatically when the bot token is saved; save it again if the site URL changes), which still mentioned the Jedomi Gateway, the `.env` file and `/setwebhook` in @BotFather
- Show the output of `cli/chat_cli.php` in English only

## 1.4.0 (2026-11-06)

- Route every AI call (web chat, scheduled agents, agent preview, Telegram and CLI) through the Datacurso AI provider to `POST /jedomi/v1/chat/completions`, using the provider license, rate limits and the pseudonymised user of each call
- Move the AI service settings (model and max tokens) and the "Enabled" switch to the Jedomi settings page of the Datacurso AI provider (Site administration > AI > Datacurso plugins list > Settings); the values are still stored in `local_jedomi`, the max tokens are kept between 256 and 128000, and the General tab of the Jedomi settings links to that page
- Read the "Enabled" state from the Datacurso AI provider for the sidebar and file reading; the upgrade removes the old `enabled` setting of `local_jedomi` (the provider keeps its value)
- Show the provider's own message, in the user's language, when the Datacurso AI provider is disabled in Site administration > AI > AI providers (code `provider_disabled`), in the web chat, agent preview, Telegram, agents and CLI; nothing is sent to the AI service
- Add `cli/e2e_check.php`: one short real conversation with the `search_tables` tool through the provider, plugins-ai, LiteLLM and the model, with the site license, model and region; prints PASS or FAIL for provider enabled, request answered, tool call returned, final answer and model used, and on failure the error code and the layer (provider, plugins-ai, token-manager, network); never prints the license or answer bodies; exit code 0 or 1
- Replace the free-text model setting with a list of evaluated and priced models: Gemma 4 26B A4B (default, `jedomi/google/gemma-4-26B-A4B-it`), DeepSeek V4.1 Flash, GLM 5.3 Flash and MiMo V2.6 Flash
- Use the default model when the saved model is not in the list; the upgrade replaces such a saved model with the default one
- Apply the `max_tokens` setting to every channel
- Remove the `gateway_url` and `api_key` settings; the upgrade deletes them and replaces a model without the `jedomi/` prefix with the default one
- Ignore the model sent by the browser to the web chat proxy
- Show clear messages in English and Spanish for no credit, rate limit (with retry time), missing or invalid license, unavailable service and other AI errors in the web chat, agent preview, Telegram, agents and CLI
- Store answer ratings in Moodle (`local_jedomi_feedback`, web service `local_jedomi_save_feedback`) and add a privacy provider for them
- Stop sending the gateway URL and API key to the browser; remove the browser calls to the legacy gateway for voice transcription, action confirmation, message sending and feedback (confirmation, message sending and file reading now go through Moodle, see below)
- Ask for confirmation in the web chat before a tool that changes data runs: the action waits in the new table `local_jedomi_pending_actions` and the chat shows the confirmation card with its severity: critical (type the confirmation keyword) for `delete_agent` and write web service functions that delete, remove or unenrol; high for `admin_execute` and other write web service functions; medium for `create_agent` and `send_messages`. Reading tools run as before; Telegram and scheduled agents are unchanged
- Add the web service `local_jedomi_resolve_action`: only the owner can confirm or cancel an action, once and within 15 minutes; it runs with the user's current role and returns a short result that is added to the conversation. Expired actions are deleted by the cleanup task
- Add the web chat tool `send_messages`, offered to users with `moodle/site:sendmessage`: after confirmation it sends Moodle internal messages only to users the sender can contact and reports sent and failed; the "Notify group" quick action ends in this tool again
- Restore the "File" attach option and file drag and drop for `.pdf`, `.csv`, `.txt` and `.md`: Moodle sends the file through the Datacurso provider to `POST /jedomi/v1/files/extract` (not billed); the file stays as a pending attachment above the input, like an image, and its text (with its pages, rows and columns, or characters) is sent with the next question, so editing the input does not drop it; clear messages for unsupported, too large or unreadable files and for provider errors
- Stop sending the SPT to the AI service from Telegram
- Fix Telegram answers never arriving: the request body to the Telegram API now separates fields with `&` instead of Moodle's `&amp;`, which left the message text empty
- Fix Telegram sending the same message again (and the AI service being called again) when the site is behind a proxy: the early webhook answer now declares its real length
- Fix the Telegram bot no longer answering after "Drop pending updates": the webhook is registered again after the queue is cleared
- Show a timeout message and stop resending the Telegram request to the AI service when a call times out, so it is not billed again
- Fix the agent preview and scheduled agents sending tool results without the assistant tool calls that precede them
- Fix an invalid regular expression in the cleanup of the scheduled agent answer
- Render only web and site-relative links in chat answers and escape quotes, so model output cannot inject `javascript:` or `data:` links or HTML attributes
- Log status codes and sizes instead of AI request and response contents
- Remove hardcoded default API keys and gateway URLs
- Render the "Enable Telegram integration" switch of the Integrations tab as a switch on Moodle 4.5 (Bootstrap 4) as well as on Moodle 5.x (Bootstrap 5); it used to show as a plain checkbox on 4.5
- Save the "Enable Telegram integration" switch as soon as it is toggled, without submitting the form, through the new web service `local_jedomi_set_telegram_enabled` (site administrators only); if the save fails the switch goes back to its previous state and a notification is shown
- Rebuild the Jedomi settings page with Moodle's output API: the page is an `admin_externalpage` (no redirect script in `settings.php`), uses a `tabtree`, and each tab is a renderable with its own Mustache template under `templates/admin/`; the General, Course availability, Privacy and Telegram settings are moodleforms (`classes/form/`); the settings logic lives in `\local_jedomi\local\service\admin_settings_service`; all inline JavaScript moved to AMD modules (`amd/src/admin/`) and all inline CSS to `styles.css`
- The course selector of the Course availability tab is Moodle's course autocomplete; the Telegram limits are validated on the server (rate limit 0-1000, cooldown 0-1440, max turns 1-100, timeout 5-300)
- The web service table of the APIs tab is rendered from templates in the browser; `ajax/ws_table.php` returns data instead of HTML
- Fix the agent purge form, which sent field names the endpoint did not read, and show the id field for role purges
- The Profiler tab filters (minimum duration and sort) now apply to the recent queries list
- Rebuild the Telegram profile page with a renderable, a template and an AMD module; all its text comes from the language files
- Move the sidebar markup from `sidebar.js` to Mustache templates (`templates/sidebar.mustache` and `templates/local/sidebar/`) rendered with `core/templates`; the chat answers are still converted from Markdown by the sidebar
- Show the tool status and session expired messages of the sidebar in the user's language (they always fell back to Spanish text) and remove the hardcoded fallback texts
- Fix a warning when the model omits the image format
- Fix the Profiler tab failing with "Table local_jedomi_query_log doesn't exist" and the agent name not being saved on sites that had Jedomi installed before 2026-09-02: the upgrade now creates the `local_jedomi_query_log` table and the `name` column of `local_jedomi_agents` when they are missing
- Remove dead code (`webhook_handler::handle_pending_action()`, `local_jedomi_get_all_ws()`) and unused strings; complete docblocks and translate the remaining Spanish comments to English
- Add a catalogue of models with what each one can do (chat, vision, audio); each selector of the Jedomi settings page in the Datacurso AI provider lists only the models of its use, so a multimodal model appears in more than one
- Add to that page, after the chat model: "Enable file and image processing" (on by default) with the file and image model (Qwen3-VL 30B A3B by default; also Qwen3-VL 235B A22B and the four chat models), and "Enable audio processing" (off by default) with the audio model (Qwen3-ASR 1.7B by default; also Whisper large-v3 turbo and Voxtral Mini 3B). Each model is shown only while its switch is on, never has an empty option and already shows the default model, so a switch can be turned on and saved without touching its model; a missing or unlisted model is rejected while its switch is on and replaced with the default one when read
- Remove the document-to-image service URL setting; the 2026110301 upgrade deletes it and saves the defaults of the new settings that are not set yet (file processing on, audio off, default vision and audio models)
- Read images, Telegram photos and documents, and the PDF of course activities (`document_read`) with the vision model through the Datacurso provider and `POST /jedomi/v1/files/read`: the question of the user (or the Telegram caption) goes with the file, the text read is added to the message, and the chat model never receives images; `document_read` accepts the pages to read and returns text
- Read a PDF without text attached in the web chat with the vision model when `POST /jedomi/v1/files/extract` answers that it has no text
- Add the microphone to the web chat: the recording is transcribed by the audio model through `POST /jedomi/v1/audio/transcriptions`
- Record voice messages like in Telegram: while recording, a bar with the elapsed time, stop and cancel replaces the input box; after stopping, the recording can be listened to (play/pause, progress and duration), deleted to record again, or sent. It stays in the browser and is uploaded and transcribed (billed) only when sent; its text is then sent as the user message, shown with 🎤. If the transcription fails, the preview stays to retry or delete it. The text typed before recording is kept in the input box; closing the chat while recording throws the recording away
- Accept Word and PowerPoint files (.doc, .docx, .ppt, .pptx) in the web chat (file picker and drag and drop) and as Telegram documents: their text is read without a model through `POST /jedomi/v1/files/extract`, so it is not billed (the legacy .doc and .ppt are converted by LibreOffice on the AI service); a Telegram document keeps its caption as the question. Still off when file and image processing is off
- Accept audio files (.mp3, .wav, .m4a, .ogg, .webm) as attachments in the web chat: their transcription is kept as a pending attachment, like a PDF
- Transcribe Telegram voice and audio messages: the bot first answers with what it understood (🎤) and then processes the text as a message
- With file and image processing off, the web chat has no image or file attach option and no drag and drop, `extract_file.php` and the new `read_file.php` refuse documents and images, the chat proxy refuses messages with images, `document_read` is not offered nor described to the AI, and Telegram answers that photos and files are not enabled, without calling the AI
- With audio processing off, the web chat has no microphone and no audio attach option, audio files are refused, and Telegram answers that voice messages are not enabled, without calling the AI
- Stop sending Telegram messages without text (stickers, locations...) to the AI: the bot asks for a text message
- Show clear messages for an audio too long or too large, an unsupported audio format, an audio that could not be understood, an unsupported or unreadable image or file, and the existing credit, rate limit and service errors
- Stop Jedomi while it is disabled in the Datacurso AI provider: its pages and web services show the provider's "not enabled" message (administrators get a link to the Datacurso plugins list), the web chat, file, conversation and agent preview endpoints answer that message as a JSON or stream error, the Telegram bot replies with it without calling the AI, and the Telegram notifications and the Telegram link in the user profile do nothing; the scheduled agents and the adhoc task runner log *Jedomi is disabled in the Datacurso AI provider; skipping.* (`task_skipped_plugin_disabled`) and stop; require `aiprovider_datacurso` 2026110400

### Security

- Refuse guest and not-logged-in requests to the chat, history and file reading on the server, not only in the UI
- Decide the role of the user on the server instead of trusting the role carried by the signed permissions
- Sign with a per-site secret, replacing the built-in signing key
- Make the companion HMAC cover the received parameters: the payload hash must be the SHA-256 of the sorted-key JSON of the non-auth parameters
- Check agent ownership and role rank before viewing, editing, running or deleting an agent
- Block credentials, keys, tokens, site configuration, sessions and other sensitive tables and columns from database queries in the web chat, agents and Telegram
- Limit teachers to the courses they teach, including queries that pass the course as a bound parameter
- Neutralise CSV cells starting with `=`, `+`, `-` or `@` and export only the text of link cells
- Telegram: use the same role as the web chat, refuse suspended and `nologin` users, answer private chats only and ask for confirmation before write actions

### Privacy

- Apply the anonymisation levels to the stored feedback and to the data sent to the AI; **behaviour change for existing sites, which use the Basic level by default**
- Declare, export and delete the Telegram, agent and profiler data in the privacy provider
- Redact personal data from the technical log

### Added

- Read-only student tools over their own data: my courses, my grades and my assignments

### Fixed

- Fix a crash in `validate_password`
- Add missing capability strings
- Fix the Feedback only level, which still stored the answer text
- Apply the role visibility with the page context (course and its activities)
- Show a notice when the rate limit check fails
- Fix the HTML formatting of Telegram answers
- Stop grade notifications for hidden, unreleased and anonymous grades
- Schedule agents at their hour in the creator's time zone
- Pass the real course id to course agents instead of the context id
- Make `cli/e2e_check.php` honour the Enabled switch
- Cache the agent preview per user

## 1.2.3 (2026-05-25)

- Add `duration_ms` and `row_count` to search_tables, describe_table, ws_search, ws_describe tools
- Show tool call arguments in agent preview profiler
- Add cache check in agent preview (show cached results instead of re-executing)
- Move Query Profiler to its own admin settings tab
- Improve preview UI rendering with collapsible arguments, cache status, and scrollable answer

## 1.2.2 (2026-05-25)

- Fix Telegram bot stuck loop: release `is_locked` on cURL error, HTTP non-200, and SSE parse failure paths
- Add 120s TTL to `is_locked` as defense-in-depth against stale locks
- Add `locked_at` timestamp to tool_loop state for TTL calculation
- Update `lastactivity` in `session::set_context()` so cleanup task can detect stale sessions
- Raise medium guard threshold from 20s to 40s, add soft guard keepalive at 30s
- Add `session_gen` TOCTOU protection to prevent `/new` race with stale self-pings
- Hide microphone button from sidebar input
- Bump plugin version to 2026052511

## 1.2.1 (2026-05-24)

- Replace emoji tool status icons with SVG icons
- Add proper language strings for web service tool status messages (en/es)
- Fix `placeholder` → `chat_placeholder` key mismatch in JavaScript
- Bump plugin version to 2026052410

## 1.1.0 (2026-05-22)

- Extract all hardcoded user-facing strings to Moodle language files (en/es)
- Replace hardcoded tool descriptions, error messages, SSE summaries, and context labels with `get_string()` calls
- Add ~70 new string definitions to both `lang/en/` and `lang/es/`
