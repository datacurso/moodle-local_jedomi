<?php
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

namespace local_jedomi\output;

/**
 * Tests for the templates the local_jedomi/sidebar module renders.
 *
 * The module looks up these element ids and data attributes, so the templates must keep them.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class sidebar_templates_test extends \advanced_testcase {
    /**
     * Render a template of the plugin.
     *
     * @param string $name Template name without the component.
     * @param array $context Template context.
     * @return string
     */
    private function render(string $name, array $context): string {
        global $OUTPUT, $PAGE;
        $PAGE->set_url(new \moodle_url('/'));
        return $OUTPUT->render_from_template('local_jedomi/' . $name, $context);
    }

    /**
     * MDL-UNIT-067: the panel has the export, half width, full width, new conversation and close controls.
     * The sidebar structure has every element the module wires, and escapes the greeting.
     *
     * @return void
     */
    public function test_sidebar_structure(): void {
        $this->resetAfterTest();

        $html = $this->render('sidebar', ['greeting' => 'Hi, <b>Ana</b>!', 'strings' => ['placeholder' => 'Ask Jedomi'],
            'files_enabled' => true, 'audio_enabled' => true, 'attach_enabled' => true]);

        foreach (
            ['jedomi-trigger', 'jedomi-sidebar', 'jedomi-messages', 'jedomi-input', 'jedomi-send', 'jedomi-clear',
                'jedomi-close', 'jedomi-export', 'jedomi-expand-half', 'jedomi-expand-full', 'jedomi-quick-actions',
                'jedomi-thinking-wrapper', 'jedomi-thinking-toggle-btn', 'jedomi-attach-btn', 'jedomi-attach-menu',
                'jedomi-image-input', 'jedomi-file-input', 'jedomi-image-preview', 'jedomi-preview-img',
                'jedomi-remove-image', 'jedomi-file-preview', 'jedomi-file-preview-name', 'jedomi-remove-file',
                'jedomi-overlay', 'jedomi-dropzone', 'jedomi-context-label', 'jedomi-voice-btn', 'jedomi-audio-input',
                'jedomi-voice-bar'] as $id
        ) {
            $this->assertStringContainsString('id="' . $id . '"', $html, $id);
        }
        $this->assertStringContainsString('Hi, &lt;b&gt;Ana&lt;/b&gt;!', $html);
        $this->assertStringContainsString('placeholder="Ask Jedomi"', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    /**
     * Quick actions carry the message and the requested icon, escaped.
     *
     * @return void
     */
    public function test_quick_actions(): void {
        $this->resetAfterTest();

        $html = $this->render('local/sidebar/quick_actions', ['actions' => [
            ['label' => 'Overview', 'msg' => 'Say "hi"', 'icon' => ['users' => true]],
        ]]);

        $this->assertStringContainsString('data-msg="Say &quot;hi&quot;"', $html);
        $this->assertStringContainsString('class="jedomi-quick-btn"', $html);
        $this->assertSame(1, substr_count($html, '<svg'));
    }

    /**
     * MDL-UNIT-070: critical actions ask for the keyword and sending messages shows the count, the preview and send.
     * The confirmation card has the buttons, the keyword input of critical actions and escapes the details.
     *
     * @return void
     */
    public function test_action_confirm(): void {
        $this->resetAfterTest();

        $critical = $this->render('local/sidebar/action_confirm', ['issend' => false, 'critical' => true, 'keyword' => 'CONFIRM',
            'description' => 'Delete agent', 'hasdetails' => true, 'details' => '<img src=x>', 'strings' => []]);
        $send = $this->render('local/sidebar/action_confirm', ['issend' => true, 'countbefore' => 'Send to ', 'count' => 3,
            'countafter' => ' students?', 'preview' => 'Hello', 'strings' => []]);

        $this->assertStringContainsString('data-region="local_jedomi/sidebar/confirm-keyword"', $critical);
        $this->assertStringContainsString('&lt;img src=x&gt;', $critical);
        $this->assertStringContainsString('data-action="local_jedomi/sidebar/confirm-yes"', $send);
        $this->assertStringContainsString('Send to <strong>3</strong> students?', $send);
        $this->assertStringNotContainsString('confirm-keyword', $send);
    }

    /**
     * MDL-UNIT-071: assistant answers have copy and rating buttons; user messages have the edit button.
     * Message and user action buttons use the data actions the module listens to.
     *
     * @return void
     */
    public function test_message_actions(): void {
        $this->resetAfterTest();

        $assistant = $this->render('local/sidebar/message_actions', ['strings' => ['action_copy' => 'Copy']]);
        $user = $this->render('local/sidebar/user_actions', ['strings' => ['action_edit' => 'Edit']]);

        foreach (['copy', 'good', 'bad'] as $action) {
            $this->assertStringContainsString('data-action="local_jedomi/sidebar/' . $action . '"', $assistant);
        }
        $this->assertStringContainsString('data-action="local_jedomi/sidebar/edit"', $user);
    }

    /**
     * MDL-INT-006: the panel hides the attach options and the microphone when their processing is off.
     * MDL-UNIT-068: the panel offers only the options of the processing switches that are on.
     * M8, M9: a feature that is off has no attach option, no drop zone and no microphone.
     *
     * @return void
     */
    public function test_sidebar_hides_disabled_features(): void {
        $this->resetAfterTest();
        $base = ['greeting' => 'Hi', 'strings' => []];

        $html = $this->render('sidebar', $base + ['files_enabled' => false, 'audio_enabled' => false, 'attach_enabled' => false]);
        foreach (
            ['jedomi-attach-btn', 'jedomi-image-input', 'jedomi-file-input', 'jedomi-dropzone', 'jedomi-voice-btn',
                'jedomi-audio-input', 'jedomi-voice-bar'] as $id
        ) {
            $this->assertStringNotContainsString('id="' . $id . '"', $html, $id);
        }

        $html = $this->render('sidebar', $base + ['files_enabled' => false, 'audio_enabled' => true, 'attach_enabled' => true]);
        $this->assertStringContainsString('id="jedomi-voice-btn"', $html);
        $this->assertStringContainsString('id="jedomi-voice-bar"', $html);
        $this->assertStringContainsString('data-type="audio"', $html);
        $this->assertStringNotContainsString('data-type="image"', $html);
        $this->assertStringNotContainsString('data-type="file"', $html);
        $this->assertStringNotContainsString('id="jedomi-image-input"', $html);

        $html = $this->render('sidebar', $base + ['files_enabled' => true, 'audio_enabled' => false, 'attach_enabled' => true]);
        $this->assertStringContainsString('data-type="image"', $html);
        $this->assertStringContainsString('data-type="file"', $html);
        $this->assertStringNotContainsString('data-type="audio"', $html);
        $this->assertStringNotContainsString('id="jedomi-voice-btn"', $html);
        $this->assertStringNotContainsString('id="jedomi-voice-bar"', $html);
        $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $html);
    }

    /**
     * V9, V10: the voice conversation button and view are in the panel only while the voice conversation is enabled; the
     * view has the state, the exit button and the regions the module updates.
     *
     * @return void
     */
    public function test_voice_conversation_button_and_view_follow_the_switch(): void {
        $this->resetAfterTest();
        $base = ['greeting' => 'Hi', 'files_enabled' => true, 'audio_enabled' => true, 'attach_enabled' => true];
        $strings = ['voice_mode' => 'Voice <conversation>', 'voice_mode_exit' => 'End voice conversation',
            'voice_mode_listening' => 'Listening...'];

        $html = $this->render('sidebar', $base + ['voice_enabled' => true, 'strings' => $strings]);
        $this->assertStringContainsString('id="jedomi-voice-mode-btn"', $html);
        $this->assertStringContainsString('aria-label="Voice &lt;conversation&gt;"', $html);
        $this->assertStringContainsString('id="jedomi-voice-mode"', $html);
        $this->assertStringContainsString('data-action="local_jedomi/sidebar/voice-mode-exit"', $html);
        $this->assertStringContainsString('aria-label="End voice conversation"', $html);
        $this->assertStringContainsString('data-region="local_jedomi/sidebar/voice-mode-state"', $html);
        $this->assertStringContainsString('data-region="local_jedomi/sidebar/voice-mode-level"', $html);
        $this->assertMatchesRegularExpression('/id="jedomi-voice-mode"[^>]*jedomi-hidden/', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style=', $html);

        $html = $this->render('sidebar', $base + ['voice_enabled' => false, 'strings' => $strings]);
        $this->assertStringNotContainsString('id="jedomi-voice-mode-btn"', $html);
        $this->assertStringNotContainsString('id="jedomi-voice-mode"', $html);
    }

    /**
     * MDL-UNIT-069: while recording, the voice bar shows Cancel, the elapsed time, the recording notice and Stop.
     * While recording, the voice bar has the elapsed time and labelled cancel and stop buttons, and no send button.
     *
     * @return void
     */
    public function test_voice_bar_while_recording(): void {
        $this->resetAfterTest();

        $html = $this->render('local/sidebar/voice_bar', ['recording' => true, 'duration' => '0:00',
            'strings' => ['voice_cancel' => 'Cancel <now>', 'voice_stop' => 'Stop', 'voice_recording' => 'Recording...']]);

        $this->assertStringContainsString('data-action="local_jedomi/sidebar/voice-cancel"', $html);
        $this->assertStringContainsString('data-action="local_jedomi/sidebar/voice-stop"', $html);
        $this->assertStringContainsString('data-region="local_jedomi/sidebar/voice-time"', $html);
        $this->assertStringContainsString('aria-label="Cancel &lt;now&gt;"', $html);
        $this->assertStringContainsString('aria-label="Stop"', $html);
        $this->assertStringContainsString('Recording...', $html);
        $this->assertStringContainsString('0:00', $html);
        $this->assertStringNotContainsString('voice-send', $html);
        $this->assertStringNotContainsString('voice-play', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    /**
     * MDL-UNIT-069: after stopping, the voice bar offers play or pause with progress and duration, delete and send.
     * The preview of a recording has labelled play, delete and send buttons, the duration and a hidden transcribing state.
     *
     * @return void
     */
    public function test_voice_bar_preview(): void {
        $this->resetAfterTest();

        $html = $this->render('local/sidebar/voice_bar', ['recording' => false, 'duration' => '1:05',
            'strings' => ['voice_play' => 'Play', 'voice_delete' => 'Delete', 'voice_send' => 'Send voice',
                'transcribing' => 'Transcribing...']]);

        foreach (['voice-play', 'voice-delete', 'voice-send'] as $action) {
            $this->assertStringContainsString('data-action="local_jedomi/sidebar/' . $action . '"', $html, $action);
        }
        foreach (['voice-time', 'voice-progress', 'voice-status'] as $region) {
            $this->assertStringContainsString('data-region="local_jedomi/sidebar/' . $region . '"', $html, $region);
        }
        foreach (['Play', 'Delete', 'Send voice'] as $label) {
            $this->assertStringContainsString('aria-label="' . $label . '"', $html, $label);
        }
        $this->assertStringContainsString('1:05', $html);
        $this->assertStringContainsString('Transcribing...', $html);
        $this->assertStringNotContainsString('voice-stop', $html);
        $this->assertStringNotContainsString('voice-cancel', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    /**
     * MDL-UNIT-042: names with underscores stay intact and headings and numbered lists are formatted.
     * [Pendiente:skip]
     */
    public function test_answer_keeps_underscores_and_formats_headings_and_lists(): void {
        $this->markTestSkipped('Pending fix: the italic format alters names with underscores'
            . ' and headings and numbered lists are not supported.');
    }

    /**
     * MDL-UNIT-044: link type labels, the CSV file name and the export header and labels are in the user language.
     * [Pendiente:skip]
     */
    public function test_panel_texts_are_in_the_user_language(): void {
        $this->markTestSkipped('Pending fix: link labels, CSV name and export texts are fixed in one language.');
    }

    /**
     * MDL-UNIT-073: the location of the page sent as context is recognised for every kind of page, including the
     * private files page. Behat: panel_location.feature.
     * [Pendiente:skip] The private files page is detected as "profile" instead of "files".
     */
    public function test_page_location_is_recognised_for_every_kind_of_page(): void {
        $this->markTestSkipped('Pending fix: the private files page is detected as "profile" instead of "files".');
    }

    /**
     * MDL-E2E-004: the panel is hidden from the keyboard while closed, keeps and gives back the focus, announces the
     * answers, names every button and closes when touching outside on mobile. Behat: panel_navigation.feature.
     * [Pendiente:skip]
     */
    public function test_panel_meets_the_basic_accessibility_criteria(): void {
        $this->markTestSkipped('Pending fix: the closed panel is reachable with Tab, the focus is not kept nor given back, '
            . 'answers are not announced, some buttons have no accessible name and the backdrop never appears.');
    }

    /**
     * MDL-E2E-005: the shortcuts of the panel do not clash with the browser and the new conversation shortcut asks
     * for confirmation. Behat: panel_navigation.feature.
     * [Pendiente:skip]
     */
    public function test_shortcuts_do_not_clash_with_the_browser_nor_lose_the_conversation(): void {
        $this->markTestSkipped('Pending fix: the shortcuts match browser shortcuts and the new conversation shortcut '
            . 'clears the conversation without confirmation.');
    }

    /**
     * MDL-E2E-011: editing acts on the chosen message and the conversation looks the same after reloading.
     * Behat: panel_conversation.feature.
     * [Pendiente:skip]
     */
    public function test_editing_acts_on_the_chosen_message_and_reloading_keeps_the_conversation(): void {
        $this->markTestSkipped('Pending fix: Edit only edits the last message, removed messages come back after '
            . 'reloading and reloaded user messages show the full text sent to the AI without images or buttons.');
    }

    /**
     * MDL-E2E-012: an answer in progress can be stopped and a failed answer leaves no orphan message in the history.
     * Behat: panel_conversation.feature.
     * [Pendiente:skip]
     */
    public function test_an_answer_can_be_stopped_and_failures_leave_no_orphan_message(): void {
        $this->markTestSkipped('Pending implementation: there is no stop button and a failed answer leaves its question '
            . 'in the history without an answer.');
    }

    /**
     * MDL-E2E-013: the user is told when the panel configuration cannot be loaded. Behat: panel_navigation.feature.
     * [Pendiente:skip]
     */
    public function test_user_is_told_when_the_panel_configuration_cannot_load(): void {
        $this->markTestSkipped('Pending fix: without its configuration the panel is shown empty and does not work, '
            . 'without a notice.');
    }

    /**
     * MDL-E2E-014: an action waiting for confirmation can still be confirmed or cancelled after reloading the page.
     * Behat: panel_conversation.feature.
     * [Pendiente:skip]
     */
    public function test_pending_action_survives_a_page_reload(): void {
        $this->markTestSkipped('Pending fix: after reloading the page the confirmation card disappears and the pending '
            . 'action cannot be confirmed.');
    }

    /**
     * Render the panel the way the sidebar module does, with the strings and the greeting of the current user.
     *
     * @param bool $files Whether file processing is on.
     * @param bool $audio Whether audio processing is on.
     * @return string
     */
    private function render_panel(bool $files, bool $audio): string {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $PAGE->set_url(new \moodle_url('/local/jedomi/ajax/sidebar_config.php'));
        $config = \local_jedomi_get_sidebar_config();
        return $this->render('sidebar', [
            'greeting' => str_replace('{name}', $config['user_name'], $config['strings']['greeting']),
            'strings' => $config['strings'],
            'files_enabled' => $files,
            'audio_enabled' => $audio,
            'attach_enabled' => $files || $audio,
        ]);
    }

    /**
     * MDL-UNIT-067: the panel controls carry their labels, the greeting names the user and presents the assistant,
     * and the footer warns that the AI can make mistakes.
     *
     * @return void
     */
    public function test_panel_greets_the_user_and_shows_the_ai_notice(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']));

        $html = $this->render_panel(true, true);

        foreach (['export_conversation', 'half_width', 'full_width', 'new_conversation', 'close'] as $key) {
            $this->assertStringContainsString('title="' . s(get_string($key, 'local_jedomi')) . '"', $html, $key);
        }
        $greeting = str_replace('{name}', 'Ada Lovelace', get_string('greeting', 'local_jedomi'));
        $this->assertStringContainsString(s($greeting), $html);
        $this->assertStringContainsString(s(get_string('hello', 'local_jedomi')), $html);
        $this->assertMatchesRegularExpression('/class="jedomi-disclaimer">\s*' . preg_quote(s(get_string(
            'ai_disclaimer',
            'local_jedomi'
        )), '/') . '/', $html);
    }

    /**
     * MDL-UNIT-068: each combination of the switches offers exactly the options of the processing that is on.
     *
     * @return void
     */
    public function test_panel_options_follow_the_processing_switches(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $audioonly = $this->render_panel(false, true);
        $this->assertStringNotContainsString('data-type="image"', $audioonly);
        $this->assertStringNotContainsString('data-type="file"', $audioonly);
        $this->assertStringContainsString('id="jedomi-dropzone"', $audioonly);

        $filesonly = $this->render_panel(true, false);
        $this->assertStringNotContainsString('id="jedomi-voice-btn"', $filesonly);
        $this->assertStringNotContainsString('data-type="audio"', $filesonly);
        $this->assertStringNotContainsString('id="jedomi-audio-input"', $filesonly);

        $none = $this->render_panel(false, false);
        $this->assertStringNotContainsString('id="jedomi-dropzone"', $none);
        $this->assertStringNotContainsString('id="jedomi-attach-btn"', $none);

        $both = $this->render_panel(true, true);
        foreach (
            ['data-type="image"', 'data-type="file"', 'data-type="audio"', 'id="jedomi-voice-btn"',
                'id="jedomi-dropzone"', 'id="jedomi-attach-btn"'] as $option
        ) {
            $this->assertStringContainsString($option, $both, $option);
        }
    }

    /**
     * MDL-UNIT-070: a non critical action shows the notice, the description, the details and Confirm and Cancel,
     * without the keyword field; a message send shows send and cancel.
     *
     * @return void
     */
    public function test_confirmation_card_of_each_action_level(): void {
        $this->resetAfterTest();
        $strings = ['pending_action_needed' => 'Needs confirmation', 'confirm_btn_yes' => 'Confirm', 'confirm_btn_no' => 'Cancel',
            'yes_send' => 'Yes, send', 'cancel' => 'Do not send', 'confirm_type_to_proceed' => 'Type the word'];

        $medium = $this->render('local/sidebar/action_confirm', ['issend' => false, 'critical' => false,
            'description' => 'Create agent', 'hasdetails' => true, 'details' => 'Every day at 8', 'strings' => $strings]);
        $critical = $this->render('local/sidebar/action_confirm', ['issend' => false, 'critical' => true, 'keyword' => 'DELETE',
            'description' => 'Delete agent', 'hasdetails' => false, 'strings' => $strings]);
        $send = $this->render('local/sidebar/action_confirm', ['issend' => true, 'countbefore' => 'Send to ', 'count' => 12,
            'countafter' => ' users?', 'preview' => 'Remember the exam', 'strings' => $strings]);

        foreach (['Needs confirmation', 'Create agent', 'Every day at 8', 'Confirm', 'Cancel'] as $text) {
            $this->assertStringContainsString($text, $medium, $text);
        }
        $this->assertStringNotContainsString('confirm-keyword', $medium);
        $this->assertStringContainsString('Type the word', $critical);
        $this->assertStringContainsString('placeholder="DELETE"', $critical);
        $this->assertStringContainsString('data-action="local_jedomi/sidebar/confirm-no"', $critical);
        $this->assertStringContainsString('<strong>12</strong>', $send);
        $this->assertStringContainsString('Remember the exam', $send);
        $this->assertStringContainsString('Yes, send', $send);
        $this->assertStringContainsString('Do not send', $send);
        $this->assertStringNotContainsString('Needs confirmation', $send);
    }

    /**
     * MDL-UNIT-071: assistant answers show Copy, Helpful and Improve; user messages show no rating buttons.
     *
     * @return void
     */
    public function test_only_assistant_answers_offer_copy_and_rating(): void {
        $this->resetAfterTest();
        $strings = ['action_copy' => 'Copy', 'action_helpful' => 'Helpful', 'action_improve' => 'Improve',
            'action_edit' => 'Edit'];

        $assistant = $this->render('local/sidebar/message_actions', ['strings' => $strings]);
        $user = $this->render('local/sidebar/user_actions', ['strings' => $strings]);

        foreach (['Copy', 'Helpful', 'Improve'] as $label) {
            $this->assertStringContainsString($label, $assistant, $label);
        }
        foreach (['copy', 'good', 'bad'] as $action) {
            $this->assertStringNotContainsString('data-action="local_jedomi/sidebar/' . $action . '"', $user, $action);
        }
        $this->assertStringNotContainsString('Helpful', $user);
    }

    /**
     * MDL-UNIT-051: the audio file selector accepts the same formats as the server and recording stops or warns at the
     * maximum duration that can be transcribed.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_audio_selector_formats_and_recording_duration_match_the_server(): void {
        $this->markTestSkipped('Pending fix: the audio selector accepts fewer formats than the server and recording has no '
            . 'duration limit in the browser.');
    }
}
