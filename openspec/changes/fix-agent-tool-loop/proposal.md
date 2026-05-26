# Proposal: Fix Agent Tool Loop — Align agent_runner with webhook_handler patterns

## Intent

El `agent_runner` (scheduled task) produce planes de baja calidad — el modelo llama `search_tables` 7-9 veces sin pasar a `execute_sql` ni producir respuesta. En cambio el `webhook_handler` (chat web/Telegram) con las mismas tools funciona correctamente.

Tras investigar, la **causa raíz** son dos diferencias acumulativas:

1. **Tool definitions distintas**: `agent_runner` usa `tool_executor::get_definitions()` con descripciones hardcodeadas en inglés genéricas. `webhook_handler` usa `local_jedomi_get_tool_definitions()` que carga descripciones de **language strings** con guía de flujo ("Usá SIEMPRE search_tables antes de describe_table o execute_sql").

2. **Post-tool system message**: `agent_runner` agrega un system message tras cada tool result diciendo "You can call more tools if needed", que incentiva al modelo a seguir llamando tools en vez de producir respuesta. `webhook_handler` NO agrega ningún mensaje post-tool — solo inserta el tool result como role 'tool' y sigue.

## Scope

### In Scope
- **Swap tool definitions**: agent_runner debe usar `\local_jedomi_get_tool_definitions()` (reusa la función web) en lugar de `tool_executor::get_definitions()`
- **Post-tool message**: eliminar system message post-tool result (alinear con webhook_handler)
- **Loop detection**: detectar mismas tool + args repetidas y cortar el loop forzando fallback
- **build_tool_instructions()**: actualizar descripciones para reflejar las mismas lang strings

### Out of Scope
- Refactorizar `tool_executor` internamente
- Cambiar MAX_TOOL_ITERATIONS (10 está bien)
- Cambiar webhook_handler

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `semantic-cache`: plan quality depende de MISS → este fix mejora la calidad del plan aguas arriba

## Approach

### 1. Swap tool definitions source (PRIMARY FIX)

En `agent_runner.php`, reemplazar:

```php
$tools = \local_jedomi\agent\tool_executor::get_definitions(
    $agent->id, (int)($agent->userid ?? 0)
);
```

por:

```php
$role = \local_jedomi_get_user_role_for_user(
    (int)($agent->userid ?? 0)
);
$tools = \local_jedomi_get_tool_definitions($role);
```

Esto hace que el modelo reciba las MISMAS definiciones de tools que en el chat web — incluyendo las descripciones en lang strings con guía de flujo ("Usá SIEMPRE search_tables antes de describe_table o execute_sql").

**¿Por qué funciona?** El `tool_executor::execute()` busca tools por nombre, y los nombres son idénticos (`search_tables`, `execute_sql`, etc.). Solo cambia la descripción que ve el modelo.

### 2. Eliminar post-tool system message

En `agent_runner.php:579-582`, reemplazar el actual system message que dice "You can call more tools if needed" — NO agregar ningún mensaje post-tool, igual que webhook_handler. El tool result se inserta como role 'tool' y el loop sigue sin intervención del sistema.

### 3. Loop detection

Agregar en `agent_runner.php` tracking de tool calls con hash de nombre+args. Si el mismo hash aparece ≥5 veces, log de warning y forzar break con fallback usando el último tool result.

Igual que `webhook_handler.php:1182-1201`.

### 4. build_tool_instructions()

Actualizar el system prompt embebido para reflejar las descripciones de lang strings:
- `search_tables`: "Search Moodle tables by intent or keywords. Use this BEFORE describe_table or execute_sql to find the correct table name."
- `execute_sql`: "Execute a read-only SELECT query on the Moodle database. Use AFTER search_tables to get actual data."

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `classes/task/agent_runner.php` | Modified | Tool definitions source, post-tool message, loop detection, system prompt |
| `lib.php` | Unchanged | Reused `local_jedomi_get_tool_definitions()` ya existe |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| tool_executor::execute() no encuentra tool porque nombre difiere | Low | Nombre idéntico en ambas fuentes |
| Rol no existe para el usuario | Low | `get_user_role_for_user` devuelve 'student' por defecto |
| Modelo deja de llamar tools necesarias | Low | Tools siguen disponibles en payload nativo |
| Loop detection falsos positivos | Low | Umbral 5 repeticiones |

## Rollback Plan

- Revertir cambios en `agent_runner.php`

## Dependencies

- Ninguna

## Success Criteria

- [ ] MISS produce plan con tool calls variadas (search_tables + describe_table + execute_sql), no solo search_tables repetido
- [ ] Post-tool message NO existe (silencio tras tool result)
- [ ] Loop detection corta si mismo tool+args aparece ≥5 veces
- [ ] HIT replay genera respuestas relevantes a la pregunta del agente
