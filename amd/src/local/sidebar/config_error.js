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
 * Notice of the Jedomi sidebar shown when its configuration cannot be loaded.
 *
 * @module     local_jedomi/local/sidebar/config_error
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {state} from './state';

// Notice shown instead of the panel when its configuration cannot be loaded, with a button to try again. It is
// built without templates: the server may be failing. retry(config) starts the panel again.
export const showConfigError = async(config, retry) => {
    let strings = config?.strings || {};
    if (!strings.config_load_error) {
        try {
            const [message, retryLabel] = await getStrings([
                {key: 'config_load_error', component: 'local_jedomi'},
                {key: 'config_load_retry', component: 'local_jedomi'},
            ]);
            strings = {config_load_error: message, config_load_retry: retryLabel};
        } catch (error) {
            strings = {config_load_error: '', config_load_retry: ''};
        }
    }
    ['jedomi-trigger', 'jedomi-sidebar'].forEach((id) => document.getElementById(id)?.remove());

    const trigger = document.createElement('button');
    trigger.id = 'jedomi-trigger';
    trigger.className = 'jedomi-trigger';
    trigger.setAttribute('aria-label', 'Jedomi');
    const label = document.createElement('span');
    label.className = 'jedomi-trigger-label';
    label.textContent = 'Jedomi';
    trigger.appendChild(label);

    const panel = document.createElement('div');
    panel.id = 'jedomi-sidebar';
    panel.className = 'jedomi-sidebar jedomi-closed jedomi-config-error';
    panel.setAttribute('role', 'complementary');
    panel.setAttribute('aria-label', 'Jedomi');
    const alert = document.createElement('div');
    alert.className = 'alert alert-warning m-3';
    alert.setAttribute('role', 'alert');
    const text = document.createElement('p');
    text.textContent = strings.config_load_error;
    const retryButton = document.createElement('button');
    retryButton.type = 'button';
    retryButton.className = 'btn btn-secondary';
    retryButton.textContent = strings.config_load_retry;
    alert.append(text, retryButton);
    panel.appendChild(alert);

    trigger.addEventListener('click', () => {
        const open = panel.classList.toggle('jedomi-open');
        panel.classList.toggle('jedomi-closed', !open);
        if (open) {
            retryButton.focus();
        }
    });
    retryButton.addEventListener('click', () => {
        state.retryOpen = true;
        trigger.remove();
        panel.remove();
        retry(config);
    });
    document.body.append(trigger, panel);
    if (state.retryOpen) {
        trigger.click();
    }
};
