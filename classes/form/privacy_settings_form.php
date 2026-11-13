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
 * Form of the Privacy tab of the Jedomi settings: who can use the chat and how data is anonymised.
 *
 * Custom data: roles (array of ['id' => int, 'shortname' => string, 'name' => string]).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class privacy_settings_form extends \moodleform {
    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'accessheader', get_string('privacy_access_heading', 'local_jedomi'));
        $mform->setExpanded('accessheader');
        $mform->addElement('static', 'accesshelp', '', get_string('privacy_access_heading_help', 'local_jedomi'));
        $mform->addElement(
            'static',
            'rolevisibility',
            get_string('role_visibility_label', 'local_jedomi'),
            get_string('role_visibility_help', 'local_jedomi')
        );
        foreach ($this->_customdata['roles'] ?? [] as $role) {
            $mform->addElement('advcheckbox', 'role_visible_' . $role['id'], $role['name'], $role['shortname']);
            $mform->setDefault('role_visible_' . $role['id'], 1);
        }

        $mform->addElement('header', 'anonheader', get_string('privacy_anon_heading', 'local_jedomi'));
        $mform->setExpanded('anonheader');
        $mform->addElement(
            'static',
            'anonhelp',
            get_string('anonymization_level', 'local_jedomi'),
            get_string('anonymization_level_help', 'local_jedomi')
        );
        $labels = ['basic' => 'anon_basic', 'strict' => 'anon_strict', 'feedback' => 'anon_feedback_only'];
        foreach (admin_settings_service::ANONYMIZATION_LEVELS as $level) {
            $mform->addElement(
                'radio',
                'anonymization_level',
                get_string($labels[$level], 'local_jedomi'),
                get_string($labels[$level] . '_desc', 'local_jedomi'),
                $level
            );
        }
        $mform->setType('anonymization_level', PARAM_ALPHA);
        $mform->setDefault('anonymization_level', 'basic');

        $this->add_action_buttons(false);
    }

    /**
     * Reject an anonymization level that is not offered.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!in_array($data['anonymization_level'] ?? '', admin_settings_service::ANONYMIZATION_LEVELS, true)) {
            $errors['anonymization_level'] = get_string('required');
        }
        return $errors;
    }

    /**
     * Ids of the roles left visible in the submitted data.
     *
     * @param \stdClass $data Data returned by get_data().
     * @param array $roles Roles the form was built with.
     * @return int[]
     */
    public static function visible_role_ids(\stdClass $data, array $roles): array {
        $visible = [];
        foreach ($roles as $role) {
            if (!empty($data->{'role_visible_' . $role['id']})) {
                $visible[] = (int) $role['id'];
            }
        }
        return $visible;
    }
}
