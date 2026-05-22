# Proposal: Internationalize all frontend strings via Moodle `get_string()`

## 1. Intent

Eliminate every hardcoded visible string in the JS frontend so the plugin is fully translatable through Moodle's standard `get_string()` + lang files system. Currently ~97 strings are hardcoded in English (with 9 accidentally in Spanish), only ~50 of which are passed through the PHP config. The remaining ~47 strings always show English/Spanish regardless of the user's language preference.

## 2. Scope

### In scope
- Quick action labels and messages (36 items across admin/manager/teacher/student)
- Context labels: `ctx_profile`, `ctx_calendar`, `ctx_files`
- Button labels, tooltips, placeholders, status messages, aria-labels
- Confirmation dialog strings
- Voice/recording status messages (Spanish hardcoded)
- File upload status messages (Spanish hardcoded)
- CSV download button text (Spanish hardcoded)
- Image preview alt text and label (Spanish hardcoded)
- Attach menu items: `S.attach`, `S.image`, `S.file`
- `S.thinking_label` → align with existing `thinking` key
- Add `ai_disclaimer` and `drop_files_here` to PHP config (already in lang files)

### Out of scope
- System prompts (line 1080–1123 of sidebar.js) — these are AI instructions, not UI strings
- Business logic labels in `getMoodlePageInfo()` (lines 1257–1263) — these are Moodle core concepts and the JS function is a fallback for link previews; low visibility
- SPT/auth generation in `lib.php`
- Model configuration
- Moodle core strings (accessed via existing `get_string()`)
- PHP-side strings in settings pages (already use `get_string()`)
- Telegram/profile templates

## 3. Naming Convention

All new string keys follow this convention:

```
qa_{role}_{action}_{suffix}
```

Where:
- **qa** = quick action (prefix for all quick action keys)
- **role** = `admin`, `manager`, `teacher`, `student`
- **action** = short verb/noun like `top_student`, `at_risk`, `performance`, `inactive`, `overview`
- **suffix** = `label` or `msg`

**Examples:**
| Key | English Value |
|-----|---------------|
| `qa_admin_top_student_label` | Top student |
| `qa_admin_top_student_msg` | Who is the top student in course {$a}? |
| `qa_teacher_at_risk_label` | At risk |
| `qa_teacher_at_risk_msg` | Which students are at risk in course {$a}? |
| `qa_student_my_grade_label` | My grade |
| `qa_student_my_grade_msg` | What is my average in course {$a}? |

**Other new keys:**
| Key | English Value |
|-----|---------------|
| `ctx_profile` | Profile |
| `ctx_calendar` | Calendar |
| `ctx_files` | Files |
| `attach` | Attach |
| `image` | Image |
| `file` | File |

**Existing keys that need PHP config entry added:**
| Key | Notes |
|-----|-------|
| `ai_disclaimer` | Exists in lang files, not in PHP config |
| `drop_files_here` | Exists in lang files, not in PHP config |

**Fix key alignment:**
| JS Code | What it should use |
|---------|-------------------|
| `S.thinking_label` | Change to `S.thinking` (already exists in lang + config) |

### Placeholder format

Use Moodle `{$a}` in lang file strings. Since quick actions resolve `${courseName}` at JS runtime, we use `{$a}` in lang files and resolve either in PHP (when value is known at page-load) or in JS (when value depends on runtime URL context).

| Resolution | Strategy |
|------------|----------|
| Data known at page load | Resolve in PHP via `get_string('key', 'local_jedomi', $placeholder)` before passing to JS |
| Runtime-only data (courseName derived from current URL) | Keep `{$a}` in the string, do a simple `replace('{$a}', runtimeValue)` in JS |

## 4. Technical Approach

### 4.1 Placeholder resolution strategy

**Decision: Resolve in PHP where possible, use JS `replace('{$a}', val)` for runtime values.**

Rationale:
- Quick action messages use `${courseName}` which is computed at runtime in JS (line 694: `ctx.course_name ? `"${ctx.course_name}"` : (cid ? `con ID ${cid}` : 'actual')`)
- These values change on SPA navigation (watchNavigation in sidebar.js re-runs loadQuickActions)
- So we MUST keep quick action strings in JS config and do `'{$a}'.replace('{$a}', courseName)` in JS
- This is consistent with how `file_csv`, `file_pdf`, etc. already work (lines 1570-1574 use `.replace('{rows}', data.rows)`)

