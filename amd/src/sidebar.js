// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

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
 * The parts of the panel live in local_jedomi/local/sidebar/*; their shared state is local_jedomi/local/sidebar/state.
 *
 * @module     local_jedomi/sidebar
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import Templates from 'core/templates';
import {showRestoredActions} from './local/sidebar/actions';
import {showConfigError} from './local/sidebar/config_error';
import {fixBodyOverflow} from './local/sidebar/dom';
import {wireUp} from './local/sidebar/events';
import {loadConversationFromServer} from './local/sidebar/history';
import {restoreConversationUI} from './local/sidebar/messages';
import {openSidebar} from './local/sidebar/panel';
import {fetchConfig, setPermissionsExpiry} from './local/sidebar/permissions';
import {loadQuickActions, updateContextLabel} from './local/sidebar/quick_actions';
import {cfg, resetState, setConfig, state} from './local/sidebar/state';

// A copy of this module loaded again (require.undef) starts with a new state, as the panel it starts is new.
resetState();

/**
 * Start the panel: load its configuration, then render it into the page.
 *
 * @param {Object} config {configUrl} of the panel configuration, or the configuration itself.
 */
export const init = (config) => {
    const doBootstrap = () => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => bootstrap());
        } else {
            bootstrap();
        }
    };

    // If we received a configUrl (lightweight init), fetch the full config.
    if (config && config.configUrl) {
        state.configUrl = config.configUrl;
        fetchConfig()
            .then(fullConfig => {
                setConfig(fullConfig);
                setPermissionsExpiry(fullConfig);
                doBootstrap();
                return null;
            })
            .catch((error) => {
                // Without its configuration the panel cannot work: it is never started empty. A notice with a
                // retry button is shown instead.
                Log.debug('Jedomi: the panel configuration could not be loaded', error);
                showConfigError(config, init);
            });
    } else {
        // Backward compat: config passed inline.
        setConfig(config || {});
        doBootstrap();
    }
};

// Render the panel into the page, restore its conversation and wire its events, once.
const bootstrap = async() => {
    // Prevent a double injection.
    if (state.injected || state.injecting || document.getElementById('jedomi-trigger')) {
        if (!state.injected && !state.injecting) {
            wireUp();
        }
        return;
    }
    state.injecting = true;

    // Initialise the persistent session ID.
    state.sessionId = localStorage.getItem('jedomi_session_id');
    if (!state.sessionId) {
        state.sessionId = crypto.randomUUID ? crypto.randomUUID()
            : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
                // eslint-disable-next-line no-bitwise
                const r = Math.random() * 16 | 0;
                // eslint-disable-next-line no-bitwise
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        localStorage.setItem('jedomi_session_id', state.sessionId);
    }

    // 1. Fix overflow:hidden, the most common problem with themes.
    fixBodyOverflow();

    // 2. Render the sidebar template into body.
    try {
        await injectHTML();
    } catch (error) {
        state.injecting = false;
        Log.error('Jedomi: the sidebar template could not be rendered', error);
        return;
    }

    // Load the conversation from the Moodle session cache (server).
    // No sessionStorage: the history lives on the server.
    // Actions still waiting for confirmation are shown again, so they can be confirmed or cancelled after a reload.
    loadConversationFromServer().then((pendingActions) => {
        if (state.conversation.length > 0 || pendingActions.length > 0) {
            setTimeout(() => {
                restoreConversationUI();
                showRestoredActions(pendingActions);
            }, 150);
        }
        return null;
    }).catch(() => null);

    // 3. Wire the events.
    wireUp();

    // 4. Listen to AJAX navigation changes (SPA-like themes).
    watchNavigation();

    // SessionStorage needs no save event: it persists automatically.

    state.injected = true;
    state.injecting = false;
    Log.debug('Jedomi sidebar injected successfully');

    // Reopen automatically if it was open before navigating.
    if (sessionStorage.getItem('jedomi_open') === '1' || state.retryOpen) {
        setTimeout(() => openSidebar(), 120);
    }
};

// The theme is not involved: the local_jedomi/sidebar template is rendered here and appended to body.
const injectHTML = async() => {
    const S = cfg.strings || {};
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
    if (!trigger) {
        return;
    }
    const assistant = cfg.assistant || {};
    const offset = (value) => {
        const number = Number(value);
        return Number.isFinite(number) ? Math.max(0, Math.min(500, Math.round(number))) + 'px' : '24px';
    };
    trigger.style.setProperty('--j-fab-x', offset(assistant.x));
    trigger.style.setProperty('--j-fab-y', offset(assistant.y));
};

// Glow pulse of the trigger button every 15 seconds.
const startGlowCycle = () => {
    const trigger = document.getElementById('jedomi-trigger');
    if (!trigger) {
        return;
    }
    const doGlow = () => {
        trigger.classList.add('jedomi-glowing');
        setTimeout(() => trigger.classList.remove('jedomi-glowing'), 2000);
    };
    // First glow 3 seconds after loading, then every 15 seconds.
    setTimeout(doGlow, 3000);
    setInterval(doGlow, 15000);
};

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
