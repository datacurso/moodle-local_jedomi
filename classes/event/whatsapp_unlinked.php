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

namespace local_jedomi\event;

/**
 * Event whatsapp_unlinked: the WhatsApp link of a user was removed.
 *
 * Only ids are stored: never the WhatsApp identity (BSUID), the phone or any message text.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_unlinked extends \core\event\base {
    /**
     * Event of a user whose WhatsApp link changed, done by that user (the webhook has no session).
     *
     * @param int $userid User whose link changed.
     * @param int|null $actorid User who made the change; the same user when null.
     * @return self
     */
    public static function create_for_user(int $userid, ?int $actorid = null): self {
        return self::create([
            'context' => \context_system::instance(),
            'userid' => $actorid ?? $userid,
            'relateduserid' => $userid,
        ]);
    }

    /**
     * Set the basic event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_whatsapp_unlinked', 'local_jedomi');
    }

    /**
     * Non-localised description of the event, with ids only.
     *
     * @return string
     */
    public function get_description() {
        return "The WhatsApp link of the user with id '{$this->relateduserid}' was removed by the user with id '{$this->userid}'.";
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
