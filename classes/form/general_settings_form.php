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

use local_jedomi\local\query_runner;
use local_jedomi\local\service\chat_rate_limit_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Form of the General tab of the Jedomi settings: the session token secret, the query profiler settings, the row limit
 * of the queries of the web chat and the messages per user and minute of the web chat.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
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

        $this->add_card_start(
            'sqlheader',
            get_string('sql_heading', 'local_jedomi'),
            get_string('sql_heading_help', 'local_jedomi'),
            'i/report',
            'blue'
        );
        $mform->addElement('text', 'sql_row_limit_web', get_string('sql_row_limit_web', 'local_jedomi'), ['size' => 8]);
        $mform->setType('sql_row_limit_web', PARAM_INT);
        $mform->setDefault('sql_row_limit_web', query_runner::CHANNEL_LIMITS['web'][0]);
        $mform->addHelpButton('sql_row_limit_web', 'sql_row_limit_web', 'local_jedomi');
        $this->add_card_end();

        $this->add_card_start(
            'chatlimitheader',
            get_string('chat_limit_heading', 'local_jedomi'),
            get_string('chat_limit_heading_help', 'local_jedomi'),
            'i/scheduled',
            'green'
        );
        $mform->addElement('text', 'chat_rate_limit', get_string('chat_rate_limit', 'local_jedomi'), ['size' => 8]);
        // Raw text, so an empty or non-numeric value is refused by validation() instead of being cleaned to 0 (no limit).
        $mform->setType('chat_rate_limit', PARAM_RAW_TRIMMED);
        $mform->setDefault('chat_rate_limit', chat_rate_limit_service::DEFAULT_PER_MINUTE);
        $mform->addHelpButton('chat_rate_limit', 'chat_rate_limit', 'local_jedomi');
        $this->add_card_end();

        $this->add_action_buttons(false);
    }

    /**
     * Check that the slow threshold, the retention, the row limit and the message limit are within their ranges.
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
        $rows = (int) ($data['sql_row_limit_web'] ?? 0);
        if ($rows < query_runner::WEB_ROW_LIMIT_MIN || $rows > query_runner::WEB_ROW_LIMIT_MAX) {
            $errors['sql_row_limit_web'] = get_string('sql_row_limit_web_invalid', 'local_jedomi');
        }
        $messages = trim((string) ($data['chat_rate_limit'] ?? ''));
        if (!preg_match('/^\d{1,3}$/', $messages) || (int) $messages > chat_rate_limit_service::MAX_PER_MINUTE) {
            $errors['chat_rate_limit'] = get_string('chat_rate_limit_invalid', 'local_jedomi');
        }
        return $errors;
    }
}
