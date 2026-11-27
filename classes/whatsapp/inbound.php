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

use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\inbound_message;
use local_jedomi\messaging\media_ref;

/**
 * A message of value.messages[] of a WhatsApp webhook, validated by inbound_parser.
 *
 * The student is keyed by the BSUID; the phone (wa_id) is optional and only kept when Meta shares it. Identity change
 * system messages carry the previous BSUID and, when the number changed, the new phone.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class inbound {
    /** @var string Name of the channel in the neutral messages. */
    public const CHANNEL = 'whatsapp';

    /** @var string Type of the system messages. */
    public const TYPE_SYSTEM = 'system';

    /** @var string[] System types that tell the BSUID of the user changed. */
    public const IDENTITY_CHANGES = ['user_changed_user_id', 'user_changed_number'];

    /** @var string System type that tells the phone number of the user changed. */
    public const NUMBER_CHANGED = 'user_changed_number';

    /**
     * Constructor.
     *
     * @param string $wamid Message id of Meta.
     * @param string $bsuid BSUID of the sender; empty when Meta sent none.
     * @param string|null $waid Phone of the sender (digits without +); null when Meta did not share it.
     * @param string $type Message type (text, interactive, audio, image, document, system...).
     * @param string $text Trimmed text of a text message; empty otherwise.
     * @param string|null $replyid Id of the pressed button or list option; null when it is not a reply.
     * @param media_ref|null $media Attachment; null when the message has none the channel reads.
     * @param string $caption Trimmed caption of the attachment.
     * @param string $systemtype Type of a system message; empty otherwise.
     * @param string $previousbsuid Previous BSUID of an identity change; empty otherwise.
     * @param string|null $newwaid New phone of a number change; null when not given.
     * @param int $timestamp Time Meta gives to the message; 0 when missing.
     */
    public function __construct(
        /** @var string Message id of Meta. */
        public readonly string $wamid,
        /** @var string BSUID of the sender. */
        public readonly string $bsuid,
        /** @var string|null Phone of the sender. */
        public readonly ?string $waid,
        /** @var string Message type. */
        public readonly string $type,
        /** @var string Trimmed text. */
        public readonly string $text,
        /** @var string|null Id of the pressed button or list option. */
        public readonly ?string $replyid,
        /** @var media_ref|null Attachment. */
        public readonly ?media_ref $media,
        /** @var string Trimmed caption of the attachment. */
        public readonly string $caption,
        /** @var string Type of a system message. */
        public readonly string $systemtype,
        /** @var string Previous BSUID of an identity change. */
        public readonly string $previousbsuid,
        /** @var string|null New phone of a number change. */
        public readonly ?string $newwaid,
        /** @var int Time Meta gives to the message. */
        public readonly int $timestamp,
    ) {
    }

    /**
     * Whether this is a system message telling that the BSUID of the user changed, with a valid previous BSUID.
     *
     * @return bool
     */
    public function is_identity_change(): bool {
        return $this->type === self::TYPE_SYSTEM
            && in_array($this->systemtype, self::IDENTITY_CHANGES, true)
            && $this->previousbsuid !== '';
    }

    /**
     * The message for the channel-neutral core, keyed by the chat key of the BSUID; null for a sender without BSUID
     * (found by phone when linking) and for system messages.
     *
     * @return inbound_message|null
     */
    public function to_message(): ?inbound_message {
        if ($this->bsuid === '' || $this->type === self::TYPE_SYSTEM) {
            return null;
        }
        return new inbound_message(
            self::CHANNEL,
            whatsapp_link_service::chatkey($this->bsuid),
            $this->wamid,
            $this->text,
            $this->replyid,
            $this->media,
            $this->caption,
            true,
            '',
            $this->wamid
        );
    }
}
