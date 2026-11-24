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
 * Voice conversation of the Jedomi sidebar.
 *
 * A conversation by voice, like the voice mode of other assistants: the voice view replaces the input row,
 * the microphone stays open and the end of what the user says is detected by its silence (Web Audio). The
 * recording is transcribed by the audio model, as a voice message, and sent with voice_mode, so the answer is
 * short and fit to be spoken. The answer is read aloud (see local_jedomi/local/sidebar/voice_playback), and the full
 * text stays in the chat. Speaking while Jedomi speaks interrupts it.
 * An action that changes data still waits for the confirmation card: Jedomi asks to confirm it on screen and
 * stops listening until it is resolved.
 *
 * @module     local_jedomi/local/sidebar/voice_mode
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {g} from './dom';
import {addMessage} from './messages';
import {cfg, hooks, state} from './state';
import {VOICE_MODE_THRESHOLD, bargeInLevel, speechLevel} from './voice_levels';
import {RECORDING_TYPES} from './voice_message';
import {stopVoiceSpeaking} from './voice_playback';
import {sendVoiceUtterance} from './voice_turn';
import {setVoiceModeState, setVoiceStartButtonsDisabled, speakVoiceNotice, voiceModeRegion} from './voice_view';

// Time the user must keep speaking to interrupt Jedomi, in milliseconds.
const VOICE_MODE_BARGE_IN_MS = 250;

// Silence after speech that ends what the user says, in milliseconds.
const VOICE_MODE_SILENCE_MS = 1200;

// Least time an utterance must be heard above the speech level (see speechLevel) to be transcribed, in milliseconds.
// Shorter sounds (a cough, a click, a door, a short background noise) are ignored and never sent, so never charged.
const VOICE_MODE_MIN_SPEECH_MS = 500;

// Longest utterance of the voice conversation, in milliseconds. A sound that never stops (background voices, a
// television) would keep the utterance open: it ends here and is sent when speech was heard, or dropped otherwise.
const VOICE_MODE_MAX_UTTERANCE_MS = 30000;

// The voice conversation ends after this time listening without speech, in milliseconds.
const VOICE_MODE_IDLE_MS = 60000;

// How often the microphone level is measured, in milliseconds.
const VOICE_MODE_TICK_MS = 50;

/**
 * Why the voice conversation cannot start now: a message is being sent, a voice message is being recorded or a
 * voice message waits to be sent; empty when it can start.
 *
 * @returns {string} sending, recording, pending or an empty string.
 */
const voiceModeBusyReason = () => {
    if (state.sending) {
        return 'sending';
    }
    if (state.recorder) {
        return 'recording';
    }
    if (state.voice) {
        return 'pending';
    }
    return '';
};

