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

use local_jedomi\local\service\telegram_pin_service;

/**
 * Form of the Telegram profile page that sets the Telegram PIN (Decision 7).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_pin_form extends \moodleform {
    /**
     * Define the two PIN fields, the format description and the save button.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $format = self::format_description();
        // No PHP-only modifiers: the rule is also emitted as a JavaScript regex literal. The server check in validation()
        // uses telegram_pin_service::is_valid_format(), which is stricter.
        $pattern = '/^[0-9]{' . telegram_pin_service::MIN_LENGTH . ',' . telegram_pin_service::MAX_LENGTH . '}$/';
        // The PIN is not the Moodle password: keep browsers from filling it in.
        $attributes = ['autocomplete' => 'new-password', 'inputmode' => 'numeric', 'size' => 10,
            'maxlength' => telegram_pin_service::MAX_LENGTH];

        $mform->addElement('passwordunmask', 'pin', get_string('telegram_pin_new', 'local_jedomi'), $attributes);
        $mform->setType('pin', PARAM_RAW);
        $mform->addRule('pin', null, 'required', null, 'client');
        $mform->addRule('pin', $format, 'regex', $pattern, 'client');

        $mform->addElement('static', 'pinformat', '', \html_writer::span(s($format), 'text-muted small'));

        $mform->addElement('passwordunmask', 'pinconfirm', get_string('telegram_pin_repeat', 'local_jedomi'), $attributes);
        $mform->setType('pinconfirm', PARAM_RAW);
        $mform->addRule('pinconfirm', null, 'required', null, 'client');
        $mform->addRule('pinconfirm', $format, 'regex', $pattern, 'client');

        $this->add_action_buttons(false, get_string('telegram_pin_save', 'local_jedomi'));
    }

    /**
     * Check the PIN format on the server and that both PINs match.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $pin = (string) ($data['pin'] ?? '');
        if (!telegram_pin_service::is_valid_format($pin)) {
            $errors['pin'] = self::format_description();
        } else if ($pin !== (string) ($data['pinconfirm'] ?? '')) {
            $errors['pinconfirm'] = get_string('telegram_pin_mismatch', 'local_jedomi');
        }
        return $errors;
    }

    /**
     * Text that explains the PIN format.
     *
     * @return string
     */
    public static function format_description(): string {
        return get_string('telegram_pin_invalid', 'local_jedomi', (object) [
            'min' => telegram_pin_service::MIN_LENGTH,
            'max' => telegram_pin_service::MAX_LENGTH,
        ]);
    }
}
