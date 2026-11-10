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

namespace local_jedomi\agent\tool;

/**
 * Tools that check the permissions of the user they run for (the agent creator), not of the session user.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait acts_for_user {
    /** @var int|null User the tool runs for (the agent creator); null for the session user. */
    protected ?int $userid;

    /**
     * Store the user the tool runs for.
     *
     * @param int|null $userid User the tool runs for (the agent creator); 0 denies every permission; null uses the
     *                         session user.
     */
    public function __construct(?int $userid = null) {
        $this->userid = $userid;
    }

    /**
     * Id of the user the tool runs for.
     *
     * @return int
     */
    protected function actor_id(): int {
        global $USER;
        return $this->userid ?? (int) ($USER->id ?? 0);
    }

    /**
     * Jedomi role of the user the tool runs for.
     *
     * @return string 'admin', 'manager', 'teacher' or 'student'.
     */
    protected function actor_role(): string {
        if ($this->userid === null) {
            return \local_jedomi_get_user_role();
        }
        return \local_jedomi_get_user_role_for_user($this->userid);
    }
}
