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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Monthly WhatsApp message counters of each user (local_jedomi_whatsapp_usage): messages received, free-form service
 * messages sent, templates sent and media received, per month of the Moodle clock.
 *
 * The counters are only recorded: no monthly limit is applied yet. A failure to count is logged and never stops the
 * conversation.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class usage_meter {
    /** @var string Messages received from the user. */
    public const INBOUND = 'inbound';

    /** @var string Free-form service messages sent to the user. */
    public const OUTSERVICE = 'outservice';

    /** @var string Template messages sent to the user. */
    public const OUTTEMPLATE = 'outtemplate';

    /** @var string Media files received from the user. */
    public const MEDIA = 'media';

    /** @var string[] Counters. */
    private const COUNTERS = [self::INBOUND, self::OUTSERVICE, self::OUTTEMPLATE, self::MEDIA];

    /** @var string Table of the counters. */
    private const TABLE = whatsapp_link_service::USAGE_TABLE;

    /**
     * Add to a counter of the user in the current month.
     *
     * @param int $userid User id.
     * @param string $counter One of the counter constants.
     * @param int $amount Amount added.
     * @throws \coding_exception For an unknown counter.
     */
    public static function add(int $userid, string $counter, int $amount = 1): void {
        global $DB;
        if (!in_array($counter, self::COUNTERS, true)) {
            throw new \coding_exception('Unknown WhatsApp usage counter: ' . $counter);
        }
        if ($userid <= 0 || $amount <= 0) {
            return;
        }
        $period = self::period();
        try {
            if (!self::increment($userid, $period, $counter, $amount)) {
                try {
                    $DB->insert_record(self::TABLE, (object) array_merge(
                        array_fill_keys(self::COUNTERS, 0),
                        ['userid' => $userid, 'period' => $period, $counter => $amount]
                    ));
                } catch (\dml_write_exception $e) {
                    // Another request added the row of the month in the meantime.
                    self::increment($userid, $period, $counter, $amount);
                }
            }
        } catch (\Throwable $e) {
            \local_jedomi_log('WhatsApp usage not counted', ['counter' => $counter,
                'error' => \local_jedomi\local\safe_error::describe($e)]);
        }
    }

    /**
     * Month of the Moodle clock, as YYYYMM.
     *
     * @return int
     */
    public static function period(): int {
        return (int) date('Ym', \core\di::get(\core\clock::class)->time());
    }

    /**
     * Add to the counter of an existing row.
     *
     * @param int $userid User id.
     * @param int $period Month.
     * @param string $counter Counter column, already checked.
     * @param int $amount Amount added.
     * @return bool Whether the row of the month existed.
     */
    private static function increment(int $userid, int $period, string $counter, int $amount): bool {
        global $DB;
        if (!$DB->record_exists(self::TABLE, ['userid' => $userid, 'period' => $period])) {
            return false;
        }
        $DB->execute(
            'UPDATE {' . self::TABLE . '} SET ' . $counter . ' = ' . $counter . ' + :amount
              WHERE userid = :userid AND period = :period',
            ['amount' => $amount, 'userid' => $userid, 'period' => $period]
        );
        return true;
    }
}
