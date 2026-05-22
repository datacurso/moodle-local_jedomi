# Verification Report: Chat Proxy + Function Calling

## Critical Issues

- **Unused `$spt` param in `tool_loop()`** — `chatproxy.php:220` declares `array $spt` as a parameter to `tool_loop()` but it is never referenced inside the function body. This is dead weight; the function only needs `$role`.

## Warnings

- **Double `[DONE]` emission on edge case** — When the AI stream ends with a bare `data: [DONE]` (without a preceding `finish_reason: stop` chunk), the proxy forwards the AI's `[DONE]` verbatim (`chatproxy.php:229`) AND emits its own `[DONE]` at `chatproxy.php:304`. Most AI APIs send `finish_reason: stop` before `[DONE]`, so this edge case is unlikely in practice. The JS consumer ignores the second `[DONE]`.
- **`compact()` does not pass `$skip_hmac`** — `execute_sql.php:106` and `get_site_config.php:100` call `compact('sql', 'params', 'hmac_signature', 'hmac_timestamp', 'payload_hash')` without `skip_hmac`. This is correct because `execute_parameters()` doesn't declare `skip_hmac`, but it means `$skip_hmac` bypasses `validate_parameters()` type coercion. PHP's native `bool` type hint on the function signature mitigates this (PHP 8.0+ enforces it).

## Suggestions

- Remove `$spt` from `tool_loop()` signature and its docblock — it's unused inside the function.
- Add `@param bool $skip_hmac` docblock to `get_site_config::execute()` for consistency with `execute_sql::execute()`.

## Acceptance Criteria Status

### Spec A: table_descriptor.php

| # | Criterion | Status |
|---|-----------|--------|
| A1 | `describe()` returns columns with type, nullable, default, key, extra | ✅ PASS — `table_descriptor.php:44-61` |
| A2 | `describe()` includes foreign_keys array with column + references_table + references_column | ✅ PASS — `table_descriptor.php:71-93` |
| A3 | `describe()` includes indices array with column list + unique flag | ✅ PASS — `table_descriptor.php:96-116` |
| A4 | `describe()` with non-existent table returns error array, not exception | ✅ PASS — `table_descriptor.php:66-68` |
| A5 | `search_tables('course')` returns all tables containing 'course' | ✅ PASS — `table_descriptor.php:155-163` uses LIKE |
| A6 | `search_tables('')` returns 0 results (empty pattern guard) | ✅ PASS — `table_descriptor.php:140-142` |
| A7 | `search_tables()` returns 0 results when no tables match | ✅ PASS — returns `['tables' => [], 'total' => 0]` |
| A8 | `clear_cache()` purges all schema_cache entries | ✅ PASS — `table_descriptor.php:186-194` |
| A9 | Cache hit: subsequent `describe('course')` returns cached data, zero DB | ✅ PASS — `table_descriptor.php:36-39` |
| A10 | Cache miss: queries INFORMATION_SCHEMA, caches, returns data | ✅ PASS — `table_descriptor.php:44-127` |
| A11 | No PII (email, password, grades, user data) returned | ✅ PASS — only schema metadata, no row data |
| A12 | Cache store unavailable: falls through to DB, logs warning | ⚠️ PARTIAL — no explicit `log warning` fallback when cache store is unavailable; `cache::make()` throws exception if store unavailable |

### Spec B: execute_sql $skip_hmac

| # | Criterion | Status |
|---|-----------|--------|
| B1 | Existing WS calls with 5 params work exactly as before | ✅ PASS — `$skip_hmac=false` default, `verify_hmac` called |
| B2 | Calls with `skip_hmac=true` skip verify_hmac entirely | ✅ PASS — `execute_sql.php:115-117` |
| B3 | SELECT-only enforcement active in both modes | ✅ PASS — `execute_sql.php:120-130` (after guard) |
| B4 | INTO OUTFILE/DUMPFILE check active in both modes | ✅ PASS — `execute_sql.php:133-141` |
| B5 | Capability check active in both modes | ✅ PASS — `execute_sql.php:100-101` |
| B6 | Parameter validation active in both modes | ✅ PASS — `execute_sql.php:104-112` |
| B7 | Statement timeout active in both modes | ✅ PASS — `execute_sql.php:150-154` |
| B8 | Row cap (100 rows) active in both modes | ✅ PASS — `execute_sql.php:158` |
| B9 | `execute_parameters()` unchanged (WS API still expects HMAC params) | ✅ PASS — `execute_sql.php:46-58`, no `skip_hmac` |

### Spec C: chatproxy.php

