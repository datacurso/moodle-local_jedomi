# Proposal: Local Chat Proxy + Function Calling (tool-execution loop)

## 1. Intent

Eliminar la dependencia del Gateway Python para tool execution creando un proxy PHP local dentro del plugin `local_jedomi`. Hoy el flujo es: JS → Gateway Python → AI API → Gateway Python → tool call → CompanionWS (PHP). Esto agrega latencia, un punto de falla, y complejidad operativa.

El nuevo flujo es: JS → `chatproxy.php` (PHP) → AI API → `chatproxy.php` ejecuta la tool directamente → AI API → stream a JS. El proxy PHP recibe requests del sidebar JS, declara tools en formato OpenAI (`execute_sql`, `describe_table`, `search_tables`, `get_site_config`), ejecuta el tool-calling loop localmente, y streamea la respuesta final via SSE.

Esto elimina alucinaciones de la IA dándole acceso a datos reales del sitio via tools, y elimina la necesidad del gateway Python como intermediario para tool execution.

## 2. Scope

### In scope

| File | Action | Details |
|------|--------|---------|
| `chatproxy.php` | **CREATE** | Endpoint raíz del plugin. POST handler. Valida sesskey + SPT. Builds tools array según rol. Loop de tool calling. SSE streaming. Límite: 5 iteraciones de tool, timeout por iteración. |
| `classes/schema/table_descriptor.php` | **CREATE** | Describe tablas individuales (`describe_table`) y busca tablas por nombre (`search_tables`). Cachea resultados via Moodle Cache API (`schema_cache`). Expone `clear_cache()`. |
| `classes/external/execute_sql.php` | **MODIFY** | Agregar `$skip_hmac = false` al signature de `execute()`. Si es `true`, saltea `self::verify_hmac()`. El proxy PHP lo llama con `skip_hmac=true` porque ya autenticó via sesskey + SPT. |
| `db/caches.php` | **MODIFY** | Agregar definición `schema_cache` (MODE_APPLICATION, TTL: 86400 = 24h). |
| `db/upgrade.php` | **MODIFY** | Nuevo step para versión 2026051409: purge del cache `schema_cache`. |
| `version.php` | **MODIFY** | Bump version de `2026051408` a `2026051409`. |
| `lib.php` | **MODIFY** | Agregar `'chatproxy_url' => $CFG->wwwroot . '/local/jedomi/chatproxy.php'` al array `$config` en `local_jedomi_before_footer()`. |

### Out of scope

- No se cambia el system prompt (`generate_spt()` / `$spt` en `lib.php`)
- No se agrega lógica de negocio al proxy — solo orquestación de tools
- No se modifican archivos JS del sidebar (`sidebar.js`, `sidebar.css`, etc.) — solo se agrega la URL `chatproxy_url` al config que ya recibe el AMD module
- No se crean tablas nuevas en la DB
- No se implementa verificación automática de resultados de tools
- No se agregan nuevos endpoints webservice (no se toca `db/services.php`)

## 3. Architecture

### Flujo actual (con Gateway Python)

```
sidebar.js  ──POST──►  Gateway (Python)  ──POST──►  AI API
                            │
                            ├── tool_call ──POST──►  CompanionWS (PHP)
                            │                            │
                            │◄──── response ──────────────┘
                            │
                            ├── POST ────────►  AI API
                            │
                            ◄──── SSE stream ──────────────
```

### Flujo nuevo (chatproxy.php)

```
sidebar.js  ──POST──►  chatproxy.php (PHP)  ──POST──►  AI API
                            │                              │
                            │  SSE: tool_call              │
                            │◄─────────────────────────────┤
                            │                              │
                            │  ┌──────────────────────┐    │
                            │  │ Ejecuta tool via PHP  │    │
                            │  │ (sin HTTP, directo)   │    │
                            │  └──────────────────────┘    │
                            │                              │
                            │  POST ──────────────►  AI API│
                            │                              │
                            │  SSE: content (respuesta)    │
                            │◄─────────────────────────────┤
                            │                              │
  SSE: stream final         │                              │
◄───────────────────────────┘                              │
```

### Componentes

