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
 * Test double for a messaging channel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Channel that takes everything from another channel except its transport and its continuation, which record what the
 * conversation engine sends and schedules. Its messages are the keys themselves, so a test sees which one was used.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_channel implements channel {
    /** @var fake_transport Recording transport. */
    public fake_transport $transport;

    /** @var fake_continuation Recording continuation. */
    public fake_continuation $continuation;

    /**
     * Constructor.
     *
     * @param channel $inner Channel that gives the store, the guards, the tools, the confirmations and the limits.
     */
    public function __construct(
        /** @var channel Channel that gives everything else. */
        private readonly channel $inner,
    ) {
        $this->transport = new fake_transport();
        $this->continuation = new fake_continuation();
    }

    #[\Override]
    public function name(): string {
        return $this->inner->name();
    }

    #[\Override]
    public function label(): string {
        return $this->inner->label();
    }

    #[\Override]
    public function store(): conversation_store {
        return $this->inner->store();
    }

    #[\Override]
    public function access_guard(): access_guard {
        return $this->inner->access_guard();
    }

    #[\Override]
    public function tool_runner(): tool_runner {
        return $this->inner->tool_runner();
    }

    #[\Override]
    public function confirmation_service(): confirmation_service {
        return $this->inner->confirmation_service();
    }

    #[\Override]
    public function transport(): transport {
        return $this->transport;
    }

    #[\Override]
    public function continuation(): continuation {
        return $this->continuation;
    }

    #[\Override]
    public function limits(): channel_limits {
        return $this->inner->limits();
    }

    #[\Override]
    public function ai_timeout(): int {
        return $this->inner->ai_timeout();
    }

    #[\Override]
    public function activity_interval(): int {
        return $this->inner->activity_interval();
    }

    #[\Override]
    public function activity_timeout(): int {
        return $this->inner->activity_timeout();
    }

    #[\Override]
    public function message(string $key): string {
        return $key;
    }

    #[\Override]
    public function linked_userid(string $chatkey): ?int {
        return $this->inner->linked_userid($chatkey);
    }

    #[\Override]
    public function serves(string $chatkey, int $userid): bool {
        return $this->inner->serves($chatkey, $userid);
    }
}
