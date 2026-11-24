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

namespace local_jedomi\form;

/**
 * Tests for the cards and subsections that group the fields of the forms of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\form\card_sections
 * @covers     \local_jedomi\form\general_settings_form
 * @covers     \local_jedomi\form\course_settings_form
 * @covers     \local_jedomi\form\privacy_settings_form
 * @covers     \local_jedomi\form\personalization_settings_form
 * @covers     \local_jedomi\form\telegram_settings_form
 */
final class card_sections_test extends \advanced_testcase {
    /**
     * Roles given to the forms that list them.
     *
     * @return array
     */
    private static function roles(): array {
        return [['id' => 5, 'shortname' => 'student', 'name' => 'Student']];
    }

    /**
     * Render a form as the settings page does.
     *
     * @param \moodleform $form Form to render.
     * @return string
     */
    private static function render_form(\moodleform $form): string {
        ob_start();
        $form->display();
        return ob_get_clean();
    }

    /**
     * Forms of the settings and the cards they show, in order: title key, subtitle key and icon colour.
     *
     * @return array
     */
    public static function forms_provider(): array {
        return [
            'General' => [general_settings_form::class, [], [
                ['security_heading', 'security_heading_help', 'red'],
                ['profiler_heading', 'profiler_heading_help', 'amber'],
                ['sql_heading', 'sql_heading_help', 'blue'],
                ['chat_limit_heading', 'chat_limit_heading_help', 'green'],
            ]],
            'Course availability' => [course_settings_form::class, [], [
                ['course_availability_heading', 'course_availability_heading_help', 'green'],
            ]],
            'Privacy' => [privacy_settings_form::class, ['roles' => self::roles()], [
                ['privacy_access_heading', 'privacy_access_heading_help', 'blue'],
                ['privacy_anon_heading', 'privacy_anon_heading_help', 'indigo'],
            ]],
            'Personalization' => [personalization_settings_form::class, [], [
                ['personalization_heading_avatar', 'personalization_heading_avatar_help', 'purple'],
                ['personalization_heading_position', 'personalization_heading_position_help', 'sky'],
                ['personalization_heading_assistant', 'personalization_heading_assistant_help', 'green'],
            ]],
        ];
    }

    /**
     * Each section of the settings forms is a card with an icon, a title and a subtitle, like the cards of the APIs
     * tab, with no collapsible section nor "Expand all" link, and the markup stays balanced.
     *
     * @dataProvider forms_provider
     * @param string $class Form class.
     * @param array $customdata Custom data of the form.
     * @param array $cards Expected cards: title key, subtitle key and colour.
     * @return void
     */
    public function test_sections_are_cards(string $class, array $customdata, array $cards): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));

        $html = self::render_form(new $class(null, $customdata));

        $this->assertSame(count($cards), substr_count($html, 'class="card local_jedomi-admin-card'));
        $offset = 0;
        foreach ($cards as [$title, $subtitle, $color]) {
            $pattern = '~<div class="card local_jedomi-admin-card mb-4">\s*'
                . '<div class="card-header local_jedomi-admin-card-header">\s*'
                . '<span class="local_jedomi-admin-card-icon local_jedomi-admin-card-icon-' . $color . '">.*?</span>\s*'
                . '<div>\s*<h5 class="mb-0">' . preg_quote(s(get_string($title, 'local_jedomi')), '~') . '</h5>\s*'
                . '<small class="text-muted d-block">' . preg_quote(s(get_string($subtitle, 'local_jedomi')), '~')
                . '</small>~s';
            $this->assertSame(1, preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset), "Card {$title}");
            $offset = $match[0][1] + 1;
        }
        $this->assertStringNotContainsString('collapsible', $html);
        $this->assertStringNotContainsString('collapseexpand', $html);
        $this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'), 'Every div is closed');
        $this->assertSame(substr_count($html, '<fieldset'), substr_count($html, '</fieldset>'), 'Every fieldset is closed');
    }

    /**
     * The General form still saves after its sections became cards.
     *
     * @return void
     */
    public function test_general_form_with_cards_still_returns_its_data(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        general_settings_form::mock_submit(['spt_secret' => 's3cret', 'profiler_slow_ms' => 500, 'profiler_retention_days' => 7]);

        $data = (new general_settings_form())->get_data();

        $this->assertSame('s3cret', $data->spt_secret);
        $this->assertSame(500, (int) $data->profiler_slow_ms);
        $this->assertSame(7, (int) $data->profiler_retention_days);
    }

    /**
     * The Telegram form goes inside the Telegram card: its sections are plain subsection titles, not cards nor
     * collapsible sections, and there is no "Expand all" link.
     *
     * @return void
     */
    public function test_telegram_form_uses_subsection_titles(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'integrations']));

        $html = self::render_form(new telegram_settings_form(null, ['roles' => self::roles(), 'tokenset' => true]));

        foreach (['telegram_bot_heading', 'telegram_limits_heading', 'telegram_access_heading'] as $key) {
            $title = preg_quote(s(get_string($key, 'local_jedomi')), '~');
            $this->assertMatchesRegularExpression(
                '~<h6 class="local_jedomi-admin-subsection-title"[^>]*>' . $title . '</h6>~',
                $html
            );
        }
        $this->assertStringNotContainsString('local_jedomi-admin-card', $html);
        $this->assertStringNotContainsString('collapsible', $html);
        $this->assertStringNotContainsString('collapseexpand', $html);
        $this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'), 'Every div is closed');
    }

    /**
     * The header of the form cards is the same markup as the header of the shared card template, here the AI
     * service card of the General tab.
     *
     * @return void
     */
    public function test_card_header_matches_the_shared_card(): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $normalise = static fn(string $html): string => trim(preg_replace('/\s+/', ' ', preg_replace('/>\s+</', '><', $html)));

        $header = $OUTPUT->render_from_template('local_jedomi/admin/card_header', [
            'icon' => 'i/settings',
            'color' => 'violet',
            'title' => get_string('ai_heading', 'local_jedomi'),
            'subtitle' => get_string('ai_heading_help', 'local_jedomi'),
        ]);
        $tab = $PAGE->get_renderer('local_jedomi')->render(new \local_jedomi\output\admin\general_tab('https://example.com', ''));

        $this->assertStringContainsString($normalise($header), $normalise($tab));
    }
}
