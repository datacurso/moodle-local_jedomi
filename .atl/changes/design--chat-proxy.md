# Design: Local Chat Proxy + Function Calling (tool-execution loop)

## 1. Data Flow Architecture

```
sidebar.js                           chatproxy.php                    AI API
   │ POST /local/                        │                              │
   │ jedomi/chatproxy.php                │                              │
   │ {model, messages, spt}              │                              │
   ├──────────────────────────────────►  │                              │
   │                                     │  1. require_login()          │
   │                                     │  2. require_sesskey()        │
   │                                     │  3. Validate SPT signature   │
   │                                     │     (HMAC + TTL +           │
   │                                     │      sesskey_hash match)     │
   │                                     │  4. Extract role from SPT    │
   │                                     │  5. Build tools[] by role    │
   │                                     │  6. Inject system message    │
   │                                     │                              │
   │                                     │  POST /v1/chat/completions   │
   │                                     │  {model, messages, tools,    │
   │                                     │   stream: true}              │
   │                                     │  Authorization: Bearer key   │
   │                                     ├─────────────────────────────►│
   │                                     │                              │
   │                                     │  SSE stream begins           │
   │                                     │◄─────────────────────────────┤
   │                                     │                              │
   │                                     │  ┌─ Tool loop (max 5) ────┐ │
   │                                     │  │ Read SSE chunk          │ │
   │                                     │  │ if tool_calls:           │ │
   │                                     │  │   emit "_jedomi_tool_   │ │
   │                                     │  │   executing" SSE event   │ │
   │                                     │  │   call PHP class method  │ │
   │                                     │  │   inject result message  │ │
   │                                     │  │   re-call AI API         │ │
   │                                     │  │ if content delta:        │ │
   │                                     │  │   forward SSE chunk      │ │
   │                                     │  │ if [DONE]: break         │ │
   │                                     │  └──────────────────────────┘ │
   │                                     │                              │
   │  Forwarded SSE stream               │                              │
   │◄────────────────────────────────────┤                              │
```

### Auth validation points

| Step | Check | What it protects |
|------|-------|------------------|
| `require_login()` | User is authenticated in Moodle | Anonymous access |
| `require_sesskey()` | POST has valid sesskey | CSRF |
| SPT signature | `hash_equals()` against HMAC | SPT tampering |
| SPT expiry | `time() < expires` (5 min TTL) | Replay attacks |
| SPT sesskey_hash | matches current `substr(hash('sha256', sesskey()), 0, 16)` | Session hijacking |

## 2. chatproxy.php — Full Design

### 2.1 Endpoint structure

```
local/jedomi/
├── chatproxy.php              ← CREATE: SSE endpoint for AI proxy
├── classes/schema/
│   └── table_descriptor.php   ← CREATE: DB schema introspection
├── classes/external/
│   └── execute_sql.php        ← MODIFY: add skip_hmac
├── db/
│   ├── caches.php             ← MODIFY: add schema_cache definition
│   └── upgrade.php            ← MODIFY: purge schema cache
├── lib.php                    ← MODIFY: add chatproxy_url to config
└── version.php                ← MODIFY: bump to 2026051409
```

```php
<?php
// chatproxy.php
// @package    local_jedomi

require_once('../../config.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

require_login();
require_sesskey();

$input = json_decode(file_get_contents('php://input'), true);
$model    = $input['model'] ?? '';
$messages = $input['messages'] ?? [];
$spt      = $input['spt'] ?? [];

// --- Auth: validate SPT ---
$role = validate_spt($spt);          // throws 403 on failure

// --- Build request ---
$system = build_system_message($role, $spt);
$tools  = build_tools_for_role($role);
$messages = array_merge([$system], $messages);

// --- SSE headers ---
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
ob_implicit_flush(true);

// --- Tool loop ---
tool_loop($model, $messages, $tools);
```

### 2.2 Function signatures

