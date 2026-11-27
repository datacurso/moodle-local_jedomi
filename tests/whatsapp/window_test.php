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

namespace local_jedomi\whatsapp;

/**
 * Tests for the 24-hour service window of WhatsApp, with its safety margin.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\window
 */
final class window_test extends \basic_testcase {
    /** @var int Current time of the tests. */
    private const NOW = 1800000000;

    /** @var int Last second of the window: 24 hours minus the 10-minute margin. */
    private const OPEN_FOR = DAYSECS - 10 * MINSECS;

    /**
     * Whether the window of a link is open, for a time of its last inbound message.
     *
     * @param int $lastinbound Last inbound message of the link.
     * @return bool
     */
    private static function open(int $lastinbound): bool {
        return window::is_open((object) ['lastinbound' => $lastinbound], self::NOW);
    }

    /**
     * The window stays open until 24 hours minus the margin after the last inbound message, and closes at that point.
     */
    public function test_the_window_closes_ten_minutes_before_meta_closes_it(): void {
        $this->assertSame(10 * MINSECS, window::MARGIN);
        $this->assertTrue(self::open(self::NOW));
        $this->assertTrue(self::open(self::NOW - HOURSECS));
        $this->assertTrue(self::open(self::NOW - self::OPEN_FOR + 1));
        $this->assertFalse(self::open(self::NOW - self::OPEN_FOR));
        $this->assertFalse(self::open(self::NOW - DAYSECS));
        $this->assertFalse(self::open(self::NOW - 2 * DAYSECS));
    }

    /**
     * A link that never wrote has no window.
     */
    public function test_a_link_without_inbound_message_has_no_window(): void {
        $this->assertFalse(self::open(0));
        $this->assertFalse(window::is_open((object) [], self::NOW));
    }
}
