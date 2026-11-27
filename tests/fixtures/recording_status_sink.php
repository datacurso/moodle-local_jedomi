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

/**
 * Test double for the sink of WhatsApp delivery statuses.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\whatsapp;

/**
 * Sink that records the statuses it gets and can be told to fail.
 *
 * Register it with \core\di::set(status_sink::class, $sink); the container is reset after every test.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_status_sink implements status_sink {
    /** @var delivery_status[] Statuses recorded, in order. */
    public array $statuses = [];

    /** @var bool Whether every call throws. */
    public bool $fail = false;

    /**
     * Record the status, or throw when told to fail.
     *
     * @param delivery_status $status Status.
     */
    public function record(delivery_status $status): void {
        if ($this->fail) {
            throw new \moodle_exception('error');
        }
        $this->statuses[] = $status;
    }
}