```
chatproxy.php
├── POST /local/jedomi/chatproxy.php
│   ├── validate_sesskey()
│   ├── validate_spt($spt)          — verifica firma + TTL + sesskey_hash
│   ├── determine_role($spt)        — admin / manager / teacher / student
│   ├── build_tools($role)          — arma tools según rol
│   ├── build_system_prompt($role)  — arma contexto para la IA
│   └── tool_loop($model, $messages, $tools)
│       ├── call_ai_api($messages, $tools)
│       ├── si tool_call:
│       │   ├── emit_sse('_jedomi_tool_executing')
│       │   ├── execute_tool($tool_call)
│       │   └── append_tool_result() + loop (max 5)
│       └── si content:
│           ├── emit_sse('content', $chunk)
│           └── stream_done → return

classes/schema/table_descriptor.php
├── describe_table(string $tablename): array
│   └── cache_key: "desc_{$tablename}"
│   └── query: INFORMATION_SCHEMA.COLUMNS + KEY_COLUMN_USAGE
├── search_tables(string $query): array
│   └── cache_key: "search_{$query}"
│   └── query: INFORMATION_SCHEMA.TABLES LIKE '%query%'
└── clear_cache(): void
```

## 4. Tool Definitions (OpenAI format)

Cada tool se declara como `{"type":"function","function":{...}}` en el array `tools` enviado a la API de OpenAI-compatible.

### 4.1 `execute_sql`

Disponible para: admin, manager, teacher

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

### 4.2 `describe_table`

Disponible para: todos los roles

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

### 4.3 `search_tables`

Disponible para: todos los roles

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

### 4.4 `get_site_config`

Disponible para: solo admin

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

### Matriz de acceso por rol

| Tool | admin | manager | teacher | student |
|------|-------|---------|---------|---------|
| `execute_sql` | ✅ | ✅ | ✅ | ❌ |
| `describe_table` | ✅ | ✅ | ✅ | ✅ |
| `search_tables` | ✅ | ✅ | ✅ | ✅ |
| `get_site_config` | ✅ | ❌ | ❌ | ❌ |

## 5. Tool-Calling Loop (chatproxy.php)

```
function handle_chat_request(string $model, array $messages, array $spt): void
```

### Paso a paso

1. **Validación de sesskey**
   - `confirm_sesskey($spt['sesskey_hash'])` — el hash corto del sesskey está en el SPT

2. **Validación de SPT**
   - Reconstruir payload: `ksort($payload)`, `json_encode`, `hash_hmac('sha256', $payload, $static_secret)`
   - Comparar con `$spt['signature']` via `hash_equals()`
   - Verificar `time() < $spt['expires']` (TTL 5 minutos)
   - Verificar que `substr(hash('sha256', sesskey()), 0, 16)` coincide con `$spt['sesskey_hash']`

3. **Determinar rol**
   - Leer `$spt['role']` — ya viene resuelto de `local_jedomi_get_user_role()`

4. **Build tools array**
   - Según la matriz de acceso (sección 4.5)
   - Student: solo `describe_table` + `search_tables`
   - Teacher/manager: + `execute_sql`
   - Admin: + `get_site_config`

5. **Build system prompt**
   - Contexto de Moodle: versión, sitio, curso actual (desde SPT)
   - Descripción de tools disponibles
   - Instrucciones: usa las tools para responder preguntas sobre datos reales

6. **Tool loop** (máximo 5 iteraciones)

```
iteración = 0
mientras iteración < 5:
    response = call_ai_api_streaming(model, messages, tools)
    
    para cada chunk SSE de response:
        si chunk contiene tool_calls:
            para cada tool_call en tool_calls:
                emitir SSE: {"event": "_jedomi_tool_executing", "tool": tool_call.function.name}
                
                resultado = ejecutar_segun_rol(tool_call, spt)
                # ejecutar_segun_rol:
                #   - execute_sql → execute_sql::execute(sql, params, '', 0, '', true)
                #   - describe_table → table_descriptor::describe(tablename)
                #   - search_tables → table_descriptor::search(query)
                #   - get_site_config → get_site_config::execute(keys, '', 0, '', true)
                
                messages[] = {
                    role: "tool",
                    tool_call_id: tool_call.id,
                    content: json_encode(resultado)
                }
            
            iteración++
            continue  # siguiente llamada a AI API con resultados de tools
        
        si chunk contiene content delta:
            emitir SSE: chunk (streaming transparente al cliente)
    
    si stream termina sin tool_calls:
        break
```

