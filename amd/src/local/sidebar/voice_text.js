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
 * Texts of the Jedomi voice conversation: the text read aloud and the notices of its failures.
 *
 * @module     local_jedomi/local/sidebar/voice_text
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {cfg} from './state';

// Codes of errors that mean the session or the signed permissions of the page are no longer valid.
const VOICE_SESSION_ERRORS = ['invalidsesskey', 'requireloginerror', 'invalid_sesskey', 'invalid_spt'];

// Whether a request of the voice conversation was refused for good (expired session or permissions, or the voice
// conversation or the chat no longer allowed): listening again would transcribe, and bill, every new utterance
// only to fail again.
export const isVoiceRefusal = (err) => err?.message === '__SESSION_EXPIRED__' || err?.status === 403
    || VOICE_SESSION_ERRORS.includes(err?.code);

// Language string of each reason speech.php gives for not reading a text aloud.
const VOICE_SPEECH_ERRORS = {
    'voice_disabled': 'speech_error_disabled',
    'speech_empty': 'speech_error_empty',
    'speech_rate_limited': 'speech_error_rate_limited',
    'speech_too_long': 'speech_error_too_long',
};

/**
 * Reason a sentence could not be read aloud: the message of speech.php (per minute limit, text too long, no
 * credit...), the string of its code when the answer had no message, or the generic notice for a network failure.
 *
 * @param {Error} err Failure of the request.
 * @returns {string}
 */
export const voiceSpeechErrorReason = (err) => {
    const S = cfg.strings || {};
    if (err?.reason) {
        return err.reason;
    }
    const key = VOICE_SPEECH_ERRORS[err?.code];
    return (key && S[key]) || S.voice_mode_speech_error || '';
};

// Message of a refusal: the session expired message, or the message of the server for any other refusal.
export const voiceRefusalMessage = (err) => {
    const S = cfg.strings || {};
    if (err?.message && err.message !== '__SESSION_EXPIRED__' && !VOICE_SESSION_ERRORS.includes(err.code)) {
        return `⚠️ ${err.message}`;
    }
    return `⚠️ **${S.session_expired_title || ''}**\n\n${S.session_expired_hint || ''}`;
};

// Text fit to be spoken: without markdown marks, links (only their text) or emojis.
export const speakableText = (line) => line
    .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/https?:\/\/\S+/g, '')
    .replace(/^\s{0,3}(#{1,6}\s+|>\s*|[-*+]\s+|\d+[.)]\s+)/, '')
    .replace(/[*_`~#|]/g, '')
    // eslint-disable-next-line no-misleading-character-class
    .replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu, '')
    .replace(/\s+/g, ' ')
    .trim();
