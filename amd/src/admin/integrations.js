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
 * Integrations tab of the Jedomi settings: Telegram switch, webhook check, pending updates and secret regeneration.
 *
 * @module     local_jedomi/admin/integrations
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import 'core/copy_to_clipboard';
import Notification from 'core/notification';
import Pending from 'core/pending';
import Templates from 'core/templates';
import {getString, getStrings} from 'core/str';
import Selectors from 'local_jedomi/local/admin/selectors';
import * as Repository from 'local_jedomi/local/admin/repository';

/** @type {boolean} Whether the listeners are registered. */
let registered = false;

/**
 * Escape text for a notification, which is shown as HTML.
 *
 * @param {string} text Plain text.
 * @returns {string}
 */
const escapeText = (text) => {
    const holder = document.createElement('div');
    holder.textContent = text;
    return holder.innerHTML;
};

/**
 * Show or hide the busy label of an action button.
 *
 * @param {HTMLButtonElement} button Action button.
 * @param {boolean} busy Whether the action is running.
 */
const setBusy = (button, busy) => {
    button.disabled = busy;
    button.querySelector(Selectors.integrations.buttonLabel)?.classList.toggle('d-none', busy);
    button.querySelector(Selectors.integrations.buttonBusy)?.classList.toggle('d-none', !busy);
};

/**
 * Reflect the enabled state on the card: the body is shown only while the integration is enabled.
 *
 * @param {HTMLElement} card Integration card.
 * @param {boolean} enabled Whether the integration is enabled.
 */
const setEnabledState = (card, enabled) => {
    card.querySelector(Selectors.integrations.body).classList.toggle('d-none', !enabled);
};

/**
 * Save the Telegram switch at once; on failure put the switch back and show an error.
 *
 * @param {HTMLInputElement} input Switch checkbox.
 */
const toggleTelegram = async(input) => {
    const card = input.closest(Selectors.integrations.card);
    const enabled = input.checked;
    const pending = new Pending('local_jedomi/admin/integrations:toggle');
    input.disabled = true;
    setEnabledState(card, enabled);
    try {
        const result = await Repository.setTelegramEnabled(enabled);
        input.checked = !!result.enabled;
    } catch (error) {
        input.checked = !enabled;
        let message = await getString('telegram_enabled_save_failed', 'local_jedomi');
        if (error && error.message) {
            message += ' (' + escapeText(error.message) + ')';
        }
        Notification.addNotification({message, type: 'error'});
    }
    setEnabledState(card, input.checked);
    input.disabled = false;
    pending.resolve();
};

/**
 * Check the webhook with getWebhookInfo and show the result.
 *
 * @param {HTMLButtonElement} button Check button.
 */
const checkWebhook = async(button) => {
    const live = button.closest(Selectors.integrations.card).querySelector(Selectors.integrations.webhookLive);
    const pending = new Pending('local_jedomi/admin/integrations:check');
    setBusy(button, true);
    live.classList.add('d-none');
    let context;
    try {
        const data = await Repository.getWebhookStatus();
        if (data.ok) {
            const stored = data.stored_error || {};
            const telegramerror = data.last_error_message || '';
            const pendingcount = parseInt(data.pending_update_count, 10) || 0;
            context = {
                ok: true,
                url: data.url || '',
                pending: pendingcount,
                haspending: pendingcount > 0,
                telegramerror,
                storederror: stored.message || '',
                storederrortime: parseInt(stored.time, 10) || 0,
                healthy: !telegramerror && !stored.message,
            };
        } else {
            context = {ok: false, error: data.error || await getString('webhook_live_unknown_error', 'local_jedomi')};
        }
    } catch (error) {
        context = {ok: false, networkerror: true, error: error.message};
    }
    try {
        const {html, js} = await Templates.renderForPromise('local_jedomi/admin/webhook_live_status', context);
        Templates.replaceNodeContents(live, html, js);
        live.classList.remove('d-none');
    } catch (error) {
        Notification.exception(error);
    }
    setBusy(button, false);
    pending.resolve();
};

/**
 * Drop the pending Telegram updates and show the result.
 *
 * @param {HTMLButtonElement} button Drop button.
 */
const dropPending = async(button) => {
    const result = button.closest(Selectors.integrations.card).querySelector(Selectors.integrations.dropPendingResult);
    const pending = new Pending('local_jedomi/admin/integrations:drop');
    setBusy(button, true);
    result.classList.add('d-none');
    let context;
    try {
        const data = await Repository.dropPendingUpdates();
        context = {success: !!data.ok, error: data.error || ''};
    } catch (error) {
        context = {success: false, error: error.message};
    }
    try {
        const {html, js} = await Templates.renderForPromise('local_jedomi/admin/drop_pending_result', context);
        Templates.replaceNodeContents(result, html, js);
        result.classList.remove('d-none');
    } catch (error) {
        Notification.exception(error);
    }
    setBusy(button, false);
    pending.resolve();
};

/**
 * Ask for confirmation before the secret regeneration submits the form.
 *
 * @param {HTMLInputElement} button Regenerate submit button.
 */
const confirmRegenerate = async(button) => {
    const [title, question] = await getStrings([
        {key: 'regenerate', component: 'local_jedomi'},
        {key: 'telegram_regenerate_confirm', component: 'local_jedomi'},
    ]);
    try {
        await Notification.saveCancelPromise(title, question, title);
    } catch (error) {
        return;
    }
    button.dataset.confirmed = '1';
    button.click();
};

/**
 * Register the delegated listeners of the tab.
 */
const registerEventListeners = () => {
    document.addEventListener('change', (e) => {
        const toggle = e.target.closest(Selectors.integrations.toggle);
        if (toggle) {
            toggleTelegram(toggle);
        }
    });

    document.addEventListener('click', (e) => {
        const check = e.target.closest(Selectors.integrations.checkWebhook);
        if (check) {
            checkWebhook(check);
            return;
        }
        const drop = e.target.closest(Selectors.integrations.dropPending);
        if (drop) {
            dropPending(drop);
            return;
        }
        const regenerate = e.target.closest(Selectors.integrations.regenerateSecret);
        if (regenerate && !regenerate.dataset.confirmed) {
            e.preventDefault();
            confirmRegenerate(regenerate);
        }
    });
};

/**
 * Initialise the Integrations tab.
 */
export const init = () => {
    if (registered) {
        return;
    }
    registered = true;
    registerEventListeners();
};
