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

require_once($CFG->libdir . '/formslib.php');

use local_jedomi\whatsapp\consent;

/**
 * Consent form of the WhatsApp profile page: without accepting it and choosing a category, no link code is generated.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_consent_form extends \moodleform {
    /**
     * Define the consent text, its acceptance box, the categories and the button that generates the code.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement(
            'static',
            'consenttext',
            get_string('whatsapp_consent_heading', 'local_jedomi'),
            \html_writer::div(s(get_string('whatsapp_consent_text', 'local_jedomi')), 'small')
        );
        $mform->addElement('advcheckbox', 'consent', '', get_string('whatsapp_consent_accept', 'local_jedomi'));
        $mform->setType('consent', PARAM_BOOL);

        $mform->addElement(
            'advcheckbox',
            'chat',
            get_string('whatsapp_consent_categories', 'local_jedomi'),
            get_string('whatsapp_consent_chat', 'local_jedomi')
        );
        $mform->setType('chat', PARAM_BOOL);
        $mform->setDefault('chat', 1);
        $mform->addElement('advcheckbox', 'grades', '', get_string('whatsapp_consent_grades', 'local_jedomi'));
        $mform->setType('grades', PARAM_BOOL);
        $mform->setDefault('grades', 1);

        $this->add_action_buttons(false, get_string('whatsapp_link_generate', 'local_jedomi'));
    }

    /**
     * The consent must be accepted with at least one category.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (empty($data['consent'])) {
            $errors['consent'] = get_string('whatsapp_consent_required', 'local_jedomi');
        }
        if (empty($data['chat']) && empty($data['grades'])) {
            $errors['grades'] = get_string('whatsapp_consent_category_required', 'local_jedomi');
        }
        return $errors;
    }

    /**
     * The consent submitted, when the form is valid.
     *
     * @param int $now Time the consent is given.
     * @return consent|null Null when the form was not submitted or is not valid.
     */
    public function get_consent(int $now): ?consent {
        $data = $this->get_data();
        if (!$data) {
            return null;
        }
        $categories = [];
        if (!empty($data->chat)) {
            $categories[] = consent::CHAT;
        }
        if (!empty($data->grades)) {
            $categories[] = consent::GRADES;
        }
        return new consent(consent::VERSION, $categories, $now);
    }
}
