<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_jedomi\form;

use local_jedomi\tutor\course_settings;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Course-level settings of the AI tutor (row cmid = 0 of local_jedomi_stem_cfg).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tutor_settings_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $template = $this->_customdata['template'];

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('advcheckbox', 'enabled', get_string('tutor_settings_enabled', 'local_jedomi'));
        $mform->addHelpButton('enabled', 'tutor_settings_enabled', 'local_jedomi');

        $inherited = get_string('stem_tutor_mode_' . $template['mode'], 'local_jedomi');
        $modes = [];
        foreach (course_settings::MODES as $mode) {
            if ($mode === 'inherit') {
                $modes[$mode] = get_string('tutor_settings_mode_inherit', 'local_jedomi', $inherited);
            } else if ($mode === 'assistant') {
                $modes[$mode] = get_string('tutor_settings_mode_assistant', 'local_jedomi');
            } else {
                $modes[$mode] = get_string('stem_tutor_mode_' . $mode, 'local_jedomi');
            }
        }
        $mform->addElement('select', 'tutor_mode', get_string('tutor_settings_mode', 'local_jedomi'), $modes);
        $mform->setDefault('tutor_mode', 'inherit');

        $mform->addElement(
            'text',
            'max_questions',
            get_string('tutor_settings_maxq', 'local_jedomi', $template['maxq']),
            ['size' => 4]
        );
        $mform->setType('max_questions', PARAM_INT);
        $mform->setDefault('max_questions', 0);

        $mform->addElement(
            'textarea',
            'tutor_prompt',
            get_string('tutor_settings_prompt', 'local_jedomi'),
            ['rows' => 5, 'maxlength' => course_settings::PROMPT_MAX]
        );
        $mform->setType('tutor_prompt', PARAM_TEXT);
        $mform->addHelpButton('tutor_prompt', 'tutor_settings_prompt', 'local_jedomi');

        $this->add_action_buttons(false);
    }

    /**
     * Validates the number of questions and the mode.
     *
     * @param array $data Submitted data.
     * @param array $files Uploaded files.
     * @return array Errors by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $maxq = (int) ($data['max_questions'] ?? 0);
        if ($maxq < 0 || $maxq > course_settings::MAXQ_MAX) {
            $errors['max_questions'] = get_string('tutor_settings_maxq_range', 'local_jedomi');
        }
        if (!in_array($data['tutor_mode'] ?? '', course_settings::MODES, true)) {
            $errors['tutor_mode'] = get_string('invaliddata', 'error');
        }
        return $errors;
    }
}
