# Changes

## 1.2.4 (2026-09-10)

- Add German (de), French (fr), Indonesian (id), European Portuguese (pt), Brazilian Portuguese (pt_br), and Russian (ru) language packs, each translated against the official Moodle 4.5 language pack terminology for that locale
- Fix 8 `lang/en` strings that contained Spanish text instead of English, and a Spanish error-message fragment embedded in `tool_ws_execute_params`
- Remove duplicate dead string definitions (`tool_ws_search_desc`, `tool_ws_describe_desc`) from `lang/en` and `lang/es`
- Complete the 8 missing Admin CLI tool strings in `lang/es`
- Fix Moodle Code Checker violations across all 8 language files: add the missing GPL boilerplate and file docblock, reorder all 552 string keys alphabetically, and remove the decorative section comments that blocked automatic order validation
- Bump plugin version to 2026091100

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
