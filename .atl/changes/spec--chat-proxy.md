# Spec: Chat Proxy + Function Calling

## Spec A — `table_descriptor.php`

**File:** `classes/schema/table_descriptor.php`
**Namespace:** `local_jedomi\schema`
**Cache definition:** `schema_cache` in `db/caches.php`

### A1 — `describe(string $tablename): array`

Query INFORMATION_SCHEMA with the current DB name from `$CFG->dbname`.

**Columns query:**
```sql
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA
  FROM INFORMATION_SCHEMA.COLUMNS
 WHERE TABLE_SCHEMA = ?
   AND TABLE_NAME   = ?
 ORDER BY ORDINAL_POSITION
```

**Foreign keys query:**
```sql
SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
  JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
       ON tc.CONSTRAINT_NAME   = kcu.CONSTRAINT_NAME
      AND tc.TABLE_SCHEMA      = kcu.TABLE_SCHEMA
      AND tc.TABLE_NAME        = kcu.TABLE_NAME
 WHERE kcu.TABLE_SCHEMA           = ?
   AND kcu.TABLE_NAME             = ?
   AND tc.CONSTRAINT_TYPE         = 'FOREIGN KEY'
   AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
```

**Indices query:**
```sql
SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
  FROM INFORMATION_SCHEMA.STATISTICS
 WHERE TABLE_SCHEMA = ?
   AND TABLE_NAME   = ?
 ORDER BY INDEX_NAME, SEQ_IN_INDEX
```

**Return structure:**
```php
[
    'tablename'   => string,
    'columns'     => [
        column_name => [
            'type'     => string,   // e.g. "varchar(100)", "bigint(10)"
            'nullable' => bool,
            'default'  => ?string,
            'key'      => ?string,  // "PRI" | "UNI" | "MUL" | null
            'extra'    => ?string,  // "auto_increment" | null
        ],
    ],
    'foreign_keys' => [
        [
            'column'            => string,
            'references_table'  => string,
            'references_column' => string,
        ],
    ],
    'indices' => [
        index_name => [
            'columns'    => [string, ...],
            'unique'     => bool,
        ],
    ],
]
```

