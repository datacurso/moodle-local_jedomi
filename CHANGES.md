# Changes

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
- Show a timeout message and stop resending the Telegram request to the AI service when a call times out, so it is not billed again
- Fix the agent preview and scheduled agents sending tool results without the assistant tool calls that precede them
- Fix an invalid regular expression in the cleanup of the scheduled agent answer
- Render only web and site-relative links in chat answers and escape quotes, so model output cannot inject `javascript:` or `data:` links or HTML attributes
- Log status codes and sizes instead of AI request and response contents
- Remove hardcoded default API keys and gateway URLs

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