Implementation pattern:
```js
// In quick actions, when building msg:
(cfg.strings?.qa_admin_top_student_msg || 'Who is the top student in course {$a}?')
    .replace('{$a}', courseName)

// For non-dynamic strings, keep existing pattern:
S.key || 'fallback'
```

### 4.2 PHP config delivery

Quick action strings (labels + messages) will be computed and passed from `lib.php` in the `strings` array. Unlike statically-known UI strings, quick actions are role-dependent and numerous (~65 keys). We can either:

**Option A (recommended):** Pass ALL quick action keys regardless of role. JS picks what it needs. Simple, no role detection in PHP.

**Option B:** Role-filter in PHP, pass only strings for the current user's role. More complex but reduces payload.

**Decision: Option A — pass all keys.** The payload increase is ~65 short strings (~2KB). The complexity of role-filtering in PHP is not worth it, and quick actions are only rendered when the sidebar opens anyway.

### 4.3 `thinking_label` fix

Line 275 of sidebar.js uses `S.thinking_label || 'Jedomi is thinking…'`. The lang file and PHP config both use key `thinking`. This is a simple alignment fix: change JS to use `S.thinking` and the existing lang value `Thinking...` (en) / `Pensando...` (es) will be used.

### 4.4 Spanish hardcoded strings — conversion to English canonical

The canonical language file is English (`lang/en/local_jedomi.php`). All hardcoded Spanish strings must be:
1. Replaced with `S.key || 'English fallback'` pattern in JS
2. Added as lang keys to both en and es files
3. Added to PHP config array

The affected strings:

| Location (sidebar.js line) | Current text | New key | English value |
|---------------------------|--------------|---------|---------------|
| 305 | `alt="Imagen adjunta"` | `attached_image` | Attached image |
| 306 | `<span>Imagen adjunta</span>` | `attached_image` | Attached image |
| 1328 | `📥 Descargar CSV` | `csv_download` | 📥 Download CSV |
| 1422 | `⏹️ Silencio...` | `silence_detected` | ⏹️ Silence... |
| 1428 | `🎙️ Esperando voz...` | `waiting_voice` | 🎙️ Waiting for voice... |
| 1551 | `Procesando ${file.name}...` | `processing_file` | Processing {$a}... |
| 1580 | `[Archivo adjunto: ${file.name} — ${info}]` | `attached_file_info` | [Attached file: {$a}] |
| 1585 | `${file.name} cargado` | `file_loaded` | {$a} loaded |
| 1588 | `No se pudo procesar` | `file_process_error` | Could not process |

### 4.5 New keys summary

**Missing context labels** (3 keys — add to lang files + PHP config):
- `ctx_profile` → Profile / Perfil
- `ctx_calendar` → Calendar / Calendario
- `ctx_files` → Files / Archivos

**Missing attach menu keys** (3 keys — add to lang files + PHP config):
- `attach` → Attach / Adjuntar
- `image` → Image / Imagen
- `file` → File / Archivo

**Existing lang keys missing from PHP config** (2 keys — add to PHP config):
- `ai_disclaimer` → lang has it, PHP config doesn't
- `drop_files_here` → lang has it, PHP config doesn't

### 4.6 Quick action keys (65 new keys)

Each role's actions generate one `label` and one `msg` key per action:

**Admin in-course** (5 actions = 10 keys):
`qa_admin_top_student_label/msg`, `qa_admin_at_risk_label/msg`, `qa_admin_performance_label/msg`, `qa_admin_inactive_label/msg`, `qa_admin_missing_work_label/msg`

**Admin admin-location** (5 actions = 10 keys):
`qa_admin_active_users_label/msg`, `qa_admin_external_emails_label/msg`, `qa_admin_empty_cohorts_label/msg`, `qa_admin_gdpr_pending_label/msg`, `qa_admin_security_label/msg`

**Admin other-location** (4 actions = 8 keys):
`qa_admin_overview_label/msg`, `qa_admin_alerts_label/msg`, `qa_admin_top_courses_label/msg`, `qa_admin_storage_label/msg`

**Manager in-course** (4 actions = 8 keys):
`qa_manager_overview_label/msg`, `qa_manager_at_risk_label/msg`, `qa_manager_enrolled_label/msg`, `qa_manager_inactive_label/msg`

**Manager other** (4 actions = 8 keys):
`qa_manager_inactive_users_label/msg`, `qa_manager_top_courses_label/msg`, `qa_manager_global_risk_label/msg`, `qa_manager_no_teacher_label/msg`

