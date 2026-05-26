# Proposal: fix-telegram-stuck-loop

## Intent

Eliminar el stuck-loop permanente en el bot de Telegram donde los usuarios reciben
"⏳ Sigo procesando tu solicitud anterior..." infinitamente, bloqueando el uso
del bot hasta que el cleanup task (30 min) o un reinicio manual libera el lock.

## Scope

**Dentro del alcance:**
1. Liberación del lock (`is_locked`) en las 3 rutas de error de `proxy_to_gateway()`
2. TTL de 120s en el lock `is_locked` para evitar bloqueos permanentes
3. Campo `locked_at` en el estado `tool_loop` para soportar el TTL
4. Actualización de `lastactivity` en `session::set_context()` para que el cleanup task pueda detectar locks stale
5. Medium guard de 20s → 40s para reducir self-pings excesivos

**Fuera del alcance:**
- Refactor de la arquitectura de locks (GET_LOCK de MySQL sigue siendo el mecanismo de dedup principal)
- Cleanup de sesiones stale existentes en producción (operación manual si es necesaria)
- Cambios en el web handler principal (/start, linking, rate limiting)
- Mejoras en el SSE parser o manejo de timeouts de Gateway

## Approach

### Fix 1: Liberar is_locked en las 3 rutas de error de proxy_to_gateway()

**Archivo:** `classes/telegram/webhook_handler.php`

Actualmente en la línea 789 (`return` tras cURL error no-timeout), línea 794
(`return` tras HTTP non-200), y línea 802 (`return` tras SSE parse failure),
se sale de la función sin llamar a `session::set_context($state_key, [])`.

```php
// Antes (ejemplo línea 793-794):
if ($http_code !== 200) {
    (new api_client())->send_message($chat_id, get_string('error_telegram_e005', 'local_jedomi'));
    return; // ← is_locked NUNCA se libera
}

// Después:
if ($http_code !== 200) {
    (new api_client())->send_message($chat_id, get_string('error_telegram_e005', 'local_jedomi'));
    session::set_context($state_key, []); // ← libera el lock
    return;
}
```

**3 puntos a corregir:**
- Línea 786-788: cURL error (no timeout) — antes del `return` agregar `session::set_context($state_key, [])`
- Línea 793-794: HTTP non-200 — antes del `return` agregar `session::set_context($state_key, [])`
- Línea 801-802: SSE parse failure — antes del `return` agregar `session::set_context($state_key, [])`

### Fix 2: Agregar TTL check de 120s en is_locked

**Archivo:** `classes/telegram/webhook_handler.php`, bloque de lock check (líneas 446-451)

```php
// Antes:
$existing = session::get_context($state_key);
if (!empty($existing['is_locked'])) {
    (new api_client())->send_message($chat_id,
        '⏳ Sigo procesando tu solicitud anterior, dame un momento...');
    return;
}

// Después:
$existing = session::get_context($state_key);
if (!empty($existing['is_locked'])) {
    $lock_ttl = 120; // 2 minutes
    $locked_at = (int)($existing['locked_at'] ?? 0);
    if ($locked_at === 0 || (time() - $locked_at) < $lock_ttl) {
        (new api_client())->send_message($chat_id,
            '⏳ Sigo procesando tu solicitud anterior, dame un momento...');
        return;
    }
    // Lock expired — release it and proceed
    error_log('[GATELOOP] stale_lock_released chat=' . $chat_id
        . ' locked_at=' . $locked_at . ' age=' . (time() - $locked_at) . 's');
    session::set_context($state_key, []);
}
```

### Fix 3: Agregar locked_at al state de tool_loop

**Archivo:** `classes/telegram/webhook_handler.php`

Hay 4 lugares donde se setea `$state['is_locked'] = true`:
- Línea 534: inicialización del estado (normal flow)
- Línea 581: self-ping flow (re-lock)
- Línea 686: hard guard self-ping
- Línea 700: medium guard self-ping
- Línea 1114: checkpoint save tras tool execution

En todos ellos, agregar también `$state['locked_at'] = time()`.

### Fix 4: Actualizar lastactivity en session::set_context()

**Archivo:** `classes/telegram/session.php`, método `set_context()` (líneas 119-149)

```php
// Dentro del bloque if ($record):
$record->usercontext = $newctx_json;
$record->iteration = $newiter;
$record->lastactivity = time(); // ← AGREGAR esta línea
$DB->update_record('local_jedomi_telegram_sessions', $record);
```

Sin esto, el cleanup task (que elimina rows con `lastactivity < now - 30min`)
nunca limpia las tool_loop states atascadas porque `set_context()` jamás
actualiza `lastactivity`. La row se queda para siempre.

### Fix 5: Medium guard de 20s → 40s

**Archivo:** `classes/telegram/webhook_handler.php`, línea 696

```php
// Antes:
if ($elapsed >= 20 && $iteration > 0) {

// Después:
if ($elapsed >= 40 && $iteration > 0) {
```

El medium guard existe porque FPM `request_terminate_timeout` suele ser 30s
en hosts compartidos. La idea es self-ping antes de que FPM mate el worker.
Pero 20s es demasiado agresivo — causa 2-3 self-pings innecesarios en flujos
normales. 40s da margen para que la mayoría de las requests completen sin
self-ping, y sigue siendo seguro porque FPM está en 90s+ en hosts decentes.

## Risks

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|-------------|---------|------------|
| TTL muy bajo (120s) libera locks que aún están activos | Baja | Medio | El tool loop guarda checkpoint en DB cada iteración, perder una iteración solo causa un pequeño retroceso |
| Self-ping escribe locked_at viejo sobre estado nuevo | Baja | Bajo | Optimistic concurrency en set_context() protege contra writes stale (línea 134: `$newiter <= $olditer`) |
| `/new` + self-ping race condition | Media | Bajo | El TTL check + locked_at + clear_context previo hacen que el race sea inofensivo |

## Verification

1. **Unit test**: Simular que proxy_to_gateway() encuentra is_locked=true con locked_at=0 → debe mostrar mensaje y retornar
2. **Unit test**: Simular is_locked=true con locked_at > 120s atrás → debe liberar lock y continuar
3. **Unit test**: Verificar que error paths (cURL error, HTTP non-200, SSE parse fail) llaman a session::set_context([ ])
4. **Unit test**: Verificar que set_context() actualiza lastactivity
5. **Manual**: Enviar mensaje, matar worker antes de que termine, enviar otro mensaje → no debe quedar stuck

## Impact Analysis

| File | Change |
|------|--------|
| `classes/telegram/webhook_handler.php` | ~15 lines changed across 5 locations |
| `classes/telegram/session.php` | 1 line added to set_context() |
| `CHANGES.md` | Add entry "Fix Telegram stuck loop: is_locked TTL, error path cleanup, medium guard 20→40s" |
| `version.php` | Bump `$plugin->version` |
