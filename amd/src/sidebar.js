/* eslint-disable */
/**
 * Jedomi AI Sidebar — Theme-proof JavaScript
 *
 * ESTRATEGIA DE INYECCIÓN ROBUSTA:
 *  1. Inyecta el HTML directamente en document.body (no depende del tema)
 *  2. Repara overflow:hidden en html/body que rompe position:fixed
 *  3. Escucha MutationObserver para temas con AJAX navigation
 *  4. Funciona aunque el before_footer hook sea omitido por el tema
 *  5. Previene doble inyección
 *
 * @module     local_jedomi/sidebar
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/log'], function(Log) {

    // ── SVG Icons (profesionales, no emojis) ─────────────────────────────────
    const SVG = {
        sparkle: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.107 7.107c0 .13 0 .261 0 .393a7.5 7.5 0 0 0-7.107 7.107c-.13 0-.261 0-.393 0a7.5 7.5 0 0 0-7.107-7.107c0-.132 0-.263 0-.393a7.5 7.5 0 0 0 7.107-7.107z"/><path d="M5 5.5v3M6.5 7H3.5M19 15.5v3M20.5 17H17.5"/></svg>',
        trigger: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.107 7.107c0 .13 0 .261 0 .393a7.5 7.5 0 0 0-7.107 7.107c-.13 0-.261 0-.393 0a7.5 7.5 0 0 0-7.107-7.107c0-.132 0-.263 0-.393a7.5 7.5 0 0 0 7.107-7.107z"/><path d="M5 5.5v3M6.5 7H3.5M19 15.5v3M20.5 17H17.5"/></svg>',
        newchat: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
        export:  '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        close:   '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        mic:     '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>',
        camera:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>',
        attach:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>',
        send:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
        copy:    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        thumbup: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14z"/><path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>',
        thumbdn: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3H10z"/><path d="M17 2h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/></svg>',
        edit:    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>',
        // Quick action icons
        chart:   '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        alert:   '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        ghost:   '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 10h.01M15 10h.01M12 2a8 8 0 0 0-8 8v12l3-3 2.5 2.5L12 19l2.5 2.5L17 19l3 3V10a8 8 0 0 0-8-8z"/></svg>',
        clip:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
        moon:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>',
        users:   '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        shield:  '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        task:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        star:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        help:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        lock:    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        cpu:     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/></svg>',
        plus:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    };

    // ── State ─────────────────────────────────────────────────────────────────
    let cfg          = {};
    let conversation = [];
    let pendingImage = null;
    let isRecording  = false;
    let mediaRecorder = null;
    let audioChunks  = [];
    let injected     = false;
    let sessionId    = null;
    let lastMsgId    = null;

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

    const bootstrap = () => {
        // Prevenir doble inyección
        if (injected || document.getElementById('jedomi-trigger')) {
            if (!injected) wireUp();
            return;
        }

        // 0. Inyectar CSS en <head> (el PHP no puede hacerlo tarde)
        injectCSS();

        // Inicializar session ID persistente
        sessionId = localStorage.getItem('jedomi_session_id');
        if (!sessionId) {
            sessionId = crypto.randomUUID ? crypto.randomUUID()
                : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                    const r = Math.random() * 16 | 0;
                    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                });
            localStorage.setItem('jedomi_session_id', sessionId);
        }

        // Cargar conversación desde el cache de sesión de Moodle (servidor)
        // No sessionStorage — el historial vive en el lado del servidor
        _loadConversationFromServer().then(() => {
            if (conversation.length > 0) {
                setTimeout(() => _restoreConversationUI(), 150);
            }
        });

        // 1. Reparar overflow:hidden — el problema más común con temas
        fixBodyOverflow();

        // 2. Inyectar HTML directo en body
        injectHTML();

        // 3. Conectar eventos
        wireUp();

        // 4. Escuchar cambios de navegación AJAX (temas tipo SPA)
        watchNavigation();

        // sessionStorage no necesita evento de guardado — persiste automáticamente

        injected = true;
        Log.debug('Jedomi sidebar injected successfully');



        // Reabrir automáticamente si estaba abierto antes de navegar
        if (sessionStorage.getItem('jedomi_open') === '1') {
            setTimeout(() => openSidebar(), 120);
        }
    };

    // ── Inject CSS ────────────────────────────────────────────────────────────
    // Moodle no permite $PAGE->requires->css() en before_footer (demasiado tarde).
    // El JS inyecta el <link> directamente en el <head> — funciona siempre.
    const injectCSS = () => {
        if (document.getElementById('jedomi-styles')) return;
        const wwwroot = cfg.wwwroot || (window.M?.cfg?.wwwroot) || '';
        const link    = document.createElement('link');
        link.id       = 'jedomi-styles';
        link.rel      = 'stylesheet';
        link.href     = `${wwwroot}/local/jedomi/styles.css`;
        document.head.appendChild(link);
    };

    // ── Fix Body Overflow ─────────────────────────────────────────────────────
    // Algunos temas ponen overflow:hidden en html o body, lo que rompe
    // position:fixed. Lo detectamos y aplicamos un fix quirúrgico.
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

    // ── Confirmation UI for destructive actions ───────────────────────────────
    // Called when the gateway returns a JEDOMI_PENDING_ACTION block.
    // severity: 'critical' (red, require typing) | 'high' (orange) | 'medium' (yellow)
    const buildActionConfirmUI = (pending) => {
        const severity   = pending.severity || 'medium';
        const isCritical = severity === 'critical';
        const uid        = 'jc-' + Math.random().toString(36).slice(2, 8);

        const div = document.createElement('div');
        div.className = `jedomi-action-pending jedomi-action-pending--${severity}`;
        const CS = cfg.strings || {};
        const confirmKeyword = isCritical ? (CS.confirm_btn_yes || 'Confirm').toUpperCase() : '';
        div.innerHTML = `
            <div class="jedomi-action-warning">${escHtml(pending.warning || '')}</div>
            <div class="jedomi-action-desc">${escHtml(pending.action_desc || pending.tool)}</div>
            ${isCritical ? `
            <div class="jedomi-type-confirm-wrap">
                <label>${CS.confirm_type_to_proceed || 'Type CONFIRM to proceed:'}</label>
                <input id="${uid}" type="text" class="jedomi-type-confirm"
                       placeholder="${CS.confirm_btn_yes || 'CONFIRM'}" autocomplete="off" spellcheck="false"/>
            </div>` : ''}
            <div class="jedomi-confirm-btns" style="margin-top:12px">
                <button class="jedomi-confirm-yes">✅ ${CS.confirm_btn_yes || 'Confirm'}</button>
                <button class="jedomi-confirm-no">❌ ${CS.confirm_btn_no || 'Cancel'}</button>
            </div>
            <div class="jedomi-action-result"></div>`;

        const yesBtn    = div.querySelector('.jedomi-confirm-yes');
        const noBtn     = div.querySelector('.jedomi-confirm-no');
        const resultDiv = div.querySelector('.jedomi-action-result');

        yesBtn.addEventListener('click', async () => {
            if (isCritical) {
                const inp = document.getElementById(uid);
                if (!inp || inp.value.trim().toUpperCase() !== confirmKeyword) {
                    if (inp) { inp.style.borderColor = '#ef4444'; inp.focus(); }
                    resultDiv.innerHTML = `<span style="color:#fca5a5;font-size:12px">⛔ ${CS.confirm_type_to_proceed || 'Type CONFIRM to proceed:'}</span>`;
                    return;
                }
            }

            yesBtn.disabled = noBtn.disabled = true;
            resultDiv.innerHTML = `<span style="opacity:0.7;font-size:12px">⏳ ${CS.confirm_executing || 'Executing...'}</span>`;

            try {
                const res = await fetch(`${cfg.gateway_url}/v1/execute_action`, {
                    method:  'POST',
                    headers: {'Content-Type': 'application/json',
                              'X-Jedomi-SPT': JSON.stringify(cfg.spt || {})},
                    body:    JSON.stringify({
                        tool:   pending.tool,
                        params: pending.params,
                        spt:    cfg.spt ? JSON.stringify(cfg.spt) : null,
                    }),
                });
                const data = await res.json();
                if (data.error) {
                    div.innerHTML = `<div class="jedomi-confirm-msg" style="color:#fca5a5">❌ Error: ${escHtml(String(data.error))}</div>`;
                } else {
                    div.innerHTML = `<div class="jedomi-confirm-msg">✅ ${CS.confirm_done || 'Action executed successfully.'}</div>`;
                }
            } catch (e) {
                div.innerHTML = `<div class="jedomi-confirm-msg" style="color:#fca5a5">❌ ${CS.connection_error || 'Connection error:'} ${escHtml(e.message)}</div>`;
            }
        });

        noBtn.addEventListener('click', () => {
            div.innerHTML = `<div class="jedomi-confirm-msg" style="opacity:0.7">${CS.confirm_cancelled || 'Action cancelled.'}</div>`;
        });

        return div;
    };

    // ── Inject HTML ───────────────────────────────────────────────────────────
    // No dependemos del tema para renderizar el HTML.
    // Lo construimos en JS y lo pegamos directo en body.
    const injectHTML = () => {
        const name     = cfg.user_name || 'user';
        const S        = cfg.strings || {};
        const greeting = (S.greeting || 'Hi, {name}!').replace('{name}', escHtml(name));
        const html = `
<button id="jedomi-trigger" class="jedomi-trigger" aria-label="${S.open_sidebar || 'Open Jedomi AI'}" title="Jedomi AI (Ctrl+Shift+J)">
    ${SVG.trigger}
    <span class="jedomi-trigger-label">Jedomi</span>
</button>

<div id="jedomi-sidebar" class="jedomi-sidebar jedomi-closed"
     role="dialog" aria-modal="true" aria-label="Jedomi AI">
    <div class="jedomi-header">
        <div class="jedomi-header-info">
            <div class="jedomi-logo">${SVG.sparkle}</div>
            <div>
                <span id="jedomi-context-label" class="jedomi-context-badge"></span>
            </div>
        </div>
        <div class="jedomi-header-actions">
            <button id="jedomi-export" class="jedomi-btn-icon" title="${S.export_conversation || 'Export conversation'}">${SVG.export}</button>

            <button id="jedomi-expand-half" class="jedomi-btn-icon jedomi-expand-btn" title="${S.half_width || 'Half width'}" data-mode="half">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="8" height="18" rx="1"/><rect x="13" y="3" width="8" height="18" rx="1" opacity=".4"/></svg>
            </button>
            <button id="jedomi-expand-full" class="jedomi-btn-icon jedomi-expand-btn" title="${S.full_width || 'Full width'}" data-mode="full">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
            </button>
            <button id="jedomi-clear" class="jedomi-btn-icon" title="${S.new_conversation || 'New conversation'}" style="font-size:16px;font-weight:700;line-height:1;color:var(--j-muted)">+</button>
            <button id="jedomi-close" class="jedomi-btn-icon" title="${S.close || 'Close (Esc)'}">${SVG.close}</button>
        </div>
    </div>

    <div id="jedomi-messages" class="jedomi-messages">
        <div class="jedomi-message jedomi-message-assistant">
            <div class="jedomi-bubble">
                ${greeting}<br>
                ${S.hello || "I'm Jedomi, your Moodle AI assistant. Ask me about courses, users, grades or settings."}
            </div>
        </div>
    </div>

    <div id="jedomi-thinking-wrapper" class="jedomi-thinking-wrapper jedomi-hidden">
        <button id="jedomi-thinking-toggle-btn" class="jedomi-thinking-bar" aria-label="Toggle thinking">
            <span class="jedomi-thinking-dot"></span>
            <span class="jedomi-thinking-lbl">${S.thinking || 'Thinking...'}</span>
            <svg class="jedomi-thinking-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div id="jedomi-thinking" class="jedomi-thinking">
            <span></span><span></span><span></span>
        </div>
    </div>

    <div id="jedomi-quick-actions" class="jedomi-quick-actions"></div>

    <div class="jedomi-input-area">
        <div class="jedomi-input-row" onclick="document.getElementById('jedomi-input')?.focus()">
            <textarea id="jedomi-input" class="jedomi-input"
                placeholder="${S.placeholder || 'Ask Jedomi about courses, users, grades...'}"
                rows="1" aria-label="${S.message_input || 'Message Jedomi'}"></textarea>
            <div class="jedomi-input-actions">
                <button id="jedomi-voice-btn" class="jedomi-input-icon" title="${S.voice_input || 'Voice input'}">${SVG.mic}</button>
                <div class="jedomi-attach-wrap">
                    <button id="jedomi-attach-btn" class="jedomi-input-icon" title="${S.attach || 'Attach'}">${SVG.plus}</button>
                    <div id="jedomi-attach-menu" class="jedomi-attach-menu jedomi-hidden">
                        <button class="jedomi-attach-option" data-type="image" title="${S.attach_image || 'Attach image'}">${SVG.camera} ${S.image || 'Image'}</button>
                        <button class="jedomi-attach-option" data-type="file" title="${S.attach_file || 'Attach file'}">${SVG.attach} ${S.file || 'File'}</button>
                    </div>
                </div>
                <button id="jedomi-send" class="jedomi-send-btn" title="${S.send || 'Send'}">${SVG.send}</button>
            </div>
        </div>
        <input type="file" id="jedomi-image-input" accept="image/*" class="jedomi-hidden" aria-hidden="true">
        <input type="file" id="jedomi-file-input" accept=".pdf,.csv,.txt,.md" class="jedomi-hidden" aria-hidden="true">
        <div id="jedomi-image-preview" class="jedomi-image-preview jedomi-hidden">
            <img id="jedomi-preview-img" src="" alt="${S.attached_image || 'Attached image'}">
            <span>${S.attached_image || 'Attached image'}</span>
            <button id="jedomi-remove-image" class="jedomi-input-icon">${SVG.close}</button>
        </div>
    </div>
    <div class="jedomi-footer">
        <span class="jedomi-disclaimer">${S.ai_disclaimer || 'Jedomi is an AI and can make mistakes.'}</span>
    </div>
</div>

<div id="jedomi-overlay" class="jedomi-overlay jedomi-hidden"></div>
<div id="jedomi-dropzone" class="jedomi-dropzone jedomi-hidden">
    <div class="jedomi-dropzone-inner">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <span class="jedomi-dropzone-text">${S.drop_files_here || 'Drop files here'}</span>
    </div>
</div>`;

        document.body.insertAdjacentHTML('beforeend', html);
        updateContextLabel();
        loadQuickActions();
        startGlowCycle();
    };

    // ── Glow pulse cada 30 segundos ───────────────────────────────────────────
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
        initDropZone();
        on('jedomi-close',        'click',  closeSidebar);
        on('jedomi-clear',        'click',  clearChat);
        on('jedomi-export',       'click',  exportConversation);
        on('jedomi-expand-half',  'click',  () => setExpandMode(expandMode === 'half' ? 'normal' : 'half'));
        on('jedomi-expand-full',  'click',  () => setExpandMode(expandMode === 'full' ? 'normal' : 'full'));
        on('jedomi-thinking-toggle-btn', 'click', () => {
            g('jedomi-thinking-wrapper')?.classList.toggle('jedomi-thinking-expanded');
        });

        // ── CSV download via event delegation (evita CSP de Moodle) ──────────
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
        on('jedomi-voice-btn',    'click',  toggleVoice);
        on('jedomi-remove-image', 'click',  clearImage);
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
        on('jedomi-file-input',   'change', handleFileSelect);
        on('jedomi-image-input',  'change', handleImageSelect);

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
            // Escape → cerrar sidebar
            if (e.key === 'Escape' && g('jedomi-sidebar')?.classList.contains('jedomi-open')) {
                closeSidebar();
                return;
            }
            // Ctrl+Shift+J → abrir/cerrar Jedomi
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'J') {
                e.preventDefault();
                const isOpen = g('jedomi-sidebar')?.classList.contains('jedomi-open');
                isOpen ? closeSidebar() : openSidebar();
            }
            // Ctrl+Shift+N → nueva conversación
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'N') {
                e.preventDefault();
                clearChat();
                openSidebar();
            }
        });
    };

    // ── Watch AJAX Navigation ─────────────────────────────────────────────────
    // Temas que cambian la URL sin recargar (history.pushState) necesitan
    // que refresquemos el contexto y las quick actions.
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

        // También vigilamos cambios en el DOM por si el tema usa
        // content injection dinámica
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
        // Solo cambia el ancho del panel — no toca el layout de Moodle
        panel.classList.remove('jedomi-expand-half', 'jedomi-expand-full');
        if (mode === 'half')  panel.classList.add('jedomi-expand-half');
        if (mode === 'full')  panel.classList.add('jedomi-expand-full');
        // Highlight botón activo
        ['half','full'].forEach(m => {
            const btn = g(`jedomi-expand-${m}`);
            if (btn) btn.style.color = (mode === m) ? 'var(--j-accent)' : '';
        });
    };

    // ── Open / Close ──────────────────────────────────────────────────────────
    const openSidebar = () => {
        fixBodyOverflow();
        const panel = g('jedomi-sidebar');
        if (!panel) return;
        sessionStorage.setItem('jedomi_open', '1'); // persistir estado

        // Alinear con el navbar de Moodle (fixed-top) — solo posición, sin tocar el layout
        const navbar = document.querySelector('nav.navbar.fixed-top, .navbar.fixed-top');
        const navH   = navbar ? navbar.getBoundingClientRect().height : 0;
        panel.style.top    = navH > 0 ? navH + 'px' : '0';
        panel.style.height = navH > 0 ? `calc(100vh - ${navH}px)` : '100vh';

        // Header de Jedomi = misma altura visual que el navbar
        const jedomiHeader = panel.querySelector('.jedomi-header');
        if (jedomiHeader && navH > 0) {
            jedomiHeader.style.height    = navH + 'px';
            jedomiHeader.style.minHeight = navH + 'px';
            jedomiHeader.style.padding   = '0 14px';
        }

        panel.classList.replace('jedomi-closed', 'jedomi-open');
        // NO tocamos el layout de Moodle — solo el overlay
        setExpandMode(expandMode);
        g('jedomi-input')?.focus();
    };

    const closeSidebar = () => {
        g('jedomi-sidebar')?.classList.replace('jedomi-open', 'jedomi-closed');
        g('jedomi-overlay')?.classList.add('jedomi-hidden');
        document.body.classList.remove('jedomi-push-half', 'jedomi-push-full');
        sessionStorage.removeItem('jedomi_open'); // limpiar estado
    };

    // Restaura los mensajes guardados en el DOM al recargar página
    const _restoreConversationUI = () => {
        const msgs = g('jedomi-messages');
        if (!msgs || conversation.length === 0) return;
        // Limpiar saludo inicial
        msgs.innerHTML = '';
        conversation.forEach(msg => {
            if (msg.role === 'user') {
                addMessage('user', typeof msg.content === 'string' ? msg.content : '...');
            } else if (msg.role === 'assistant') {
                addMessage('assistant', typeof msg.content === 'string' ? msg.content : '...');
            }
        });
    };

    // ── Cache de sesión Moodle — servidor, no cliente ────────────────────────
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
        // Solo guardar el ÚLTIMO mensaje (el que acaba de completarse)
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
        // Borrar en el servidor (cache de sesión Moodle)
        const body = new URLSearchParams({
            action:  'clear',
            sesskey: M?.cfg?.sesskey || '',
        });
        fetch(_convUrl(), { method: 'POST', credentials: 'same-origin', body }).catch(() => {});
        // Nueva sesión al limpiar
        sessionId = crypto.randomUUID ? crypto.randomUUID()
            : Date.now().toString(36) + Math.random().toString(36).slice(2);
        localStorage.setItem('jedomi_session_id', sessionId);
        const msgs = g('jedomi-messages');
        if (msgs) msgs.innerHTML = `<div class="jedomi-message jedomi-message-assistant">
            <div class="jedomi-bubble">${(cfg.strings||{}).new_conversation_msg || 'New conversation started. How can I help you?'}</div></div>`;
    };

    const editLastMessage = () => {
        // Encuentra el último mensaje del usuario y lo pone en el input para editar
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
            // Seleccionar todo el texto para facilitar la edición
            input.select();
        }

        // Remover el último par user/assistant de la conversación
        const lastUserIdx = [...conversation].reverse().findIndex(m => m.role === 'user');
        if (lastUserIdx >= 0) {
            const actualIdx = conversation.length - 1 - lastUserIdx;
            // Remover desde el último mensaje del usuario en adelante
            conversation.splice(actualIdx);
        }

        // Remover los últimos bubbles del DOM
        const msgs = g('jedomi-messages');
        if (msgs) {
            const bubbles = msgs.querySelectorAll('.jedomi-message');
            // Quitar los últimos 2 (user + assistant)
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
            course:    '\uD83D\uDCDA ' + (name || S.ctx_course    || 'Course'),
            activity:  '\uD83D\uDCDD ' + (name || ctx.activity_type || S.ctx_activity || 'Activity'),
            grades:    '\uD83D\uDCCA ' + (S.ctx_grades    || 'Grades'),
            admin:     '\u2699\uFE0F '  + (S.ctx_admin    || 'Administration'),
            dashboard: '\uD83C\uDFE0 ' + (S.ctx_dashboard || 'Dashboard'),
            messages:  '\uD83D\uDCAC ' + (S.ctx_messages  || 'Messages'),
            profile:   '\uD83D\uDC64 ' + (S.ctx_profile   || 'Profile'),
            calendar:  '\uD83D\uDCC5 ' + (S.ctx_calendar  || 'Calendar'),
            files:     '\uD83D\uDCC1 ' + (S.ctx_files     || 'Files'),
        };
        // Fallback: título de la página (sin el sufijo "| Moodle")
        const rawTitle  = document.title.replace(/\s*[|\-]\s*Moodle.*$/i, '').trim();
        const pageTitle = rawTitle.length > 35 ? rawTitle.substring(0, 33) + '\u2026' : rawTitle;
        const label = labels[loc] || (pageTitle ? '\uD83D\uDCC4 ' + pageTitle : '');
        const el = g('jedomi-context-label');
        if (el) {
            el.textContent     = label;
            el.style.display   = label ? '' : 'none';
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

    const loadQuickActions = () => {
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
        // Usar nombre del curso si está disponible, no el ID
        const courseName = ctx.course_name
            ? `"${ctx.course_name}"`
            : (cid ? `con ID ${cid}` : 'actual');

        let actions = [];

        // ── detectar si estamos en un curso (por contexto PHP o URL) ──────────
        const inCourse = !!(ctx.course_name || cid) && loc !== 'admin' && loc !== 'dashboard';

        // ── ADMIN ────────────────────────────────────────────────
        if (role === 'admin') {
            if (inCourse) {
                actions = [
                    { icon: '🏆', label: S.qa_admin_top_student_label || 'Top student',
                      msg: (S.qa_admin_top_student_msg || 'Who is the top student in course {$a}?').replace('{$a}', courseName) },
                    { icon: '⚠️', label: S.qa_admin_at_risk_label || 'At risk',
                      msg: (S.qa_admin_at_risk_msg || 'Which students are at risk of failing in course {$a}?').replace('{$a}', courseName) },
                    { icon: '📊', label: S.qa_admin_performance_label || 'Performance',
                      msg: (S.qa_admin_performance_msg || 'Show me the grade ranking for course {$a}').replace('{$a}', courseName) },
                    { icon: '👻', label: S.qa_admin_inactive_label || 'Inactive',
                      msg: (S.qa_admin_inactive_msg || 'Which students haven\'t accessed course {$a} in the last 30 days?').replace('{$a}', courseName) },
                    { icon: '📝', label: S.qa_admin_missing_work_label || 'Missing work',
                      msg: (S.qa_admin_missing_work_msg || 'Which students haven\'t submitted activities in course {$a}?').replace('{$a}', courseName) },
                ];
            } else if (loc === 'admin') {
                actions = [
                    { icon: '👥', label: S.qa_admin_active_users_label || 'Active users',
                      msg: S.qa_admin_active_users_msg || 'How many active users are there in the system?' },
                    { icon: '🔐', label: S.qa_admin_external_emails_label || 'External emails',
                      msg: S.qa_admin_external_emails_msg || 'Are there users with non-institutional emails?' },
                    { icon: '📋', label: S.qa_admin_empty_cohorts_label || 'Empty cohorts',
                      msg: S.qa_admin_empty_cohorts_msg || 'Are there cohorts with no members assigned?' },
                    { icon: '⚠️', label: S.qa_admin_gdpr_pending_label || 'GDPR pending',
                      msg: S.qa_admin_gdpr_pending_msg || 'Are there pending GDPR deletion requests?' },
                    { icon: '🔒', label: S.qa_admin_security_label || 'Security',
                      msg: S.qa_admin_security_msg || 'Are there users with suspicious characters in their names?' },
                ];
            } else {
                actions = [
                    { icon: '📊', label: S.qa_admin_overview_label || 'Overview',
                      msg: S.qa_admin_overview_msg || 'Give me a summary of the overall platform status' },
                    { icon: '⚠️', label: S.qa_admin_alerts_label || 'Alerts',
                      msg: S.qa_admin_alerts_msg || 'What requires immediate attention today?' },
                    { icon: '🏆', label: S.qa_admin_top_courses_label || 'Top courses',
                      msg: S.qa_admin_top_courses_msg || 'Which courses have the best performance?' },
                    { icon: '💾', label: S.qa_admin_storage_label || 'Storage',
                      msg: S.qa_admin_storage_msg || 'Which course has the heaviest files uploaded?' },
                ];
            }
        } else if (role === 'manager') {
            if (inCourse) {
                actions = [
                    { icon: SVG.chart, label: S.qa_manager_overview_label || 'Overview',
                      msg: (S.qa_manager_overview_msg || 'Give me a summary of course {$a}').replace('{$a}', courseName) },
                    { icon: SVG.alert, label: S.qa_manager_at_risk_label || 'At risk',
                      msg: (S.qa_manager_at_risk_msg || 'Which students are at risk in course {$a}?').replace('{$a}', courseName) },
                    { icon: SVG.users, label: S.qa_manager_enrolled_label || 'Enrolled',
                      msg: (S.qa_manager_enrolled_msg || 'How many students are enrolled in course {$a}?').replace('{$a}', courseName) },
                    { icon: SVG.ghost, label: S.qa_manager_inactive_label || 'Inactive',
                      msg: (S.qa_manager_inactive_msg || 'Which students haven\'t accessed course {$a} in the last 14 days?').replace('{$a}', courseName) },
                ];
            } else {
                actions = [
                    { icon: SVG.users, label: S.qa_manager_inactive_users_label || 'Inactive users',
                      msg: S.qa_manager_inactive_users_msg || 'Which users haven\'t accessed the system in the last 30 days?' },
                    { icon: SVG.chart, label: S.qa_manager_top_courses_label || 'Top courses',
                      msg: S.qa_manager_top_courses_msg || 'Which courses have the most activity this week?' },
                    { icon: SVG.alert, label: S.qa_manager_global_risk_label || 'Global risk',
                      msg: S.qa_manager_global_risk_msg || 'Are there students at risk of failing in any course?' },
                    { icon: SVG.task,  label: S.qa_manager_no_teacher_label || 'No teacher',
                      msg: S.qa_manager_no_teacher_msg || 'Are there courses with no teacher assigned?' },
                ];
            }
        } else if (role === 'teacher') {
            if ((loc === 'course' || loc === 'activity' || loc === 'grades') && cid) {
                actions = [
                    { icon: SVG.chart, label: S.qa_teacher_performance_label || 'Performance',
                      msg: (S.qa_teacher_performance_msg || 'What is the average grade in course {$a}?').replace('{$a}', courseName) },
                    { icon: SVG.alert, label: S.qa_teacher_at_risk_label || 'At risk',
                      msg: (S.qa_teacher_at_risk_msg || 'Which students are at risk in course {$a}?').replace('{$a}', courseName) },
                    { icon: SVG.ghost, label: S.qa_teacher_never_logged_label || 'Never logged in',
                      msg: (S.qa_teacher_never_logged_msg || 'Which students never accessed course {$a}?').replace('{$a}', courseName) },
                    { icon: SVG.task,  label: S.qa_teacher_missing_work_label || 'Missing work',
                      msg: (S.qa_teacher_missing_work_msg || 'Who hasn\'t submitted the latest assignment in course {$a}?').replace('{$a}', courseName) },
                ];
                if (caps.can_send_messages) {
                    actions.push({ icon: SVG.mic, label: S.qa_teacher_notify_group_label || 'Notify group',
                      msg: (S.qa_teacher_notify_group_msg || 'Draft a message for students in course {$a} reminding them of pending activities').replace('{$a}', courseName) });
                }
                if (caps.can_view_all_grades) {
                    actions.push({ icon: SVG.star, label: S.qa_teacher_top_students_label || 'Top students',
                      msg: (S.qa_teacher_top_students_msg || 'Who has more than 90 points in course {$a}?').replace('{$a}', courseName) });
                }
            } else {
                actions = [
                    { icon: SVG.chart, label: S.qa_teacher_my_courses_label || 'My courses',
                      msg: S.qa_teacher_my_courses_msg || 'In which courses am I currently a teacher?' },
                    { icon: SVG.alert, label: S.qa_teacher_my_alerts_label || 'My alerts',
                      msg: S.qa_teacher_my_alerts_msg || 'Are there students at risk in any of my courses?' },
                ];
            }
        } else { // STUDENT
            if (cid) {
                actions = [
                    { icon: SVG.task,  label: S.qa_student_my_tasks_label || 'My tasks',
                      msg: (S.qa_student_my_tasks_msg || 'What assignments do I have pending in course {$a}?').replace('{$a}', courseName) },
                    { icon: SVG.chart, label: S.qa_student_my_grade_label || 'My grade',
                      msg: (S.qa_student_my_grade_msg || 'What is my average in course {$a}?').replace('{$a}', courseName) },
                    { icon: SVG.task,  label: S.qa_student_my_progress_label || 'My progress',
                      msg: (S.qa_student_my_progress_msg || 'How many activities have I completed in course {$a}?').replace('{$a}', courseName) },
                    { icon: SVG.help,  label: S.qa_student_help_label || 'Help',
                      msg: (S.qa_student_help_msg || 'I don\'t understand the content of course {$a}. Can you explain?').replace('{$a}', courseName) },
                ];
            } else {
                actions = [
                    { icon: SVG.chart, label: S.qa_student_my_courses_label || 'My courses',
                      msg: S.qa_student_my_courses_msg || 'Which courses am I currently enrolled in?' },
                    { icon: SVG.task,  label: S.qa_student_my_grades_label || 'My grades',
                      msg: S.qa_student_my_grades_msg || 'What is my overall average across all my courses?' },
                    { icon: SVG.task,  label: S.qa_student_this_week_label || 'This week',
                      msg: S.qa_student_this_week_msg || 'What activities and deadlines do I have coming up?' },
                ];
            }
        }

        // Mapeo de iconos emoji → SVG tech
        const iconMap = {
            '📊': SVG.chart, '⚠️': SVG.alert, '👻': SVG.ghost, '📝': SVG.task,
            '🌙': SVG.moon,  '👥': SVG.users, '🔐': SVG.shield,'📋': SVG.lock,
            '🏆': SVG.star,  '❓': SVG.help,  '💰': SVG.cpu,   '✅': SVG.task,
            '📚': SVG.chart, '💬': SVG.mic,   '🔒': SVG.shield,'📅': SVG.task,
        };
        container.innerHTML = actions.map(a => {
            const svgIcon = iconMap[a.icon] || SVG.chart;
            return `<button class="jedomi-quick-btn" data-msg="${escHtml(a.msg)}">${svgIcon} ${a.label}</button>`;
        }).join('');

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

        if (!text && !pendingImage) return;

        let userContent;
        let displayText = text;

        if (pendingImage) {
            userContent = [
                { type: 'text', text: text || (cfg.strings?.analyze_image || 'Analyze this image.') },
                { type: 'image_url', image_url: { url: `data:${pendingImage.mimeType};base64,${pendingImage.base64}` } }
            ];
        } else {
            userContent = text;
        }

        addMessage('user', displayText, pendingImage);
        if (input) { input.value = ''; input.style.height = ''; }
        clearImage();
        conversation.push({ role: 'user', content: userContent });
        _saveConversation(); // Guardar inmediatamente

        setThinking(true);
        if (sendBtn) sendBtn.disabled = true;

        // Crear bubble vacío para ir llenando con streaming
        const streamBubble = createStreamBubble();

        try {
            let fullText = '';

            await callGatewayStream(
                conversation,
                // onToken: agrega cada token al bubble
                (token) => {
                    fullText += token;
                    if (streamBubble.thinking) {
                        // Primera respuesta — ocultar thinking y mostrar bubble
                        setThinking(false);
                        streamBubble.thinking = false;
                        streamBubble.el.classList.remove('jedomi-hidden');
                    }
                    streamBubble.rawText += token;
                    streamBubble.inner.innerHTML = renderMarkdown(streamBubble.rawText);
                    // Scroll suave al fondo
                    const msgs = g('jedomi-messages');
                    if (msgs) msgs.scrollTop = msgs.scrollHeight;
                },
                // onToolEvent: muestra qué herramienta se está ejecutando
                (toolEvent) => {
                    // toolEvent is {tool: "search_tables", args: {...}}
                    const toolName = typeof toolEvent === 'string' ? toolEvent : (toolEvent?.tool || 'unknown');
                    const S2 = cfg.strings || {};
                    const labels = {
                        search_tables:  '🔍 ' + (S2.tool_search_tables || 'Buscando tablas...'),
                        execute_sql:    '💾 ' + (S2.tool_execute_sql || 'Consultando base de datos...'),
                        describe_table: '📋 ' + (S2.tool_describe_table || 'Describiendo tabla...'),
                        get_site_config:'⚙️ ' + (S2.tool_get_site_config || 'Obteniendo configuración...'),
                        ws_search:      '🔍 ' + (S2.tool_ws_search || 'Buscando funciones web...'),
                        ws_describe:    '📖 ' + (S2.tool_ws_describe || 'Describiendo función web...'),
                        ws_execute:     '⚡ ' + (S2.tool_ws_execute || 'Ejecutando acción...'),
                    };
                    streamBubble.inner.innerHTML =
                        `<span class="jedomi-tool-status">${labels[toolName] || '🔧 ' + toolName}</span>`;
                    streamBubble.el.classList.remove('jedomi-hidden');
                    setThinking(false);
                    streamBubble.thinking = false;
                }
            );

            // ── Detectar acción JEDOMI_ACTION en la respuesta ─────────────────
            const actionMatch = fullText.match(
                /JEDOMI_ACTION:send_messages\s*\nUSER_IDS:\s*\[([^\]]+)\]\s*\nMESSAGE:\s*([\s\S]*?)\nEND_ACTION/
            );
            if (actionMatch) {
                const userIds = actionMatch[1].split(',').map(s => s.trim()).filter(Boolean);
                const msgText = actionMatch[2].trim();
                // Ocultar el bloque técnico — mostrar solo la parte visible
                const cleanText = fullText.replace(/JEDOMI_ACTION[\s\S]*?END_ACTION/, '').trim();
                streamBubble.inner.innerHTML = renderMarkdown(cleanText);
                // Agregar botón de confirmación de envío
                const confirmDiv = document.createElement('div');
                confirmDiv.className = 'jedomi-send-confirm';
                confirmDiv.innerHTML = `
                    <div class="jedomi-confirm-msg">📬 ${((cfg.strings?.confirm_send_bulk || 'Send this message to {count} student(s)?').replace('{count}', '<strong>' + userIds.length + '</strong>'))}</div>
                    <div class="jedomi-confirm-preview">${msgText.substring(0, 120)}${msgText.length > 120 ? '...' : ''}</div>
                    <div class="jedomi-confirm-btns">
                        <button class="jedomi-confirm-yes">✅ ${(cfg.strings?.yes_send || 'Yes, send')}</button>
                        <button class="jedomi-confirm-no">❌ ${(cfg.strings?.cancel || 'Cancel')}</button>
                    </div>`;
                confirmDiv.querySelector('.jedomi-confirm-yes').addEventListener('click', async () => {
                    confirmDiv.innerHTML = `<div class="jedomi-confirm-msg">⏳ ${cfg.strings?.sending || 'Sending...'}</div>`;
                    try {
                        const res  = await fetch(`${cfg.gateway_url}/v1/messages/send`, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({user_ids: userIds, message: msgText})
                        });
                        const data = await res.json();
                        if (data.sent > 0) {
                            const S = cfg.strings || {};
                            const fails = data.failed > 0 ? ` ${(S.messages_sent_fail || '({failed} failed)').replace('{failed}', data.failed)}` : '';
                            const sent  = (S.messages_sent || '{sent} message(s) sent as Moodle internal messages.').replace('{sent}', data.sent);
                            confirmDiv.innerHTML = `<div class="jedomi-confirm-msg">✅ ${sent}${fails}<br><small style="opacity:0.7">${S.messages_recipients_note || 'Recipients will see them in their Moodle message inbox.'}</small></div>`;
                        } else {
                            const err = data.error || data.detail || (cfg.strings?.unknown_error || 'Unknown error');
                            confirmDiv.innerHTML = `<div class="jedomi-confirm-msg">❌ ${cfg.strings?.no_messages_sent || 'No messages sent.'}<br><small style="opacity:0.8">${err}</small></div>`;
                        }
                    } catch(e) {
                        confirmDiv.innerHTML = `<div class="jedomi-confirm-msg">❌ ${cfg.strings?.connection_error || 'Connection error:'} ${e.message}</div>`;
                    }
                });
                confirmDiv.querySelector('.jedomi-confirm-no').addEventListener('click', () => {
                    confirmDiv.innerHTML = `<div class="jedomi-confirm-msg">${cfg.strings?.cancelled || 'Cancelled.'}</div>`;
                });
                streamBubble.inner.appendChild(confirmDiv);
                fullText = cleanText; // guardar sin el bloque técnico
            } else {
                // ── Detectar acción pendiente de confirmación ─────────────────
                const pendingMatch = fullText.match(
                    /JEDOMI_PENDING_ACTION:(\{[\s\S]*?\}):END_PENDING/
                );
                if (pendingMatch) {
                    try {
                        const pending   = JSON.parse(pendingMatch[1]);
                        const cleanText = fullText
                            .replace(/JEDOMI_PENDING_ACTION:[\s\S]*?:END_PENDING/, '')
                            .trim();
                        streamBubble.inner.innerHTML = renderMarkdown(cleanText);
                        streamBubble.inner.appendChild(buildActionConfirmUI(pending));
                        fullText = cleanText;
                    } catch (parseErr) {
                        // Si el JSON no parsea bien, mostrar el texto tal cual
                        streamBubble.inner.innerHTML = renderMarkdown(fullText);
                    }
                } else {
                    // Finalizar: aplicar markdown completo
                    streamBubble.inner.innerHTML = renderMarkdown(fullText);
                }
            }
            addMessageActions(streamBubble.wrap, fullText);

            // Links — abrir en la misma pestaña directamente (sin preventDefault)
            streamBubble.inner.querySelectorAll('a[href]').forEach(a => {
                if (!a.getAttribute('target')) {
                    a.setAttribute('target', '_self');
                }
                a.style.cursor = 'pointer';
            });

            conversation.push({ role: 'assistant', content: fullText });
            _saveConversation(); // Persistir en localStorage

        } catch (err) {
            Log.error('Jedomi gateway error', err);
            setThinking(false);
            streamBubble.inner.innerHTML = renderMarkdown(
                `⚠️ **${cfg.strings?.gateway_error_title || 'Could not connect to the Jedomi Gateway.'}**\n\n` +
                `URL: \`${cfg.gateway_url}\`\n\n` +
                `${cfg.strings?.gateway_error_hint || 'Make sure the service is running:'}\n` +
                `\`docker compose up jedomi-api\``
            );
            streamBubble.el.classList.remove('jedomi-hidden');
        } finally {
            setThinking(false);
            if (sendBtn) sendBtn.disabled = false;
            input?.focus();
        }
    };

    // ── Call Chat Proxy (streaming) ─────────────────────────────────────────
    // El proxy recibe los mensajes, agrega tools + system prompt anti-hallucination,
    // ejecuta el tool loop contra la BD real de Moodle y devuelve SSE.
    const callGatewayStream = async (messages, onToken, onToolEvent) => {
        // Context truncation: máx 30 mensajes para evitar overflow de contexto
        const truncated = messages.slice(-30);

        const payload = {
            model:    cfg.model || 'jedomi-e4b',
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
            const err = await res.json().catch(() => ({}));
            throw new Error(err.detail || err.error?.message || `HTTP ${res.status}`);
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

                    // Evento de herramienta ejecutándose
                    if (chunk._jedomi_tool_executing && onToolEvent) {
                        onToolEvent(chunk._jedomi_tool_executing);
                        continue;
                    }
                    // Meta al final
                    if (chunk._meta) continue;
                    // Error del servidor
                    if (chunk.error) throw new Error(chunk.error.message || 'Error del Gateway');

                    // Token de contenido
                    const token = chunk.choices?.[0]?.delta?.content;
                    if (token && onToken) onToken(token);

                } catch (e) {
                    if (e.message !== 'Unexpected end of JSON input') throw e;
                }
            }
        }
    };

    // Fallback sin streaming (para clientes que no soporten SSE)
    const callGateway = async (messages) => {
        const payload = {
            model:    cfg.model || 'jedomi-e4b',
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
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || `HTTP ${res.status}`);
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
    // Crea un bubble vacío en el DOM y retorna referencias para llenarlo
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

        return { wrap, el: bubble, inner, rawText: '', thinking: true };
    };

    // Botones de acción bajo cada respuesta del asistente
    const addMessageActions = (wrap, text) => {
        const actions = document.createElement('div');
        actions.className = 'jedomi-msg-actions';
        actions.innerHTML = `
            <button class="jedomi-action-btn" data-action="copy" title="${(cfg.strings||{}).action_copy || 'Copy'}">${SVG.copy} ${(cfg.strings||{}).action_copy || 'Copy'}</button>
            <button class="jedomi-action-btn" data-action="good" title="${(cfg.strings||{}).action_helpful || 'Helpful'}">${SVG.thumbup} ${(cfg.strings||{}).action_helpful || 'Helpful'}</button>
            <button class="jedomi-action-btn" data-action="bad"  title="${(cfg.strings||{}).action_improve_title || 'Needs work'}">${SVG.thumbdn} ${(cfg.strings||{}).action_improve || 'Improve'}</button>`;

        actions.querySelector('[data-action="copy"]').addEventListener('click', () => {
            navigator.clipboard.writeText(text).then(() => {
                const btn = actions.querySelector('[data-action="copy"]');
                btn.textContent = '✅';
                setTimeout(() => { btn.textContent = '📋'; }, 1500);
            });
        });
        actions.querySelector('[data-action="good"]').addEventListener('click', (e) => {
            e.target.closest('button').style.color = '#10B981';
            saveFeedback(text, 'positive');
        });
        actions.querySelector('[data-action="bad"]').addEventListener('click', (e) => {
            e.target.closest('button').style.color = '#EF4444';
            saveFeedback(text, 'negative');
        });
        const editBtn = actions.querySelector('[data-action="edit-from"]');
        if (editBtn) {
            editBtn.addEventListener('click', () => editLastMessage());
        }

        // ← Dentro del bubble (inner), no fuera (wrap)
        const bubbleInner = wrap.querySelector('.jedomi-bubble-inner, .jedomi-bubble');
        if (bubbleInner) {
            bubbleInner.appendChild(actions);
        } else {
            wrap.appendChild(actions);
        }
    };

    const saveFeedback = async (text, type) => {
        // Envía feedback al Gateway para guardarlo en PostgreSQL (RLHF)
        if (lastMsgId && sessionId) {
            try {
                await fetch(`${cfg.gateway_url}/v1/chat/completions`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Authorization': `Bearer ${cfg.api_key}`},
                    body: JSON.stringify({
                        model: cfg.model,
                        messages: [{role: 'user', content: '_feedback_only_'}],
                        session_id: sessionId,
                        feedback: {message_id: lastMsgId, type},
                    }),
                });
            } catch(e) { Log.debug('Feedback error:', e); }
        }
        // Fallback localStorage
        const key = 'jedomi_feedback';
        const existing = JSON.parse(localStorage.getItem(key) || '[]');
        existing.push({text: text.slice(0,200), type, ts: Date.now()});
        localStorage.setItem(key, JSON.stringify(existing.slice(-50)));
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
            // Links → navegan el browser principal
            bubble.querySelectorAll('a[href]').forEach(a => {
                const href = a.getAttribute('href');
                if (href && !href.startsWith('http')) return;
                a.style.cursor = 'pointer';
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
            // Botón de editar en mensajes del usuario
            const editActions = document.createElement('div');
            editActions.className = 'jedomi-msg-actions jedomi-user-actions';
            editActions.innerHTML = `<button class="jedomi-action-btn" title="${cfg.strings?.action_edit_title || 'Edit message'}">${SVG.edit} ${cfg.strings?.action_edit || 'Edit'}</button>`;
            editActions.querySelector('button').addEventListener('click', () => editLastMessage());
            wrap.appendChild(editActions);
        }

        wrap.appendChild(bubble);
        msgs.appendChild(wrap);
        msgs.scrollTop = msgs.scrollHeight;
    };

    // Detecta tipo de página Moodle desde la URL
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
            // Code blocks
            .replace(/```[\w]*\n([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
            // Inline code
            .replace(/`([^`\n]+)`/g, '<code>$1</code>')
            // Bold
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            // Italic
            .replace(/_(.+?)_/g, '<em>$1</em>')
            // Links Moodle enriquecidos [text](url) — genera preview card para URLs de Moodle
            .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, linkText, url) => {
                const pageInfo = getMoodlePageInfo(url);
                if (pageInfo) {
                    return `<a href="${url}" class="jedomi-moodle-link" data-type="${pageInfo.type}">` +
                           `${pageInfo.icon} ${linkText} <span class="jedomi-link-badge">${pageInfo.type} ↗</span></a>`;
                }
                return `<a href="${url}">${linkText} ↗</a>`;
            })
            // ── Markdown tables — UNA tabla por bloque, con botón CSV correcto ──
            // Paso 1: marcar filas y separadores
            .replace(/^\|(.+)\|$/gm, (row) => {
                if (row.replace(/[\|\s\-:]/g, '') === '') return '§SEP§';
                const cells = row.slice(1,-1).split('|').map(c => c.trim());
                return '§ROW§' + cells.join('§CELL§');
            })
            // Paso 2: eliminar separadores
            .replace(/§SEP§\n?/g, '')
            // Paso 3: agrupar TODOS los §ROW§ consecutivos en UNA sola tabla
            .replace(/((?:§ROW§[^\n]*\n?)+)/g, (block) => {
                const rows = block.trim().split('\n')
                    .filter(r => r.startsWith('§ROW§'))
                    .map(r => r.replace('§ROW§', '').split('§CELL§'));

                // Mínimo 2 filas reales (encabezado + 1 dato) para crear tabla
                if (rows.length < 2) {
                    // Una sola fila — renderizar como lista simple
                    return '<p>' + rows[0].join(' | ') + '</p>';
                }

                // Primera fila = encabezados <th>
                const thead = '<thead><tr>' +
                    rows[0].map(c => `<th>${c}</th>`).join('') +
                    '</tr></thead>';

                // Resto = datos <td>
                const tbody = '<tbody>' + rows.slice(1).map(r =>
                    '<tr>' + r.map(c => `<td>${c}</td>`).join('') + '</tr>'
                ).join('') + '</tbody>';

                // Botón CSV via data attribute (event delegation, sin inline onclick)
                const csvBtn = `<button class="jedomi-csv-btn" data-csv-download="true">${cfg.strings?.csv_download || '📥 Download CSV'}</button>`;

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

    // ── Voice ─────────────────────────────────────────────────────────────────
    // ── Variables para visualizador de audio ─────────────────────────────────
    let audioCtx       = null;
    let analyser       = null;
    let vizAnimId      = null;
    let silenceTimer   = null;
    let speechDetected = false;

    const toggleVoice = async () => {
        if (isRecording) {
            // Clic manual para parar
            _stopRecording();
            return;
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });

            // ── Audio Context para análisis en tiempo real ─────────────────
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const source = audioCtx.createMediaStreamSource(stream);
            analyser = audioCtx.createAnalyser();
            analyser.fftSize = 256;
            analyser.smoothingTimeConstant = 0.7;
            source.connect(analyser);

            // ── MediaRecorder ──────────────────────────────────────────────
            audioChunks  = [];
            mediaRecorder = new MediaRecorder(stream);
            mediaRecorder.ondataavailable = (e) => { if (e.data.size > 0) audioChunks.push(e.data); };
            mediaRecorder.onstop = async () => {
                stream.getTracks().forEach(t => t.stop());
                if (audioCtx) { audioCtx.close(); audioCtx = null; }
                cancelAnimationFrame(vizAnimId);
                clearTimeout(silenceTimer);
                _setVizState(false);

                if (audioChunks.length === 0 || !speechDetected) {
                    setRecordingState(false);
                    const input = g('jedomi-input');
                    if (input) input.placeholder = cfg.strings?.placeholder || 'Ask Jedomi...';
                    return;
                }

                const blob = new Blob(audioChunks, { type: 'audio/webm' });
                setRecordingState(false);
                const input = g('jedomi-input');
                if (input) input.placeholder = '🎙️ ' + (cfg.strings?.transcribing || 'Transcribing...');
                await transcribeAudio(blob);
                if (input) input.placeholder = cfg.strings?.placeholder || 'Ask Jedomi...';
            };
            mediaRecorder.start(100); // chunks cada 100ms para respuesta rápida
            isRecording    = true;
            speechDetected = false;
            setRecordingState(true);

            // ── Visualizador + detección de silencio ───────────────────────
            const data   = new Uint8Array(analyser.frequencyBinCount);
            const input  = g('jedomi-input');
            let silentMs = 0;
            const SILENCE_THRESHOLD = 10;   // nivel mínimo de voz
            const SILENCE_STOP_MS   = 1800; // 1.8s de silencio → para
            const TICK_MS           = 80;

            const tick = () => {
                if (!isRecording) return;
                analyser.getByteFrequencyData(data);

                // Nivel de audio promedio (frecuencias de voz: 80-3000 Hz)
                const voiceBins = data.slice(2, Math.floor(data.length * 0.4));
                const avg = voiceBins.reduce((s, v) => s + v, 0) / voiceBins.length;

                // Actualizar visualizador
                _updateViz(avg);

                if (avg > SILENCE_THRESHOLD) {
                    speechDetected = true;
                    silentMs = 0;
                    if (input) input.placeholder = '🔴 ' + (cfg.strings?.listening || 'Listening...');
                } else if (speechDetected) {
                    silentMs += TICK_MS;
                    if (input) input.placeholder = `⏹️ ${cfg.strings?.silence_detected || 'Silence...'} (${((SILENCE_STOP_MS - silentMs)/1000).toFixed(1)}s)`;
                    if (silentMs >= SILENCE_STOP_MS) {
                        _stopRecording();
                        return;
                    }
                } else {
                    if (input) input.placeholder = '🎙️ ' + (cfg.strings?.waiting_voice || 'Waiting for voice...');
                }
                vizAnimId = setTimeout(tick, TICK_MS);
            };
            tick();

            // Safety: auto-stop a los 45 segundos
            setTimeout(() => { if (isRecording) _stopRecording(); }, 45000);

        } catch (e) {
            addMessage('assistant', '⚠️ ' + (cfg.strings?.mic_error || 'Could not access the microphone. Please check your browser permissions.'));
        }
    };

    const _stopRecording = () => {
        if (!isRecording) return;
        isRecording = false;
        cancelAnimationFrame(vizAnimId);
        clearTimeout(silenceTimer);
        mediaRecorder?.stop();
    };

    // ── Visualizador de barras en el botón ────────────────────────────────────
    let vizCanvas = null;
    let vizCtx    = null;

    const _setVizState = (on) => {
        const btn = g('jedomi-voice-btn');
        if (!btn) return;
        if (!on) {
            // Restaurar icono original
            btn.innerHTML = SVG.mic;
            btn.classList.remove('recording');
            if (vizCanvas) { vizCanvas.remove(); vizCanvas = null; vizCtx = null; }
        }
    };

    const _updateViz = (level) => {
        const btn = g('jedomi-voice-btn');
        if (!btn) return;

        // Crear canvas la primera vez
        if (!vizCanvas) {
            vizCanvas = document.createElement('canvas');
            vizCanvas.width  = 36;
            vizCanvas.height = 20;
            vizCanvas.style.cssText = 'position:absolute;bottom:-8px;left:50%;transform:translateX(-50%);pointer-events:none;';
            btn.style.position = 'relative';
            btn.appendChild(vizCanvas);
            vizCtx = vizCanvas.getContext('2d');
        }

        // Dibujar barras de audio (5 barras)
        vizCtx.clearRect(0, 0, 36, 20);
        const bars    = 5;
        const barW    = 4;
        const gap     = 2;
        const maxH    = 18;
        const baseH   = 3;
        const norm    = Math.min(level / 120, 1);

        for (let i = 0; i < bars; i++) {
            // Cada barra varía con el nivel + algo de aleatoriedad por posición
            const phase = Math.sin(Date.now() / 150 + i * 1.2) * 0.3 + 0.7;
            const h = baseH + (maxH - baseH) * norm * phase;
            const x = i * (barW + gap) + 2;
            const y = (maxH - h) / 2 + 1;

            const alpha = speechDetected ? 0.9 : 0.4;
            vizCtx.fillStyle = speechDetected
                ? `rgba(239,68,68,${alpha})`
                : `rgba(139,92,246,${alpha})`;
            vizCtx.beginPath();
            vizCtx.roundRect(x, y, barW, h, 2);
            vizCtx.fill();
        }
    };

    const transcribeAudio = async (blob) => {
        const form = new FormData();
        form.append('file', blob, 'audio.webm');
        try {
            const r = await fetch(`${cfg.gateway_url}/v1/audio/transcriptions`, {
                method:  'POST',
                headers: { 'Authorization': `Bearer ${cfg.api_key}` },
                body:    form,
            });
            const d = await r.json();
            if (d.text) {
                const input = g('jedomi-input');
                if (input) {
                    input.value = d.text;
                    input.dispatchEvent(new Event('input'));
                }
            }
        } catch (e) {
            Log.error('Transcription error', e);
        }
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

    // ── File (PDF/CSV/TXT) ────────────────────────────────────────────────────
    const handleFileSelect = async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;

        const statusEl = g('jedomi-input');
        const origPlaceholder = statusEl?.placeholder;
        if (statusEl) statusEl.placeholder = (cfg.strings?.processing_file || 'Processing {$a}...').replace('{$a}', file.name);

        try {
            const formData = new FormData();
            formData.append('file', file);

            const resp = await fetch(`${cfg.gateway_url}/v1/files/extract`, {
                method: 'POST',
                headers: { 'Authorization': `Bearer ${cfg.api_key}` },
                body: formData,
            });

            if (!resp.ok) {
                const err = await resp.json().catch(() => ({}));
                throw new Error(err.detail || `Error ${resp.status}`);
            }

            const data = await resp.json();
            const text = data.text || '';
            const info = data.format === 'csv'
                ? (cfg.strings?.file_csv  || 'CSV: {rows} rows, {columns} columns').replace('{rows}', data.rows).replace('{columns}', data.columns)
                : data.format === 'pdf'
                ? (cfg.strings?.file_pdf  || 'PDF: {pages} pages').replace('{pages}', data.pages)
                : (cfg.strings?.file_text || 'Text: {chars} characters').replace('{chars}', data.chars);

            // Incluir el contenido del archivo en el mensaje
            const input = g('jedomi-input');
            if (input) {
                const prefix = input.value ? input.value + '\n\n' : '';
                input.value = `${prefix}${(cfg.strings?.attached_file_info || '[Attached file: {$a}]').replace('{$a}', file.name)} — ${info}\n\n${text}`;
                input.dispatchEvent(new Event('input'));
                input.focus();
            }

            addMessage('assistant', `📎 **${file.name}** ${(cfg.strings?.file_loaded || 'loaded')} (${info}). ${cfg.strings?.file_loaded_hint || 'You can ask questions about its content.'}`);

        } catch (err) {
            addMessage('assistant', `⚠️ ${cfg.strings?.file_process_error || 'Could not process'} **${file.name}**: ${err.message}`);
        } finally {
            if (statusEl && origPlaceholder) statusEl.placeholder = origPlaceholder;
            const fi = g('jedomi-file-input');
            if (fi) fi.value = '';
        }
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
    const setRecordingState = (recording) => {
        const btn = g('jedomi-voice-btn');
        if (btn) {
            btn.classList.toggle('recording', recording);
            btn.innerHTML = recording
                ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="8"/></svg>'
                : SVG.mic;
        }
    };

    // ── Thinking colapsable ───────────────────────────────────────────────────
    const setThinking = (show) => {
        const wrapper = g('jedomi-thinking-wrapper');
        if (!wrapper) return;
        wrapper.classList.toggle('jedomi-hidden', !show);
        if (show) {
            // Empieza colapsado (solo la barra, sin los puntos)
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
                const dt = new DataTransfer();
                dt.items.add(file);
                const input = g('jedomi-file-input');
                if (input) { input.files = dt.files; input.dispatchEvent(new Event('change')); }
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

    const escHtml = (s) => String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

    return { init };
});
