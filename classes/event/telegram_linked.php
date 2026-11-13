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
 * Event telegram_linked: a user linked a Telegram account.
 *
 * No conversation text, prompt or answer is stored in the event.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_linked extends \core\event\base {
    /**
     * Set the basic event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_telegram_linked', 'local_jedomi');
    }

    /**
     * Non-localised description of the event, without any conversation content.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' linked a Telegram account.";
    }

    /**
     * Check the required data of the event.
     *
     * @return void
     * @throws \coding_exception When a required value is missing.
     */
    protected function validate_data() {
        parent::validate_data();
        if (empty($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
    }
}