```php
/**
 * Validate SPT signature, TTL, and sesskey binding.
 * @param  array  $spt  The signed permission token
 * @return string       The resolved role (admin|manager|teacher|student)
 * @throws moodle_exception  403 if invalid
 */
function validate_spt(array $spt): string;

/**
 * Build the system message for the AI model.
 * @param  string $role
 * @param  array  $spt
 * @return array  ['role' => 'system', 'content' => '...']
 */
function build_system_message(string $role, array $spt): array;

/**
 * Build the tools array (OpenAI format) based on user role.
 * @param  string $role
 * @return array  List of tool definitions
 */
function build_tools_for_role(string $role): array;

/**
 * Main tool-calling loop. Reads SSE from AI API, detects tool_calls,
 * executes them locally, re-injects results, and streams content to client.
 * Max 5 iterations.
 *
 * @param string $model
 * @param array  &$messages  Passed by reference — mutated with tool results
 * @param array  $tools
 * @return void              Outputs SSE directly
 */
function tool_loop(string $model, array &$messages, array $tools): void;

/**
 * Call the AI API with streaming SSE response.
 * @param  string $model
 * @param  array  $messages
 * @param  array  $tools
 * @return Generator  Yields raw SSE line strings
 */
function call_ai_api_streaming(string $model, array $messages, array $tools): Generator;

/**
 * Dispatch a tool_call to the appropriate PHP class.
 * @param  array  $tool_call  {'id','function':{'name','arguments'}}
 * @param  string $role
 * @return string  JSON-encoded result
 */
function execute_tool(array $tool_call, string $role): string;

/**
 * Send an SSE event line to the client.
 * @param string $event  Event name
 * @param mixed  $data   Data payload (will be JSON-encoded)
 */
function sse_send(string $event, $data): void;
```

### 2.3 validate_spt() — detailed logic

```php
function validate_spt(array $spt): string {
    // 1. Check sesskey binding
    $expected_hash = substr(hash('sha256', sesskey()), 0, 16);
    if (!hash_equals($expected_hash, $spt['sesskey_hash'] ?? '')) {
        http_response_code(403);
        die(json_encode(['error' => 'invalid_spt']));
    }

    // 2. Check TTL
    if (($spt['expires'] ?? 0) < time()) {
        http_response_code(403);
        die(json_encode(['error' => 'spt_expired']));
    }

    // 3. Reconstruct signature
    $secret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';
    $payload = $spt;
    unset($payload['signature']);
    ksort($payload);
    $to_sign = json_encode($payload);
    $expected = hash_hmac('sha256', $to_sign, $secret);

    if (!hash_equals($expected, $spt['signature'] ?? '')) {
        http_response_code(403);
        die(json_encode(['error' => 'invalid_spt']));
    }

    return $spt['role'] ?? 'student';
}
```

### 2.4 tool_loop() — detailed logic

```
function tool_loop(string $model, array &$messages, array $tools): void {
    for ($i = 0; $i < 5; $i++) {
        $stream = call_ai_api_streaming($model, $messages, $tools);

        $tool_calls = [];       // accumulate tool_calls from stream
        $content_buffer = '';   // accumulate content from stream

        foreach ($stream as $sse_line) {
            // Forward raw SSE to client (transparent passthrough)
            echo $sse_line;
            ob_flush(); flush();

            // Parse JSON from "data: {...}" line
            $parsed = parse_sse_data($sse_line);
            if (!$parsed) continue;

            // Detect finish_reason: tool_calls (last chunk of the response)
            if (isset($parsed['choices'][0]['delta']['tool_calls'])) {
                foreach ($parsed['choices'][0]['delta']['tool_calls'] as $tc) {
                    $idx = $tc['index'];
                    if (!isset($tool_calls[$idx])) {
                        $tool_calls[$idx] = $tc;
                    } else {
                        // Accumulate streaming arguments
                        $tool_calls[$idx]['function']['arguments'] .=
                            $tc['function']['arguments'] ?? '';
                    }
                }
            }

            // Normal content delta — accumulate for context
            $delta = $parsed['choices'][0]['delta']['content'] ?? '';
            $content_buffer .= $delta;
        }

        // If no tool_calls, we're done
        if (empty($tool_calls)) {
            break;
        }

        // Execute each tool_call
        foreach ($tool_calls as $tc) {
            sse_send('_jedomi_tool_executing', [
                'tool' => $tc['function']['name'],
                'args' => json_decode($tc['function']['arguments'] ?? '{}', true),
            ]);

            $result = execute_tool($tc, $role);

            sse_send('_jedomi_tool_result', [
                'tool'    => $tc['function']['name'],
                'summary' => summarize_result($tc['function']['name'], $result),
            ]);

            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $tc['id'],
                'content'      => $result,
            ];
        }

        // Loop continues — re-call AI API with tool results
    }
}
```

