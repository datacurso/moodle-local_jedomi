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
 * Messages of the Jedomi chat: user and assistant bubbles, the streaming bubble, the restored conversation and the
 * edition of the last question.
 *
 * @module     local_jedomi/local/sidebar/messages
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {g, renderInto} from './dom';
import {addMessageActions} from './feedback';
import {undoLastQuestion} from './history';
import {renderMarkdown} from './markdown';
import Selectors from './selectors';
import {cfg, state} from './state';

// Add a message bubble of the user or of the assistant to the chat. Returns its element.
export const addMessage = (role, content, image = null) => {
    const msgs = g('jedomi-messages');
    if (!msgs) {
        return;
    }

    const wrap = document.createElement('div');
    wrap.className = `jedomi-message jedomi-message-${role}`;

    const bubble = document.createElement('div');
    bubble.className = 'jedomi-bubble';

    if (role === 'assistant') {
        bubble.innerHTML = renderMarkdown(content);
        // Links → navigate the main browser window.
        bubble.querySelectorAll('a[href]').forEach(a => {
            const href = a.getAttribute('href');
            if (href && !href.startsWith('http')) {
                return;
            }
            a.addEventListener('click', (e) => {
                e.preventDefault();
                if (href) {
                    window.location.href = href;
                }
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
    if (role === 'user') {
        refreshEditButton();
    }
    msgs.scrollTop = msgs.scrollHeight;
    return wrap;
};

// Note shown after a reload in place of the images of a user message, which the history does not keep.
const addImagePlaceholder = (wrap, count) => {
    const bubble = wrap.querySelector('.jedomi-bubble');
    if (!bubble) {
        return;
    }
    const note = document.createElement('p');
    note.className = 'jedomi-msg-image-placeholder';
    const label = (cfg.strings || {}).image_not_kept || '';
    note.textContent = count > 1 ? `🖼 ${label} (${count})` : `🖼 ${label}`;
    bubble.appendChild(note);
};

// Restore the saved messages in the DOM after a page reload.
export const restoreConversationUI = () => {
    const msgs = g('jedomi-messages');
    if (!msgs || state.conversation.length === 0) {
        return;
    }
    // Clear the initial greeting.
    msgs.replaceChildren();
    state.conversation.forEach(msg => {
        const content = typeof msg.content === 'string' ? msg.content : '...';
        if (msg.role === 'user') {
            // The text the chat showed when it was sent (the question and the names of its files).
            const wrap = addMessage('user', typeof msg.display === 'string' && msg.display ? msg.display : content);
            // The images are not kept in the history (only that the message had them): a note takes their place.
            if (wrap && msg.images > 0) {
                addImagePlaceholder(wrap, msg.images);
            }
        } else if (msg.role === 'assistant') {
            const wrap = addMessage('assistant', content);
            // The answer gets its Copy and rating buttons back, with the same id, so its rating is kept.
            if (wrap) {
                addMessageActions(wrap, content, msg.id || '');
            }
        }
    });
};

// Create an empty bubble in the DOM and return references to fill it.
export const createStreamBubble = () => {
    const msgs = g('jedomi-messages');
    const wrap = document.createElement('div');
    wrap.className = 'jedomi-message jedomi-message-assistant';

    const bubble = document.createElement('div');
    bubble.className = 'jedomi-bubble jedomi-hidden';

    const inner = document.createElement('div');
    bubble.appendChild(inner);
    wrap.appendChild(bubble);
    msgs?.appendChild(wrap);
    if (msgs) {
        msgs.scrollTop = msgs.scrollHeight;
    }

    return {wrap, el: bubble, inner, rawText: '', thinking: true, version: 0};
};

// Id of a new answer, so its rating is stored once and can be changed (letters, digits, - and _ only).
export const newMessageId = () => `${(state.sessionId || 'jedomi').replace(/[^\w-]/g, '').slice(0, 36)}-${Date.now().toString(36)}`
    + `-${Math.random().toString(36).slice(2, 8)}`;

// Put the last question back in the message field to edit it, removing it and what follows it from the chat.
export const editLastMessage = () => {
    // Find the last user message and put it in the input to edit it.
    const lastUserMsg = [...state.conversation].reverse().find(m => m.role === 'user');
    if (!lastUserMsg) {
        return;
    }

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
    undoLastQuestion();

    // Remove its bubble and the ones after it from the chat.
    const msgs = g('jedomi-messages');
    const editable = msgs ? [...msgs.querySelectorAll('.jedomi-message-user:not([data-orphan])')].pop() : null;
    if (editable) {
        while (editable.nextElementSibling) {
            editable.nextElementSibling.remove();
        }
        editable.remove();
    }
    refreshEditButton();
};

// Only the last question of the conversation can be edited: the Edit button is shown under it alone.
export const refreshEditButton = () => {
    const msgs = g('jedomi-messages');
    if (!msgs) {
        return;
    }
    msgs.querySelectorAll('.jedomi-user-actions').forEach(node => node.remove());
    const last = [...msgs.querySelectorAll('.jedomi-message-user:not([data-orphan])')].pop();
    if (!last) {
        return;
    }
    const editActions = document.createElement('div');
    editActions.className = 'jedomi-msg-actions jedomi-user-actions';
    last.insertBefore(editActions, last.firstChild);
    renderInto(editActions, 'local_jedomi/local/sidebar/user_actions', {strings: cfg.strings || {}}).then(() => {
        editActions.querySelector(Selectors.actions.edit)
            ?.addEventListener('click', () => editLastMessage());
        return null;
    }).catch(() => null);
};
