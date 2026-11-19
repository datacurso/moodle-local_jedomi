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
    use \local_jedomi\tests\spanish_strings;

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
     * Source of the sidebar JavaScript modules.
     *
     * @param string ...$modules Modules under amd/src/local/sidebar, or 'sidebar' for the entry module; all when none.
     * @return string
     */
    private static function sidebar_source(string ...$modules): string {
        $dir = __DIR__ . '/../../amd/src';
        if (!$modules) {
            $files = array_merge([$dir . '/sidebar.js'], glob($dir . '/local/sidebar/*.js'));
        } else {
            $files = array_map(fn($module) => $module === 'sidebar' ? "{$dir}/sidebar.js" : "{$dir}/local/sidebar/{$module}.js",
                $modules);
        }
        return implode("\n", array_map('file_get_contents', $files));
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
            'files_enabled' => true, 'documents_enabled' => true, 'audio_enabled' => true, 'attach_enabled' => true]);

        foreach (
            ['jedomi-trigger', 'jedomi-sidebar', 'jedomi-messages', 'jedomi-input', 'jedomi-send', 'jedomi-clear',
                'jedomi-close', 'jedomi-export', 'jedomi-expand-half', 'jedomi-expand-full', 'jedomi-quick-actions',
                'jedomi-thinking-wrapper', 'jedomi-thinking-toggle-btn', 'jedomi-attach-btn', 'jedomi-attach-menu',
                'jedomi-image-input', 'jedomi-file-input', 'jedomi-image-preview', 'jedomi-preview-img',
                'jedomi-remove-image', 'jedomi-file-preview',
                'jedomi-dropzone', 'jedomi-context-label', 'jedomi-voice-btn', 'jedomi-audio-input',
                'jedomi-voice-bar', 'jedomi-stop'] as $id
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
     * JEDOMI-SEC-013, SEC-JD-043: a card whose data was cut shows the notice and only lets the user cancel it, in the
     * card of an action and in the card of a message.
     *
     * @return void
     */
    public function test_cut_action_card_shows_the_notice_and_cannot_be_confirmed(): void {
        $this->resetAfterTest();
        $strings = ['pending_action_truncated' => 'Cut notice'];

        $cut = $this->render('local/sidebar/action_confirm', ['issend' => false, 'critical' => true, 'keyword' => 'CONFIRM',
            'description' => 'Create agent', 'hasdetails' => true, 'details' => 'Prompt: a', 'truncated' => true,
            'strings' => $strings]);
        $cutsend = $this->render('local/sidebar/action_confirm', ['issend' => true, 'countbefore' => 'Send to ', 'count' => 3,
            'countafter' => ' users?', 'preview' => 'Hello', 'truncated' => true, 'strings' => $strings]);
        $whole = $this->render('local/sidebar/action_confirm', ['issend' => false, 'critical' => false,
            'description' => 'Create agent', 'hasdetails' => true, 'details' => 'Prompt: a', 'strings' => $strings]);

        foreach ([$cut, $cutsend] as $html) {
            $this->assertStringContainsString('Cut notice', $html);
            $this->assertStringNotContainsString('confirm-yes', $html);
            $this->assertStringNotContainsString('confirm-keyword', $html);
            $this->assertStringContainsString('data-action="local_jedomi/sidebar/confirm-no"', $html);
        }
        $this->assertStringNotContainsString('Cut notice', $whole);
        $this->assertStringContainsString('confirm-yes', $whole);
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

        $html = $this->render('sidebar', $base + ['files_enabled' => true, 'documents_enabled' => true, 'audio_enabled' => false,
            'attach_enabled' => true]);
        $this->assertStringContainsString('data-type="image"', $html);
        $this->assertStringContainsString('data-type="file"', $html);
        $this->assertStringNotContainsString('data-type="audio"', $html);
        $this->assertStringNotContainsString('id="jedomi-voice-btn"', $html);
        $this->assertStringNotContainsString('id="jedomi-voice-bar"', $html);
        $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $html);
    }

    /**
     * MDL-UNIT-068 / MDL-UNIT-111 / MDL-UNIT-112: the voice view has the sphere, the state text and the exit button, and the button
     * sits next to the microphone only while the voice is enabled.
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
     *
     * The format runs in the browser; the rules of local_jedomi/sidebar are checked here.
     *
     * @return void
     */
    public function test_answer_keeps_underscores_and_formats_headings_and_lists(): void {
        $source = self::sidebar_source('markdown');

        $this->assertStringNotContainsString(".replace(/_(.+?)_/g, '<em>$1</em>')", $source, 'Underscores inside names');
        $this->assertStringContainsString(
            'const ITALIC_PATTERN = /(^|[^\w',
            $source,
            'Italics only when the underscores are not part of a word'
        );
        $this->assertStringContainsString(
            'keep(' . chr(96) . '<code>${code}</code>' . chr(96) . ')',
            $source,
            'Code is never formatted'
        );
        $this->assertStringContainsString('keep(url)', $source, 'Addresses keep their underscores');
        $this->assertStringContainsString('class="jedomi-md-heading"', $source, 'Headings');
        $this->assertStringContainsString(
            '<ol${start !== 1 ? ' . chr(96) . ' start="${start}"' . chr(96),
            $source,
            'Numbered lists'
        );
    }

    /**
     * MDL-UNIT-044: link type labels, the CSV file name and the export header and labels are in the user language.
     *
     * @return void
     */
    public function test_panel_texts_are_in_the_user_language(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $source = self::sidebar_source();
        $fixedtexts = ["type: 'Curso'", "type: 'Notas'", "type: 'Quiz'", "'jedomi_datos.csv'", 'JEDOMI — Conversation',
            'm.role.toUpperCase()'];
        foreach ($fixedtexts as $fixed) {
            $this->assertStringNotContainsString($fixed, $source, "'{$fixed}' is no longer fixed in the panel");
        }

        $texts = self::sidebar_source('markdown', 'csv', 'conversation');
        $keys = ['export_csv_filename', 'export_filename', 'export_role_user', 'export_title', 'link_type_admin',
            'link_type_assign', 'link_type_course', 'link_type_grades', 'link_type_messages', 'link_type_profile',
            'link_type_quiz'];
        $manager = get_string_manager();
        foreach ($keys as $key) {
            $this->assertStringContainsString("L.{$key}", str_replace('cfg.strings?.', 'L.', $texts), "{$key} is used");
            $this->assertNotSame(
                $manager->get_string($key, 'local_jedomi', null, 'en'),
                self::plugin_spanish_string($key),
                "{$key} is translated"
            );
        }
        $this->assertStringContainsString("'link_type_course'", file_get_contents($CFG->dirroot . '/local/jedomi/lib.php'));
    }

    /**
     * MDL-UNIT-073: the location of the page sent as context is recognised for every kind of page, including the
     * private files page. Behat: panel_location.feature.
     *
     * @return void
     */
    public function test_page_location_is_recognised_for_every_kind_of_page(): void {
        $this->resetAfterTest();
        $cases = [
            'course' => ['/course/view.php', 'course-view-topics', true, false],
            'activity' => ['/mod/page/view.php', 'mod-page-view', true, true],
            'grades' => ['/grade/report/grader/index.php', 'grade-report-grader-index', true, false],
            'admin' => ['/admin/search.php', 'admin-search', false, false],
            'dashboard' => ['/my/', 'my-index', false, false],
            'messages' => ['/message/index.php', 'message-index', false, false],
            'profile' => ['/user/profile.php', 'user-profile', false, false],
            'calendar' => ['/calendar/view.php', 'calendar-view', false, false],
            'files' => ['/user/files.php', 'user-files', false, false],
            'unknown' => ['/tag/search.php', 'tag-search', false, false],
        ];
        foreach ($cases as $expected => [$path, $pagetype, $incourse, $inactivity]) {
            $this->assertSame($expected, \local_jedomi_page_location($path, $pagetype, $incourse, $inactivity), $path);
        }
        $this->assertSame('', \local_jedomi_clean_page_path('/user/../admin/x.php'), 'A path with dots is dropped');
        $this->assertSame('/user/files.php', \local_jedomi_clean_page_path('/user/files.php?id=3#top'));
        $this->assertSame('', \local_jedomi_clean_page_path('https://evil.example/x.php'));
    }

    /**
     * MDL-E2E-004: the panel is hidden from the keyboard while closed, keeps and gives back the focus, announces the
     * answers and names every button. The panel fills the screen on mobile, so there is no backdrop to touch: it was
     * removed. Behat: panel_navigation.feature.
     *
     * @return void
     */
    public function test_panel_meets_the_basic_accessibility_criteria(): void {
        $this->resetAfterTest();
        $strings = ['open_sidebar' => 'Open Jedomi', 'new_conversation' => 'New conversation', 'close' => 'Close',
            'export_conversation' => 'Export', 'half_width' => 'Half', 'full_width' => 'Full', 'attach' => 'Attach',
            'send' => 'Send', 'stop_answer' => 'Stop the answer', 'keyboard_shortcuts' => 'Keyboard shortcuts: Alt+Shift+J',
            'image' => 'Image', 'file' => 'File', 'audio_file' => 'Audio file'];
        $html = $this->render('sidebar', ['greeting' => 'Hi', 'strings' => $strings, 'files_enabled' => true,
            'documents_enabled' => true, 'audio_enabled' => true, 'attach_enabled' => true, 'voice_enabled' => true]);

        $this->assertMatchesRegularExpression(
            '~<div id="jedomi-sidebar"[^>]*aria-hidden="true"[^>]*\sinert>~',
            $html,
            'The closed panel is out of the Tab order and hidden from screen readers'
        );
        $this->assertMatchesRegularExpression(
            '~id="jedomi-messages"[^>]*role="log"[^>]*aria-live="polite"~',
            $html,
            'New answers are announced'
        );
        $this->assertStringNotContainsString('jedomi-overlay', $html, 'No backdrop that never appears');
        $this->assertStringContainsString('aria-label="New conversation" aria-keyshortcuts="Alt+Shift+N"', $html);
        $this->assertMatchesRegularExpression(
            '~aria-keyshortcuts="Alt\+Shift\+N"><svg aria-hidden="true"~',
            $html,
            'The "+" icon is not its name'
        );

        // Every button has a name: a text of its own or an aria-label.
        preg_match_all('~<button\b([^>]*)>(.*?)</button>~s', $html, $buttons, PREG_SET_ORDER);
        $this->assertNotEmpty($buttons);
        foreach ($buttons as [$button, $attributes, $content]) {
            $content = preg_replace(['~<svg\b.*?</svg>~s', '~<span aria-hidden="true">.*?</span>~s'], '', $content);
            $text = trim(strip_tags($content));
            $this->assertTrue($text !== '' || str_contains($attributes, 'aria-label="'), 'Button without a name: ' . $button);
        }

        $source = self::sidebar_source('panel', 'events', 'chat');
        $this->assertStringContainsString("panel.setAttribute('inert', '')", $source, 'Closing hides the panel again');
        $this->assertStringContainsString("panel.removeAttribute('inert')", $source, 'Opening makes it reachable');
        $this->assertStringContainsString("addEventListener('keydown', trapFocus)", $source, 'The focus stays in the panel');
        $this->assertStringContainsString('back?.focus()', $source, 'The focus goes back when the panel closes');
        $this->assertStringContainsString("setAttribute('aria-busy', 'true')", $source, 'Answers are announced once complete');
    }

    /**
     * MDL-E2E-005: the shortcuts of the panel do not clash with the browser and the new conversation shortcut asks
     * for confirmation. Behat: panel_navigation.feature.
     *
     * @return void
     */
    public function test_shortcuts_do_not_clash_with_the_browser_nor_lose_the_conversation(): void {
        $source = self::sidebar_source();

        $this->assertStringNotContainsString("e.key === 'J'", $source, 'Ctrl+Shift+J opens the browser console');
        $this->assertStringNotContainsString("e.key === 'N'", $source, 'Ctrl+Shift+N opens a private window');
        $shortcuts = self::sidebar_source('events', 'conversation');
        $this->assertStringContainsString("if (!e.altKey || !e.shiftKey || e.ctrlKey || e.metaKey) {", $shortcuts);
        $this->assertStringContainsString("e.code === 'KeyJ'", $shortcuts);
        $this->assertStringContainsString("e.code === 'KeyN'", $shortcuts);
        $this->assertStringContainsString('Notification.saveCancelPromise(', $shortcuts, 'The new conversation is confirmed');
        $this->assertStringContainsString("S.new_conversation_confirm", $shortcuts);
        $this->assertStringContainsString('Alt+Shift+N', get_string('keyboard_shortcuts', 'local_jedomi'));
    }

    /**
     * MDL-E2E-011: only the last question can be edited, the removed messages do not come back after reloading and
     * the reloaded conversation shows the text the user wrote and the buttons of the answers.
     * Behat: panel_conversation.feature.
     *
     * Images are not kept in the history (it lives in the session), so they are not shown again after a reload.
     *
     * @return void
     */
    public function test_editing_acts_on_the_chosen_message_and_reloading_keeps_the_conversation(): void {
        $source = self::sidebar_source('messages', 'history');

        $this->assertStringContainsString(
            "msgs.querySelectorAll('.jedomi-user-actions').forEach(node => node.remove())",
            $source,
            'The Edit button is only under the last question'
        );
        $this->assertStringContainsString(
            "historyRequest({action: 'undolast'})",
            $source,
            'The edited question leaves the history on the server'
        );
        $this->assertStringContainsString("msg.display", $source, 'The reloaded question shows what the user wrote');
        $this->assertStringContainsString(
            "addMessageActions(wrap, content, msg.id || '')",
            $source,
            'The reloaded answers keep their Copy and rating buttons'
        );

        $conversation = file_get_contents(__DIR__ . '/../../conversation.php');
        $this->assertStringContainsString("case 'undolast':", $conversation);
        $this->assertStringContainsString("\$message['display'] = \$display;", $conversation);
    }

    /**
     * MDL-E2E-012: an answer in progress can be stopped, a new conversation stops it too and a failed answer leaves
     * no orphan message in the history. Behat: panel_conversation.feature.
     *
     * @return void
     */
    public function test_an_answer_can_be_stopped_and_failures_leave_no_orphan_message(): void {
        $source = self::sidebar_source('events', 'chat', 'conversation');

        $this->assertStringContainsString("on('jedomi-stop', 'click', stopAnswer)", $source, 'Stop button');
        $this->assertStringContainsString(
            'voiceHooks?.signal || controller?.signal',
            $source,
            'The written answer can be cancelled'
        );
        $this->assertStringContainsString('controller.cleared = true;', $source, 'A new conversation stops the answer');
        $this->assertStringContainsString('cfg.strings?.answer_stopped', $source, 'The kept text is marked as stopped');
        $this->assertStringContainsString(
            "userWrap.dataset.orphan = '1';",
            $source,
            'A failed question leaves the history'
        );

        $proxy = file_get_contents(__DIR__ . '/../../chatproxy.php');
        $this->assertStringContainsString('ignore_user_abort(true);', $proxy, 'A stopped answer never stops a tool halfway');
        $handler = file_get_contents(__DIR__ . '/../../classes/proxy/handler.php');
        $this->assertStringContainsString('if (connection_aborted()) {', $handler, 'No AI round after the answer is stopped');
    }

    /**
     * MDL-E2E-013: the user is told when the panel configuration cannot be loaded. Behat: panel_navigation.feature.
     *
     * @return void
     */
    public function test_user_is_told_when_the_panel_configuration_cannot_load(): void {
        $this->resetAfterTest();
        $source = self::sidebar_source();
        $notice = self::sidebar_source('sidebar', 'config_error');
        $this->assertStringContainsString('showConfigError', $notice);
        $this->assertStringNotContainsString('bootstrap with empty config', $source, 'The panel never starts empty');
        $this->assertStringContainsString("setAttribute('role', 'alert')", $notice);
        foreach (['config_load_error', 'config_load_retry'] as $key) {
            $this->assertStringContainsString($key, $notice, $key);
            $this->assertNotEmpty(get_string($key, 'local_jedomi'), $key);
        }
        $hook = file_get_contents(__DIR__ . '/../../classes/hook_callbacks.php');
        $expected = "'config_load_error' => get_string('config_load_error', 'local_jedomi')";
        $this->assertStringContainsString($expected, $hook, 'The notice texts are given with the page, before the configuration');
    }

    /**
     * MDL-E2E-014: an action waiting for confirmation can still be confirmed or cancelled after reloading the page.
     * Behat: panel_conversation.feature. The panel loads the cards with the conversation (conversation.php, action get).
     *
     * @return void
     */
    public function test_pending_action_survives_a_page_reload(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $service = \local_jedomi\local\service\pending_action_service::class;
        $kept = $service::hold((int) $user->id, 'create_agent', ['name' => 'Reload agent', 'prompt' => 'Daily summary']);
        $expired = $service::hold((int) $user->id, 'delete_agent', ['id' => 3]);
        $DB->set_field($service::TABLE, 'timecreated', time() - $service::EXPIRY - 1, ['id' => $expired['id']]);
        $service::hold((int) $other->id, 'delete_agent', ['id' => 4]);

        $cards = $service::cards_for_user((int) $user->id);

        $this->assertSame([$kept], $cards, 'Only the own action still in time comes back, with the same card');
        $this->setUser($user);
        $result = $service::resolve($kept['id'], (int) $user->id, false);
        $this->assertSame($service::STATUS_CANCELLED, $result['status'], 'The restored action can still be resolved');
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
            'greeting' => $config['welcome_message'],
            'assistant' => $config['assistant'],
            'strings' => $config['strings'],
            'files_enabled' => $files,
            'documents_enabled' => $files,
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
        $greeting = str_replace(
            ['{username}', '{assistantname}'],
            ['Ada Lovelace', 'Jedomi'],
            get_string('welcome_message_default', 'local_jedomi')
        );
        $this->assertStringContainsString(s($greeting), $html);
        $this->assertMatchesRegularExpression('/class="jedomi-disclaimer">\s*' . preg_quote(s(get_string(
            'ai_disclaimer',
            'local_jedomi',
            'Jedomi'
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
     *
     * @return void
     */
    public function test_audio_selector_formats_and_recording_duration_match_the_server(): void {
        $this->resetAfterTest();
        $html = $this->render('sidebar', ['strings' => [], 'files_enabled' => true, 'documents_enabled' => true,
            'audio_enabled' => true, 'attach_enabled' => true]);
        $this->assertMatchesRegularExpression('/id="jedomi-audio-input" accept="([^"]+)"/', $html);
        preg_match('/id="jedomi-audio-input" accept="([^"]+)"/', $html, $match);
        $accepted = array_map(fn($extension) => ltrim($extension, '.'), explode(',', $match[1]));
        $server = \local_jedomi\local\service\audio_service::EXTENSIONS;
        $this->assertEqualsCanonicalizing($server, $accepted, 'The selector accepts the formats of the server');

        $source = self::sidebar_source('attachments', 'voice_message');
        $this->assertSame(1, preg_match("/const AUDIO_EXTENSIONS = \\[([^\\]]+)\\];/", $source, $js));
        $dropped = array_map(fn($extension) => trim($extension, " '"), explode(',', $js[1]));
        $this->assertEqualsCanonicalizing($server, $dropped, 'Dropped audio files use the formats of the server');
        $this->assertStringContainsString('const VOICE_MESSAGE_MAX_MS = 300000;', $source, 'A recording stops at 5 minutes');
        $this->assertStringContainsString('voice_too_long', $source, 'The user is told why the recording stopped');
    }

    /**
     * MDL-UNIT-117: pressing the voice conversation button while a message is sent, during a recording or with a voice
     * message pending tells the user why, and the automatic exit after the silence is announced.
     *
     * @return void
     */
    public function test_voice_conversation_button_never_fails_silently(): void {
        $this->resetAfterTest();
        $source = self::sidebar_source();
        $voicemode = self::sidebar_source('voice_mode');
        $this->assertMatchesRegularExpression("/if \\(state\\.sending\\) \\{\\s*return 'sending';/", $voicemode);
        $this->assertMatchesRegularExpression("/if \\(state\\.recorder\\) \\{\\s*return 'recording';/", $voicemode);
        $this->assertMatchesRegularExpression("/if \\(state\\.voice\\) \\{\\s*return 'pending';/", $voicemode);
        $this->assertStringContainsString('voice_mode_busy_${busy}', $voicemode, 'Each busy reason has its notice');
        $silent = 'sending || recorder || voice || !cfg.speech_url) return;';
        $this->assertStringNotContainsString($silent, $source, 'The button no longer returns silently while busy');
        $this->assertStringContainsString('voice_mode_idle_exit', $voicemode, 'The exit after the silence is announced');
        $spoken = '/voice_mode_idle_exit[\\s\\S]{0,400}speakVoiceNotice\\(notice/';
        $this->assertMatchesRegularExpression($spoken, $voicemode, 'The exit notice is also spoken with the voice of the browser');

        $manager = get_string_manager();
        $lib = file_get_contents(__DIR__ . '/../../lib.php');
        $keys = ['voice_mode_busy_sending', 'voice_mode_busy_recording', 'voice_mode_busy_pending', 'voice_mode_idle_exit'];
        foreach ($keys as $key) {
            $this->assertNotSame(
                $manager->get_string($key, 'local_jedomi', null, 'en'),
                self::plugin_spanish_string($key),
                "{$key} is translated"
            );
            $this->assertStringContainsString("get_string('{$key}', 'local_jedomi')", $lib, "{$key} reaches the sidebar");
        }
    }

    /**
     * MDL-INT-052: several dropped files are all kept, a cut text is announced and GIF images or disabled types get a
     * clear notice in the browser. Behat: chat_system.feature.
     *
     * @return void
     */
    public function test_dropped_files_are_all_kept_with_clear_notices(): void {
        $this->resetAfterTest();
        $html = $this->render('local/sidebar/file_chips', ['files' => [
            ['index' => 0, 'name' => 'first.txt', 'removelabel' => 'Remove first.txt'],
            ['index' => 1, 'name' => '<b>second</b>.txt', 'removelabel' => 'Remove second.txt'],
        ]]);
        $this->assertSame(2, substr_count($html, 'data-action="local_jedomi/sidebar/remove-file"'), 'One chip per file');
        $this->assertStringContainsString('data-index="1"', $html);
        $this->assertStringContainsString('first.txt', $html);
        $this->assertStringContainsString('&lt;b&gt;second&lt;/b&gt;.txt', $html, 'File names are escaped');

        $source = self::sidebar_source('attachments', 'composer');
        $this->assertStringContainsString('const MAX_ATTACHMENTS = 5;', $source);
        $imagetypes = "const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];";
        $this->assertStringContainsString($imagetypes, $source, 'A dropped GIF is refused in the browser, as on the server');
        $notices = ['file_attach_limit', 'file_truncated', 'image_error_unsupported', 'files_error_disabled',
            'audio_error_disabled', 'image_attach_limit'];
        foreach ($notices as $key) {
            $this->assertStringContainsString("S.{$key}", $source, "Notice {$key}");
        }
        $this->assertStringContainsString('attachment.controller?.abort()', $source, 'Removing a file cancels its reading');
    }

    /**
     * MDL-INT-112: in the voice conversation only what the user says is transcribed and charged: an utterance has a
     * maximum duration, failures do not fill the chat and a failed transcription is also said aloud.
     * Behat: voice_conversation.feature.
     *
     * @return void
     */
    public function test_voice_conversation_only_transcribes_what_the_user_says(): void {
        $source = self::sidebar_source('voice_mode', 'voice_turn');

        $this->assertMatchesRegularExpression(
            '~const VOICE_MODE_MAX_UTTERANCE_MS = 30000;~',
            $source,
            'An utterance has a maximum duration'
        );
        $this->assertStringContainsString(
            'lastMessage.dataset.voiceNotice === notice',
            $source,
            'The same notice right after itself is not repeated'
        );
        $this->assertStringContainsString('speakVoiceNotice(notice, () => {', $source, 'The failure is said aloud');
        $this->assertStringContainsString(
            'window.speechSynthesis?.cancel();',
            $source,
            'Ending the conversation stops a notice read aloud'
        );
    }

    /**
     * MDL-E2E-011 (images after a reload): the history keeps how many images a question had, never the images, and the
     * chat shows a translated note in their place after a reload.
     */
    public function test_images_of_a_question_leave_a_note_after_a_reload(): void {
        $source = self::sidebar_source('chat', 'history', 'messages');
        $this->assertMatchesRegularExpression('/if \(image\) \{\s*userMessage\.images = 1;/', $source);
        $this->assertStringContainsString('fields.images = String(last.images)', $source);
        $this->assertMatchesRegularExpression('/msg\.images > 0\) \{\s*addImagePlaceholder\(wrap, msg\.images\)/', $source);
        $this->assertStringContainsString('image_not_kept', $source);

        $endpoint = file_get_contents(__DIR__ . '/../../conversation.php');
        $this->assertStringContainsString("\$message['images'] = \$images;", $endpoint);
        $this->assertStringNotContainsString('base64', $endpoint, 'The images themselves are never stored');

        $manager = get_string_manager();
        $this->assertNotSame(
            $manager->get_string('image_not_kept', 'local_jedomi', null, 'en'),
            self::plugin_spanish_string('image_not_kept')
        );
    }

    /**
     * Personalization: the button and the header show the avatar and the name of the assistant, also in the text
     * alternatives, and carry the classes of the configured corner and panel side.
     *
     * @return void
     */
    public function test_panel_shows_the_configured_assistant(): void {
        $this->resetAfterTest();
        $assistant = ['name' => 'Robo <3', 'avatar_url' => 'https://example.com/robo.png', 'corner' => 'top-right',
            'side' => 'right', 'x' => 10, 'y' => 80];

        $html = $this->render('sidebar', ['greeting' => "Hi <b>Ada</b>\nI am Robo", 'assistant' => $assistant,
            'strings' => ['open_sidebar' => 'Open Robo <3']]);

        $this->assertMatchesRegularExpression(
            '~<button type="button" id="jedomi-trigger" class="jedomi-trigger jedomi-fab-top-right"[^>]*'
                . 'aria-label="Open Robo &lt;3"~',
            $html
        );
        $this->assertStringContainsString(
            '<img class="jedomi-trigger-avatar" src="https://example.com/robo.png" alt="Robo &lt;3"',
            $html
        );
        $this->assertStringContainsString('<span class="jedomi-trigger-label">Robo &lt;3</span>', $html);
        $this->assertMatchesRegularExpression(
            '~<div id="jedomi-sidebar" class="jedomi-sidebar jedomi-closed jedomi-side-right"[^>]*aria-label="Robo &lt;3"~',
            $html
        );
        $this->assertStringContainsString('<img class="jedomi-logo-avatar" src="https://example.com/robo.png" alt=""', $html);
        $this->assertStringContainsString('<strong class="jedomi-assistant-name">Robo &lt;3</strong>', $html);
        // The welcome message is text: escaped, its lines kept by the stylesheet.
        $this->assertStringContainsString("Hi &lt;b&gt;Ada&lt;/b&gt;\nI am Robo", $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    /**
     * Personalization: without an avatar image the button and the header keep the Jedomi icon.
     *
     * @return void
     */
    public function test_panel_keeps_the_jedomi_icon_without_an_avatar(): void {
        $this->resetAfterTest();
        $assistant = ['name' => 'Jedomi', 'avatar_url' => null, 'corner' => 'bottom-left', 'side' => 'left', 'x' => 24,
            'y' => 24];

        $html = $this->render('sidebar', ['greeting' => 'Hi', 'assistant' => $assistant, 'strings' => []]);

        $this->assertStringNotContainsString('<img class="jedomi-trigger-avatar"', $html);
        $this->assertStringNotContainsString('<img class="jedomi-logo-avatar"', $html);
        $this->assertMatchesRegularExpression('~id="jedomi-trigger"[^>]*>\s*<svg aria-hidden="true"~', $html);
        $this->assertStringContainsString('jedomi-fab-bottom-left', $html);
        $this->assertStringContainsString('jedomi-side-left', $html);
    }

    /**
     * Personalization: the module places the button with CSS variables, and on phones the stylesheet keeps the bottom
     * corner of phase 16 whatever corner is configured, without touching the layers of the panel and the button.
     *
     * @return void
     */
    public function test_position_is_applied_with_css_variables_and_kept_on_phones(): void {
        $source = self::sidebar_source('sidebar');
        $this->assertStringContainsString("setProperty('--j-fab-x'", $source);
        $this->assertStringContainsString("setProperty('--j-fab-y'", $source);

        $css = file_get_contents(__DIR__ . '/../../styles.css');
        $this->assertStringContainsString('var(--j-fab-x, 24px)', $css);
        $this->assertMatchesRegularExpression(
            '~@media \(max-width: 767px\) \{[^@]*?\.jedomi-trigger,\s*\.jedomi-trigger\[class\*="jedomi-fab-"\] \{[^}]*'
                . 'bottom: 16px;[^}]*top: auto;~',
            $css
        );
        $this->assertStringContainsString('z-index: 1037;', $css);
        $this->assertStringContainsString('z-index: 1036;', $css);
    }

    /**
     * With the custom AI service only images are read: the image option stays, the document option and input go.
     *
     * @return void
     */
    public function test_documents_option_follows_the_documents_switch(): void {
        $this->resetAfterTest();

        $html = $this->render('sidebar', ['greeting' => 'Hi', 'strings' => [], 'files_enabled' => true,
            'documents_enabled' => false, 'audio_enabled' => false, 'attach_enabled' => true]);

        $this->assertStringContainsString('data-type="image"', $html);
        $this->assertStringContainsString('id="jedomi-image-input"', $html);
        $this->assertStringNotContainsString('data-type="file"', $html);
        $this->assertStringNotContainsString('id="jedomi-file-input"', $html);
    }

    /**
     * JEDOMI-SEC-005: a link of the answer to another site is marked as external with its host and an accessible label
     * in the user language, opens in a new window without referrer, and only the origin of the site counts as internal.
     * The format runs in the browser; the rules of local_jedomi/sidebar are checked here.
     *
     * @return void
     */
    public function test_external_links_are_marked(): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $source = self::sidebar_source('markdown');

        $this->assertStringContainsString('class="jedomi-external-link" target="_blank" rel="noopener noreferrer"', $source);
        $this->assertStringContainsString('<span class="jedomi-external-badge" aria-hidden="true">↗ ${host}</span>', $source);
        $this->assertStringContainsString('<span class="sr-only">(${label}: ${host})</span>', $source);
        $this->assertStringContainsString('S.link_external', $source, 'The label is a language string');
        $this->assertStringContainsString('new URL(url).origin === new URL(cfg.wwwroot).origin', $source);
        $this->assertStringNotContainsString('url.includes(wwwroot)', self::sidebar_source(), 'An address holding the site address');
        $this->assertStringContainsString('\/(?![\/\\\\]))/i.test(url)', $source, '/\ is not a relative path');

        $this->setUser($this->getDataGenerator()->create_user());
        $PAGE->set_url(new \moodle_url('/local/jedomi/ajax/sidebar_config.php'));
        $strings = \local_jedomi_get_sidebar_config()['strings'];
        $this->assertSame(get_string('link_external', 'local_jedomi'), $strings['link_external']);
        $this->assertNotSame($strings['link_external'], self::plugin_spanish_string('link_external'), 'Translated');
    }
}
