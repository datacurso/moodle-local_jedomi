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
 * Channel-neutral rate limiter: messages per minute and cooldown per chat key.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Per-chat rate limit of a messaging channel (Telegram, WhatsApp), kept in that channel's rate limit table.
 *
 * Every channel table has the shape of local_jedomi_telegram_rate_limit: chatid (unique), windowstart, msgcount and
 * blockeduntil. The limiter gets the table name and the names of the local_jedomi settings that hold the messages
 * allowed per minute (0 means no limit) and the cooldown in minutes applied once the limit is passed.
 */
class rate_limiter {
    /** @var int Messages per minute when the limit setting is not saved. */
    public const DEFAULT_LIMIT = 30;

    /** @var int Cooldown minutes when the cooldown setting is not saved. */
    public const DEFAULT_COOLDOWN = 5;

    /** @var int Length of the window in seconds. */
    private const WINDOW = 60;

    /**
     * Constructor.
     *
     * @param string $table Rate limit table of the channel, e.g. local_jedomi_telegram_rate_limit.
     * @param string $limitsetting local_jedomi setting with the messages allowed per minute, e.g. telegram_rate_limit.
     * @param string $cooldownsetting local_jedomi setting with the cooldown in minutes, e.g. telegram_cooldown.
     * @param string $logprefix Prefix of the log line written on a failure, e.g. [TELEGRAM RATE].
     */
    public function __construct(
        /** @var string Rate limit table of the channel. */
        private readonly string $table,
        /** @var string local_jedomi setting with the messages allowed per minute. */
        private readonly string $limitsetting,
        /** @var string local_jedomi setting with the cooldown in minutes. */
        private readonly string $cooldownsetting,
        /** @var string Prefix of the failure log line. */
        private readonly string $logprefix,
    ) {
    }

    /**
     * Messages allowed per minute; 0 means no limit.
     *
     * @return int
     */
    public function limit(): int {
        return $this->int_setting($this->limitsetting, self::DEFAULT_LIMIT);
    }

    /**
     * Minutes a chat stays blocked once it passes the limit.
     *
     * @return int
     */
    public function cooldown_minutes(): int {
        return $this->int_setting($this->cooldownsetting, self::DEFAULT_COOLDOWN);
    }

    /**
     * Record one message against the per-chat window and report if the chat is blocked.
     *
     * Uses a delegated transaction. When the limit cannot be checked or recorded (a database error) the failure is
     * logged and null is returned, so the caller does not process the message and tells the user.
     *
     * @param string $chatkey Chat key of the channel (validated by the channel before it gets here).
     * @return bool|null True if the chat is currently rate-limited (message must be rejected), null on failure.
     */
    public function check_and_record(string $chatkey): ?bool {
        global $DB;
        $now = time();
        $limit = $this->limit();
        $cooldown = $this->cooldown_minutes() * 60;

        if ($limit <= 0) {
            return false; // Unlimited.
        }

        try {
            $transaction = $DB->start_delegated_transaction();
        } catch (\Throwable $e) {
            $this->log_failure($chatkey, $e);
            return null;
        }
        try {
            $record = $DB->get_record($this->table, ['chatid' => $chatkey]);

            if (!$record) {
                $DB->insert_record($this->table, (object) [
                    'chatid' => $chatkey,
                    'windowstart' => $now,
                    'msgcount' => 1,
                    'blockeduntil' => 0,
                ]);
                $transaction->allow_commit();
                return false;
            }

            // Check if blocked.
            if ($record->blockeduntil > $now) {
                $transaction->allow_commit();
                return true;
            }

            // Reset window if expired.
            if ($record->windowstart + self::WINDOW < $now) {
                $record->windowstart = $now;
                $record->msgcount = 1;
                $record->blockeduntil = 0;
                $DB->update_record($this->table, $record);
                $transaction->allow_commit();
                return false;
            }

            // Check limit.
            if ($record->msgcount >= $limit) {
                $record->blockeduntil = $now + $cooldown;
                $DB->update_record($this->table, $record);
                $transaction->allow_commit();
                return true;
            }

            // Increment.
            $record->msgcount = $record->msgcount + 1;
            $DB->update_record($this->table, $record);
            $transaction->allow_commit();
            return false;
        } catch (\Throwable $e) {
            $this->log_failure($chatkey, $e);
            try {
                // The rollback throws the error again; it is already handled here.
                $transaction->rollback($e);
            } catch (\Throwable $rethrown) {
                unset($rethrown);
            }
            return null;
        }
    }

    /**
     * Integer value of a local_jedomi setting, or the default when it is not saved.
     *
     * @param string $name Setting name.
     * @param int $default Value used when the setting is not saved or empty.
     * @return int
     */
    private function int_setting(string $name, int $default): int {
        $value = get_config('local_jedomi', $name);
        return ($value === false || $value === '') ? $default : (int) $value;
    }

    /**
     * Log a failure to check or record the limit, with a pseudonym of the chat key.
     *
     * @param string $chatkey Chat key of the channel.
     * @param \Throwable $e The failure.
     */
    private function log_failure(string $chatkey, \Throwable $e): void {
        \local_jedomi_log($this->logprefix . ' limit check failed for chat ' . \local_jedomi_log_pseudonym('chat', $chatkey)
            . ': ' . \local_jedomi\local\safe_error::describe($e));
    }
}
