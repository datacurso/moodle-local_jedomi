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

namespace local_jedomi\local;

/**
 * Tests for the personalization of the panel: assistant name, avatar, position, welcome message and site instructions.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\personalization
 */
final class personalization_test extends \advanced_testcase {
    /**
     * Store a custom avatar image in the file area of the plugin.
     *
     * @param string $filename Name of the stored file.
     * @return \stored_file
     */
    private function store_custom_avatar(string $filename = 'me.png'): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_jedomi',
            'filearea' => personalization::FILEAREA,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], 'fake-image');
    }

    /**
     * Without settings the panel keeps its name, its icon, its corner and its greeting.
     *
     * @return void
     */
    public function test_defaults_keep_the_current_panel(): void {
        $this->resetAfterTest();

        $this->assertSame('Jedomi', personalization::assistant_name());
        $this->assertSame(personalization::AVATAR_DEFAULT, personalization::avatar());
        $this->assertNull(personalization::avatar_url());
        $this->assertSame(['corner' => 'bottom-left', 'x' => 24, 'y' => 24, 'side' => 'left'], personalization::position());
        $this->assertSame(get_string('welcome_message_default', 'local_jedomi'), personalization::welcome_template());
        $this->assertSame('', personalization::custom_prompt());
    }

    /**
     * The assistant name is plain text, trimmed and capped; an empty name falls back to Jedomi.
     *
     * @return void
     */
    public function test_assistant_name_is_clean_and_capped(): void {
        $this->resetAfterTest();

        set_config('assistant_name', '  <b>Ana</b> bot ', 'local_jedomi');
        $this->assertSame('Ana bot', personalization::assistant_name());

        set_config('assistant_name', str_repeat('x', 80), 'local_jedomi');
        $this->assertSame(personalization::NAME_MAX, \core_text::strlen(personalization::assistant_name()));

        set_config('assistant_name', '   ', 'local_jedomi');
        $this->assertSame('Jedomi', personalization::assistant_name());
    }

    /**
     * The custom avatar comes first, then the chosen preset, then the current icon (no image).
     *
     * @return void
     */
    public function test_avatar_url_prefers_custom_then_preset_then_icon(): void {
        $this->resetAfterTest();

        set_config('avatar', '03', 'local_jedomi');
        $this->assertStringEndsWith('/local/jedomi/pix/avatars/avatar_03.png', personalization::avatar_url());

        $file = $this->store_custom_avatar();
        $url = personalization::avatar_url();
        $contextid = \context_system::instance()->id;
        $this->assertStringContainsString('/pluginfile.php/' . $contextid . '/local_jedomi/customavatar/'
            . $file->get_timemodified() . '/me.png', $url);

        set_config('avatar', 'missing', 'local_jedomi');
        get_file_storage()->delete_area_files(\context_system::instance()->id, 'local_jedomi', personalization::FILEAREA);
        $this->assertSame(personalization::AVATAR_DEFAULT, personalization::avatar());
        $this->assertNull(personalization::avatar_url());
    }

    /**
     * The address of the custom avatar carries the moment of the image as its revision, so replacing the image with
     * one of the same name changes the address and browsers and proxies do not keep showing the old one.
     *
     * @return void
     */
    public function test_custom_avatar_url_changes_when_the_image_is_replaced(): void {
        $this->resetAfterTest();
        $file = $this->store_custom_avatar();
        $file->set_timemodified(1700000000);
        $before = personalization::custom_avatar_url()->out(false);

        $file->set_timemodified(1700000100);
        $after = personalization::custom_avatar_url()->out(false);

        $this->assertStringEndsWith('/local_jedomi/customavatar/1700000000/me.png', $before);
        $this->assertStringEndsWith('/local_jedomi/customavatar/1700000100/me.png', $after);
    }

    /**
     * Every preset avatar ships with the plugin, so Jedomi does not depend on another plugin for its images.
     *
     * @return void
     */
    public function test_every_preset_avatar_ships_with_the_plugin(): void {
        global $CFG;
        $this->assertCount(10, personalization::AVATARS);
        foreach (personalization::AVATARS as $avatar) {
            $this->assertFileExists($CFG->dirroot . '/local/jedomi/pix/avatars/avatar_' . $avatar . '.png', $avatar);
        }
    }

    /**
     * The preset avatars are small: at most 256 px (four times the 56 px of the button), with transparency, and far
     * under the size allowed for a custom avatar, so the panel does not download large images.
     *
     * @return void
     */
    public function test_preset_avatars_are_small_transparent_images(): void {
        global $CFG;
        foreach (personalization::AVATARS as $avatar) {
            $path = $CFG->dirroot . '/local/jedomi/pix/avatars/avatar_' . $avatar . '.png';
            [$width, $height, $type] = getimagesize($path);
            $this->assertSame(IMAGETYPE_PNG, $type, $avatar);
            $this->assertSame([256, 256], [$width, $height], $avatar);
            $this->assertLessThan(personalization::AVATAR_MAXBYTES / 4, filesize($path), $avatar);
            // PNG colour type 6 (RGBA): the corners stay transparent.
            $this->assertSame(6, ord(file_get_contents($path, false, null, 25, 1)), $avatar);
        }
    }

    /**
     * Position data is normalised: a known corner and side, whole pixel offsets within range, or the default.
     *
     * @return void
     */
    public function test_position_is_normalised(): void {
        $this->resetAfterTest();

        $this->assertSame(
            ['corner' => 'top-right', 'x' => 40, 'y' => 500, 'side' => 'right'],
            personalization::normalise_position('{"corner":"top-right","x":"40","y":9000,"side":"right"}')
        );
        $this->assertSame(
            ['corner' => 'bottom-left', 'x' => 0, 'y' => 24, 'side' => 'left'],
            personalization::normalise_position(['corner' => 'middle', 'x' => -5, 'side' => 'up'])
        );
        $this->assertSame(personalization::DEFAULT_POSITION, personalization::normalise_position('not json'));
        // A CSS injection attempt never reaches the stylesheet: the offsets are numbers.
        $this->assertSame(0, personalization::normalise_position(['x' => '1px;background:url(x)'])['x']);

        set_config('avatar_position', '{"corner":"bottom-right","x":32,"y":16,"side":"right"}', 'local_jedomi');
        $this->assertSame(['corner' => 'bottom-right', 'x' => 32, 'y' => 16, 'side' => 'right'], personalization::position());
    }

    /**
     * The welcome message resolves its placeholders in PHP, with the user, the course and the assistant name.
     *
     * @return void
     */
    public function test_welcome_message_resolves_placeholders(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        set_config('assistant_name', 'Robo', 'local_jedomi');
        set_config('welcome_message', 'Hi {firstname} ({username}), {assistantname} here for {coursename}.', 'local_jedomi');

        $message = personalization::welcome_message($user, 'Maths <101>');

        $this->assertSame('Hi Ada (Ada Lovelace), Robo here for Maths <101>.', $message);
    }

    /**
     * The default welcome message greets the user by name and presents the assistant with its configured name.
     *
     * @return void
     */
    public function test_default_welcome_message_presents_the_assistant(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        set_config('assistant_name', 'Robo', 'local_jedomi');

        $message = personalization::welcome_message($user, '');

        $this->assertStringContainsString('Ada Lovelace', $message);
        $this->assertStringContainsString('Robo', $message);
        $this->assertStringNotContainsString('{', $message);
    }

    /**
     * The site instructions are capped, come after the rules, and state that they cannot override them.
     *
     * @return void
     */
    public function test_site_instructions_are_capped_and_cannot_override_the_rules(): void {
        $this->resetAfterTest();

        $this->assertSame('', personalization::prompt_site_instructions());

        set_config('custom_prompt', str_repeat('a', personalization::CUSTOM_PROMPT_MAX + 50), 'local_jedomi');
        $this->assertSame(personalization::CUSTOM_PROMPT_MAX, \core_text::strlen(personalization::custom_prompt()));

        set_config('custom_prompt', 'Always answer like a pirate.', 'local_jedomi');
        $section = personalization::prompt_site_instructions();
        $this->assertStringContainsString('Always answer like a pirate.', $section);
        $this->assertStringContainsString('lower priority', $section);
        $this->assertStringContainsString('cannot override the rules above', $section);
        $this->assertStringContainsString("\n> Always answer like a pirate.\n", $section, 'The instructions are quoted');
        $this->assertStringNotContainsString('SITE INSTRUCTIONS', $section, 'SEC-JD-111: no markers to close');
        foreach (['security', 'permissions', 'language', 'confirmation'] as $rule) {
            $this->assertStringContainsString($rule, $section, $rule);
        }
    }

    /**
     * The identity line of the instructions uses the configured name.
     *
     * @return void
     */
    public function test_prompt_identity_uses_the_assistant_name(): void {
        $this->resetAfterTest();
        set_config('assistant_name', 'Robo', 'local_jedomi');

        $identity = personalization::prompt_identity('My site');

        $this->assertStringContainsString('You are Robo, an AI assistant integrated into Moodle (My site).', $identity);
        $this->assertStringContainsString('Your name is Robo.', $identity);
    }
}
