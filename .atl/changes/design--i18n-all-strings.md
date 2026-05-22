# Technical Design: i18n via Moodle `get_string()` for all frontend strings

## 1. Data Flow Architecture

```
lang/en/local_jedomi.php          (source of truth: English)
  └─ $string['key'] = 'English value';
lang/es/local_jedomi.php          (translation layer)
  └─ $string['key'] = 'Spanish value';

lib.php                           (orchestration)
  └─ $config['strings'] = [
         'key' => get_string('key', 'local_jedomi'),
         // ^ resolves to current lang at page render time
     ];

$PAGE->requires->js_call_amd(
    'local_jedomi/sidebar',
    'init',
    [$config]                       // JSON-serialized to JS
);

sidebar.js init(config)            (AMD entry point)
  └─ cfg = config;
  └─ const S = cfg.strings || {};  // shorthand alias

DOM rendering:
  └─ S.key || 'English fallback'   // key from PHP → translated string | fallback
```

Key detail: `get_string()` resolves at **PHP render time** using Moodle's global `$CFG->lang`. When the user switches language, Moodle re-renders the page, `get_string()` picks up the new lang file, and the JS receives the correct strings. No client-side i18n library needed.

---

## 2. Module/Component Map

| Function | Lines (sidebar.js) | Strings Consumed | Phase |
|----------|-------------------|------------------|-------|
| `init()` | ~off-screen end | `cfg` is stored, `cfg.strings` is the global `S` | — |
| `injectHTML()` | 230–321 | `title`, `open_sidebar`, `close`, `new_conversation`, `export_conversation`, `half_width`, `full_width`, `greeting`, `hello`, `thinking`(fix), `placeholder`, `message_input`, `send`, `voice_input`, `attach`, `attach_image`, `image`, `file`, `attach_file`, `ai_disclaimer`, `drop_files_here`, `attached_image` (new) | A, B |
| `buildActionConfirmUI()` | 157–225 | `confirm_type_to_proceed`, `confirm_btn_yes`, `confirm_btn_no`, `confirm_executing`, `confirm_done`, `confirm_cancelled`, `connection_error` | — (already done) |
| `updateContextLabel()` | 638–663 | `ctx_course`, `ctx_activity`, `ctx_grades`, `ctx_admin`, `ctx_dashboard`, `ctx_messages`, `ctx_profile` (new), `ctx_calendar` (new), `ctx_files` (new) | A |
| `loadQuickActions()` | 680–802 | All `qa_*` keys (~65; Phase C) | C |
| `addMessageActions()` | 1145–1151 | `action_copy`, `action_helpful`, `action_improve`, `action_improve_title` | — (already done) |
| `toggleVoice()` / `_stopRecording()` | 1380–1438 | `placeholder`, `transcribing`, `listening`, `mic_error`, `silence_detected` (new), `waiting_voice` (new) | B |
| `handleFileSelect()` / around | ~1550–1593 | `file_csv`, `file_pdf`, `file_text`, `processing_file` (new), `attached_file_info` (new), `file_loaded` (new), `file_process_error` (new), `csv_download` (new) | B |

---

## 3. Key Design Decisions

### DD1: Pass all quick action keys unfiltered

Instead of role-filtering in PHP, pass ALL ~65 quick action keys in the `strings` array. JS picks what it needs based on `cfg.user_role` and `ctx.location`.

**Rationale**: ~2KB extra payload for ~65 short strings. Role detection in PHP would duplicate logic already present in JS (`loadQuickActions()` lines 701–777). Simpler to let the client decide.

```php
// lib.php — all QA keys in one block (no role condition):
'qa_admin_top_student_label' => get_string('qa_admin_top_student_label', 'local_jedomi'),
'qa_admin_top_student_msg'   => get_string('qa_admin_top_student_msg',   'local_jedomi'),
// ... all 65 keys
```

```js
// sidebar.js — JS already selects by role:
if (role === 'admin') {
    if (inCourse) {
        actions = [
            { icon: '🏆', label: S.qa_admin_top_student_label || 'Top student', ... },
        ];
    }
}
```

### DD2: `{$a}` placeholder resolved in JS

Quick action messages use `${courseName}` computed at runtime from URL context (`ctx.course_name || (cid ? `con ID ${cid}` : 'actual')`).

