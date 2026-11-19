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
 * Microphone levels of the Jedomi voice conversation: what counts as speech, and as speaking over Jedomi.
 *
 * @module     local_jedomi/local/sidebar/voice_levels
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Level (RMS, 0 to 1) above which the microphone hears speech.
export const VOICE_MODE_THRESHOLD = 0.035;

// Lowest level needed to interrupt Jedomi while it speaks, so its own voice coming back through the microphone
// does not. The real threshold also follows the background noise measured while listening (see bargeInLevel).
const VOICE_MODE_BARGE_IN_MIN = 0.05;

// How many times the background noise the user must speak over Jedomi (speaking) or while it thinks.
const VOICE_MODE_BARGE_IN_NOISE_SPEAKING = 4;
const VOICE_MODE_BARGE_IN_NOISE_THINKING = 3;

// How many times the background noise measured while listening the speech must be, to count as speech.
const VOICE_MODE_SPEECH_NOISE = 3;

// Level that counts as speech while listening: above the threshold and well above the background noise, so a noisy
// room does not turn its noise into utterances.
export const speechLevel = (current) => Math.max(VOICE_MODE_THRESHOLD, (current.noise || 0) * VOICE_MODE_SPEECH_NOISE);

// Level the user must reach to interrupt Jedomi: above the background noise, and higher while Jedomi speaks so its
// own voice coming back through the microphone does not interrupt it.
export const bargeInLevel = (current) => {
    const noise = current.noise || 0;
    if (current.state === 'speaking') {
        return Math.max(VOICE_MODE_BARGE_IN_MIN, noise * VOICE_MODE_BARGE_IN_NOISE_SPEAKING);
    }
    return Math.max(VOICE_MODE_THRESHOLD, noise * VOICE_MODE_BARGE_IN_NOISE_THINKING);
};
