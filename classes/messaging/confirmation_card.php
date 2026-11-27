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
 * Confirmation card of a held action, as a messaging channel shows it.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

use local_jedomi\local\service\pending_action_service;

/**
 * What a channel shows for an action held until the user confirms it: the id its answers carry, the tool, the
 * severity, whether the PIN is asked, and the readable card data of pending_action_service::card().
 */
final class confirmation_card {
    /**
     * Constructor.
     *
     * @param string $actionid Id of the held action, carried by the answers of the user (e.g. the button data).
     * @param string $tool Tool name.
     * @param string $severity One of the pending_action_service::SEVERITY_* constants.
     * @param bool $askpin Whether the user must type the PIN to confirm it.
     * @param array $card Card data: description and details, plus count and preview for send_messages.
     */
    public function __construct(
        /** @var string Id of the held action. */
        public readonly string $actionid,
        /** @var string Tool name. */
        public readonly string $tool,
        /** @var string Severity of the action. */
        public readonly string $severity,
        /** @var bool Whether the PIN is asked. */
        public readonly bool $askpin,
        /** @var array Readable card data. */
        public readonly array $card,
    ) {
    }

    /**
     * Whether the action is critical: it is confirmed only with the confirmation word.
     *
     * @return bool
     */
    public function is_critical(): bool {
        return $this->severity === pending_action_service::SEVERITY_CRITICAL;
    }
}
