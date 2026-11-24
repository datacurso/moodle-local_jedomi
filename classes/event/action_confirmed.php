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

namespace local_jedomi\event;

/**
 * Event action_confirmed: a user confirmed an action proposed by the assistant.
 *
 * No conversation text, prompt or answer is stored in the event.
 *
 * Extra data in other:
 * - string action: name of the action (tool), never its arguments.
 * - string channel: web or telegram.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class action_confirmed extends \core\event\base {
    /**
     * Set the basic event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_action_confirmed', 'local_jedomi');
    }

    /**
     * Non-localised description of the event, without any conversation content.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' confirmed the assistant action '"
            . ($this->other['action'] ?? '')
            . "' through the channel '"
            . ($this->other['channel'] ?? '')
            . "'.";
    }

    /**
     * Check the required data of the event.
     *
     * @return void
     * @throws \coding_exception When a required value is missing.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['action'])) {
            throw new \coding_exception('The \'action\' value must be set in other.');
        }
        if (!isset($this->other['channel'])) {
            throw new \coding_exception('The \'channel\' value must be set in other.');
        }
    }

    /**
     * The values in other hold no ids.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