### 2.5 call_ai_api_streaming() — HTTP transport

```php
function call_ai_api_streaming(string $model, array $messages, array $tools): Generator {
    $gateway_url = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://localhost:8765', '/');
    $api_key     = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';

    // Construct AI API endpoint
    $url = $gateway_url . '/chat/completions';

    $body = json_encode([
        'model'    => $model,
        'messages' => $messages,
        'tools'    => $tools,
        'stream'   => true,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$generator) {
            // yield each SSE chunk
            $generator->send($data);
            return strlen($data);
        },
    ]);

    // Use a callback-based approach or readline loop
    $resource = curl_exec($ch);
    curl_close($ch);
}
```

**Implementation note:** PHP's curl doesn't natively support generators as write callbacks. The actual implementation will use `CURLOPT_FILE` with a temporary stream or read SSE line-by-line via `fgets()` on a pipe. The generator abstraction is a design intent — the implementation detail will be resolved during coding.

**Alternative approach (recommended for implementation):** Use `fopen('php://temp', 'w+')` as CURLOPT_FILE, then rewind and `fgets()` line by line to yield. This avoids callback complexity:

```php
$temp = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_FILE, $temp);
curl_exec($ch);
rewind($temp);
while ($line = fgets($temp)) {
    yield $line;
}
fclose($temp);
```

### 2.6 execute_tool() dispatcher

```php
function execute_tool(array $tool_call, string $role): string {
    $name = $tool_call['function']['name'];
    $args = json_decode($tool_call['function']['arguments'] ?? '{}', true);

    return match ($name) {
        'execute_sql'    => execute_sql_tool($args, $role),
        'describe_table' => describe_table_tool($args),
        'search_tables'  => search_tables_tool($args),
        'get_site_config'=> get_site_config_tool($args, $role),
        default          => json_encode(['error' => 'Unknown tool: ' . $name]),
    };
}
```

### 2.7 Per-tool execution

```php
function execute_sql_tool(array $args, string $role): string {
    // Role gate (defense-in-depth: the tool definition shouldn't exist for students)
    if (!in_array($role, ['admin', 'manager', 'teacher'])) {
        return json_encode(['error' => 'permission_denied']);
    }
    try {
        $result = \local_jedomi\external\execute_sql::execute(
            $args['sql'],
            $args['params'] ?? [],
            '',     // hmac_signature — unused when skip_hmac=true
            0,      // hmac_timestamp
            '',     // payload_hash
            true    // skip_hmac
        );
        return json_encode($result);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function describe_table_tool(array $args): string {
    try {
        $result = \local_jedomi\schema\table_descriptor::describe($args['tablename']);
        return json_encode($result);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function search_tables_tool(array $args): string {
    try {
        $result = \local_jedomi\schema\table_descriptor::search_tables($args['query']);
        return json_encode($result);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function get_site_config_tool(array $args, string $role): string {
    if ($role !== 'admin') {
        return json_encode(['error' => 'permission_denied']);
    }
    try {
        $result = \local_jedomi\external\get_site_config::execute(
            $args['keys'],
            '',     // hmac_signature
            0,      // hmac_timestamp
            '',     // payload_hash
            true    // skip_hmac
        );
        return json_encode($result);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}
```

