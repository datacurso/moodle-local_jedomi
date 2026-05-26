# Proposal: Semantic Cache — Replay Tool Chain + LLM Reformulation

## Intent

La cache actual guarda texto plano (la respuesta final del LLM). En la próxima ejecución del agente, devuelve ese mismo texto aunque los datos hayan cambiado. Necesitamos que el cache guarde el **plan de ejecución** (qué tools llamar y con qué argumentos) para re-ejecutarlas y obtener datos frescos, reformulando la respuesta con el LLM.

## Scope

### In Scope
- Almacenar el plan de tools (orden + argumentos) durante cache MISS
- En cache HIT: re-ejecutar todas las tools del plan, luego 1 round de LLM para reformular
- Columna `plan_json` en `local_jedomi_agent_cache`
- Detectar y excluir tools destructivas del plan (solo read-only)
- Limpiar cache existente (texto plano) en upgrade
- Actualizar `agent_preview.php` para que también almacene/use planes

### Out of Scope
- Template interpolation automática (Fase 2/3 futura)
- TTL por agente
- Cache tagging o invalidación por eventos de Moodle
- Per-agent config para habilitar/deshabilitar semantic cache

## Capabilities

### New Capabilities
- `semantic-cache`: Almacena y reproduce planes de ejecución de tools con datos frescos

### Modified Capabilities
- None

## Approach

**Fase 1 — Tool Chain Recording + Replay:**

1. **Cache MISS** (agent_runner.php): durante el loop multi-turn, registrar cada tool call (nombre + argumentos) en un array `$plan[]`. Al finalizar, guardar `plan_json` en cache junto al `answer` (texto original como referencia).
2. **Cache HIT**: cargar `plan_json`, re-ejecutar cada tool, obtener datos frescos. Hacer 1 llamado al Gateway con: datos frescos + answer original como contexto → modelo reformula.
3. **Destructive tools**: si el plan contiene tools de escritura (clasificadas por `local_jedomi_classify_ws_access()`), NO cachear plan — forzar MISS siempre.
4. **Upgrade**: agregar columna `plan_json MEDIUMTEXT` y purgar cache textual existente.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `classes/task/agent_runner.php` | Modified | Grabar plan en MISS, replay en HIT con reformulación |
| `classes/agent/agent_manager.php` | Modified | Métodos `get_plan()`, `set_plan()`, `has_plan()` |
| `db/install.xml` | Modified | Columna `plan_json` MEDIUMTEXT en `local_jedomi_agent_cache` |
| `db/upgrade.php` | Modified | Migration + purge cache vieja |
| `ajax/agent_preview.php` | Modified | Almacenar plan también en preview cache |
| `version.php` | Modified | Bump |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Tool args con fechas fijas (ej: `WHERE timecreated > 1710000000`) | Medium | SQL con funciones relativas (`NOW()`, `UNIX_TIMESTAMP()`) se auto-refresca. Args literales quedan como están — el dato es el que corresponde a ese filtro |
| Tool permission changes entre MISS y HIT | Low | Cada tool ya valida permisos en su `execute()` — falla naturalmente |
| Destructive tools en plan | Medium | Clasificar con `local_jedomi_classify_ws_access()` — solo cachear planes read-only |

## Rollback Plan

- Revertir cambios en `agent_runner.php`, `agent_manager.php`
- `upgrade.php`: eliminar step de `plan_json`
- Cache textual anterior se recupera purgando y forzando MISS

## Dependencies

- Ninguna externa

## Success Criteria

- [ ] Cache MISS → plan grabado con tools + args
- [ ] Cache HIT → tools re-ejecutadas, respuesta con datos frescos
- [ ] Tools destructivas NUNCA se cachean como plan
- [ ] Preview panel también usa semantic cache
- [ ] Tests existentes pasan