// Start the voice conversation. It must run from a click: browsers only let a page play audio after one.
export const startVoiceMode = async() => {
    const S = cfg.strings || {};
    if (state.voiceMode || state.voiceModeStarting || !cfg.speech_url) {
        return;
    }
    // Busy: the button never does nothing silently, the user is told why the conversation does not start.
    const busy = voiceModeBusyReason();
    if (busy) {
        addMessage('assistant', `⚠️ ${S[`voice_mode_busy_${busy}`] || ''}`);
        return;
    }
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined' || !AudioContextClass) {
        addMessage('assistant', `⚠️ ${S.mic_error || ''}`);
        return;
    }
    // Marked before waiting for the microphone permission: another click is ignored meanwhile, and ending the
    // conversation (its button, Esc or closing the panel) during the prompt cancels it.
    const starting = {cancelled: false};
    state.voiceModeStarting = starting;
    setVoiceStartButtonsDisabled(true);
    // Created and resumed during the click, so the answers can be played later.
    const ctx = new AudioContextClass();
    ctx.resume().catch(() => null);

    let stream = null;
    try {
        stream = await navigator.mediaDevices.getUserMedia(
            {audio: {echoCancellation: true, noiseSuppression: true, autoGainControl: true}});
    } catch (err) {
        stream = null;
    }
    if (state.voiceModeStarting === starting) {
        state.voiceModeStarting = null;
        setVoiceStartButtonsDisabled(false);
    }
    if (starting.cancelled || !stream || !g('jedomi-sidebar')?.classList.contains('jedomi-open')) {
        // Cancelled, refused or the panel was closed meanwhile: the microphone is released at once.
        stream?.getTracks().forEach(track => track.stop());
        ctx.close().catch(() => null);
        if (!stream && !starting.cancelled) {
            addMessage('assistant', `⚠️ ${S.mic_error || ''}`);
        }
        return;
    }

    const analyser = ctx.createAnalyser();
    analyser.fftSize = 1024;
    ctx.createMediaStreamSource(stream).connect(analyser);
    state.voiceMode = {
        stream, ctx, analyser,
        samples: new Float32Array(analyser.fftSize),
        state: 'listening',
        // Recording of the current utterance and whether it is sent to transcription when it stops.
        recorder: null, keepRecording: false,
        // Speech detection: whether speech was heard, when it started, the last time it was heard, how long it
        // was heard above the speech level, the time of the last measure, and since when the user speaks over Jedomi.
        heard: false, speechStart: 0, lastVoice: 0, voicedMs: 0, lastTick: 0, bargeStart: 0,
        // Each utterance is a turn; an interruption or the end of the conversation drops older work.
        turn: 0,
        // Chunks waiting to be spoken ({text, notice, controller, audio}), the one playing and its audio source,
        // and the sentences grouped for the next chunk (see VOICE_MODE_GROUP_TARGET).
        queue: [], current: null, source: null, group: '',
        // Sentence splitting of the answer being received.
        pendingText: '', inCodeBlock: false, firstQueued: false, display: null,
        // Background noise while listening, the controller of the requests of the turn, and whether the question
        // of the turn already went to the AI (only then can thinking be interrupted).
        noise: 0, requestController: null, chatStarted: false,
        answerDone: false, pendingAction: false, speechFailed: false,
        monitor: null, idleTimer: null,
    };

    const view = g('jedomi-voice-mode');
    view?.classList.remove('jedomi-hidden');
    g('jedomi-input')?.closest('.jedomi-input-row')?.classList.add('jedomi-hidden');
    view?.querySelector('[data-action="local_jedomi/sidebar/voice-mode-exit"]')?.focus();

    state.voiceMode.monitor = setInterval(monitorVoiceMode, VOICE_MODE_TICK_MS);
    startVoiceListening();
};

// End the voice conversation: stop speaking and listening, release the microphone and give the input row back.
export const exitVoiceMode = () => {
    if (state.voiceModeStarting) {
        // Still waiting for the microphone permission: the stream is released when it arrives.
        state.voiceModeStarting.cancelled = true;
        state.voiceModeStarting = null;
        setVoiceStartButtonsDisabled(false);
    }
    if (!state.voiceMode) {
        return;
    }
    const current = state.voiceMode;
    // A notice read aloud by the browser stops too.
    window.speechSynthesis?.cancel();
    // A transcription in progress is cancelled, so what was said after leaving is not transcribed (and billed).
    // An answer the AI is already writing is kept: it is shown in the chat.
    if (!current.chatStarted) {
        current.requestController?.abort();
    }
    current.requestController = null;
    stopVoiceSpeaking();
    clearInterval(current.monitor);
    clearTimeout(current.idleTimer);
    current.keepRecording = false;
    if (current.recorder && current.recorder.state !== 'inactive') {
        current.recorder.stop();
    }
    current.stream.getTracks().forEach(track => track.stop());
    current.ctx.close().catch(() => null);
    state.voiceMode = null;

    const view = g('jedomi-voice-mode');
    view?.classList.add('jedomi-hidden');
    const input = g('jedomi-input');
    input?.closest('.jedomi-input-row')?.classList.remove('jedomi-hidden');
    g('jedomi-voice-mode-btn')?.focus();
};

