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
 * View of the Jedomi voice conversation: its state, its buttons and the notices read aloud by the browser.
 *
 * @module     local_jedomi/local/sidebar/voice_view
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {g} from './dom';
import Selectors from './selectors';
import {cfg, state} from './state';
import {speakableText} from './voice_text';

// An element of the voice view by its data-region.
export const voiceModeRegion = (name) => g('jedomi-voice-mode')?.querySelector(Selectors.region(name));

// Show the state of the voice conversation: listening, thinking, speaking or paused (waiting for a confirmation).
export const setVoiceModeState = (modeState) => {
    if (!state.voiceMode) {
        return;
    }
    const S = cfg.strings || {};
    state.voiceMode.state = modeState;
    const view = g('jedomi-voice-mode');
    if (view) {
        ['listening', 'thinking', 'speaking', 'paused']
            .forEach(s => view.classList.toggle(`jedomi-voice-mode--${s}`, s === modeState));
    }
    const label = voiceModeRegion('voice-mode-state');
    if (label) {
        label.textContent = S[`voice_mode_${modeState}`] || '';
    }
};

// Disable the microphone and voice conversation buttons while the voice conversation waits for the microphone.
export const setVoiceStartButtonsDisabled = (disabled) => {
    ['jedomi-voice-btn', 'jedomi-voice-mode-btn'].forEach(id => {
        const button = g(id);
        if (button) {
            button.disabled = disabled;
        }
    });
};

// Longest time a notice read aloud by the browser may take before listening again, in milliseconds.
const VOICE_NOTICE_MAX_MS = 8000;

// Say a short notice aloud with the voice of the browser, which costs nothing and works even when the speech
// service is not available (no credits, service down), then call done. The microphone is not listening meanwhile,
// so the notice is not transcribed.
export const speakVoiceNotice = (notice, done) => {
    const synth = window.speechSynthesis;
    const text = speakableText(notice);
    if (!synth || typeof window.SpeechSynthesisUtterance !== 'function' || !text) {
        done();
        return;
    }
    let finished = false;
    let guard = null;
    const finish = () => {
        if (finished) {
            return;
        }
        finished = true;
        clearTimeout(guard);
        done();
    };
    guard = setTimeout(finish, VOICE_NOTICE_MAX_MS);
    const utterance = new window.SpeechSynthesisUtterance(text);
    utterance.lang = document.documentElement.lang || '';
    utterance.addEventListener('end', finish);
    utterance.addEventListener('error', finish);
    setVoiceModeState('speaking');
    synth.cancel();
    synth.speak(utterance);
};
