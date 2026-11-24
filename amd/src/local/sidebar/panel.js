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
 * Panel of the Jedomi sidebar: opening, closing, width and keyboard focus.
 *
 * @module     local_jedomi/local/sidebar/panel
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {fixBodyOverflow, g} from './dom';
import {state} from './state';
import {exitVoiceMode} from './voice_mode';

// Set the width of the panel: 'normal' | 'half' | 'full'.
export const setExpandMode = (mode) => {
    const panel = g('jedomi-sidebar');
    if (!panel) {
        return;
    }
    state.expandMode = mode;
    // Only the panel width changes; Moodle's layout is not touched.
    panel.classList.remove('jedomi-expand-half', 'jedomi-expand-full');
    if (mode === 'half') {
        panel.classList.add('jedomi-expand-half');
    }
    if (mode === 'full') {
        panel.classList.add('jedomi-expand-full');
    }
    // Highlight the active button.
    ['half', 'full'].forEach(m => {
        const btn = g(`jedomi-expand-${m}`);
        if (btn) {
            btn.classList.toggle('jedomi-expand-active', mode === m);
        }
    });
};

export const openSidebar = () => {
    fixBodyOverflow();
    const panel = g('jedomi-sidebar');
    if (!panel) {
        return;
    }
    sessionStorage.setItem('jedomi_open', '1'); // Keep the state.
    if (!panel.contains(document.activeElement)) {
        state.focusBeforeOpen = document.activeElement;
    }

    // Align with Moodle's fixed-top navbar: position only, the layout is not touched.
    const navbar = document.querySelector('nav.navbar.fixed-top, .navbar.fixed-top');
    const navH = navbar ? navbar.getBoundingClientRect().height : 0;
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
    setExpandMode(state.expandMode);
    g('jedomi-input')?.focus();
};

// Whether an element is inside a core modal (Bootstrap .modal), which sits above the panel.
export const isInCoreModal = (el) => !!(el && el.closest && el.closest('.modal'));

// Whether the open panel covers the whole page: full screen, or the phone layout where it takes the full width.
// Only then does it behave as a dialog; beside the page it is a complementary region the user can Tab out of.
const panelCoversPage = (panel) => panel.classList.contains('jedomi-expand-full')
    || window.matchMedia('(max-width: 767px)').matches;

// While the panel covers the page, keep the focus inside it: Tab after its last control goes to the first one,
// and Shift+Tab before its first control goes to the last one. The focus is never taken back from a core modal.
export const trapFocus = (e) => {
    const panel = g('jedomi-sidebar');
    if (e.key !== 'Tab' || !panel?.classList.contains('jedomi-open') || !panelCoversPage(panel)) {
        return;
    }
    if (isInCoreModal(document.activeElement) || isInCoreModal(e.target) || document.querySelector('.modal.show')) {
        return;
    }
    const focusable = [...panel.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]):not([type="file"]), textarea:not([disabled]), '
        + 'select:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )].filter(el => el.offsetParent !== null && !el.closest('.jedomi-hidden'));
    if (!focusable.length) {
        return;
    }
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

export const closeSidebar = () => {
    exitVoiceMode();
    // A recording in progress is thrown away and a recorded voice message stops playing.
    if (state.recorder && state.recorder.state === 'recording') {
        state.recordingCancelled = true;
        state.recorder.stop();
    }
    state.voice?.audio.pause();
    const panel = g('jedomi-sidebar');
    const trigger = g('jedomi-trigger');
    // The focus goes back to where it was (the trigger by default) before the panel stops being reachable.
    if (panel && panel.contains(document.activeElement)) {
        const back = state.focusBeforeOpen && document.contains(state.focusBeforeOpen) && !panel.contains(state.focusBeforeOpen)
            && state.focusBeforeOpen !== document.body ? state.focusBeforeOpen : trigger;
        back?.focus();
    }
    state.focusBeforeOpen = null;
    if (panel) {
        panel.classList.replace('jedomi-open', 'jedomi-closed');
        // The closed panel is out of the Tab order and hidden from screen readers.
        panel.inert = true;
        panel.setAttribute('inert', '');
        panel.setAttribute('aria-hidden', 'true');
    }
    trigger?.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('jedomi-push-half', 'jedomi-push-full');
    sessionStorage.removeItem('jedomi_open'); // Clear the state.
};
