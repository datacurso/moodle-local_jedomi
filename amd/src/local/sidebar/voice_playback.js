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
 * Spoken answers of the Jedomi voice conversation.
 *
 * The answer is read aloud through speech.php: a short first chunk, then the next sentences grouped in longer chunks,
 * each requested while the one before plays, and the full text stays in the chat.
 *
 * @module     local_jedomi/local/sidebar/voice_playback
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import {addMessage} from './messages';
import {ensureFreshPermissions, renewPermissions} from './permissions';
import {cfg, hooks, state} from './state';
import {isVoiceRefusal, speakableText, voiceRefusalMessage, voiceSpeechErrorReason} from './voice_text';
import {setVoiceModeState} from './voice_view';

// Longest text sent to speech.php at once, in characters (the server accepts up to 1000).
const VOICE_MODE_MAX_SENTENCE = 900;

/**
 * Longest and shortest first chunk of an answer, in characters. Each chunk is one request to speech.php (and one
 * charge). The first chunk groups whole sentences until it reaches the minimum or the answer ends, so a short
 * answer is read with one request. A first sentence longer than the maximum is cut at a clause or a space, so
 * Jedomi does not wait for the audio of a long text before it starts speaking.
 *
 * @type {number}
 */
const VOICE_MODE_FIRST_CHUNK_MAX = 160;

const VOICE_MODE_FIRST_CHUNK_MIN = 80;

/**
 * Characters a chunk read aloud after the first one may reach by grouping whole sentences. A sentence is never
 * split to fit: a longer one goes alone (cut only above VOICE_MODE_MAX_SENTENCE). The group is sent when the
 * chunk before it starts playing (its audio is ready, so the next one is prepared while it is heard), when it
 * reaches this size, when the answer ends, before a notice of Jedomi, or at once when nothing is being read or
 * waiting to be read, so Jedomi never goes silent.
 *
 * @type {number}
 */
const VOICE_MODE_GROUP_TARGET = 400;

