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
 * Telegram form of the Integrations tab of the Jedomi settings: bot token, limits and role access.
 *
 * Custom data: roles (array of ['id' => int, 'shortname' => string, 'name' => string]) and
 * tokenset (bool, whether a bot token is already stored).
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_settings_form extends \moodleform {
    /** @var array<string, int[]> Allowed range of each number field, as [min, max]. */
    private const RANGES = [
        'telegram_rate_limit' => [0, 1000],
        'telegram_cooldown' => [0, 1440],
        'telegram_max_turns' => [1, 100],
        'telegram_gateway_timeout' => [5, 300],
    ];

    /**
     * Define the form fields.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'botheader', get_string('security_heading', 'local_jedomi'));
        $mform->setExpanded('botheader');
        $placeholder = !empty($this->_customdata['tokenset'])
            ? get_string('telegram_bot_token_placeholder_saved', 'local_jedomi')
            : get_string('telegram_bot_token_placeholder', 'local_jedomi');
        $mform->addElement('passwordunmask', 'telegram_bot_token', get_string('telegram_bot_token', 'local_jedomi'),
            ['placeholder' => $placeholder, 'autocomplete' => 'new-password']);
        $mform->setType('telegram_bot_token', PARAM_TEXT);
        $mform->addElement('static', 'telegram_bot_token_help', '', get_string('telegram_bot_token_help', 'local_jedomi'));
        $mform->addElement('submit', 'regeneratesecret', get_string('regenerate', 'local_jedomi'), [
            'data-action' => 'local_jedomi/admin/integrations/regenerate-secret',
            'class' => 'btn-outline-warning',
        ]);
        $mform->addElement('static', 'regeneratesecret_help', '', get_string('telegram_webhook_secret_help', 'local_jedomi'));

        $mform->addElement('header', 'rateheader', get_string('telegram_rate_limit', 'local_jedomi'));
        foreach (array_keys(self::RANGES) as $field) {
            $mform->addElement('text', $field, get_string($field, 'local_jedomi'), ['size' => 6]);
            $mform->setType($field, PARAM_INT);
            $mform->addHelpButton($field, $field, 'local_jedomi');
        }

        $mform->addElement('header', 'accessheader', get_string('privacy_access_heading', 'local_jedomi'));
        $mform->addElement('static', 'telegram_role_visibility_help', '',
            get_string('telegram_role_visibility_help', 'local_jedomi'));
        foreach ($this->_customdata['roles'] ?? [] as $role) {
            $mform->addElement('advcheckbox', 'telegram_role_visible_' . $role['id'], $role['name'], $role['shortname']);
            $mform->setDefault('telegram_role_visible_' . $role['id'], 1);
        }

        $this->add_action_buttons(false);
    }

    /**
     * Check that every number is inside its allowed range.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors keyed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        foreach (self::RANGES as $field => [$min, $max]) {
            $value = (int) ($data[$field] ?? 0);
            if ($value < $min || $value > $max) {
                $errors[$field] = get_string('error_value_range', 'local_jedomi', ['min' => $min, 'max' => $max]);
            }
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
            if (!empty($data->{'telegram_role_visible_' . $role['id']})) {
                $visible[] = (int) $role['id'];
            }
        }
        return $visible;
    }
}
