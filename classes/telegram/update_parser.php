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
 * Parser of Telegram updates.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\messaging\inbound_message;
use local_jedomi\messaging\media_ref;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Turns a Telegram update (a message or a pressed inline button) into an inbound message of the router.
 *
 * The update arrives from an external HTTP payload: the chat id is validated here, before it reaches any SQL, cache
 * key or log line.
 */
final class update_parser {
    /** @var string Name of the channel of the messages. */
    private const CHANNEL = 'telegram';

    /**
     * Inbound message of an update.
     *
     * @param array $update Decoded JSON from the Telegram webhook.
     * @return inbound_message|null The message, or null for an update that is not processed: an unknown type, a message
     *     with an invalid chat id (logged without the raw value) or an incomplete button press.
     */
    public static function parse(array $update): ?inbound_message {
        if (isset($update['callback_query'])) {
            return self::parse_callback_query($update['callback_query']);
        }
        if (isset($update['message'])) {
            return self::parse_message($update['message']);
        }
        // Unknown update type — silently ignored.
        \local_jedomi_log('Telegram webhook: unknown update type');
        return null;
    }

    /**
     * Validate and normalise a Telegram chat id.
     *
     * Telegram chat ids are integers (negative for groups and channels).
     *
     * @param string $chatid Raw value from the webhook payload.
     * @return string|null The normalised id, or null when invalid.
     */
    public static function chat_key(string $chatid): ?string {
        if (!preg_match('/^-?\d{1,20}$/', $chatid)) {
            return null;
        }
        return $chatid;
    }

    /**
     * Inbound message of a Telegram message (text, photo, document, voice or audio).
     *
     * @param array $message Message object of the update.
     * @return inbound_message|null
     */
    private static function parse_message(array $message): ?inbound_message {
        $chatkey = self::chat_key((string) ($message['chat']['id'] ?? ''));
        if ($chatkey === null) {
            // Attacker-controllable value: never log it raw, never let it reach the DB.
            \local_jedomi_log('[TELEGRAM ENTRY] rejected non-numeric chat id (len='
                . mb_strlen((string) ($message['chat']['id'] ?? '')) . ')');
            return null;
        }
        $messageid = (string) ($message['message_id'] ?? '');
        return new inbound_message(
            self::CHANNEL,
            $chatkey,
            $messageid,
            trim($message['text'] ?? ''),
            null,
            self::media($message),
            trim((string) ($message['caption'] ?? '')),
            self::is_private_chat($chatkey, (string) ($message['chat']['type'] ?? '')),
            (string) ($message['chat']['username'] ?? ''),
            $messageid
        );
    }

    /**
     * Inbound message of a pressed inline button.
     *
     * @param array $callback The callback_query object of the update.
     * @return inbound_message|null Null when the press has no id, no data or no valid chat.
     */
    private static function parse_callback_query(array $callback): ?inbound_message {
        $callbackid = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatkey = self::chat_key((string) ($callback['message']['chat']['id'] ?? ''));
        if ($callbackid === '' || $data === '' || $chatkey === null) {
            return null;
        }
        return new inbound_message(
            self::CHANNEL,
            $chatkey,
            $callbackid,
            '',
            $data,
            null,
            '',
            self::is_private_chat($chatkey, (string) ($callback['message']['chat']['type'] ?? '')),
            (string) ($callback['message']['chat']['username'] ?? ''),
            (string) ($callback['message']['message_id'] ?? '')
        );
    }

    /**
     * Attachment of a message: a voice message or an audio file, otherwise the largest size of a photo or a document.
     *
     * @param array $message Message object of the update.
     * @return media_ref|null Null when the message has none of them.
     */
    private static function media(array $message): ?media_ref {
        $audio = $message['voice'] ?? $message['audio'] ?? null;
        if (is_array($audio)) {
            // A voice message is always ogg; an audio file keeps its name and type to find its format.
            return new media_ref(
                media_ref::AUDIO,
                (string) ($audio['file_id'] ?? ''),
                isset($message['voice']) ? 'voice.ogg' : (string) ($audio['file_name'] ?? ''),
                (string) ($audio['mime_type'] ?? '')
            );
        }
        $file = !empty($message['photo']) && is_array($message['photo']) ? end($message['photo']) : ($message['document'] ?? null);
        if (!is_array($file)) {
            return null;
        }
        if (isset($message['document'])) {
            return new media_ref(
                media_ref::DOCUMENT,
                (string) ($file['file_id'] ?? ''),
                (string) ($file['file_name'] ?? 'document'),
                (string) ($file['mime_type'] ?? '')
            );
        }
        return new media_ref(media_ref::IMAGE, (string) ($file['file_id'] ?? ''), 'photo.jpg', '');
    }

    /**
     * Whether a Telegram chat is a private chat with one person. Groups, supergroups and channels have negative ids,
     * and their messages carry their type.
     *
     * @param string $chatkey Normalised chat id.
     * @param string $type Chat type from the update, empty when absent.
     * @return bool
     */
    private static function is_private_chat(string $chatkey, string $type): bool {
        if ($type !== '' && $type !== 'private') {
            return false;
        }
        return strpos($chatkey, '-') !== 0;
    }
}
