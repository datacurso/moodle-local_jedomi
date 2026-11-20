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
 * Copy and rating buttons under each Jedomi answer, and the rating sent to the server.
 *
 * @module     local_jedomi/local/sidebar/feedback
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';
import {renderInto} from './dom';
import Selectors from './selectors';
import {cfg, state} from './state';

// Action buttons under each assistant answer. messageId identifies the answer: rating it again, with the same or
// the other value, changes its rating instead of adding another one.
export const addMessageActions = async(wrap, text, messageId = '') => {
    const actions = document.createElement('div');
    actions.className = 'jedomi-msg-actions';
    // Inside the bubble (inner), not outside (wrap).
    const bubbleInner = wrap.querySelector('.jedomi-bubble-inner, .jedomi-bubble');
    (bubbleInner || wrap).appendChild(actions);
    await renderInto(actions, 'local_jedomi/local/sidebar/message_actions', {strings: cfg.strings || {}});

    const copy = actions.querySelector(Selectors.actions.copy);
    copy?.addEventListener('click', () => {
        navigator.clipboard.writeText(text).then(() => {
            copy.classList.add('jedomi-action-done');
            setTimeout(() => copy.classList.remove('jedomi-action-done'), 1500);
            return null;
        }).catch(() => null);
    });
    const good = actions.querySelector(Selectors.actions.good);
    const bad = actions.querySelector(Selectors.actions.bad);
    // Only one rating is shown as given: the last one.
    const showRating = (rating) => {
        good?.classList.toggle('jedomi-feedback-good', rating === 'good');
        good?.setAttribute('aria-pressed', rating === 'good' ? 'true' : 'false');
        bad?.classList.toggle('jedomi-feedback-bad', rating === 'bad');
        bad?.setAttribute('aria-pressed', rating === 'bad' ? 'true' : 'false');
    };
    good?.addEventListener('click', () => {
        showRating('good');
        actions.querySelector('.jedomi-feedback-comment')?.remove();
        saveFeedback(text, 'good', messageId);
    });
    bad?.addEventListener('click', async() => {
        showRating('bad');
        saveFeedback(text, 'bad', messageId);
        if (actions.querySelector('.jedomi-feedback-comment')) {
            return;
        }
        // "Improve" offers an optional comment, saved with the same rating.
        const holder = document.createElement('div');
        holder.className = 'jedomi-feedback-comment';
        actions.appendChild(holder);
        await renderInto(holder, 'local_jedomi/local/sidebar/feedback_comment', {
            uniqid: `jedomi-comment-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`,
            strings: cfg.strings || {},
        });
        const field = holder.querySelector(Selectors.regions.feedbackCommentText);
        field?.focus();
        holder.querySelector(Selectors.actions.feedbackCommentSend)?.addEventListener('click', () => {
            const comment = (field?.value || '').trim();
            if (!comment) {
                return;
            }
            saveFeedback(text, 'bad', messageId, comment);
            const thanks = document.createElement('p');
            thanks.className = 'jedomi-feedback-comment small mb-0';
            thanks.setAttribute('role', 'status');
            thanks.textContent = cfg.strings?.feedback_comment_saved || '';
            holder.replaceWith(thanks);
        });
    });
};

const saveFeedback = async(text, rating, messageId = '', comment = null) => {
    const args = {rating, sessionid: state.sessionId || '', messagetext: text.slice(0, 1000), messageid: messageId};
    if (comment !== null) {
        args.comment = comment;
    }
    try {
        await Ajax.call([{methodname: 'local_jedomi_save_feedback', args}])[0];
    } catch (e) {
        Log.debug('Jedomi: feedback not saved', e);
    }
};
