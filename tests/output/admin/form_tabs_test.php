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

namespace local_jedomi\output\admin;

/**
 * Tests for the admin tabs that wrap a settings form: General, Course availability and Privacy.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\output\admin\general_tab
 * @covers     \local_jedomi\output\admin\courses_tab
 * @covers     \local_jedomi\output\admin\privacy_tab
 */
final class form_tabs_test extends \advanced_testcase {
    /**
     * MDL-INT-020: the General tab shows the AI service card with the link to the Jedomi settings in the provider.
     * The General tab exports the provider settings link and the form, and renders both.
     *
     * @return void
     */
    public function test_general_tab(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $tab = new general_tab('https://example.com/provider', '<form data-test="general"></form>');
        $renderer = $PAGE->get_renderer('local_jedomi');

        $data = $tab->export_for_template($renderer);
        $html = $renderer->render($tab);

        $this->assertSame('https://example.com/provider', $data['provider']['url']);
        $this->assertSame('local_jedomi/admin/general_tab', $tab->get_template_name($renderer));
        $this->assertStringContainsString('href="https://example.com/provider"', $html);
        $this->assertStringContainsString('data-test="general"', $html);
        $this->assertStringContainsString(get_string('ai_heading', 'local_jedomi'), $html);
    }

    /**
     * The Course availability and Privacy tabs render their form.
     *
     * @return void
     */
    public function test_courses_and_privacy_tabs(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $renderer = $PAGE->get_renderer('local_jedomi');

        $courses = $renderer->render(new courses_tab('<form data-test="courses"></form>'));
        $privacy = $renderer->render(new privacy_tab('<form data-test="privacy"></form>'));

        $this->assertStringContainsString('data-test="courses"', $courses);
        $this->assertStringContainsString('data-test="privacy"', $privacy);
    }

    /**
     * MDL-UNIT-106: the AI service card of the General tab lists every setting configured in the provider, including
     * the voice conversation, the voice model and the voice.
     *
     * @return void
     */
    public function test_ai_service_card_lists_the_voice_settings(): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));

        $html = $OUTPUT->render_from_template('local_jedomi/provider_settings_link', ['url' => 'https://example.com/x']);

        foreach (['Enabled switch', 'chat model', 'audio processing', 'voice conversation', 'voice model', 'the voice'] as $part) {
            $this->assertStringContainsString($part, $html, $part);
        }
        force_current_language('es');
        $this->assertStringContainsString(
            'la conversación por voz, el modelo de voz, la voz',
            get_string('provider_settings_info', 'local_jedomi')
        );
        force_current_language('en');
    }
}
