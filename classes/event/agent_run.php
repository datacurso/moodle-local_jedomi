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
 * Event agent_run: a scheduled agent was run.
 *
 * No conversation text, prompt or answer is stored in the event.
 *
 * Extra data in other:
 * - bool success: whether the run produced an answer.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent_run extends \core\event\base {
    /**
     * Set the basic event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_jedomi_agents';
    }

    /**
     * Localised name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_agent_run', 'local_jedomi');
    }

    /**
     * Non-localised description of the event, without any conversation content.
     *
     * @return string
     */
    public function get_description() {
        return "The agent with id '{$this->objectid}' of the user with id '{$this->relateduserid}' ran "
            . (!empty($this->other['success']) ? 'successfully' : 'with a failure')
            . ".";
    }

    /**
     * Check the required data of the event.
     *
     * @return void
     * @throws \coding_exception When a required value is missing.
     */
    protected function validate_data() {
        parent::validate_data();
        if (empty($this->objectid)) {
            throw new \coding_exception('The objectid must be set.');
        }
        if (empty($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
        if (!isset($this->other['success'])) {
            throw new \coding_exception('The \'success\' value must be set in other.');
        }
    }

    /**
     * The agent id is not mapped on restore: agents are not backed up.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_jedomi_agents', 'restore' => \core\event\base::NOT_MAPPED];
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
