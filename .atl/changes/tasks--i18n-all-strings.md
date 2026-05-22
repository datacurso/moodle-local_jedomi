# Tasks: Internacionalización de la UI (i18n all strings)

---

## Phase A: Quick Fixes (8 tasks, ~1hr)

---

### A1: Fix `thinking_label` → `thinking`

- [ ] **File**: `amd/src/sidebar.js:275`
- **Before**: `${S.thinking_label || 'Jedomi is thinking\u2026'}`
- **After**: `${S.thinking || 'Thinking...'}`
- **Note**: `thinking` key already exists in lang files (en/es) and PHP config — only JS reference changes.
- **Verify**: `rg -n 'thinking_label' amd/src/sidebar.js` → 0 results. Open sidebar → "Thinking..." appears during AI response.

---

### A2: Add `attach`, `image`, `file` to `lang/en/local_jedomi.php`

- [ ] **File**: `lang/en/local_jedomi.php`, section `// ── Input area ──` (after line 15, before line 22)
- **Insert** in alphabetical order within section:
  ```php
  $string['attach']      = 'Attach';
  $string['attach_file'] = 'Attach PDF/CSV';   // already exists
  $string['attach_image']= 'Attach image';      // already exists
  $string['chat_placeholder'] = 'Ask Jedomi...';// already exists
  // 'file' goes after existing keys in this section, or in Image/file section:
  $string['file']        = 'File';
  $string['image']       = 'Image';
  $string['message_input'] = 'Message Jedomi';  // already exists
  $string['send']        = 'Send';              // already exists
  $string['voice_input'] = 'Voice input';        // already exists
  ```
- **Actual insert points**: `$string['attach']` before `$string['attach_file']`, `$string['file']` after `$string['attach_file']`, `$string['image']` after `$string['file']` — or put all three in `// ── Image / file attachment ──` section (line 31). Design doc shows them in Input area section. Choose ONE section per convention.
- **Verify**: `rg -c "^\$string\['attach'\]" lang/en/local_jedomi.php` → 1

---

### A3: Add `attach`, `image`, `file` to `lang/es/local_jedomi.php`

- [ ] **File**: `lang/es/local_jedomi.php`, same section positions as A2
- **Values**:
  - `$string['attach'] = 'Adjuntar';`
  - `$string['image'] = 'Imagen';`
  - `$string['file'] = 'Archivo';`
- **Verify**: `rg -c "^\$string\['(attach|image|file)'\]" lang/es/local_jedomi.php` → 3

---

### A4: Add `attach`, `image`, `file` to PHP config (`lib.php`)

- [ ] **File**: `lib.php:64-135` (strings array), in `// Input` section (after line 80 `'attach_file'`)
- **Add**:
  ```php
  'attach'        => get_string('attach',        'local_jedomi'),
  'image'         => get_string('image',         'local_jedomi'),
  'file'          => get_string('file',          'local_jedomi'),
  ```
- **Verify**: `rg "'(attach|image|file)' => get_string" lib.php` → 3 matches

---

### A5: Add `ai_disclaimer` and `drop_files_here` to PHP config (`lib.php`)

- [ ] **File**: `lib.php:64-135`, these keys EXIST in lang files but are MISSING from PHP config
- **Insert** `'ai_disclaimer'` near a footer/disclaimer section or in Files section (after line 90 `'file_text'`)
- **Insert** `'drop_files_here'` after `'file_text'` or in a new `// Footer` section
- **Values**:
  ```php
  'ai_disclaimer'   => get_string('ai_disclaimer',   'local_jedomi'),
  'drop_files_here' => get_string('drop_files_here', 'local_jedomi'),
  ```
- **Verify**: `rg "'(ai_disclaimer|drop_files_here)' => get_string" lib.php` → 2 matches. Open sidebar → disclaimer text shows. Drag file → drop zone text shows.

---

### A6: Add `ctx_profile`, `ctx_calendar`, `ctx_files` to `lang/en/local_jedomi.php`

