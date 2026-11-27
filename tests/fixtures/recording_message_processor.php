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
 * Test double for the processor of WhatsApp messages.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\whatsapp;

/**
 * Processor that records the messages it gets and can be told to fail.
 *
 * Register it with \core\di::set(message_processor::class, $processor); the container is reset after every test.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_message_processor implements message_processor {
    /** @var inbound[] Messages processed, in order. */
    public array $messages = [];

    /** @var int Number of next calls that throw. */
    public int $failures = 0;

    /**
     * Constructor.
     *
     * @param \ArrayObject|null $journal Shared journal the processor appends 'process' to, to check the order of events.
     */
    public function __construct(
        /** @var \ArrayObject|null Shared journal of events. */
        private ?\ArrayObject $journal = null,
    ) {
    }

    /**
     * Record the message, or throw while failures are left.
     *
     * @param inbound $message Message.
     */
    public function process(inbound $message): void {
        if ($this->journal !== null) {
            $this->journal[] = 'process';
        }
        if ($this->failures > 0) {
            $this->failures--;
            throw new \moodle_exception('error');
        }
        $this->messages[] = $message;
    }
}
