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
 * Cache clock test double for the Telegram manager tests.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

/**
 * Clock of the cache API, moved by the tests to check when link codes expire.
 *
 * The cache API takes every TTL decision from cache::now(); moving it simulates time passing.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class link_code_test_clock extends \cache {
    /**
     * Set the current time seen by the cache API.
     *
     * @param float $now Timestamp.
     * @return void
     */
    public static function set_now(float $now): void {
        self::$now = $now;
    }

    /**
     * Restore the real clock.
     *
     * @return void
     */
    public static function reset(): void {
        self::$now = null;
    }
}
