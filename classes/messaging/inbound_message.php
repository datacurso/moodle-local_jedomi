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
 * Inbound message of a messaging channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * What a user sent to a channel (Telegram, WhatsApp), already validated and normalised by the parser of the channel:
 * a typed message, with its text and attachment, or a pressed button, with its payload.
 */
final class inbound_message {
    /**
     * Constructor.
     *
     * @param string $channel Name of the channel, e.g. telegram.
     * @param string $chatkey Validated chat key of the channel.
     * @param string $externalid Id of what was received: the message id, or the id of the button press.
     * @param string $text Trimmed text of a typed message; empty for a button press or a message without text.
     * @param string|null $actionpayload Payload of a pressed button; null for a typed message.
     * @param media_ref|null $media Attachment of the message; null when it has none the channel can read.
     * @param string $caption Trimmed caption of the attachment; empty when there is none.
     * @param bool $isprivate Whether the chat is a private chat with one person.
     * @param string $username Name of the sender in the channel, kept with the link; empty when unknown.
     * @param string $messageid Id of the message in the chat: the typed message, or the message that carries the
     *     pressed button; empty when unknown.
     */
    public function __construct(
        /** @var string Name of the channel. */
        public readonly string $channel,
        /** @var string Validated chat key of the channel. */
        public readonly string $chatkey,
        /** @var string Id of what was received. */
        public readonly string $externalid,
        /** @var string Trimmed text of a typed message. */
        public readonly string $text,
        /** @var string|null Payload of a pressed button. */
        public readonly ?string $actionpayload,
        /** @var media_ref|null Attachment of the message. */
        public readonly ?media_ref $media,
        /** @var string Trimmed caption of the attachment. */
        public readonly string $caption,
        /** @var bool Whether the chat is a private chat with one person. */
        public readonly bool $isprivate,
        /** @var string Name of the sender in the channel. */
        public readonly string $username,
        /** @var string Id of the message in the chat. */
        public readonly string $messageid,
    ) {
    }

    /**
     * Whether the user pressed a button instead of typing a message.
     *
     * @return bool
     */
    public function is_action(): bool {
        return $this->actionpayload !== null;
    }
}
