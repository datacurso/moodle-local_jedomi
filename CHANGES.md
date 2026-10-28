# Changes

## 1.3.0 (2026-10-28)

- Route every AI call (web chat, scheduled agents, agent preview, Telegram and CLI) through the Datacurso AI provider to `POST /jedomi/v1/chat/completions`, using the provider license, rate limits and the pseudonymised user of each call
- Require `aiprovider_datacurso` 1.7.0 (2026102700)
- Replace the free-text model setting with a list of priced models; the default is `jedomi/google/gemma-4-26B-A4B-it`
- Apply the `max_tokens` setting to every channel
- Remove the `gateway_url` and `api_key` settings; the upgrade deletes them and replaces a model without the `jedomi/` prefix with the default one
- Ignore the model sent by the browser to the web chat proxy
- Show clear messages in English and Spanish for no credit, rate limit (with retry time), missing or invalid license, unavailable service and other AI errors in the web chat, agent preview, Telegram, agents and CLI
- Store answer ratings in Moodle (`local_jedomi_feedback`, web service `local_jedomi_save_feedback`) and add a privacy provider for them
- Stop sending the gateway URL and API key to the browser; remove the browser calls to the legacy gateway for voice transcription, action confirmation, message sending and feedback
- Disable the "File" attach option with a notice, since document text extraction is not available
- Stop sending the SPT to the AI service from Telegram
- Show a timeout message and stop resending the Telegram request to the AI service when a call times out, so it is not billed again
- Fix the agent preview and scheduled agents sending tool results without the assistant tool calls that precede them
- Fix an invalid regular expression in the cleanup of the scheduled agent answer
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