7. **SSE response format**

```
Content-Type: text/event-stream
Cache-Control: no-cache
Connection: keep-alive
X-Accel-Buffering: no

data: {"event": "_jedomi_tool_executing", "tool": "execute_sql", "args": {"sql": "SELECT..."}}

data: {"event": "_jedomi_tool_result", "tool": "execute_sql", "summary": "Encontré 3 cursos activos"}

data: {"event": "content", "content": "Basado en"}

data: {"event": "content", "content": " los datos actuales,"}

data: {"event": "content", "content": " hay 3 cursos activos en..."}

data: {"event": "done"}
```

## 6. Detailed File Specifications

### 6.1 `chatproxy.php` (CREATE, root of plugin)

```
local/jedomi/
├── chatproxy.php         ← NUEVO
├── lib.php
├── version.php
├── ...
```

**Estructura:**

```php
<?php
// @package local_jedomi

require_once('../../config.php');

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// Headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

$model    = $body['model'] ?? '';
$messages = $body['messages'] ?? [];
$spt      = $body['spt'] ?? [];

// 1. Validar sesskey
validate_spt_sesskey($spt);

// 2. Validar SPT
$role = validate_spt($spt);

// 3. Build system prompt + tools
$system = build_system_message($role, $spt);
$tools  = build_tools_for_role($role);

// 4. Tool loop
$messages = array_merge([$system], $messages);
tool_loop($model, $messages, $tools, $role, $spt);
```

**Function breakdown:**

| Function | Purpose |
|----------|---------|
| `validate_spt_sesskey(array $spt)` | Verifica que `substr(hash('sha256', sesskey()), 0, 16)` coincide con `$spt['sesskey_hash']`. Error 403 si falla. |
| `validate_spt(array $spt): string` | Reconstruye firma HMAC, verifica TTL, devuelve role. Error 403 si inválido. |
| `build_system_message(string $role, array $spt): array` | Arma mensaje system con contexto de Moodle + instrucciones de tools. |
| `build_tools_for_role(string $role): array` | Devuelve array de tool definitions según matriz de acceso. |
| `tool_loop(string $model, array $messages, array $tools, string $role, array $spt): void` | Loop principal (max 5 iters). Llama AI API, maneja tool_calls, streamea respuesta. |
| `call_ai_api_streaming(string $model, array $messages, array $tools): Generator` | POST a `gateway_url` con `stream: true`. Retorna chunks SSE como generator. |
| `execute_tool(array $tool_call, string $role, array $spt): string` | Dispatcher: llama a la clase PHP correspondiente según `$tool_call['function']['name']`. |
| `sse_send(string $event, $data): void` | Envía un evento SSE formateado: `data: {"event":"...",...}\n\n`. |

### 6.2 `classes/schema/table_descriptor.php` (CREATE)

**Namespace:** `local_jedomi\schema`

```php
namespace local_jedomi\schema;

class table_descriptor {
    public static function describe(string $tablename): array
    public static function search_tables(string $query): array
    public static function clear_cache(): void
}
```

**`describe(string $tablename): array`**
- Cache key: `desc_{$tablename}`
- Retorna columnas (nombre, tipo, nullable, default, key, extra) + foreign keys
- Idealmente mismo formato que `extract_schema::describe_table()` pero para una sola tabla
- Si `$tablename` no existe: retorna `{"error": "Table not found"}`
- TTL cache: 86400s (24h)

**`search_tables(string $query): array`**
- Cache key: `search_{$query}`
- `SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?`
- Retorna array plano de nombres de tabla que matchean
- TTL cache: 86400s (24h)

**`clear_cache(): void`**
- `cache_helper::purge_by_event('local_jedomi_schema_changed')`
- Llamado desde el upgrade step

### 6.3 `classes/external/execute_sql.php` (MODIFY)

**Cambio específico:** Agregar `$skip_hmac` al signature de `execute()`.

```php
public static function execute(
    string $sql,
    array  $params,
    string $hmac_signature,
    int    $hmac_timestamp,
    string $payload_hash,
    bool   $skip_hmac = false   // ← NUEVO
): array {
```