- [ ] **File**: `lang/en/local_jedomi.php`, section `// ── Context labels (sidebar badge) ──` (lines 81-87)
- **Insert** in alphabetical order within section:
  ```php
  $string['ctx_calendar'] = 'Calendar';
  $string['ctx_course']   = 'Course';      // already exists
  $string['ctx_dashboard']= 'Dashboard';    // already exists
  $string['ctx_files']    = 'Files';
  $string['ctx_grades']   = 'Grades';       // already exists
  $string['ctx_messages'] = 'Messages';     // already exists
  $string['ctx_profile']  = 'Profile';
  ```
- **Verify**: `rg "'ctx_(profile|calendar|files)' =>" lang/en/local_jedomi.php` — wait, lang files use `$string['key']` format: `rg "ctx_profile" lang/en/local_jedomi.php` → 1

---

### A7: Add `ctx_profile`, `ctx_calendar`, `ctx_files` to `lang/es/local_jedomi.php`

- [ ] **File**: `lang/es/local_jedomi.php`, section `// ── Context labels (sidebar badge) ──` (lines 81-87)
- **Values**:
  - `$string['ctx_profile'] = 'Perfil';`
  - `$string['ctx_calendar'] = 'Calendario';`
  - `$string['ctx_files'] = 'Archivos';`
- **Verify**: `rg "ctx_(profile|calendar|files)" lang/es/local_jedomi.php` → 3 matches

---

### A8: Add `ctx_profile`, `ctx_calendar`, `ctx_files` to PHP config (`lib.php`)

- [ ] **File**: `lib.php:115-121` (context labels section), after `'ctx_messages'`
- **Add**:
  ```php
  'ctx_profile'  => get_string('ctx_profile',  'local_jedomi'),
  'ctx_calendar' => get_string('ctx_calendar', 'local_jedomi'),
  'ctx_files'    => get_string('ctx_files',    'local_jedomi'),
  ```
- **Verify**: `rg "'ctx_(profile|calendar|files)' =>" lib.php` → 3 matches. Navigate to user profile → badge shows "Profile" (en) / "Perfil" (es).

---

## Phase B: Spanish Cleanup (12 tasks, ~1.5hr)

---

### B1: Replace image preview alt text (`sidebar.js:305`)

- [ ] **File**: `amd/src/sidebar.js:305`
- **Before**: `<img id="jedomi-preview-img" src="" alt="Imagen adjunta">`
- **After**: `<img id="jedomi-preview-img" src="" alt="${S.attached_image || 'Attached image'}">`
- **Verify**: Attach an image → `alt` attribute reads from `S.attached_image`. English: "Attached image". Spanish: "Imagen adjunta".

---

### B2: Replace image preview label (`sidebar.js:306`)

- [ ] **File**: `amd/src/sidebar.js:306`
- **Before**: `<span>Imagen adjunta</span>`
- **After**: `<span>${S.attached_image || 'Attached image'}</span>`
- **Note**: Shares key `attached_image` with B1.
- **Verify**: Attach an image → label text reads from `S.attached_image`.

---

### B3: Replace CSV download button (`sidebar.js:1328`)