| # | Criterion | Status |
|---|-----------|--------|
| C1 | POST with valid sesskey+SPT returns SSE stream | ✅ PASS — `chatproxy.php:17-58` |
| C2 | Tools declared match user's role (admin: 4 tools, manager/teacher: 3 tools, student: 2 tools) | ✅ PASS — `chatproxy.php:447-535` |
| C3 | Invalid/missing sesskey returns 403 | ✅ PASS — `chatproxy.php:73-77` |
| C4 | Invalid SPT signature returns 403 | ✅ PASS — `chatproxy.php:93-97` |
| C5 | Expired SPT returns 403 | ✅ PASS — `chatproxy.php:80-84` |
| C6 | Non-POST request returns 405 | ✅ PASS — `chatproxy.php:17-21` |
| C7 | Missing/invalid JSON body returns 400 | ✅ PASS — `chatproxy.php:27-31` |
| C8 | Tool call intercepted, executed, result injected, loop continues | ✅ PASS — `chatproxy.php:278-301` |
| C9 | Max 5 tool iterations enforced | ✅ PASS — `chatproxy.php:221` |
| C10 | `{"_jedomi_tool_executing":"tool_name"}` event sent before each tool | ✅ PASS — `chatproxy.php:283-286` |
| C11 | `{"_jedomi_tool_result":"..."}` event sent after each tool | ✅ PASS — `chatproxy.php:291-294` |
| C12 | Content tokens streamed transparently when no tool call | ✅ PASS — `chatproxy.php:229` echoes raw SSE |
| C13 | Error during tool execution returns SSE error event, loop continues | ✅ PASS — `chatproxy.php:372-374` catch + return |
| C14 | `[DONE]` sent at end of successful stream | ✅ PASS — `chatproxy.php:304` |
| C15 | `[DONE]` sent after error termination | ✅ PASS — after for loop, `chatproxy.php:304` |
| C16 | Role permission checked at dispatch level (defense-in-depth) | ✅ PASS — `chatproxy.php:359-361` (`execute_sql_tool`), `chatproxy.php:415-417` (`get_site_config_tool`) |

### Spec D: Cache + Upgrade + Config

| # | Criterion | Status |
|---|-----------|--------|
| D1 | `schema_cache` in caches.php: MODE_APPLICATION, simplekeys, simpledata, TTL=86400 | ✅ PASS — `db/caches.php:34-39` |
| D2 | Upgrade step purges schema_cache on version 2026051409 | ✅ PASS — `db/upgrade.php:83-89` |
| D3 | version.php bumped to 2026051409 | ✅ PASS — `version.php:5` |
| D4 | `chatproxy_url` config key sent to JS AMD module | ✅ PASS — `lib.php:60` |

### Global Acceptance Criteria

| # | Criterion | Status |
|---|-----------|--------|
| G1 | No business logic in proxy (only orchestration) | ✅ PASS |
| G2 | No DB table changes | ✅ PASS — only cache definition + upgrade purge |
| G3 | No sidebar.js changes | ✅ PASS — zero occurrences of `chatproxy_url` in sidebar.js |
| G4 | No HMAC changes to other external functions (except execute_sql + get_site_config) | ✅ PASS |
| G5 | System prompt untouched (`generate_spt()`/`$spt` in lib.php) | ✅ PASS — `lib.php:434-500` unchanged |
| G6 | SSE format compatible with existing JS consumer | ✅ PASS — standard `chat.completion.chunk` passthrough |
| G7 | No PII exposed | ✅ PASS |
| G8 | Backward compatible (existing WS calls unchanged) | ✅ PASS — `execute_parameters()` unchanged, `$skip_hmac=false` default |
| G9 | Defense-in-depth (capability check + role check) | ✅ PASS |

## Counts Verification

| Check | Expected | Actual | Status |
|-------|----------|--------|--------|
| `skip_hmac` in execute_sql.php | 1+ occurrences | 3 (doc, sig, guard) | ✅ |
| `skip_hmac` in get_site_config.php | 1+ occurrences | 2 (sig, guard) | ✅ |
| `chatproxy_url` in lib.php | 1 | 1 | ✅ |
| `chatproxy_url` in sidebar.js | 0 | 0 | ✅ |
| `2026051409` in upgrade.php | 1+ | 2 (cond, savepoint) | ✅ |
| version `2026051409` in version.php | 1 | 1 | ✅ |
| `class table_descriptor` | 1 | 1 | ✅ |
| `chatproxy.php` exists | Yes | Yes | ✅ |
| `function validate_spt` | 1 | 1 | ✅ |
| `function build_tools_for_role` | 1 | 1 | ✅ |
| `function tool_loop` | 1 | 1 | ✅ |
| `function execute_tool` | 1 | 1 | ✅ |
| `function call_ai_api_streaming` | 1 | 1 | ✅ |
| `function sse_send` | 1 | 1 | ✅ |
| `INFORMATION_SCHEMA.COLUMNS` query | 1+ | 1 | ✅ |
| `INFORMATION_SCHEMA.KEY_COLUMN_USAGE` query | 1+ | 1 | ✅ |
| `INFORMATION_SCHEMA.STATISTICS` query | 1+ | 1 | ✅ |

## Summary

- **Total acceptance criteria:** 41
- **Passed:** 40
- **Failed:** 0
- **Partial:** 1 (A12: cache store unavailable — no explicit warning log)
- **Warnings:** 2
- **Critical Issues:** 1 (unused `$spt` param in `tool_loop()`)
