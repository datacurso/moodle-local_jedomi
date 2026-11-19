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
 * Custom AI service of the Jedomi page in the Datacurso AI provider.
 *
 * Verifies the custom service with local_jedomi_verify_custom_service. A verified connection sets the hidden
 * custom_verified field, which shows the model fields of the custom service (hideIf of the form); changing the
 * address, the key or "Allow HTTP for local models" hides them again until the connection is verified again. The
 * server checks the verified pair on saving.
 *
 * A successful verification lists the ids of the models of the service under its message and offers them as
 * suggestions (one datalist) in the model fields, which stay free text. The ids come from an external service, so
 * they are only ever set as text or as DOM properties, never as HTML. It also fills an empty max tokens field with the
 * default, and shows the context length the service tells for the chosen chat model as the placeholder of the context
 * window field ("16384 (auto)"), updated while the chat model changes.
 *
 * The chat model and the typed context window go with the verification, which probes the model: its warnings (no tool
 * calling, no answer, no context window known) are shown under the result, as text only.
 *
 * @module     local_jedomi/admin/custom_service
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import {getString} from 'core/str';

/**
 * Tell the form dependencies (hideIf) that a field changed.
 *
 * @param {HTMLElement} field Field.
 */
const notifyChange = (field) => {
    field.dispatchEvent(new Event('change', {bubbles: true}));
};

/**
 * Checkbox (not the hidden input of an advcheckbox) of a form field.
 *
 * @param {HTMLFormElement} form Form.
 * @param {string} name Field name.
 * @returns {HTMLInputElement|null}
 */
const checkbox = (form, name) => form.querySelector(`input[type="checkbox"][name="${name}"]`);

/** @var {string} Id of the datalist with the model ids of the service. */
const DATALIST_ID = 'local_jedomi-custom-models';

/** @var {string[]} Model fields of the custom service that get the suggestions. */
const MODEL_FIELDS = ['custom_model', 'custom_vision_model', 'custom_audio_model', 'custom_speech_model'];

/**
 * Offer model ids as suggestions in the model fields, or remove the suggestions when there are none.
 *
 * @param {HTMLFormElement} form Form.
 * @param {string[]} ids Model ids.
 */
const suggestModels = (form, ids) => {
    let datalist = document.getElementById(DATALIST_ID);
    if (!datalist) {
        datalist = document.createElement('datalist');
        datalist.id = DATALIST_ID;
        form.append(datalist);
    }
    datalist.replaceChildren(...ids.map((id) => {
        const option = document.createElement('option');
        option.value = id;
        return option;
    }));
    MODEL_FIELDS.forEach((name) => {
        const field = form.querySelector(`input[name="${name}"]`);
        if (field && ids.length) {
            field.setAttribute('list', DATALIST_ID);
        } else if (field) {
            field.removeAttribute('list');
        }
    });
};

/**
 * List model ids under the result of a verification.
 *
 * @param {HTMLElement|null} region Result region.
 * @param {string[]} ids Model ids shown.
 * @param {number} total Number of valid model ids.
 */
const showModels = async(region, ids, total) => {
    if (!region || !ids.length) {
        return;
    }
    const box = document.createElement('div');
    box.className = 'small mt-2';
    box.style.maxHeight = '12rem';
    box.style.overflowY = 'auto';
    const title = document.createElement('div');
    title.textContent = await getString('custom_verify_models', 'local_jedomi');
    const list = document.createElement('ul');
    list.className = 'list-inline mb-0';
    ids.forEach((id) => {
        const item = document.createElement('li');
        item.className = 'list-inline-item';
        const code = document.createElement('code');
        code.textContent = id;
        item.append(code);
        list.append(item);
    });
    box.append(title, list);
    if (ids.length < total) {
        const shown = document.createElement('div');
        shown.textContent = await getString('custom_verify_models_shown', 'local_jedomi', {shown: ids.length, total});
        box.append(shown);
    }
    region.append(box);
};

/**
 * Show the context length detected for the chosen chat model as the placeholder of the context window field.
 *
 * @param {HTMLFormElement} form Form.
 * @param {Map<string, number>|null} lengths Context length of each model id of the last verification on the page; null
 *     before any, so the placeholder of the saved settings stays.
 */
const showContextWindow = async(form, lengths) => {
    const field = form.querySelector('input[name="custom_context_window"]');
    const model = form.querySelector('input[name="custom_model"]');
    if (!field || !model || lengths === null) {
        return;
    }
    const tokens = lengths.get(model.value.trim());
    field.placeholder = tokens ? await getString('custom_context_window_detected', 'local_jedomi', tokens) : '';
};