**Teacher in-course** (4 + 2 conditional = up to 12 keys):
`qa_teacher_performance_label/msg`, `qa_teacher_at_risk_label/msg`, `qa_teacher_never_logged_label/msg`, `qa_teacher_missing_work_label/msg`, `qa_teacher_notify_group_label/msg`, `qa_teacher_top_students_label/msg`

**Teacher other** (2 actions = 4 keys):
`qa_teacher_my_courses_label/msg`, `qa_teacher_my_alerts_label/msg`

**Student in-course** (4 actions = 8 keys):
`qa_student_my_tasks_label/msg`, `qa_student_my_grade_label/msg`, `qa_student_my_progress_label/msg`, `qa_student_help_label/msg`

**Student other** (3 actions = 6 keys):
`qa_student_my_courses_label/msg`, `qa_student_my_grades_label/msg`, `qa_student_this_week_label/msg`

Total quick action keys: 65 (29 label + 36 msg, minus one duplicate where `label` doesn't apply for conditional actions)

## 5. Implementation Plan (Phased)

### Phase A — Quick Fixes (~1 hour)

**Task A1: Fix `thinking_label` → `thinking`**
- File: `amd/src/sidebar.js` line 275
- Change `S.thinking_label || 'Jedomi is thinking…'` to `S.thinking || 'Thinking...'`

**Task A2: Add missing PHP config entries**
- File: `lib.php` lines 64-135 (the `strings` array)
- Add entries for:
  - `'attach' => get_string('attach', 'local_jedomi')`
  - `'image' => get_string('image', 'local_jedomi')`
  - `'file' => get_string('file', 'local_jedomi')`
  - `'ai_disclaimer' => get_string('ai_disclaimer', 'local_jedomi')`
  - `'drop_files_here' => get_string('drop_files_here', 'local_jedomi')`

**Task A3: Add new lang keys for attach menu**
- File: `lang/en/local_jedomi.php` and `lang/es/local_jedomi.php`
- Add `$string['attach']`, `$string['image']`, `$string['file']`

**Task A4: Add context labels**
- File: `lib.php` strings config — add `'ctx_profile'`, `'ctx_calendar'`, `'ctx_files'`
- File: both lang files — add the three new string entries

### Phase B — Spanish Cleanup (~1.5 hours)

**Task B1: Replace image preview Spanish strings (lines 305-306)**
- Add lang keys for `attached_image`
- Replace hardcoded `alt="Imagen adjunta"` and `<span>Imagen adjunta</span>` with `S.attached_image`

**Task B2: Replace CSV download button (line 1328)**
- Change `📥 Descargar CSV` to `(cfg.strings?.csv_download || '📥 Download CSV')` — wait, emoji should stay, but actual text needs to change
- Actually, the whole string should use `S.csv_download || '📥 Download CSV'`
- Add key `csv_download` to lang files

**Task B3: Replace voice/recording Spanish strings (lines 1422, 1428)**
- Line 1422: Change to `(cfg.strings?.silence_detected || '⏹️ Silence...')`
- Line 1428: Change to `(cfg.strings?.waiting_voice || '🎙️ Waiting for voice...')`
- Add keys `silence_detected`, `waiting_voice`

**Task B4: Replace file upload Spanish strings (lines 1551, 1580, 1585, 1588)**
- Line 1551: Change to `(cfg.strings?.processing_file || 'Processing {$a}...').replace('{$a}', file.name)`
- Line 1580: Change to `(cfg.strings?.attached_file_info || '[Attached file: {$a}]').replace('{$a}', file.name + ' — ' + info)` — actually this is more complex because it concatenates multiple dynamic parts. Need to decide: single placeholder or multiple.
  - Best approach: use `{$a}` for the filename portion. The `— ${info}` part is already resolved from file type detection (lines 1570-1574). So: `(S.attached_file_info || '[Attached file: {$a}]').replace('{$a}', file.name) + ' — ' + info`
- Line 1585: Change to `(cfg.strings?.file_loaded || '{$a} loaded').replace('{$a}', file.name)`
- Line 1588: Change to `(cfg.strings?.file_process_error || 'Could not process').replace('...')` — note this is `\`⚠️ No se pudo procesar **${file.name}**: ${err.message}\`` so: `'⚠️ ' + (cfg.strings?.file_process_error || 'Could not process') + ' **' + file.name + '**: ' + err.message`
- Add keys: `processing_file`, `attached_file_info`, `file_loaded`, `file_process_error`

### Phase C — Quick Actions i18n (~4-6 hours)

**Task C1: Add ~65 lang keys to `lang/en/local_jedomi.php`**

All quick action labels and messages. Example entries:
```php
// ── Quick actions: Admin ──────────────────────────────────────────────
$string['qa_admin_top_student_label']   = 'Top student';
$string['qa_admin_top_student_msg']     = 'Who is the top student in course {$a}?';
$string['qa_admin_at_risk_label']      = 'At risk';
$string['qa_admin_at_risk_msg']        = 'Which students are at risk of failing in course {$a}?';
// ... (all 65 keys)
```

**Task C2: Add Spanish translations to `lang/es/local_jedomi.php`**
Same 65 keys with translated values. Example:
```php
$string['qa_admin_top_student_label']   = 'Mejor estudiante';
$string['qa_admin_top_student_msg']     = '¿Quién es el mejor estudiante en el curso {$a}?';
```

**Task C3: Pass all quick action strings in `lib.php` PHP config**
Add all 65 keys to the `config['strings']` array in order. Grouped by role for maintainability.

**Task C4: Refactor `loadQuickActions()` in `sidebar.js`**
Replace hardcoded `label`/`msg` with lang key lookups. The refactoring pattern:

```js
// Before:
{ icon: '🏆', label: 'Top student', msg: `Who is the top student in course ${courseName}?` }

// After:
{ icon: '🏆', label: S.qa_admin_top_student_label || 'Top student',
  msg: (S.qa_admin_top_student_msg || 'Who is the top student in course {$a}?').replace('{$a}', courseName) }
```

Important: NO logic changes. The action definitions, role conditions, icon map, and event handlers remain identical. Only `label` and `msg` values change to reference `S.` strings.

## 6. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Missing a string** during audit | Medium | High — untouched strings remain untranslated | Final review pass: grep for all `|| '` and all English text strings in render paths. Use a checklist of all UI locations. |
| **Breaking quick action logic** | Low | High — action buttons may show wrong messages or fail | Strict "no logic changes" policy during Phase C. Only touch `label:` and `msg:` lines. Add a `git diff --stat` review before commit. |
| **Backward compat: removing existing keys** | Low | Medium — outdated custom themes/plugins referencing keys | Only ADD keys. Never remove or rename existing keys in lang files or PHP config. Existing fallback patterns (`|| 'fallback'`) ensure existing keys continue working. |
| **Placeholder format mismatch** | Medium | Medium — `{$a}` in lang file but JS expects `{name}` or `${var}` | Audit every `get_string()` call with placeholders. JS replace must match the lang file placeholder format exactly. Use `{$a}` consistently in lang files, `'{$a}'.replace(...)` in JS. |
| **Lang file becomes too large** | Low | Low — file maintainability | Group keys by section with clear comments. The en file will grow from ~241 to ~310 lines, still manageable. |
| **Emoji in lang strings** | Medium | Low — emoji display varies by OS | Keep emoji in the lang strings (they're part of the label). This is the current design and changing it is out of scope. |

## 7. Effort Estimate

| Phase | Description | Estimated time |
|-------|-------------|---------------|
| **A** | Quick fixes: thinking_label, missing config entries, context labels, attach keys | ~1 hour |
| **B** | Spanish cleanup: 9 hardcoded strings → lang keys + JS changes | ~1.5 hours |
| **C** | Quick actions i18n: 65 lang keys, PHP config, JS refactoring | ~4-6 hours |
| **Buffer** | Edge cases, testing, PR review | ~1.5 hours |
| **Total** | | **~7-9 hours** |

### File-by-file change summary

| File | Changes |
|------|---------|
| `amd/src/sidebar.js` | ~15 string replacements (Phase A+B) + ~36 msg/label lines refactored (Phase C) |
| `lib.php` | ~70 new `get_string()` calls in the `strings` array |
| `lang/en/local_jedomi.php` | ~75 new string definitions |
| `lang/es/local_jedomi.php` | ~75 new string definitions (Spanish translations) |

### Verification checklist

After all phases:
- [ ] Open sidebar in all 4 roles (admin, manager, teacher, student)
- [ ] Verify quick action buttons show translated labels
- [ ] Click each quick action — verify the message uses translated text with course name correct
- [ ] Attach an image — verify preview text is translated
- [ ] Attach a file — verify status messages are translated
- [ ] Click CSV download button — verify button text is translated
- [ ] Enable voice input — verify "listening"/"silence"/"waiting" messages are translated
- [ ] Navigate to profile/calendar/files pages — verify context badges show translated labels
- [ ] Switch Moodle language to Spanish — verify all strings appear in Spanish
- [ ] Run `grunt build` — verify AMD module compiles without errors
