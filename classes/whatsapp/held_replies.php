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

namespace local_jedomi\whatsapp;

use local_jedomi\local\service\whatsapp_link_service;

/**
 * Replies that found the 24-hour window of the student closed, kept until the next message of the student opens it.
 *
 * They are kept in a row of the WhatsApp session table whose chat key is whatsapp_link_service::HELD_PREFIX plus the
 * chat key of the student, so they are exported, moved on an identity change and removed on unlink with the rest of
 * the chat. The idle conversation cleanup leaves them alone; cleanup_whatsapp removes them after TTL. The texts are
 * already in the format of WhatsApp, as the transport received them. A text is removed only once it was sent
 * (peek(), then acknowledge()): a text that could not be sent waits, with the ones after it, for the next message.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class held_replies {
    /** @var int Most texts kept: the newest ones. */
    public const MAX_TEXTS = 10;

    /** @var int Seconds held replies wait for the next message of the student. */
    public const TTL = 7 * DAYSECS;

    /** @var string Table of the rows. */
    private const TABLE = whatsapp_link_service::SESSIONS_TABLE;

    /**
     * Keep a text for the link.
     *
     * @param \stdClass $link Link of the student.
     * @param string $text Text, in the format of WhatsApp.
     * @return bool Whether it is the first held text: nothing was waiting for the student before.
     */
    public static function hold(\stdClass $link, string $text): bool {
        global $DB;
        $key = self::key($link);
        $now = \core\di::get(\core\clock::class)->time();
        $row = $DB->get_record(self::TABLE, ['chatid' => $key]);
        if (!$row) {
            try {
                $DB->insert_record(self::TABLE, (object) ['chatid' => $key, 'messages' => json_encode([$text]),
                    'lastactivity' => $now]);
                return true;
            } catch (\dml_write_exception $e) {
                // Another request held a text for the student in the meantime.
                $row = $DB->get_record(self::TABLE, ['chatid' => $key], '*', MUST_EXIST);
            }
        }
        $texts = self::decode($row->messages);
        $texts[] = $text;
        $texts = array_slice($texts, -self::MAX_TEXTS);
        $DB->update_record(self::TABLE, (object) ['id' => $row->id, 'messages' => json_encode($texts),
            'lastactivity' => $now]);
        return false;
    }

    /**
     * The held texts of the link, oldest first. They stay held until acknowledge() removes the delivered ones.
     *
     * @param \stdClass $link Link of the student.
     * @return string[]
     */
    public static function peek(\stdClass $link): array {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['chatid' => self::key($link)]);
        return $row ? self::decode($row->messages) : [];
    }

    /**
     * Remove the texts delivered: the first $sent texts of the list peek() returned. The texts not sent stay held in
     * order. The row is read again, so a text held meanwhile is kept, and when it pushed out the oldest texts (see
     * MAX_TEXTS) only the delivered texts still kept are removed. The time of the row is not changed, so the TTL still
     * counts from the last held text.
     *
     * @param \stdClass $link Link of the student.
     * @param string[] $held Texts returned by peek().
     * @param int $sent How many of them were sent, from the first one.
     */
    public static function acknowledge(\stdClass $link, array $held, int $sent): void {
        global $DB;
        $sent = min(max($sent, 0), count($held));
        if ($sent === 0) {
            return;
        }
        $row = $DB->get_record(self::TABLE, ['chatid' => self::key($link)]);
        if (!$row) {
            return;
        }
        $current = self::decode($row->messages);
        $remaining = array_slice($current, max(0, $sent - self::trimmed($held, $current)));
        if ($remaining === []) {
            $DB->delete_records(self::TABLE, ['id' => $row->id]);
            return;
        }
        $DB->set_field(self::TABLE, 'messages', json_encode($remaining), ['id' => $row->id]);
    }

    /**
     * How many of the texts read were pushed out of the stored list since: the stored list starts with the rest of
     * them, followed by the texts held since. All of them when none of the texts read is kept.
     *
     * @param string[] $read Texts read.
     * @param string[] $current Texts stored now.
     * @return int
     */
    private static function trimmed(array $read, array $current): int {
        $count = count($read);
        for ($offset = 0; $offset < $count; $offset++) {
            $kept = array_slice($read, $offset);
            if (array_slice($current, 0, count($kept)) === $kept) {
                return $offset;
            }
        }
        return $count;
    }

    /**
     * Chat key of the held texts of a link.
     *
     * @param \stdClass $link Link.
     * @return string
     */
    private static function key(\stdClass $link): string {
        return whatsapp_link_service::HELD_PREFIX . whatsapp_link_service::chatkey((string) $link->bsuid);
    }

    /**
     * Texts of a stored row.
     *
     * @param string|null $messages JSON list of texts.
     * @return string[]
     */
    private static function decode(?string $messages): array {
        $texts = json_decode((string) $messages, true);
        return is_array($texts) ? array_values(array_filter($texts, 'is_string')) : [];
    }
}
