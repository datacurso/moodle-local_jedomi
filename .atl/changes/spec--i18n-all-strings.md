# Spec: Internacionalización de la UI

## Phase A: Quick Fixes

**Effort:** ~1 hour

**Requirements:**

1. **A1 — Fix `thinking_label` → `thinking`**
   - En `amd/src/sidebar.js:275` cambiar `S.thinking_label || 'Jedomi is thinking…'` → `S.thinking || 'Thinking...'`
   - No tocar lang files ni PHP config: la key `thinking` ya existe en ambos.
   - Validar que el fallback en inglés coincide con el valor actual del lang file (`'Thinking...'`).

2. **A2 — Añadir entradas faltantes al PHP config (`lib.php:64-135`)**
   - Insertar en el array `config['strings']` (sección que corresponda):
     - `'attach' => get_string('attach', 'local_jedomi')`
     - `'image' => get_string('image', 'local_jedomi')`
     - `'file' => get_string('file', 'local_jedomi')`
     - `'ai_disclaimer' => get_string('ai_disclaimer', 'local_jedomi')`
     - `'drop_files_here' => get_string('drop_files_here', 'local_jedomi')`
   - `ai_disclaimer` y `drop_files_here` ya existen en ambos lang files, solo falta el binding PHP.
   - `attach`, `image`, `file` son keys nuevas que también deben crearse en los lang files (A3).

3. **A3 — Añadir keys al menú attach en lang files**
   - En `lang/en/local_jedomi.php` y `lang/es/local_jedomi.php`:
     - `$string['attach']` → English: `'Attach'`, Spanish: `'Adjuntar'`
     - `$string['image']` → English: `'Image'`, Spanish: `'Imagen'`
     - `$string['file']` → English: `'File'`, Spanish: `'Archivo'`
   - Insertar en orden alfabético dentro del bloque `// ── Image / file attachment ───`.

4. **A4 — Añadir context labels faltantes**
   - En `lang/en/local_jedomi.php` y `lang/es/local_jedomi.php` dentro del bloque `// ── Context labels (sidebar badge) ──`:
     - `$string['ctx_profile']` → English: `'Profile'`, Spanish: `'Perfil'`
     - `$string['ctx_calendar']` → English: `'Calendar'`, Spanish: `'Calendario'`
     - `$string['ctx_files']` → English: `'Files'`, Spanish: `'Archivos'`
   - En `lib.php` dentro del array `config['strings']`, sección "Context labels" (después de `ctx_messages`):
     - `'ctx_profile' => get_string('ctx_profile', 'local_jedomi')`
     - `'ctx_calendar' => get_string('ctx_calendar', 'local_jedomi')`
     - `'ctx_files' => get_string('ctx_files', 'local_jedomi')`

**Acceptance Criteria:**

- [ ] `S.thinking` resuelve a "Thinking..." (en) / "Pensando..." (es) en lugar del fallback hardcoded
- [ ] PHP config expone `attach`, `image`, `file`, `ai_disclaimer`, `drop_files_here`
- [ ] Lang files en/es contienen `attach`, `image`, `file`
- [ ] Lang files en/es contienen `ctx_profile`, `ctx_calendar`, `ctx_files`
- [ ] PHP config expone `ctx_profile`, `ctx_calendar`, `ctx_files`
- [ ] Ninguna key existente fue renombrada o eliminada
- [ ] Las keys nuevas se insertan en orden alfabético dentro de su sección

---

## Phase B: Spanish Cleanup

**Effort:** ~1.5 hours

**Requirements:**

1. **B1 — Reemplazar image preview Spanish hardcoded (sidebar.js:305-306)**
   - Línea 305: `alt="Imagen adjunta"` → `alt={S.attached_image || 'Attached image'}`
   - Línea 306: `<span>Imagen adjunta</span>` → `<span>{S.attached_image || 'Attached image'}</span>`
   - Crear key `attached_image` en lang files + PHP config.

