# Design: Fix Agent Tool Loop

## Technical Approach

Alinear `agent_runner.php` con `handler.php` (proxy web) en 5 puntos. El swap de tool definitions a `\local_jedomi_get_tool_definitions()` es el primario — las descripciones vía lang strings incluyen guía de flujo ("Usá SIEMPRE search_tables antes de describe_table o execute_sql") que el modelo necesita para no quedarse en loop. Los otros 4 puntos refuerzan.

## Architecture Decisions

### Decision: Reusar `\local_jedomi_get_tool_definitions()` como fuente única de tools

**Choice**: Reemplazar `tool_executor::get_definitions()` por `\local_jedomi_get_tool_definitions($role)` en el MISS payload.
**Alternatives considered**: (a) Modificar descripciones hardcodeadas en `tool_executor::get_tools()`, (b) Duplicar lang strings en agent_runner.
**Rationale**: Reusar la función existente da cero mantenimiento adicional — si cambian las descripciones en lang strings, ambos (web + agent) se actualizan juntos. `tool_executor::execute()` busca tools por nombre, y los nombres son idénticos.

### Decision: Eliminar post-tool system message

**Choice**: No agregar ningún mensaje tras el tool result, igual que `handler.php`.
**Alternatives considered**: (a) Cambiar texto a "Provide FINAL ANSWER", (b) Agregar mensaje user en vez de system.
**Rationale**: La web no agrega nada y funciona. El mensaje actual "You can call more tools if needed" incentiva activamente más tool calls. Sin mensaje, el modelo solo ve el tool result y decide si continuar o responder.

### Decision: Loop detection por hash de args

**Choice**: SHA256 de `nombre|signature(args)` con threshold >3.
**Alternatives considered**: (a) Contar solo por nombre de tool, (b) Threshold >2 como web.
**Rationale**: mismo nombre + args distintos es legítimo (ej. `execute_sql` con diferentes queries). Threshold >3 (vs web's >2) porque el agente puede necesitar más variaciones que un chat interactivo.

### Decision: Subir MAX_TOOL_ITERATIONS a 50

**Choice**: Cambiar de 10 a 50, igual que web.
**Rationale**: Con loop detection activo, 50 es un safety net, no un objetivo. El loop se corta por loop detection o por final answer mucho antes.

## Data Flow

```
MISS flow (post-fix):
  agent_runner
    │
    ├─ 1. Resolve role from agent creator (get_user_role_for_user)
    ├─ 2. Get tool definitions via \local_jedomi_get_tool_definitions($role)
    │      → lang strings with flow guidance
    ├─ 3. Build system prompt = agent prompt + build_tool_instructions (simplified)
    ├─ 4. Gateway call with native tools payload
    │
    └─ Loop (max 50):
         ├─ Parse native tool_calls + text TOOL_CALL blocks
         ├─ Hash-check: if same tool+args >3 → return error result, break
         ├─ Execute via tool_executor::execute()
         ├─ Inject role='tool' result (NO system message after)
         └─ If no more tool calls → final answer
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `classes/task/agent_runner.php` | Modify | 5 changes detailed below |

### Changes per line

| Lines | Change |
|-------|--------|
| 29 | `MAX_TOOL_ITERATIONS` = 10 → 50 |
| 48-87 | `build_tool_instructions()` simplify — remove TOOL_CALL format, add flow guidance matching lang strings |
| 413-415 | `tool_executor::get_definitions()` → `\local_jedomi_get_tool_definitions($role)` |
| 579-582 | Remove post-tool system message entirely |
| 548 (new) | Add hash tracking + loop detection break after `execute()` |

## Interfaces / Contracts

Sin nuevos interfaces. `\local_jedomi_get_tool_definitions(string $role): array` ya existe. Solo se agrega una dependencia: obtener el rol del creador vía `\local_jedomi_get_user_role_for_user(int $userid): string`.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Manual | MISS produce plan con tools variadas | Ejecutar agente, revisar log |
| Manual | Mismo tool+args 4+ veces → loop detection | Forzar con tool que devuelva datos que lleven a repetir |
| Manual | HIT replay no se ve afectado | Cache HIT no toca el loop |

## Migration / Rollout

No migration required. El cache existente (HIT) sigue funcionando porque no se cambia el schema ni el formato de cache.

## Open Questions

- None
