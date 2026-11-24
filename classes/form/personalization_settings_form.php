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
use local_jedomi\local\service\admin_settings_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Form of the Personalization tab of the Jedomi settings: avatar, position of the button, assistant name, welcome
 * message and site instructions for the AI.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class personalization_settings_form extends \moodleform {
    use card_sections;

    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        global $OUTPUT;
        $mform = $this->_form;

        $this->add_card_start(
            'avatarheader',
            get_string('personalization_heading_avatar', 'local_jedomi'),
            get_string('personalization_heading_avatar_help', 'local_jedomi'),
            'i/user',
            'purple'
        );

        // Visual selector: one radio per avatar, its image as the label.
        $options = [$mform->createElement(
            'radio',
            'avatar',
            '',
            $OUTPUT->render_from_template('local_jedomi/admin/avatar_option', [
                'icon' => ['trigger' => true],
                'label' => get_string('personalization_avatar_default', 'local_jedomi'),
            ]),
            personalization::AVATAR_DEFAULT
        )];
        foreach (personalization::AVATARS as $index => $avatar) {
            $options[] = $mform->createElement(
                'radio',
                'avatar',
                '',
                $OUTPUT->render_from_template('local_jedomi/admin/avatar_option', [
                    'url' => personalization::preset_avatar_url($avatar)->out(false),
                    'label' => get_string('personalization_avatar_option', 'local_jedomi', $index + 1),
                ]),
                $avatar
            );
        }
        $mform->addGroup(
            $options,
            'avatargroup',
            get_string('personalization_avatar', 'local_jedomi'),
            '',
            false,
            ['class' => 'local_jedomi-avatar-selector']
        );
        $mform->setType('avatar', PARAM_ALPHANUM);
        $mform->setDefault('avatar', personalization::AVATAR_DEFAULT);
        $mform->addHelpButton('avatargroup', 'personalization_avatar', 'local_jedomi');

        $mform->addElement(
            'filemanager',
            'customavatar',
            get_string('personalization_customavatar', 'local_jedomi'),
            null,
            admin_settings_service::custom_avatar_options()
        );
        $mform->addHelpButton('customavatar', 'personalization_customavatar', 'local_jedomi');
        $this->add_card_end();

        $this->add_card_start(
            'positionheader',
            get_string('personalization_heading_position', 'local_jedomi'),
            get_string('personalization_heading_position_help', 'local_jedomi'),
            'i/move_2d',
            'sky'
        );

        $corners = [];
        foreach (personalization::CORNERS as $corner) {
            $corners[$corner] = get_string('personalization_corner_' . str_replace('-', '_', $corner), 'local_jedomi');
        }
        $mform->addElement('select', 'position_corner', get_string('personalization_corner', 'local_jedomi'), $corners);
        $mform->addHelpButton('position_corner', 'personalization_corner', 'local_jedomi');
        foreach (['x', 'y'] as $axis) {
            $mform->addElement(
                'text',
                'position_' . $axis,
                get_string('personalization_offset_' . $axis, 'local_jedomi'),
                ['size' => 6, 'inputmode' => 'numeric']
            );
            $mform->setType('position_' . $axis, PARAM_INT);
        }
        $sides = [];
        foreach (personalization::SIDES as $side) {
            $sides[$side] = get_string('personalization_side_' . $side, 'local_jedomi');
        }
        $mform->addElement('select', 'position_side', get_string('personalization_side', 'local_jedomi'), $sides);

        $mform->addElement(
            'static',
            'position_preview',
            get_string('personalization_preview', 'local_jedomi'),
            $OUTPUT->render_from_template('local_jedomi/admin/position_preview', [
                'avatarurl' => personalization::avatar_url(),
                'icon' => ['trigger' => true],
                'customavatar' => personalization::custom_avatar_url() !== null,
                'name' => personalization::assistant_name(),
                'help' => get_string('personalization_preview_help', 'local_jedomi'),
            ])
        );

        $this->add_card_end();

        $this->add_card_start(
            'assistantheader',
            get_string('personalization_heading_assistant', 'local_jedomi'),
            get_string('personalization_heading_assistant_help', 'local_jedomi'),
            't/message',
            'green'
        );
        $mform->addElement(
            'text',
            'assistant_name',
            get_string('personalization_assistant_name', 'local_jedomi'),
            ['size' => 30, 'maxlength' => personalization::NAME_MAX]
        );
        $mform->setType('assistant_name', PARAM_TEXT);
        $mform->addHelpButton('assistant_name', 'personalization_assistant_name', 'local_jedomi');

        $mform->addElement(
            'textarea',
            'welcome_message',
            get_string('personalization_welcome_message', 'local_jedomi'),
            ['rows' => 4, 'cols' => 60]
        );
        $mform->setType('welcome_message', PARAM_TEXT);
        $mform->addHelpButton('welcome_message', 'personalization_welcome_message', 'local_jedomi');

        $mform->addElement(
            'textarea',
            'custom_prompt',
            get_string('personalization_custom_prompt', 'local_jedomi'),
            ['rows' => 6, 'cols' => 60]
        );
        $mform->setType('custom_prompt', PARAM_TEXT);
        $mform->addHelpButton('custom_prompt', 'personalization_custom_prompt', 'local_jedomi');
        $this->add_card_end();

        $this->add_action_buttons(false);
    }

    /**
     * Check the distances of the button and the length of the texts.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        foreach (['position_x', 'position_y'] as $field) {
            $value = $data[$field] ?? '';
            if (!is_numeric($value) || (int) $value < 0 || (int) $value > personalization::OFFSET_MAX) {
                $errors[$field] = get_string('personalization_offset_invalid', 'local_jedomi', personalization::OFFSET_MAX);
            }
        }
        $limits = ['welcome_message' => personalization::WELCOME_MAX, 'custom_prompt' => personalization::CUSTOM_PROMPT_MAX];
        foreach ($limits as $field => $max) {
            if (\core_text::strlen(trim((string) ($data[$field] ?? ''))) > $max) {
                $errors[$field] = get_string('personalization_' . $field . '_toolong', 'local_jedomi', $max);
            }
        }
        return $errors;
    }

    /**
     * Position of the button from the submitted data, as {@see admin_settings_service::save_personalization_settings()}
     * takes it.
     *
     * @param \stdClass $data Data returned by get_data().
     * @return array{corner: string, x: int, y: int, side: string}
     */
    public static function position(\stdClass $data): array {
        return personalization::normalise_position([
            'corner' => (string) ($data->position_corner ?? ''),
            'x' => (int) ($data->position_x ?? 0),
            'y' => (int) ($data->position_y ?? 0),
            'side' => (string) ($data->position_side ?? ''),
        ]);
    }

    /**
     * Form defaults from the stored settings, with the custom avatar copied to a draft area.
     *
     * @param array $settings Settings from {@see admin_settings_service::get_personalization_settings()}.
     * @return array
     */
    public static function defaults(array $settings): array {
        $draftid = file_get_submitted_draft_itemid('customavatar');
        file_prepare_draft_area(
            $draftid,
            \context_system::instance()->id,
            'local_jedomi',
            personalization::FILEAREA,
            0,
            admin_settings_service::custom_avatar_options()
        );
        return [
            'avatar' => $settings['avatar'],
            'customavatar' => $draftid,
            'position_corner' => $settings['position']['corner'],
            'position_x' => $settings['position']['x'],
            'position_y' => $settings['position']['y'],
            'position_side' => $settings['position']['side'],
            'assistant_name' => $settings['assistant_name'],
            'welcome_message' => $settings['welcome_message'],
            'custom_prompt' => $settings['custom_prompt'],
        ];
    }
}
