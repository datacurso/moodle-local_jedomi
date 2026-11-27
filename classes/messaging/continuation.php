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

/**
 * Continuation port of a messaging channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * How a channel hands a long tool loop to the next worker: the state is saved first, then the next leg is scheduled.
 */
interface continuation {
    /**
     * Schedule the next leg of the tool loop of a chat. It returns at once; a failure is only logged.
     *
     * @param string $chatkey Chat key of the channel.
     * @param int $sessiongen Session generation of the saved loop, so a leg of an older conversation is dropped.
     * @return void
     */
    public function schedule(string $chatkey, int $sessiongen): void;
}
