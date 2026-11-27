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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use local_jedomi\local\secret_store;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\conversation_engine;

/**
 * Processes one row of the WhatsApp inbox, inline after the early response or from the rescue task.
 *
 * A row is claimed with a conditional update (pending, or processing for longer than LOCK_TTL, to processing) under a
 * lock per row, so the webhook and the rescue task never process it twice. Identity change system messages move the
 * link to the new BSUID here; every other message goes to the message_processor port. A finished row (done or failed)
 * keeps no payload.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inbox_processor {
    /** @var string Stored, waiting to be processed. */
    public const STATUS_PENDING = 'pending';

    /** @var string Taken by a worker. */
    public const STATUS_PROCESSING = 'processing';

    /** @var string Processed. */
    public const STATUS_DONE = 'done';

    /** @var string Not processable, or failed MAX_ATTEMPTS times. */
    public const STATUS_FAILED = 'failed';

    /** @var string The row was processed now. */
    public const RESULT_DONE = 'done';

    /** @var string The row was already finished, or does not exist: nothing to do. */
    public const RESULT_SKIPPED = 'skipped';

    /** @var string Another worker holds the row; try again after LOCK_TTL. */
    public const RESULT_BUSY = 'busy';

    /** @var string Processing failed and the row is pending again for another attempt. */
    public const RESULT_RETRY = 'retry';

    /** @var string The row failed for good. */
    public const RESULT_FAILED = 'failed';

    /** @var int Seconds after which a row left in processing is taken over, as the conversation lock. */
    public const LOCK_TTL = conversation_engine::LOCK_TTL;

    /** @var int Attempts before a row fails for good. */
    public const MAX_ATTEMPTS = 3;

    /** @var string Table of the inbox. */
    private const TABLE = whatsapp_link_service::INBOX_TABLE;

    /**
     * Process a row when it is pending, or processing for longer than LOCK_TTL.
     *
     * @param int $inboxid Row id.
     * @return string One of the RESULT_* constants.
     */
    public function process(int $inboxid): string {
        $lock = self::acquire_lock($inboxid);
        if ($lock === false) {
            return self::RESULT_BUSY;
        }
        try {
            return $this->process_unlocked($inboxid);
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Claim and process a row; the caller holds the lock of the row when one is available.
     *
     * @param int $inboxid Row id.
     * @return string One of the RESULT_* constants.
     */
    private function process_unlocked(int $inboxid): string {
        global $DB;
        $row = $DB->get_record(self::TABLE, ['id' => $inboxid]);
        if (!$row || in_array($row->status, [self::STATUS_DONE, self::STATUS_FAILED], true)) {
            return self::RESULT_SKIPPED;
        }
        $now = self::now();
        if ($row->status === self::STATUS_PROCESSING && (int) $row->timeprocessed >= $now - self::LOCK_TTL) {
            return self::RESULT_BUSY;
        }
        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            $this->finish($inboxid, self::STATUS_FAILED, $now);
            return self::RESULT_FAILED;
        }
        if (!$this->claim($row, $now)) {
            return self::RESULT_BUSY;
        }
        $attempts = (int) $row->attempts + 1;

        $message = self::decode((string) $row->payload);
        if ($message === null) {
            $this->finish($inboxid, self::STATUS_FAILED, $now);
            \local_jedomi_log('WhatsApp inbox: unreadable payload', ['row' => $inboxid]);
            return self::RESULT_FAILED;
        }

        try {
            if ($message->is_identity_change()) {
                whatsapp_link_service::change_identity(
                    $message->previousbsuid,
                    $message->bsuid,
                    $message->newwaid,
                    $message->systemtype === inbound::NUMBER_CHANGED
                );
            } else {
                self::message_processor()->process($message);
            }
        } catch (\Throwable $e) {
            \local_jedomi_log('WhatsApp inbox: processing failed', [
                'row' => $inboxid,
                'attempt' => $attempts,
                'error' => \local_jedomi\local\safe_error::describe($e),
            ]);
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->finish($inboxid, self::STATUS_FAILED, self::now());
                return self::RESULT_FAILED;
            }
            $DB->set_field(self::TABLE, 'status', self::STATUS_PENDING, ['id' => $inboxid]);
            return self::RESULT_RETRY;
        }

        $this->finish($inboxid, self::STATUS_DONE, self::now());
        return self::RESULT_DONE;
    }

    /**
     * Take a row with a conditional update: pending, or processing since before LOCK_TTL, becomes processing.
     *
     * @param \stdClass $row Row as read.
     * @param int $now Current time.
     * @return bool Whether this worker took it.
     */
    private function claim(\stdClass $row, int $now): bool {
        global $DB;
        $DB->execute(
            'UPDATE {' . self::TABLE . '}
                SET status = :processing, attempts = :attempts, timeprocessed = :now
              WHERE id = :id AND attempts = :previous
                AND (status = :pending OR (status = :stale AND timeprocessed < :cutoff))',
            [
                'processing' => self::STATUS_PROCESSING,
                'attempts' => (int) $row->attempts + 1,
                'now' => $now,
                'id' => $row->id,
                'previous' => (int) $row->attempts,
                'pending' => self::STATUS_PENDING,
                'stale' => self::STATUS_PROCESSING,
                'cutoff' => $now - self::LOCK_TTL,
            ]
        );
        $claimed = $DB->get_record(self::TABLE, ['id' => $row->id], 'status, attempts, timeprocessed');
        return $claimed
            && $claimed->status === self::STATUS_PROCESSING
            && (int) $claimed->attempts === (int) $row->attempts + 1
            && (int) $claimed->timeprocessed === $now;
    }

    /**
     * Finish a row and blank its payload, so no message stays stored after it is handled.
     *
     * @param int $inboxid Row id.
     * @param string $status STATUS_DONE or STATUS_FAILED.
     * @param int $now Current time.
     */
    private function finish(int $inboxid, string $status, int $now): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) ['id' => $inboxid, 'status' => $status, 'payload' => '',
            'timeprocessed' => $now]);
    }

    /**
     * The message stored in an encrypted payload.
     *
     * @param string $payload Stored payload.
     * @return inbound|null Null when it cannot be decrypted or parsed.
     */
    private static function decode(string $payload): ?inbound {
        try {
            $data = json_decode(secret_store::decrypt($payload), true);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($data) || !is_array($data['message'] ?? null)) {
            return null;
        }
        $contact = is_array($data['contact'] ?? null) ? [$data['contact']] : [];
        return inbound_parser::parse($data['message'], $contact);
    }

    /**
     * The message processor registered in the container, or the chat_processor of the channel.
     *
     * @return message_processor
     */
    private static function message_processor(): message_processor {
        $container = \core\di::get_container();
        return $container->has(message_processor::class)
            ? $container->get(message_processor::class)
            : new chat_processor();
    }

    /**
     * Take the lock of a row.
     *
     * @param int $inboxid Row id.
     * @return \core\lock\lock|false|null The lock, false when another process holds it, null when no lock factory is
     *     available (the conditional update still keeps a second worker out).
     */
    private static function acquire_lock(int $inboxid) {
        try {
            $factory = \core\lock\lock_config::get_lock_factory('local_jedomi_whatsapp_inbox');
            return $factory->get_lock('inbox_' . $inboxid, 0) ?: false;
        } catch (\Throwable $e) {
            \local_jedomi_log('WhatsApp inbox: lock not available (' . get_class($e) . ')');
            return null;
        }
    }

    /**
     * Current time on the Moodle clock.
     *
     * @return int
     */
    private static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