**Edge cases:**
- If `$tablename` does not exist in INFORMATION_SCHEMA: return `['error' => 'Table not found: {tablename}']`
- If table exists but has no columns (shouldn't happen, but defend): return `['tablename' => $tablename, 'columns' => [], 'foreign_keys' => [], 'indices' => []]`
- Empty indices array if no indices exist
- Empty foreign_keys array if no FK constraints exist

**Cache key:** `desc_{$tablename}` — TTL 86400s (24h)

### A2 — `search_tables(string $pattern): array`

**Query:**
```sql
SELECT TABLE_NAME
  FROM INFORMATION_SCHEMA.TABLES
 WHERE TABLE_SCHEMA = ?
   AND TABLE_NAME LIKE ?
 ORDER BY TABLE_NAME
```

The `$pattern` param is wrapped: `'%' . $DB->sql_like_escape($pattern) . '%'`.

**Return:**
```php
[
    'tables' => [string, string, ...],  // matching table names
    'total'  => int,                      // count of matches
]
```

**Edge cases:**
- Empty pattern (`''` or `null`): return `['tables' => [], 'total' => 0]` (no reason to dump all 500+ tables)
- Pattern matching zero tables: return `['tables' => [], 'total' => 0]`

**Cache key:** `search_{$pattern}` — TTL 86400s (24h)

### A3 — `clear_cache(): bool`

Purge all schema_cache entries:
```php
$cache = \cache::make('local_jedomi', 'schema_cache');
$cache->purge();
return true;
```

Return `true` on success, `false` if cache store is unavailable.

### A4 — Cache behavior

| Condition | Behavior |
|-----------|----------|
| Cache hit for `desc_{$tablename}` | Return cached array immediately, zero DB queries |
| Cache miss for `desc_{$tablename}` | Query INFORMATION_SCHEMA, cache result with TTL 86400, return data |
| Cache hit for `search_{$pattern}` | Return cached array |
| Cache miss for `search_{$pattern}` | Query INFORMATION_SCHEMA, cache result with TTL 86400, return data |
| Cache store unavailable | Fall through to DB query, log warning, return data |
| `clear_cache()` called | Purge entire schema_cache definition |

### A5 — No PII exposure

All return values contain only:
- Table names
- Column names
- Data types (e.g. `varchar(100)`, `bigint(10)`)
- Nullability flags
- Key/constraint metadata
- Index information

No row data is ever queried or returned. No user data, no grades, no emails.

### Acceptance Criteria (Spec A)

- [ ] `describe()` returns columns with type, nullable, default, key, extra for every column
- [ ] `describe()` includes foreign_keys array with column + references_table + references_column
- [ ] `describe()` includes indices array with column list + unique flag
- [ ] `describe()` with non-existent table returns error array, not exception
- [ ] `search_tables('course')` returns all tables containing 'course' in name
- [ ] `search_tables('')` returns 0 results (empty pattern guard)
- [ ] `search_tables()` returns 0 results when no tables match
- [ ] `clear_cache()` purges all schema_cache entries
- [ ] Cache hit: subsequent `describe('course')` returns cached data, zero DB queries
- [ ] Cache miss: queries INFORMATION_SCHEMA, caches, returns data
- [ ] No PII (email, password, grades, user data) returned in any output
- [ ] Cache store unavailable: falls through to DB, returns data, logs warning

---

## Spec B — `execute_sql.php` `$skip_hmac`

**File:** `classes/external/execute_sql.php`

### B1 — Add parameter

Add `bool $skip_hmac = false` as 6th parameter of `execute()`:

```php
public static function execute(
    string $sql,
    array  $params,
    string $hmac_signature,
    int    $hmac_timestamp,
    string $payload_hash,
    bool   $skip_hmac = false   // ← NEW
): array {
```

### B2 — Guard verify_hmac

Replace line 113 with conditional:

```php
if (!$skip_hmac) {
    self::verify_hmac('jedomi_execute_sql', $hmac_timestamp, $payload_hash, $hmac_signature);
}
```

### B3 — Preserve all other security

The following checks remain active in BOTH modes (skip_hmac=true AND skip_hmac=false):

| Check | Location | Always active? |
|-------|----------|----------------|
| `require_capability('local/jedomi:companion_api', ...)` | Line 99 | ✅ |
| `self::validate_context($context)` | Line 98 | ✅ |
| `self::validate_parameters(...)` | Lines 102-110 | ✅ |
| SELECT-only enforcement (first token check) | Lines 116-126 | ✅ |
| INTO OUTFILE/DUMPFILE exfiltration check | Lines 129-137 | ✅ |
| Statement timeout (MariaDB max_statement_time) | Lines 146-150 | ✅ |
| Row cap at MAX_ROWS + 1 | Line 154 | ✅ |
| Audit logging (SQL hash, row count, timing) | Lines 193-200 | ✅ |

### B4 — Backward compatibility

Default value `false` guarantees zero behavior change for existing callers:
- Gateway Python calls via WS: `execute_sql::execute($sql, $params, $hmac, $ts, $hash)` → $skip_hmac=false → verify_hmac called
- Proxy PHP calls: `execute_sql::execute($sql, $params, '', 0, '', true)` → $skip_hmac=true → verify_hmac skipped

### B5 — No parameter definition change

`execute_parameters()` is NOT modified. The `$skip_hmac` parameter is kept internal — it's only used when calling from PHP directly. The external WS definition stays unchanged (the gateway still sends all HMAC params).

### Scenarios

#### B-SC1: Existing Gateway call
- **Precondition:** Gateway Python calls `jedomi_execute_sql` via WS
- **Action:** `execute()` receives 5 params (no `$skip_hmac`)
- **Expected:** $skip_hmac=false (default), verify_hmac executes, security enforced as before

#### B-SC2: Proxy call with skip_hmac
- **Precondition:** chatproxy.php calls `execute_sql::execute($sql, $params, '', 0, '', true)`
- **Action:** `execute()` receives $skip_hmac=true
- **Expected:** verify_hmac skipped, all other security checks still run

#### B-SC3: Proxy call with non-SELECT SQL
- **Precondition:** chatproxy.php calls execute_sql with DML SQL (e.g. DELETE)
- **Action:** SELECT-only check runs
- **Expected:** Throws moodle_exception regardless of skip_hmac

#### B-SC4: Proxy call without capability
- **Precondition:** User without `local/jedomi:companion_api` calls via proxy
- **Action:** capability check runs
- **Expected:** Throws moodle_exception regardless of skip_hmac

### Acceptance Criteria (Spec B)

- [ ] Existing WS calls with 5 params work exactly as before (verify_hmac called, backward compat)
- [ ] Calls with `skip_hmac=true` skip verify_hmac entirely
- [ ] SELECT-only enforcement active in both modes
- [ ] INTO OUTFILE/DUMPFILE check active in both modes
- [ ] Capability check active in both modes
- [ ] Parameter validation active in both modes
- [ ] Statement timeout active in both modes
- [ ] Row cap (100 rows) active in both modes
- [ ] `execute_parameters()` unchanged (WS API still expects HMAC params)

---

## Spec C — `chatproxy.php`

**File:** `chatproxy.php` (root of plugin, `local/jedomi/chatproxy.php`)

### C1 — Endpoint

- Receives POST only. Any other method → 405 Method Not Allowed.
- Content-Type: `application/json`
- Reads `php://input`, decodes JSON.
- Expected body:
  ```json
  {
    "model": "string",
    "messages": [{"role": "...", "content": "..."}],
    "spt": {"signature": "...", "expires": 12345, "sesskey_hash": "...", "role": "admin", ...}
  }
  ```

### C2 — Session validation

```php
function validate_sesskey(array $spt): void {
    $hash = substr(hash('sha256', sesskey()), 0, 16);
    if (!hash_equals($hash, $spt['sesskey_hash'] ?? '')) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'invalid_session']));
    }
}
```

On failure: 403, JSON error body (not SSE — connection is still being established).

### C3 — SPT signature validation

```php
function validate_spt(array $spt): string {
    // 1. Check TTL
    if (time() >= ($spt['expires'] ?? 0)) {
        http_response_code(403);
        die(json_encode(['error' => 'token_expired']));
    }

    // 2. Reconstruct and verify signature
    $payload = $spt;
    unset($payload['signature']);
    ksort($payload);
    $static_secret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';
    $to_sign = json_encode($payload);
    $expected = hash_hmac('sha256', $to_sign, $static_secret);

    if (!hash_equals($expected, $spt['signature'] ?? '')) {
        http_response_code(403);
        die(json_encode(['error' => 'invalid_signature']));
    }

    return $spt['role'] ?? 'student';
}
```

On failure: 403, JSON error body.

### C4 — Role determination

Read `$spt['role']` directly — already resolved by `local_jedomi_get_user_role()` during SPT generation.

Valid roles: `admin`, `manager`, `teacher`, `student`.

### C5 — Build tools array

```php
function build_tools_for_role(string $role): array {
    $tools = [
        'describe_table' => [
            'type' => 'function',
            'function' => [
                'name' => 'describe_table',
                'description' => 'Obtiene la estructura (columnas, tipos, claves, índices, foreign keys) de una tabla de la base de datos de Moodle. Cacheado 24h.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tablename' => [
                            'type' => 'string',
                            'description' => 'Nombre de la tabla (ej: course, user, grade_grades)',
                        ],
                    ],
                    'required' => ['tablename'],
                ],
            ],
        ],
        'search_tables' => [
            'type' => 'function',
            'function' => [
                'name' => 'search_tables',
                'description' => 'Busca tablas en la base de datos por nombre. Devuelve lista de tablas que contienen el texto buscado. Cacheado 24h.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Texto a buscar en el nombre de la tabla (ej: grade, user, course)',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ],
    ];

    if (in_array($role, ['admin', 'manager', 'teacher'])) {
        $tools['execute_sql'] = [
            'type' => 'function',
            'function' => [
                'name' => 'execute_sql',
                'description' => 'Ejecuta una consulta SELECT en la base de datos de Moodle y devuelve las filas como JSON. SOLO lectura. Límite: 100 filas, timeout 5 segundos.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => [
                            'type' => 'string',
                            'description' => 'Consulta SELECT a ejecutar. Usá ? como placeholder para parámetros. Ejemplo: SELECT id, fullname FROM {course} WHERE category = ?',
                        ],
                        'params' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Parámetros vinculados para la consulta (opcional)',
                        ],
                    ],
                    'required' => ['sql'],
                ],
            ],
        ];
    }

    if ($role === 'admin') {
        $tools['get_site_config'] = [
            'type' => 'function',
            'function' => [
                'name' => 'get_site_config',
                'description' => 'Obtiene configuración del sitio Moodle (sitename, lang, timezone, etc.). Solo disponible para administradores.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'keys' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Lista de claves de configuración. Válidas: sitename, siteurl, lang, country, timezone, maxbytes, passwordpolicy, enrol_plugins_enabled, auth_plugins_enabled',
                        ],
                    ],
                    'required' => ['keys'],
                ],
            ],
        ];
    }

    return array_values($tools);
}
```

### C6 — Tool execution dispatch

```php
function execute_tool(array $tool_call, string $role): string {
    $name = $tool_call['function']['name'];
    $args = json_decode($tool_call['function']['arguments'] ?? '{}', true);

    return match ($name) {
        'execute_sql' => execute_sql_tool($args, $role),
        'describe_table' => describe_table_tool($args),
        'search_tables' => search_tables_tool($args),
        'get_site_config' => get_site_config_tool($args, $role),
        default => json_encode(['error' => "Unknown tool: {$name}"]),
    };
}

function execute_sql_tool(array $args, string $role): string {
    if (!in_array($role, ['admin', 'manager', 'teacher'])) {
        return json_encode(['error' => 'Permission denied']);
    }
    $result = \local_jedomi\external\execute_sql::execute(
        $args['sql'] ?? '',
        $args['params'] ?? [],
        '', 0, '',  // HMAC params — empty, skip_hmac=true
        true         // skip_hmac
    );
    return json_encode($result);
}

function describe_table_tool(array $args): string {
    $result = \local_jedomi\schema\table_descriptor::describe($args['tablename'] ?? '');
    return json_encode($result);
}

function search_tables_tool(array $args): string {
    $result = \local_jedomi\schema\table_descriptor::search_tables($args['query'] ?? '');
    return json_encode($result);
}

function get_site_config_tool(array $args, string $role): string {
    if ($role !== 'admin') {
        return json_encode(['error' => 'Permission denied']);
    }
    // Only return whitelisted keys
    $allowed = ['sitename', 'siteurl', 'lang', 'country', 'timezone',
                 'maxbytes', 'passwordpolicy', 'enrol_plugins_enabled', 'auth_plugins_enabled'];
    $result = [];
    foreach ($args['keys'] ?? [] as $key) {
        if (in_array($key, $allowed, true)) {
            $result[$key] = get_config('core', $key);
        }
    }
    return json_encode($result);
}
```

### C7 — AI API call + SSE streaming

PHP streams the request to the AI API and reads the response as a stream:

```php
function call_ai_api_streaming(string $model, array $messages, array $tools): Generator {
    $gateway_url = get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765';
    $api_key = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'tools' => $tools,
        'stream' => true,
    ];

    $ch = curl_init($gateway_url . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer) {
            $buffer .= $data;
            // Yield each SSE chunk as it arrives
            foreach (parse_sse_chunks($buffer) as $chunk) {
                yield $chunk;
            }
            return strlen($data);
        },
    ]);

    curl_exec($ch);
    curl_close($ch);
}
```

### C8 — SSE format

```text
Content-Type: text/event-stream
Cache-Control: no-cache
X-Accel-Buffering: no

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"delta":{"role":"assistant"},"index":0}]}

data: {"id":"chatcmpl-xxx","object":"chat.completion.chunk","choices":[{"delta":{"content":"Hello"},"index":0}]}

data: {"_jedomi_tool_executing":"execute_sql"}

data: {"_jedomi_tool_result":"3 filas encontradas en la tabla course"}

data: [DONE]
```

Events:
| data content | When | Purpose |
|---|---|---|
| `chat.completion.chunk` delta | Standard streaming | Transparent passthrough of AI content tokens |
| `{"_jedomi_tool_executing":"tool_name"}` | Before tool execution | JS can show tool execution indicator |
| `{"_jedomi_tool_result":"summary"}` | After tool execution | JS can show tool result summary |
| `[DONE]` | Stream complete | Signal end of stream to JS consumer |
| `{"error":"message"}` | On error during stream | JS can show error state |

### C9 — Tool loop (max 5 iterations)

```
iteration = 0
while iteration < 5:
    response = call_ai_api_streaming(model, messages, tools)
    tool_calls = []
    content_chunks = []

    for each SSE chunk from response:
        if chunk has tool_calls:
            tool_calls = extract_tool_calls(chunk)
            break  # stop consuming, we need to execute tools
        elif chunk has content delta:
            emit chunk raw to client (passthrough)
            content_chunks.append(delta)
        elif chunk is [DONE] and no tool_calls:
            emit [DONE]
            return

    if empty(tool_calls):
        emit [DONE]
        return

    for each tool_call in tool_calls:
        emit SSE: {"_jedomi_tool_executing": tool_call.function.name}
        result = execute_tool(tool_call, role)
        emit SSE: {"_jedomi_tool_result": summarize(result)}

        messages[] = {
            role: "tool",
            tool_call_id: tool_call.id,
            content: result
        }

    iteration++

// Post-loop: If still no final content after 5 iterations, emit error
emit SSE: {"error": "Max tool call iterations reached (5)"}
emit SSE: [DONE]
```

### C10 — Error handling

| Error type | Response | SSE? |
|---|---|---|
| Invalid method (not POST) | 405, plain text | No |
| Invalid/missing JSON body | 400, JSON error | No |
| Invalid sesskey | 403, JSON error | No |
| Invalid SPT signature | 403, JSON error | No |
| Expired SPT | 403, JSON error | No |
| AI API connection failure | SSE error event during stream | Yes |
| Tool execution error | Emit SSE with error, continue loop | Yes |
| Max iterations reached | SSE error event, [DONE] | Yes |
| Unknown tool called by AI | Return error string as tool result, loop continues | Yes |

### Scenarios

#### C-SC1: Student asks "what tables are in the database?"
- **Precondition:** Student with valid SPT
- **Action:** Sends chat message
- **Expected:** Tools array contains only `describe_table` + `search_tables`. No `execute_sql` or `get_site_config` in tools. AI can suggest tables but cannot query data.

#### C-SC2: Admin asks "how many users?"
- **Precondition:** Admin with valid SPT
- **Action:** Sends "how many users are registered?"
- **Expected:** AI calls `execute_sql` with `SELECT COUNT(*) FROM {user}`. Proxy executes SQL, returns result. AI formats response. SSE events emitted for tool execution.

#### C-SC3: Student tries to call execute_sql
- **Precondition:** Student with valid SPT, tools array has only describe + search
- **Action:** AI (or compromised client) sends tool_call for `execute_sql`
- **Expected:** Tool is NOT in the tools array sent to AI. The AI cannot call it because it doesn't know about it. The function dispatch in PHP ALSO checks role permission before executing.

#### C-SC4: Expired SPT
- **Precondition:** SPT has `expires` in the past (5+ min old)
- **Action:** POST to chatproxy.php
- **Expected:** 403, `{"error": "token_expired"}`

#### C-SC5: Invalid SPT signature
- **Precondition:** SPT has tampered signature
- **Action:** POST to chatproxy.php
- **Expected:** 403, `{"error": "invalid_signature"}`

#### C-SC6: SPT sesskey mismatch
- **Precondition:** SPT with valid signature but sesskey_hash from a different session
- **Action:** POST to chatproxy.php
- **Expected:** 403, `{"error": "invalid_session"}`

#### C-SC7: Tool call fails
- **Precondition:** AI calls execute_sql with invalid SQL
- **Action:** Proxy executes, DB throws exception
- **Expected:** Proxy catches exception, returns error as tool result, loop continues, SSE error event sent

#### C-SC8: AI loops beyond 5 iterations
- **Precondition:** AI keeps requesting tools without producing final content
- **Action:** After 5 iterations, loop terminates
- **Expected:** SSE error event: `{"error": "Max tool call iterations reached (5)"}`, [DONE] sent

### Acceptance Criteria (Spec C)

- [ ] POST to chatproxy.php with valid sesskey+SPT returns SSE stream
- [ ] Tools declared match user's role (admin: 4 tools, manager/teacher: 3 tools, student: 2 tools)
- [ ] Invalid/missing sesskey returns 403
- [ ] Invalid SPT signature returns 403
- [ ] Expired SPT returns 403
- [ ] Non-POST request returns 405
- [ ] Missing/invalid JSON body returns 400
- [ ] Tool call intercepted, executed, result injected into messages, loop continues
- [ ] Max 5 tool iterations enforced
- [ ] `{"_jedomi_tool_executing":"tool_name"}` event sent before each tool execution
- [ ] `{"_jedomi_tool_result":"..."}` event sent after each tool execution
- [ ] Content tokens streamed transparently when no tool call in progress
- [ ] Error during tool execution returns SSE error event, loop continues
- [ ] `[DONE]` sent at end of successful stream
- [ ] `[DONE]` sent after error termination
- [ ] Role permission checked at dispatch level (defense-in-depth beyond tools array)

---

## Spec D — Cache + Upgrade + Config

### D1 — `db/caches.php`

Add to `$definitions` array:

```php
'schema_cache' => [
    'mode'       => cache_store::MODE_APPLICATION,
    'simplekeys' => true,
    'simpledata' => true,
    'ttl'        => 86400,    // 24 horas
],
```

### D2 — `db/upgrade.php`

Add after the existing `$oldversion < 2026051401` block:

```php
if ($oldversion < 2026051409) {
    // Purge schema cache — ensures new cache definition is used
    // from the first request post-upgrade.
    cache_helper::purge_by_event('local_jedomi_schema_changed');

    upgrade_plugin_savepoint(true, 2026051409, 'local', 'jedomi');
}
```

### D3 — `version.php`

```php
$plugin->version = 2026051409; // + chat proxy + function calling + schema_cache
```

### D4 — `lib.php`

In `local_jedomi_before_footer()`, within `$config` array (after gateway_url or model):

```php
'chatproxy_url' => $CFG->wwwroot . '/local/jedomi/chatproxy.php',
```

### Acceptance Criteria (Spec D)

- [ ] `schema_cache` definition present in caches.php with MODE_APPLICATION, simplekeys=true, simpledata=true, TTL=86400
- [ ] Upgrade step purges schema_cache on version 2026051409
- [ ] version.php bumped to 2026051409
- [ ] `chatproxy_url` config key sent to JS AMD module

---

## Global Acceptance Criteria

- [ ] **No business logic in proxy** — only orchestration of tools, no Moodle-specific logic
- [ ] **No DB table changes** — no new tables, no schema migrations
- [ ] **No sidebar.js changes** — only the URL in config, JS consumer unchanged
- [ ] **No HMAC changes to external functions except execute_sql** — other WS functions keep existing HMAC behavior
- [ ] **System prompt untouched** — `generate_spt()`/`$spt` in `lib.php` unchanged
- [ ] **SSE format compatible with existing JS consumer** — the sidebar already handles SSE streams from the Gateway Python; the new proxy emits the same `chat.completion.chunk` delta format
- [ ] **No PII exposed** in table_descriptor outputs, execute_sql tool results (already in existing code), or config
- [ ] **Backward compatible** — existing WS calls unchanged, existing JS consumer unchanged, existing user sessions unaffected
- [ ] **Defense-in-depth** — capability checks in execute_sql survive even if called directly, tool dispatch also checks role

---

## Archivos afectados

| File | Action | Details |
|------|--------|---------|
| `chatproxy.php` | **CREATE** | Endpoint raíz. POST handler. SPT validation. Tool loop. SSE streaming. |
| `classes/schema/table_descriptor.php` | **CREATE** | Describe, search, cache de tablas via INFORMATION_SCHEMA. |
| `classes/external/execute_sql.php` | **MODIFY** | +$skip_hmac param, guard verify_hmac call. |
| `db/caches.php` | **MODIFY** | +schema_cache definition. |
| `db/upgrade.php` | **MODIFY** | +upgrade step 2026051409, purge schema_cache. |
| `version.php` | **MODIFY** | Bump to 2026051409. |
| `lib.php` | **MODIFY** | +chatproxy_url in config array. |

---

## Verificación post-implementación

```bash
# 1. Verificar que execute_parameters() NO incluye skip_hmac
rg 'skip_hmac' classes/external/execute_sql.php

# 2. Verificar que verify_hmac está condicional
rg 'if.!skip_hmac' classes/external/execute_sql.php

# 3. Verificar schema_cache definición
rg 'schema_cache' db/caches.php

# 4. Verificar upgrade step
rg '2026051409' db/upgrade.php

# 5. Verificar version bump
rg '2026051409' version.php

# 6. Verificar chatproxy_url en lib.php
rg 'chatproxy_url' lib.php

# 7. Verificar que table_descriptor existe
Test-Path classes/schema/table_descriptor.php

# 8. Verificar que chatproxy.php existe
Test-Path chatproxy.php
```
