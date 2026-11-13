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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

use local_jedomi\local\service\admin_settings_service;

/**
 * Form of the Course availability tab of the Jedomi settings: where the chat is shown.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_settings_form extends \moodleform {
    use card_sections;

    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $this->add_card_start(
            'coursesheader',
            get_string('course_availability_heading', 'local_jedomi'),
            get_string('course_availability_heading_help', 'local_jedomi'),
            'i/course',
            'green'
        );

        foreach (admin_settings_service::COURSE_MODES as $mode) {
            $mform->addElement(
                'radio',
                'course_mode',
                get_string('course_mode_' . $mode, 'local_jedomi'),
                get_string('course_mode_' . $mode . '_desc', 'local_jedomi'),
                $mode
            );
        }
        $mform->setType('course_mode', PARAM_ALPHA);
        $mform->setDefault('course_mode', 'all');

        $mform->addElement('course', 'course_ids', get_string('course_availability_courses', 'local_jedomi'), [
            'multiple' => true,
            'noselectionstring' => get_string('course_none_selected', 'local_jedomi'),
            'placeholder' => get_string('course_search_placeholder', 'local_jedomi'),
        ]);
        $mform->addHelpButton('course_ids', 'course_availability_courses', 'local_jedomi');
        $mform->hideIf('course_ids', 'course_mode', 'eq', 'all');
        $this->add_card_end();

        $this->add_action_buttons(false);
    }

    /**
     * Reject a mode that is not offered.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!in_array($data['course_mode'] ?? '', admin_settings_service::COURSE_MODES, true)) {
            $errors['course_mode'] = get_string('required');
        }
        return $errors;
    }
}