2. **B2 — Reemplazar CSV download button (sidebar.js:1328)**
   - `📥 Descargar CSV` → `(cfg.strings?.csv_download || '📥 Download CSV')`
   - Crear key `csv_download` en lang files + PHP config.
   - El emoji 📥 se mantiene en el lang string (es parte del label visible).

3. **B3 — Reemplazar voice status messages (sidebar.js:1422, 1428)**
   - Línea 1422: `⏹️ Silencio...` → `(cfg.strings?.silence_detected || '⏹️ Silence...')`
   - Línea 1428: `🎙️ Esperando voz...` → `(cfg.strings?.waiting_voice || '🎙️ Waiting for voice...')`
   - Crear keys `silence_detected`, `waiting_voice` en lang files + PHP config.
   - Los emojis se mantienen en los lang strings.

4. **B4 — Reemplazar file processing messages (sidebar.js:1551, 1580, 1585, 1588)**
   - Línea 1551: `` Procesando ${file.name}... `` → `(cfg.strings?.processing_file || 'Processing {$a}...').replace('{$a}', file.name)`
   - Línea 1580: `` [Archivo adjunto: ${file.name} — ${info}] `` → `(S.attached_file_info || '[Attached file: {$a}]').replace('{$a}', file.name) + ' — ' + info` — se traduce solo el filename portion, `info` viene de file type detection (no es texto de UI).
   - Línea 1585: `` ${file.name} cargado `` → `(cfg.strings?.file_loaded || '{$a} loaded').replace('{$a}', file.name)`
   - Línea 1588: `` ⚠️ No se pudo procesar **${file.name}**: ${err.message} `` → `` `⚠️ ${cfg.strings?.file_process_error || 'Could not process'} **${file.name}**: ${err.message}` ``
   - Crear keys `processing_file`, `attached_file_info`, `file_loaded`, `file_process_error` en lang files + PHP config.

5. **B5 — Añadir entradas PHP config para todas las keys nuevas de Phase B**
   - En `lib.php` agregar `get_string()` calls para: `attached_image`, `csv_download`, `silence_detected`, `waiting_voice`, `processing_file`, `attached_file_info`, `file_loaded`, `file_process_error`.

**Scenarios:**

### B1: Image preview alt/label
- **Precondition:** User attaches an image to the chat
- **Action:** Image preview renders in the message thread
- **Expected:** `alt` attribute reads value from `S.attached_image`. `<span>` label reads value from `S.attached_image`. English: "Attached image", Spanish: "Imagen adjunta". Falls back to "Attached image" if key missing.

### B2: CSV download button
- **Precondition:** AI response includes CSV data
- **Action:** Download button renders below CSV preview
- **Expected:** Button text reads from `S.csv_download`. English: "📥 Download CSV", Spanish: "📥 Descargar CSV".

### B3: Voice status messages
- **Precondition:** User activates voice input and stops speaking
- **Action:** Status indicator shows "silence detected" message
- **Expected:** Line 1422 reads from `S.silence_detected`. English: "⏹️ Silence...", Spanish: "⏹️ Silencio...".
- **Precondition:** User activates voice input and waits
- **Action:** Status indicator shows "waiting for voice" message
- **Expected:** Line 1428 reads from `S.waiting_voice`. English: "🎙️ Waiting for voice...", Spanish: "🎙️ Esperando voz...".

### B4: File processing messages
- **Precondition:** User drops a file for processing
- **Action:** Status message "Processing {filename}..." appears
- **Expected:** Line 1551 reads from `S.processing_file`. English: "Processing {filename}...", Spanish: "Procesando {filename}...". `{$a}` placeholder resolves to `file.name`.
- **Precondition:** File processing completes
- **Action:** "Attached file: {filename} — {info}" message appears
- **Expected:** Line 1580 reads from `S.attached_file_info` for the bracketed prefix. English: "[Attached file: {filename}] — {info}", Spanish: "[Archivo adjunto: {filename}] — {info}".
- **Precondition:** File loads successfully
- **Action:** "{filename} loaded" confirmation appears
- **Expected:** Line 1585 reads from `S.file_loaded`. English: "{filename} loaded", Spanish: "{filename} cargado".
- **Precondition:** File processing fails
- **Action:** Error message appears with filename and error details
- **Expected:** Line 1588 reads from `S.file_process_error`. English: "⚠️ Could not process **{filename}**: {error}", Spanish: "⚠️ No se pudo procesar **{filename}**: {error}".

