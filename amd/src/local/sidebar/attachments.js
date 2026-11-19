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
 * Files and image attached to the next message of the Jedomi chat.
 *
 * When the message is sent, Moodle sends the file to the AI service through the Datacurso provider and returns
 * its text (or the transcription of an audio file), which is added to the message with its pages, rows and
 * columns, characters or seconds. Until then the file stays in the browser.
 *
 * @module     local_jedomi/local/sidebar/attachments
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {g, renderInto} from './dom';
import {addMessage} from './messages';
import {cfg, state} from './state';

// Image types the file and image model reads (the server checks them again).
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

// Document extensions the server reads (the audio ones are AUDIO_EXTENSIONS); the server checks them again.
const FILE_EXTENSIONS = ['pdf', 'csv', 'txt', 'md', 'doc', 'docx', 'ppt', 'pptx'];

// The same audio extensions the server transcribes (audio_service::EXTENSIONS).
const AUDIO_EXTENSIONS = ['ogg', 'oga', 'opus', 'webm', 'mp3', 'm4a', 'mp4', 'wav'];

// Most files attached to one message.
const MAX_ATTACHMENTS = 5;

// Error codes of the message limit (the same for the chat and the file endpoints).
const RATE_LIMIT_CODES = ['rate_limited', 'rate_limit_busy'];

// Whether a file is above the attachment cap of the server: such a file is never uploaded.
const isOverUploadCap = (file) => !!cfg.max_upload_bytes && file.size > cfg.max_upload_bytes;

// Tell the user that a file was not attached because it is above the attachment cap.
const reportOverUploadCap = (file) => {
    const S = cfg.strings || {};
    addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.file_error_upload_too_large || ''}`);
};

// Message for a failed upload: a refusal of the message limit is shown as the chat shows it, without the file name.
export const uploadErrorMessage = (err, name) => {
    const S = cfg.strings || {};
    return RATE_LIMIT_CODES.includes(err.code)
        ? `⚠️ ${err.message}`
        : `⚠️ ${S.file_process_error || ''} **${name}**: ${err.message}`;
};

// Pages, rows and columns, characters or seconds of a read file, as shown with its name.
export const fileInfo = (data) => {
    const S = cfg.strings || {};
    return data.format === 'csv'
        ? (S.file_csv || '').replace('{rows}', data.rows).replace('{columns}', data.columns)
        : data.format === 'audio'
        ? (S.file_audio || '').replace('{seconds}', data.seconds)
        : data.format === 'pdf'
        ? (S.file_pdf || '').replace('{pages}', data.pages)
        : (S.file_text || '').replace('{chars}', data.chars);
};

// Attach the image chosen in the image input and show its preview.
export const handleImageSelect = (e) => {
    const file = e.target.files?.[0];
    if (!file) {
        return;
    }
    if (isOverUploadCap(file)) {
        reportOverUploadCap(file);
        e.target.value = '';
        return;
    }
    const reader = new FileReader();
    reader.onload = (ev) => {
        const dataUrl = ev.target.result;
        state.pendingImage = {base64: dataUrl.split(',')[1], mimeType: file.type || 'image/jpeg', file: file};
        const preview = g('jedomi-image-preview');
        const img = g('jedomi-preview-img');
        if (img) {
            img.src = dataUrl;
        }
        preview?.classList.remove('jedomi-hidden');
    };
    reader.readAsDataURL(file);
};

export const handleFileSelect = (e) => {
    const files = [...(e.target.files || [])];
    if (files.length) {
        attachFiles(files);
    }
};

// Attach files: only their chips are shown, like an image, so the textarea stays free for the question. Nothing is
// uploaded, read or charged until the message is sent; removing a chip before sending makes no request. A file of
// a kind that cannot be read, or beyond MAX_ATTACHMENTS, is not attached and the user is told why.
const attachFiles = (files) => {
    const S = cfg.strings || {};
    let overLimit = false;
    files.forEach((file) => {
        const extension = (file.name.split('.').pop() || '').toLowerCase();
        if (!FILE_EXTENSIONS.includes(extension) && !AUDIO_EXTENSIONS.includes(extension)) {
            addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.file_error_unsupported || ''}`);
        } else if (isOverUploadCap(file)) {
            reportOverUploadCap(file);
        } else if (state.pendingFiles.length >= MAX_ATTACHMENTS) {
            overLimit = true;
        } else {
            state.pendingFiles.push({file, name: file.name, extracted: null, controller: null});
        }
    });
    if (overLimit) {
        addMessage('assistant', `⚠️ ${(S.file_attach_limit || '').replace('{$a}', MAX_ATTACHMENTS)}`);
    }
    renderFileChips();
    if (state.pendingFiles.length) {
        g('jedomi-input')?.focus();
    }
    const fi = g('jedomi-file-input');
    if (fi) {
        fi.value = '';
    }
    const ai = g('jedomi-audio-input');
    if (ai) {
        ai.value = '';
    }
};

