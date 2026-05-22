# Tasks: Chat Proxy + Function Calling

> Based on: proposal--chat-proxy.md → spec--chat-proxy.md → design--chat-proxy.md
> Design doc extends skip_hmac to get_site_config (not in original proposal). Spec B5 says keep execute_parameters() unchanged. Tool tasks follow spec B5 approach.

---

## TASK GROUP 1: `classes/schema/table_descriptor.php` (CREATE)

### T1.1: Create namespace, class, and skeleton
- [ ] **File**: `classes/schema/table_descriptor.php` (CREATE)
- **Description**: Create the file with namespace `local_jedomi\schema`, class `table_descriptor` with static methods `describe()`, `search_tables()`, `clear_cache()`
- **Details**:
  - Namespace `local_jedomi\schema`
  - `defined('MOODLE_INTERNAL') || die();`
  - Static methods: `describe(string $tablename): array`, `search_tables(string $pattern): array`, `clear_cache(): bool`
  - Import `cache` and `cache_store` for cache operations
- **Ref**: design section 3, spec section A
- **Verify**: `rg "class table_descriptor" classes/schema/table_descriptor.php` → 1 match

### T1.2: Implement `describe()` — columns query
- [ ] **File**: `classes/schema/table_descriptor.php` (MODIFY)
- **Description**: Query `INFORMATION_SCHEMA.COLUMNS` for column metadata
- **Details**:
  ```sql
  SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
  ORDER BY ORDINAL_POSITION
  ```
  - Get DB name from `$CFG->dbname`
  - Use `$DB->get_recordset_sql()` (same pattern as `extract_schema.php:187-194`)
  - Build `columns` associative array: `column_name => ['type' => ..., 'nullable' => bool, 'default' => ?string, 'key' => ?string, 'extra' => ?string]`
- **Ref**: spec A1, design 3.1 step a-b, extract_schema.php:187-207
- **Verify**: `rg "INFORMATION_SCHEMA.COLUMNS" classes/schema/table_descriptor.php` → 1 match

### T1.3: Implement `describe()` — foreign keys query
- [ ] **File**: `classes/schema/table_descriptor.php` (MODIFY)
- **Description**: Query `INFORMATION_SCHEMA.KEY_COLUMN_USAGE` joined with `TABLE_CONSTRAINTS` for foreign keys
- **Details**:
  ```sql
  SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
  JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
   AND tc.TABLE_SCHEMA    = kcu.TABLE_SCHEMA
   AND tc.TABLE_NAME      = kcu.TABLE_NAME
  WHERE kcu.TABLE_SCHEMA           = ?
    AND kcu.TABLE_NAME             = ?
    AND tc.CONSTRAINT_TYPE         = 'FOREIGN KEY'
    AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
  ```
  - Use `$DB->get_recordset_sql()` (same as `extract_schema.php:210-222`)
  - Build `foreign_keys` array: `[['column' => ..., 'references_table' => ..., 'references_column' => ...]]`
- **Ref**: spec A1, design 3.1 step c, extract_schema.php:210-232
- **Verify**: `rg "KEY_COLUMN_USAGE" classes/schema/table_descriptor.php` → 1 match

### T1.4: Implement `describe()` — indices query
- [ ] **File**: `classes/schema/table_descriptor.php` (MODIFY)
- **Description**: Query `INFORMATION_SCHEMA.STATISTICS` for index metadata
- **Details**:
  ```sql
  SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
  ORDER BY INDEX_NAME, SEQ_IN_INDEX
  ```
  - Use `$DB->get_recordset_sql()`
  - Aggregate rows by `INDEX_NAME` into `indices` array:
    - `index_name => ['columns' => [col1, col2, ...], 'unique' => bool]`
- **Ref**: spec A1, design 3.1 step d
- **Verify**: `rg "INFORMATION_SCHEMA.STATISTICS" classes/schema/table_descriptor.php` → 1 match