// Listen to the user: a new recording starts at once, so the beginning of what is said is kept.
const startVoiceListening = () => {
    if (!state.voiceMode) {
        return;
    }
    const current = state.voiceMode;
    if (current.recorder && current.recorder.state !== 'inactive') {
        current.keepRecording = false;
        current.recorder.stop();
    }
    const type = RECORDING_TYPES.find(t => MediaRecorder.isTypeSupported(t)) || '';
    const rec = new MediaRecorder(current.stream, type ? {mimeType: type} : undefined);
    const chunks = [];
    rec.addEventListener('dataavailable', (e) => {
        if (e.data && e.data.size) {
            chunks.push(e.data);
        }
    });
    rec.addEventListener('stop', () => {
        if (state.voiceMode !== current || current.recorder !== rec || !current.keepRecording) {
            return;
        }
        current.keepRecording = false;
        const mime = rec.mimeType || type || 'audio/webm';
        sendVoiceUtterance(new Blob(chunks, {type: mime}), mime, turnControl);
    });
    current.recorder = rec;
    current.keepRecording = false;
    current.heard = false;
    current.voicedMs = 0;
    current.bargeStart = 0;
    // No question of this new turn went to the AI yet: thinking cannot be interrupted until it does.
    current.chatStarted = false;
    rec.start();
    setVoiceModeState('listening');
    resetVoiceIdleTimer();
};

// End the voice conversation after a while listening without speech.
const resetVoiceIdleTimer = () => {
    if (!state.voiceMode) {
        return;
    }
    clearTimeout(state.voiceMode.idleTimer);
    state.voiceMode.idleTimer = setTimeout(() => {
        if (state.voiceMode?.state !== 'listening' || state.voiceMode.heard) {
            return;
        }
        // The exit is announced on screen and aloud with the voice of the browser (free), then the conversation
        // ends. The recording stops first, so the notice is not transcribed.
        const current = state.voiceMode;
        const notice = (cfg.strings || {}).voice_mode_idle_exit || '';
        current.keepRecording = false;
        if (current.recorder && current.recorder.state !== 'inactive') {
            current.recorder.stop();
        }
        addMessage('assistant', `ℹ️ ${notice}`);
        speakVoiceNotice(notice, () => {
            // Speaking over the notice keeps the conversation: it is listening again.
            if (state.voiceMode === current && current.state === 'speaking') {
                exitVoiceMode();
            }
        });
    }, VOICE_MODE_IDLE_MS);
};