## 3. table_descriptor.php — Schema introspection

```php
<?php
namespace local_jedomi\schema;

defined('MOODLE_INTERNAL') || die();

class table_descriptor {

    /**
     * Describe a single table: columns, primary key, indices, foreign keys.
     * Cached 24h in schema_cache.
     *
     * @param  string $tablename
     * @return array
     */
    public static function describe(string $tablename): array;

    /**
     * Search tables by name pattern (LIKE %query%).
     * Cached 24h in schema_cache.
     *
     * @param  string $query
     * @return array   List of table names
     */
    public static function search_tables(string $query): array;

    /**
     * Purge the schema_cache entirely.
     * Called from upgrade.php.
     *
     * @return bool
     */
    public static function clear_cache(): bool;
}
```

### 3.1 describe() — query logic

```
1. Cache check: schema_cache key "desc_{$tablename}"
2. If not cached:
   a. Get DB name: $CFG->dbname
   b. Query INFORMATION_SCHEMA.COLUMNS:
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
               COLUMN_DEFAULT, COLUMN_KEY, EXTRA
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
   c. Query INFORMATION_SCHEMA.KEY_COLUMN_USAGE for FKs:
        SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME,
               kcu.REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
        JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
          ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         AND tc.TABLE_SCHEMA    = kcu.TABLE_SCHEMA
         AND tc.TABLE_NAME      = kcu.TABLE_NAME
        WHERE kcu.TABLE_SCHEMA   = ?
          AND kcu.TABLE_NAME     = ?
          AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
          AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
   d. Query INFORMATION_SCHEMA.STATISTICS for indices:
        SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE,
               SEQ_IN_INDEX, INDEX_COMMENT
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ORDER BY INDEX_NAME, SEQ_IN_INDEX
   e. Build result structure:
        {
          'name':         $tablename,
          'columns':      [{name, type, nullable, default, key, extra}],
          'primary_key':  ['id'],
          'indices':      [{name, columns, unique}],
          'foreign_keys': [{column, references_table, references_column}],
        }
   f. Store in cache with 86400 TTL
3. Return cached result
```

### 3.2 search_tables() — query logic

```
1. Cache key: "search_" . md5($query)
2. If not cached:
   a. SELECT TABLE_NAME
      FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = ?
        AND TABLE_TYPE = 'BASE TABLE'
        AND TABLE_NAME LIKE ?
   b. $params = [$CFG->dbname, '%' . $query . '%']
   c. Return flat list of table names
   d. Cache with 86400 TTL
3. Return cached result
```

### 3.3 Same format as extract_schema

The `describe()` output must match the column format used in `extract_schema::describe_table()` (`classes/external/extract_schema.php:185`) so the AI sees consistent schema data regardless of which endpoint is used.

## 4. execute_sql — skip_hmac modification

### 4.1 Signature change

```php
public static function execute(
    string $sql,
    array  $params,
    string $hmac_signature,
    int    $hmac_timestamp,
    string $payload_hash,
    bool   $skip_hmac = false   // ← NEW 6th parameter
): array {
```

### 4.2 Guard before verify_hmac

Insert at line 112 (before `self::verify_hmac()`):

```php
if (!$skip_hmac) {
    self::verify_hmac('jedomi_execute_sql', $hmac_timestamp, $payload_hash, $hmac_signature);
}
```

### 4.3 execute_parameters() update

Add to the parameter definition:

```php
'skip_hmac' => new external_value(PARAM_BOOL,
    'Skip HMAC verification (used by chatproxy — auth via SPT)',
    VALUE_DEFAULT,
    false
),
```

### 4.4 validate_parameters() call update

The `compact()` call inside `execute()` already includes all local variables. Since `skip_hmac` will be a local variable after the parameter is added to `execute_parameters()`, and `compact('...', 'skip_hmac')` will need to include it. Update:

