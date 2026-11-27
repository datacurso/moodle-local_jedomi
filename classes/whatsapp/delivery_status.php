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
 * A delivery status of value.statuses[] of a WhatsApp webhook, validated by status_parser.
 *
 * The status is found by the message id (wamid) of the message sent: Meta omits recipient_user_id when the message was
 * sent to a phone, and omits contacts in failed statuses.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delivery_status {
    /** @var string The message left the business. */
    public const SENT = 'sent';

    /** @var string The message reached the device. */
    public const DELIVERED = 'delivered';

    /** @var string The user read the message. */
    public const READ = 'read';

    /** @var string The message could not be delivered. */
    public const FAILED = 'failed';

    /** @var string[] States the channel reads. */
    public const KNOWN = [self::SENT, self::DELIVERED, self::READ, self::FAILED];

    /**
     * Constructor.
     *
     * @param string $wamid Message id of the message sent.
     * @param string $status One of the KNOWN states.
     * @param string $recipientbsuid BSUID of the recipient; empty when Meta omitted it (send to a phone).
     * @param string|null $recipientwaid Phone of the recipient; null when Meta omitted it.
     * @param int[] $errorcodes Error codes of a failed status.
     * @param int $timestamp Time Meta gives to the status; 0 when missing.
     */
    public function __construct(
        /** @var string Message id of the message sent. */
        public readonly string $wamid,
        /** @var string One of the KNOWN states. */
        public readonly string $status,
        /** @var string BSUID of the recipient. */
        public readonly string $recipientbsuid,
        /** @var string|null Phone of the recipient. */
        public readonly ?string $recipientwaid,
        /** @var int[] Error codes. */
        public readonly array $errorcodes,
        /** @var int Time Meta gives to the status. */
        public readonly int $timestamp,
    ) {
    }

    /**
     * Whether the message could not be delivered.
     *
     * @return bool
     */
    public function is_failed(): bool {
        return $this->status === self::FAILED;
    }
}