**Acceptance Criteria:**

- [ ] `attached_image`, `csv_download`, `silence_detected`, `waiting_voice` existen en lang files (en/es) y PHP config
- [ ] `processing_file`, `attached_file_info`, `file_loaded`, `file_process_error` existen en lang files (en/es) y PHP config
- [ ] Todas las líneas JS modificadas usan `S.key || 'English fallback'` (o `cfg.strings?.key || '...'` para keys que vienen por config)
- [ ] Los placeholders `{$a}` se resuelven correctamente en runtime JS via `.replace('{$a}', value)`
- [ ] Mensajes compuestos (line 1580, 1588) concatenan correctamente partes traducidas + dinámicas
- [ ] No hay texto hardcoded en español en las líneas modificadas
- [ ] Fallback patterns preservados para cada string

---

## Phase C: Quick Actions

**Effort:** ~4-6 hours

**Requirements:**

1. **C1 — Definir ~74 lang keys en `lang/en/local_jedomi.php`**
   - 37 acciones × 2 keys (label + msg) = 74 keys
   - Naming convention: `qa_{role}_{action}_{suffix}`
   - `suffix` = `label` | `msg`
   - Agrupar por rol con comentarios sección:
     ```php
     // ── Quick actions: Admin ──────────────────────────────────────
     $string['qa_admin_top_student_label'] = 'Top student';
     $string['qa_admin_top_student_msg']   = 'Who is the top student in course {$a}?';
     ```
   - Todas las `msg` keys usan `{$a}` para `courseName`
   - Para acciones sin contexto de curso (admin-location, other-location, manager-other, teacher-other, student-other), `msg` NO tiene `{$a}` porque no hay `courseName` disponible
   - Insertar en orden alfabético dentro de su bloque de rol, o al final del archivo en un bloque `// ── Quick actions ──` (según conveniencia de mantenimiento).

2. **C2 — Traducir al español en `lang/es/local_jedomi.php`**
   - Mismas 74 keys con valores en español rioplatense (voseo).
   - Ejemplo: `$string['qa_student_my_grade_msg'] = '¿Cuál es mi promedio en el curso {$a}?'`

3. **C3 — Pasar todas las quick action strings via PHP config (`lib.php`)**
   - Añadir bloque al final del array `config['strings']` con todas las keys de quick actions:
     ```php
     // ── Quick actions ──────────────────────────────────────────
     'qa_admin_top_student_label' => get_string('qa_admin_top_student_label', 'local_jedomi'),
     'qa_admin_top_student_msg'   => get_string('qa_admin_top_student_msg', 'local_jedomi'),
     ```
   - Todas las keys, sin filtro por rol (ver sección 4.2 del proposal — Option A).

4. **C4 — Refactorizar `loadQuickActions()` en `sidebar.js:680-775`**
   - NO cambiar lógica. Solo reemplazar valores de `label:` y `msg:`.
   - Pattern:
     ```js
     // Before:
     { icon: '🏆', label: 'Top student', msg: `Who is the top student in course ${courseName}?` }
     
     // After:
     { icon: '🏆', label: S.qa_admin_top_student_label || 'Top student',
       msg: (S.qa_admin_top_student_msg || 'Who is the top student in course {$a}?').replace('{$a}', courseName) }
     ```
   - Para mensajes SIN `courseName` (admin-location, etc.), no hay `.replace('{$a}', ...)`:
     ```js
     { icon: '👥', label: S.qa_admin_active_users_label || 'Active users',
       msg: S.qa_admin_active_users_msg || 'How many active users are there in the system?' }
     ```
   - Para teacher conditional actions (notify_group, top_students), aplicar el mismo patrón en los `.push(...)`.