```php
$validated = self::validate_parameters(
    self::execute_parameters(),
    compact('sql', 'params', 'hmac_signature', 'hmac_timestamp', 'payload_hash', 'skip_hmac')
);
$skip_hmac = $validated['skip_hmac'];
```

### 4.5 Capability check note

`execute_sql::execute()` requires `local/jedomi:companion_api` capability (line 99). When called from chatproxy.php, the user already has a valid Moodle session, so `self::validate_context($context)` and `require_capability()` will work correctly with the current user's session. No changes needed.

## 5. get_site_config — skip_hmac modification (same pattern)

### 5.1 Reason

The proposal originally scoped skip_hmac to execute_sql only. However, if the proxy calls `get_site_config::execute()` directly, it will fail on the HMAC check because the proxy doesn't have the HMAC secret (only the Gateway Python does).

**Options considered:**
a. Add skip_hmac to get_site_config → **chosen**
b. Call get_site_config's internal logic directly → violates DRY, duplicates masking/allowlist logic

### 5.2 Signature change

```php
public static function execute(
    array  $keys,
    string $hmac_signature,
    int    $hmac_timestamp,
    string $payload_hash,
    bool   $skip_hmac = false   // ← NEW
): array {
```

### 5.3 Guard

Insert before `self::verify_hmac()` (line 107):

```php
if (!$skip_hmac) {
    self::verify_hmac('jedomi_get_site_config', $hmac_timestamp, $payload_hash, $hmac_signature);
}
```

### 5.4 execute_parameters() update

```php
'skip_hmac' => new external_value(PARAM_BOOL,
    'Skip HMAC verification (used by chatproxy — auth via SPT)',
    VALUE_DEFAULT,
    false
),
```

## 6. Cache definition

Add to `db/caches.php`:

```php
'schema_cache' => [
    'mode'       => cache_store::MODE_APPLICATION,
    'simplekeys' => true,
    'simpledata' => true,
    'ttl'        => 86400, // 24 hours — Moodle schema rarely changes
],
```

- **MODE_APPLICATION**: shared across all users. DB schema is site-wide, not per-user.
- **simplekeys**: keys are plain strings like `desc_mdl_user` or `search_hash`.
- **simpledata**: values are plain arrays (column definitions, table lists), not objects.
- **ttl 86400**: 24h is safe — schema changes only during upgrades or plugin installs.

## 7. Upgrade step

Add to `db/upgrade.php` after the existing Telegram upgrade block:

```php
if ($oldversion < 2026051409) {
    // Purge schema_cache to ensure the new definition takes effect
    // immediately after upgrade, avoiding stale cache entries from
    // before the definition existed.
    \cache_helper::purge_by_event('local_jedomi_schema_changed');

    upgrade_plugin_savepoint(true, 2026051409, 'local', 'jedomi');
}
```

## 8. version.php bump

```php
$plugin->version   = 2026051409; // + chat proxy + function calling + schema_cache
```

## 9. lib.php config addition

In `local_jedomi_before_footer()`, inside the `$config` array (after `'model'` on line 59):

```php
'chatproxy_url' => $CFG->wwwroot . '/local/jedomi/chatproxy.php',
```

This gives the JS an alternative endpoint. The JS can use `cfg.chatproxy_url` when available, with fallback to `cfg.gateway_url`.

## 10. SSE Stream Design

### 10.1 Wire format

```
Content-Type: text/event-stream
Cache-Control: no-cache
Connection: keep-alive
X-Accel-Buffering: no

data: {"id":"chatcmpl-...","object":"chat.completion.chunk","choices":[{"delta":{"role":"assistant"},"index":0}]}

data: {"id":"chatcmpl-...","object":"chat.completion.chunk","choices":[{"delta":{"content":"Vamos"},"index":0}]}

data: {"event":"_jedomi_tool_executing","tool":"execute_sql","args":{"sql":"SELECT COUNT(*) FROM {course} WHERE visible = ?","params":["1"]}}

data: {"event":"_jedomi_tool_result","tool":"execute_sql","summary":"3 courses found"}

data: {"id":"chatcmpl-...","object":"chat.completion.chunk","choices":[{"delta":{"content":" a revisar los datos."},"index":0}]}

data: {"id":"chatcmpl-...","object":"chat.completion.chunk","choices":[{"delta":{},"finish_reason":"stop","index":0}]}

data: [DONE]
```

