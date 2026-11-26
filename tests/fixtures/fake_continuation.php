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
 * Test double for the continuation of a messaging channel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Continuation that records each scheduled leg instead of starting a new worker.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_continuation implements continuation {
    /** @var array<int, array{chatkey: string, sessiongen: int}> Legs scheduled, in order. */
    public array $calls = [];

    /** @var \Closure|null Called with the chat key and the session generation on each schedule(). */
    public ?\Closure $onschedule = null;

    #[\Override]
    public function schedule(string $chatkey, int $sessiongen): void {
        $this->calls[] = ['chatkey' => $chatkey, 'sessiongen' => $sessiongen];
        if ($this->onschedule !== null) {
            ($this->onschedule)($chatkey, $sessiongen);
        }
    }
}
