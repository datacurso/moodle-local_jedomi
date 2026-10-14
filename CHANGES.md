# Changes

## 1.3.0 (2026-10-14)

- Add optional Datacurso AI backend: new `ai_backend` setting (gateway | datacurso) and `datacurso_model` setting; the gateway remains the default and is used whenever the provider is missing or has no license key
- Add `local_jedomi\ai` client layer (`client`, `gateway_backend`, `datacurso_backend`, `sse_parser`, `curl_transport`) replacing 7 duplicated cURL blocks across the chat proxy, agent runner, agent preview, Telegram webhook and CLI
- Stop exposing the gateway URL and API key in the sidebar config when the Datacurso backend is active; the license key is never sent to the browser
- Hide gateway-only sidebar features (voice input, file extraction, action confirmation, direct messages, feedback) when the Datacurso backend is active
- Honour the `max_tokens` setting in web chat, Telegram and CLI (previously hardcoded to 16384)
- Remove the `192.168.1.28:8080` development gateway fallback and the hardcoded default model in the CLI
- Surface `data: {"error"}` SSE chunks as errors instead of silently ignoring them
- Send `userid` to the Datacurso service as a string so the token-manager licence check accepts it
- Preserve vendor keys on streamed tool_calls (e.g. Gemini `extra_content.thought_signature`) and echo them back in the agent preview so Gemini tool loops work
- Fix parallel tool_calls streamed without `index` (Gemini OpenAI-compatible endpoint) being merged into a single call by `sse_parser`; entries are now keyed by `id` when `index` is absent, so every parallel call keeps its own id and arguments
- Bump plugin version to 2026101400

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
