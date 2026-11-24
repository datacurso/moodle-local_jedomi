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
 * Voice message of the Jedomi chat, like in Telegram.
 *
 * The voice bar replaces the input row while recording, and after stopping the recording can be played, deleted or
 * sent. It stays in the browser until it is sent: only then it is uploaded and transcribed by the audio model (billed
 * per second), and its text is sent as the user message. The text typed in the input box is kept for later.
 *
 * @module     local_jedomi/local/sidebar/voice_message
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import Templates from 'core/templates';
import {uploadForText} from './api';
import {runChat} from './chat';
import {g} from './dom';
import {addMessage} from './messages';
import Selectors from './selectors';
import {cfg, state} from './state';

// Recording formats, in order of preference; the first one the browser supports is used.
export const RECORDING_TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/ogg', 'audio/mp4'];

// Prefix of the data-action of the voice bar buttons.
const VOICE_ACTION = Selectors.voiceAction;

// Shortest voice message that is sent, in milliseconds; a shorter recording is discarded with a hint.
const VOICE_MESSAGE_MIN_MS = 500;

// Longest voice message, in milliseconds: the recording stops there with a hint, and can still be played and sent.
const VOICE_MESSAGE_MAX_MS = 300000;

// A number of seconds as m:ss.
const formatDuration = (seconds) => {
    const total = Math.max(0, Math.round(seconds || 0));
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
};

// An element of the voice bar by its data-region.
const voiceRegion = (name) => g('jedomi-voice-bar')?.querySelector(Selectors.region(name));

// Render the voice bar (recording or preview) in place of the input row and focus its main button.
const showVoiceBar = async(recording, seconds) => {
    const bar = g('jedomi-voice-bar');
    if (!bar) {
        return;
    }
    const version = ++state.voiceBarVersion;
    try {
        const {html, js} = await Templates.renderForPromise('local_jedomi/local/sidebar/voice_bar',
            {recording, duration: formatDuration(seconds), strings: cfg.strings || {}});
        if (version !== state.voiceBarVersion) {
            return;
        }
        Templates.replaceNodeContents(bar, html, js);
    } catch (error) {
        Log.error('Jedomi: template local_jedomi/local/sidebar/voice_bar could not be rendered', error);
        return;
    }
    bar.classList.remove('jedomi-hidden');
    g('jedomi-input')?.closest('.jedomi-input-row')?.classList.add('jedomi-hidden');
    bar.querySelector(`[data-action="${VOICE_ACTION}${recording ? 'stop' : 'send'}"]`)?.focus();
};

// Hide the voice bar and give the input row back, with the text typed before recording.
const hideVoiceBar = () => {
    state.voiceBarVersion++;
    const bar = g('jedomi-voice-bar');
    if (bar) {
        bar.classList.add('jedomi-hidden');
        bar.replaceChildren();
    }
    const input = g('jedomi-input');
    input?.closest('.jedomi-input-row')?.classList.remove('jedomi-hidden');
    input?.focus();
};

export const startRecording = async() => {
    const S = cfg.strings || {};
    if (state.recorder || state.voice || state.voiceMode || state.voiceModeStarting) {
        return;
    }
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        addMessage('assistant', `⚠️ ${S.mic_error || ''}`);
        return;
    }

    let stream;
    try {
        stream = await navigator.mediaDevices.getUserMedia({audio: true});
    } catch (err) {
        addMessage('assistant', `⚠️ ${S.mic_error || ''}`);
        return;
    }

    const type = RECORDING_TYPES.find(t => MediaRecorder.isTypeSupported(t)) || '';
    const current = new MediaRecorder(stream, type ? {mimeType: type} : undefined);
    const chunks = [];
    const started = Date.now();
    let timer = null;

    current.addEventListener('dataavailable', (e) => {
        if (e.data && e.data.size) {
            chunks.push(e.data);
        }
    });
    current.addEventListener('stop', () => {
        stream.getTracks().forEach(track => track.stop());
        clearInterval(timer);
        state.recorder = null;
        const mime = current.mimeType || type || 'audio/webm';
        const blob = new Blob(chunks, {type: mime});
        if (state.recordingCancelled || !blob.size) {
            hideVoiceBar();
            return;
        }
        if (Date.now() - started < VOICE_MESSAGE_MIN_MS) {
            // A tap on the microphone is not a message: nothing is uploaded nor charged.
            hideVoiceBar();
            addMessage('assistant', `⚠️ ${(cfg.strings || {}).voice_too_short || ''}`);
            return;
        }
        // The duration is measured here: a recorded WebM often reports an unknown duration.
        const url = URL.createObjectURL(blob);
        const audio = new Audio(url);
        state.voice = {blob, url, audio, mime, seconds: (Date.now() - started) / 1000};
        audio.addEventListener('timeupdate', updateVoiceProgress);
        audio.addEventListener('pause', () => setVoicePlaying(false));
        audio.addEventListener('ended', () => {
            audio.currentTime = 0;
            setVoicePlaying(false);
            updateVoiceProgress();
        });
        showVoiceBar(false, state.voice.seconds);
    });

    state.recorder = current;
    state.recordingCancelled = false;
    current.start();
    showVoiceBar(true, 0);
    timer = setInterval(() => {
        const time = voiceRegion('voice-time');
        if (time) {
            time.textContent = formatDuration((Date.now() - started) / 1000);
        }
        if (Date.now() - started >= VOICE_MESSAGE_MAX_MS && current.state === 'recording') {
            // The longest recording the audio model accepts: it stops, and the user can listen to it and send it.
            clearInterval(timer);
            current.stop();
            addMessage('assistant', `⚠️ ${((cfg.strings || {}).voice_too_long || '').replace('{$a}',
                VOICE_MESSAGE_MAX_MS / 60000)}`);
        }
    }, 500);
};

