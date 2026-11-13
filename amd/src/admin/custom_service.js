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
 * Wire the verification button of the form.
 */
export const init = () => {
    const button = document.querySelector('[name="custom_verify"]');
    const form = button?.closest('form');
    if (!form) {
        return;
    }
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
        });
        field?.addEventListener('change', () => setVerified('0'));
    });

    button.addEventListener('click', async() => {
        button.disabled = true;
        showStatus(region, await getString('custom_verify_checking', 'local_jedomi'), 'info');
        try {
            const result = await fetchMany([{
                methodname: 'local_jedomi_verify_custom_service',
                args: {url: url?.value || '', apikey: key?.value || '', allowhttp: !!allowHttp?.checked},
            }])[0];
            setVerified(result.valid ? '1' : '0');
            showStatus(region, result.message, result.valid ? 'success' : 'danger');
        } catch (error) {
            setVerified('0');
            showStatus(region, error.message || '', 'danger');
        } finally {
            button.disabled = false;
        }
    });
};
