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

/**
 * Form of the General tab of the Jedomi settings: the session token secret and the query profiler settings.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class general_settings_form extends \moodleform {
    use card_sections;

    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $this->add_card_start(
            'securityheader',
            get_string('security_heading', 'local_jedomi'),
            get_string('security_heading_help', 'local_jedomi'),
            'i/lock',
            'red'
        );
        $mform->addElement('passwordunmask', 'spt_secret', get_string('spt_secret', 'local_jedomi'));
        $mform->setType('spt_secret', PARAM_TEXT);
        $mform->addElement('static', 'spt_secret_help', '', get_string('spt_secret_help', 'local_jedomi'));

        $this->add_card_end();

        $this->add_card_start(
            'profilerheader',
            get_string('profiler_heading', 'local_jedomi'),
            get_string('profiler_heading_help', 'local_jedomi'),
            'i/dashboard',
            'amber'
        );
        $mform->addElement('text', 'profiler_slow_ms', get_string('profiler_slow_ms', 'local_jedomi'), ['size' => 8]);
        $mform->setType('profiler_slow_ms', PARAM_INT);
        $mform->addHelpButton('profiler_slow_ms', 'profiler_slow_ms', 'local_jedomi');
        $mform->addElement(
            'text',
            'profiler_retention_days',
            get_string('profiler_retention_days', 'local_jedomi'),
            ['size' => 8]
        );
        $mform->setType('profiler_retention_days', PARAM_INT);
        $mform->addHelpButton('profiler_retention_days', 'profiler_retention_days', 'local_jedomi');
        $this->add_card_end();

        $this->add_action_buttons(false);
    }

    /**
     * Check that the slow threshold and the retention are within their ranges.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $slowms = (int) ($data['profiler_slow_ms'] ?? 0);
        if ($slowms < 1 || $slowms > 600000) {
            $errors['profiler_slow_ms'] = get_string('profiler_slow_ms_invalid', 'local_jedomi');
        }
        $days = (int) ($data['profiler_retention_days'] ?? 0);
        if ($days < 1 || $days > 3650) {
            $errors['profiler_retention_days'] = get_string('profiler_retention_days_invalid', 'local_jedomi');
        }
        return $errors;
    }
}