// Clicks on the voice bar buttons, through event delegation because the bar is rendered again for each state.
export const handleVoiceAction = (e) => {
    const btn = e.target.closest(`[data-action^="${VOICE_ACTION}"]`);
    if (!btn || btn.disabled) {
        return;
    }
    const action = btn.getAttribute('data-action').slice(VOICE_ACTION.length);
    if (action === 'stop' || action === 'cancel') {
        state.recordingCancelled = action === 'cancel';
        if (state.recorder && state.recorder.state === 'recording') {
            state.recorder.stop();
        }
    } else if (action === 'play') {
        toggleVoicePlayback();
    } else if (action === 'delete') {
        discardVoice();
    } else if (action === 'send') {
        sendVoiceMessage();
    }
};

// Show the play or the pause icon and label on the play button.
const setVoicePlaying = (playing) => {
    const S = cfg.strings || {};
    const btn = g('jedomi-voice-bar')?.querySelector(`[data-action="${VOICE_ACTION}play"]`);
    if (!btn) {
        return;
    }
    const label = (playing ? S.voice_pause : S.voice_play) || '';
    btn.classList.toggle('jedomi-voice-playing', playing);
    btn.setAttribute('aria-label', label);
    btn.setAttribute('title', label);
};

const toggleVoicePlayback = () => {
    if (!state.voice) {
        return;
    }
    if (state.voice.audio.paused) {
        state.voice.audio.play()
            .then(() => setVoicePlaying(true))
            .catch(error => Log.error('Jedomi: the voice message could not be played', error));
    } else {
        state.voice.audio.pause();
    }
};

// Move the progress bar and show the played time, or the duration when stopped.
const updateVoiceProgress = () => {
    if (!state.voice) {
        return;
    }
    const {audio, seconds} = state.voice;
    const progress = voiceRegion('voice-progress');
    if (progress) {
        progress.style.width = `${seconds ? Math.min(100, audio.currentTime / seconds * 100) : 0}%`;
    }
    const time = voiceRegion('voice-time');
    if (time) {
        time.textContent = formatDuration(audio.currentTime > 0 ? audio.currentTime : seconds);
    }
};

// Delete the recording: nothing is uploaded nor billed, and the input row comes back to record again.
const discardVoice = () => {
    if (state.voice) {
        state.voice.audio.pause();
        URL.revokeObjectURL(state.voice.url);
        state.voice = null;
    }
    hideVoiceBar();
};

// Transcribe the recording and send its text as the user message. On an error the preview stays,
// so the user can try again or delete it.
const sendVoiceMessage = async() => {
    if (!state.voice || state.sending) {
        return;
    }
    const S = cfg.strings || {};
    const current = state.voice;
    const setBusy = (busy) => {
        g('jedomi-voice-bar')?.querySelectorAll('button').forEach(btn => {
            btn.disabled = busy;
        });
        voiceRegion('voice-status')?.classList.toggle('jedomi-hidden', !busy);
    };

    current.audio.pause();
    state.sending = true;
    setBusy(true);
    let text = '';
    try {
        const extension = current.mime.includes('ogg') ? 'ogg' : (current.mime.includes('mp4') ? 'm4a' : 'webm');
        const file = new File([current.blob], `recording.${extension}`, {type: current.mime});
        const data = await uploadForText(cfg.extract_file_url, file);
        text = (data.text || '').trim();
        if (!text) {
            throw new Error(S.audio_error_unreadable || '');
        }
    } catch (err) {
        addMessage('assistant', `⚠️ ${err.message}`);
        return;
    } finally {
        state.sending = false;
        if (state.voice === current) {
            setBusy(false);
        }
    }

    discardVoice();
    await runChat((S.voice_message || '{$a}').replace('{$a}', () => text), text);
};