### 10.2 Event types

| Event | Direction | Purpose |
|-------|-----------|---------|
| `_jedomi_tool_executing` | proxy → client | Notification that a tool is being executed. JS can show a spinner. |
| `_jedomi_tool_result` | proxy → client | Summary of tool execution result. JS can display briefly. |
| Regular SSE (passthrough) | AI API → client via proxy | Standard `data: {"id":"...","choices":[...]}` lines forwarded verbatim. |
| `data: [DONE]` | AI API → client via proxy | Standard OpenAI stream termination. |

### 10.3 SSE sending helper

```php
function sse_send(string $event, $data): void {
    $payload = json_encode(array_merge(
        ['event' => $event],
        is_array($data) ? $data : ['data' => $data]
    ), JSON_UNESCAPED_UNICODE);
    echo "data: {$payload}\n\n";
    ob_flush();
    flush();
}
```

### 10.4 JS detection pattern

The JS's existing SSE parser checks each `data:` line. If the parsed object has an `event` field with a `_jedomi_` prefix, it's a custom event (tool execution). Otherwise, it's a standard OpenAI chunk:

```javascript
// Pseudocode — actual JS is in sidebar.js
onLine(line) {
  if (line.startsWith('data: ')) {
    const data = line.slice(6);
    if (data === '[DONE]') return this.onDone();
    const parsed = JSON.parse(data);
    if (parsed.event === '_jedomi_tool_executing') {
      return this.onToolExecuting(parsed.tool, parsed.args);
    }
    if (parsed.event === '_jedomi_tool_result') {
      return this.onToolResult(parsed.tool, parsed.summary);
    }
    return this.onChunk(parsed); // Standard OpenAI format
  }
}
```

## 11. Tool Definitions (OpenAI format)

### 11.1 execute_sql

```json
{
  "type": "function",
  "function": {
    "name": "execute_sql",
    "description": "Ejecuta una consulta SELECT en la base de datos de Moodle y devuelve las filas como JSON. SOLO lectura. Límite: 100 filas, timeout 5 segundos.",
    "parameters": {
      "type": "object",
      "properties": {
        "sql": {
          "type": "string",
          "description": "Consulta SELECT a ejecutar. Ejemplo: SELECT id, fullname FROM {course} WHERE category = ?"
        },
        "params": {
          "type": "array",
          "items": {"type": "string"},
          "description": "Parámetros vinculados para la consulta (opcional)"
        }
      },
      "required": ["sql"]
    }
  }
}
```

### 11.2 describe_table

```json
{
  "type": "function",
  "function": {
    "name": "describe_table",
    "description": "Obtiene la estructura (columnas, tipos, claves, foreign keys) de una tabla de la base de datos de Moodle. Cacheado 24h.",
    "parameters": {
      "type": "object",
      "properties": {
        "tablename": {
          "type": "string",
          "description": "Nombre de la tabla (ej: course, user, grade_grades)"
        }
      },
      "required": ["tablename"]
    }
  }
}
```

### 11.3 search_tables

```json
{
  "type": "function",
  "function": {
    "name": "search_tables",
    "description": "Busca tablas en la base de datos por nombre. Devuelve lista de tablas que contienen el texto buscado. Cacheado 24h.",
    "parameters": {
      "type": "object",
      "properties": {
        "query": {
          "type": "string",
          "description": "Texto a buscar en el nombre de la tabla (ej: grade, user, course)"
        }
      },
      "required": ["query"]
    }
  }
}
```

### 11.4 get_site_config

