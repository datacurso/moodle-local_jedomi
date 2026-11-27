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

use local_jedomi\messaging\media_ref;

/**
 * Parser of value.messages[] of a WhatsApp webhook.
 *
 * The sender is keyed by from_user_id (BSUID), with contacts[].user_id as fallback; from and wa_id (phone) are only
 * read when they come, never required. The profile name and username of the contact and the *_parent_user_id fields
 * are ignored: none of them is ever a key.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class inbound_parser {
    /** @var string BSUID: ISO country code, a dot, an optional ENT. and up to 128 alphanumeric characters. */
    private const BSUID_PATTERN = '/^[A-Z]{2}\.(ENT\.)?[A-Za-z0-9]{1,128}$/D';

    /** @var string Phone of Meta: digits without +. */
    private const WAID_PATTERN = '/^\d{1,20}$/D';

    /** @var string Message id of Meta: printable ASCII without spaces, up to the column length. */
    private const WAMID_PATTERN = '/^[\x21-\x7e]{1,128}$/D';

    /** @var string[] Media kind of each message type the channel reads. */
    private const MEDIA_KINDS = [
        'audio' => media_ref::AUDIO,
        'image' => media_ref::IMAGE,
        'document' => media_ref::DOCUMENT,
    ];

    /**
     * Whether a value is a BSUID.
     *
     * @param mixed $value Value.
     * @return bool
     */
    public static function is_bsuid(mixed $value): bool {
        return is_string($value) && preg_match(self::BSUID_PATTERN, $value) === 1;
    }

    /**
     * Whether a value is a phone of Meta (wa_id).
     *
     * @param mixed $value Value.
     * @return bool
     */
    public static function is_waid(mixed $value): bool {
        return is_string($value) && preg_match(self::WAID_PATTERN, $value) === 1;
    }

    /**
     * Whether a value is a message id of Meta (wamid).
     *
     * @param mixed $value Value.
     * @return bool
     */
    public static function is_wamid(mixed $value): bool {
        return is_string($value) && preg_match(self::WAMID_PATTERN, $value) === 1;
    }

    /**
     * The contact of value.contacts[] that belongs to a message: the one with its BSUID or phone, or the only one.
     *
     * @param array $message Raw message.
     * @param array $contacts Raw value.contacts[].
     * @return array The contact; empty when none matches.
     */
    public static function contact_for(array $message, array $contacts): array {
        $contacts = array_values(array_filter($contacts, 'is_array'));
        foreach ($contacts as $contact) {
            $byuser = isset($message['from_user_id'], $contact['user_id']) && $contact['user_id'] === $message['from_user_id'];
            $byphone = isset($message['from'], $contact['wa_id']) && $contact['wa_id'] === $message['from'];
            if ($byuser || $byphone) {
                return $contact;
            }
        }
        return count($contacts) === 1 ? $contacts[0] : [];
    }

    /**
     * BSUID of the sender: from_user_id, else contacts[].user_id; empty when neither is a BSUID.
     *
     * @param array $message Raw message.
     * @param array $contacts Raw value.contacts[].
     * @return string
     */
    public static function sender_bsuid(array $message, array $contacts): string {
        if (self::is_bsuid($message['from_user_id'] ?? null)) {
            return $message['from_user_id'];
        }
        $contact = self::contact_for($message, $contacts);
        return self::is_bsuid($contact['user_id'] ?? null) ? $contact['user_id'] : '';
    }

    /**
     * Phone of the sender: from, else contacts[].wa_id; null when Meta did not share it.
     *
     * @param array $message Raw message.
     * @param array $contacts Raw value.contacts[].
     * @return string|null
     */
    public static function sender_waid(array $message, array $contacts): ?string {
        if (self::is_waid($message['from'] ?? null)) {
            return $message['from'];
        }
        $contact = self::contact_for($message, $contacts);
        return self::is_waid($contact['wa_id'] ?? null) ? $contact['wa_id'] : null;
    }

    /**
     * Parse one message of value.messages[].
     *
     * @param array $message Raw message.
     * @param array $contacts Raw value.contacts[] (or the matching contact alone).
     * @return inbound|null The message; null when it has no valid id.
     */
    public static function parse(array $message, array $contacts = []): ?inbound {
        if (!self::is_wamid($message['id'] ?? null)) {
            return null;
        }
        $type = is_string($message['type'] ?? null) ? $message['type'] : '';
        $text = $type === 'text' ? self::string($message['text']['body'] ?? null) : '';

        $media = null;
        $caption = '';
        if (isset(self::MEDIA_KINDS[$type]) && is_array($message[$type] ?? null)) {
            $part = $message[$type];
            $media = new media_ref(
                self::MEDIA_KINDS[$type],
                self::string($part['id'] ?? null),
                self::string($part['filename'] ?? null),
                self::string($part['mime_type'] ?? null)
            );
            $caption = self::string($part['caption'] ?? null);
        }

        $system = $type === inbound::TYPE_SYSTEM && is_array($message['system'] ?? null) ? $message['system'] : [];
        $previous = self::is_bsuid($system['previous_user_id'] ?? null) ? $system['previous_user_id'] : '';
        $newwaid = self::is_waid($system['new_wa_id'] ?? null) ? $system['new_wa_id'] : null;
        $bsuid = self::sender_bsuid($message, $contacts);
        if ($bsuid === '' && self::is_bsuid($system['user_id'] ?? null)) {
            $bsuid = $system['user_id'];
        }

        return new inbound(
            $message['id'],
            $bsuid,
            self::sender_waid($message, $contacts),
            $type,
            $text,
            self::reply_id($type, $message),
            $media,
            $caption,
            self::string($system['type'] ?? null),
            $previous,
            $newwaid,
            is_numeric($message['timestamp'] ?? null) ? (int) $message['timestamp'] : 0
        );
    }

    /**
     * Id of the pressed button or list option of an interactive reply.
     *
     * @param string $type Message type.
     * @param array $message Raw message.
     * @return string|null
     */
    private static function reply_id(string $type, array $message): ?string {
        if ($type !== 'interactive' || !is_array($message['interactive'] ?? null)) {
            return null;
        }
        $interactive = $message['interactive'];
        foreach (['button_reply', 'list_reply'] as $kind) {
            $id = $interactive[$kind]['id'] ?? null;
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }
        return null;
    }

    /**
     * A trimmed string, or an empty one when the value is not a string.
     *
     * @param mixed $value Value.
     * @return string
     */
    private static function string(mixed $value): string {
        return is_string($value) ? trim($value) : '';
    }
}
