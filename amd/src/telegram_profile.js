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
 * Telegram profile page: link code countdown and unlink confirmation.
 *
 * @module     local_jedomi/telegram_profile
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import 'core/copy_to_clipboard';
import Notification from 'core/notification';
import {getString, getStrings} from 'core/str';
import Selectors from 'local_jedomi/local/telegram_profile/selectors';

/**
 * Update the countdown every second until the code expires.
 *
 * @param {HTMLElement} element Countdown element with data-expiry (Unix time).
 */
const startCountdown = async(element) => {
    const expiry = parseInt(element.dataset.expiry, 10) * 1000;
    const expired = await getString('telegram_code_expired', 'local_jedomi');
    const tick = () => {
        const remaining = Math.max(0, expiry - Date.now());
        if (remaining === 0) {
            element.textContent = expired;
            element.classList.add('text-danger');
            return;
        }
        const minutes = Math.floor(remaining / 60000);
        const seconds = Math.floor((remaining % 60000) / 1000);
        element.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
        setTimeout(tick, 1000);
    };
    tick();
};

/**
 * Ask for confirmation before the unlink form is submitted.
 *
 * @param {HTMLFormElement} form Unlink form.
 */
const confirmUnlink = async(form) => {
    const [title, question] = await getStrings([
        {key: 'unlink', component: 'local_jedomi'},
        {key: 'areyousure', component: 'core'},
    ]);
    try {
        await Notification.deleteCancelPromise(title, question, title);
    } catch (error) {
        return;
    }
    form.dataset.confirmed = '1';
    form.submit();
};

/**
 * Initialise the page.
 */
export const init = () => {
    const countdown = document.querySelector(Selectors.countdown);
    if (countdown) {
        startCountdown(countdown);
    }
    document.addEventListener('submit', (e) => {
        const form = e.target.closest(Selectors.unlinkForm);
        if (form && form.dataset.confirmed !== '1') {
            e.preventDefault();
            confirmUnlink(form);
        }
    });
};