```js
// lang file stores Moodle placeholder format:
//   $string['qa_admin_top_student_msg'] = 'Who is the top student in course {$a}?';

// JS resolves at render time:
msg: (S.qa_admin_top_student_msg || 'Who is the top student in course {$a}?')
        .replace('{$a}', courseName)
```

**Rationale**: `courseName` changes on SPA navigation (`watchNavigation` re-runs `loadQuickActions`). Can't resolve in PHP because it depends on runtime URL parsing.

### DD3: Keep `S.key || 'English fallback'` pattern

Every string reference uses the fallback pattern:
```js
S.key || 'English fallback text'
```

**Rationale**: Backward compatibility. If a key is somehow missing from `cfg.strings` (e.g. old cache, partial deployment), the English fallback displays instead of `undefined` or blank. This also means the feature works even if the PHP config entry is accidentally omitted.

### DD4: Keys in alphabetical order within sections (lang files)

Moodle CI enforces `LangFilesOrdering` check. All new keys must be inserted in correct alphabetical position within their section, not appended at the end.

Example insertion point for `attach`:
```
// ── Input area ────────────────────────────────────────────
$string['attach_file']     = 'Attach PDF/CSV';
$string['attach_image']    = 'Attach image';
$string['chat_placeholder']= 'Ask Jedomi...';
// ↑ 'attach' would go BEFORE 'attach_file' alphabetically
```

### DD5: Spanish hardcoded strings → English canonicals

The 9 strings currently hardcoded in Spanish (sidebar.js lines 305–306, 1328, 1422, 1428, 1551, 1580, 1585, 1588) become:
1. Lang key in `lang/en/local_jedomi.php` with **English** value
2. Lang key in `lang/es/local_jedomi.php` with **Spanish** value
3. JS references `S.key || 'English fallback'`

**Rationale**: Canonical lang is English, as per Moodle conventions and existing patterns in the file.

---

## 4. Detailed Change Specifications

### 4.1 `lib.php` — strings array additions

**Phase A** — insert these entries into the `$config['strings']` array at the correct section:

```php
// In the "UI chrome" section (after 'full_width'):
'attach'            => get_string('attach',            'local_jedomi'),
'image'             => get_string('image',             'local_jedomi'),
'file'              => get_string('file',              'local_jedomi'),

// In the "Files" section (after 'file_text'):
'ai_disclaimer'     => get_string('ai_disclaimer',     'local_jedomi'),

// In the "Greeting" section (after 'hello' or 'thinking'):
// (no change — 'thinking' already exists)

// In the "Context labels" section (after 'ctx_messages'):
'ctx_profile'       => get_string('ctx_profile',       'local_jedomi'),
'ctx_calendar'      => get_string('ctx_calendar',      'local_jedomi'),
'ctx_files'         => get_string('ctx_files',          'local_jedomi'),

// Near the drop zone / footer area:
'drop_files_here'   => get_string('drop_files_here',   'local_jedomi'),
```

**Phase B** — add entries for the 9 Spanish→English conversions:

```php
// In a new "File/image preview" section (after 'attach_file'):
'attached_image'     => get_string('attached_image',     'local_jedomi'),
'csv_download'       => get_string('csv_download',       'local_jedomi'),

// In the "Microphone" section (after 'listening'):
'silence_detected'   => get_string('silence_detected',   'local_jedomi'),
'waiting_voice'      => get_string('waiting_voice',      'local_jedomi'),

// In the "Files" section (after 'file_text'):
'processing_file'    => get_string('processing_file',    'local_jedomi'),
'attached_file_info' => get_string('attached_file_info', 'local_jedomi'),
'file_loaded'        => get_string('file_loaded',        'local_jedomi'),
'file_process_error' => get_string('file_process_error', 'local_jedomi'),
```

**Phase C** — add ~65 `qa_*` entries. Grouped by role for maintainability:

```php
// Quick action strings (all roles — JS filters by role at runtime)
'qa_admin_top_student_label' => get_string('qa_admin_top_student_label', 'local_jedomi'),
'qa_admin_top_student_msg'   => get_string('qa_admin_top_student_msg',   'local_jedomi'),
// ... (all 65 keys, in alphabetical order)
```

### 4.2 `sidebar.js` — exact diffs per location

#### injectHTML() — line 275 (Phase A: `thinking_label` → `thinking`)