// Show a chip for each attached file, each one with its remove button.
const renderFileChips = () => {
    const preview = g('jedomi-file-preview');
    if (!preview) {
        return;
    }
    const S = cfg.strings || {};
    preview.classList.toggle('jedomi-hidden', !state.pendingFiles.length);
    if (!state.pendingFiles.length) {
        preview.replaceChildren();
        return;
    }
    renderInto(preview, 'local_jedomi/local/sidebar/file_chips', {
        files: state.pendingFiles.map((attachment, index) => ({
            index,
            name: attachment.name,
            removelabel: (S.file_remove || '').replace('{$a}', attachment.name),
        })),
    });
};

// Remove one attached file: a reading in progress is cancelled, so it is not charged.
export const removeFile = (index) => {
    const attachment = state.pendingFiles[index];
    if (!attachment) {
        return;
    }
    attachment.controller?.abort();
    state.pendingFiles.splice(index, 1);
    renderFileChips();
};

// Remove every attached file, cancelling any reading in progress.
export const clearFile = () => {
    state.pendingFiles.forEach((attachment) => attachment.controller?.abort());
    state.pendingFiles = [];
    renderFileChips();
};

export const clearImage = () => {
    state.pendingImage = null;
    g('jedomi-image-preview')?.classList.add('jedomi-hidden');
    const img = g('jedomi-preview-img');
    if (img) {
        img.src = '';
    }
    const input = g('jedomi-image-input');
    if (input) {
        input.value = '';
    }
};

// Attach the dropped files: the first readable image and up to MAX_ATTACHMENTS files. A file of a kind whose
// processing is off, an image of another type or a second image is not attached, and the user is told why.
export const handleDroppedFiles = (files) => {
    const S = cfg.strings || {};
    const documents = [];
    let image = null;
    let extraImages = false;
    for (const file of files) {
        const extension = (file.name.split('.').pop() || '').toLowerCase();
        const isaudio = AUDIO_EXTENSIONS.includes(extension);
        const isimage = (file.type || '').startsWith('image/');
        // Only the kinds whose feature is on are accepted; the server refuses the others too.
        if (isaudio && !cfg.audio_enabled) {
            addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.audio_error_disabled || ''}`);
        } else if (!isaudio && !cfg.files_enabled) {
            addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.files_error_disabled || ''}`);
        } else if (!isaudio && !isimage && !cfg.documents_enabled) {
            // The custom AI service reads images only.
            addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.documents_error_custom || ''}`);
        } else if (isimage && !IMAGE_TYPES.includes(file.type)) {
            addMessage('assistant', `⚠️ ${S.file_process_error || ''} **${file.name}**: ${S.image_error_unsupported || ''}`);
        } else if (isimage) {
            extraImages = extraImages || image !== null;
            image = image || file;
        } else {
            documents.push(file);
        }
    }
    if (image) {
        const dt = new DataTransfer();
        dt.items.add(image);
        const input = g('jedomi-image-input');
        if (input) {
            input.files = dt.files; input.dispatchEvent(new Event('change'));
        }
    }
    if (extraImages) {
        addMessage('assistant', `⚠️ ${S.image_attach_limit || ''}`);
    }
    if (documents.length) {
        attachFiles(documents);
    }
};
