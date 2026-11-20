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
 * Message field of the Jedomi chat: sends the question with its attached files and image.
 *
 * @module     local_jedomi/local/sidebar/composer
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {uploadForText} from './api';
import {clearFile, clearImage, fileInfo, uploadErrorMessage} from './attachments';
import {runChat} from './chat';
import {g} from './dom';
import {addMessage} from './messages';
import {cfg, state} from './state';

// Mark the message as being prepared while a file is read: the send button is disabled and the message field shows
// placeholder. Returns the function that ends it.
const startReading = (input, sendBtn, placeholder) => {
    state.sending = true;
    if (sendBtn) {
        sendBtn.disabled = true;
    }
    const origPlaceholder = input?.placeholder;
    if (input) {
        input.placeholder = placeholder;
    }
    return () => {
        state.sending = false;
        if (sendBtn) {
            sendBtn.disabled = false;
        }
        if (input && origPlaceholder) {
            input.placeholder = origPlaceholder;
        }
    };
};

// Read the text of an attached file and keep it on the attachment. Resolves to false when it could not be read: the
// reading was cancelled, or it failed and the user was told.
const readAttachment = async(attachment, input, sendBtn) => {
    const S = cfg.strings || {};
    const endReading = startReading(input, sendBtn, (S.processing_file || '').replace('{$a}', attachment.name));
    attachment.controller = new AbortController();
    try {
        const data = await uploadForText(cfg.extract_file_url, attachment.file, {}, attachment.controller.signal);
        // The chat model gets only the name and text of the file between untrusted data markers, so it never
        // follows what the file says. An answer without them is an error: the text is never sent unwrapped.
        if (typeof data.untrusted_text !== 'string' || data.untrusted_text === '') {
            throw new Error(S.unknown_error || '');
        }
        attachment.extracted = {info: fileInfo(data), text: data.untrusted_text};
        if (data.truncated) {
            addMessage('assistant', `⚠️ ${(S.file_truncated || '').replace('{$a}', attachment.name)}`);
        }
        return true;
    } catch (err) {
        if (err?.name === 'AbortError') {
            return false;
        }
        // The file stays attached, so the user can try again or remove it.
        addMessage('assistant', uploadErrorMessage(err, attachment.name));
        return false;
    } finally {
        attachment.controller = null;
        endReading();
    }
};

// Read the attached files that were not read yet. The attached files are read now, when the message is sent, as
// images are: a file removed before sending is never read nor charged, and removing it while it is read cancels its
// reading. The text of each one is kept on its attachment, so a retry after another error reads nothing again.
// Resolves to false when the message must not be sent.
const readAttachments = async(attachments, input, sendBtn) => {
    for (const attachment of attachments) {
        if (attachment.extracted) {
            continue;
        }
        if (!await readAttachment(attachment, input, sendBtn)) {
            return false;
        }
        // Removed while it was being read: the message is not sent, so the user can review it.
        if (!state.pendingFiles.includes(attachment)) {
            return false;
        }
    }
    // Removed or added while the others were read: the message is not sent, so the user can review it.
    return attachments.length === state.pendingFiles.length && attachments.every((a, i) => state.pendingFiles[i] === a);
};

// Read the attached image with the vision model: the chat model only gets the question and that text. Resolves to
// that text, or to null when it could not be read and the user was told.
const readImage = async(question, input, sendBtn) => {
    const S = cfg.strings || {};
    const endReading = startReading(input, sendBtn, S.reading_image || '');
    try {
        const data = await uploadForText(cfg.read_file_url, state.pendingImage.file, {question});
        return data.message || '';
    } catch (err) {
        // The image stays attached, so the user can try again.
        addMessage('assistant', uploadErrorMessage(err, state.pendingImage.file.name));
        return null;
    } finally {
        endReading();
    }
};

// Send the question of the message field, with the text of its attached files and image.
export const sendMessage = async() => {
    const input = g('jedomi-input');
    const sendBtn = g('jedomi-send');
    const text = input?.value.trim() || '';

    if ((!text && !state.pendingImage && !state.pendingFiles.length) || state.sending) {
        return;
    }

    const S = cfg.strings || {};
    let displayText = text;
    let modelText = text;

    const attachments = state.pendingFiles.slice();
    if (!await readAttachments(attachments, input, sendBtn)) {
        return;
    }

    // The image is read by the vision model first: the chat model only gets the question and that text.
    if (state.pendingImage) {
        modelText = await readImage(text, input, sendBtn);
        if (modelText === null) {
            return;
        }
    }

    // The text of each file goes with the question in the same message; the chat shows only their names.
    if (attachments.length) {
        const question = modelText || S.analyze_file || '';
        // The name of each file is inside its untrusted block, never outside it.
        const parts = attachments.map(({extracted}) => extracted.text);
        modelText = [question, ...parts].join('\n\n');
        const names = attachments.map(({name, extracted}) => `📎 ${name} (${extracted.info})`).join('\n');
        displayText = `${text ? text + '\n\n' : ''}${names}`;
    }

    const image = state.pendingImage;
    if (input) {
        input.value = '';
        input.style.height = '';
    }
    clearImage();
    clearFile();
    await runChat(displayText, modelText, image);
};
