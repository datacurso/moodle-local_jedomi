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
 * Shared state of the Jedomi sidebar: its configuration and everything the modules of the panel change.
 *
 * Every module reads and writes this state here, so no module keeps state of its own.
 *
 * @module     local_jedomi/local/sidebar/state
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Configuration of the panel, as the server gave it. The object is kept and its content replaced (see setConfig), so
 * every module reads the current configuration.
 *
 * @type {Object}
 */
export const cfg = {};

/**
 * Replace the configuration of the panel.
 *
 * @param {Object} config New configuration.
 */
export const setConfig = (config) => {
    Object.keys(cfg).forEach((key) => delete cfg[key]);
    Object.assign(cfg, config || {});
};

/**
 * Initial value of each field of the state.
 *
 * @returns {Object}
 */
const initialState = () => ({
    conversation: [],
    // Image attached for the next message: {base64, mimeType, file}. It is read by the vision model when sent.
    pendingImage: null,
    // Files attached to the next message, in order: [{file, name, extracted, controller}]. Each one is read (and
    // charged) only when the message is sent; extracted keeps its text, so a retry reads nothing again.
    pendingFiles: [],
    // Address of the panel configuration, kept to renew the signed permissions (SPT) before they expire.
    configUrl: null,
    // Time, on the clock of the browser, when the signed permissions expire (0 when unknown).
    sptExpiresAt: 0,
    // Renewal of the signed permissions in progress, shared by the requests that wait for it.
    renewing: null,
    // True after the user asked to load the panel again: it opens when it loads, or the notice opens again.
    retryOpen: false,
    injected: false,
    // True while the sidebar template is being rendered, so a second bootstrap does not inject it twice.
    injecting: false,
    sessionId: null,
    // Microphone recording in progress, if any, and whether it was cancelled instead of stopped.
    recorder: null,
    recordingCancelled: false,
    // Recorded voice message waiting in the preview bar: {blob, url, audio, mime, seconds}. It is only uploaded when sent.
    voice: null,
    // Increases on every voice bar render, so an older render that resolves late is dropped.
    voiceBarVersion: 0,
    // True while a message is being prepared or answered, so it is not sent twice.
    sending: false,
    // Voice conversation in progress, if any (see startVoiceMode).
    voiceMode: null,
    // Voice conversation waiting for the microphone permission, if any: {cancelled} (see startVoiceMode).
    voiceModeStarting: null,
    // Written answer in progress, if any: aborting it stops the answer (stop button and new conversation).
    chatController: null,
    // Requests to the conversation history, sent one after the other so they reach the server in order.
    historyQueue: Promise.resolve(),
    // Element that had the focus before the panel opened, to give it back when the panel closes.
    focusBeforeOpen: null,
    // Width of the open panel: 'normal' | 'half' | 'full'.
    expandMode: 'normal',
    // Drag events over the panel not yet left, to show the drop zone while a file is dragged over it.
    dropCounter: 0,
});

/**
 * Mutable state of the panel.
 *
 * @type {Object}
 */
export const state = initialState();

/**
 * Give every field of the state its initial value, and empty the configuration.
 *
 * Called when the entry module is loaded again (require.undef), as a new copy of the panel starts from scratch.
 */
export const resetState = () => {
    Object.assign(state, initialState());
    setConfig({});
};

/**
 * Calls into modules that load after the ones that make them, registered by those modules, so no two modules import
 * each other.
 *
 * @type {Object}
 */
export const hooks = {
    // A confirmation card was resolved (registered by the voice conversation, which listens again).
    actionResolved: () => null,
    // End the voice conversation (registered by the voice conversation).
    exitVoiceMode: () => null,
    // The last sentence of a spoken answer was read (registered by the voice conversation).
    voiceTurnSpoken: () => null,
};
