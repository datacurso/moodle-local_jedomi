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
 * DOM helpers of the Jedomi sidebar: elements by id, template rendering, thinking indicator and page overflow.
 *
 * @module     local_jedomi/local/sidebar/dom
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import Templates from 'core/templates';

// An element of the panel by its id.
export const g = (id) => document.getElementById(id);

// Listen to an event of an element of the panel, when the element exists.
export const on = (id, ev, fn) => g(id)?.addEventListener(ev, fn);

// Render a template into a node, replacing its content or appending to it.
export const renderInto = async(node, template, context, append = false) => {
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

// Show or hide the collapsible thinking indicator.
export const setThinking = (show) => {
    const wrapper = g('jedomi-thinking-wrapper');
    if (!wrapper) {
        return;
    }
    wrapper.classList.toggle('jedomi-hidden', !show);
    if (show) {
        // Starts collapsed (only the bar, without the dots).
        wrapper.classList.remove('jedomi-thinking-expanded');
        const msgs = g('jedomi-messages');
        if (msgs) {
            msgs.scrollTop = msgs.scrollHeight;
        }
    }
};

// ── Fix Body Overflow ─────────────────────────────────────────────────────
// Some themes set overflow:hidden on html or body, which breaks
// position:fixed. It is detected here and fixed surgically.
export const fixBodyOverflow = () => {
    const els = [document.documentElement, document.body];
    els.forEach(el => {
        const style = window.getComputedStyle(el);
        const overflow = style.overflow + style.overflowX + style.overflowY;
        if (overflow.includes('hidden')) {
            el.style.setProperty('overflow', 'visible', 'important');
            Log.debug('Jedomi: fixed overflow:hidden on', el.tagName);
        }
    });
};
