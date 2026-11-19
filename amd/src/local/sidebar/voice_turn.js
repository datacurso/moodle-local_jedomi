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
 * A turn of the Jedomi voice conversation: what the user said is transcribed, sent to the chat and its answer read
 * aloud as it arrives.
 *
 * @module     local_jedomi/local/sidebar/voice_turn
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';
import {uploadForText} from './api';
import {runChat} from './chat';
import {g} from './dom';
import {addMessage} from './messages';
import {cfg, state} from './state';
import {queueVoiceSentence, speakCompleteSentences, stopVoiceSpeaking} from './voice_playback';
import {isVoiceRefusal, voiceRefusalMessage} from './voice_text';
import {speakVoiceNotice} from './voice_view';

// Wait until no other message is being sent or answered.
const waitUntilNotSending = async() => {
    while (state.sending) {
        await new Promise(resolve => setTimeout(resolve, 100));
    }
};

// Transcribe what the user said and send it as a message of the voice conversation. control tells what to do when
// the turn ends: {listen()} listens again, {exit()} ends the conversation and {finish()} ends the spoken answer.
export const sendVoiceUtterance = async(blob, mime, control) => {
    if (!state.voiceMode || !blob.size) {
        control.listen();
        return;
    }
    const S = cfg.strings || {};
    const current = state.voiceMode;
    const turn = ++current.turn;
    // One controller cancels the transcription and the AI request of this turn when the user interrupts.
    const controller = new AbortController();
    current.requestController = controller;
    current.chatStarted = false;
    let text = '';
    // Message of the server when the transcription fails (service unavailable, no credits, limit reached...):
    // it is already translated and says what happened, so it is shown instead of "could not understand".
    let failure = '';
    let refusal = null;
    try {
        const extension = mime.includes('ogg') ? 'ogg' : (mime.includes('mp4') ? 'm4a' : 'webm');
        const data = await uploadForText(cfg.extract_file_url, new File([blob], `recording.${extension}`, {type: mime}),
            {}, controller.signal);
        text = (data.text || '').trim();
    } catch (err) {
        if (err?.name === 'AbortError') {
            return;
        }
        Log.debug('Jedomi: the voice conversation could not transcribe the audio', err);
        failure = (err?.message || '').trim();
        refusal = isVoiceRefusal(err) ? err : null;
    }
    if (state.voiceMode !== current || current.turn !== turn) {
        return;
    }
    if (refusal) {
        addMessage('assistant', voiceRefusalMessage(refusal));
        control.exit();
        return;
    }
    if (!text) {
        const notice = failure || S.voice_mode_transcription_error || '';
        // The same notice right after itself is not repeated, so noise does not fill the chat. A new notice is
        // also said aloud, as the user may not be looking at the screen.
        const msgs = g('jedomi-messages');
        const lastMessage = msgs?.querySelector('.jedomi-message:last-of-type');
        if (lastMessage && lastMessage.dataset.voiceNotice === notice) {
            control.listen();
            return;
        }
        const wrap = addMessage('assistant', `⚠️ ${notice}`);
        if (wrap) {
            wrap.dataset.voiceNotice = notice;
        }
        speakVoiceNotice(notice, () => {
            if (state.voiceMode === current && current.turn === turn) {
                control.listen();
            }
        });
        return;
    }

    await waitUntilNotSending();
    if (state.voiceMode !== current || current.turn !== turn) {
        return;
    }
    current.pendingText = '';
    current.group = '';
    current.inCodeBlock = false;
    current.firstQueued = false;
    current.display = null;
    current.answerDone = false;
    current.pendingAction = false;
    current.speechFailed = false;
    const isTurn = () => state.voiceMode === current && current.turn === turn;
    current.chatStarted = true;
    await runChat((S.voice_message || '{$a}').replace('{$a}', () => text), text, null, {
        signal: controller.signal,
        onDisplay: (display) => {
            if (isTurn()) {
                current.display = display;
            }
        },
        onToken: (token) => {
            if (!isTurn()) {
                return;
            }
            current.pendingText += token;
            speakCompleteSentences(false);
        },
        onPendingAction: () => {
            if (isTurn()) {
                current.pendingAction = true;
            }
        },
        onDone: () => {
            if (!isTurn()) {
                return;
            }
            speakCompleteSentences(true);
            if (current.pendingAction) {
                queueVoiceSentence(S.voice_mode_confirm_notice || '', true);
            }
            current.answerDone = true;
            control.finish();
        },
        onError: (err) => {
            if (!isTurn()) {
                return;
            }
            // The error is shown in the chat. After a refusal the conversation ends; else listen again.
            if (isVoiceRefusal(err)) {
                control.exit();
                return;
            }
            stopVoiceSpeaking();
            control.listen();
        },
    });
};
