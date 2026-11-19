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
 * Events of the Jedomi sidebar: its buttons, fields and keyboard shortcuts.
 *
 * @module     local_jedomi/local/sidebar/events
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {closeAttachMenu, initDropZone, toggleAttachMenu} from './attach_controls';
import {clearImage, handleFileSelect, handleImageSelect, removeFile} from './attachments';
import {stopAnswer} from './chat';
import {sendMessage} from './composer';
import {clearChat, confirmNewConversation, exportConversation} from './conversation';
import {downloadTableCsv} from './csv';
import {g, on} from './dom';
import {editLastMessage} from './messages';
import {closeSidebar, isInCoreModal, openSidebar, setExpandMode, trapFocus} from './panel';
import Selectors from './selectors';
import {state} from './state';
import {handleVoiceAction, startRecording} from './voice_message';
import {exitVoiceMode, startVoiceMode} from './voice_mode';

// Wire the events of the panel, once it is in the page.
export const wireUp = () => {
    on('jedomi-trigger', 'click', openSidebar);
    g('jedomi-input')?.closest(Selectors.actions.focusInput)
        ?.addEventListener('click', () => g('jedomi-input')?.focus());
    initDropZone();
    on('jedomi-close', 'click', closeSidebar);
    on('jedomi-clear', 'click', clearChat);
    on('jedomi-export', 'click', exportConversation);
    on('jedomi-expand-half', 'click', () => setExpandMode(state.expandMode === 'half' ? 'normal' : 'half'));
    on('jedomi-expand-full', 'click', () => setExpandMode(state.expandMode === 'full' ? 'normal' : 'full'));
    on('jedomi-thinking-toggle-btn', 'click', () => {
        g('jedomi-thinking-wrapper')?.classList.toggle('jedomi-thinking-expanded');
    });

    // CSV download through event delegation (avoids Moodle's CSP).
    const msgs = g('jedomi-messages');
    if (msgs) {
        msgs.addEventListener('click', (e) => {
            const btn = e.target.closest(Selectors.actions.csvDownload);
            if (!btn) {
                return;
            }
            downloadTableCsv(btn);
        });
    }
    on('jedomi-edit-last', 'click', editLastMessage);
    on('jedomi-send', 'click', sendMessage);
    on('jedomi-stop', 'click', stopAnswer);
    on('jedomi-remove-image', 'click', clearImage);
    on('jedomi-file-preview', 'click', (e) => {
        const btn = e.target.closest(Selectors.actions.removeFile);
        if (btn) {
            removeFile(parseInt(btn.getAttribute('data-index'), 10));
        }
    });
    on('jedomi-edit-last', 'click', editLastMessage);
    on('jedomi-attach-btn', 'click', toggleAttachMenu);
    on('jedomi-attach-menu', 'click', (e) => {
        const btn = e.target.closest('.jedomi-attach-option');
        if (!btn) {
            return;
        }
        const type = btn.getAttribute('data-type');
        if (type === 'image') {
            const inp = g('jedomi-image-input');
            if (inp) {
                inp.click();
            }
        } else if (type === 'audio') {
            const inp = g('jedomi-audio-input');
            if (inp) {
                inp.click();
            }
        } else {
            const inp = g('jedomi-file-input');
            if (inp) {
                inp.click();
            }
        }
        closeAttachMenu();
    });
    on('jedomi-image-input', 'change', handleImageSelect);
    on('jedomi-file-input', 'change', handleFileSelect);
    on('jedomi-audio-input', 'change', handleFileSelect);
    on('jedomi-voice-btn', 'click', startRecording);
    on('jedomi-voice-bar', 'click', handleVoiceAction);
    on('jedomi-voice-mode-btn', 'click', startVoiceMode);
    on('jedomi-voice-mode', 'click', (e) => {
        if (e.target.closest(Selectors.actions.voiceModeExit)) {
            exitVoiceMode();
        }
    });

    const input = g('jedomi-input');
    if (input) {
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        input.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    }

    // While the panel is open the focus stays in it: Tab and Shift+Tab go round its controls.
    g('jedomi-sidebar')?.addEventListener('keydown', trapFocus);

    // Keyboard shortcuts. Alt+Shift is used because Ctrl+Shift+J and Ctrl+Shift+N are browser shortcuts (console
    // and private window). The physical key (code) is checked, as Alt changes the character on some keyboards.
    document.addEventListener('keydown', (e) => {
        // A key pressed in a core modal (for example the new conversation confirmation) belongs to that modal:
        // its Escape only closes the modal, not the sidebar.
        if (isInCoreModal(e.target)) {
            return;
        }
        // Escape → end the voice conversation first, then close the sidebar.
        if (e.key === 'Escape' && (state.voiceMode || state.voiceModeStarting)) {
            exitVoiceMode();
            return;
        }
        if (e.key === 'Escape' && g('jedomi-sidebar')?.classList.contains('jedomi-open')) {
            closeSidebar();
            return;
        }
        if (!e.altKey || !e.shiftKey || e.ctrlKey || e.metaKey) {
            return;
        }
        // Alt+Shift+J → open/close Jedomi.
        if (e.code === 'KeyJ') {
            e.preventDefault();
            const isOpen = g('jedomi-sidebar')?.classList.contains('jedomi-open');
            if (isOpen) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }
        // Alt+Shift+N → new conversation, after a confirmation when there is a conversation to lose.
        if (e.code === 'KeyN') {
            e.preventDefault();
            openSidebar();
            confirmNewConversation();
        }
    });
};