**En el body, antes de `self::verify_hmac()` (línea 113):**

```php
if (!$skip_hmac) {
    self::verify_hmac('jedomi_execute_sql', $hmac_timestamp, $payload_hash, $hmac_signature);
}
```

**Cuando el proxy PHP lo llama:**

```php
execute_sql::execute($sql, $params, '', 0, '', true);
//                                          ^^^^ skip_hmac = true
```

**Razonamiento:** El proxy PHP ya validó sesskey + SPT. El HMAC es redundante en este contexto porque no hay gateway intermediario. El HMAC sigue siendo OBLIGATORIO cuando se llama via Webservice (desde el Gateway Python).

### 6.4 `db/caches.php` (MODIFY)

Agregar al array `$definitions`:

```php
'schema_cache' => [
    'mode'       => cache_store::MODE_APPLICATION,
    'simplekeys' => true,
    'simpledata' => true,
    'ttl'        => 86400,    // 24 horas
],
```

- `MODE_APPLICATION`: compartido entre todos los usuarios (el schema de la DB no depende del usuario)
- `ttl: 86400`: 24 horas — el schema rara vez cambia
- `simpledata: true`: los valores son arrays JSON-serializables, no objetos complejos

### 6.5 `db/upgrade.php` (MODIFY)

Agregar después del bloque existente (`$oldversion < 2026051401`):

```php
if ($oldversion < 2026051409) {
    // Purge schema cache — asegura que la nueva definición de cache
    // se use desde la primera request post-upgrade.
    cache_helper::purge_by_event('local_jedomi_schema_changed');

    upgrade_plugin_savepoint(true, 2026051409, 'local', 'jedomi');
}
```

### 6.6 `version.php` (MODIFY)

```php
$plugin->version   = 2026051409; // + chat proxy + function calling + schema_cache
```

### 6.7 `lib.php` (MODIFY)

En `local_jedomi_before_footer()`, dentro del array `$config` (después de `'model'` line 59):

```php
'chatproxy_url' => $CFG->wwwroot . '/local/jedomi/chatproxy.php',
```

## 7. Security Model

### Trust boundaries

```
[Browser / JS] ───POST──► [chatproxy.php] ───POST──► [AI API externa]
     │                        │                           │
     │ sesskey + SPT          │                           │
     │ (identidad +           │ API key (admin config)    │
     │  capabilities)         │                           │
```

1. **JS → chatproxy.php**: autenticación via sesskey + SPT firmado. El SPT prueba que el usuario tenía ciertos permisos cuando se generó la página. El proxy verifica la firma HMAC y el TTL de 5 min.

2. **chatproxy.php → AI API**: usa la `api_key` de la config del plugin. La API externa nunca recibe credenciales de Moodle.

3. **chatproxy.php → ejecución de tools**: el proxy ya conoce el rol del usuario (del SPT validado). Las tools se ejecutan bajo ese rol. `execute_sql` no necesita re-validar capability porque el proxy ya lo hizo — pero igual se deja `require_capability` como defensa en profundidad (capability check barato, no duele tenerlo).

### Restricciones tool por rol

| Tool | Admin | Manager | Teacher | Student | Mecanismo |
|------|-------|---------|---------|---------|-----------|
| `execute_sql` | ✅ | ✅ | ✅ | ❌ | `build_tools_for_role()` no incluye la definición de la tool student. La IA no puede llamar una tool que no existe en su lista. |
| `describe_table` | ✅ | ✅ | ✅ | ✅ | Todos pueden ver estructura de tablas (no datos). |
| `search_tables` | ✅ | ✅ | ✅ | ✅ | Todos pueden buscar tablas (no datos). |
| `get_site_config` | ✅ | ❌ | ❌ | ❌ | Solo incluida para admin. |

### Rate limiting

- Máximo 5 iteraciones de tool por request
- Timeout por iteración: 60 segundos
- El loop completo no debe exceder ~300 segundos
- No hay rate limiting por usuario en esta versión (el TTL del SPT de 5 min ya limita ventanas de ataque)

