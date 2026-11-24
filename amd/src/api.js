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
 * Public browser API other plugins use to open the Jedomi chat.
 *
 * The signatures of isAvailable() and openWithSuggestion() are stable: an incompatible change raises the major
 * version of Jedomi. The server-side check is \local_jedomi\public_api::chat_available().
 *
 * A suggestion is only ever written in the message field of the panel, as plain text: the user reads it, can change
 * it and sends it. It is never sent on its own.
 *
 * @module     local_jedomi/api
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @type {number} Longest suggestion, in characters; a longer one is cut. */
const MAX_SUGGESTION_LENGTH = 4000;

/** @type {number} How long a suggestion waits for the panel to finish loading, in milliseconds. */
const PANEL_WAIT_MS = 15000;

/** @type {string} Mark the server writes in the footer of a page where the panel is going to start. */
const PANEL_MARK = '[data-region="local_jedomi-panel"]';

/** @type {boolean} Whether the panel was started on this page for this user. */
let present = false;

/** @type {boolean} Whether the panel failed to load on this page. */
let failed = false;

/** @type {?function(string): boolean} Writes a suggestion in the loaded panel; null until the panel is loaded. */
let suggestionHandler = null;

/** @type {Array<{text: string, resolve: function(boolean), timer: number}>} Suggestions waiting for the panel. */
let waiting = [];

/**
 * Clean the text of a suggestion: a string, without control characters other than line breaks and tabs, cut to
 * MAX_SUGGESTION_LENGTH.
 *
 * @param {*} value Text given by the caller.
 * @returns {string}
 */
const cleanText = (value) => {
    if (typeof value !== 'string') {
        return '';
    }
    // eslint-disable-next-line no-control-regex
    return value.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '').trim().slice(0, MAX_SUGGESTION_LENGTH);
};

/**
 * Whether the Jedomi panel is on this page for the current user, already started or still loading.
 *
 * @returns {boolean}
 */
export const isAvailable = () => !failed && (present || document.querySelector(PANEL_MARK) !== null);

/**
 * Open the Jedomi panel with a suggested message in its message field, ready to be changed and sent by the user.
 *
 * @param {string|{prompt: string, source: ?string}} suggestion The text, or {prompt, source} where source names the
 *     calling plugin (for example local_datacurso_heatmap).
 * @returns {Promise<boolean>} True when the panel opened with the suggestion; false when Jedomi is not on the page,
 *     the panel could not be loaded or the text is empty.
 */
export const openWithSuggestion = (suggestion) => {
    const text = cleanText(typeof suggestion === 'object' && suggestion !== null ? suggestion.prompt : suggestion);
    if (!isAvailable() || text === '') {
        return Promise.resolve(false);
    }
    if (suggestionHandler) {
        return Promise.resolve(suggestionHandler(text));
    }
    return new Promise((resolve) => {
        const entry = {text, resolve, timer: 0};
        entry.timer = window.setTimeout(() => {
            waiting = waiting.filter((other) => other !== entry);
            resolve(false);
        }, PANEL_WAIT_MS);
        waiting.push(entry);
    });
};

/**
 * Mark the panel as started on this page. Internal: only local_jedomi/sidebar calls it.
 *
 * @private
 */
export const panelStarted = () => {
    present = true;
};

/**
 * Give the function that writes a suggestion in the loaded panel, and hand it the suggestions that were waiting.
 * Internal: only local_jedomi/sidebar calls it.
 *
 * @private
 * @param {function(string): boolean} handler Opens the panel and writes the text in its message field.
 */
export const panelReady = (handler) => {
    suggestionHandler = handler;
    const pending = waiting;
    waiting = [];
    pending.forEach((entry) => {
        window.clearTimeout(entry.timer);
        entry.resolve(handler(entry.text));
    });
};

/**
 * The panel could not be loaded: Jedomi is not available on this page. Internal: only local_jedomi/sidebar calls it.
 *
 * @private
 */
export const panelFailed = () => {
    present = false;
    failed = true;
    const pending = waiting;
    waiting = [];
    pending.forEach((entry) => {
        window.clearTimeout(entry.timer);
        entry.resolve(false);
    });
};