```json
{
  "type": "function",
  "function": {
    "name": "get_site_config",
    "description": "Obtiene configuración del sitio Moodle (sitename, lang, timezone, etc.). Solo disponible para administradores.",
    "parameters": {
      "type": "object",
      "properties": {
        "keys": {
          "type": "array",
          "items": {"type": "string"},
          "description": "Lista de claves de configuración a obtener. Válidas: sitename, siteurl, lang, country, timezone, maxbytes, passwordpolicy, enrol_plugins_enabled, auth_plugins_enabled"
        }
      },
      "required": ["keys"]
    }
  }
}
```

### 11.5 Access matrix (build_tools_for_role)

```php
function build_tools_for_role(string $role): array {
    $tools = [
        self::describe_table_definition(),
        self::search_tables_definition(),
    ];

    if (in_array($role, ['admin', 'manager', 'teacher'])) {
        $tools[] = self::execute_sql_definition();
    }

    if ($role === 'admin') {
        $tools[] = self::get_site_config_definition();
    }

    return $tools;
}
```

## 12. System Prompt Design

```php
function build_system_message(string $role, array $spt): array {
    $site = get_site_info(); // sitename, lang, version
    $course = $spt['course_id'] ?? 0;

    $content = "Eres un asistente de IA integrado en Moodle ({$site['sitename']}).\n";
    $content .= "Tu función es ayudar al usuario (rol: {$role}) respondiendo preguntas ";
    $content .= "sobre el sitio usando las tools disponibles.\n\n";
    $content .= "REGLAS:\n";
    $content .= "- Usa execute_sql SOLO para consultas SELECT. Nunca intentes modificar datos.\n";
    $content .= "- Cuando necesites entender la estructura de una tabla, usa describe_table primero.\n";
    $content .= "- Para encontrar tablas relevantes, usa search_tables.\n";
    $content .= "- Siempre usa parámetros vinculados (?) en lugar de concatenar valores en SQL.\n";
    $content .= "- Explica QUÉ datos consultaste y POR QUÉ son relevantes.\n";
    $content .= "- Si una consulta devuelve 0 filas, dilo explícitamente.\n";
    $content .= "- No inventes datos. Si no encuentras información, dilo.\n";
    $content .= "- Respetá la privacidad: no reveles datos personales de otros usuarios.\n\n";
    $content .= "Contexto actual:\n";
    $content .= "- Versión de Moodle: {$site['release']}\n";
    $content .= "- Curso ID: {$course}\n";
    $content .= "- Rol del usuario: {$role}\n";

    return ['role' => 'system', 'content' => $content];
}
```

## 13. Security Considerations

| Threat | Mitigation |
|--------|-----------|
| Direct POST to chatproxy.php without session | `require_login()` detects no session → redirect/403. |
| CSRF via forged POST | `require_sesskey()` rejects requests without valid sesskey. |
| SPT tampering (modified role/capabilities) | HMAC-SHA256 signature verified with `hash_equals()` before any tool is built. |
| SPT replay (captured and re-sent) | 5-min TTL (`expires` field) + sesskey_hash binding. Even within TTL, a different session won't match. |
| SPT forgery (attacker crafts fake SPT) | `spt_secret` is a config value never exposed to the client. Without it, attacker cannot sign a valid SPT. |
| SQL injection via execute_sql | SELECT-only enforcement + parameter binding (existing in `execute_sql::execute()`). The AI only generates SQL text; parameters are bound separately. |
| Tool execution by unauthorized role | The tool definition is simply not included in the tools array for that role. The AI cannot call what it cannot see. |
| File exfiltration via INTO OUTFILE | Existing `INTO OUTFILE/DUMPFILE` regex check in `execute_sql::execute()` (line 129). |
| AI API key exposure | Stored in `mdl_config` (`local_jedomi/api_key`). Never sent to JS. Only used server-side in the curl call. |
| Infinite tool loop | Hard limit of 5 iterations. After 5 iterations, the last AI response (without tool_calls) is what the user sees. |
| Slow AI API / timeout per iteration | `set_time_limit(60)` per iteration via `set_time_limit()`. Total max ~300s across all iterations. |
| Cache stampede on schema_cache | 24h TTL + MODE_APPLICATION cache store handles concurrent requests. The first request populates the cache; subsequent reads hit cache. |
| PHP memory exhaustion from large tool results | `execute_sql` already limits to 100 rows. Tool results are JSON-encoded and re-injected as messages, which adds to the request body size. Each tool result should be kept under ~50KB. |