## 8. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **AI API call fails or infinite loops** | Medium | High — usuario espera sin respuesta | Max 5 tool iterations. Timeout de 60s por iteración. Si la API no responde en 60s, se corta el loop y se devuelve error SSE. |
| **SQL injection via tool parameters** | Low | Critical — filtración de datos | `execute_sql` ya enforce SELECT-only + parameter binding. La IA solo puede generar el SQL, no inyectar directamente. |
| **SPT replay attack** | Low | Medium — alguien reusa un SPT robado | TTL 5 min + binding a sesskey. Si la sesión expiró, el SPT es inválido aunque esté dentro del TTL. |
| **SSE stream breaks mid-response** | Low | Medium — usuario ve respuesta incompleta | JS sidebar ya maneja errores de conexión (patrón existente). El proxy puede reintentar 1 vez si la llamada a API falla antes de emitir contenido. |
| **Cache stampede en schema_cache** | Low | Low — múltiples requests simultáneos intentan regenerar el cache | 24h TTL + Moodle Cache API con lock implícito (MODE_APPLICATION ya lo maneja via el store configurado). |
| **AI revela datos de otros usuarios via tool** | Low | High — la IA podría pedir datos que no debería ver | `execute_sql` corre bajo el contexto del usuario actual. Las capabilities del SPT limitan lo que puede ver. Adicionalmente, el system prompt instruye respetar privacidad. |
| **AI abuse: demasiadas llamadas a tool** | Medium | Medium — consumo excesivo de recursos | Max 5 tool calls por request. Timeout por query de 5 segundos. Row limit de 100 filas. Esto hace que incluso 5 calls sean rápidas y ligeras. |

## 9. System Prompt Design

El mensaje `system` que `chatproxy.php` inyecta debe darle contexto a la IA sobre Moodle y las tools disponibles. Algo como:

```
Eres un asistente de IA integrado en Moodle ({$sitename}).
Tu función es ayudar al usuario ({$role}) respondiendo preguntas sobre
el sitio usando las tools disponibles.

REGLAS:
- Usa execute_sql SOLO para consultas SELECT. Nunca intentes modificar datos.
- Cuando necesites entender la estructura de una tabla, usa describe_table primero.
- Para encontrar tablas relevantes, usa search_tables.
- {si admin: Puedes consultar configuración del sitio con get_site_config.}
- Si no estás segura de qué tabla usar, busca primero con search_tables.
- Siempre usa parámetros vinculados (?) en lugar de concatenar valores en SQL.
- Explica QUÉ datos consultaste y POR QUÉ son relevantes.
- Si una consulta devuelve 0 filas, dilo explícitamente.
- No inventes datos. Si no encuentras información, dilo.
- Respetá la privacidad: no reveles datos personales de otros usuarios
  a menos que sea necesario para responder y tengas permiso.

Contexto actual:
- Curso: {$course_name} (ID: {$course_id})
- Usuario: {$user_fullname} (rol: {$role})
```

## 10. Effort Estimate

| Component | Estimated time | Notes |
|-----------|---------------|-------|
| `table_descriptor.php` | 2-3 hours | Queries INFORMATION_SCHEMA, cache integration, testing con tabla real |
| `chatproxy.php` | 4-6 hours | SPT validation, tool loop, SSE streaming, error handling, AI API client |
| `execute_sql.php` $skip_hmac | 30 min | Agregar parámetro, condicional, verificar que WS sigue funcionando |
| `db/caches.php` | 15 min | Una definición de cache |
| `db/upgrade.php` | 15 min | Purge del cache |
| `version.php` | 5 min | Bump version string |
| `lib.php` config | 15 min | Una línea en el array de configuración |
| Testing | 1-2 hours | Probar con cada rol, edge cases, SSE streaming, timeouts |
| **Total** | **~8-12 hours** | |

## 11. Future Considerations (out of scope for this proposal)

- **Tool result verification**: que el proxy verifique que el resultado de la tool tiene sentido antes de pasarlo a la IA
- **More tools**: `get_user_info`, `get_course_info`, `list_activities` — tools más semánticas que SQL crudo
- **Streaming de tool execution al JS**: emitir SSE con el progreso de cada tool call para feedback visual al usuario
- **Automatic cache invalidation**: escuchar eventos Moodle (course_updated, user_deleted) para purgar schema_cache
- **Conversation history persistence**: guardar historial de chat + tool calls para auditoría
- **Observability**: métricas de uso de tools por rol, latencia, tasa de éxito/fracaso
