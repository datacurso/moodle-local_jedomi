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
 * Answer of the user to a held action.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * How the user answered a held action (one of the confirmation_service::MODE_* constants) and which action.
 */
final class confirmation_reply {
    /**
     * Constructor.
     *
     * @param string $mode One of the confirmation_service::MODE_* constants.
     * @param string $actionid Id of the held action.
     */
    public function __construct(
        /** @var string How the user answered. */
        public readonly string $mode,
        /** @var string Id of the held action. */
        public readonly string $actionid,
    ) {
    }
}
