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
 * Suggestion of another plugin (local_jedomi/api) written in the message field of the Jedomi sidebar.
 *
 * @module     local_jedomi/local/sidebar/suggestion
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {g} from './dom';
import {openSidebar} from './panel';
import {state} from './state';

/**
 * Open the panel with a suggestion written in the message field, as plain text: the user can change it and sends it.
 * It is never sent here.
 *
 * @param {string} text Suggestion, already cleaned by local_jedomi/api.
 * @returns {boolean} Whether the suggestion was written (false without a message field or during a voice conversation).
 */
export const showSuggestion = (text) => {
    const input = g('jedomi-input');
    if (!input || state.voiceMode) {
        return false;
    }
    openSidebar();
    input.value = text;
    input.dispatchEvent(new Event('input'));
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
    return true;
};