/**
 * Show the result of a verification.
 *
 * @param {HTMLElement|null} region Result region.
 * @param {string} message Message.
 * @param {string} type Bootstrap alert type: info, success or danger.
 */
const showStatus = (region, message, type) => {
    if (!region) {
        return;
    }
    region.textContent = '';
    if (!message) {
        return;
    }
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} mb-0 mt-2`;
    alert.textContent = message;
    region.append(alert);
};

/**
 * Show the warnings of a verification under its result.
 *
 * @param {HTMLElement|null} region Result region.
 * @param {Array<{code: string, message: string}>} warnings Warnings.
 */
const showWarnings = (region, warnings) => {
    if (!region) {
        return;
    }
    warnings.forEach(({message}) => {
        const alert = document.createElement('div');
        alert.className = 'alert alert-warning mb-0 mt-2';
        alert.textContent = message;
        region.append(alert);
    });
};

/**
 * Wire the verification button of the form.
 *
 * @param {Object} config Configuration.
 * @param {number} config.defaultmaxtokens Default max tokens, set in an empty max tokens field after a verification; at
 *     most half the context window detected for the chosen chat model, so it fits next to the conversation.
 * @param {number} config.minmaxtokens Smallest max tokens accepted.
 */
export const init = ({defaultmaxtokens, minmaxtokens = 0} = {}) => {
    const button = document.querySelector('[name="custom_verify"]');
    const form = button?.closest('form');
    if (!form) {
        return;
    }
    let lengths = null;
    form.querySelector('input[name="custom_model"]')?.addEventListener('input', () => showContextWindow(form, lengths));
    const verified = form.querySelector('input[type="hidden"][name="custom_verified"]');
    const region = form.querySelector('[data-region="local_jedomi-custom-verify-status"]');
    const url = form.querySelector('[name="custom_gateway_url"]');
    const key = form.querySelector('[name="custom_api_key"]');
    const allowHttp = checkbox(form, 'custom_allow_http');

    const setVerified = (value) => {
        if (verified && verified.value !== value) {
            verified.value = value;
            notifyChange(verified);
        }
    };

    // A changed address, key or HTTP option has to be verified again.
    [url, key, allowHttp].forEach((field) => {
        field?.addEventListener('input', () => {
            setVerified('0');
            showStatus(region, '', 'info');
            suggestModels(form, []);
        });
        field?.addEventListener('change', () => setVerified('0'));
    });

    button.addEventListener('click', async() => {
        button.disabled = true;
        showStatus(region, await getString('custom_verify_checking', 'local_jedomi'), 'info');
        try {
            const result = await fetchMany([{
                methodname: 'local_jedomi_verify_custom_service',
                args: {
                    url: url?.value || '',
                    apikey: key?.value || '',
                    allowhttp: !!allowHttp?.checked,
                    model: form.querySelector('input[name="custom_model"]')?.value.trim() || '',
                    contextwindow: parseInt(form.querySelector('input[name="custom_context_window"]')?.value || '', 10) || 0,
                },
            }])[0];
            setVerified(result.valid ? '1' : '0');
            showStatus(region, result.message, result.valid ? 'success' : 'danger');
            showWarnings(region, result.valid ? (result.warnings || []) : []);
            const ids = result.valid ? result.models : [];
            suggestModels(form, ids);
            await showModels(region, ids, result.modeltotal);
            if (result.valid) {
                lengths = new Map((result.contextlengths || []).map(({id, tokens}) => [id, tokens]));
                const maxtokens = form.querySelector('input[name="custom_max_tokens"]');
                if (maxtokens && !maxtokens.value.trim() && defaultmaxtokens) {
                    const typed = parseInt(form.querySelector('input[name="custom_context_window"]')?.value || '', 10);
                    const model = form.querySelector('input[name="custom_model"]')?.value.trim() || '';
                    const window = typed > 0 ? typed : (lengths.get(model) || 0);
                    const fitting = window > 0 ? Math.max(minmaxtokens, Math.min(defaultmaxtokens, Math.floor(window / 2))) : 0;
                    maxtokens.value = String(fitting || defaultmaxtokens);
                }
                await showContextWindow(form, lengths);
            }
        } catch (error) {
            setVerified('0');
            showStatus(region, error.message || '', 'danger');
            suggestModels(form, []);
        } finally {
            button.disabled = false;
        }
    });
};