### Trust boundaries

```
Browser / JS                    chatproxy.php                   AI API externa
     │                              │                              │
     │  Known session + SPT         │  api_key (from config)       │
     │  (identity + capabilities)   │  Bearer token                │
     │                              │                              │
     │  ──POST──►                    │  ──POST──►                    │
     │                              │                              │
     │  ◄──SSE──                    │  ◄──SSE──                      │
     │                              │                              │
     │                              │  Tool execution:             │
     │                              │  - execute_sql::execute()    │
     │                              │    under current $USER       │
     │                              │  - table_descriptor::desc()  │
     │                              │    (no user data, just       │
     │                              │     schema)                  │
```

## 14. Key Design Decisions

| ID | Decision | Rationale |
|----|----------|-----------|
| **DD1** | Auth via SPT + sesskey, not HMAC | The proxy runs inside Moodle's request lifecycle. SPT validates identity+permissions, sesskey prevents CSRF. HMAC is for server-to-server auth (Gateway Python ↔ CompanionWS). |
| **DD2** | skip_hmac added to both execute_sql AND get_site_config | Both external functions require HMAC verification. When called from the proxy, HMAC is redundant because the proxy already authenticated via SPT+sesskey. |
| **DD3** | SSE passthrough for AI response chunks | The JS already handles standard OpenAI SSE format. Forwarding raw chunks means zero JS changes for the streaming path. Only tool events are injected as custom lines. |
| **DD4** | Table data cached 24h in MODE_APPLICATION | Moodle's schema only changes during upgrades/plugin installs. 24h TTL is safe. `clear_cache()` exists for maintenance windows. MODE_APPLICATION shares across users (no per-user schema). |
| **DD5** | Proxy calls `gateway_url` directly | Uses existing config. The URL can point directly to the AI API (e.g. `https://api.openai.com/v1`) or through the existing gateway. Either way, the proxy manages the full tool loop. |
| **DD6** | System prompt built server-side, not passed from JS | The system prompt is constructed in chatproxy.php using the validated SPT context. This prevents prompt injection via the JS client and ensures the AI always has accurate role/context info. |
| **DD7** | Tool results re-injected as `role: tool` messages | Standard OpenAI tool-calling protocol. The AI receives results in the same format as if the gateway executed them. The existing conversation history with `role: tool` entries can be used directly. |

## 15. Effort Breakdown

| Component | Est. time | Dependencies |
|-----------|-----------|-------------|
| `table_descriptor.php` | 2-3h | None — standalone class |
| `chatproxy.php` | 4-6h | Depends on table_descriptor, execute_sql, get_site_config |
| `execute_sql.php` | 30min | None — simple param + guard |
| `get_site_config.php` | 30min | None — same pattern as execute_sql |
| `db/caches.php` | 15min | None — one definition |
| `db/upgrade.php` | 15min | None — one save point |
| `version.php` | 5min | None — one line |
| `lib.php` | 15min | None — one array entry |
| Integration testing | 2-3h | All components deployed |
| **Total** | **~10-14h** | |

## 16. Future Considerations (out of scope)

- **Tool result verification**: validate tool output before passing to AI (catch hallucinations)
- **Semantic tools**: `get_user_info`, `get_course_info` instead of raw SQL
- **Streaming tool progress**: emit SSE with per-chunk progress during long tool execution
- **Automatic cache invalidation**: purge schema_cache on Moodle upgrade events
- **Conversation persistence**: save chat history + tool calls to DB for auditing
- **Observability**: metrics on tool usage per role, latency percentiles, error rates