**Acciones y su naming (37 acciones, 74 keys):**

### Admin — in-course (5 acciones, 10 keys)
| key | label | msg tiene `{$a}` |
|-----|-------|-------------------|
| `qa_admin_top_student` | Top student | Who is the top student in course {$a}? |
| `qa_admin_at_risk` | At risk | Which students are at risk of failing in course {$a}? |
| `qa_admin_performance` | Performance | Show me the grade ranking for course {$a} |
| `qa_admin_inactive` | Inactive | Which students haven't accessed course {$a} in the last 30 days? |
| `qa_admin_missing_work` | Missing work | Which students haven't submitted activities in course {$a}? |

### Admin — admin-location (5 acciones, 10 keys)
| key | label | msg (sin `{$a}`) |
|-----|-------|-------------------|
| `qa_admin_active_users` | Active users | How many active users are there in the system? |
| `qa_admin_external_emails` | External emails | Are there users with non-institutional emails? |
| `qa_admin_empty_cohorts` | Empty cohorts | Are there cohorts with no members assigned? |
| `qa_admin_gdpr_pending` | GDPR pending | Are there pending GDPR deletion requests? |
| `qa_admin_security` | Security | Are there users with suspicious characters in their names? |

### Admin — other-location (4 acciones, 8 keys)
| key | label | msg (sin `{$a}`) |
|-----|-------|-------------------|
| `qa_admin_overview` | Overview | Give me a summary of the overall platform status |
| `qa_admin_alerts` | Alerts | What requires immediate attention today? |
| `qa_admin_top_courses` | Top courses | Which courses have the best performance? |
| `qa_admin_storage` | Storage | Which course has the heaviest files uploaded? |

### Manager — in-course (4 acciones, 8 keys)
| key | label | msg tiene `{$a}` |
|-----|-------|-------------------|
| `qa_manager_overview` | Overview | Give me a summary of course {$a} |
| `qa_manager_at_risk` | At risk | Which students are at risk in course {$a}? |
| `qa_manager_enrolled` | Enrolled | How many students are enrolled in course {$a}? |
| `qa_manager_inactive` | Inactive | Which students haven't accessed course {$a} in the last 14 days? |

### Manager — other (4 acciones, 8 keys)
| key | label | msg (sin `{$a}`) |
|-----|-------|-------------------|
| `qa_manager_inactive_users` | Inactive users | Which users haven't accessed the system in the last 30 days? |
| `qa_manager_top_courses` | Top courses | Which courses have the most activity this week? |
| `qa_manager_global_risk` | Global risk | Are there students at risk of failing in any course? |
| `qa_manager_no_teacher` | No teacher | Are there courses with no teacher assigned? |

### Teacher — in-course (6 acciones, 12 keys)
| key | label | msg tiene `{$a}` |
|-----|-------|-------------------|
| `qa_teacher_performance` | Performance | What is the average grade in course {$a}? |
| `qa_teacher_at_risk` | At risk | Which students are at risk in course {$a}? |
| `qa_teacher_never_logged` | Never logged in | Which students never accessed course {$a}? |
| `qa_teacher_missing_work` | Missing work | Who hasn't submitted the latest assignment in course {$a}? |
| `qa_teacher_notify_group` | Notify group | Draft a message for students in course {$a} reminding them of pending activities |
| `qa_teacher_top_students` | Top students | Who has more than 90 points in course {$a}? |

### Teacher — other (2 acciones, 4 keys)
| key | label | msg (sin `{$a}`) |
|-----|-------|-------------------|
| `qa_teacher_my_courses` | My courses | In which courses am I currently a teacher? |
| `qa_teacher_my_alerts` | My alerts | Are there students at risk in any of my courses? |

