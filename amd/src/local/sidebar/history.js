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
 * Conversation history of the Jedomi sidebar, kept on the server (Moodle session cache).
 *
 * @module     local_jedomi/local/sidebar/history
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {ensureFreshPermissions, pageCourseId, signedPermissions} from './permissions';
import {cfg, state} from './state';

// Address of the conversation history endpoint.
const conversationUrl = () => `${cfg.wwwroot}/local/jedomi/conversation.php`;

// Load the conversation of the session. Resolves to the actions still waiting for confirmation.
export const loadConversationFromServer = async() => {
    try {
        await ensureFreshPermissions();
        const r = await fetch(conversationUrl(), {
            method: 'POST',
            credentials: 'same-origin',
            body: new URLSearchParams({action: 'get', sesskey: M?.cfg?.sesskey || '', spt: signedPermissions(),
                courseid: String(pageCourseId())}),
        });
        const d = await r.json();
        if (d.ok && Array.isArray(d.messages) && d.messages.length > 0) {
            state.conversation = d.messages;
        }
        return d.ok && Array.isArray(d.pendingactions) ? d.pendingactions : [];
    } catch (e) {
        // First visit or empty cache: nothing to restore.
        return [];
    }
};

// Send a change of the conversation history to the server. The changes go one after the other, so a removal never
// reaches the server before the message it removes. The permissions are renewed first when they are about to
// expire; the body is built with the renewed ones.
export const historyRequest = (fields) => {
    state.historyQueue = state.historyQueue.then(() => ensureFreshPermissions()).then(() => fetch(conversationUrl(), {
        method: 'POST',
        credentials: 'same-origin',
        body: new URLSearchParams({
            ...fields,
            sesskey: M?.cfg?.sesskey || '',
            spt:     signedPermissions(),
            courseid: String(pageCourseId()),
        }),
    })).catch(() => null); // Silent.
    return state.historyQueue;
};

export const saveConversation = () => {
    // Only save the LAST message (the one just completed).
    if (state.conversation.length === 0) {
        return;
    }
    const last = state.conversation[state.conversation.length - 1];
    const fields = {action: 'append', role: last.role, content: last.content};
    if (last.display) {
        fields.display = last.display;
    }
    if (last.images) {
        fields.images = String(last.images);
    }
    if (last.id) {
        fields.messageid = last.id;
    }
    historyRequest(fields);
};

// Remove the last user message, and what follows it, from the conversation and from the history on the server:
// the question was edited, or its answer was stopped before any text or failed.
export const undoLastQuestion = () => {
    const lastUserIdx = state.conversation.map(m => m.role).lastIndexOf('user');
    if (lastUserIdx < 0) {
        return;
    }
    state.conversation.splice(lastUserIdx);
    historyRequest({action: 'undolast'});
};
