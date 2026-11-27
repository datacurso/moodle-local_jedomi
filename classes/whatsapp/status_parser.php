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
 * Parser of value.statuses[] of a WhatsApp webhook.
 *
 * The recipient is read from recipient_user_id (BSUID) and, when missing, from recipient_id (phone); contacts are not
 * needed, since Meta omits them in failed statuses.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status_parser {
    /**
     * Parse the statuses of a change value; unknown states and statuses without a valid message id are skipped.
     *
     * @param array $value Raw entry[].changes[].value.
     * @return delivery_status[]
     */
    public static function parse(array $value): array {
        $raw = $value['statuses'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $statuses = [];
        foreach ($raw as $status) {
            if (
                !is_array($status) || !inbound_parser::is_wamid($status['id'] ?? null)
                || !in_array($status['status'] ?? null, delivery_status::KNOWN, true)
            ) {
                continue;
            }
            $statuses[] = new delivery_status(
                $status['id'],
                $status['status'],
                inbound_parser::is_bsuid($status['recipient_user_id'] ?? null) ? $status['recipient_user_id'] : '',
                inbound_parser::is_waid($status['recipient_id'] ?? null) ? $status['recipient_id'] : null,
                self::error_codes($status['errors'] ?? []),
                is_numeric($status['timestamp'] ?? null) ? (int) $status['timestamp'] : 0
            );
        }
        return $statuses;
    }

    /**
     * Numeric codes of errors[].
     *
     * @param mixed $errors Raw errors[].
     * @return int[]
     */
    private static function error_codes(mixed $errors): array {
        if (!is_array($errors)) {
            return [];
        }
        $codes = [];
        foreach ($errors as $error) {
            if (is_array($error) && is_numeric($error['code'] ?? null)) {
                $codes[] = (int) $error['code'];
            }
        }
        return $codes;
    }
}