- [ ] **File**: `amd/src/sidebar.js:1328`
- **Before**: ``const csvBtn = `<button class="jedomi-csv-btn" data-csv-download="true">📥 Descargar CSV</button>`;``
- **After**: ``const csvBtn = `<button class="jedomi-csv-btn" data-csv-download="true">${cfg.strings?.csv_download || '📥 Download CSV'}</button>`;``
- **Note**: Emoji `📥` stays in the lang string value. Uses `cfg.strings?.csv_download` (since it's in a template literal constructed at render time, not in the `S` shorthand scope of `injectHTML()`).
- **Verify**: `rg -n 'Descargar CSV' amd/src/sidebar.js` → 0 results. AI response with CSV data → button shows "📥 Download CSV" (en) / "📥 Descargar CSV" (es).

---

### B4: Replace silence detected message (`sidebar.js:1422`)

- [ ] **File**: `amd/src/sidebar.js:1422`
- **Before**: ``if (input) input.placeholder = `⏹️ Silencio... (${((SILENCE_STOP_MS - silentMs)/1000).toFixed(1)}s)`;``
- **After**: ``if (input) input.placeholder = `⏹️ ${cfg.strings?.silence_detected || 'Silence...'} (${((SILENCE_STOP_MS - silentMs)/1000).toFixed(1)}s)`;``
- **Note**: Emoji `⏹️` stays in JS. Only the text portion goes into the lang key. Timer `(Xs)` stays in JS (dynamic, not translatable).
- **Verify**: Activate voice, speak then stop → "⏹️ Silence... (Xs)" appears.

---

### B5: Replace waiting for voice message (`sidebar.js:1428`)

- [ ] **File**: `amd/src/sidebar.js:1428`
- **Before**: ``if (input) input.placeholder = '\ud83c\udfa4\ufe0f Esperando voz...';``
- **After**: ``if (input) input.placeholder = '\ud83c\udfa4\ufe0f ' + (cfg.strings?.waiting_voice || 'Waiting for voice...');``
- **Note**: Emoji `🎙️` stays in JS (it's `\ud83c\udfa4\ufe0f` in the source — verify exact escape sequence before editing). Concatenate with `+` operator.
- **Verify**: Activate voice without speaking → "🎙️ Waiting for voice..." appears.

---

### B6: Replace file processing message (`sidebar.js:1551`)

- [ ] **File**: `amd/src/sidebar.js:1551`
- **Before**: ``if (statusEl) statusEl.placeholder = `Procesando ${file.name}...`;``
- **After**: ``if (statusEl) statusEl.placeholder = (cfg.strings?.processing_file || 'Processing {$a}...').replace('{$a}', file.name);``
- **Verify**: Drop a file → placeholder shows "Processing filename..." (en) / "Procesando filename..." (es).

---

### B7: Replace attached file info (`sidebar.js:1580`)

- [ ] **File**: `amd/src/sidebar.js:1580`
- **Before**: ``input.value = `${prefix}[Archivo adjunto: ${file.name} — ${info}]\n\n${text}`;``
- **After**: ``input.value = `${prefix}${(cfg.strings?.attached_file_info || '[Attached file: {$a}]').replace('{$a}', file.name)} — ${info}\n\n${text}`;``
- **Note**: `— ${info}` stays outside the lang string — `info` comes from file type detection (lines 1570-1574, e.g. "CSV: 10 rows, 5 columns"), not UI text.
- **Verify**: Attach a file → input shows "[Attached file: filename] — CSV: 10 rows, 5 columns".

---

### B8: Replace file loaded confirmation (`sidebar.js:1585`)

- [ ] **File**: `amd/src/sidebar.js:1585`
- **Before**: ``addMessage('assistant', `\ud83d\udcce **${file.name}** cargado (${info}). Pod\u00e9s hacer preguntas sobre su contenido.`);``
- **After**: ``addMessage('assistant', `\ud83d\udcce **${file.name}** ${cfg.strings?.file_loaded || 'loaded'} (${info}). ${cfg.strings?.file_loaded_hint || 'You can ask questions about its content.'}`);``
- **Note**: Two natural language fragments → requires TWO keys:
  - `file_loaded` = `'loaded'` (en) / `'cargado'` (es) — no `{$a}` placeholder because `**${file.name}**` is already in the JS template literal.
  - `file_loaded_hint` = `'You can ask questions about its content.'` (en) / `'Pod\u00e9s hacer preguntas sobre su contenido.'` (es)
- **Verify**: File loads successfully → message shows "📎 **filename** loaded (info). You can ask questions about its content."

---

### B9: Replace file process error (`sidebar.js:1588`)

- [ ] **File**: `amd/src/sidebar.js:1588`
- **Before**: ``addMessage('assistant', `\u26a0\ufe0f No se pudo procesar **${file.name}**: ${err.message}`);``
- **After**: ``addMessage('assistant', `\u26a0\ufe0f ${cfg.strings?.file_process_error || 'Could not process'} **${file.name}**: ${err.message}`);``
- **Note**: Emoji `⚠️` stays in JS. Error details `${err.message}` stay in JS.
- **Verify**: File processing fails → message shows "⚠️ Could not process **filename**: error details".

---

### B10: Add Phase B lang keys to `lang/en/local_jedomi.php`

- [ ] **File**: `lang/en/local_jedomi.php`
- **Add 9 new keys** in their corresponding sections:

  **Image/file attachment section** (after line 31 or create sub-section):
  ```php
  $string['attached_file_info'] = '[Attached file: {$a}]';
  $string['attached_image']     = 'Attached image';
  $string['csv_download']       = '📥 Download CSV';
  $string['file_loaded']        = 'loaded';
  $string['file_loaded_hint']   = 'You can ask questions about its content.';
  $string['file_process_error'] = 'Could not process';
  $string['processing_file']    = 'Processing {$a}...';
  ```

  **Microphone section** (after line 71 `'listening'`):
  ```php
  $string['silence_detected']   = 'Silence...';
  $string['waiting_voice']      = 'Waiting for voice...';
  ```

- **Verify**: `rg "^\$string\['(attached_image|csv_download|silence_detected|waiting_voice|processing_file|attached_file_info|file_loaded|file_loaded_hint|file_process_error)'\]" lang/en/local_jedomi.php` → 9 matches

---

### B11: Add Phase B lang keys to `lang/es/local_jedomi.php`

- [ ] **File**: `lang/es/local_jedomi.php`
- **Add same 9 keys** with Spanish translations:

  **Image/file attachment section**:
  ```php
  $string['attached_file_info'] = '[Archivo adjunto: {$a}]';
  $string['attached_image']     = 'Imagen adjunta';
  $string['csv_download']       = '📥 Descargar CSV';
  $string['file_loaded']        = 'cargado';
  $string['file_loaded_hint']   = 'Podés hacer preguntas sobre su contenido.';
  $string['file_process_error'] = 'No se pudo procesar';
  $string['processing_file']    = 'Procesando {$a}...';
  ```

  **Microphone section**:
  ```php
  $string['silence_detected']   = 'Silencio...';
  $string['waiting_voice']      = 'Esperando voz...';
  ```

- **Verify**: `rg "^\$string\['(attached_image|csv_download|silence_detected|waiting_voice|processing_file|attached_file_info|file_loaded|file_loaded_hint|file_process_error)'\]" lang/es/local_jedomi.php` → 9 matches

---

### B12: Add Phase B keys to PHP config (`lib.php`)

- [ ] **File**: `lib.php:64-135`
- **Add 9 entries** in appropriate sections:

  **After `'file_text'` (line 90) or in Image/file attachment section**:
  ```php
  'attached_file_info' => get_string('attached_file_info', 'local_jedomi'),
  'attached_image'     => get_string('attached_image',     'local_jedomi'),
  'csv_download'       => get_string('csv_download',       'local_jedomi'),
  'file_loaded'        => get_string('file_loaded',        'local_jedomi'),
  'file_loaded_hint'   => get_string('file_loaded_hint',   'local_jedomi'),
  'file_process_error' => get_string('file_process_error', 'local_jedomi'),
  'processing_file'    => get_string('processing_file',    'local_jedomi'),
  ```

  **After `'listening'` (line 127) in Errors section**:
  ```php
  'silence_detected'   => get_string('silence_detected',   'local_jedomi'),
  'waiting_voice'      => get_string('waiting_voice',      'local_jedomi'),
  ```

- **Verify**: For each of the 9 keys, `rg "'KEY' => get_string" lib.php` → 1 match each

---

## Phase C: Quick Actions (23 tasks, ~4-6hr)

### C1–C4: Add QA keys to `lang/en/local_jedomi.php`

**C1** — Admin QA (28 keys: 14 actions × 2)
- [ ] **File**: `lang/en/local_jedomi.php`, new section `// ── Quick actions: Admin ──`
- **Keys**:
  ```php
  $string['qa_admin_active_users_label']    = 'Active users';
  $string['qa_admin_active_users_msg']      = 'How many active users are there in the system?';
  $string['qa_admin_alerts_label']          = 'Alerts';
  $string['qa_admin_alerts_msg']            = 'What requires immediate attention today?';
  $string['qa_admin_at_risk_label']         = 'At risk';
  $string['qa_admin_at_risk_msg']           = 'Which students are at risk of failing in course {$a}?';
  $string['qa_admin_empty_cohorts_label']   = 'Empty cohorts';
  $string['qa_admin_empty_cohorts_msg']     = 'Are there cohorts with no members assigned?';
  $string['qa_admin_external_emails_label'] = 'External emails';
  $string['qa_admin_external_emails_msg']   = 'Are there users with non-institutional emails?';
  $string['qa_admin_gdpr_pending_label']    = 'GDPR pending';
  $string['qa_admin_gdpr_pending_msg']      = 'Are there pending GDPR deletion requests?';
  $string['qa_admin_inactive_label']        = 'Inactive';
  $string['qa_admin_inactive_msg']          = 'Which students haven\'t accessed course {$a} in the last 30 days?';
  $string['qa_admin_missing_work_label']    = 'Missing work';
  $string['qa_admin_missing_work_msg']      = 'Which students haven\'t submitted activities in course {$a}?';
  $string['qa_admin_overview_label']        = 'Overview';
  $string['qa_admin_overview_msg']          = 'Give me a summary of the overall platform status';
  $string['qa_admin_performance_label']     = 'Performance';
  $string['qa_admin_performance_msg']       = 'Show me the grade ranking for course {$a}';
  $string['qa_admin_security_label']        = 'Security';
  $string['qa_admin_security_msg']          = 'Are there users with suspicious characters in their names?';
  $string['qa_admin_storage_label']         = 'Storage';
  $string['qa_admin_storage_msg']           = 'Which course has the heaviest files uploaded?';
  $string['qa_admin_top_courses_label']     = 'Top courses';
  $string['qa_admin_top_courses_msg']       = 'Which courses have the best performance?';
  $string['qa_admin_top_student_label']     = 'Top student';
  $string['qa_admin_top_student_msg']       = 'Who is the top student in course {$a}?';
  ```

**C2** — Manager QA (16 keys: 8 actions × 2)
- [ ] **File**: `lang/en/local_jedomi.php`, new section `// ── Quick actions: Manager ──`
- **Keys**:
  ```php
  $string['qa_manager_at_risk_label']       = 'At risk';
  $string['qa_manager_at_risk_msg']         = 'Which students are at risk in course {$a}?';
  $string['qa_manager_enrolled_label']      = 'Enrolled';
  $string['qa_manager_enrolled_msg']        = 'How many students are enrolled in course {$a}?';
  $string['qa_manager_global_risk_label']   = 'Global risk';
  $string['qa_manager_global_risk_msg']     = 'Are there students at risk of failing in any course?';
  $string['qa_manager_inactive_label']      = 'Inactive';
  $string['qa_manager_inactive_msg']        = 'Which students haven\'t accessed course {$a} in the last 14 days?';
  $string['qa_manager_inactive_users_label'] = 'Inactive users';
  $string['qa_manager_inactive_users_msg']  = 'Which users haven\'t accessed the system in the last 30 days?';
  $string['qa_manager_no_teacher_label']    = 'No teacher';
  $string['qa_manager_no_teacher_msg']      = 'Are there courses with no teacher assigned?';
  $string['qa_manager_overview_label']      = 'Overview';
  $string['qa_manager_overview_msg']        = 'Give me a summary of course {$a}';
  $string['qa_manager_top_courses_label']   = 'Top courses';
  $string['qa_manager_top_courses_msg']     = 'Which courses have the most activity this week?';
  ```

**C3** — Teacher QA (16 keys: 8 actions × 2)
- [ ] **File**: `lang/en/local_jedomi.php`, new section `// ── Quick actions: Teacher ──`
- **Keys**:
  ```php
  $string['qa_teacher_at_risk_label']       = 'At risk';
  $string['qa_teacher_at_risk_msg']         = 'Which students are at risk in course {$a}?';
  $string['qa_teacher_my_alerts_label']     = 'My alerts';
  $string['qa_teacher_my_alerts_msg']       = 'Are there students at risk in any of my courses?';
  $string['qa_teacher_my_courses_label']    = 'My courses';
  $string['qa_teacher_my_courses_msg']      = 'In which courses am I currently a teacher?';
  $string['qa_teacher_missing_work_label']  = 'Missing work';
  $string['qa_teacher_missing_work_msg']    = 'Who hasn\'t submitted the latest assignment in course {$a}?';
  $string['qa_teacher_never_logged_label']  = 'Never logged in';
  $string['qa_teacher_never_logged_msg']    = 'Which students never accessed course {$a}?';
  $string['qa_teacher_notify_group_label']  = 'Notify group';
  $string['qa_teacher_notify_group_msg']    = 'Draft a message for students in course {$a} reminding them of pending activities';
  $string['qa_teacher_performance_label']   = 'Performance';
  $string['qa_teacher_performance_msg']     = 'What is the average grade in course {$a}?';
  $string['qa_teacher_top_students_label']  = 'Top students';
  $string['qa_teacher_top_students_msg']    = 'Who has more than 90 points in course {$a}?';
  ```

**C4** — Student QA (14 keys: 7 actions × 2)
- [ ] **File**: `lang/en/local_jedomi.php`, new section `// ── Quick actions: Student ──`
- **Keys**:
  ```php
  $string['qa_student_help_label']          = 'Help';
  $string['qa_student_help_msg']            = 'I don\'t understand the content of course {$a}. Can you explain?';
  $string['qa_student_my_courses_label']    = 'My courses';
  $string['qa_student_my_courses_msg']      = 'Which courses am I currently enrolled in?';
  $string['qa_student_my_grade_label']      = 'My grade';
  $string['qa_student_my_grade_msg']        = 'What is my average in course {$a}?';
  $string['qa_student_my_grades_label']     = 'My grades';
  $string['qa_student_my_grades_msg']       = 'What is my overall average across all my courses?';
  $string['qa_student_my_progress_label']   = 'My progress';
  $string['qa_student_my_progress_msg']     = 'How many activities have I completed in course {$a}?';
  $string['qa_student_my_tasks_label']      = 'My tasks';
  $string['qa_student_my_tasks_msg']        = 'What assignments do I have pending in course {$a}?';
  $string['qa_student_this_week_label']     = 'This week';
  $string['qa_student_this_week_msg']       = 'What activities and deadlines do I have coming up?';
  ```

### C5–C8: Add QA keys to `lang/es/local_jedomi.php`

**C5** — Admin QA (28 keys, Spanish rioplatense voseo)
- [ ] **File**: `lang/es/local_jedomi.php`
- **Translate all 28 Admin QA keys** from C1. Examples:
  ```php
  $string['qa_admin_top_student_label'] = 'Mejor estudiante';
  $string['qa_admin_top_student_msg']   = '¿Quién es el mejor estudiante en el curso {$a}?';
  $string['qa_admin_at_risk_label']     = 'En riesgo';
  $string['qa_admin_at_risk_msg']       = '¿Qué estudiantes están en riesgo de perder el curso {$a}?';
  // ... full 28 keys
  ```

**C6** — Manager QA (16 keys, Spanish)
- [ ] **File**: `lang/es/local_jedomi.php`
- **Translate all 16 Manager QA keys** from C2.

**C7** — Teacher QA (16 keys, Spanish)
- [ ] **File**: `lang/es/local_jedomi.php`
- **Translate all 16 Teacher QA keys** from C3.

**C8** — Student QA (14 keys, Spanish)
- [ ] **File**: `lang/es/local_jedomi.php`
- **Translate all 14 Student QA keys** from C4.

### C9–C12: Add QA keys to PHP config (`lib.php`)

**C9** — Admin QA config (28 entries)
- [ ] **File**: `lib.php:64-135`
- **Add** all 28 Admin QA `get_string()` calls in a `// ── Quick actions ──` block:
  ```php
  'qa_admin_active_users_label' => get_string('qa_admin_active_users_label', 'local_jedomi'),
  'qa_admin_active_users_msg'   => get_string('qa_admin_active_users_msg',   'local_jedomi'),
  // ... all 28 Admin QA keys
  ```

**C10** — Manager QA config (16 entries)
- [ ] **File**: `lib.php`
- **Add** all 16 Manager QA `get_string()` calls.

**C11** — Teacher QA config (16 entries)
- [ ] **File**: `lib.php`
- **Add** all 16 Teacher QA `get_string()` calls.

**C12** — Student QA config (14 entries)
- [ ] **File**: `lib.php`
- **Add** all 14 Student QA `get_string()` calls.

### C13–C19: Refactor quick actions in `sidebar.js`

**Refactoring pattern for ALL tasks in this group:**

```js
// Before:
{ icon: '🏆', label: 'Top student', msg: `Who is the top student in course ${courseName}?` }

// After:
{ icon: '🏆', label: S.qa_admin_top_student_label || 'Top student',
  msg: (S.qa_admin_top_student_msg || 'Who is the top student in course {$a}?').replace('{$a}', courseName) }
```

**Rules:**
- ✅ Only `label:` and `msg:` values change
- ✅ `msg` WITH `{$a}` → has `.replace('{$a}', courseName)`
- ✅ `msg` WITHOUT `{$a}` → NO replace, just `S.key || 'English fallback'`
- ✅ Emoji/SVG icons stay as-is
- ✅ Role conditions, event handlers, DOM construction stay identical
- ✅ Fallback English preserved in every `|| '...'`

---

**C13** — Refactor Admin in-course QA (sidebar.js:704-710)
- [ ] **File**: `amd/src/sidebar.js:704-710`
- **5 actions** (`top_student`, `at_risk`, `performance`, `inactive`, `missing_work`)
- All have `{$a}` (course-based)
- **Verify**: Admin on course page → 5 QA buttons show translated labels. Click each → message contains course name.

**C14** — Refactor Admin admin-location QA (sidebar.js:711-718)
- [ ] **File**: `amd/src/sidebar.js:711-718`
- **5 actions** (`active_users`, `external_emails`, `empty_cohorts`, `gdpr_pending`, `security`)
- NO `{$a}` (system-level, no course context)
- **Verify**: Admin on `/admin/` page → 5 QA buttons show translated labels. Click → messages have NO course name.

**C15** — Refactor Admin other-location QA (sidebar.js:719-725)
- [ ] **File**: `amd/src/sidebar.js:719-725`
- **4 actions** (`overview`, `alerts`, `top_courses`, `storage`)
- NO `{$a}`
- **Verify**: Admin on dashboard → 4 QA buttons show translated labels.

**C16** — Refactor Manager QA (sidebar.js:727-741)
- [ ] **File**: `amd/src/sidebar.js:727-741`
- **8 actions**: 4 in-course (`overview`, `at_risk`, `enrolled`, `inactive`) with `{$a}` + 4 other (`inactive_users`, `top_courses`, `global_risk`, `no_teacher`) without `{$a}`
- **Verify**: Manager on course page → 4 in-course QA. Manager elsewhere → 4 other QA. All translated.

**C17** — Refactor Teacher in-course QA (sidebar.js:743-756)
- [ ] **File**: `amd/src/sidebar.js:743-756`
- **4 base + 2 conditional** = up to 6 actions (`performance`, `at_risk`, `never_logged`, `missing_work`, `notify_group`, `top_students`)
- All have `{$a}`
- **Note**: Conditional actions (lines 751-755) use `.push()` — apply same refactor pattern there.
- **Verify**: Teacher on course page, with messaging+grades caps → 6 QA buttons. All translated, course name resolves.

**C18** — Refactor Teacher other QA (sidebar.js:757-761)
- [ ] **File**: `amd/src/sidebar.js:757-761`
- **2 actions** (`my_courses`, `my_alerts`). NO `{$a}`.
- **Verify**: Teacher on dashboard → 2 QA buttons. Translated.

**C19** — Refactor Student QA (sidebar.js:763-777)
- [ ] **File**: `amd/src/sidebar.js:763-777`
- **7 actions**: 4 in-course (`my_tasks`, `my_grade`, `my_progress`, `help`) with `{$a}` + 3 other (`my_courses`, `my_grades`, `this_week`) without `{$a}`.
- **Verify**: Student in course → 4 QA with course name. Student elsewhere → 3 QA. All translated.

---

## Verification Section

Run these checks AFTER all tasks are completed:

### Compilation
- [ ] `npx grunt build` — AMD module compiles without errors
- [ ] `rg -n 'thinking_label' amd/src/sidebar.js` — 0 results
- [ ] `rg -n 'Imagen adjunta' amd/src/sidebar.js` — 0 results
- [ ] `rg -n 'Descargar CSV' amd/src/sidebar.js` — 0 results
- [ ] `rg -n 'Esperando voz' amd/src/sidebar.js` — 0 results
- [ ] `rg -n 'Procesando' amd/src/sidebar.js` — 0 results (verify only in comments or lang context)
- [ ] `rg -n 'Archivo adjunto' amd/src/sidebar.js` — 0 results
- [ ] `rg -n '\bcargado\b' amd/src/sidebar.js` — 0 results (no Spanish hardcoded)
- [ ] `rg -n 'No se pudo procesar' amd/src/sidebar.js` — 0 results

### Lang file completeness
- [ ] `rg -c "^\$string\['qa_" lang/en/local_jedomi.php` — 74
- [ ] `rg -c "^\$string\['qa_" lang/es/local_jedomi.php` — 74
- [ ] `rg "qa_" lib.php | measure-object | Select-Object -ExpandProperty Count` — 74 (or close, verify exact)

### Integration tests
- [ ] Set Moodle to English → sidebar shows all strings in English
- [ ] Set Moodle to Spanish → sidebar shows all strings in Spanish
- [ ] Admin role in course → 5 QA buttons show translated labels + messages
- [ ] Admin on `/admin/` → 5 system-level QA buttons translated
- [ ] Admin elsewhere → 4 QA buttons translated
- [ ] Manager in course → 4 QA buttons translated with course name
- [ ] Manager elsewhere → 4 QA buttons translated
- [ ] Teacher in course → 4-6 QA buttons translated with course name
- [ ] Teacher elsewhere → 2 QA buttons translated
- [ ] Student in course → 4 QA buttons translated with course name
- [ ] Student elsewhere → 3 QA buttons translated
- [ ] Attach image → preview shows "Attached image" (en) / "Imagen adjunta" (es)
- [ ] Attach file → "filename loaded (info). You can ask..." (en) / "filename cargado (info). Podés hacer..." (es)
- [ ] File processing error → "⚠️ Could not process **filename**: error" (en)
- [ ] CSV download button → "📥 Download CSV" (en) / "📥 Descargar CSV" (es)
- [ ] Voice input → "🔴 Listening..." / "⏹️ Silence..." / "🎙️ Waiting for voice..." translated
- [ ] Navigate to profile → badge shows "Profile" (en) / "Perfil" (es)
- [ ] Navigate to calendar → badge shows "Calendar" (en) / "Calendario" (es)
- [ ] Navigate to files → badge shows "Files" (en) / "Archivos" (es)
- [ ] Click each QA → message contains course name correctly resolved (for course-based actions)
- [ ] Disclaimer text shows correctly from lang key
- [ ] Drag file over sidebar → drop zone shows translated text

### Git diff sanity
- [ ] `git diff --stat` — only 4 files changed: `sidebar.js`, `lib.php`, `lang/en/local_jedomi.php`, `lang/es/local_jedomi.php`
- [ ] `git diff amd/src/sidebar.js` — ONLY string reference changes, zero logic changes in QA section
