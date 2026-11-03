/* eslint-disable */
/**
 * Jedomi AI Sidebar — Theme-proof JavaScript
 *
 * ROBUST INJECTION STRATEGY:
 *  1. Renders the sidebar template straight into document.body (no theme dependency)
 *  2. Fixes overflow:hidden on html/body, which breaks position:fixed
 *  3. Listens to a MutationObserver for themes with AJAX navigation
 *  4. Works even when the theme skips the before_footer hook
 *  5. Prevents a double injection
 *
 * @module     local_jedomi/sidebar
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/log', 'core/ajax', 'core/templates'], function(Log, Ajax, Templates) {

    // ── State ─────────────────────────────────────────────────────────────────
    let cfg          = {};
    let conversation = [];
    let pendingImage = null;
    // Text of an attached file, sent with the next message: {name, info, text}.
    let pendingFile = null;
    let injected     = false;
    // True while the sidebar template is being rendered, so a second bootstrap does not inject it twice.
    let injecting    = false;
    let sessionId    = null;

    // ── Init — entry point ────────────────────────────────────────────────────
    const init = (config) => {
        const doBootstrap = () => {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => bootstrap());
            } else {
                bootstrap();
            }
        };

        // If we received a configUrl (lightweight init), fetch the full config.
        if (config && config.configUrl) {
            fetch(config.configUrl)
                .then(r => r.json())
                .then(fullConfig => {
                    cfg = fullConfig || {};
                    doBootstrap();
                })
                .catch(() => {
                    // Fallback: bootstrap with empty config.
                    cfg = {};
                    doBootstrap();
                });
        } else {
            // Backward compat: config passed inline.
            cfg = config || {};
            doBootstrap();
        }
    };

    const bootstrap = async () => {
        // Prevent a double injection.
        if (injected || injecting || document.getElementById('jedomi-trigger')) {
            if (!injected && !injecting) wireUp();
            return;
        }
        injecting = true;

        // Initialise the persistent session ID.
        sessionId = localStorage.getItem('jedomi_session_id');
        if (!sessionId) {
            sessionId = crypto.randomUUID ? crypto.randomUUID()
                : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                    const r = Math.random() * 16 | 0;
                    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                });
            localStorage.setItem('jedomi_session_id', sessionId);
        }

        // 1. Fix overflow:hidden, the most common problem with themes.
        fixBodyOverflow();

        // 2. Render the sidebar template into body.
        try {
            await injectHTML();
        } catch (error) {
            injecting = false;
            Log.error('Jedomi: the sidebar template could not be rendered', error);
            return;
        }

        // Load the conversation from the Moodle session cache (server).
        // No sessionStorage: the history lives on the server.
        _loadConversationFromServer().then(() => {
            if (conversation.length > 0) {
                setTimeout(() => _restoreConversationUI(), 150);
            }
        });

        // 3. Wire the events.
        wireUp();

        // 4. Listen to AJAX navigation changes (SPA-like themes).
        watchNavigation();

        // sessionStorage needs no save event: it persists automatically.

        injected = true;
        injecting = false;
        Log.debug('Jedomi sidebar injected successfully');



        // Reopen automatically if it was open before navigating.
        if (sessionStorage.getItem('jedomi_open') === '1') {
            setTimeout(() => openSidebar(), 120);
        }
    };

    // ── Fix Body Overflow ─────────────────────────────────────────────────────
    // Some themes set overflow:hidden on html or body, which breaks
    // position:fixed. It is detected here and fixed surgically.
    const fixBodyOverflow = () => {
        const els = [document.documentElement, document.body];
        els.forEach(el => {
            const style    = window.getComputedStyle(el);
            const overflow = style.overflow + style.overflowX + style.overflowY;
            if (overflow.includes('hidden')) {
                el.style.setProperty('overflow', 'visible', 'important');
                Log.debug('Jedomi: fixed overflow:hidden on', el.tagName);
            }
        });
    };

    // ── Inject HTML ───────────────────────────────────────────────────────────
    // The theme is not involved: the local_jedomi/sidebar template is rendered here and appended to body.
    const injectHTML = async () => {
        const S        = cfg.strings || {};
        const greeting = (S.greeting || '').replace('{name}', cfg.user_name || '');
        const {html, js} = await Templates.renderForPromise('local_jedomi/sidebar', {greeting, strings: S});
        Templates.appendNodeContents(document.body, html, js);
        updateContextLabel();
        loadQuickActions();
        startGlowCycle();
    };

    // Render a template into a node, replacing its content or appending to it.
    const renderInto = async (node, template, context, append = false) => {
        try {
            const {html, js} = await Templates.renderForPromise(template, context);
            if (append) {
                Templates.appendNodeContents(node, html, js);
            } else {
                Templates.replaceNodeContents(node, html, js);
            }
        } catch (error) {
            Log.error('Jedomi: template ' + template + ' could not be rendered', error);
        }
    };

    // ── Glow pulse every 15 seconds ───────────────────────────────────────────
    const startGlowCycle = () => {
        const trigger = document.getElementById('jedomi-trigger');
        if (!trigger) return;
        const doGlow = () => {
            trigger.classList.add('jedomi-glowing');
            setTimeout(() => trigger.classList.remove('jedomi-glowing'), 2000);
        };
        setTimeout(doGlow, 3000);          // primer disparo a los 3s de cargar
        setInterval(doGlow, 15000);        // cada 15s después
    };

    // ── Wire Events ───────────────────────────────────────────────────────────
    const wireUp = () => {
        on('jedomi-trigger',      'click',  openSidebar);
        g('jedomi-input')?.closest('[data-action="local_jedomi/sidebar/focus-input"]')
            ?.addEventListener('click', () => g('jedomi-input')?.focus());
        initDropZone();
        on('jedomi-close',        'click',  closeSidebar);
        on('jedomi-clear',        'click',  clearChat);
        on('jedomi-export',       'click',  exportConversation);
        on('jedomi-expand-half',  'click',  () => setExpandMode(expandMode === 'half' ? 'normal' : 'half'));
        on('jedomi-expand-full',  'click',  () => setExpandMode(expandMode === 'full' ? 'normal' : 'full'));
        on('jedomi-thinking-toggle-btn', 'click', () => {
            g('jedomi-thinking-wrapper')?.classList.toggle('jedomi-thinking-expanded');
        });

        // ── CSV download through event delegation (avoids Moodle's CSP) ─────────
        const msgs = g('jedomi-messages');
        if (msgs) {
            msgs.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-csv-download]');
                if (!btn) return;
                const tableWrap = btn.closest('.jedomi-table-wrap');
                const tbl = tableWrap && tableWrap.querySelector('table');
                if (!tbl) return;
                const rows = [...tbl.querySelectorAll('tr')].map(tr =>
                    [...tr.querySelectorAll('th,td')]
                        .map(c => '"' + c.innerText.replace(/"/g, '""') + '"')
                        .join(',')
                );
                const blob = new Blob(['\uFEFF' + rows.join('\n')], {type: 'text/csv;charset=utf-8;'});
                const url  = URL.createObjectURL(blob);
                const a    = document.createElement('a');
                a.href     = url;
                a.download = 'jedomi_datos.csv';
                document.body.appendChild(a);
                a.click();
                setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 500);
            });
        }
        on('jedomi-edit-last',    'click',  editLastMessage);
        on('jedomi-send',         'click',  sendMessage);
        on('jedomi-overlay',      'click',  closeSidebar);
        on('jedomi-remove-image', 'click',  clearImage);
        on('jedomi-remove-file', 'click',   clearFile);
        on('jedomi-edit-last',    'click',  editLastMessage);
        on('jedomi-attach-btn',   'click',  toggleAttachMenu);
        on('jedomi-attach-menu',   'click',  (e) => {
            const btn = e.target.closest('.jedomi-attach-option');
            if (!btn) return;
            const type = btn.getAttribute('data-type');
            if (type === 'image') {
                const inp = g('jedomi-image-input');
                if (inp) inp.click();
            } else {
                const inp = g('jedomi-file-input');
                if (inp) inp.click();
            }
            closeAttachMenu();
        });
        on('jedomi-image-input',  'change', handleImageSelect);
        on('jedomi-file-input',   'change', handleFileSelect);

        const input = g('jedomi-input');
        if (input) {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            input.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Escape → close the sidebar.
            if (e.key === 'Escape' && g('jedomi-sidebar')?.classList.contains('jedomi-open')) {
                closeSidebar();
                return;
            }
            // Ctrl+Shift+J → open/close Jedomi.
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'J') {
                e.preventDefault();
                const isOpen = g('jedomi-sidebar')?.classList.contains('jedomi-open');
                isOpen ? closeSidebar() : openSidebar();
            }
            // Ctrl+Shift+N → new conversation.
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'N') {
                e.preventDefault();
                clearChat();
                openSidebar();
            }
        });
    };

    // ── Watch AJAX Navigation ─────────────────────────────────────────────────
    // Themes that change the URL without reloading (history.pushState) need
    // the context and the quick actions refreshed.
    const watchNavigation = () => {
        const originalPush = history.pushState.bind(history);
        history.pushState = function(...args) {
            originalPush(...args);
            setTimeout(() => {
                updateContextLabel();
                loadQuickActions();
            }, 300);
        };

        window.addEventListener('popstate', () => {
            setTimeout(() => {
                updateContextLabel();
                loadQuickActions();
            }, 300);
        });

        // Also watch DOM changes in case the theme injects
        // content dynamically.
        const observer = new MutationObserver(() => {
            fixBodyOverflow();
        });
        observer.observe(document.body, {
            childList: true,
            subtree:   false,
        });
    };

    // ── Expand modes ─────────────────────────────────────────────────────────
    let expandMode = 'normal'; // 'normal' | 'half' | 'full'

    const setExpandMode = (mode) => {
        const panel = g('jedomi-sidebar');
        if (!panel) return;
        expandMode = mode;
        // Only the panel width changes; Moodle's layout is not touched.
        panel.classList.remove('jedomi-expand-half', 'jedomi-expand-full');
        if (mode === 'half')  panel.classList.add('jedomi-expand-half');
        if (mode === 'full')  panel.classList.add('jedomi-expand-full');
        // Highlight the active button.
        ['half','full'].forEach(m => {
            const btn = g(`jedomi-expand-${m}`);
            if (btn) btn.classList.toggle('jedomi-expand-active', mode === m);
        });
    };

    // ── Open / Close ──────────────────────────────────────────────────────────
    const openSidebar = () => {
        fixBodyOverflow();
        const panel = g('jedomi-sidebar');
        if (!panel) return;
        sessionStorage.setItem('jedomi_open', '1'); // Keep the state.

        // Align with Moodle's fixed-top navbar: position only, the layout is not touched.
        const navbar = document.querySelector('nav.navbar.fixed-top, .navbar.fixed-top');
        const navH   = navbar ? navbar.getBoundingClientRect().height : 0;
        panel.style.top    = navH > 0 ? navH + 'px' : '0';
        panel.style.height = navH > 0 ? `calc(100vh - ${navH}px)` : '100vh';

        // The Jedomi header has the same visual height as the navbar.
        const jedomiHeader = panel.querySelector('.jedomi-header');
        if (jedomiHeader && navH > 0) {
            jedomiHeader.style.height    = navH + 'px';
            jedomiHeader.style.minHeight = navH + 'px';
            jedomiHeader.style.padding   = '0 14px';
        }

        panel.classList.replace('jedomi-closed', 'jedomi-open');
        // Moodle's layout is NOT touched, only the overlay.
        setExpandMode(expandMode);
        g('jedomi-input')?.focus();
    };

    const closeSidebar = () => {
        g('jedomi-sidebar')?.classList.replace('jedomi-open', 'jedomi-closed');
        g('jedomi-overlay')?.classList.add('jedomi-hidden');
        document.body.classList.remove('jedomi-push-half', 'jedomi-push-full');
        sessionStorage.removeItem('jedomi_open'); // limpiar estado
    };

    // Restore the saved messages in the DOM after a page reload.
    const _restoreConversationUI = () => {
        const msgs = g('jedomi-messages');
        if (!msgs || conversation.length === 0) return;
        // Clear the initial greeting.
        msgs.replaceChildren();
        conversation.forEach(msg => {
            if (msg.role === 'user') {
                addMessage('user', typeof msg.content === 'string' ? msg.content : '...');
            } else if (msg.role === 'assistant') {
                addMessage('assistant', typeof msg.content === 'string' ? msg.content : '...');
            }
        });
    };

    // ── Moodle session cache: server, not client ─────────────────────────────
    const _convUrl = () => `${cfg.wwwroot}/local/jedomi/conversation.php`;

    const _loadConversationFromServer = async () => {
        try {
            const r = await fetch(
                `${_convUrl()}?action=get&sesskey=${encodeURIComponent(M?.cfg?.sesskey || '')}`,
                { credentials: 'same-origin' }
            );
            const d = await r.json();
            if (d.ok && Array.isArray(d.messages) && d.messages.length > 0) {
                conversation = d.messages;
            }
        } catch(e) { /* silencioso — primera visita o cache vacío */ }
    };

    const _saveConversation = () => {
        // Only save the LAST message (the one just completed).
        if (conversation.length === 0) return;
        const last = conversation[conversation.length - 1];
        const body = new URLSearchParams({
            action:  'append',
            sesskey: M?.cfg?.sesskey || '',
            role:    last.role,
            content: last.content,
        });
        fetch(_convUrl(), {
            method: 'POST',
            credentials: 'same-origin',
            body,
        }).catch(() => {}); // silencioso
    };

    const clearChat = () => {
        conversation = [];
        // Clear it on the server (Moodle session cache).
        const body = new URLSearchParams({
            action:  'clear',
            sesskey: M?.cfg?.sesskey || '',
        });
        fetch(_convUrl(), { method: 'POST', credentials: 'same-origin', body }).catch(() => {});
        // New session after clearing.
        sessionId = crypto.randomUUID ? crypto.randomUUID()
            : Date.now().toString(36) + Math.random().toString(36).slice(2);
        localStorage.setItem('jedomi_session_id', sessionId);
        const msgs = g('jedomi-messages');
        if (msgs) renderInto(msgs, 'local_jedomi/local/sidebar/notice', {text: (cfg.strings || {}).new_conversation_msg || ''});
    };

    const editLastMessage = () => {
        // Find the last user message and put it in the input to edit it.
        const lastUserMsg = [...conversation].reverse().find(m => m.role === 'user');
        if (!lastUserMsg) return;

        const content = typeof lastUserMsg.content === 'string'
            ? lastUserMsg.content
            : lastUserMsg.content?.find?.(p => p.type === 'text')?.text || '';

        const input = g('jedomi-input');
        if (input) {
            input.value = content;
            input.dispatchEvent(new Event('input'));
            input.focus();
            // Select all the text to make editing easier.
            input.select();
        }

        // Remove the last user/assistant pair from the conversation.
        const lastUserIdx = [...conversation].reverse().findIndex(m => m.role === 'user');
        if (lastUserIdx >= 0) {
            const actualIdx = conversation.length - 1 - lastUserIdx;
            // Remove from the last user message onwards.
            conversation.splice(actualIdx);
        }

        // Remove the last bubbles from the DOM.
        const msgs = g('jedomi-messages');
        if (msgs) {
            const bubbles = msgs.querySelectorAll('.jedomi-message');
            // Remove the last 2 (user + assistant).
            const toRemove = Math.min(2, bubbles.length - 1); // dejar al menos el saludo
            for (let i = 0; i < toRemove; i++) {
                const last = msgs.querySelector('.jedomi-message:last-child');
                if (last) last.remove();
            }
        }
    };

    const exportConversation = () => {
        if (!conversation.length) return;
        const lines = conversation.map(m =>
            `[${m.role.toUpperCase()}]\n${typeof m.content === 'string' ? m.content : JSON.stringify(m.content)}\n`
        ).join('\n---\n\n');
        const blob = new Blob([`JEDOMI — Conversation\n${'='.repeat(40)}\n\n${lines}`], {type: 'text/plain'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `jedomi-conversation-${new Date().toISOString().slice(0,10)}.txt`;
        a.click();
        URL.revokeObjectURL(a.href);
    };

    // ── Context Label & Quick Actions ─────────────────────────────────────────
    const updateContextLabel = () => {
        const ctx  = cfg.context || {};
        const loc  = ctx.location || getCurrentLocation();
        const name = ctx.course_name || ctx.activity_name || '';
        const S    = cfg.strings || {};
        const labels = {
            course:    '\uD83D\uDCDA ' + (name || S.ctx_course    || ''),
            activity:  '\uD83D\uDCDD ' + (name || ctx.activity_type || S.ctx_activity || ''),
            grades:    '\uD83D\uDCCA ' + (S.ctx_grades    || ''),
            admin:     '\u2699\uFE0F '  + (S.ctx_admin    || ''),
            dashboard: '\uD83C\uDFE0 ' + (S.ctx_dashboard || ''),
            messages:  '\uD83D\uDCAC ' + (S.ctx_messages  || ''),
            profile:   '\uD83D\uDC64 ' + (S.ctx_profile   || ''),
            calendar:  '\uD83D\uDCC5 ' + (S.ctx_calendar  || ''),
            files:     '\uD83D\uDCC1 ' + (S.ctx_files     || ''),
        };
        // Fallback: the page title (without the "| Moodle" suffix).
        const rawTitle  = document.title.replace(/\s*[|\-]\s*Moodle.*$/i, '').trim();
        const pageTitle = rawTitle.length > 35 ? rawTitle.substring(0, 33) + '\u2026' : rawTitle;
        const label = labels[loc] || (pageTitle ? '\uD83D\uDCC4 ' + pageTitle : '');
        const el = g('jedomi-context-label');
        if (el) {
            el.textContent     = label;
            el.classList.toggle('jedomi-hidden', !label);
        }
    };

    const getCurrentLocation = () => {
        const p = window.location.pathname;
        const q = window.location.search;
        if (p.includes('/admin/'))                           return 'admin';
        if (p.includes('/grade/') || q.includes('grade'))   return 'grades';
        if (p.includes('/calendar/'))                        return 'calendar';
        if (p.includes('/message/'))                         return 'messages';
        if (p.includes('/user/') || p.includes('/profile/')) return 'profile';
        if (p.includes('/files/'))                           return 'files';
        if (p.includes('/my/') || p === '/')                 return 'dashboard';
        if (p.includes('/mod/'))                             return 'activity';
        if (p.includes('/course/'))                          return 'course';
        return 'unknown';
    };

    const loadQuickActions = async () => {
        const container = g('jedomi-quick-actions');
        if (!container) return;

        const ctx      = cfg.context || {};
        const S        = cfg.strings || {};   // ← FIX: needed by all quick action labels
        const spt      = cfg.spt    || {};
        const role     = cfg.user_role || ctx.user_role || 'student';
        const caps     = spt.capabilities || {};
        const loc      = ctx.location || getCurrentLocation();
        const cid      = ctx.course_id || getCourseidFromUrl();
        const uid      = spt.userid || ctx.user_id || 0;
        // Use the course name when available, not the ID.
        const courseName = ctx.course_name
            ? `"${ctx.course_name}"`
            : (cid ? (S.qa_course_with_id || '').replace('{$a}', cid) : (S.qa_course_current || ''));

        let actions = [];

        // ── Detect whether we are in a course (from the PHP context or the URL) ──
        const inCourse = !!(ctx.course_name || cid) && loc !== 'admin' && loc !== 'dashboard';

        // ── ADMIN ────────────────────────────────────────────────
        if (role === 'admin') {
            if (inCourse) {
                actions = [
                    { icon: '🏆', label: S.qa_admin_top_student_label || '',
                      msg: (S.qa_admin_top_student_msg || '').replace('{$a}', courseName) },
                    { icon: '⚠️', label: S.qa_admin_at_risk_label || '',
                      msg: (S.qa_admin_at_risk_msg || '').replace('{$a}', courseName) },
                    { icon: '📊', label: S.qa_admin_performance_label || '',
                      msg: (S.qa_admin_performance_msg || '').replace('{$a}', courseName) },
                    { icon: '👻', label: S.qa_admin_inactive_label || '',
                      msg: (S.qa_admin_inactive_msg || '').replace('{$a}', courseName) },
                    { icon: '📝', label: S.qa_admin_missing_work_label || '',
                      msg: (S.qa_admin_missing_work_msg || '').replace('{$a}', courseName) },
                ];
            } else if (loc === 'admin') {
                actions = [
                    { icon: '👥', label: S.qa_admin_active_users_label || '',
                      msg: S.qa_admin_active_users_msg || '' },
                    { icon: '🔐', label: S.qa_admin_external_emails_label || '',
                      msg: S.qa_admin_external_emails_msg || '' },
                    { icon: '📋', label: S.qa_admin_empty_cohorts_label || '',
                      msg: S.qa_admin_empty_cohorts_msg || '' },
                    { icon: '⚠️', label: S.qa_admin_gdpr_pending_label || '',
                      msg: S.qa_admin_gdpr_pending_msg || '' },
                    { icon: '🔒', label: S.qa_admin_security_label || '',
                      msg: S.qa_admin_security_msg || '' },
                ];
            } else {
                actions = [
                    { icon: '📊', label: S.qa_admin_overview_label || '',
                      msg: S.qa_admin_overview_msg || '' },
                    { icon: '⚠️', label: S.qa_admin_alerts_label || '',
                      msg: S.qa_admin_alerts_msg || '' },
                    { icon: '🏆', label: S.qa_admin_top_courses_label || '',
                      msg: S.qa_admin_top_courses_msg || '' },
                    { icon: '💾', label: S.qa_admin_storage_label || '',
                      msg: S.qa_admin_storage_msg || '' },
                ];
            }
        } else if (role === 'manager') {
            if (inCourse) {
                actions = [
                    { icon: 'chart', label: S.qa_manager_overview_label || '',
                      msg: (S.qa_manager_overview_msg || '').replace('{$a}', courseName) },
                    { icon: 'alert', label: S.qa_manager_at_risk_label || '',
                      msg: (S.qa_manager_at_risk_msg || '').replace('{$a}', courseName) },
                    { icon: 'users', label: S.qa_manager_enrolled_label || '',
                      msg: (S.qa_manager_enrolled_msg || '').replace('{$a}', courseName) },
                    { icon: 'ghost', label: S.qa_manager_inactive_label || '',
                      msg: (S.qa_manager_inactive_msg || '').replace('{$a}', courseName) },
                ];
            } else {
                actions = [
                    { icon: 'users', label: S.qa_manager_inactive_users_label || '',
                      msg: S.qa_manager_inactive_users_msg || '' },
                    { icon: 'chart', label: S.qa_manager_top_courses_label || '',
                      msg: S.qa_manager_top_courses_msg || '' },
                    { icon: 'alert', label: S.qa_manager_global_risk_label || '',
                      msg: S.qa_manager_global_risk_msg || '' },
                    { icon: 'task',  label: S.qa_manager_no_teacher_label || '',
                      msg: S.qa_manager_no_teacher_msg || '' },
                ];
            }
        } else if (role === 'teacher') {
            if ((loc === 'course' || loc === 'activity' || loc === 'grades') && cid) {
                actions = [
                    { icon: 'chart', label: S.qa_teacher_performance_label || '',
                      msg: (S.qa_teacher_performance_msg || '').replace('{$a}', courseName) },
                    { icon: 'alert', label: S.qa_teacher_at_risk_label || '',
                      msg: (S.qa_teacher_at_risk_msg || '').replace('{$a}', courseName) },
                    { icon: 'ghost', label: S.qa_teacher_never_logged_label || '',
                      msg: (S.qa_teacher_never_logged_msg || '').replace('{$a}', courseName) },
                    { icon: 'task',  label: S.qa_teacher_missing_work_label || '',
                      msg: (S.qa_teacher_missing_work_msg || '').replace('{$a}', courseName) },
                ];
                if (caps.can_send_messages) {
                    actions.push({ icon: 'mic', label: S.qa_teacher_notify_group_label || '',
                      msg: (S.qa_teacher_notify_group_msg || '').replace('{$a}', courseName) });
                }
                if (caps.can_view_all_grades) {
                    actions.push({ icon: 'star', label: S.qa_teacher_top_students_label || '',
                      msg: (S.qa_teacher_top_students_msg || '').replace('{$a}', courseName) });
                }
            } else {
                actions = [
                    { icon: 'chart', label: S.qa_teacher_my_courses_label || '',
                      msg: S.qa_teacher_my_courses_msg || '' },
                    { icon: 'alert', label: S.qa_teacher_my_alerts_label || '',
                      msg: S.qa_teacher_my_alerts_msg || '' },
                ];
            }
        } else { // STUDENT
            if (cid) {
                actions = [
                    { icon: 'task',  label: S.qa_student_my_tasks_label || '',
                      msg: (S.qa_student_my_tasks_msg || '').replace('{$a}', courseName) },
                    { icon: 'chart', label: S.qa_student_my_grade_label || '',
                      msg: (S.qa_student_my_grade_msg || '').replace('{$a}', courseName) },
                    { icon: 'task',  label: S.qa_student_my_progress_label || '',
                      msg: (S.qa_student_my_progress_msg || '').replace('{$a}', courseName) },
                    { icon: 'help',  label: S.qa_student_help_label || '',
                      msg: (S.qa_student_help_msg || '').replace('{$a}', courseName) },
                ];
            } else {
                actions = [
                    { icon: 'chart', label: S.qa_student_my_courses_label || '',
                      msg: S.qa_student_my_courses_msg || '' },
                    { icon: 'task',  label: S.qa_student_my_grades_label || '',
                      msg: S.qa_student_my_grades_msg || '' },
                    { icon: 'task',  label: S.qa_student_this_week_label || '',
                      msg: S.qa_student_this_week_msg || '' },
                ];
            }
        }

        // Emoji icons of the actions → names of the icons in local_jedomi/local/sidebar/icon.
        const iconMap = {
            '📊': 'chart', '⚠️': 'alert', '👻': 'ghost', '📝': 'task',
            '🌙': 'moon',  '👥': 'users', '🔐': 'shield', '📋': 'lock',
            '🏆': 'star',  '❓': 'help',  '💰': 'cpu',   '✅': 'task',
            '📚': 'chart', '💬': 'mic',   '🔒': 'shield', '📅': 'task',
        };
        const iconName = (icon) => iconMap[icon] || (/^[a-z]+$/.test(icon) ? icon : 'chart');
        await renderInto(container, 'local_jedomi/local/sidebar/quick_actions', {
            actions: actions.map(a => ({label: a.label, msg: a.msg, icon: {[iconName(a.icon)]: true}})),
        });

        container.querySelectorAll('.jedomi-quick-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = g('jedomi-input');
                if (input) {
                    input.value = btn.dataset.msg;
                    input.dispatchEvent(new Event('input'));
                }
                sendMessage();
            });
        });
    };

    const getCourseidFromUrl = () => {
        const m = window.location.href.match(/[?&]id=(\d+)/);
        return m ? parseInt(m[1]) : (cfg.context?.course_id || 0);
    };

    // ── Send Message ──────────────────────────────────────────────────────────
    const sendMessage = async () => {
        const input   = g('jedomi-input');
        const sendBtn = g('jedomi-send');
        const text    = input?.value.trim() || '';

        if (!text && !pendingImage && !pendingFile) return;

        let userContent;
        let displayText = text;
        let modelText = text;

        // The file text goes with the question in the same message; the chat shows only its name.
        if (pendingFile) {
            const S = cfg.strings || {};
            const question = text || S.analyze_file || '';
            const header = (S.attached_file_info || '').replace('{$a}', pendingFile.name);
            modelText = `${question}\n\n${header} — ${pendingFile.info}\n\n${pendingFile.text}`;
            displayText = `${text ? text + '\n\n' : ''}📎 ${pendingFile.name} (${pendingFile.info})`;
        }

        if (pendingImage) {
            userContent = [
                { type: 'text', text: modelText || (cfg.strings?.analyze_image || '') },
                { type: 'image_url', image_url: { url: `data:${pendingImage.mimeType};base64,${pendingImage.base64}` } }
            ];
        } else {
            userContent = modelText;
        }

        addMessage('user', displayText, pendingImage);
        if (input) { input.value = ''; input.style.height = ''; }
        clearImage();
        clearFile();
        conversation.push({ role: 'user', content: userContent });
        _saveConversation(); // Save at once.

        setThinking(true);
        if (sendBtn) sendBtn.disabled = true;

        // Create an empty bubble to fill while streaming.
        const streamBubble = createStreamBubble();

        try {
            let fullText = '';
            let pendingAction = null;

            await callGatewayStream(
                conversation,
                // onToken: add each token to the bubble.
                (token) => {
                    fullText += token;
                    if (streamBubble.thinking) {
                        // First answer: hide thinking and show the bubble.
                        setThinking(false);
                        streamBubble.thinking = false;
                        streamBubble.el.classList.remove('jedomi-hidden');
                    }
                    streamBubble.rawText += token;
                    streamBubble.version++;
                    streamBubble.inner.innerHTML = renderMarkdown(streamBubble.rawText);
                    // Smooth scroll to the bottom.
                    const msgs = g('jedomi-messages');
                    if (msgs) msgs.scrollTop = msgs.scrollHeight;
                },
                // onToolEvent: show which tool is running.
                (toolEvent) => {
                    // toolEvent is {tool: "search_tables", args: {...}}
                    const toolName = typeof toolEvent === 'string' ? toolEvent : (toolEvent?.tool || 'unknown');
                    const S2 = cfg.strings || {};
                    const toolIcons = {
                        search_tables:  'search',
                        execute_sql:    'db',
                        describe_table: 'clipboard',
                        get_site_config:'gear',
                        ws_search:      'search',
                        ws_describe:    'book',
                        ws_execute:     'zap',
                    };
                    const toolLabels = {
                        search_tables:  S2.tool_search_tables || '',
                        execute_sql:    S2.tool_execute_sql || '',
                        describe_table: S2.tool_describe_table || '',
                        get_site_config:S2.tool_get_site_config || '',
                        ws_search:      S2.tool_ws_search || '',
                        ws_describe:    S2.tool_ws_describe || '',
                        ws_execute:     S2.tool_ws_execute || '',
                    };

                    const label = toolLabels[toolName] || (S2.tool_unknown || '');
                    const icon  = toolIcons[toolName] || 'zap';

                    // A token that arrives while the status renders wins: the status only replaces older content.
                    const version = ++streamBubble.version;
                    Templates.renderForPromise('local_jedomi/local/sidebar/tool_status', {label, icon: {[icon]: true}})
                        .then(({html, js}) => {
                            if (streamBubble.version === version) {
                                Templates.replaceNodeContents(streamBubble.inner, html, js);
                            }
                            return null;
                        })
                        .catch(error => Log.error('Jedomi: tool status could not be rendered', error));
                    streamBubble.el.classList.remove('jedomi-hidden');
                    setThinking(false);
                    streamBubble.thinking = false;
                },
                // onPendingAction: a tool that changes data waits for the user's confirmation.
                (pending) => {
                    pendingAction = pending;
                }
            );

            // Technical action blocks (JEDOMI_ACTION / JEDOMI_PENDING_ACTION) came from the legacy
            // gateway; confirmations now arrive as the _jedomi_pending_action event, so any such
            // block the model still writes is removed from the answer.
            fullText = fullText
                .replace(/JEDOMI_ACTION[\s\S]*?END_ACTION/g, '')
                .replace(/JEDOMI_PENDING_ACTION:[\s\S]*?:END_PENDING/g, '')
                .trim();
            streamBubble.version++;
            streamBubble.inner.innerHTML = renderMarkdown(fullText);
            if (pendingAction) {
                streamBubble.el.classList.remove('jedomi-hidden');
                streamBubble.inner.appendChild(buildActionConfirmUI(pendingAction));
            }
            addMessageActions(streamBubble.wrap, fullText);

            // Links: open in the same tab directly (no preventDefault).
            streamBubble.inner.querySelectorAll('a[href]').forEach(a => {
                if (!a.getAttribute('target')) {
                    a.setAttribute('target', '_self');
                }
            });

            conversation.push({ role: 'assistant', content: fullText });
            _saveConversation(); // Save it on the server.

        } catch (err) {
            Log.error('Jedomi error', err);
            setThinking(false);
            streamBubble.version++;
            if (err.message === '__SESSION_EXPIRED__') {
                streamBubble.inner.innerHTML = renderMarkdown(
                    `⚠️ **${cfg.strings?.session_expired_title || ''}**\n\n` +
                    `${cfg.strings?.session_expired_hint || ''}`
                );
            } else {
                const message = err.jedomiServerError
                    ? err.message
                    : (cfg.strings?.ai_error_connection || '');
                streamBubble.inner.innerHTML = renderMarkdown(`⚠️ ${message}`);
            }
            streamBubble.el.classList.remove('jedomi-hidden');
        } finally {
            setThinking(false);
            if (sendBtn) sendBtn.disabled = false;
            input?.focus();
        }
    };

    // ── Call Chat Proxy (streaming) ─────────────────────────────────────────
    // The proxy receives the messages, adds the tools and the anti-hallucination system prompt,
    // runs the tool loop against the real Moodle DB and returns SSE.
    const callGatewayStream = async (messages, onToken, onToolEvent, onPendingAction) => {
        // Context truncation: at most 30 messages to avoid a context overflow.
        const truncated = messages.slice(-30);

        const payload = {
            messages: truncated,
            spt:      cfg.spt,
            sesskey:  cfg.sesskey,
            context:  {
                ...cfg.context,
                page_url:   window.location.href,
                page_title: document.title.replace(/\s*[|\-]\s*Moodle.*$/i, '').trim(),
                location:   getCurrentLocation(),
                course_id:  getCourseidFromUrl(),
            },
        };

        const res = await fetch(cfg.chatproxy_url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        if (!res.ok) {
            // Detect an expired session (403 from chatproxy.php → invalid sesskey/SPT).
            if (res.status === 403) {
                throw new Error('__SESSION_EXPIRED__');
            }
            const err = await res.json().catch(() => ({}));
            throw new Error(err.detail || err.error?.message || `HTTP ${res.status}`);
        }

        // Security: detect when require_login() redirected to the HTML login page.
        const contentType = res.headers.get('content-type') || '';
        if (contentType.includes('text/html')) {
            throw new Error('__SESSION_EXPIRED__');
        }

        const reader  = res.body.getReader();
        const decoder = new TextDecoder();
        let   buffer  = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop(); // último fragmento incompleto

            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                const raw = line.slice(6).trim();
                if (raw === '[DONE]') return;

                try {
                    const chunk = JSON.parse(raw);

                    // A tool is running.
                    if (chunk._jedomi_tool_executing && onToolEvent) {
                        onToolEvent(chunk._jedomi_tool_executing);
                        continue;
                    }
                    // An action waiting for the user's confirmation (the server holds it).
                    if (chunk._jedomi_pending_action) {
                        if (onPendingAction) onPendingAction(chunk._jedomi_pending_action);
                        continue;
                    }
                    // Metadata at the end.
                    if (chunk._meta) continue;
                    // Server error: {"error": {"code", "message"}} with a message ready to show.
                    if (chunk.error) {
                        const serverError = new Error(chunk.error.message
                            || cfg.strings?.ai_error_connection || '');
                        serverError.jedomiServerError = true;
                        throw serverError;
                    }

                    // Content token.
                    const token = chunk.choices?.[0]?.delta?.content;
                    if (token && onToken) onToken(token);

                } catch (e) {
                    if (e.message !== 'Unexpected end of JSON input') throw e;
                }
            }
        }
    };

    // Fallback without streaming (for clients without SSE support).
    const callGateway = async (messages) => {
        const payload = {
            messages: messages,
            spt:      cfg.spt,
            sesskey:  cfg.sesskey,
            context:  {
                ...cfg.context,
                page_url:   window.location.href,
                page_title: document.title.replace(/\s*[|\-]\s*Moodle.*$/i, '').trim(),
                location:   getCurrentLocation(),
                course_id:  getCourseidFromUrl(),
            },
        };
        const res = await fetch(cfg.chatproxy_url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload),
        });
        if (!res.ok) {
            if (res.status === 403) {
                throw new Error('__SESSION_EXPIRED__');
            }
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || `HTTP ${res.status}`);
        }
        const ct = res.headers.get('content-type') || '';
        if (ct.includes('text/html')) {
            throw new Error('__SESSION_EXPIRED__');
        }
        return res.json();
    };

    // ── System Prompt ─────────────────────────────────────────────────────────
    const buildSystemPrompt = () => {
        const ctx   = cfg.context || {};
        const role  = cfg.user_role || 'user';
        const loc   = ctx.location || getCurrentLocation();
        const url   = window.location.href;
        const cid   = ctx.course_id || getCourseidFromUrl();
        const cname = ctx.course_name || '';
        const wwwroot = cfg.wwwroot || (window.M?.cfg?.wwwroot) || '';

        const roleDescs = {
            admin:   'You are the Moodle site administrator.',
            manager: 'You are a manager with access to user and course management.',
            teacher: `You are a teacher.${cname ? ` You are in course "${cname}" (ID: ${cid}).` : ''}`,
            student: `You are a student.${cname ? ` You are in course "${cname}" (ID: ${cid}).` : ''}`,
        };

        const locDescs = {
            course:   `Currently viewing course${cname ? ` "${cname}"` : ''} (ID: ${cid}).`,
            activity: `Currently in an activity of type "${ctx.activity_type || 'unknown'}".`,
            grades:   'Currently viewing the gradebook.',
            admin:    'Currently in the Moodle administration panel.',
            dashboard:'Currently on the Moodle dashboard.',
            messages: 'Currently in the messages section.',
        };

        return `You are Jedomi, the native AI assistant for Moodle LMS 5.2.
${roleDescs[role] || ''}
${locDescs[loc] || ''}
Current URL: ${url}
Page title: ${document.title}
wwwroot: ${wwwroot}

AVAILABLE TOOLS (you MUST use these for any data question):
- execute_sql: Run SELECT queries on the Moodle database. Parameters: query (SQL with ? placeholders), params (array of values).
- describe_table: Describe a table's columns. Parameter: table (table name without prefix).
- search_tables: Find tables by keyword. Parameter: keyword.
- get_site_config: Get Moodle configuration values. Parameter: key (config name).
- ws_search: Search web service functions by intent in ENGLISH. Parameters: query (e.g. 'create user'). Always call BEFORE ws_execute.
- ws_describe: Get full docs for a specific WS function by exact name. Call AFTER ws_search and BEFORE ws_execute to confirm required params.
- ws_execute: Execute a web service function. Parameters: wsname, params (JSON with ALL * fields present).

ANTI-HALLUCINATION RULES (CRITICAL — NEVER violate these):
1. CRITICAL: You MUST call a tool BEFORE answering ANY question about site data (user counts, grades, courses, activity, config, etc.). NEVER guess numbers.
2. If a tool returns 0 rows or an error, say "I couldn't find that data" — do NOT fabricate results.
3. If you don't know the answer, say "I don't know" — do NOT make up information.
4. Only use execute_sql for SELECT queries. Never modify data.
5. Use bound parameters (?) instead of concatenating values in SQL.
6. WS STRATEGY (MANDATORY): 1) ws_search(query in ENGLISH) → 2) ws_describe(exact name) → 3) ws_execute with ALL * (required) fields present. If user didn't provide a value, generate a sensible default. NEVER guess function names.
7. WS ERROR HANDLING: If ws_execute returns an error, read the full message. Common causes: missing required field, invalid type, duplicate unique value. Fix and retry once. Do NOT retry with same parameters.
8. Respect privacy: do not reveal personal data of other users unless authorized.
9. Do NOT fabricate tool calls. Only call tools that actually exist.

GENERAL INSTRUCTIONS:
- Always respond in the language the user is writing in.
- When mentioning Moodle pages, include navigable links: [Text](FULL_URL).
- For courses: ${wwwroot}/course/view.php?id=COURSEID
- For grades: ${wwwroot}/grade/report/user/index.php?id=COURSEID
- For users: ${wwwroot}/user/view.php?id=USERID&course=COURSEID
- For assignments: ${wwwroot}/mod/assign/view.php?id=CMID
- Show data (grades, students, etc.) in markdown tables.
- Explain WHAT data you queried and WHY it is relevant.
- For actions (enrol, messages, etc.) always use the Moodle API.`;
    };

    // ── Stream Bubble ─────────────────────────────────────────────────────────
    // Create an empty bubble in the DOM and return references to fill it.
    const createStreamBubble = () => {
        const msgs = g('jedomi-messages');
        const wrap = document.createElement('div');
        wrap.className = 'jedomi-message jedomi-message-assistant';

        const bubble = document.createElement('div');
        bubble.className = 'jedomi-bubble jedomi-hidden';

        const inner = document.createElement('div');
        bubble.appendChild(inner);
        wrap.appendChild(bubble);
        msgs?.appendChild(wrap);
        msgs && (msgs.scrollTop = msgs.scrollHeight);

        return { wrap, el: bubble, inner, rawText: '', thinking: true, version: 0 };
    };

    // Action buttons under each assistant answer.
    const addMessageActions = async (wrap, text) => {
        const actions = document.createElement('div');
        actions.className = 'jedomi-msg-actions';
        // Inside the bubble (inner), not outside (wrap).
        const bubbleInner = wrap.querySelector('.jedomi-bubble-inner, .jedomi-bubble');
        (bubbleInner || wrap).appendChild(actions);
        await renderInto(actions, 'local_jedomi/local/sidebar/message_actions', {strings: cfg.strings || {}});

        const copy = actions.querySelector('[data-action="local_jedomi/sidebar/copy"]');
        copy?.addEventListener('click', () => {
            navigator.clipboard.writeText(text).then(() => {
                copy.classList.add('jedomi-action-done');
                setTimeout(() => copy.classList.remove('jedomi-action-done'), 1500);
                return null;
            }).catch(() => null);
        });
        actions.querySelector('[data-action="local_jedomi/sidebar/good"]')?.addEventListener('click', (e) => {
            e.target.closest('button').classList.add('jedomi-feedback-good');
            saveFeedback(text, 'good');
        });
        actions.querySelector('[data-action="local_jedomi/sidebar/bad"]')?.addEventListener('click', (e) => {
            e.target.closest('button').classList.add('jedomi-feedback-bad');
            saveFeedback(text, 'bad');
        });
    };

    const saveFeedback = async (text, rating) => {
        try {
            await Ajax.call([{
                methodname: 'local_jedomi_save_feedback',
                args: {rating, sessionid: sessionId || '', messagetext: text.slice(0, 1000)},
            }])[0];
        } catch (e) {
            Log.debug('Jedomi: feedback not saved', e);
        }
    };

    // ── Render Messages ───────────────────────────────────────────────────────
    const addMessage = (role, content, image = null) => {
        const msgs = g('jedomi-messages');
        if (!msgs) return;

        const wrap   = document.createElement('div');
        wrap.className = `jedomi-message jedomi-message-${role}`;

        const bubble = document.createElement('div');
        bubble.className = 'jedomi-bubble';

        if (role === 'assistant') {
            bubble.innerHTML = renderMarkdown(content);
            // Links → navigate the main browser window.
            bubble.querySelectorAll('a[href]').forEach(a => {
                const href = a.getAttribute('href');
                if (href && !href.startsWith('http')) return;
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (href) window.location.href = href;
                });
            });
        } else {
            const p = document.createElement('p');
            p.textContent = content || '';
            bubble.appendChild(p);
            if (image) {
                const img = document.createElement('img');
                img.src = `data:${image.mimeType};base64,${image.base64}`;
                img.className = 'jedomi-msg-image';
                bubble.appendChild(img);
            }
            // Edit button under user messages.
            const editActions = document.createElement('div');
            editActions.className = 'jedomi-msg-actions jedomi-user-actions';
            wrap.appendChild(editActions);
            renderInto(editActions, 'local_jedomi/local/sidebar/user_actions', {strings: cfg.strings || {}}).then(() => {
                editActions.querySelector('[data-action="local_jedomi/sidebar/edit"]')
                    ?.addEventListener('click', () => editLastMessage());
                return null;
            }).catch(() => null);
        }

        wrap.appendChild(bubble);
        msgs.appendChild(wrap);
        msgs.scrollTop = msgs.scrollHeight;
    };

    // Detect the Moodle page type from the URL.
    const getMoodlePageInfo = (url) => {
        const wwwroot = cfg.wwwroot || '';
        if (!url.includes(wwwroot) && !url.startsWith('/')) return null;

        const patterns = [
            { re: /\/course\/view\.php\?id=(\d+)/,      icon: '📚', type: 'Curso' },
            { re: /\/user\/view\.php\?id=(\d+)/,        icon: '👤', type: 'Perfil' },
            { re: /\/mod\/assign\/view\.php\?id=(\d+)/,  icon: '📝', type: 'Tarea' },
            { re: /\/mod\/quiz\/view\.php\?id=(\d+)/,    icon: '❓', type: 'Quiz' },
            { re: /\/grade\/report\/user/,               icon: '📊', type: 'Notas' },
            { re: /\/admin\//,                           icon: '⚙️', type: 'Admin' },
            { re: /\/message\//,                         icon: '💬', type: 'Mensajes' },
        ];

        for (const p of patterns) {
            if (p.re.test(url)) {
                const match = url.match(p.re);
                return { icon: p.icon, type: p.type, id: match?.[1] };
            }
        }
        return { icon: '🔗', type: 'Moodle', id: null };
    };

    const renderMarkdown = (text) => {
        if (!text) return '';
        let html = text
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            // Code blocks
            .replace(/```[\w]*\n([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
            // Inline code
            .replace(/`([^`\n]+)`/g, '<code>$1</code>')
            // Bold
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            // Italic
            .replace(/_(.+?)_/g, '<em>$1</em>')
            // Rich Moodle links [text](url): a preview card for Moodle URLs.
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, linkText, url) => {
                // Only web and site-relative links: model output can carry javascript: or data: URLs.
                if (!/^(https?:\/\/|\/(?!\/))/i.test(url)) {
                    return linkText;
                }
                const pageInfo = getMoodlePageInfo(url);
                if (pageInfo) {
                    return `<a href="${url}" class="jedomi-moodle-link" data-type="${pageInfo.type}">` +
                           `${pageInfo.icon} ${linkText} <span class="jedomi-link-badge">${pageInfo.type} ↗</span></a>`;
                }
                return `<a href="${url}">${linkText} ↗</a>`;
            })
            // ── Markdown tables: ONE table per block, with a working CSV button ──
            // Step 1: mark rows and separators.
            .replace(/^\|(.+)\|$/gm, (row) => {
                if (row.replace(/[\|\s\-:]/g, '') === '') return '§SEP§';
                const cells = row.slice(1,-1).split('|').map(c => c.trim());
                return '§ROW§' + cells.join('§CELL§');
            })
            // Step 2: remove separators.
            .replace(/§SEP§\n?/g, '')
            // Step 3: group ALL consecutive §ROW§ into ONE table.
            .replace(/((?:§ROW§[^\n]*\n?)+)/g, (block) => {
                const rows = block.trim().split('\n')
                    .filter(r => r.startsWith('§ROW§'))
                    .map(r => r.replace('§ROW§', '').split('§CELL§'));

                // At least 2 real rows (header + 1 data row) to build a table.
                if (rows.length < 2) {
                    // A single row: render it as plain text.
                    return '<p>' + rows[0].join(' | ') + '</p>';
                }

                // First row = <th> headers.
                const thead = '<thead><tr>' +
                    rows[0].map(c => `<th>${c}</th>`).join('') +
                    '</tr></thead>';

                // Rest = data <td>.
                const tbody = '<tbody>' + rows.slice(1).map(r =>
                    '<tr>' + r.map(c => `<td>${c}</td>`).join('') + '</tr>'
                ).join('') + '</tbody>';

                // CSV button through a data attribute (event delegation, no inline onclick).
                const csvBtn = `<button class="jedomi-csv-btn" data-csv-download="true">${cfg.strings?.csv_download || ''}</button>`;

                return `<div class="jedomi-table-wrap">${csvBtn}<div class="jedomi-table-scroll"><table class="jedomi-table">${thead}${tbody}</table></div></div>`;
            })
            // Unordered lists
            .replace(/^[-*] (.+)$/gm, '<li>$1</li>')
            .replace(/((?:<li>.*<\/li>\n?)+)/g, '<ul>$1</ul>')
            // Paragraphs
            .replace(/\n\n/g, '</p><p>')
            .replace(/\n/g, '<br>');

        return '<p>' + html + '</p>';
    };

    // ── Image ─────────────────────────────────────────────────────────────────
    const handleImageSelect = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (ev) => {
            const dataUrl = ev.target.result;
            pendingImage = { base64: dataUrl.split(',')[1], mimeType: file.type || 'image/jpeg' };
            const preview = g('jedomi-image-preview');
            const img     = g('jedomi-preview-img');
            if (img) img.src = dataUrl;
            preview?.classList.remove('jedomi-hidden');
        };
        reader.readAsDataURL(file);
    };

    // ── File (PDF/CSV/TXT/MD) ─────────────────────────────────────────────────
    // Moodle sends the file to the AI service through the Datacurso provider and returns its
    // text, which is added to the message with its pages, rows and columns, or characters.
    const handleFileSelect = (e) => {
        const file = e.target.files?.[0];
        if (file) attachFile(file);
    };

    const attachFile = async (file) => {
        const S = cfg.strings || {};
        const input = g('jedomi-input');
        const origPlaceholder = input?.placeholder;
        if (input) input.placeholder = (S.processing_file || '').replace('{$a}', file.name);

        try {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('sesskey', cfg.sesskey || '');

            const res = await fetch(cfg.extract_file_url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (data.error) {
                throw new Error(data.error.message || S.unknown_error || '');
            }
            if (!res.ok) {
                throw new Error(S.ai_error_connection || '');
            }

            const info = data.format === 'csv'
                ? (S.file_csv || '').replace('{rows}', data.rows).replace('{columns}', data.columns)
                : data.format === 'pdf'
                ? (S.file_pdf || '').replace('{pages}', data.pages)
                : (S.file_text || '').replace('{chars}', data.chars);

            // The text is kept as a pending attachment, like an image, so the textarea stays free for the question.
            pendingFile = { name: file.name, info: info, text: data.text || '' };
            const chipname = g('jedomi-file-preview-name');
            if (chipname) chipname.textContent = `📎 ${file.name} — ${info}`;
            g('jedomi-file-preview')?.classList.remove('jedomi-hidden');
            input?.focus();

            addMessage('assistant', `📎 **${file.name}** ${S.file_loaded || ''} (${info}). ${S.file_loaded_hint || ''}`);
        } catch (err) {
            addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${err.message}`);
        } finally {
            if (input && origPlaceholder) input.placeholder = origPlaceholder;
            const fi = g('jedomi-file-input');
            if (fi) fi.value = '';
        }
    };

    // ── Confirmation of actions that change data ──────────────────────────────
    // The server holds the action and sends its id; it runs only through
    // local_jedomi_resolve_action, which checks the owner, the expiry and the role again.
    // severity: 'critical' (red, the keyword must be typed) | 'high' (orange) | 'medium' (yellow).
    const resolveAction = async (id, confirm) => {
        return Ajax.call([{
            methodname: 'local_jedomi_resolve_action',
            args: {id, confirm},
        }])[0];
    };

    // A short note keeps the outcome in the conversation, so the next answer knows it.
    const addActionNote = (pending, message) => {
        conversation.push({ role: 'assistant', content: `${pending.description} — ${message}` });
        _saveConversation();
    };

    // Returns the card element at once; its content is rendered from local_jedomi/local/sidebar/action_confirm.
    const buildActionConfirmUI = (pending) => {
        const CS = cfg.strings || {};
        const isSend = pending.tool === 'send_messages';
        const severity = ['critical', 'high', 'medium'].includes(pending.severity) ? pending.severity : 'medium';
        const isCritical = severity === 'critical';
        const confirmKeyword = (CS.confirm_btn_yes || 'Confirm').toUpperCase();
        const preview = String(pending.preview || '');
        const [countbefore, countafter] = String(CS.confirm_send_bulk || '').split('{count}');

        const div = document.createElement('div');
        div.className = isSend ? 'jedomi-send-confirm' : `jedomi-action-pending jedomi-action-pending--${severity}`;

        const showDone = (text, note = '') => {
            renderInto(div, 'local_jedomi/local/sidebar/action_done', {text, note});
        };

        const resolve = async (confirm) => {
            const yesBtn    = div.querySelector('[data-action="local_jedomi/sidebar/confirm-yes"]');
            const noBtn     = div.querySelector('[data-action="local_jedomi/sidebar/confirm-no"]');
            const resultDiv = div.querySelector('[data-region="local_jedomi/sidebar/confirm-result"]');
            if (confirm && isCritical) {
                const inp = div.querySelector('[data-region="local_jedomi/sidebar/confirm-keyword"]');
                if (!inp || inp.value.trim().toUpperCase() !== confirmKeyword) {
                    inp?.classList.add('is-invalid');
                    inp?.focus();
                    resultDiv.textContent = '⛔ ' + (CS.confirm_type_to_proceed || '');
                    return;
                }
            }
            yesBtn.disabled = noBtn.disabled = true;
            resultDiv.textContent = confirm
                ? '⏳ ' + (isSend ? (CS.sending || '') : (CS.confirm_executing || ''))
                : '';
            try {
                const result = await resolveAction(Number(pending.id), confirm);
                const icon = result.status === 'executed' ? '✅ ' : (result.status === 'cancelled' ? '' : '❌ ');
                const note = isSend && result.status === 'executed' ? (CS.messages_recipients_note || '') : '';
                showDone(icon + result.message, note);
                addActionNote(pending, result.message);
            } catch (e) {
                const message = e?.message || CS.unknown_error || '';
                showDone('❌ ' + message);
                addActionNote(pending, message);
            }
        };

        renderInto(div, 'local_jedomi/local/sidebar/action_confirm', {
            issend: isSend,
            countbefore: countbefore || '',
            count: Number(pending.count || 0),
            countafter: countafter || '',
            preview: preview.substring(0, 120) + (preview.length > 120 ? '...' : ''),
            description: pending.description || pending.tool || '',
            hasdetails: !!pending.details,
            details: pending.details || '',
            critical: isCritical,
            keyword: confirmKeyword,
            strings: CS,
        }).then(() => {
            div.querySelector('[data-action="local_jedomi/sidebar/confirm-yes"]')?.addEventListener('click', () => resolve(true));
            div.querySelector('[data-action="local_jedomi/sidebar/confirm-no"]')?.addEventListener('click', () => resolve(false));
            return null;
        }).catch(() => null);
        return div;
    };

    const clearFile = () => {
        pendingFile = null;
        g('jedomi-file-preview')?.classList.add('jedomi-hidden');
        const chipname = g('jedomi-file-preview-name');
        if (chipname) chipname.textContent = '';
    };

    const clearImage = () => {
        pendingImage = null;
        g('jedomi-image-preview')?.classList.add('jedomi-hidden');
        const img = g('jedomi-preview-img');
        if (img) img.src = '';
        const input = g('jedomi-image-input');
        if (input) input.value = '';
    };

    // ── Helpers ───────────────────────────────────────────────────────────────
    const g   = (id) => document.getElementById(id);
    const on  = (id, ev, fn) => g(id)?.addEventListener(ev, fn);
    // ── Thinking colapsable ───────────────────────────────────────────────────
    const setThinking = (show) => {
        const wrapper = g('jedomi-thinking-wrapper');
        if (!wrapper) return;
        wrapper.classList.toggle('jedomi-hidden', !show);
        if (show) {
            // Starts collapsed (only the bar, without the dots).
            wrapper.classList.remove('jedomi-thinking-expanded');
            const msgs = g('jedomi-messages');
            if (msgs) msgs.scrollTop = msgs.scrollHeight;
        }
    };
    // ── Drop zone ──────────────────────────────────────────────────────────
    let dropCounter = 0;
    const initDropZone = () => {
        const sidebar = g('jedomi-sidebar');
        const zone    = g('jedomi-dropzone');
        if (!sidebar || !zone) return;

        sidebar.addEventListener('dragenter', (e) => {
            e.preventDefault(); e.stopPropagation();
            dropCounter++;
            zone.classList.remove('jedomi-hidden');
        });
        sidebar.addEventListener('dragover', (e) => {
            e.preventDefault(); e.stopPropagation();
        });
        sidebar.addEventListener('dragleave', (e) => {
            e.preventDefault(); e.stopPropagation();
            dropCounter--;
            if (dropCounter <= 0) { dropCounter = 0; zone.classList.add('jedomi-hidden'); }
        });
        sidebar.addEventListener('drop', (e) => {
            e.preventDefault(); e.stopPropagation();
            dropCounter = 0;
            zone.classList.add('jedomi-hidden');
            const files = e.dataTransfer?.files;
            if (!files || files.length === 0) return;
            handleDroppedFiles(files);
        });
    };
    const handleDroppedFiles = (files) => {
        for (const file of files) {
            if (file.type.startsWith('image/')) {
                const dt = new DataTransfer();
                dt.items.add(file);
                const input = g('jedomi-image-input');
                if (input) { input.files = dt.files; input.dispatchEvent(new Event('change')); }
            } else {
                attachFile(file);
            }
        }
    };

    // ── Attach menu ─────────────────────────────────────────────────────────
    const toggleAttachMenu = () => {
        const menu = g('jedomi-attach-menu');
        if (!menu) return;
        menu.classList.toggle('jedomi-hidden');
        if (!menu.classList.contains('jedomi-hidden')) {
            setTimeout(() => document.addEventListener('click', closeAttachOutside), 10);
        }
    };
    const closeAttachMenu = () => {
        g('jedomi-attach-menu')?.classList.add('jedomi-hidden');
        document.removeEventListener('click', closeAttachOutside);
    };
    const closeAttachOutside = (e) => {
        if (!e.target.closest('.jedomi-attach-wrap')) closeAttachMenu();
    };

    return { init };
});
