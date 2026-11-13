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

use local_jedomi\local\personalization;

/**
 * Tests for the form of the Personalization tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\form\personalization_settings_form
 */
final class personalization_settings_form_test extends \advanced_testcase {
    /**
     * Values of a valid submission.
     *
     * @return array
     */
    private static function valid_submission(): array {
        return [
            'avatar' => '02',
            'position_corner' => 'top-right',
            'position_x' => 40,
            'position_y' => 60,
            'position_side' => 'right',
            'assistant_name' => 'Robo',
            'welcome_message' => 'Hi {firstname}',
            'custom_prompt' => 'Be brief.',
            'customavatar' => file_get_unused_draft_itemid(),
        ];
    }

    /**
     * A valid submission returns every field, and the position as the service expects it.
     *
     * @return void
     */
    public function test_submitted_values_are_returned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        personalization_settings_form::mock_submit(self::valid_submission());

        $data = (new personalization_settings_form())->get_data();

        $this->assertNotNull($data);
        $this->assertSame('02', $data->avatar);
        $this->assertSame('Robo', $data->assistant_name);
        $this->assertSame('Hi {firstname}', $data->welcome_message);
        $this->assertSame('Be brief.', $data->custom_prompt);
        $this->assertSame(
            ['corner' => 'top-right', 'x' => 40, 'y' => 60, 'side' => 'right'],
            personalization_settings_form::position($data)
        );
    }

    /**
     * Texts over their limits and distances outside 0-500 pixels are refused with a message.
     *
     * @return void
     */
    public function test_limits_are_validated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        personalization_settings_form::mock_submit(array_merge(self::valid_submission(), [
            'position_x' => 501,
            'position_y' => -1,
            'welcome_message' => str_repeat('w', personalization::WELCOME_MAX + 1),
            'custom_prompt' => str_repeat('p', personalization::CUSTOM_PROMPT_MAX + 1),
        ]));

        $form = new personalization_settings_form();

        $this->assertNull($form->get_data());
        $errors = $form->validation(array_merge(self::valid_submission(), [
            'position_x' => 501,
            'position_y' => -1,
            'welcome_message' => str_repeat('w', personalization::WELCOME_MAX + 1),
            'custom_prompt' => str_repeat('p', personalization::CUSTOM_PROMPT_MAX + 1),
        ]), []);
        $this->assertSame(
            ['position_x', 'position_y', 'welcome_message', 'custom_prompt'],
            array_keys($errors)
        );
    }

    /**
     * The avatar selector offers the Jedomi icon and the ten preset avatars, each with its image, and the preview.
     *
     * @return void
     */
    public function test_avatar_selector_and_preview_are_shown(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'personalization']));

        $form = new personalization_settings_form();
        ob_start();
        $form->display();
        $html = ob_get_clean();

        $this->assertSame(11, preg_match_all('~<input[^>]+type="radio"[^>]+name="avatar"~', $html));
        foreach (personalization::AVATARS as $avatar) {
            $this->assertStringContainsString('/local/jedomi/pix/avatars/avatar_' . $avatar . '.png', $html);
        }
        $this->assertStringContainsString(get_string('personalization_avatar_default', 'local_jedomi'), $html);
        $this->assertStringContainsString('name="customavatar"', $html);
        $this->assertStringContainsString('data-region="local_jedomi/personalization/preview"', $html);
        $this->assertStringNotContainsString('local_dttutor', $html);
    }
}
