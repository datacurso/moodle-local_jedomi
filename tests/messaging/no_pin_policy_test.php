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

namespace local_jedomi\messaging;

/**
 * Tests for the PIN policy of a channel that cannot ask for a PIN.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\no_pin_policy
 */
final class no_pin_policy_test extends \basic_testcase {
    /**
     * Whether a user needs a PIN is the rule given by the channel; the channel never asks for it, never reads a text as
     * a PIN and never confirms one.
     */
    public function test_never_asks_reads_nor_confirms_a_pin(): void {
        $policy = new no_pin_policy(static fn(int $userid): bool => $userid === 2);

        $this->assertFalse($policy->available());
        $this->assertTrue($policy->is_required(2));
        $this->assertFalse($policy->is_required(3));
        $this->assertFalse($policy->is_pin('123456'));
        $this->assertFalse($policy->in_window(2));
        $this->assertFalse($policy->has_pin(2));
        $this->assertSame(0, $policy->locked_until(2));
        $this->assertSame(['result' => pin_policy::RESULT_NO_PIN, 'remaining' => 0], $policy->verify(2, '123456'));
    }

    /**
     * A channel without PIN has no PIN texts: asking for one is a coding error.
     */
    public function test_has_no_pin_texts(): void {
        $policy = new no_pin_policy(static fn(int $userid): bool => true);

        $texts = [fn() => $policy->missing_message(), fn() => $policy->locked_message(time()), fn() => $policy->wrong_message(1)];
        foreach ($texts as $text) {
            try {
                $text();
                $this->fail('A channel without PIN has no PIN texts');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('no PIN', $e->getMessage());
            }
        }
    }
}
