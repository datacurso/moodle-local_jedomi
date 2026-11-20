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
 * Selectors of the data-action and data-region attributes of the Jedomi sidebar templates.
 *
 * The elements of the panel itself are found by their id (jedomi-trigger, jedomi-sidebar, jedomi-input...), listed in
 * the local_jedomi/sidebar template.
 *
 * @module     local_jedomi/local/sidebar/selectors
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

export default {
    // Prefix of the data-action of the voice bar buttons (voice-cancel, voice-stop, voice-play, voice-delete, voice-send).
    voiceAction: 'local_jedomi/sidebar/voice-',
    // Selector of a data-region of the panel by its name, after local_jedomi/sidebar/.
    region: (name) => `[data-region="local_jedomi/sidebar/${name}"]`,
    actions: {
        focusInput: '[data-action="local_jedomi/sidebar/focus-input"]',
        removeFile: '[data-action="local_jedomi/sidebar/remove-file"]',
        voiceModeExit: '[data-action="local_jedomi/sidebar/voice-mode-exit"]',
        edit: '[data-action="local_jedomi/sidebar/edit"]',
        copy: '[data-action="local_jedomi/sidebar/copy"]',
        good: '[data-action="local_jedomi/sidebar/good"]',
        bad: '[data-action="local_jedomi/sidebar/bad"]',
        feedbackCommentSend: '[data-action="local_jedomi/sidebar/feedback-comment-send"]',
        confirmYes: '[data-action="local_jedomi/sidebar/confirm-yes"]',
        confirmNo: '[data-action="local_jedomi/sidebar/confirm-no"]',
        csvDownload: '[data-csv-download]',
    },
    regions: {
        feedbackCommentText: '[data-region="local_jedomi/sidebar/feedback-comment-text"]',
        confirmResult: '[data-region="local_jedomi/sidebar/confirm-result"]',
        confirmKeyword: '[data-region="local_jedomi/sidebar/confirm-keyword"]',
    },
};