// Queue the sentences of a line that is complete; code blocks and table rows are not read aloud.
const speakLine = (line) => {
    const current = state.voiceMode;
    if (/^\s*(```|~~~)/.test(line)) {
        current.inCodeBlock = !current.inCodeBlock;
        return;
    }
    if (current.inCodeBlock || /^\s*\|/.test(line)) {
        return;
    }
    // A stop followed by a space ends a sentence, so decimals such as 15.38 are kept whole.
    speakableText(line).replace(/([.!?…]+["')\]]*)\s+/g, '$1\n').split('\n')
        .forEach(sentence => queueVoiceSentence(sentence));
};

// Split the answer received so far into sentences and queue the complete ones. final flushes the rest.
export const speakCompleteSentences = (final) => {
    const current = state.voiceMode;
    if (!current) {
        return;
    }
    let text = current.pendingText;
    let newline = text.indexOf('\n');
    while (newline !== -1) {
        speakLine(text.slice(0, newline));
        text = text.slice(newline + 1);
        newline = text.indexOf('\n');
    }
    if (final) {
        speakLine(text);
        text = '';
        // The answer ended: the sentences still grouped are read now.
        flushVoiceGroup();
        playNextVoiceSentence();
    } else if (!current.inCodeBlock && !/^\s*(\||`|~)/.test(text)) {
        // Sentences of the line that already ended (a stop followed by a space) can be spoken before the line ends.
        const match = text.match(/^([\s\S]*[.!?…]["')\]]*)\s+(\S[\s\S]*)?$/);
        if (match) {
            speakLine(match[1]);
            text = match[2] || '';
        }
    }
    current.pendingText = text;
};

// Where to end the first chunk when its text is longer than VOICE_MODE_FIRST_CHUNK_MAX: after the first comma,
// semicolon or colon followed by a space within the limits (so decimals such as 15,38 are kept whole), else at the
// last space within the limits. Returns 0 when the text has no suitable place.
const firstChunkEnd = (text) => {
    const clause = /[,;:](?=\s)/g;
    let match;
    while ((match = clause.exec(text)) !== null) {
        const end = match.index + 1;
        if (end > VOICE_MODE_FIRST_CHUNK_MAX) {
            break;
        }
        if (end >= VOICE_MODE_FIRST_CHUNK_MIN) {
            return end;
        }
    }
    const space = text.lastIndexOf(' ', VOICE_MODE_FIRST_CHUNK_MAX);
    return space >= VOICE_MODE_FIRST_CHUNK_MIN ? space : 0;
};

// Queue a sentence to be read aloud, grouped with the others of the answer (see VOICE_MODE_FIRST_CHUNK_MAX and
// VOICE_MODE_GROUP_TARGET); long ones are cut at a space. notice marks a text of Jedomi that is not part of the
// answer, so it is spoken but not shown.
export const queueVoiceSentence = (sentence, notice = false) => {
    const current = state.voiceMode;
    if (!current) {
        return;
    }
    let text = (sentence || '').trim();
    if (!notice && !current.firstQueued && /[\p{L}\p{N}]/u.test(text)) {
        // The first chunk: a sentence that makes it longer than the maximum is cut, or starts the next group.
        const joined = current.group ? `${current.group} ${text}` : text;
        if (joined.length > VOICE_MODE_FIRST_CHUNK_MAX) {
            const end = firstChunkEnd(joined);
            if (end > 0) {
                current.group = joined.slice(0, end).trim();
                text = joined.slice(end).trim();
            }
            flushVoiceGroup();
        }
    }
    while (text.length > VOICE_MODE_MAX_SENTENCE) {
        const cut = text.lastIndexOf(' ', VOICE_MODE_MAX_SENTENCE);
        const at = cut > 0 ? cut : VOICE_MODE_MAX_SENTENCE;
        queueVoiceSentence(text.slice(0, at), notice);
        text = text.slice(at).trim();
    }
    // Text with no letter or number (a lone symbol) is not read.
    if (!/[\p{L}\p{N}]/u.test(text)) {
        return;
    }
    if (notice) {
        // A notice of Jedomi is read after the sentences of the answer grouped before it.
        flushVoiceGroup();
        current.queue.push({text, notice, controller: new AbortController(), audio: null});
    } else {
        // Whole sentences are grouped up to the target; one that does not fit starts the next group.
        if (current.group && current.group.length + 1 + text.length > VOICE_MODE_GROUP_TARGET) {
            flushVoiceGroup();
        }
        current.group = current.group ? `${current.group} ${text}` : text;
        const target = current.firstQueued ? VOICE_MODE_GROUP_TARGET : VOICE_MODE_FIRST_CHUNK_MIN;
        if (current.group.length >= target) {
            flushVoiceGroup();
        }
    }
    playNextVoiceSentence();
};

// Queue the sentences grouped so far as one chunk to be read aloud.
const flushVoiceGroup = () => {
    const current = state.voiceMode;
    if (!current?.group) {
        return;
    }
    current.queue.push({text: current.group, notice: false, controller: new AbortController(), audio: null});
    current.group = '';
    current.firstQueued = true;
};

// Ask speech.php for the audio of a sentence and decode it.
const fetchVoiceSentence = async(item) => {
    const current = state.voiceMode;
    const request = () => fetch(cfg.speech_url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({text: item.text, sesskey: cfg.sesskey, spt: cfg.spt}),
        signal: item.controller.signal,
    });
    await ensureFreshPermissions();
    let res = await request();
    // Expired permissions are renewed while the Moodle session is active and the sentence is requested once more.
    if (res.status === 403) {
        const refusal = await res.clone().json().catch(() => ({}));
        if (refusal.error?.code === 'invalid_spt' && await renewPermissions()) {
            res = await request();
        }
    }
    if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        const error = new Error(data.error?.message || `HTTP ${res.status}`);
        error.status = res.status;
        error.code = data.error?.code || data.errorcode || '';
        error.reason = data.error?.message || '';
        throw error;
    }
    const buffer = await res.arrayBuffer();
    return current.ctx.decodeAudioData(buffer);
};

// Play the next chunk in order, and request the one after it while it plays.
const playNextVoiceSentence = async() => {
    const current = state.voiceMode;
    if (!current) {
        return;
    }
    // Nothing is being read or waiting to be read after the first chunk: the grouped sentences go now, so Jedomi
    // never stays silent. While a chunk is prepared they keep growing, and go when it starts playing.
    if (current.firstQueued && !current.current && !current.queue.length) {
        flushVoiceGroup();
    }
    const next = current.queue[0];
    if (next && !next.audio) {
        next.audio = fetchVoiceSentence(next);
        // A failure is handled when the sentence is played.
        next.audio.catch(() => null);
    }
    if (current.current || !next) {
        return;
    }

    current.queue.shift();
    current.current = next;
    setVoiceModeState('speaking');
    playNextVoiceSentence();
    let decoded = null;
    try {
        decoded = await next.audio;
    } catch (err) {
        if (err?.name !== 'AbortError') {
            Log.debug('Jedomi: a sentence could not be read aloud', err);
            if (state.voiceMode === current && isVoiceRefusal(err)) {
                // Refused for good: told once, and the conversation ends (the answer stays in the chat).
                addMessage('assistant', voiceRefusalMessage(err));
                hooks.exitVoiceMode();
                return;
            }
            if (state.voiceMode === current && !current.speechFailed) {
                // The text is already in the chat: tell it once and go on with the next sentence.
                current.speechFailed = true;
                addMessage('assistant', `⚠️ ${voiceSpeechErrorReason(err)}`);
            }
        }
    }
    if (state.voiceMode !== current || current.current !== next) {
        return;
    }
    // The text of the sentence appears as it starts to be heard (or at once when it could not be read aloud).
    if (!next.notice) {
        current.display?.showSpoken(next.text);
    }
    if (!decoded) {
        current.current = null;
        playNextVoiceSentence();
        hooks.voiceTurnSpoken();
        return;
    }
    const source = current.ctx.createBufferSource();
    source.buffer = decoded;
    source.connect(current.ctx.destination);
    source.addEventListener('ended', () => {
        if (state.voiceMode !== current || current.source !== source) {
            return;
        }
        current.source = null;
        current.current = null;
        playNextVoiceSentence();
        hooks.voiceTurnSpoken();
    });
    current.source = source;
    source.start();
    // The chunk is heard: the sentences grouped meanwhile go now, so the next chunk is prepared while it plays.
    flushVoiceGroup();
    playNextVoiceSentence();
};

// Stop speaking: the audio stops, the queue is emptied and the pending requests are cancelled.
export const stopVoiceSpeaking = () => {
    if (!state.voiceMode) {
        return;
    }
    const current = state.voiceMode;
    // The answer that was being spoken is shown complete.
    current.display?.finish();
    current.display = null;
    current.turn++;
    const items = current.current ? [current.current, ...current.queue] : current.queue;
    items.forEach(item => item.controller.abort());
    current.queue = [];
    current.group = '';
    current.current = null;
    current.pendingText = '';
    if (current.source) {
        const source = current.source;
        current.source = null;
        try {
            source.stop();
        } catch (e) {
            // Already stopped.
        }
    }
};
