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
 * Conversation of the Jedomi chat as a whole: a new conversation and its export.
 *
 * @module     local_jedomi/local/sidebar/conversation
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Notification from 'core/notification';
import {g, renderInto} from './dom';
import {historyRequest} from './history';
import {cfg, state} from './state';

// New conversation from the keyboard shortcut: a conversation in progress is only cleared after a confirmation.
export const confirmNewConversation = () => {
    if (!state.conversation.length) {
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

// Start a new conversation: the chat, its history on the server and the session are cleared.
export const clearChat = () => {
    // An answer in progress is stopped: it belongs to the conversation that is cleared.
    if (state.chatController) {
        const controller = state.chatController;
        controller.cleared = true;
        controller.abort();
    }
    state.conversation = [];
    // Clear it on the server (Moodle session cache).
    historyRequest({action: 'clear'});
    // New session after clearing.
    state.sessionId = crypto.randomUUID ? crypto.randomUUID()
        : Date.now().toString(36) + Math.random().toString(36).slice(2);
    localStorage.setItem('jedomi_session_id', state.sessionId);
    const msgs = g('jedomi-messages');
    if (msgs) {
        renderInto(msgs, 'local_jedomi/local/sidebar/notice', {text: (cfg.strings || {}).new_conversation_msg || ''});
    }
};

// Download the conversation as a text file.
export const exportConversation = () => {
    if (!state.conversation.length) {
        return;
    }
    // The title, the speaker names and the file name are language strings, in the language of the user.
    const L = cfg.strings || {};
    const speaker = (role) => (role === 'user' ? L.export_role_user : L.export_role_assistant) || role;
    const lines = state.conversation.map(m =>
        `[${speaker(m.role)}]\n${typeof m.content === 'string' ? m.content : JSON.stringify(m.content)}\n`
    ).join('\n---\n\n');
    const title = L.export_title || 'Jedomi';
    const blob = new Blob([`${title}\n${'='.repeat(40)}\n\n${lines}`], {type: 'text/plain'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    const filename = L.export_filename || 'jedomi-conversation';
    a.download = `${filename}-${new Date().toISOString().slice(0, 10)}.txt`;
    a.click();
    URL.revokeObjectURL(a.href);
};
