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

namespace local_jedomi\whatsapp;

/**
 * Port that records the delivery statuses of value.statuses[]; they never go to the inbox.
 *
 * The intake gets it from the container, \core\di::get(status_sink::class), when one is registered, and uses
 * log_status_sink otherwise; a test registers a double with \core\di::set().
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface status_sink {
    /**
     * Record one status.
     *
     * @param delivery_status $status Status.
     */
    public function record(delivery_status $status): void;
}
