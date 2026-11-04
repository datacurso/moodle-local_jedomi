# Changes

## 1.4.0 (2026-11-03)

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

## 1.3.0 (2026-10-28)

- Route every AI call (web chat, scheduled agents, agent preview, Telegram and CLI) through the Datacurso AI provider to `POST /jedomi/v1/chat/completions`, using the provider license, rate limits and the pseudonymised user of each call
- Require `aiprovider_datacurso` 1.7.0 (2026102900)
- Move the AI service settings (model, document-to-image service URL and max tokens) and the "Enabled" switch to the Jedomi settings page of the Datacurso AI provider (Site administration > AI > Datacurso plugins list > Settings); the values are still stored in `local_jedomi`, the max tokens are kept between 256 and 128000, and the General tab of the Jedomi settings links to that page
- Read the "Enabled" state from the Datacurso AI provider for the sidebar and file reading; the upgrade removes the old `enabled` setting of `local_jedomi` (the provider keeps its value)
- Show the provider's own message, in the user's language, when the Datacurso AI provider is disabled in Site administration > AI > AI providers (code `provider_disabled`), in the web chat, agent preview, Telegram, agents and CLI; nothing is sent to the AI service
- Send the `X-Plugin-*` headers (`local_jedomi`, release and build) with every AI call
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
- Remove the `http://localhost:8000` fallback of the document-to-image service: without a URL, reading PDFs answers that the service is not configured; fix a warning when the model omits the image format
- Fix the Profiler tab failing with "Table local_jedomi_query_log doesn't exist" and the agent name not being saved on sites that had Jedomi installed before 2026-09-02: the upgrade now creates the `local_jedomi_query_log` table and the `name` column of `local_jedomi_agents` when they are missing
- Remove dead code (`webhook_handler::handle_pending_action()`, `local_jedomi_get_all_ws()`) and unused strings; complete docblocks and translate the remaining Spanish comments to English

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