### Student — in-course (4 acciones, 8 keys)
| key | label | msg tiene `{$a}` |
|-----|-------|-------------------|
| `qa_student_my_tasks` | My tasks | What assignments do I have pending in course {$a}? |
| `qa_student_my_grade` | My grade | What is my average in course {$a}? |
| `qa_student_my_progress` | My progress | How many activities have I completed in course {$a}? |
| `qa_student_help` | Help | I don't understand the content of course {$a}. Can you explain? |

### Student — other (3 acciones, 6 keys)
| key | label | msg (sin `{$a}`) |
|-----|-------|-------------------|
| `qa_student_my_courses` | My courses | Which courses am I currently enrolled in? |
| `qa_student_my_grades` | My grades | What is my overall average across all my courses? |
| `qa_student_this_week` | This week | What activities and deadlines do I have coming up? |

**Resumen de keys nuevas:**
| Grupo | Cantidad acciones | Keys (label + msg) |
|-------|------------------|-------------------|
| Admin | 14 | 28 |
| Manager | 8 | 16 |
| Teacher | 8 | 16 |
| Student | 7 | 14 |
| **Total** | **37** | **74** |

**Acceptance Criteria:**

- [ ] Cada acción quick tiene `label` y `msg` como keys lang
- [ ] Naming convention `qa_{role}_{action}_{label|msg}` consistente
- [ ] Todas las keys se pasan via PHP config sin filtro de rol
- [ ] `msg` keys con `{$a}` aplican `.replace('{$a}', courseName)` en JS runtime
- [ ] `msg` keys sin `{$a}` NO aplican replace
- [ ] Zero cambios de lógica — solo se modifican `label:` y `msg:` en action definitions
- [ ] Conditional actions (teacher notify_group, top_students) también refactorizadas
- [ ] Fallback English preservado en cada `|| '...'`

---

## Acceptance Criteria (Global — aplica a todas las fases)

- [ ] **Cobertura total:** Toda string visible en el sidebar es translatable via Moodle lang system
- [ ] **Fallback pattern:** `S.key || 'English fallback'` (o `cfg.strings?.key || '...'`) preservado en todas las referencias JS
- [ ] **Backward compat:** Ninguna key existente fue removida o renombrada. Solo se ADD keys nuevas.
- [ ] **Orden alfabético:** Lang files mantienen keys ordenadas alfabéticamente dentro de cada sección comentada
- [ ] **Español rioplatense (voseo):** Todas las traducciones al español usan voseo ("preguntá", "verificá", "andá", "decime")
- [ ] **Placeholders `{$a}`:** Resuelven correctamente en runtime JS
- [ ] **Compilación AMD:** `grunt build` no produce errores

---

## Archivos afectados por fase

| Fase | Archivo | Tipo de cambio |
|------|---------|---------------|
| A | `amd/src/sidebar.js:275` | 1 reemplazo (thinking_label → thinking) |
| A | `lib.php:64-135` | +6 entradas al array strings |
| A, B, C | `lang/en/local_jedomi.php` | ~83 nuevas definiciones |
| A, B, C | `lang/es/local_jedomi.php` | ~83 nuevas definiciones (traducciones) |
| B | `amd/src/sidebar.js:305-306,1328,1422,1428,1551,1580,1585,1588` | 9 reemplazos |
| B | `lib.php:64-135` | +8 entradas al array strings |
| C | `amd/src/sidebar.js:680-775` | ~74 refactors label/msg (zero lógica) |
| C | `lib.php:64-135` | ~74 entradas al array strings |

---

## Verificación post-implementación

```bash
# 1. Compilar AMD
grunt build

# 2. Verificar que no hay strings hardcoded en español en sidebar.js
rg -n '(Imagen adjunta|Descargar CSV|Silencio|Esperando voz|Procesando|Archivo adjunto|cargado|No se pudo procesar)' amd/src/sidebar.js

# 3. Verificar que todas las keys nuevas existen en en/es lang files
rg -c "^\$string\['qa_" lang/en/local_jedomi.php
rg -c "^\$string\['qa_" lang/es/local_jedomi.php

# 4. Verificar que todas las keys nuevas existen en lib.php
rg "qa_" lib.php
```