// Measure the microphone: detect the end of what the user says, or the user speaking over Jedomi.
const monitorVoiceMode = () => {
    if (!state.voiceMode) {
        return;
    }
    const current = state.voiceMode;
    current.analyser.getFloatTimeDomainData(current.samples);
    let sum = 0;
    for (const sample of current.samples) {
        sum += sample * sample;
    }
    const level = Math.sqrt(sum / current.samples.length);
    const meter = voiceModeRegion('voice-mode-level');
    if (meter) {
        meter.style.transform = `scale(${1 + Math.min(level * 8, 0.8)})`;
    }
    const now = Date.now();
    // Time since the last measure; a page in the background measures less often, so it is capped.
    const elapsed = current.lastTick ? Math.min(now - current.lastTick, VOICE_MODE_TICK_MS * 4) : VOICE_MODE_TICK_MS;
    current.lastTick = now;

    if (current.state === 'listening') {
        if (!current.heard && level <= VOICE_MODE_THRESHOLD) {
            // Background noise while nobody speaks, kept as a slow moving average.
            current.noise = current.noise ? current.noise * 0.95 + level * 0.05 : level;
        }
        if (level > VOICE_MODE_THRESHOLD) {
            if (!current.heard) {
                current.heard = true;
                current.speechStart = now;
                current.voicedMs = 0;
                clearTimeout(current.idleTimer);
            }
            current.lastVoice = now;
            if (level > speechLevel(current)) {
                current.voicedMs += elapsed;
            }
        }
        if (current.heard && now - current.speechStart >= VOICE_MODE_MAX_UTTERANCE_MS) {
            if (current.voicedMs >= VOICE_MODE_MIN_SPEECH_MS) {
                // The longest utterance: what was said is sent, and a sound that never stops cannot keep it open.
                setVoiceModeState('thinking');
                current.keepRecording = true;
                current.recorder?.stop();
            } else {
                // Only noise for the whole utterance: it is dropped with a new recording, and nothing is charged.
                startVoiceListening();
            }
        } else if (level <= VOICE_MODE_THRESHOLD && current.heard && now - current.lastVoice > VOICE_MODE_SILENCE_MS) {
            if (current.voicedMs >= VOICE_MODE_MIN_SPEECH_MS) {
                // The user finished speaking: the recording is sent to transcription when it stops.
                setVoiceModeState('thinking');
                current.keepRecording = true;
                current.recorder?.stop();
            } else {
                // Only a short noise: keep listening, and nothing is transcribed.
                current.heard = false;
                current.voicedMs = 0;
                resetVoiceIdleTimer();
            }
        }
    } else if (!current.pendingAction
            && (current.state === 'speaking' || (current.state === 'thinking' && current.chatStarted))) {
        // While the recording is still being transcribed it is not interrupted, so what was said is not lost.
        // Nor is an answer that holds an action: the conversation must wait for its confirmation on screen.
        if (level > bargeInLevel(current)) {
            current.bargeStart = current.bargeStart || now;
            if (now - current.bargeStart >= VOICE_MODE_BARGE_IN_MS) {
                // The user speaks while Jedomi thinks or speaks: drop that answer and listen to the new words.
                interruptVoiceTurn();
            }
        } else {
            current.bargeStart = 0;
        }
    }
};

// Drop the answer in progress (transcription, AI request and speech) and listen again. The question already sent
// stays in the conversation, so the next one is answered with it in mind.
const interruptVoiceTurn = () => {
    const current = state.voiceMode;
    if (!current || current.pendingAction) {
        return;
    }
    current.requestController?.abort();
    current.requestController = null;
    stopVoiceSpeaking();
    startVoiceListening();
};

// After the last sentence, listen again, or wait for the confirmation card when an action is pending.
const finishVoiceTurnIfDone = () => {
    const current = state.voiceMode;
    if (!current || !current.answerDone || current.current || current.queue.length || current.group) {
        return;
    }
    if (current.state === 'listening' || current.state === 'paused') {
        return;
    }
    // Everything was heard: the complete answer replaces the spoken sentences.
    current.display?.finish();
    if (current.pendingAction) {
        if (current.recorder && current.recorder.state !== 'inactive') {
            current.keepRecording = false;
            current.recorder.stop();
        }
        clearTimeout(current.idleTimer);
        setVoiceModeState('paused');
        // The microphone stays open while paused: the conversation ends if the action is not resolved in time.
        current.idleTimer = setTimeout(() => {
            if (state.voiceMode === current && current.state === 'paused') {
                exitVoiceMode();
            }
        }, VOICE_MODE_IDLE_MS);
        return;
    }
    startVoiceListening();
};

// The confirmation card was resolved: a paused voice conversation listens again.
const resumeVoiceModeAfterAction = () => {
    if (state.voiceMode?.state !== 'paused') {
        return;
    }
    state.voiceMode.pendingAction = false;
    startVoiceListening();
};

// What a turn of the conversation does when it ends (see local_jedomi/local/sidebar/voice_turn).
const turnControl = {
    listen: () => startVoiceListening(),
    exit: () => exitVoiceMode(),
    finish: () => finishVoiceTurnIfDone(),
};

// The modules this one uses call back into the voice conversation through these hooks.
hooks.actionResolved = resumeVoiceModeAfterAction;
hooks.exitVoiceMode = exitVoiceMode;
hooks.voiceTurnSpoken = finishVoiceTurnIfDone;