### T1.5: Implement `describe()` — return structure + cache logic
- [ ] **File**: `classes/schema/table_descriptor.php` (MODIFY)
- **Description**: Assemble return array and add cache get/set with TTL 86400
- **Details**:
  ```php
  return [
      'tablename'    => $tablename,
      'columns'      => [...],
      'foreign_keys' => [...],
      'indices'      => [...],
  ];
  ```
  - Cache key: `desc_{$tablename}`
  - Cache store: `cache::make('local_jedomi', 'schema_cache')`
  - Check cache first → if hit, return immediately
  - On miss: query DB, `$cache->set($key, $result, 86400)`, return
  - If tablename not found → return `['error' => 'Table not found: {tablename}']`
- **Ref**: spec A1, spec A4 (cache behavior table)
- **Verify**: `rg "desc_" classes/schema/table_descriptor.php` → 1 match

### T1.6: Implement `search_tables()`
- [ ] **File**: `classes/schema/table_descriptor.php` (MODIFY)
- **Description**: LIKE query on `INFORMATION_SCHEMA.TABLES` with pattern matching
- **Details**:
  ```sql
  SELECT TABLE_NAME
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = ?
    AND TABLE_TYPE = 'BASE TABLE'
    AND TABLE_NAME LIKE ?
  ORDER BY TABLE_NAME
  ```
  - Wrap pattern: `'%' . $DB->sql_like_escape($pattern) . '%'`
  - Empty pattern guard: if `empty($pattern)` → return `['tables' => [], 'total' => 0]` immediately
  - Return structure: `['tables' => [string, ...], 'total' => int]`
  - Cache key: `search_{$pattern}` — TTL 86400
- **Ref**: spec A2, design 3.2
- **Verify**: `rg "search_" classes/schema/table_descriptor.php` → 1 match

### T1.7: Implement `clear_cache()`
- [ ] **File**: `classes/schema/table_descriptor.php` (MODIFY)
- **Description**: Purge the entire `schema_cache` definition
- **Details**:
  ```php
  $cache = cache::make('local_jedomi', 'schema_cache');
  $cache->purge();
  return true;
  ```
  - Catch potential `moodle_exception` if store unavailable → return `false`
- **Ref**: spec A3, design section 6
- **Verify**: `rg "clear_cache" classes/schema/table_descriptor.php` → 1 match

