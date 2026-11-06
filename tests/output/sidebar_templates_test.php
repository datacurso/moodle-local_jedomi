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
}
