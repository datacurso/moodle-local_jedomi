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
 * Confirmation cards of the Jedomi actions that change data. The server holds each action and runs it only through
 * local_jedomi_resolve_action.
 *
 * @module     local_jedomi/local/sidebar/actions
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {g, renderInto} from './dom';
import {saveConversation} from './history';
import Selectors from './selectors';
import {cfg, hooks, state} from './state';

// The server holds the action and sends its id; it runs only through
// local_jedomi_resolve_action, which checks the owner, the expiry, the role and the keyword again.
// severity: 'critical' (red, the keyword must be typed) | 'high' (orange) | 'medium' (yellow).
const resolveAction = async(id, confirm, keyword = '') => {
    return Ajax.call([{
        methodname: 'local_jedomi_resolve_action',
        args: {id, confirm, keyword},
    }])[0];
};

// A short note keeps the outcome in the conversation, so the next answer knows it.
const addActionNote = (pending, message) => {
    state.conversation.push({role: 'assistant', content: `${pending.description} — ${message}`});
    saveConversation();
};

// Returns the card element at once; its content is rendered from local_jedomi/local/sidebar/action_confirm.
export const buildActionConfirmUI = (pending) => {
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

    const resolve = async(confirm) => {
        const yesBtn = div.querySelector(Selectors.actions.confirmYes);
        const noBtn = div.querySelector(Selectors.actions.confirmNo);
        const resultDiv = div.querySelector(Selectors.regions.confirmResult);
        const keywordInput = div.querySelector(Selectors.regions.confirmKeyword);
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
        // A paused voice conversation listens again.
        hooks.actionResolved();
    };

    renderInto(div, 'local_jedomi/local/sidebar/action_confirm', {
        issend: isSend,
        countbefore: countbefore || '',
        count: Number(pending.count || 0),
        countafter: countafter || '',
        // The whole message and data are shown (JEDOMI-SEC-013); cut data only lets the user cancel.
        preview: preview,
        description: pending.description || pending.tool || '',
        hasdetails: !!pending.details,
        details: pending.details || '',
        truncated: !!pending.truncated,
        critical: isCritical,
        keyword: confirmKeyword,
        strings: CS,
    }).then(() => {
        div.querySelector(Selectors.actions.confirmYes)?.addEventListener('click', () => resolve(true));
        div.querySelector(Selectors.actions.confirmNo)?.addEventListener('click', () => resolve(false));
        return null;
    }).catch(() => null);
    return div;
};

// Cards of the actions the server still holds for the user, each in its own answer bubble.
export const showRestoredActions = (cards) => {
    const msgs = g('jedomi-messages');
    if (!msgs || !cards.length) {
        return;
    }
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
