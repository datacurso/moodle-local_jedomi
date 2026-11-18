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

define(['core/log', 'core/ajax', 'core/templates', 'core/str', 'core/notification', 'local_jedomi/api'],
    function(Log, Ajax, Templates, Str, Notification, Api) {

    // ── State ─────────────────────────────────────────────────────────────────
    let cfg          = {};
    let conversation = [];
    // Image attached for the next message: {base64, mimeType, file}. It is read by the vision model when sent.
    let pendingImage = null;
    // Files attached to the next message, in order: [{file, name, extracted, controller}]. Each one is read (and
    // charged) only when the message is sent; extracted keeps its text, so a retry reads nothing again.
    let pendingFiles = [];
    // Address of the panel configuration, kept to renew the signed permissions (SPT) before they expire.
    let configUrl    = null;
    // Time, on the clock of the browser, when the signed permissions expire (0 when unknown).
    let sptExpiresAt = 0;
    // Renewal of the signed permissions in progress, shared by the requests that wait for it.
    let renewing     = null;
    // True after the user asked to load the panel again: it opens when it loads, or the notice opens again.
    let retryOpen    = false;
    let injected     = false;
    // True while the sidebar template is being rendered, so a second bootstrap does not inject it twice.
    let injecting    = false;
    let sessionId    = null;
    // Microphone recording in progress, if any, and whether it was cancelled instead of stopped.
    let recorder     = null;
    let recordingCancelled = false;
    // Recorded voice message waiting in the preview bar: {blob, url, audio, mime, seconds}. It is only uploaded when sent.
    let voice        = null;
    // Increases on every voice bar render, so an older render that resolves late is dropped.
    let voiceBarVersion = 0;
    // True while a message is being prepared or answered, so it is not sent twice.
    let sending      = false;
    // Voice conversation in progress, if any (see startVoiceMode).
    let voiceMode    = null;
    // Voice conversation waiting for the microphone permission, if any: {cancelled} (see startVoiceMode).
    let voiceModeStarting = null;
    // Written answer in progress, if any: aborting it stops the answer (stop button and new conversation).
    let chatController = null;
    // Requests to the conversation history, sent one after the other so they reach the server in order.
    let historyQueue = Promise.resolve();
    // Element that had the focus before the panel opened, to give it back when the panel closes.
    let focusBeforeOpen = null;

    // ── Init — entry point ────────────────────────────────────────────────────
    const init = (config) => {
        // Other plugins can now ask, through local_jedomi/api, to open the panel with a suggestion.
        Api.panelStarted();
        const doBootstrap = () => {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => bootstrap());
            } else {
                bootstrap();
            }
        };

        // If we received a configUrl (lightweight init), fetch the full config.
        if (config && config.configUrl) {
            configUrl = config.configUrl;
            fetchConfig()
                .then(fullConfig => {
                    cfg = fullConfig;
                    setPermissionsExpiry(fullConfig);
                    doBootstrap();
                    return null;
                })
                .catch((error) => {
                    // Without its configuration the panel cannot work: it is never started empty. A notice with a
                    // retry button is shown instead.
                    Log.debug('Jedomi: the panel configuration could not be loaded', error);
                    Api.panelFailed();
                    showConfigError(config);
                });
        } else {
            // Backward compat: config passed inline.
            cfg = config || {};
            doBootstrap();
        }
    };

    // ── Panel configuration and signed permissions ──────────────────────────
    // Margin before the expiry of the signed permissions in which they are renewed, in milliseconds.
    const SPT_RENEW_MARGIN_MS = 120000;

    // Fetch the panel configuration. A refusal (an error answer, a login page or a failed request) is an error: a
    // configuration without signed permissions is never used.
    const fetchConfig = async () => {
        const res = await fetch(configUrl, {credentials: 'same-origin'});
        const data = await res.json();
        if (!res.ok || !data || data.error || !data.spt) {
            throw new Error(data?.message || `HTTP ${res.status}`);
        }
        return data;
    };

    // Remember when the signed permissions expire, on the clock of the browser: the server sends its own time with them.
    const setPermissionsExpiry = (config) => {
        const lifetime = Number(config?.spt?.expires) - Number(config?.server_time);
        sptExpiresAt = Number.isFinite(lifetime) && lifetime > 0 ? Date.now() + lifetime * 1000 : 0;
    };

    // Ask the server for new signed permissions while the Moodle session is active. Resolves to whether they were
    // renewed; the requests in progress share one renewal.
    const renewPermissions = () => {
        if (!configUrl) {
            return Promise.resolve(false);
        }
        if (!renewing) {
            renewing = fetchConfig()
                .then((fresh) => {
                    cfg.spt = fresh.spt;
                    cfg.sesskey = fresh.sesskey || cfg.sesskey;
                    setPermissionsExpiry(fresh);
                    return true;
                })
                .catch((error) => {
                    Log.debug('Jedomi: the chat permissions could not be renewed', error);
                    return false;
                })
                .finally(() => {
                    renewing = null;
                });
        }
        return renewing;
    };

    // Renew the signed permissions when they expire in less than SPT_RENEW_MARGIN_MS, before a request uses them.
    const ensureFreshPermissions = async () => {
        if (configUrl && sptExpiresAt && Date.now() >= sptExpiresAt - SPT_RENEW_MARGIN_MS) {
            await renewPermissions();
        }
    };

    // Notice shown instead of the panel when its configuration cannot be loaded, with a button to try again. It is
    // built without templates: the server may be failing.
    const showConfigError = async (config) => {
        let strings = config?.strings || {};
        if (!strings.config_load_error) {
            try {
                const [message, retry] = await Str.get_strings([
                    {key: 'config_load_error', component: 'local_jedomi'},
                    {key: 'config_load_retry', component: 'local_jedomi'},
                ]);
                strings = {config_load_error: message, config_load_retry: retry};
            } catch (error) {
                strings = {config_load_error: '', config_load_retry: ''};
            }
        }
        ['jedomi-trigger', 'jedomi-sidebar'].forEach((id) => document.getElementById(id)?.remove());

        const trigger = document.createElement('button');
        trigger.id = 'jedomi-trigger';
        trigger.className = 'jedomi-trigger';
        trigger.setAttribute('aria-label', 'Jedomi');
        const label = document.createElement('span');
        label.className = 'jedomi-trigger-label';
        label.textContent = 'Jedomi';
        trigger.appendChild(label);

        const panel = document.createElement('div');
        panel.id = 'jedomi-sidebar';
        panel.className = 'jedomi-sidebar jedomi-closed jedomi-config-error';
        panel.setAttribute('role', 'complementary');
        panel.setAttribute('aria-label', 'Jedomi');
        const alert = document.createElement('div');
        alert.className = 'alert alert-warning m-3';
        alert.setAttribute('role', 'alert');
        const text = document.createElement('p');
        text.textContent = strings.config_load_error;
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-secondary';
        retry.textContent = strings.config_load_retry;
        alert.append(text, retry);
        panel.appendChild(alert);

        trigger.addEventListener('click', () => {
            const open = panel.classList.toggle('jedomi-open');
            panel.classList.toggle('jedomi-closed', !open);
            if (open) retry.focus();
        });
        retry.addEventListener('click', () => {
            retryOpen = true;
            trigger.remove();
            panel.remove();
            init(config);
        });
        document.body.append(trigger, panel);
        if (retryOpen) trigger.click();
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
        // Actions still waiting for confirmation are shown again, so they can be confirmed or cancelled after a reload.
        _loadConversationFromServer().then((pendingActions) => {
            if (conversation.length > 0 || pendingActions.length > 0) {
                setTimeout(() => {
                    _restoreConversationUI();
                    showRestoredActions(pendingActions);
                }, 150);
            }
            return null;
        }).catch(() => null);

        // 3. Wire the events.
        wireUp();

        // 4. Listen to AJAX navigation changes (SPA-like themes).
        watchNavigation();

        // sessionStorage needs no save event: it persists automatically.

        injected = true;
        injecting = false;
        Log.debug('Jedomi sidebar injected successfully');
        Api.panelReady(showSuggestion);



        // Reopen automatically if it was open before navigating.
        if (sessionStorage.getItem('jedomi_open') === '1' || retryOpen) {
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
        // The welcome message comes resolved from the server as plain text; the template escapes it.
        const greeting = cfg.welcome_message || '';
        // Features that are off get no button, no attach option and no drop zone; the server refuses them too.
        const {html, js} = await Templates.renderForPromise('local_jedomi/sidebar', {
            greeting,
            assistant: cfg.assistant || {},
            strings: S,
            files_enabled: !!cfg.files_enabled,
            documents_enabled: !!(cfg.files_enabled && cfg.documents_enabled),
            audio_enabled: !!cfg.audio_enabled,
            attach_enabled: !!(cfg.files_enabled || cfg.audio_enabled),
            voice_enabled: !!(cfg.voice_enabled && cfg.audio_enabled && cfg.speech_url),
        });
        Templates.appendNodeContents(document.body, html, js);
        applyTriggerPosition();
        updateContextLabel();
        loadQuickActions();
        startGlowCycle();
    };

    // Distances of the button from the edges of its corner, set in the Personalization tab. The corner itself is a class
    // of the template; the stylesheet reads these variables, and ignores them on phones (phase 16 position).
    const applyTriggerPosition = () => {
        const trigger = document.getElementById('jedomi-trigger');
        if (!trigger) return;
        const assistant = cfg.assistant || {};
        const offset = (value) => {
            const number = Number(value);
            return Number.isFinite(number) ? Math.max(0, Math.min(500, Math.round(number))) + 'px' : '24px';
        };
        trigger.style.setProperty('--j-fab-x', offset(assistant.x));
        trigger.style.setProperty('--j-fab-y', offset(assistant.y));
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
        // Text of a table cell as shown in the data: links export only their text, without icon or type label.
        const csvCellText = (cell) => {
            const copy = cell.cloneNode(true);
            copy.querySelectorAll('a').forEach(link => {
                const label = link.querySelector('[data-link-text]');
                link.replaceWith(document.createTextNode(label ? label.textContent : link.textContent));
            });
            return copy.textContent.trim();
        };
        // A quoted CSV cell; a value starting with a formula character is exported as text.
        const csvCell = (value) => {
            const safe = /^[=+\-@\t\r]/.test(value) ? `'${value}` : value;
            return '"' + safe.replace(/"/g, '""') + '"';
        };
        const msgs = g('jedomi-messages');
        if (msgs) {
            msgs.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-csv-download]');
                if (!btn) return;
                const tableWrap = btn.closest('.jedomi-table-wrap');
                const tbl = tableWrap && tableWrap.querySelector('table');
                if (!tbl) return;
                const rows = [...tbl.querySelectorAll('tr')].map(tr =>
                    [...tr.querySelectorAll('th,td')].map(c => csvCell(csvCellText(c))).join(',')
                );
                const blob = new Blob(['\uFEFF' + rows.join('\n')], {type: 'text/csv;charset=utf-8;'});
                const url  = URL.createObjectURL(blob);
                const a    = document.createElement('a');
                a.href     = url;
                a.download = `${cfg.strings?.export_csv_filename || 'jedomi_data'}.csv`;
                document.body.appendChild(a);
                a.click();
                setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 500);
            });
        }
        on('jedomi-edit-last',    'click',  editLastMessage);
        on('jedomi-send',         'click',  sendMessage);
        on('jedomi-stop',         'click',  stopAnswer);
        on('jedomi-remove-image', 'click',  clearImage);
        on('jedomi-file-preview', 'click',  (e) => {
            const btn = e.target.closest('[data-action="local_jedomi/sidebar/remove-file"]');
            if (btn) removeFile(parseInt(btn.getAttribute('data-index'), 10));
        });
        on('jedomi-edit-last',    'click',  editLastMessage);
        on('jedomi-attach-btn',   'click',  toggleAttachMenu);
        on('jedomi-attach-menu',   'click',  (e) => {
            const btn = e.target.closest('.jedomi-attach-option');
            if (!btn) return;
            const type = btn.getAttribute('data-type');
            if (type === 'image') {
                const inp = g('jedomi-image-input');
                if (inp) inp.click();
            } else if (type === 'audio') {
                const inp = g('jedomi-audio-input');
                if (inp) inp.click();
            } else {
                const inp = g('jedomi-file-input');
                if (inp) inp.click();
            }
            closeAttachMenu();
        });
        on('jedomi-image-input',  'change', handleImageSelect);
        on('jedomi-file-input',   'change', handleFileSelect);
        on('jedomi-audio-input',  'change', handleFileSelect);
        on('jedomi-voice-btn',    'click',  startRecording);
        on('jedomi-voice-bar',    'click',  handleVoiceAction);
        on('jedomi-voice-mode-btn', 'click', startVoiceMode);
        on('jedomi-voice-mode',   'click',  (e) => {
            if (e.target.closest('[data-action="local_jedomi/sidebar/voice-mode-exit"]')) exitVoiceMode();
        });

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

        // While the panel is open the focus stays in it: Tab and Shift+Tab go round its controls.
        g('jedomi-sidebar')?.addEventListener('keydown', trapFocus);

        // Keyboard shortcuts. Alt+Shift is used because Ctrl+Shift+J and Ctrl+Shift+N are browser shortcuts (console
        // and private window). The physical key (code) is checked, as Alt changes the character on some keyboards.
        document.addEventListener('keydown', (e) => {
            // A key pressed in a core modal (for example the new conversation confirmation) belongs to that modal:
            // its Escape only closes the modal, not the sidebar.
            if (isInCoreModal(e.target)) return;
            // Escape → end the voice conversation first, then close the sidebar.
            if (e.key === 'Escape' && (voiceMode || voiceModeStarting)) {
                exitVoiceMode();
                return;
            }
            if (e.key === 'Escape' && g('jedomi-sidebar')?.classList.contains('jedomi-open')) {
                closeSidebar();
                return;
            }
            if (!e.altKey || !e.shiftKey || e.ctrlKey || e.metaKey) return;
            // Alt+Shift+J → open/close Jedomi.
            if (e.code === 'KeyJ') {
                e.preventDefault();
                const isOpen = g('jedomi-sidebar')?.classList.contains('jedomi-open');
                isOpen ? closeSidebar() : openSidebar();
            }
            // Alt+Shift+N → new conversation, after a confirmation when there is a conversation to lose.
            if (e.code === 'KeyN') {
                e.preventDefault();
                openSidebar();
                confirmNewConversation();
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
        if (!panel.contains(document.activeElement)) focusBeforeOpen = document.activeElement;

        // Align with Moodle's fixed-top navbar: position only, the layout is not touched.
        const navbar = document.querySelector('nav.navbar.fixed-top, .navbar.fixed-top');
        const navH   = navbar ? navbar.getBoundingClientRect().height : 0;
        // The stylesheet reads --j-nav-h: top and height of the panel, height of its header (same as the navbar).
        panel.style.setProperty('--j-nav-h', navH > 0 ? navH + 'px' : '0px');
        panel.classList.toggle('jedomi-below-navbar', navH > 0);

        panel.classList.replace('jedomi-closed', 'jedomi-open');
        // The open panel is reachable with the keyboard and by screen readers again.
        panel.inert = false;
        panel.removeAttribute('inert');
        panel.removeAttribute('aria-hidden');
        g('jedomi-trigger')?.setAttribute('aria-expanded', 'true');
        // Moodle's layout is NOT touched.
        setExpandMode(expandMode);
        g('jedomi-input')?.focus();
    };

    // Open the panel with a suggestion of another plugin (local_jedomi/api) written in the message field, as plain text:
    // the user can change it and sends it. It is never sent here. Answers whether the suggestion was written.
    const showSuggestion = (text) => {
        const input = g('jedomi-input');
        if (!input || voiceMode) {
            return false;
        }
        openSidebar();
        input.value = text;
        input.dispatchEvent(new Event('input'));
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
        return true;
    };

    // Whether an element is inside a core modal (Bootstrap .modal), which sits above the panel.
    const isInCoreModal = (el) => !!(el && el.closest && el.closest('.modal'));

    // Whether the open panel covers the whole page: full screen, or the phone layout where it takes the full width.
    // Only then does it behave as a dialog; beside the page it is a complementary region the user can Tab out of.
    const panelCoversPage = (panel) => panel.classList.contains('jedomi-expand-full')
        || window.matchMedia('(max-width: 767px)').matches;

    // While the panel covers the page, keep the focus inside it: Tab after its last control goes to the first one,
    // and Shift+Tab before its first control goes to the last one. The focus is never taken back from a core modal.
    const trapFocus = (e) => {
        const panel = g('jedomi-sidebar');
        if (e.key !== 'Tab' || !panel?.classList.contains('jedomi-open') || !panelCoversPage(panel)) return;
        if (isInCoreModal(document.activeElement) || isInCoreModal(e.target) || document.querySelector('.modal.show')) {
            return;
        }
        const focusable = [...panel.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]):not([type="file"]), textarea:not([disabled]), '
            + 'select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )].filter(el => el.offsetParent !== null && !el.closest('.jedomi-hidden'));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && (document.activeElement === first || !panel.contains(document.activeElement))) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    };

    const closeSidebar = () => {
        exitVoiceMode();
        // A recording in progress is thrown away and a recorded voice message stops playing.
        if (recorder && recorder.state === 'recording') {
            recordingCancelled = true;
            recorder.stop();
        }
        voice?.audio.pause();
        const panel = g('jedomi-sidebar');
        const trigger = g('jedomi-trigger');
        // The focus goes back to where it was (the trigger by default) before the panel stops being reachable.
        if (panel && panel.contains(document.activeElement)) {
            const back = focusBeforeOpen && document.contains(focusBeforeOpen) && !panel.contains(focusBeforeOpen)
                && focusBeforeOpen !== document.body ? focusBeforeOpen : trigger;
            back?.focus();
        }
        focusBeforeOpen = null;
        if (panel) {
            panel.classList.replace('jedomi-open', 'jedomi-closed');
            // The closed panel is out of the Tab order and hidden from screen readers.
            panel.inert = true;
            panel.setAttribute('inert', '');
            panel.setAttribute('aria-hidden', 'true');
        }
        trigger?.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('jedomi-push-half', 'jedomi-push-full');
        sessionStorage.removeItem('jedomi_open'); // limpiar estado
    };

    // Note shown after a reload in place of the images of a user message, which the history does not keep.
    const addImagePlaceholder = (wrap, count) => {
        const bubble = wrap.querySelector('.jedomi-bubble');
        if (!bubble) return;
        const note = document.createElement('p');
        note.className = 'jedomi-msg-image-placeholder';
        const label = (cfg.strings || {}).image_not_kept || '';
        note.textContent = count > 1 ? `🖼 ${label} (${count})` : `🖼 ${label}`;
        bubble.appendChild(note);
    };

    // Restore the saved messages in the DOM after a page reload.
    const _restoreConversationUI = () => {
        const msgs = g('jedomi-messages');
        if (!msgs || conversation.length === 0) return;
        // Clear the initial greeting.
        msgs.replaceChildren();
        conversation.forEach(msg => {
            const content = typeof msg.content === 'string' ? msg.content : '...';
            if (msg.role === 'user') {
                // The text the chat showed when it was sent (the question and the names of its files).
                const wrap = addMessage('user', typeof msg.display === 'string' && msg.display ? msg.display : content);
                // The images are not kept in the history (only that the message had them): a note takes their place.
                if (wrap && msg.images > 0) addImagePlaceholder(wrap, msg.images);
            } else if (msg.role === 'assistant') {
                const wrap = addMessage('assistant', content);
                // The answer gets its Copy and rating buttons back, with the same id, so its rating is kept.
                if (wrap) addMessageActions(wrap, content, msg.id || '');
            }
        });
    };

    // ── Moodle session cache: server, not client ─────────────────────────────
    const _convUrl = () => `${cfg.wwwroot}/local/jedomi/conversation.php`;
    // Signed permissions of the panel: the server takes the course of the request from them, and applies the course
    // availability and role visibility to it.
    const _signedPermissions = () => JSON.stringify(cfg.spt || {});
    // Course of the page the panel is on, as the server gave it with the configuration (0 outside a course). It is sent
    // with every request: the server refuses it when it is not the course signed in the permissions. It is never read
    // from the address, whose id is not a course on the pages of activities, profiles and others.
    const _pageCourseId = () => parseInt(cfg.context?.course_id, 10) || 0;

    const _loadConversationFromServer = async () => {
        try {
            await ensureFreshPermissions();
            const r = await fetch(_convUrl(), {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({action: 'get', sesskey: M?.cfg?.sesskey || '', spt: _signedPermissions(),
                    courseid: String(_pageCourseId())}),
            });
            const d = await r.json();
            if (d.ok && Array.isArray(d.messages) && d.messages.length > 0) {
                conversation = d.messages;
            }
            return d.ok && Array.isArray(d.pendingactions) ? d.pendingactions : [];
        } catch (e) {
            // First visit or empty cache: nothing to restore.
            return [];
        }
    };

    // Cards of the actions the server still holds for the user, each in its own answer bubble.
    const showRestoredActions = (cards) => {
        const msgs = g('jedomi-messages');
        if (!msgs || !cards.length) return;
        cards.forEach((pending) => {
            const wrap = document.createElement('div');
            wrap.className = 'jedomi-message jedomi-message-assistant';
            const bubble = document.createElement('div');
            bubble.className = 'jedomi-bubble';
            bubble.appendChild(buildActionConfirmUI(pending));
            wrap.appendChild(bubble);
            msgs.appendChild(wrap);
        });
        msgs.scrollTop = msgs.scrollHeight;
    };

    // Send a change of the conversation history to the server. The changes go one after the other, so a removal never
    // reaches the server before the message it removes. The permissions are renewed first when they are about to
    // expire; the body is built with the renewed ones.
    const _historyRequest = (fields) => {
        historyQueue = historyQueue.then(() => ensureFreshPermissions()).then(() => fetch(_convUrl(), {
            method: 'POST',
            credentials: 'same-origin',
            body: new URLSearchParams({
                ...fields,
                sesskey: M?.cfg?.sesskey || '',
                spt:     _signedPermissions(),
                courseid: String(_pageCourseId()),
            }),
        })).catch(() => null); // Silent.
        return historyQueue;
    };

    const _saveConversation = () => {
        // Only save the LAST message (the one just completed).
        if (conversation.length === 0) return;
        const last = conversation[conversation.length - 1];
        const fields = {action: 'append', role: last.role, content: last.content};
        if (last.display) fields.display = last.display;
        if (last.images) fields.images = String(last.images);
        if (last.id) fields.messageid = last.id;
        _historyRequest(fields);
    };

    // Remove the last user message, and what follows it, from the conversation and from the history on the server:
    // the question was edited, or its answer was stopped before any text or failed.
    const _undoLastQuestion = () => {
        const lastUserIdx = conversation.map(m => m.role).lastIndexOf('user');
        if (lastUserIdx < 0) return;
        conversation.splice(lastUserIdx);
        _historyRequest({action: 'undolast'});
    };

    // Stop the written answer in progress: what was received stays in the chat, marked as stopped.
    const stopAnswer = () => {
        chatController?.abort();
    };

    // New conversation from the keyboard shortcut: a conversation in progress is only cleared after a confirmation.
    const confirmNewConversation = () => {
        if (!conversation.length) {
            clearChat();
            return;
        }
        const S = cfg.strings || {};
        Notification.saveCancelPromise(S.new_conversation || '', S.new_conversation_confirm || '', S.new_conversation || '')
            .then(() => {
                clearChat();
                g('jedomi-input')?.focus();
                return null;
            })
            .catch(() => g('jedomi-input')?.focus());
    };

    const clearChat = () => {
        // An answer in progress is stopped: it belongs to the conversation that is cleared.
        if (chatController) {
            const controller = chatController;
            controller.cleared = true;
            controller.abort();
        }
        conversation = [];
        // Clear it on the server (Moodle session cache).
        _historyRequest({action: 'clear'});
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

        // Remove the last user message and what follows it, here and on the server, so they do not come back after a
        // reload.
        _undoLastQuestion();

        // Remove its bubble and the ones after it from the chat.
        const msgs = g('jedomi-messages');
        const editable = msgs ? [...msgs.querySelectorAll('.jedomi-message-user:not([data-orphan])')].pop() : null;
        if (editable) {
            while (editable.nextElementSibling) editable.nextElementSibling.remove();
            editable.remove();
        }
        refreshEditButton();
    };

    // Only the last question of the conversation can be edited: the Edit button is shown under it alone.
    const refreshEditButton = () => {
        const msgs = g('jedomi-messages');
        if (!msgs) return;
        msgs.querySelectorAll('.jedomi-user-actions').forEach(node => node.remove());
        const last = [...msgs.querySelectorAll('.jedomi-message-user:not([data-orphan])')].pop();
        if (!last) return;
        const editActions = document.createElement('div');
        editActions.className = 'jedomi-msg-actions jedomi-user-actions';
        last.insertBefore(editActions, last.firstChild);
        renderInto(editActions, 'local_jedomi/local/sidebar/user_actions', {strings: cfg.strings || {}}).then(() => {
            editActions.querySelector('[data-action="local_jedomi/sidebar/edit"]')
                ?.addEventListener('click', () => editLastMessage());
            return null;
        }).catch(() => null);
    };

    const exportConversation = () => {
        if (!conversation.length) return;
        // The title, the speaker names and the file name are language strings, in the language of the user.
        const L = cfg.strings || {};
        const speaker = (role) => (role === 'user' ? L.export_role_user : L.export_role_assistant) || role;
        const lines = conversation.map(m =>
            `[${speaker(m.role)}]\n${typeof m.content === 'string' ? m.content : JSON.stringify(m.content)}\n`
        ).join('\n---\n\n');
        const title = L.export_title || 'Jedomi';
        const blob = new Blob([`${title}\n${'='.repeat(40)}\n\n${lines}`], {type: 'text/plain'});
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        const filename = L.export_filename || 'jedomi-conversation';
        a.download = `${filename}-${new Date().toISOString().slice(0,10)}.txt`;
        a.click();
        URL.revokeObjectURL(a.href);
    };

    // ── Context Label & Quick Actions ─────────────────────────────────────────
    const updateContextLabel = () => {
        const ctx  = cfg.context || {};
        const loc  = ctx.location || 'unknown';
        const S    = cfg.strings || {};
        const labels = {
            course:    '\uD83D\uDCDA ' + (ctx.course_name || S.ctx_course || ''),
            activity:  '\uD83D\uDCDD ' + (ctx.activity_name || ctx.course_name || S.ctx_activity || ''),
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

    const loadQuickActions = async () => {
        const container = g('jedomi-quick-actions');
        if (!container) return;

        const ctx      = cfg.context || {};
        const S        = cfg.strings || {};   // ← FIX: needed by all quick action labels
        const spt      = cfg.spt    || {};
        const role     = cfg.user_role || ctx.user_role || 'student';
        const caps     = spt.capabilities || {};
        // The location and the course come from the server, which reads them from the page; never from the address,
        // whose id is not a course on the pages of activities, profiles and others.
        const loc      = ctx.location || 'unknown';
        const cid      = parseInt(ctx.course_id, 10) || 0;
        // Use the course name when available, not the ID.
        const courseName = ctx.course_name
            ? `"${ctx.course_name}"`
            : (cid ? (S.qa_course_with_id || '').replace('{$a}', cid) : (S.qa_course_current || ''));

        let actions = [];

        // ── Inside a course: the course of the page, as the server gave it ──
        const inCourse = cid > 0 && loc !== 'admin' && loc !== 'dashboard';

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

    // ── Send Message ──────────────────────────────────────────────────────────
    const sendMessage = async () => {
        const input   = g('jedomi-input');
        const sendBtn = g('jedomi-send');
        const text    = input?.value.trim() || '';

        if ((!text && !pendingImage && !pendingFiles.length) || sending) return;

        const S = cfg.strings || {};
        let displayText = text;
        let modelText = text;

        // The attached files are read now, when the message is sent, as images are: a file removed before sending is
        // never read nor charged, and removing it while it is read cancels its reading. The text of each one is kept
        // on its attachment, so a retry after another error reads nothing again.
        const attachments = pendingFiles.slice();
        for (const attachment of attachments) {
            if (attachment.extracted) continue;
            sending = true;
            if (sendBtn) sendBtn.disabled = true;
            const origPlaceholder = input?.placeholder;
            if (input) input.placeholder = (S.processing_file || '').replace('{$a}', attachment.name);
            attachment.controller = new AbortController();
            try {
                const data = await uploadForText(cfg.extract_file_url, attachment.file, {}, attachment.controller.signal);
                // The chat model gets only the name and text of the file between untrusted data markers, so it never
                // follows what the file says. An answer without them is an error: the text is never sent unwrapped.
                if (typeof data.untrusted_text !== 'string' || data.untrusted_text === '') {
                    throw new Error(S.unknown_error || '');
                }
                attachment.extracted = {info: fileInfo(data), text: data.untrusted_text};
                if (data.truncated) {
                    addMessage('assistant', `⚠️ ${(S.file_truncated || '').replace('{$a}', attachment.name)}`);
                }
            } catch (err) {
                if (err?.name === 'AbortError') return;
                // The file stays attached, so the user can try again or remove it.
                addMessage('assistant', uploadErrorMessage(err, attachment.name));
                return;
            } finally {
                attachment.controller = null;
                sending = false;
                if (sendBtn) sendBtn.disabled = false;
                if (input && origPlaceholder) input.placeholder = origPlaceholder;
            }
            // Removed while it was being read: the message is not sent, so the user can review it.
            if (!pendingFiles.includes(attachment)) return;
        }
        // Removed or added while the others were read: the message is not sent, so the user can review it.
        if (attachments.length !== pendingFiles.length || attachments.some((a, i) => pendingFiles[i] !== a)) return;

        // The image is read by the vision model first: the chat model only gets the question and that text.
        if (pendingImage) {
            sending = true;
            if (sendBtn) sendBtn.disabled = true;
            const origPlaceholder = input?.placeholder;
            if (input) input.placeholder = S.reading_image || '';
            try {
                const data = await uploadForText(cfg.read_file_url, pendingImage.file, {question: text});
                modelText = data.message || '';
            } catch (err) {
                // The image stays attached, so the user can try again.
                addMessage('assistant', uploadErrorMessage(err, pendingImage.file.name));
                return;
            } finally {
                sending = false;
                if (sendBtn) sendBtn.disabled = false;
                if (input && origPlaceholder) input.placeholder = origPlaceholder;
            }
        }

        // The text of each file goes with the question in the same message; the chat shows only their names.
        if (attachments.length) {
            const question = modelText || S.analyze_file || '';
            // The name of each file is inside its untrusted block, never outside it.
            const parts = attachments.map(({extracted}) => extracted.text);
            modelText = [question, ...parts].join('\n\n');
            const names = attachments.map(({name, extracted}) => `📎 ${name} (${extracted.info})`).join('\n');
            displayText = `${text ? text + '\n\n' : ''}${names}`;
        }

        const image = pendingImage;
        if (input) { input.value = ''; input.style.height = ''; }
        clearImage();
        clearFile();
        await runChat(displayText, modelText, image);
    };

    // Display of a spoken answer: each sentence appears in the bubble, as plain text, when it starts to be heard, and
    // the complete answer (tables, code, links, confirmation card) replaces it when the turn ends. finish() can be
    // asked before the answer arrives (an interruption): the complete answer is then shown as soon as it arrives.
    const createVoiceDisplay = (bubble) => {
        const display = {
            shown: false, done: false, spoken: '', showAnswer: null, finishRequested: false,
            showSpoken: (text) => {
                if (display.done || !text) return;
                if (!display.shown) {
                    display.shown = true;
                    setThinking(false);
                    bubble.thinking = false;
                    bubble.el.classList.remove('jedomi-hidden');
                }
                display.spoken = display.spoken ? `${display.spoken} ${text}` : text;
                bubble.version++;
                bubble.inner.textContent = display.spoken;
                const msgs = g('jedomi-messages');
                if (msgs) msgs.scrollTop = msgs.scrollHeight;
            },
            setFinish: (showAnswer) => {
                display.showAnswer = showAnswer;
                if (display.finishRequested) display.finish();
            },
            finish: () => {
                display.finishRequested = true;
                if (display.done || !display.showAnswer) return;
                display.done = true;
                setThinking(false);
                display.showAnswer();
            },
        };
        return display;
    };

    // Show the user message, add it to the conversation and stream the answer of the chat proxy.
    // displayText is what the chat shows, modelText what the model receives and image the attached image, if any.
    // voiceHooks, given by the voice conversation, asks for a spoken answer (voice_mode) and receives the answer as it
    // arrives: {onToken(token), onPendingAction(pending), onDone(), onError(err)}.
    const runChat = async (displayText, modelText, image = null, voiceHooks = null) => {
        const input   = g('jedomi-input');
        const sendBtn = g('jedomi-send');

        const userWrap = addMessage('user', displayText, image);
        const userMessage = {role: 'user', content: modelText};
        // What the chat shows for the question (without the text read from its files), kept for a reload.
        if (displayText && displayText !== modelText) userMessage.display = displayText;
        // Only the number of images is kept for a reload, never the images themselves.
        if (image) userMessage.images = 1;
        conversation.push(userMessage);
        _saveConversation(); // Save at once.

        setThinking(true);
        sending = true;
        if (sendBtn) sendBtn.disabled = true;
        // A written answer can be stopped: the stop button replaces the send button while it is in progress. The
        // voice conversation stops its answers by speaking over Jedomi.
        const controller = voiceHooks ? null : new AbortController();
        chatController = controller;
        const stopBtn = g('jedomi-stop');
        if (controller) {
            sendBtn?.classList.add('jedomi-hidden');
            stopBtn?.classList.remove('jedomi-hidden');
        }
        // Screen readers announce the answer once it is complete, not every token.
        g('jedomi-messages')?.setAttribute('aria-busy', 'true');

        // Create an empty bubble to fill while streaming.
        const streamBubble = createStreamBubble();
        // A spoken answer is shown as it is heard (see createVoiceDisplay), not as it arrives.
        const voiceDisplay = voiceHooks ? createVoiceDisplay(streamBubble) : null;
        voiceHooks?.onDisplay?.(voiceDisplay);

        let fullText = '';
        let pendingAction = null;
        // Technical action blocks (JEDOMI_ACTION / JEDOMI_PENDING_ACTION) came from the legacy
        // gateway; confirmations now arrive as the _jedomi_pending_action event, so any such
        // block the model still writes is removed from the answer.
        const withoutActionBlocks = (text) => text
            .replace(/JEDOMI_ACTION[\s\S]*?END_ACTION/g, '')
            .replace(/JEDOMI_PENDING_ACTION:[\s\S]*?:END_PENDING/g, '')
            .trim();
        try {
            await callGatewayStream(
                conversation,
                // onToken: add each token to the bubble.
                (token) => {
                    fullText += token;
                    voiceHooks?.onToken(token);
                    if (voiceDisplay) return;
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
                        site_overview:  'chart',
                        ws_search:      'search',
                        ws_describe:    'book',
                        ws_execute:     'zap',
                    };
                    const toolLabels = {
                        search_tables:  S2.tool_search_tables || '',
                        execute_sql:    S2.tool_execute_sql || '',
                        describe_table: S2.tool_describe_table || '',
                        get_site_config:S2.tool_get_site_config || '',
                        site_overview:  S2.tool_site_overview || '',
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
                    voiceHooks?.onPendingAction(pending);
                },
                voiceHooks ? {voice_mode: true} : {},
                voiceHooks?.signal || controller?.signal || null
            );

            fullText = withoutActionBlocks(fullText);
            const messageId = newMessageId();
            const showAnswer = () => {
                streamBubble.version++;
                if (voiceDisplay) streamBubble.el.classList.remove('jedomi-hidden');
                streamBubble.inner.innerHTML = renderMarkdown(fullText);
                if (pendingAction) {
                    streamBubble.el.classList.remove('jedomi-hidden');
                    streamBubble.inner.appendChild(buildActionConfirmUI(pendingAction));
                }
                addMessageActions(streamBubble.wrap, fullText, messageId);

                // Links: open in the same tab directly (no preventDefault).
                streamBubble.inner.querySelectorAll('a[href]').forEach(a => {
                    if (!a.getAttribute('target')) {
                        a.setAttribute('target', '_self');
                    }
                });
            };
            if (voiceDisplay) {
                voiceDisplay.setFinish(showAnswer);
            } else {
                showAnswer();
            }

            conversation.push({role: 'assistant', content: fullText, id: messageId});
            _saveConversation(); // Save it on the server.
            voiceHooks?.onDone();

        } catch (err) {
            if (voiceDisplay) voiceDisplay.done = true;
            if (voiceHooks && err?.name === 'AbortError') {
                setThinking(false);
                if (pendingAction) {
                    // The server already holds an action: its card is shown, so it can still be confirmed or cancelled.
                    streamBubble.version++;
                    streamBubble.inner.innerHTML = renderMarkdown(withoutActionBlocks(fullText));
                    streamBubble.inner.appendChild(buildActionConfirmUI(pendingAction));
                    streamBubble.el.classList.remove('jedomi-hidden');
                    return;
                }
                // The user spoke over Jedomi: the answer was cancelled on purpose and leaves nothing in the chat.
                streamBubble.wrap?.remove();
                return;
            }
            if (controller && err?.name === 'AbortError') {
                setThinking(false);
                // A new conversation stopped it: the chat was already cleared.
                if (controller.cleared) {
                    streamBubble.wrap?.remove();
                    return;
                }
                const partial = withoutActionBlocks(fullText);
                if (!partial && !pendingAction) {
                    // Stopped before any text: the question goes back to the message field, as when it is edited.
                    editLastMessage();
                    renderInto(g('jedomi-messages'), 'local_jedomi/local/sidebar/notice',
                        {text: cfg.strings?.answer_stopped || ''}, true);
                    return;
                }
                // The text received so far stays, marked as stopped, and is kept in the conversation.
                const messageId = newMessageId();
                streamBubble.version++;
                streamBubble.inner.innerHTML = renderMarkdown(partial);
                if (pendingAction) streamBubble.inner.appendChild(buildActionConfirmUI(pendingAction));
                const note = document.createElement('p');
                note.className = 'jedomi-stopped-note text-muted small mb-0';
                note.textContent = cfg.strings?.answer_stopped || '';
                streamBubble.inner.appendChild(note);
                streamBubble.el.classList.remove('jedomi-hidden');
                addMessageActions(streamBubble.wrap, partial, messageId);
                conversation.push({role: 'assistant', content: partial, id: messageId});
                _saveConversation();
                return;
            }
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
            // The question has no answer: it leaves the history (here and on the server), so it is not sent again
            // as if it had been answered. It stays in the chat with the error, and can no longer be edited.
            if (!pendingAction) {
                _undoLastQuestion();
                if (userWrap) userWrap.dataset.orphan = '1';
                refreshEditButton();
            }
            voiceHooks?.onError(err);
        } finally {
            // A spoken answer keeps the thinking indicator until its first sentence is heard.
            if (!voiceDisplay || voiceDisplay.shown || voiceDisplay.done) setThinking(false);
            sending = false;
            if (sendBtn) sendBtn.disabled = false;
            if (controller) {
                if (chatController === controller) chatController = null;
                stopBtn?.classList.add('jedomi-hidden');
                sendBtn?.classList.remove('jedomi-hidden');
            }
            g('jedomi-messages')?.setAttribute('aria-busy', 'false');
            input?.focus();
        }
    };

    // ── Call Chat Proxy (streaming) ─────────────────────────────────────────
    // The proxy receives the messages, adds the tools and the anti-hallucination system prompt,
    // runs the tool loop against the real Moodle DB and returns SSE.
    // extra adds fields to the request, such as voice_mode for the voice conversation.
    // signal, when given, cancels the request (the voice conversation does it when the user speaks over Jedomi).
    const callGatewayStream = async (messages, onToken, onToolEvent, onPendingAction, extra = {}, signal = null) => {
        // Context truncation: at most 30 messages to avoid a context overflow. Only the role and the content are sent:
        // the text shown in the chat and the id of an answer stay in the browser.
        const truncated = messages.slice(-30).map(({role, content}) => ({role, content}));

        // The page context (location, course, activity and path) is the one the server read from the page; the server
        // reads the names again and never uses free text of the browser. The payload is built after a renewal, so it
        // carries the renewed permissions.
        const request = () => fetch(cfg.chatproxy_url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                messages: truncated,
                spt:      cfg.spt,
                sesskey:  cfg.sesskey,
                context:  {
                    ...cfg.context,
                    course_id:  _pageCourseId(),
                },
                ...extra,
            }),
            signal: signal || undefined,
        });

        await ensureFreshPermissions();
        let res = await request();
        // Refused permissions (expired or no longer valid): they are renewed while the Moodle session is active and
        // the request is sent once more.
        if (res.status === 403 && await renewPermissions()) {
            res = await request();
        }

        if (!res.ok) {
            // Detect an expired session (403 from chatproxy.php → invalid sesskey/SPT that could not be renewed).
            if (res.status === 403) {
                throw new Error('__SESSION_EXPIRED__');
            }
            const err = await res.json().catch(() => ({}));
            const error = new Error(err.detail || err.error?.message || `HTTP ${res.status}`);
            // A refusal with a message ready to show (such as a request above the size cap) is shown as it is.
            error.jedomiServerError = !!err.error?.message;
            throw error;
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

    // Id of a new answer, so its rating is stored once and can be changed (letters, digits, - and _ only).
    const newMessageId = () => `${(sessionId || 'jedomi').replace(/[^\w-]/g, '').slice(0, 36)}-${Date.now().toString(36)}`
        + `-${Math.random().toString(36).slice(2, 8)}`;

    // Action buttons under each assistant answer. messageId identifies the answer: rating it again, with the same or
    // the other value, changes its rating instead of adding another one.
    const addMessageActions = async (wrap, text, messageId = '') => {
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
        const good = actions.querySelector('[data-action="local_jedomi/sidebar/good"]');
        const bad = actions.querySelector('[data-action="local_jedomi/sidebar/bad"]');
        // Only one rating is shown as given: the last one.
        const showRating = (rating) => {
            good?.classList.toggle('jedomi-feedback-good', rating === 'good');
            good?.setAttribute('aria-pressed', rating === 'good' ? 'true' : 'false');
            bad?.classList.toggle('jedomi-feedback-bad', rating === 'bad');
            bad?.setAttribute('aria-pressed', rating === 'bad' ? 'true' : 'false');
        };
        good?.addEventListener('click', () => {
            showRating('good');
            actions.querySelector('.jedomi-feedback-comment')?.remove();
            saveFeedback(text, 'good', messageId);
        });
        bad?.addEventListener('click', async () => {
            showRating('bad');
            saveFeedback(text, 'bad', messageId);
            if (actions.querySelector('.jedomi-feedback-comment')) return;
            // "Improve" offers an optional comment, saved with the same rating.
            const holder = document.createElement('div');
            holder.className = 'jedomi-feedback-comment';
            actions.appendChild(holder);
            await renderInto(holder, 'local_jedomi/local/sidebar/feedback_comment', {
                uniqid: `jedomi-comment-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`,
                strings: cfg.strings || {},
            });
            const field = holder.querySelector('[data-region="local_jedomi/sidebar/feedback-comment-text"]');
            field?.focus();
            holder.querySelector('[data-action="local_jedomi/sidebar/feedback-comment-send"]')?.addEventListener('click', () => {
                const comment = (field?.value || '').trim();
                if (!comment) return;
                saveFeedback(text, 'bad', messageId, comment);
                const thanks = document.createElement('p');
                thanks.className = 'jedomi-feedback-comment small mb-0';
                thanks.setAttribute('role', 'status');
                thanks.textContent = cfg.strings?.feedback_comment_saved || '';
                holder.replaceWith(thanks);
            });
        });
    };

    const saveFeedback = async (text, rating, messageId = '', comment = null) => {
        const args = {rating, sessionid: sessionId || '', messagetext: text.slice(0, 1000), messageid: messageId};
        if (comment !== null) args.comment = comment;
        try {
            await Ajax.call([{methodname: 'local_jedomi_save_feedback', args}])[0];
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
        }

        wrap.appendChild(bubble);
        msgs.appendChild(wrap);
        // Only the last question can be edited: the Edit button moves under it.
        if (role === 'user') refreshEditButton();
        msgs.scrollTop = msgs.scrollHeight;
        return wrap;
    };

    // Detect the Moodle page type from the URL.
    const getMoodlePageInfo = (url) => {
        const wwwroot = cfg.wwwroot || '';
        if (!url.includes(wwwroot) && !url.startsWith('/')) return null;

        // The type labels are language strings, in the language of the user.
        const L = cfg.strings || {};
        const patterns = [
            { re: /\/course\/view\.php\?id=(\d+)/,      icon: '📚', type: L.link_type_course },
            { re: /\/user\/view\.php\?id=(\d+)/,        icon: '👤', type: L.link_type_profile },
            { re: /\/mod\/assign\/view\.php\?id=(\d+)/,  icon: '📝', type: L.link_type_assign },
            { re: /\/mod\/quiz\/view\.php\?id=(\d+)/,    icon: '❓', type: L.link_type_quiz },
            { re: /\/grade\/report\/user/,               icon: '📊', type: L.link_type_grades },
            { re: /\/admin\//,                           icon: '⚙️', type: L.link_type_admin },
            { re: /\/message\//,                         icon: '💬', type: L.link_type_messages },
        ];

        for (const p of patterns) {
            if (p.re.test(url)) {
                const match = url.match(p.re);
                return { icon: p.icon, type: p.type, id: match?.[1] };
            }
        }
        return { icon: '🔗', type: L.link_type_moodle || 'Moodle', id: null };
    };

    // Italic text: _text_ only when the underscores are not part of a word, a path or an address, so names such as
    // course_modules or mod_assign, and addresses with underscores, are shown as they are.
    const ITALIC_PATTERN = /(^|[^\w\\/.:=&?%-])_([^_\s](?:[^_\n]*[^_\s])?)_(?![\w/])/g;

    const renderMarkdown = (text) => {
        if (!text) return '';
        // Code and links are set aside while the rest is formatted, so their content (names and addresses with
        // underscores, marks of lists or headings) is never formatted; they are put back at the end.
        const kept = [];
        const keep = (html) => `\u0000${kept.push(html) - 1}\u0000`;
        let html = text.replace(/\u0000/g, '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
            // Code blocks
            .replace(/```[\w]*\n([\s\S]*?)```/g, (match, code) => keep(`<pre><code>${code}</code></pre>`))
            // Inline code
            .replace(/`([^`\n]+)`/g, (match, code) => keep(`<code>${code}</code>`))
            // Bold
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            // Rich Moodle links [text](url): a preview card for Moodle URLs.
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, linkText, url) => {
                // Only web and site-relative links: model output can carry javascript: or data: URLs.
                if (!/^(https?:\/\/|\/(?!\/))/i.test(url)) {
                    return linkText;
                }
                const pageInfo = getMoodlePageInfo(url);
                if (pageInfo) {
                    return keep(`<a href="${url}" class="jedomi-moodle-link" data-type="${pageInfo.type}">` +
                           `${pageInfo.icon} <span data-link-text>${linkText}</span> ` +
                           `<span class="jedomi-link-badge">${pageInfo.type} ↗</span></a>`);
                }
                return keep(`<a href="${url}"><span data-link-text>${linkText}</span> ↗</a>`);
            })
            // Bare web addresses keep their underscores.
            .replace(/\bhttps?:\/\/[^\s<]+/g, (url) => keep(url))
            // Italic
            .replace(ITALIC_PATTERN, '$1<em>$2</em>')
            // Headings: # to ###### become small headings that fit in the panel.
            .replace(/^(#{1,6}) +(.+?)#*\s*$/gm, (match, marks, title) =>
                `<h${Math.min(marks.length + 3, 6)} class="jedomi-md-heading">${title}</h${Math.min(marks.length + 3, 6)}>`)
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
            .replace(/((?:<li>.*<\/li>\n?)+)/g, (block) => `<ul>${block.replace(/\n/g, '')}</ul>\n`)
            // Numbered lists, which keep their first number.
            .replace(/^(\d+)[.)] (.+)$/gm, '<li data-number="$1">$2</li>')
            .replace(/((?:<li data-number="\d+">.*<\/li>\n?)+)/g, (block) => {
                const start = parseInt(block.match(/data-number="(\d+)"/)[1], 10);
                const items = block.replace(/<li data-number="\d+">/g, '<li>').replace(/\n/g, '');
                return `<ol${start !== 1 ? ` start="${start}"` : ''}>${items}</ol>\n`;
            })
            // No line break right after a heading or a list.
            .replace(/(<\/h[4-6]>|<\/[ou]l>)\n/g, '$1')
            // Paragraphs
            .replace(/\n\n/g, '</p><p>')
            .replace(/\n/g, '<br>');

        html = html.replace(/\u0000(\d+)\u0000/g, (match, index) => kept[index]);
        return '<p>' + html + '</p>';
    };

    // ── Image ─────────────────────────────────────────────────────────────────
    // Whether a file is above the attachment cap of the server: such a file is never uploaded.
    const isOverUploadCap = (file) => !!cfg.max_upload_bytes && file.size > cfg.max_upload_bytes;

    // Tell the user that a file was not attached because it is above the attachment cap.
    const reportOverUploadCap = (file) => {
        const S = cfg.strings || {};
        addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.file_error_upload_too_large || ''}`);
    };

    // Error codes of the message limit (the same for the chat and the file endpoints).
    const RATE_LIMIT_CODES = ['rate_limited', 'rate_limit_busy'];

    // Message for a failed upload: a refusal of the message limit is shown as the chat shows it, without the file name.
    const uploadErrorMessage = (err, name) => {
        const S = cfg.strings || {};
        return RATE_LIMIT_CODES.includes(err.code)
            ? `⚠️ ${err.message}`
            : `⚠️ ${S.file_process_error || ''} **${name}**: ${err.message}`;
    };

    const handleImageSelect = (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        if (isOverUploadCap(file)) {
            reportOverUploadCap(file);
            e.target.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = (ev) => {
            const dataUrl = ev.target.result;
            pendingImage = { base64: dataUrl.split(',')[1], mimeType: file.type || 'image/jpeg', file: file };
            const preview = g('jedomi-image-preview');
            const img     = g('jedomi-preview-img');
            if (img) img.src = dataUrl;
            preview?.classList.remove('jedomi-hidden');
        };
        reader.readAsDataURL(file);
    };

    // ── File (PDF/CSV/TXT/MD/Word/PowerPoint) and audio file ──────────────────
    // When the message is sent, Moodle sends the file to the AI service through the Datacurso provider and returns
    // its text (or the transcription of an audio file), which is added to the message with its pages, rows and
    // columns, characters or seconds. Until then the file stays in the browser.
    // Document extensions the server reads (the audio ones are AUDIO_EXTENSIONS); the server checks them again.
    const FILE_EXTENSIONS = ['pdf', 'csv', 'txt', 'md', 'doc', 'docx', 'ppt', 'pptx'];

    const handleFileSelect = (e) => {
        const file = e.target.files?.[0];
        const files = [...(e.target.files || [])];
        if (files.length) attachFiles(files);
    };

    // Pages, rows and columns, characters or seconds of a read file, as shown with its name.
    const fileInfo = (data) => {
        const S = cfg.strings || {};
        return data.format === 'csv'
            ? (S.file_csv || '').replace('{rows}', data.rows).replace('{columns}', data.columns)
            : data.format === 'audio'
            ? (S.file_audio || '').replace('{seconds}', data.seconds)
            : data.format === 'pdf'
            ? (S.file_pdf || '').replace('{pages}', data.pages)
            : (S.file_text || '').replace('{chars}', data.chars);
    };

    // Most files attached to one message.
    const MAX_ATTACHMENTS = 5;

    // Attach files: only their chips are shown, like an image, so the textarea stays free for the question. Nothing is
    // uploaded, read or charged until the message is sent; removing a chip before sending makes no request. A file of
    // a kind that cannot be read, or beyond MAX_ATTACHMENTS, is not attached and the user is told why.
    const attachFiles = (files) => {
        const S = cfg.strings || {};
        let overLimit = false;
        files.forEach((file) => {
            const extension = (file.name.split('.').pop() || '').toLowerCase();
            if (!FILE_EXTENSIONS.includes(extension) && !AUDIO_EXTENSIONS.includes(extension)) {
                addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.file_error_unsupported || ''}`);
            } else if (isOverUploadCap(file)) {
                reportOverUploadCap(file);
            } else if (pendingFiles.length >= MAX_ATTACHMENTS) {
                overLimit = true;
            } else {
                pendingFiles.push({file, name: file.name, extracted: null, controller: null});
            }
        });
        if (overLimit) {
            addMessage('assistant', `⚠️ ${(S.file_attach_limit || '').replace('{$a}', MAX_ATTACHMENTS)}`);
        }
        renderFileChips();
        if (pendingFiles.length) g('jedomi-input')?.focus();
        const fi = g('jedomi-file-input');
        if (fi) fi.value = '';
        const ai = g('jedomi-audio-input');
        if (ai) ai.value = '';
    };

    // Show a chip for each attached file, each one with its remove button.
    const renderFileChips = () => {
        const preview = g('jedomi-file-preview');
        if (!preview) return;
        const S = cfg.strings || {};
        preview.classList.toggle('jedomi-hidden', !pendingFiles.length);
        if (!pendingFiles.length) {
            preview.replaceChildren();
            return;
        }
        renderInto(preview, 'local_jedomi/local/sidebar/file_chips', {
            files: pendingFiles.map((attachment, index) => ({
                index,
                name: attachment.name,
                removelabel: (S.file_remove || '').replace('{$a}', attachment.name),
            })),
        });
    };

    // Remove one attached file: a reading in progress is cancelled, so it is not charged.
    const removeFile = (index) => {
        const attachment = pendingFiles[index];
        if (!attachment) return;
        attachment.controller?.abort();
        pendingFiles.splice(index, 1);
        renderFileChips();
    };

    // Send a file to a Moodle endpoint that answers JSON, or {"error": {"code", "message"}} with a message to show.
    const uploadForText = async (url, file, fields = {}, signal = null) => {
        const S = cfg.strings || {};
        // Built for each try, so a retry after a renewal carries the renewed permissions.
        const request = async () => {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('sesskey', cfg.sesskey || '');
            formData.append('spt', _signedPermissions());
            formData.append('courseid', String(_pageCourseId()));
            Object.entries(fields).forEach(([name, value]) => formData.append(name, value));
            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                signal: signal || undefined,
            });
            return {res: response, data: await response.json().catch(() => ({}))};
        };

        await ensureFreshPermissions();
        let {res, data} = await request();
        // Expired permissions are renewed while the Moodle session is active and the file is sent once more; nothing
        // was read nor charged on the refused try.
        if (res.status === 403 && data.error?.code === 'invalid_spt' && await renewPermissions()) {
            ({res, data} = await request());
        }
        if (data.error || !res.ok) {
            const error = new Error((data.error ? data.error.message || S.unknown_error : S.ai_error_connection) || '');
            // The status and the code tell a refusal (expired session, voice no longer allowed) from a failure.
            error.status = res.status;
            error.code = data.error?.code || data.errorcode || '';
            throw error;
        }
        return data;
    };

    // ── Microphone ──────────────────────────────────────────────────────────
    // A voice message, like in Telegram: the voice bar replaces the input row while recording, and after
    // stopping the recording can be played, deleted or sent. It stays in the browser until it is sent: only
    // then it is uploaded and transcribed by the audio model (billed per second), and its text is sent as
    // the user message. The text typed in the input box is kept for later.
    // The same audio extensions the server transcribes (audio_service::EXTENSIONS).
    const AUDIO_EXTENSIONS = ['ogg', 'oga', 'opus', 'webm', 'mp3', 'm4a', 'mp4', 'wav'];
    const RECORDING_TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/ogg', 'audio/mp4'];
    const VOICE_ACTION = 'local_jedomi/sidebar/voice-';

    // A number of seconds as m:ss.
    const formatDuration = (seconds) => {
        const total = Math.max(0, Math.round(seconds || 0));
        return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
    };

    // An element of the voice bar by its data-region.
    const voiceRegion = (name) => g('jedomi-voice-bar')?.querySelector(`[data-region="local_jedomi/sidebar/${name}"]`);

    // Render the voice bar (recording or preview) in place of the input row and focus its main button.
    const showVoiceBar = async (recording, seconds) => {
        const bar = g('jedomi-voice-bar');
        if (!bar) return;
        const version = ++voiceBarVersion;
        try {
            const {html, js} = await Templates.renderForPromise('local_jedomi/local/sidebar/voice_bar',
                {recording, duration: formatDuration(seconds), strings: cfg.strings || {}});
            if (version !== voiceBarVersion) return;
            Templates.replaceNodeContents(bar, html, js);
        } catch (error) {
            Log.error('Jedomi: template local_jedomi/local/sidebar/voice_bar could not be rendered', error);
            return;
        }
        bar.classList.remove('jedomi-hidden');
        g('jedomi-input')?.closest('.jedomi-input-row')?.classList.add('jedomi-hidden');
        bar.querySelector(`[data-action="${VOICE_ACTION}${recording ? 'stop' : 'send'}"]`)?.focus();
    };

    // Hide the voice bar and give the input row back, with the text typed before recording.
    const hideVoiceBar = () => {
        voiceBarVersion++;
        const bar = g('jedomi-voice-bar');
        if (bar) {
            bar.classList.add('jedomi-hidden');
            bar.replaceChildren();
        }
        const input = g('jedomi-input');
        input?.closest('.jedomi-input-row')?.classList.remove('jedomi-hidden');
        input?.focus();
    };

    const startRecording = async () => {
        const S = cfg.strings || {};
        if (recorder || voice || voiceMode || voiceModeStarting) return;
        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
            addMessage('assistant', `⚠️ ${S.mic_error || ''}`);
            return;
        }

        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({audio: true});
        } catch (err) {
            addMessage('assistant', `⚠️ ${S.mic_error || ''}`);
            return;
        }

        const type = RECORDING_TYPES.find(t => MediaRecorder.isTypeSupported(t)) || '';
        const current = new MediaRecorder(stream, type ? {mimeType: type} : undefined);
        const chunks = [];
        const started = Date.now();
        let timer = null;

        current.addEventListener('dataavailable', (e) => {
            if (e.data && e.data.size) chunks.push(e.data);
        });
        current.addEventListener('stop', () => {
            stream.getTracks().forEach(track => track.stop());
            clearInterval(timer);
            recorder = null;
            const mime = current.mimeType || type || 'audio/webm';
            const blob = new Blob(chunks, {type: mime});
            if (recordingCancelled || !blob.size) {
                hideVoiceBar();
                return;
            }
            if (Date.now() - started < VOICE_MESSAGE_MIN_MS) {
                // A tap on the microphone is not a message: nothing is uploaded nor charged.
                hideVoiceBar();
                addMessage('assistant', `⚠️ ${(cfg.strings || {}).voice_too_short || ''}`);
                return;
            }
            // The duration is measured here: a recorded WebM often reports an unknown duration.
            const url = URL.createObjectURL(blob);
            const audio = new Audio(url);
            voice = {blob, url, audio, mime, seconds: (Date.now() - started) / 1000};
            audio.addEventListener('timeupdate', updateVoiceProgress);
            audio.addEventListener('pause', () => setVoicePlaying(false));
            audio.addEventListener('ended', () => {
                audio.currentTime = 0;
                setVoicePlaying(false);
                updateVoiceProgress();
            });
            showVoiceBar(false, voice.seconds);
        });

        recorder = current;
        recordingCancelled = false;
        current.start();
        showVoiceBar(true, 0);
        timer = setInterval(() => {
            const time = voiceRegion('voice-time');
            if (time) time.textContent = formatDuration((Date.now() - started) / 1000);
            if (Date.now() - started >= VOICE_MESSAGE_MAX_MS && current.state === 'recording') {
                // The longest recording the audio model accepts: it stops, and the user can listen to it and send it.
                clearInterval(timer);
                current.stop();
                addMessage('assistant', `⚠️ ${((cfg.strings || {}).voice_too_long || '').replace('{$a}',
                    VOICE_MESSAGE_MAX_MS / 60000)}`);
            }
        }, 500);
    };

    // Clicks on the voice bar buttons, through event delegation because the bar is rendered again for each state.
    const handleVoiceAction = (e) => {
        const btn = e.target.closest(`[data-action^="${VOICE_ACTION}"]`);
        if (!btn || btn.disabled) return;
        const action = btn.getAttribute('data-action').slice(VOICE_ACTION.length);
        if (action === 'stop' || action === 'cancel') {
            recordingCancelled = action === 'cancel';
            if (recorder && recorder.state === 'recording') recorder.stop();
        } else if (action === 'play') {
            toggleVoicePlayback();
        } else if (action === 'delete') {
            discardVoice();
        } else if (action === 'send') {
            sendVoiceMessage();
        }
    };

    // Show the play or the pause icon and label on the play button.
    const setVoicePlaying = (playing) => {
        const S = cfg.strings || {};
        const btn = g('jedomi-voice-bar')?.querySelector(`[data-action="${VOICE_ACTION}play"]`);
        if (!btn) return;
        const label = (playing ? S.voice_pause : S.voice_play) || '';
        btn.classList.toggle('jedomi-voice-playing', playing);
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', label);
    };

    const toggleVoicePlayback = () => {
        if (!voice) return;
        if (voice.audio.paused) {
            voice.audio.play()
                .then(() => setVoicePlaying(true))
                .catch(error => Log.error('Jedomi: the voice message could not be played', error));
        } else {
            voice.audio.pause();
        }
    };

    // Move the progress bar and show the played time, or the duration when stopped.
    const updateVoiceProgress = () => {
        if (!voice) return;
        const {audio, seconds} = voice;
        const progress = voiceRegion('voice-progress');
        if (progress) progress.style.width = `${seconds ? Math.min(100, audio.currentTime / seconds * 100) : 0}%`;
        const time = voiceRegion('voice-time');
        if (time) time.textContent = formatDuration(audio.currentTime > 0 ? audio.currentTime : seconds);
    };

    // Delete the recording: nothing is uploaded nor billed, and the input row comes back to record again.
    const discardVoice = () => {
        if (voice) {
            voice.audio.pause();
            URL.revokeObjectURL(voice.url);
            voice = null;
        }
        hideVoiceBar();
    };

    // Transcribe the recording and send its text as the user message. On an error the preview stays,
    // so the user can try again or delete it.
    const sendVoiceMessage = async () => {
        if (!voice || sending) return;
        const S = cfg.strings || {};
        const current = voice;
        const setBusy = (busy) => {
            g('jedomi-voice-bar')?.querySelectorAll('button').forEach(btn => { btn.disabled = busy; });
            voiceRegion('voice-status')?.classList.toggle('jedomi-hidden', !busy);
        };

        current.audio.pause();
        sending = true;
        setBusy(true);
        let text = '';
        try {
            const extension = current.mime.includes('ogg') ? 'ogg' : (current.mime.includes('mp4') ? 'm4a' : 'webm');
            const file = new File([current.blob], `recording.${extension}`, {type: current.mime});
            const data = await uploadForText(cfg.extract_file_url, file);
            text = (data.text || '').trim();
            if (!text) {
                throw new Error(S.audio_error_unreadable || '');
            }
        } catch (err) {
            addMessage('assistant', `⚠️ ${err.message}`);
            return;
        } finally {
            sending = false;
            if (voice === current) setBusy(false);
        }

        discardVoice();
        await runChat((S.voice_message || '{$a}').replace('{$a}', () => text), text);
    };

    // ── Voice conversation ─────────────────────────────────────────────────
    // A conversation by voice, like the voice mode of other assistants: the voice view replaces the input row,
    // the microphone stays open and the end of what the user says is detected by its silence (Web Audio). The
    // recording is transcribed by the audio model, as a voice message, and sent with voice_mode, so the answer is
    // short and fit to be spoken. The answer is read aloud through speech.php: a short first chunk, then the next
    // sentences grouped in longer chunks, each requested while the one before plays, and the full text stays in the
    // chat. Speaking while Jedomi speaks interrupts it.
    // An action that changes data still waits for the confirmation card: Jedomi asks to confirm it on screen and
    // stops listening until it is resolved.
    // Level (RMS, 0 to 1) above which the microphone hears speech.
    const VOICE_MODE_THRESHOLD = 0.035;
    // Lowest level needed to interrupt Jedomi while it speaks, so its own voice coming back through the microphone
    // does not. The real threshold also follows the background noise measured while listening (see bargeInLevel).
    const VOICE_MODE_BARGE_IN_MIN = 0.05;
    // How many times the background noise the user must speak over Jedomi (speaking) or while it thinks.
    const VOICE_MODE_BARGE_IN_NOISE_SPEAKING = 4;
    const VOICE_MODE_BARGE_IN_NOISE_THINKING = 3;
    // Time the user must keep speaking to interrupt Jedomi, in milliseconds.
    const VOICE_MODE_BARGE_IN_MS = 250;
    // Silence after speech that ends what the user says, in milliseconds.
    const VOICE_MODE_SILENCE_MS = 1200;
    // Least time an utterance must be heard above the speech level (see speechLevel) to be transcribed, in milliseconds.
    // Shorter sounds (a cough, a click, a door, a short background noise) are ignored and never sent, so never charged.
    const VOICE_MODE_MIN_SPEECH_MS = 500;
    // How many times the background noise measured while listening the speech must be, to count as speech.
    const VOICE_MODE_SPEECH_NOISE = 3;
    // Shortest voice message that is sent, in milliseconds; a shorter recording is discarded with a hint.
    const VOICE_MESSAGE_MIN_MS = 500;
    // Longest voice message, in milliseconds: the recording stops there with a hint, and can still be played and sent.
    const VOICE_MESSAGE_MAX_MS = 300000;
    // Longest utterance of the voice conversation, in milliseconds. A sound that never stops (background voices, a
    // television) would keep the utterance open: it ends here and is sent when speech was heard, or dropped otherwise.
    const VOICE_MODE_MAX_UTTERANCE_MS = 30000;
    // The voice conversation ends after this time listening without speech, in milliseconds.
    const VOICE_MODE_IDLE_MS = 60000;
    // How often the microphone level is measured, in milliseconds.
    const VOICE_MODE_TICK_MS = 50;
    // Longest text sent to speech.php at once, in characters (the server accepts up to 1000).
    const VOICE_MODE_MAX_SENTENCE = 900;
    /**
     * Longest and shortest first chunk of an answer, in characters. Each chunk is one request to speech.php (and one
     * charge). The first chunk groups whole sentences until it reaches the minimum or the answer ends, so a short
     * answer is read with one request. A first sentence longer than the maximum is cut at a clause or a space, so
     * Jedomi does not wait for the audio of a long text before it starts speaking.
     *
     * @type {number}
     */
    const VOICE_MODE_FIRST_CHUNK_MAX = 160;
    const VOICE_MODE_FIRST_CHUNK_MIN = 80;
    /**
     * Characters a chunk read aloud after the first one may reach by grouping whole sentences. A sentence is never
     * split to fit: a longer one goes alone (cut only above VOICE_MODE_MAX_SENTENCE). The group is sent when the
     * chunk before it starts playing (its audio is ready, so the next one is prepared while it is heard), when it
     * reaches this size, when the answer ends, before a notice of Jedomi, or at once when nothing is being read or
     * waiting to be read, so Jedomi never goes silent.
     *
     * @type {number}
     */
    const VOICE_MODE_GROUP_TARGET = 400;

    // An element of the voice view by its data-region.
    const voiceModeRegion = (name) => g('jedomi-voice-mode')?.querySelector(`[data-region="local_jedomi/sidebar/${name}"]`);

    // Show the state of the voice conversation: listening, thinking, speaking or paused (waiting for a confirmation).
    const setVoiceModeState = (state) => {
        if (!voiceMode) return;
        const S = cfg.strings || {};
        voiceMode.state = state;
        const view = g('jedomi-voice-mode');
        if (view) {
            ['listening', 'thinking', 'speaking', 'paused'].forEach(s => view.classList.toggle(`jedomi-voice-mode--${s}`, s === state));
        }
        const label = voiceModeRegion('voice-mode-state');
        if (label) label.textContent = S[`voice_mode_${state}`] || '';
    };

    // Disable the microphone and voice conversation buttons while the voice conversation waits for the microphone.
    const setVoiceStartButtonsDisabled = (disabled) => {
        ['jedomi-voice-btn', 'jedomi-voice-mode-btn'].forEach(id => {
            const button = g(id);
            if (button) button.disabled = disabled;
        });
    };

    /**
     * Why the voice conversation cannot start now: a message is being sent, a voice message is being recorded or a
     * voice message waits to be sent; empty when it can start.
     *
     * @returns {string} sending, recording, pending or an empty string.
     */
    const voiceModeBusyReason = () => {
        if (sending) return 'sending';
        if (recorder) return 'recording';
        if (voice) return 'pending';
        return '';
    };

    // Start the voice conversation. It must run from a click: browsers only let a page play audio after one.
    const startVoiceMode = async () => {
        const S = cfg.strings || {};
        if (voiceMode || voiceModeStarting || !cfg.speech_url) return;
        // Busy: the button never does nothing silently, the user is told why the conversation does not start.
        const busy = voiceModeBusyReason();
        if (busy) {
            addMessage('assistant', `⚠️ ${S[`voice_mode_busy_${busy}`] || ''}`);
            return;
        }
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined' || !AudioContextClass) {
            addMessage('assistant', `⚠️ ${S.mic_error || ''}`);
            return;
        }
        // Marked before waiting for the microphone permission: another click is ignored meanwhile, and ending the
        // conversation (its button, Esc or closing the panel) during the prompt cancels it.
        const starting = {cancelled: false};
        voiceModeStarting = starting;
        setVoiceStartButtonsDisabled(true);
        // Created and resumed during the click, so the answers can be played later.
        const ctx = new AudioContextClass();
        ctx.resume().catch(() => null);

        let stream = null;
        try {
            stream = await navigator.mediaDevices.getUserMedia(
                {audio: {echoCancellation: true, noiseSuppression: true, autoGainControl: true}});
        } catch (err) {
            stream = null;
        }
        if (voiceModeStarting === starting) {
            voiceModeStarting = null;
            setVoiceStartButtonsDisabled(false);
        }
        if (starting.cancelled || !stream || !g('jedomi-sidebar')?.classList.contains('jedomi-open')) {
            // Cancelled, refused or the panel was closed meanwhile: the microphone is released at once.
            stream?.getTracks().forEach(track => track.stop());
            ctx.close().catch(() => null);
            if (!stream && !starting.cancelled) addMessage('assistant', `⚠️ ${S.mic_error || ''}`);
            return;
        }

        const analyser = ctx.createAnalyser();
        analyser.fftSize = 1024;
        ctx.createMediaStreamSource(stream).connect(analyser);
        voiceMode = {
            stream, ctx, analyser,
            samples: new Float32Array(analyser.fftSize),
            state: 'listening',
            // Recording of the current utterance and whether it is sent to transcription when it stops.
            recorder: null, keepRecording: false,
            // Speech detection: whether speech was heard, when it started, the last time it was heard, how long it
            // was heard above the speech level, the time of the last measure, and since when the user speaks over Jedomi.
            heard: false, speechStart: 0, lastVoice: 0, voicedMs: 0, lastTick: 0, bargeStart: 0,
            // Each utterance is a turn; an interruption or the end of the conversation drops older work.
            turn: 0,
            // Chunks waiting to be spoken ({text, notice, controller, audio}), the one playing and its audio source,
            // and the sentences grouped for the next chunk (see VOICE_MODE_GROUP_TARGET).
            queue: [], current: null, source: null, group: '',
            // Sentence splitting of the answer being received.
            pendingText: '', inCodeBlock: false, firstQueued: false, display: null,
            // Background noise while listening, the controller of the requests of the turn, and whether the question
            // of the turn already went to the AI (only then can thinking be interrupted).
            noise: 0, requestController: null, chatStarted: false,
            answerDone: false, pendingAction: false, speechFailed: false,
            monitor: null, idleTimer: null,
        };

        const view = g('jedomi-voice-mode');
        view?.classList.remove('jedomi-hidden');
        g('jedomi-input')?.closest('.jedomi-input-row')?.classList.add('jedomi-hidden');
        view?.querySelector('[data-action="local_jedomi/sidebar/voice-mode-exit"]')?.focus();

        voiceMode.monitor = setInterval(monitorVoiceMode, VOICE_MODE_TICK_MS);
        startVoiceListening();
    };

    // End the voice conversation: stop speaking and listening, release the microphone and give the input row back.
    const exitVoiceMode = () => {
        if (voiceModeStarting) {
            // Still waiting for the microphone permission: the stream is released when it arrives.
            voiceModeStarting.cancelled = true;
            voiceModeStarting = null;
            setVoiceStartButtonsDisabled(false);
        }
        if (!voiceMode) return;
        const current = voiceMode;
        // A notice read aloud by the browser stops too.
        window.speechSynthesis?.cancel();
        // A transcription in progress is cancelled, so what was said after leaving is not transcribed (and billed).
        // An answer the AI is already writing is kept: it is shown in the chat.
        if (!current.chatStarted) current.requestController?.abort();
        current.requestController = null;
        stopVoiceSpeaking();
        clearInterval(current.monitor);
        clearTimeout(current.idleTimer);
        current.keepRecording = false;
        if (current.recorder && current.recorder.state !== 'inactive') current.recorder.stop();
        current.stream.getTracks().forEach(track => track.stop());
        current.ctx.close().catch(() => null);
        voiceMode = null;

        const view = g('jedomi-voice-mode');
        view?.classList.add('jedomi-hidden');
        const input = g('jedomi-input');
        input?.closest('.jedomi-input-row')?.classList.remove('jedomi-hidden');
        g('jedomi-voice-mode-btn')?.focus();
    };

    // Listen to the user: a new recording starts at once, so the beginning of what is said is kept.
    const startVoiceListening = () => {
        if (!voiceMode) return;
        const current = voiceMode;
        if (current.recorder && current.recorder.state !== 'inactive') {
            current.keepRecording = false;
            current.recorder.stop();
        }
        const type = RECORDING_TYPES.find(t => MediaRecorder.isTypeSupported(t)) || '';
        const rec = new MediaRecorder(current.stream, type ? {mimeType: type} : undefined);
        const chunks = [];
        rec.addEventListener('dataavailable', (e) => {
            if (e.data && e.data.size) chunks.push(e.data);
        });
        rec.addEventListener('stop', () => {
            if (voiceMode !== current || current.recorder !== rec || !current.keepRecording) return;
            current.keepRecording = false;
            const mime = rec.mimeType || type || 'audio/webm';
            sendVoiceUtterance(new Blob(chunks, {type: mime}), mime);
        });
        current.recorder = rec;
        current.keepRecording = false;
        current.heard = false;
        current.voicedMs = 0;
        current.bargeStart = 0;
        // No question of this new turn went to the AI yet: thinking cannot be interrupted until it does.
        current.chatStarted = false;
        rec.start();
        setVoiceModeState('listening');
        resetVoiceIdleTimer();
    };

    // End the voice conversation after a while listening without speech.
    const resetVoiceIdleTimer = () => {
        if (!voiceMode) return;
        clearTimeout(voiceMode.idleTimer);
        voiceMode.idleTimer = setTimeout(() => {
            if (voiceMode?.state !== 'listening' || voiceMode.heard) return;
            // The exit is announced on screen and aloud with the voice of the browser (free), then the conversation
            // ends. The recording stops first, so the notice is not transcribed.
            const current = voiceMode;
            const notice = (cfg.strings || {}).voice_mode_idle_exit || '';
            current.keepRecording = false;
            if (current.recorder && current.recorder.state !== 'inactive') current.recorder.stop();
            addMessage('assistant', `ℹ️ ${notice}`);
            speakVoiceNotice(notice, () => {
                // Speaking over the notice keeps the conversation: it is listening again.
                if (voiceMode === current && current.state === 'speaking') exitVoiceMode();
            });
        }, VOICE_MODE_IDLE_MS);
    };

    // Measure the microphone: detect the end of what the user says, or the user speaking over Jedomi.
    const monitorVoiceMode = () => {
        if (!voiceMode) return;
        const current = voiceMode;
        current.analyser.getFloatTimeDomainData(current.samples);
        let sum = 0;
        for (const sample of current.samples) sum += sample * sample;
        const level = Math.sqrt(sum / current.samples.length);
        const meter = voiceModeRegion('voice-mode-level');
        if (meter) meter.style.transform = `scale(${1 + Math.min(level * 8, 0.8)})`;
        const now = Date.now();
        // Time since the last measure; a page in the background measures less often, so it is capped.
        const elapsed = current.lastTick ? Math.min(now - current.lastTick, VOICE_MODE_TICK_MS * 4) : VOICE_MODE_TICK_MS;
        current.lastTick = now;

        if (current.state === 'listening') {
            if (!current.heard && level <= VOICE_MODE_THRESHOLD) {
                // Background noise while nobody speaks, kept as a slow moving average.
                current.noise = current.noise ? current.noise * 0.95 + level * 0.05 : level;
            }
            if (level > VOICE_MODE_THRESHOLD) {
                if (!current.heard) {
                    current.heard = true;
                    current.speechStart = now;
                    current.voicedMs = 0;
                    clearTimeout(current.idleTimer);
                }
                current.lastVoice = now;
                if (level > speechLevel(current)) current.voicedMs += elapsed;
            }
            if (current.heard && now - current.speechStart >= VOICE_MODE_MAX_UTTERANCE_MS) {
                if (current.voicedMs >= VOICE_MODE_MIN_SPEECH_MS) {
                    // The longest utterance: what was said is sent, and a sound that never stops cannot keep it open.
                    setVoiceModeState('thinking');
                    current.keepRecording = true;
                    current.recorder?.stop();
                } else {
                    // Only noise for the whole utterance: it is dropped with a new recording, and nothing is charged.
                    startVoiceListening();
                }
            } else if (level <= VOICE_MODE_THRESHOLD && current.heard && now - current.lastVoice > VOICE_MODE_SILENCE_MS) {
                if (current.voicedMs >= VOICE_MODE_MIN_SPEECH_MS) {
                    // The user finished speaking: the recording is sent to transcription when it stops.
                    setVoiceModeState('thinking');
                    current.keepRecording = true;
                    current.recorder?.stop();
                } else {
                    // Only a short noise: keep listening, and nothing is transcribed.
                    current.heard = false;
                    current.voicedMs = 0;
                    resetVoiceIdleTimer();
                }
            }
        } else if (!current.pendingAction
                && (current.state === 'speaking' || (current.state === 'thinking' && current.chatStarted))) {
            // While the recording is still being transcribed it is not interrupted, so what was said is not lost.
            // Nor is an answer that holds an action: the conversation must wait for its confirmation on screen.
            if (level > bargeInLevel(current)) {
                current.bargeStart = current.bargeStart || now;
                if (now - current.bargeStart >= VOICE_MODE_BARGE_IN_MS) {
                    // The user speaks while Jedomi thinks or speaks: drop that answer and listen to the new words.
                    interruptVoiceTurn();
                }
            } else {
                current.bargeStart = 0;
            }
        }
    };

    // Level that counts as speech while listening: above the threshold and well above the background noise, so a noisy
    // room does not turn its noise into utterances.
    const speechLevel = (current) => Math.max(VOICE_MODE_THRESHOLD, (current.noise || 0) * VOICE_MODE_SPEECH_NOISE);

    // Level the user must reach to interrupt Jedomi: above the background noise, and higher while Jedomi speaks so its
    // own voice coming back through the microphone does not interrupt it.
    const bargeInLevel = (current) => {
        const noise = current.noise || 0;
        if (current.state === 'speaking') {
            return Math.max(VOICE_MODE_BARGE_IN_MIN, noise * VOICE_MODE_BARGE_IN_NOISE_SPEAKING);
        }
        return Math.max(VOICE_MODE_THRESHOLD, noise * VOICE_MODE_BARGE_IN_NOISE_THINKING);
    };

    // Drop the answer in progress (transcription, AI request and speech) and listen again. The question already sent
    // stays in the conversation, so the next one is answered with it in mind.
    const interruptVoiceTurn = () => {
        const current = voiceMode;
        if (!current || current.pendingAction) return;
        current.requestController?.abort();
        current.requestController = null;
        stopVoiceSpeaking();
        startVoiceListening();
    };

    // Wait until no other message is being sent or answered.
    const waitUntilNotSending = async () => {
        while (sending) {
            await new Promise(resolve => setTimeout(resolve, 100));
        }
    };

    // Codes of errors that mean the session or the signed permissions of the page are no longer valid.
    const VOICE_SESSION_ERRORS = ['invalidsesskey', 'requireloginerror', 'invalid_sesskey', 'invalid_spt'];

    // Whether a request of the voice conversation was refused for good (expired session or permissions, or the voice
    // conversation or the chat no longer allowed): listening again would transcribe, and bill, every new utterance
    // only to fail again.
    const isVoiceRefusal = (err) => err?.message === '__SESSION_EXPIRED__' || err?.status === 403
        || VOICE_SESSION_ERRORS.includes(err?.code);

    // Language string of each reason speech.php gives for not reading a text aloud.
    const VOICE_SPEECH_ERRORS = {
        'voice_disabled': 'speech_error_disabled',
        'speech_empty': 'speech_error_empty',
        'speech_rate_limited': 'speech_error_rate_limited',
        'speech_too_long': 'speech_error_too_long',
    };

    /**
     * Reason a sentence could not be read aloud: the message of speech.php (per minute limit, text too long, no
     * credit...), the string of its code when the answer had no message, or the generic notice for a network failure.
     *
     * @param {Error} err Failure of the request.
     * @returns {string}
     */
    const voiceSpeechErrorReason = (err) => {
        const S = cfg.strings || {};
        if (err?.reason) return err.reason;
        const key = VOICE_SPEECH_ERRORS[err?.code];
        return (key && S[key]) || S.voice_mode_speech_error || '';
    };

    // Message of a refusal: the session expired message, or the message of the server for any other refusal.
    const voiceRefusalMessage = (err) => {
        const S = cfg.strings || {};
        if (err?.message && err.message !== '__SESSION_EXPIRED__' && !VOICE_SESSION_ERRORS.includes(err.code)) {
            return `⚠️ ${err.message}`;
        }
        return `⚠️ **${S.session_expired_title || ''}**\n\n${S.session_expired_hint || ''}`;
    };

    // Longest time a notice read aloud by the browser may take before listening again, in milliseconds.
    const VOICE_NOTICE_MAX_MS = 8000;

    // Say a short notice aloud with the voice of the browser, which costs nothing and works even when the speech
    // service is not available (no credits, service down), then call done. The microphone is not listening meanwhile,
    // so the notice is not transcribed.
    const speakVoiceNotice = (notice, done) => {
        const synth = window.speechSynthesis;
        const text = speakableText(notice);
        if (!synth || typeof window.SpeechSynthesisUtterance !== 'function' || !text) {
            done();
            return;
        }
        let finished = false;
        let guard = null;
        const finish = () => {
            if (finished) return;
            finished = true;
            clearTimeout(guard);
            done();
        };
        guard = setTimeout(finish, VOICE_NOTICE_MAX_MS);
        const utterance = new window.SpeechSynthesisUtterance(text);
        utterance.lang = document.documentElement.lang || '';
        utterance.addEventListener('end', finish);
        utterance.addEventListener('error', finish);
        setVoiceModeState('speaking');
        synth.cancel();
        synth.speak(utterance);
    };

    // Transcribe what the user said and send it as a message of the voice conversation.
    const sendVoiceUtterance = async (blob, mime) => {
        if (!voiceMode || !blob.size) {
            startVoiceListening();
            return;
        }
        const S = cfg.strings || {};
        const current = voiceMode;
        const turn = ++current.turn;
        // One controller cancels the transcription and the AI request of this turn when the user interrupts.
        const controller = new AbortController();
        current.requestController = controller;
        current.chatStarted = false;
        let text = '';
        // Message of the server when the transcription fails (service unavailable, no credits, limit reached...):
        // it is already translated and says what happened, so it is shown instead of "could not understand".
        let failure = '';
        let refusal = null;
        try {
            const extension = mime.includes('ogg') ? 'ogg' : (mime.includes('mp4') ? 'm4a' : 'webm');
            const data = await uploadForText(cfg.extract_file_url, new File([blob], `recording.${extension}`, {type: mime}),
                {}, controller.signal);
            text = (data.text || '').trim();
        } catch (err) {
            if (err?.name === 'AbortError') return;
            Log.debug('Jedomi: the voice conversation could not transcribe the audio', err);
            failure = (err?.message || '').trim();
            refusal = isVoiceRefusal(err) ? err : null;
        }
        if (voiceMode !== current || current.turn !== turn) return;
        if (refusal) {
            addMessage('assistant', voiceRefusalMessage(refusal));
            exitVoiceMode();
            return;
        }
        if (!text) {
            const notice = failure || S.voice_mode_transcription_error || '';
            // The same notice right after itself is not repeated, so noise does not fill the chat. A new notice is
            // also said aloud, as the user may not be looking at the screen.
            const msgs = g('jedomi-messages');
            const lastMessage = msgs?.querySelector('.jedomi-message:last-of-type');
            if (lastMessage && lastMessage.dataset.voiceNotice === notice) {
                startVoiceListening();
                return;
            }
            const wrap = addMessage('assistant', `⚠️ ${notice}`);
            if (wrap) wrap.dataset.voiceNotice = notice;
            speakVoiceNotice(notice, () => {
                if (voiceMode === current && current.turn === turn) startVoiceListening();
            });
            return;
        }

        await waitUntilNotSending();
        if (voiceMode !== current || current.turn !== turn) return;
        current.pendingText = '';
        current.group = '';
        current.inCodeBlock = false;
        current.firstQueued = false;
        current.display = null;
        current.answerDone = false;
        current.pendingAction = false;
        current.speechFailed = false;
        const isTurn = () => voiceMode === current && current.turn === turn;
        current.chatStarted = true;
        await runChat((S.voice_message || '{$a}').replace('{$a}', () => text), text, null, {
            signal: controller.signal,
            onDisplay: (display) => {
                if (isTurn()) current.display = display;
            },
            onToken: (token) => {
                if (!isTurn()) return;
                current.pendingText += token;
                speakCompleteSentences(false);
            },
            onPendingAction: () => {
                if (isTurn()) current.pendingAction = true;
            },
            onDone: () => {
                if (!isTurn()) return;
                speakCompleteSentences(true);
                if (current.pendingAction) queueVoiceSentence(S.voice_mode_confirm_notice || '', true);
                current.answerDone = true;
                finishVoiceTurnIfDone();
            },
            onError: (err) => {
                if (!isTurn()) return;
                // The error is shown in the chat. After a refusal the conversation ends; else listen again.
                if (isVoiceRefusal(err)) {
                    exitVoiceMode();
                    return;
                }
                stopVoiceSpeaking();
                startVoiceListening();
            },
        });
    };

    // Text fit to be spoken: without markdown marks, links (only their text) or emojis.
    const speakableText = (line) => line
        .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/https?:\/\/\S+/g, '')
        .replace(/^\s{0,3}(#{1,6}\s+|>\s*|[-*+]\s+|\d+[.)]\s+)/, '')
        .replace(/[*_`~#|]/g, '')
        .replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu, '')
        .replace(/\s+/g, ' ')
        .trim();

    // Queue the sentences of a line that is complete; code blocks and table rows are not read aloud.
    const speakLine = (line) => {
        const current = voiceMode;
        if (/^\s*(```|~~~)/.test(line)) {
            current.inCodeBlock = !current.inCodeBlock;
            return;
        }
        if (current.inCodeBlock || /^\s*\|/.test(line)) return;
        // A stop followed by a space ends a sentence, so decimals such as 15.38 are kept whole.
        speakableText(line).replace(/([.!?…]+["')\]]*)\s+/g, '$1\n').split('\n')
            .forEach(sentence => queueVoiceSentence(sentence));
    };

    // Split the answer received so far into sentences and queue the complete ones. final flushes the rest.
    const speakCompleteSentences = (final) => {
        const current = voiceMode;
        if (!current) return;
        let text = current.pendingText;
        let newline = text.indexOf('\n');
        while (newline !== -1) {
            speakLine(text.slice(0, newline));
            text = text.slice(newline + 1);
            newline = text.indexOf('\n');
        }
        if (final) {
            speakLine(text);
            text = '';
            // The answer ended: the sentences still grouped are read now.
            flushVoiceGroup();
            playNextVoiceSentence();
        } else if (!current.inCodeBlock && !/^\s*(\||`|~)/.test(text)) {
            // Sentences of the line that already ended (a stop followed by a space) can be spoken before the line ends.
            const match = text.match(/^([\s\S]*[.!?…]["')\]]*)\s+(\S[\s\S]*)?$/);
            if (match) {
                speakLine(match[1]);
                text = match[2] || '';
            }
        }
        current.pendingText = text;
    };

    // Where to end the first chunk when its text is longer than VOICE_MODE_FIRST_CHUNK_MAX: after the first comma,
    // semicolon or colon followed by a space within the limits (so decimals such as 15,38 are kept whole), else at the
    // last space within the limits. Returns 0 when the text has no suitable place.
    const firstChunkEnd = (text) => {
        const clause = /[,;:](?=\s)/g;
        let match;
        while ((match = clause.exec(text)) !== null) {
            const end = match.index + 1;
            if (end > VOICE_MODE_FIRST_CHUNK_MAX) break;
            if (end >= VOICE_MODE_FIRST_CHUNK_MIN) return end;
        }
        const space = text.lastIndexOf(' ', VOICE_MODE_FIRST_CHUNK_MAX);
        return space >= VOICE_MODE_FIRST_CHUNK_MIN ? space : 0;
    };

    // Queue a sentence to be read aloud, grouped with the others of the answer (see VOICE_MODE_FIRST_CHUNK_MAX and
    // VOICE_MODE_GROUP_TARGET); long ones are cut at a space. notice marks a text of Jedomi that is not part of the
    // answer, so it is spoken but not shown.
    const queueVoiceSentence = (sentence, notice = false) => {
        const current = voiceMode;
        if (!current) return;
        let text = (sentence || '').trim();
        if (!notice && !current.firstQueued && /[\p{L}\p{N}]/u.test(text)) {
            // The first chunk: a sentence that makes it longer than the maximum is cut, or starts the next group.
            const joined = current.group ? `${current.group} ${text}` : text;
            if (joined.length > VOICE_MODE_FIRST_CHUNK_MAX) {
                const end = firstChunkEnd(joined);
                if (end > 0) {
                    current.group = joined.slice(0, end).trim();
                    text = joined.slice(end).trim();
                }
                flushVoiceGroup();
            }
        }
        while (text.length > VOICE_MODE_MAX_SENTENCE) {
            const cut = text.lastIndexOf(' ', VOICE_MODE_MAX_SENTENCE);
            const at = cut > 0 ? cut : VOICE_MODE_MAX_SENTENCE;
            queueVoiceSentence(text.slice(0, at), notice);
            text = text.slice(at).trim();
        }
        // Text with no letter or number (a lone symbol) is not read.
        if (!/[\p{L}\p{N}]/u.test(text)) return;
        if (notice) {
            // A notice of Jedomi is read after the sentences of the answer grouped before it.
            flushVoiceGroup();
            current.queue.push({text, notice, controller: new AbortController(), audio: null});
        } else {
            // Whole sentences are grouped up to the target; one that does not fit starts the next group.
            if (current.group && current.group.length + 1 + text.length > VOICE_MODE_GROUP_TARGET) flushVoiceGroup();
            current.group = current.group ? `${current.group} ${text}` : text;
            const target = current.firstQueued ? VOICE_MODE_GROUP_TARGET : VOICE_MODE_FIRST_CHUNK_MIN;
            if (current.group.length >= target) flushVoiceGroup();
        }
        playNextVoiceSentence();
    };

    // Queue the sentences grouped so far as one chunk to be read aloud.
    const flushVoiceGroup = () => {
        const current = voiceMode;
        if (!current?.group) return;
        current.queue.push({text: current.group, notice: false, controller: new AbortController(), audio: null});
        current.group = '';
        current.firstQueued = true;
    };

    // Ask speech.php for the audio of a sentence and decode it.
    const fetchVoiceSentence = async (item) => {
        const current = voiceMode;
        const request = () => fetch(cfg.speech_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({text: item.text, sesskey: cfg.sesskey, spt: cfg.spt}),
            signal: item.controller.signal,
        });
        await ensureFreshPermissions();
        let res = await request();
        // Expired permissions are renewed while the Moodle session is active and the sentence is requested once more.
        if (res.status === 403) {
            const refusal = await res.clone().json().catch(() => ({}));
            if (refusal.error?.code === 'invalid_spt' && await renewPermissions()) {
                res = await request();
            }
        }
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            const error = new Error(data.error?.message || `HTTP ${res.status}`);
            error.status = res.status;
            error.code = data.error?.code || data.errorcode || '';
            error.reason = data.error?.message || '';
            throw error;
        }
        const buffer = await res.arrayBuffer();
        return current.ctx.decodeAudioData(buffer);
    };

    // Play the next chunk in order, and request the one after it while it plays.
    const playNextVoiceSentence = async () => {
        const current = voiceMode;
        if (!current) return;
        // Nothing is being read or waiting to be read after the first chunk: the grouped sentences go now, so Jedomi
        // never stays silent. While a chunk is prepared they keep growing, and go when it starts playing.
        if (current.firstQueued && !current.current && !current.queue.length) flushVoiceGroup();
        const next = current.queue[0];
        if (next && !next.audio) {
            next.audio = fetchVoiceSentence(next);
            // A failure is handled when the sentence is played.
            next.audio.catch(() => null);
        }
        if (current.current || !next) return;

        current.queue.shift();
        current.current = next;
        setVoiceModeState('speaking');
        playNextVoiceSentence();
        let decoded = null;
        try {
            decoded = await next.audio;
        } catch (err) {
            if (err?.name !== 'AbortError') {
                Log.debug('Jedomi: a sentence could not be read aloud', err);
                if (voiceMode === current && isVoiceRefusal(err)) {
                    // Refused for good: told once, and the conversation ends (the answer stays in the chat).
                    addMessage('assistant', voiceRefusalMessage(err));
                    exitVoiceMode();
                    return;
                }
                if (voiceMode === current && !current.speechFailed) {
                    // The text is already in the chat: tell it once and go on with the next sentence.
                    current.speechFailed = true;
                    addMessage('assistant', `⚠️ ${voiceSpeechErrorReason(err)}`);
                }
            }
        }
        if (voiceMode !== current || current.current !== next) return;
        // The text of the sentence appears as it starts to be heard (or at once when it could not be read aloud).
        if (!next.notice) current.display?.showSpoken(next.text);
        if (!decoded) {
            current.current = null;
            playNextVoiceSentence();
            finishVoiceTurnIfDone();
            return;
        }
        const source = current.ctx.createBufferSource();
        source.buffer = decoded;
        source.connect(current.ctx.destination);
        source.addEventListener('ended', () => {
            if (voiceMode !== current || current.source !== source) return;
            current.source = null;
            current.current = null;
            playNextVoiceSentence();
            finishVoiceTurnIfDone();
        });
        current.source = source;
        source.start();
        // The chunk is heard: the sentences grouped meanwhile go now, so the next chunk is prepared while it plays.
        flushVoiceGroup();
        playNextVoiceSentence();
    };

    // Stop speaking: the audio stops, the queue is emptied and the pending requests are cancelled.
    const stopVoiceSpeaking = () => {
        if (!voiceMode) return;
        const current = voiceMode;
        // The answer that was being spoken is shown complete.
        current.display?.finish();
        current.display = null;
        current.turn++;
        const items = current.current ? [current.current, ...current.queue] : current.queue;
        items.forEach(item => item.controller.abort());
        current.queue = [];
        current.group = '';
        current.current = null;
        current.pendingText = '';
        if (current.source) {
            const source = current.source;
            current.source = null;
            try {
                source.stop();
            } catch (e) {
                // Already stopped.
            }
        }
    };

    // After the last sentence, listen again, or wait for the confirmation card when an action is pending.
    const finishVoiceTurnIfDone = () => {
        const current = voiceMode;
        if (!current || !current.answerDone || current.current || current.queue.length || current.group) return;
        if (current.state === 'listening' || current.state === 'paused') return;
        // Everything was heard: the complete answer replaces the spoken sentences.
        current.display?.finish();
        if (current.pendingAction) {
            if (current.recorder && current.recorder.state !== 'inactive') {
                current.keepRecording = false;
                current.recorder.stop();
            }
            clearTimeout(current.idleTimer);
            setVoiceModeState('paused');
            // The microphone stays open while paused: the conversation ends if the action is not resolved in time.
            current.idleTimer = setTimeout(() => {
                if (voiceMode === current && current.state === 'paused') exitVoiceMode();
            }, VOICE_MODE_IDLE_MS);
            return;
        }
        startVoiceListening();
    };

    // The confirmation card was resolved: a paused voice conversation listens again.
    const resumeVoiceModeAfterAction = () => {
        if (voiceMode?.state !== 'paused') return;
        voiceMode.pendingAction = false;
        startVoiceListening();
    };

    // ── Confirmation of actions that change data ──────────────────────────────
    // The server holds the action and sends its id; it runs only through
    // local_jedomi_resolve_action, which checks the owner, the expiry, the role and the keyword again.
    // severity: 'critical' (red, the keyword must be typed) | 'high' (orange) | 'medium' (yellow).
    const resolveAction = async (id, confirm, keyword = '') => {
        return Ajax.call([{
            methodname: 'local_jedomi_resolve_action',
            args: {id, confirm, keyword},
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
            const keywordInput = div.querySelector('[data-region="local_jedomi/sidebar/confirm-keyword"]');
            if (confirm && isCritical) {
                const inp = keywordInput;
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
                const result = await resolveAction(Number(pending.id), confirm, confirm ? (keywordInput?.value || '') : '');
                const icon = result.status === 'executed' ? '✅ ' : (result.status === 'cancelled' ? '' : '❌ ');
                const note = isSend && result.status === 'executed' ? (CS.messages_recipients_note || '') : '';
                showDone(icon + result.message, note);
                addActionNote(pending, result.message);
            } catch (e) {
                const message = e?.message || CS.unknown_error || '';
                showDone('❌ ' + message);
                addActionNote(pending, message);
            }
            resumeVoiceModeAfterAction();
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

    // Remove every attached file, cancelling any reading in progress.
    const clearFile = () => {
        pendingFiles.forEach((attachment) => attachment.controller?.abort());
        pendingFiles = [];
        renderFileChips();
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
    // Image types the file and image model reads (the server checks them again).
    const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    // Attach the dropped files: the first readable image and up to MAX_ATTACHMENTS files. A file of a kind whose
    // processing is off, an image of another type or a second image is not attached, and the user is told why.
    const handleDroppedFiles = (files) => {
        const S = cfg.strings || {};
        const documents = [];
        let image = null;
        let extraImages = false;
        for (const file of files) {
            const extension = (file.name.split('.').pop() || '').toLowerCase();
            const isaudio = AUDIO_EXTENSIONS.includes(extension);
            const isimage = (file.type || '').startsWith('image/');
            // Only the kinds whose feature is on are accepted; the server refuses the others too.
            if (isaudio && !cfg.audio_enabled) {
                addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.audio_error_disabled || ''}`);
            } else if (!isaudio && !cfg.files_enabled) {
                addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.files_error_disabled || ''}`);
            } else if (!isaudio && !isimage && !cfg.documents_enabled) {
                // The custom AI service reads images only.
                addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.documents_error_custom || ''}`);
            } else if (isimage && !IMAGE_TYPES.includes(file.type)) {
                addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.image_error_unsupported || ''}`);
            } else if (isimage) {
                extraImages = extraImages || image !== null;
                image = image || file;
            } else {
                documents.push(file);
            }
        }
        if (image) {
            const dt = new DataTransfer();
            dt.items.add(image);
            const input = g('jedomi-image-input');
            if (input) { input.files = dt.files; input.dispatchEvent(new Event('change')); }
        }
        if (extraImages) {
            addMessage('assistant', `⚠️ ${S.image_attach_limit || ''}`);
        }
        if (documents.length) {
            attachFiles(documents);
        }
    };

    // ── Attach menu ─────────────────────────────────────────────────────────
    const toggleAttachMenu = () => {
        const menu = g('jedomi-attach-menu');
        if (!menu) return;
        menu.classList.toggle('jedomi-hidden');
        g('jedomi-attach-btn')?.setAttribute('aria-expanded', menu.classList.contains('jedomi-hidden') ? 'false' : 'true');
        if (!menu.classList.contains('jedomi-hidden')) {
            setTimeout(() => document.addEventListener('click', closeAttachOutside), 10);
        }
    };
    const closeAttachMenu = () => {
        g('jedomi-attach-menu')?.classList.add('jedomi-hidden');
        g('jedomi-attach-btn')?.setAttribute('aria-expanded', 'false');
        document.removeEventListener('click', closeAttachOutside);
    };
    const closeAttachOutside = (e) => {
        if (!e.target.closest('.jedomi-attach-wrap')) closeAttachMenu();
    };

    return { init };
});