### T1.8: Handle edge cases
- [ ] **File**: `classes/schema/table_descriptor.php` (MODIFY)
- **Description**: Defensive edge case handling for both describe() and search_tables()
- **Details**:
  - `describe()`: if table doesn't exist → `['error' => 'Table not found: {tablename}']` (not an exception)
  - `describe()`: if table has no columns → return empty arrays (defensive, shouldn't happen)
  - `search_tables('')` → return 0 results immediately (dumping 500+ tables is never useful)
  - `search_tables('nonexistent')` → return 0 results gracefully
  - Cache store unavailable → fall through to DB query, log warning, return data
- **Ref**: spec A1 edge cases, spec A4 cache behavior
- **Verify**: Manual review of guard clauses

---

## TASK GROUP 2: `chatproxy.php` (CREATE)

### T2.1: Create endpoint with auth + JSON input parsing
- [ ] **File**: `chatproxy.php` (CREATE, root of plugin)
- **Description**: Create the main POST endpoint with method check, require_login, require_sesskey, JSON body parsing
- **Details**:
  - `require_once('../../config.php');`
  - Method check: POST only → 405 for anything else
  - `require_login(); require_sesskey();`
  - `file_get_contents('php://input')` → `json_decode($input, true)`
  - Extract `model`, `messages`, `spt` from body
  - Call `validate_spt($spt)` → get `$role`
  - Call `build_system_message($role, $spt)` → `$system`
  - Call `build_tools_for_role($role)` → `$tools`
  - Merge: `$messages = array_merge([$system], $messages)`
  - Set SSE headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`
  - `ob_implicit_flush(true);`
  - Call `tool_loop($model, $messages, $tools, $role, $spt);`
- **Ref**: spec C1, design 2.1, proposal section 6.1
- **Verify**: `rg "chatproxy.php" -g "*.php"` → file exists, `Test-Path chatproxy.php` → True

### T2.2: Implement SPT validation (signature + TTL + sesskey_hash)
- [ ] **File**: `chatproxy.php` (MODIFY)
- **Description**: Implement `validate_spt(array $spt): string` — validates signature via `hash_equals()`, TTL check, sesskey_hash binding
- **Details**:
  - Check sesskey_hash: `substr(hash('sha256', sesskey()), 0, 16)` vs `$spt['sesskey_hash']`
  - Check TTL: `time() < ($spt['expires'] ?? 0)` — 5 min TTL
  - Reconstruct signature: unset `signature`, `ksort($payload)`, `json_encode()` + `hash_hmac('sha256', $payload, $secret)`
  - Compare with `hash_equals($expected, $spt['signature'])`
  - On any failure: 403, JSON error body (NOT SSE — connection not yet established)
  - Return `$spt['role'] ?? 'student'` on success
- **Ref**: spec C2 (sesskey), spec C3 (SPT), design 2.3, proposal section 7
- **Verify**: `rg "function validate_spt" chatproxy.php` → 1 match

### T2.3: Implement tool definition functions (OpenAI format)
- [ ] **File**: `chatproxy.php` (MODIFY)
- **Description**: Create helper functions returning tool definition arrays in OpenAI format
- **Details**: Four functions returning `['type' => 'function', 'function' => [...]]`:
  - `describe_table_definition()` — name: `describe_table`, params: `tablename` (string, required)
  - `search_tables_definition()` — name: `search_tables`, params: `query` (string, required)
  - `execute_sql_definition()` — name: `execute_sql`, params: `sql` (string, required), `params` (array of strings, optional)
  - `get_site_config_definition()` — name: `get_site_config`, params: `keys` (array of strings, required)
- **Ref**: spec C5, proposal section 4.1-4.4, design section 11
- **Verify**: `rg "function.*_definition" chatproxy.php` → 4+ matches

### T2.4: Implement `build_tools_for_role()`
- [ ] **File**: `chatproxy.php` (MODIFY)
- **Description**: Return tool array based on role access matrix
- **Details**:
  - Student: only `describe_table` + `search_tables`
  - Teacher/Manager: + `execute_sql`
  - Admin: + `get_site_config`
  - Call `array_values($tools)` to return sequential array (not assoc)
- **Ref**: spec C5, design 11.5, proposal section 4.5
- **Verify**: `rg "function build_tools_for_role" chatproxy.php` → 1 match

### T2.5: Implement `call_ai_api_streaming()` — HTTP POST to AI API
- [ ] **File**: `chatproxy.php` (MODIFY)
- **Description**: Make HTTP POST to AI API with `stream: true`, read response as SSE stream
- **Details**:
  - GET `gateway_url` and `api_key` from `get_config('local_jedomi', ...)`
  - Build payload: `model`, `messages`, `tools`, `stream: true`
  - Use curl with `CURLOPT_WRITEFUNCTION` callback OR `CURLOPT_FILE` + `php://temp` approach (design 2.5 alternative)
  - **Recommended approach**: `fopen('php://temp', 'w+')` as `CURLOPT_FILE`, exec, rewind, `fgets()` line-by-line → yield each line
  - Timeout: 120s, ConnectTimeout: 10s
  - `Authorization: Bearer {api_key}`
  - Return `Generator` that yields raw SSE line strings
- **Ref**: design 2.5, spec C7, proposal section 6.1
- **Verify**: `rg "function call_ai_api_streaming" chatproxy.php` → 1 match

### T2.6: Implement tool-calling loop (max 5 iterations)
- [ ] **File**: `chatproxy.php` (MODIFY)
- **Description**: Main loop that reads SSE stream, detects tool_calls, executes tools, re-injects results
- **Details**:
  - Signature: `function tool_loop(string $model, array &$messages, array $tools, string $role, array $spt): void`
  - For `$i = 0; $i < 5; $i++`:
    - Call `call_ai_api_streaming($model, $messages, $tools)`
    - Iterate over generator, forward each raw SSE line to client via `echo` + `ob_flush()` + `flush()`
    - Parse `data: {...}` lines → detect `delta.tool_calls` in choices array
    - Accumulate streaming tool_calls arguments (multiple chunks per tool_call)
    - Accumulate content delta for context tracking
    - If finish_reason === 'tool_calls': collect all tool_calls, break streaming
    - If finish_reason === 'stop' and no tool_calls: break loop
    - Execute each tool_call via `execute_tool()`, inject result as `role: tool` message
    - Continue to next iteration
  - After loop exit without resolution → emit SSE error `{"error": "Max tool call iterations reached (5)"}`
  - Emit `data: [DONE]\n\n` at end
- **Ref**: spec C9, design 2.4, proposal section 5
- **Verify**: `rg "function tool_loop" chatproxy.php` → 1 match

### T2.7: Implement tool execution routing + per-tool functions
- [ ] **File**: `chatproxy.php` (MODIFY)
- **Description**: Dispatcher that routes tool names to specific execution functions
- **Details**:
  - `execute_tool(array $tool_call, string $role): string` — dispatches via `match` on `$tool_call['function']['name']`
  - `execute_sql_tool(array $args, string $role): string`
    - Role gate: must be admin/manager/teacher
    - Call `execute_sql::execute($sql, $params, '', 0, '', true)`
    - Wrap in try/catch, return error as JSON string on exception
  - `describe_table_tool(array $args): string`
    - Call `table_descriptor::describe($args['tablename'])`
    - Wrap in try/catch
  - `search_tables_tool(array $args): string`
    - Call `table_descriptor::search_tables($args['query'])`
    - Wrap in try/catch
  - `get_site_config_tool(array $args, string $role): string`
    - Role gate: must be admin
    - Call `get_site_config::execute($keys, '', 0, '', true)`
    - Wrap in try/catch
- **Ref**: spec C6, design 2.6-2.7, proposal section 5 step 6
- **Verify**: `rg "function execute_tool" chatproxy.php` → 1 match

### T2.8: Implement SSE passthrough + `_jedomi_tool_executing` event emission
- [ ] **File**: `chatproxy.php` (MODIFY)
- **Description**: SSE helper function and event emission during tool execution
- **Details**:
  - `sse_send(string $event, $data): void`:
    ```php
    $payload = json_encode(array_merge(['event' => $event], is_array($data) ? $data : ['data' => $data]));
    echo "data: {$payload}\n\n";
    ob_flush(); flush();
    ```
  - Before each tool execution: `sse_send('_jedomi_tool_executing', ['tool' => $name, 'args' => $args])`
  - After each tool execution: `sse_send('_jedomi_tool_result', ['tool' => $name, 'summary' => $summary])`
  - Regular content chunks: echo raw SSE line as-is (passthrough from AI API)
  - Tool result summary: first 200 chars of result, or `{n} rows` for SQL, or `{n} columns` for describe
- **Ref**: spec C8, design 10.1-10.3, proposal section 5 step 7
- **Verify**: `rg "function sse_send" chatproxy.php` → 1 match

### T2.9: Implement error handling
- [ ] **File**: `chatproxy.php` (MODIFY)
- **Description**: Error handling for all failure modes
- **Details**:
  - Non-POST: 405 text/plain
  - Invalid/missing JSON body: 400 JSON error
  - Invalid sesskey: 403 JSON error
  - Invalid/expired SPT: 403 JSON error
  - AI API connection failure: catch exception, emit SSE error event `{"event": "error", "data": "connection failed"}`
  - Tool execution error: catch exception, emit SSE error event, continue loop (don't break on tool failure)
  - Max iterations: emit SSE error event `{"error": "Max tool call iterations reached (5)"}`, then `[DONE]`
  - Unknown tool called: return JSON error as tool result, loop continues normally
- **Ref**: spec C10, proposal section 8
- **Verify**: Manual review of try/catch blocks and error responses

---

## TASK GROUP 3: `classes/external/execute_sql.php` (MODIFY)

### T3.1: Add `$skip_hmac` parameter to `execute()` signature
- [ ] **File**: `classes/external/execute_sql.php` (MODIFY)
- **Description**: Add `bool $skip_hmac = false` as 6th parameter
- **Details**:
  - **IMPORTANT**: `execute_parameters()` is NOT modified per spec B5. The `$skip_hmac` param is internal-only (used from direct PHP calls, not via WS).
  - **IMPORTANT**: `compact()` call on line 102-104 is NOT modified. The `$skip_hmac` value comes directly from the function parameter.
  - Docblock should document the new parameter
- **Ref**: spec B1, design 4.1
- **Verify**: `rg "bool \$skip_hmac = false" classes/external/execute_sql.php` → 1 match

### T3.2: Add guard around `verify_hmac()`
- [ ] **File**: `classes/external/execute_sql.php` (MODIFY)
- **Description**: Replace unconditional `self::verify_hmac()` call (line 113) with conditional
- **Details**:
  ```php
  if (!$skip_hmac) {
      self::verify_hmac('jedomi_execute_sql', $hmac_timestamp, $payload_hash, $hmac_signature);
  }
  ```
- **Ref**: spec B2, design 4.2
- **Verify**: `rg "if.*!skip_hmac" classes/external/execute_sql.php` → 1 match

### T3.3: Verify security checks remain active in both modes
- [ ] **File**: `classes/external/execute_sql.php` (VERIFY)
- **Description**: Ensure all other security checks are NOT inside the skip_hmac conditional
- **Details**: Verify that these all run regardless of `$skip_hmac`:
  - `require_capability('local/jedomi:companion_api', ...)` — line 99 ✅
  - `self::validate_context($context)` — line 98 ✅
  - `self::validate_parameters(...)` — lines 102-105 ✅
  - SELECT-only enforcement — lines 116-126 ✅
  - INTO OUTFILE/DUMPFILE check — lines 129-137 ✅
  - Statement timeout — lines 146-150 ✅
  - Row cap at MAX_ROWS + 1 — lines 152-164 ✅
  - Audit logging — lines 193-200 ✅
- **Ref**: spec B3
- **Verify**: `rg "verify_hmac|require_capability|INTO OUTFILE" classes/external/execute_sql.php`

---

## TASK GROUP 4: `classes/external/get_site_config.php` (MODIFY)

### T4.1: Add `$skip_hmac` parameter to `execute()` signature
- [ ] **File**: `classes/external/get_site_config.php` (MODIFY)
- **Description**: Add `bool $skip_hmac = false` as 5th parameter (after `$payload_hash`)
- **Details**:
  - Same pattern as execute_sql (T3.1)
  - `execute_parameters()` is NOT modified
  - `compact()` call is NOT modified
- **Ref**: design 5.2
- **Verify**: `rg "bool \$skip_hmac = false" classes/external/get_site_config.php` → 1 match

### T4.2: Add guard around `verify_hmac()`
- [ ] **File**: `classes/external/get_site_config.php` (MODIFY)
- **Description**: Replace unconditional `self::verify_hmac()` call (line 107) with conditional
- **Details**:
  ```php
  if (!$skip_hmac) {
      self::verify_hmac('jedomi_get_site_config', $hmac_timestamp, $payload_hash, $hmac_signature);
  }
  ```
- **Ref**: design 5.3
- **Verify**: `rg "if.*!skip_hmac" classes/external/get_site_config.php` → 1 match

---

## TASK GROUP 5: `db/caches.php` (MODIFY)

### T5.1: Add `schema_cache` definition
- [ ] **File**: `db/caches.php` (MODIFY)
- **Description**: Add `schema_cache` to the `$definitions` array
- **Details**: Insert after `telegram_link_codes` definition (before closing `];`):
  ```php
  'schema_cache' => [
      'mode'       => cache_store::MODE_APPLICATION,
      'simplekeys' => true,
      'simpledata' => true,
      'ttl'        => 86400,    // 24 hours
  ],
  ```
- **Ref**: spec D1, design section 6, proposal section 6.4
- **Verify**: `rg "schema_cache" db/caches.php` → 1 match

---

## TASK GROUP 6: `db/upgrade.php` (MODIFY)

### T6.1: Add upgrade step for version 2026051409
- [ ] **File**: `db/upgrade.php` (MODIFY)
- **Description**: Add upgrade block that purges the schema_cache
- **Details**: Insert after line 81 (after the existing `$oldversion < 2026051401` block, before `return true`):
  ```php
  if ($oldversion < 2026051409) {
      \cache_helper::purge_by_event('local_jedomi_schema_changed');

      upgrade_plugin_savepoint(true, 2026051409, 'local', 'jedomi');
  }
  ```
- **Ref**: spec D2, design section 7, proposal section 6.5
- **Verify**: `rg "2026051409" db/upgrade.php` → 2 matches

---

## TASK GROUP 7: `version.php` (MODIFY)

### T7.1: Bump version to 2026051409
- [ ] **File**: `version.php` (MODIFY)
- **Description**: Update `$plugin->version` from `2026051408` to `2026051409`
- **Details**: Update the version line and comment:
  ```php
  $plugin->version   = 2026051409; // + chat proxy + function calling + schema_cache
  ```
- **Ref**: spec D3, design section 8, proposal section 6.6
- **Verify**: `rg "2026051409" version.php` → 1 match

---

## TASK GROUP 8: `lib.php` (MODIFY)

### T8.1: Add `chatproxy_url` to JS config
- [ ] **File**: `lib.php` (MODIFY)
- **Description**: Add `'chatproxy_url'` key to the `$config` array in `local_jedomi_before_footer()`
- **Details**: Insert after `'model'` on line 59, before `'user_name'` on line 60:
  ```php
  'chatproxy_url' => $CFG->wwwroot . '/local/jedomi/chatproxy.php',
  ```
- **Ref**: spec D4, design section 9, proposal section 6.7
- **Verify**: `rg "chatproxy_url" lib.php` → 1 match

---

## VERIFICATION CHECKLIST

### Integration Tests
- [ ] POST to `chatproxy.php` with valid sesskey+SPT returns SSE stream with `Content-Type: text/event-stream`
- [ ] POST with invalid sesskey returns 403 JSON error
- [ ] POST with expired SPT returns 403 JSON error
- [ ] POST with tampered SPT signature returns 403 JSON error
- [ ] Non-POST request returns 405
- [ ] Missing/invalid JSON body returns 400
- [ ] Admin sees all 4 tools in tool definitions
- [ ] Manager/Teacher sees 3 tools (no `get_site_config`)
- [ ] Student sees only 2 tools (`describe_table`, `search_tables`)
- [ ] tool_call intercepted and executed via direct PHP method call (no HTTP)
- [ ] `_jedomi_tool_executing` SSE event sent before each tool execution
- [ ] `_jedomi_tool_result` SSE event sent after each tool execution
- [ ] Max 5 iterations enforced (error event on 6th)
- [ ] `[DONE]` sent at end of successful stream
- [ ] `[DONE]` sent after error termination
- [ ] AI API connection failure → SSE error event

### Table Descriptor Tests
- [ ] `describe('course')` returns columns with type, nullable, default, key, extra
- [ ] `describe('course')` includes foreign_keys array
- [ ] `describe('course')` includes indices array with column list + unique flag
- [ ] `describe('nonexistent_table')` returns error array, not exception
- [ ] `search_tables('course')` returns tables containing 'course'
- [ ] `search_tables('')` returns 0 results (empty pattern guard)
- [ ] `search_tables('xyznonexistent')` returns 0 results
- [ ] `clear_cache()` purges all schema_cache entries
- [ ] Cache hit: subsequent `describe('course')` returns cached data (zero DB queries)
- [ ] Cache miss: queries INFORMATION_SCHEMA, caches, returns data
- [ ] No PII (email, passwords, grades) in any output

### Backward Compatibility Tests
- [ ] `execute_sql::execute()` with 5 params (no `skip_hmac`) still verifies HMAC
- [ ] `execute_sql::execute()` with `skip_hmac=true` skips HMAC verification
- [ ] `get_site_config::execute()` with 4 params (no `skip_hmac`) still verifies HMAC
- [ ] `get_site_config::execute()` with `skip_hmac=true` skips HMAC verification
- [ ] Both functions: SELECT-only enforcement active in both modes
- [ ] Both functions: INTO OUTFILE/DUMPFILE check active in both modes
- [ ] Both functions: capability check active in both modes
- [ ] `execute_parameters()` unchanged for both functions (WS API backward compat)
- [ ] Existing WS calls unaffected (gateway Python still works)

### Config Files
- [ ] `schema_cache` definition present in `db/caches.php`
- [ ] Upgrade step 2026051409 present in `db/upgrade.php`
- [ ] `version.php` bumped to `2026051409`
- [ ] `chatproxy_url` present in `lib.php` config array