```diff
-<span class="jedomi-thinking-lbl">${S.thinking_label || 'Jedomi is thinking\u2026'}</span>
+<span class="jedomi-thinking-lbl">${S.thinking || 'Thinking...'}</span>
```

#### injectHTML() — lines 293–296 (Phase A: attach menu keys)

```diff
-<button id="jedomi-attach-btn" class="jedomi-input-icon" title="${S.attach || 'Attach'}">${SVG.plus}</button>
+<button id="jedomi-attach-btn" class="jedomi-input-icon" title="${S.attach || 'Attach'}">${SVG.plus}</button>
```

No change needed — `S.attach` is already referenced. Only missing was the PHP config entry (Phase A2).

```diff
-<button class="jedomi-attach-option" data-type="image" title="${S.attach_image || 'Attach image'}">${SVG.camera} ${S.image || 'Image'}</button>
-<button class="jedomi-attach-option" data-type="file" title="${S.attach_file || 'Attach file'}">${SVG.attach} ${S.file || 'File'}</button>
+<button class="jedomi-attach-option" data-type="image" title="${S.attach_image || 'Attach image'}">${SVG.camera} ${S.image || 'Image'}</button>
+<button class="jedomi-attach-option" data-type="file" title="${S.attach_file || 'Attach file'}">${SVG.attach} ${S.file || 'File'}</button>
```

No change — `S.image` and `S.file` already referenced. Only PHP config entries were missing.

#### injectHTML() — lines 305–306 (Phase B: Spanish hardcoded → `S.attached_image`)

```diff
-<img id="jedomi-preview-img" src="" alt="Imagen adjunta">
-<span>Imagen adjunta</span>
+<img id="jedomi-preview-img" src="" alt="${S.attached_image || 'Attached image'}">
+<span>${S.attached_image || 'Attached image'}</span>
```

#### injectHTML() — line 311 (Phase A: ai_disclaimer already referenced, config was missing)

```diff
-<span class="jedomi-disclaimer">${S.ai_disclaimer || 'Jedomi is an AI and can make mistakes.'}</span>
+<span class="jedomi-disclaimer">${S.ai_disclaimer || 'Jedomi is an AI and can make mistakes.'}</span>
```

No JS change. Only PHP config entry was missing.

#### injectHTML() — line 319 (Phase A: drop_files_here already referenced, config was missing)

```diff
-<span class="jedomi-dropzone-text">${S.drop_files_here || 'Drop files here'}</span>
+<span class="jedomi-dropzone-text">${S.drop_files_here || 'Drop files here'}</span>
```

No JS change. Only PHP config entry was missing.

#### updateContextLabel() — lines 650–652 (Phase A: new context labels)

```diff
 const labels = {
     course:    '\uD83D\uDCDA ' + (name || S.ctx_course    || 'Course'),
     activity:  '\uD83D\uDCDD ' + (name || ctx.activity_type || S.ctx_activity || 'Activity'),
     grades:    '\uD83D\uDCCA ' + (S.ctx_grades    || 'Grades'),
     admin:     '\u2699\uFE0F '  + (S.ctx_admin    || 'Administration'),
     dashboard: '\uD83C\uDFE0 ' + (S.ctx_dashboard || 'Dashboard'),
     messages:  '\uD83D\uDCAC ' + (S.ctx_messages  || 'Messages'),
-    profile:   '\uD83D\uDC64 Profile',
-    calendar:  '\uD83D\uDCC5 Calendar',
-    files:     '\uD83D\uDCC1 Files',
+    profile:   '\uD83D\uDC64 ' + (S.ctx_profile   || 'Profile'),
+    calendar:  '\uD83D\uDCC5 ' + (S.ctx_calendar  || 'Calendar'),
+    files:     '\uD83D\uDCC1 ' + (S.ctx_files     || 'Files'),
 };
```

#### toggleVoice() / _stopRecording() — lines 1419–1428 (Phase B: Spanish hardcoded)

