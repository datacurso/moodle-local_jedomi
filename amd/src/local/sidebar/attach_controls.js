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
 * Controls to attach files to the Jedomi chat: the drop zone and the attach menu.
 *
 * @module     local_jedomi/local/sidebar/attach_controls
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {handleDroppedFiles} from './attachments';
import {g} from './dom';
import {state} from './state';

// Show the drop zone while files are dragged over the panel, and attach the dropped files.
export const initDropZone = () => {
    const sidebar = g('jedomi-sidebar');
    const zone = g('jedomi-dropzone');
    if (!sidebar || !zone) {
        return;
    }

    sidebar.addEventListener('dragenter', (e) => {
        e.preventDefault(); e.stopPropagation();
        state.dropCounter++;
        zone.classList.remove('jedomi-hidden');
    });
    sidebar.addEventListener('dragover', (e) => {
        e.preventDefault(); e.stopPropagation();
    });
    sidebar.addEventListener('dragleave', (e) => {
        e.preventDefault(); e.stopPropagation();
        state.dropCounter--;
        if (state.dropCounter <= 0) {
            state.dropCounter = 0; zone.classList.add('jedomi-hidden');
        }
    });
    sidebar.addEventListener('drop', (e) => {
        e.preventDefault(); e.stopPropagation();
        state.dropCounter = 0;
        zone.classList.add('jedomi-hidden');
        const files = e.dataTransfer?.files;
        if (!files || files.length === 0) {
            return;
        }
        handleDroppedFiles(files);
    });
};

// Open or close the attach menu.
export const toggleAttachMenu = () => {
    const menu = g('jedomi-attach-menu');
    if (!menu) {
        return;
    }
    menu.classList.toggle('jedomi-hidden');
    g('jedomi-attach-btn')?.setAttribute('aria-expanded', menu.classList.contains('jedomi-hidden') ? 'false' : 'true');
    if (!menu.classList.contains('jedomi-hidden')) {
        setTimeout(() => document.addEventListener('click', closeAttachOutside), 10);
    }
};

// Close the attach menu.
export const closeAttachMenu = () => {
    g('jedomi-attach-menu')?.classList.add('jedomi-hidden');
    g('jedomi-attach-btn')?.setAttribute('aria-expanded', 'false');
    document.removeEventListener('click', closeAttachOutside);
};

// Close the attach menu on a click outside it.
const closeAttachOutside = (e) => {
    if (!e.target.closest('.jedomi-attach-wrap')) {
        closeAttachMenu();
    }
};