```diff
 if (avg > SILENCE_THRESHOLD) {
     speechDetected = true;
     silentMs = 0;
     if (input) input.placeholder = '🔴 ' + (cfg.strings?.listening || 'Listening...');
 } else if (speechDetected) {
     silentMs += TICK_MS;
-    if (input) input.placeholder = `⏹️ Silencio... (${((SILENCE_STOP_MS - silentMs)/1000).toFixed(1)}s)`;
+    if (input) input.placeholder = `⏹️ ${cfg.strings?.silence_detected || 'Silence...'} (${((SILENCE_STOP_MS - silentMs)/1000).toFixed(1)}s)`;
     if (silentMs >= SILENCE_STOP_MS) {
         _stopRecording();
         return;
     }
 } else {
-    if (input) input.placeholder = '🎙️ Esperando voz...';
+    if (input) input.placeholder = '🎙️ ' + (cfg.strings?.waiting_voice || 'Waiting for voice...');
 }
```

Note: emojis stay in JS (they're presentation, not language). Only the text portion goes into lang files.

#### handleFileSelect() — line 1551 (Phase B: Spanish processing status)

```diff
-const origPlaceholder = statusEl?.placeholder;
-if (statusEl) statusEl.placeholder = `Procesando ${file.name}...`;
+const origPlaceholder = statusEl?.placeholder;
+if (statusEl) statusEl.placeholder = (cfg.strings?.processing_file || 'Processing {$a}...').replace('{$a}', file.name);
```

#### handleFileSelect() — line 1580 (Phase B: Spanish file info)

```diff
-input.value = `${prefix}[Archivo adjunto: ${file.name} — ${info}]\n\n${text}`;
+input.value = `${prefix}${(cfg.strings?.attached_file_info || '[Attached file: {$a}]').replace('{$a}', file.name)} — ${info}\n\n${text}`;
```

Note: `— ${info}` remains outside the lang string because `info` is already resolved from file type detection (csv/pdf/text at lines 1570–1574). Only `file.name` uses the `{$a}` placeholder.

#### handleFileSelect() — line 1585 (Phase B: Spanish loaded message)

```diff
-addMessage('assistant', `📎 **${file.name}** cargado (${info}). Podés hacer preguntas sobre su contenido.`);
+addMessage('assistant', `📎 **${file.name}** ${(cfg.strings?.file_loaded || '{$a} loaded').replace('{$a}', '')} (${info}). ${cfg.strings?.file_loaded_hint || 'You can ask questions about its content.'}`);
```

Wait — let me reconsider. The original line 1585:
```
addMessage('assistant', `📎 **${file.name}** cargado (${info}). Podés hacer preguntas sobre su contenido.`);
```

This has two separate natural language parts:
1. `{file.name} cargado` → "loaded"
2. `Podés hacer preguntas sobre su contenido.` → "You can ask questions about its content."

The cleanest approach is two separate keys:

```js
addMessage('assistant', `📎 **${file.name}** ${(cfg.strings?.file_loaded || '{$a} loaded').replace('{$a}', '')} (${info}). ${cfg.strings?.file_loaded_hint || 'You can ask questions about its content.'}`);
```

Actually, looking more carefully at the proposal, the loaded_message uses `{$a}` for the filename:

```js
addMessage('assistant', `${cfg.strings?.file_loaded_hint_emoji || '📎'} **${file.name}** ${(cfg.strings?.file_loaded || 'loaded')} (${info}). ${cfg.strings?.file_loaded_hint || 'You can ask questions about its content.'}`);
```

Hmm, let me keep it simpler and closer to what the proposal says. The proposal says:

Line 1585: Change to `(cfg.strings?.file_loaded || '{$a} loaded').replace('{$a}', file.name)`

But the current line 1585 has the format: `📎 **${file.name}** cargado (${info}). Podés hacer preguntas sobre su contenido.`

I think the right approach is to break the message into two separate lang strings. Let me design this more carefully:

Actually, this is a design doc, not implementation. I'll document the approach with clarity about the two-part message.

```js
// The current line 1585 contains TWO semantic units:
//   "📎 **{name}** loaded ({info}). You can ask questions about its content."
// This should use two keys:
//   file_loaded: "{a} loaded"
//   file_loaded_hint: "You can ask questions about its content."
```

#### handleFileSelect() — line 1588 (Phase B: Spanish error message)

```diff
-addMessage('assistant', `⚠️ No se pudo procesar **${file.name}**: ${err.message}`);
+addMessage('assistant', `⚠️ ${cfg.strings?.file_process_error || 'Could not process'} **${file.name}**: ${err.message}`);
```

#### CSV download — around line 1328 (Phase B: Spanish hardcoded)

Need to read the exact line 1328 to see the context:

Let me reference the proposal: line 1328 has `📥 Descargar CSV`. I need to find this in the file. Let me check around that area.

I'll reference what I know from the proposal. The change pattern:

```diff
-'📥 Descargar CSV'
+(cfg.strings?.csv_download || '📥 Download CSV')
```

#### loadQuickActions() — lines 696–777 (Phase C: all 65 QA keys)

The refactoring pattern for EVERY action entry:

```diff
// Before:
-{ icon: '🏆', label: 'Top student', msg: `Who is the top student in course ${courseName}?` }
+{ icon: '🏆',
+  label: S.qa_admin_top_student_label || 'Top student',
+  msg: (S.qa_admin_top_student_msg || 'Who is the top student in course {$a}?').replace('{$a}', courseName) }
```

This same pattern applies to all ~17 action groups across the 4 roles. Emoji icons remain hardcoded (they're presentation, mapped via `iconMap` at line 781). SVG icons (used by manager/teacher/student) also remain hardcoded — they reference `SVG.chart`, `SVG.alert`, etc. which are already defined at module scope.

**Important**: No logic changes. Only `label:` and `msg:` values change. The role conditions (`if (role === 'admin')`), emoji→SVG mapping (`iconMap`), event handlers, and DOM construction remain identical.

### 4.3 Lang file format

#### `lang/en/local_jedomi.php` — new key insertions

```php
// ── Input area ────────────────────────────────────────────────
$string['attach']           = 'Attach';
$string['attach_file']      = 'Attach PDF/CSV';
$string['attach_image']     = 'Attach image';
// ... existing keys ...
$string['file']             = 'File';
$string['image']            = 'Image';

// ── Image / file attachment ───────────────────────────────────
$string['ai_disclaimer']    = 'Jedomi is an AI and can make mistakes.';
$string['analyze_image']    = 'Analyze this image.';
$string['attached_file_info'] = '[Attached file: {$a}]';
$string['attached_image']   = 'Attached image';
$string['csv_download']     = '📥 Download CSV';
$string['drop_files_here']  = 'Drop files here';
// ... existing keys ...
$string['file_loaded']      = '{$a} loaded';
$string['file_process_error'] = 'Could not process';
$string['processing_file']  = 'Processing {$a}...';

// ── Context labels (sidebar badge) ────────────────────────────
$string['ctx_calendar']     = 'Calendar';
$string['ctx_course']       = 'Course';
$string['ctx_dashboard']    = 'Dashboard';
// ... existing keys ...
$string['ctx_files']        = 'Files';
$string['ctx_grades']       = 'Grades';
$string['ctx_messages']     = 'Messages';
$string['ctx_profile']      = 'Profile';

// ── Microphone ────────────────────────────────────────────────
$string['listening']        = 'Listening...';
$string['mic_error']        = 'Could not access the microphone...';
$string['silence_detected'] = 'Silence...';
$string['transcribing']     = 'Transcribing...';
$string['waiting_voice']    = 'Waiting for voice...';

// ── Quick actions: Admin ──────────────────────────────────────
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

// ── Quick actions: Manager ────────────────────────────────────
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

// ── Quick actions: Teacher ────────────────────────────────────
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

// ── Quick actions: Student ────────────────────────────────────
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

#### `lang/es/local_jedomi.php` — same keys with Spanish translations

Every key above gets a Spanish counterpart. Examples:

```php
$string['attach']              = 'Adjuntar';
$string['attached_image']      = 'Imagen adjunta';
$string['csv_download']        = '📥 Descargar CSV';
$string['ctx_calendar']        = 'Calendario';
$string['ctx_files']           = 'Archivos';
$string['ctx_profile']         = 'Perfil';
$string['file']                = 'Archivo';
$string['file_loaded']         = '{$a} cargado';
$string['file_process_error']  = 'No se pudo procesar';
$string['image']               = 'Imagen';
$string['processing_file']     = 'Procesando {$a}...';
$string['silence_detected']    = 'Silencio...';
$string['waiting_voice']       = 'Esperando voz...';

// Quick actions
$string['qa_admin_top_student_label'] = 'Mejor estudiante';
$string['qa_admin_top_student_msg']   = '¿Quién es el mejor estudiante en el curso {$a}?';
// ... all 65 keys translated
```

---

## 5. Migration Strategy

**Zero-risk: only ADD, never remove or rename.**

| What | Approach | Risk |
|------|----------|------|
| Existing keys in `lang/en/`, `lang/es/` | Untouched | None |
| Existing keys in `lib.php` `$config['strings']` | Untouched | None |
| Existing `S.key || 'fallback'` in sidebar.js | Untouched | None |
| New keys | Added alongside existing ones | None (no collision because naming is unique) |

The only modification to an existing reference is `S.thinking_label` → `S.thinking` (Phase A, Task A1). This is safe because:
- `thinking` key already exists in both lang files and PHP config
- `thinking_label` was never a lang key — it was a JS-only key name that happened to NOT match the lang key
- The fallback string changes from `'Jedomi is thinking…'` to `'Thinking...'` (aligning with the existing lang value)

---

## 6. Verification Strategy

### Phase A verification

| Check | Method |
|-------|--------|
| `S.thinking_label` removed | `grep -rn 'thinking_label' amd/src/sidebar.js` → 0 results |
| Each new PHP config entry exists | For each of 8 new entries, regex `'key' => get_string\('key'` in `lib.php` |
| `ctx_profile` works on `/user/` page | Open sidebar on user profile page → badge shows "Profile" |
| `attach`/`image`/`file` in attach menu | Click ⊕ → menu shows "Image" and "File" |
| `ai_disclaimer` in footer | Footer shows "Jedomi is an AI and can make mistakes." |
| `drop_files_here` on drag | Drag file over sidebar → overlay shows "Drop files here" |

### Phase B verification

| Check | Method |
|-------|--------|
| No `Imagen adjunta` hardcoded | `grep -rn 'Imagen adjunta' amd/src/sidebar.js` → 0 results |
| No `Descargar CSV` hardcoded | `grep -rn 'Descargar CSV' amd/src/sidebar.js` → 0 results |
| No `Esperando voz` hardcoded | `grep -rn 'Esperando voz' amd/src/sidebar.js` → 0 results |
| No `Procesando` hardcoded | `grep -rn 'Procesando' amd/src/sidebar.js` → 0 results |
| No `Archivo adjunto` hardcoded | `grep -rn 'Archivo adjunto' amd/src/sidebar.js` → 0 results |
| No `cargado` hardcoded (file context) | `grep -rn '\bcargado\b' amd/src/sidebar.js` → 0 results |
| No `No se pudo procesar` hardcoded | `grep -rn 'No se pudo procesar' amd/src/sidebar.js` → 0 results |
| Each Spanish string's key exists in PHP | For each of 9 keys, regex in `lib.php` |
| Each key exists in both lang files | For each key, check `lang/en/` and `lang/es/` |

### Phase C verification

| Check | Method |
|-------|--------|
| Only label/msg values changed in QA | `git diff` of sidebar.js lines 696–802: confirm only `label:` and `msg:` lines differ |
| All ~65 keys in PHP config | Count `'qa_'` matches in `lib.php strings` array |
| All ~65 keys in both lang files | Count `$string['qa_'` matches in both lang files |
| No QA key hardcoded in JS | `grep -rn "'label':" amd/src/sidebar.js` — verify no plain string literals in QA section |

### Full integration test

1. Set Moodle language to English → open sidebar → every UI element shows English
2. Set Moodle language to Spanish (`/admin/settings.php?section=langsettings`) → open sidebar → every UI element shows Spanish
3. Test all 4 roles: admin, manager, teacher, student → each role's QA buttons show translated labels/messages
4. Click each QA → message text is translated and course name resolves correctly
5. Attach image → preview shows "Attached image" (en) / "Imagen adjunta" (es)
6. Attach file → status messages are translated
7. Click CSV download → button shows translated text
8. Voice input → "Listening...", "Silence...", "Waiting for voice..." are translated
9. Navigate to profile/calendar/files → context badges show translated labels
10. Run `npx grunt build` → AMD module compiles without errors

---

## Summary of file changes

| File | Type of Change | Count |
|------|---------------|-------|
| `lib.php` | New `get_string()` entries in `$config['strings']` | ~76 entries |
| `amd/src/sidebar.js` | String literal → `S.key || 'fallback'` replacements | ~48 locations |
| `lang/en/local_jedomi.php` | New string definitions | ~76 keys |
| `lang/es/local_jedomi.php` | New string definitions (Spanish) | ~76 keys |
