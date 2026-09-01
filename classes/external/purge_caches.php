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
 * jedomi_purge_caches — CompanionWS external function.
 *
 * Requirement CWS-CACHE-01:
 *  - Validates HMAC before execution
 *  - Requires moodle/site:config capability (admin-level)
 *  - Calls purge_all_caches() natively — no CLI subprocess
 *  - Returns cache type list and execution time
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

/**
 * External function that purges all Moodle caches after HMAC authentication.
 */
class purge_caches extends external_api {
    use companion_hmac;

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------

    /**
     * Describe the parameters accepted by the execute function.
     *
     * @return external_function_parameters HMAC auth fields only.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT, 'Unix timestamp from X-Jedomi-Timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of the request body'),
        ]);
    }

    /**
     * Describe the structure returned by the execute function.
     *
     * @return external_single_structure Status, list of cleared cache types and timing.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'         => new external_value(PARAM_TEXT, '"success" or "error"'),
            'caches_cleared' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Cache type name'),
                'List of cache types that were cleared'
            ),
            'execution_ms'   => new external_value(PARAM_INT, 'Execution time in milliseconds'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    /**
     * Authenticate the request and purge all Moodle caches via purge_all_caches().
     *
     * @param  string $hmac_signature HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmac_timestamp Unix timestamp from the request header.
     * @param  string $payload_hash   SHA256 hex of the request body.
     * @return array Status, cleared cache type names and execution time in milliseconds.
     */
    public static function execute(
        string $hmac_signature,
        int $hmac_timestamp,
        string $payload_hash
    ): array {
        // --- Capability check (admin-level required per spec CWS-CACHE-01) ---
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // --- Parameter validation ---
        $validated = self::validate_parameters(
            self::execute_parameters(),
            compact('hmac_signature', 'hmac_timestamp', 'payload_hash')
        );

        // --- HMAC authentication ---
        self::verify_hmac(
            'jedomi_purge_caches',
            $validated['hmac_timestamp'],
            $validated['payload_hash'],
            $validated['hmac_signature']
        );

        $start = microtime(true);

        // Moodle's native cache purge — equivalent to Site Admin → Development → Purge all caches.
        // Timeout per spec: 60 seconds.
        @set_time_limit(60);
        purge_all_caches();

        $execution_ms = (int) ((microtime(true) - $start) * 1000);

        // These are the cache subsystems purge_all_caches() clears.
        $caches_cleared = ['lang', 'template', 'js', 'theme', 'css', 'other'];

        \local_jedomi_log(sprintf(
            '[local_jedomi] purge_caches: execution_ms=%d',
            $execution_ms
        ));

        return [
            'status'         => 'success',
            'caches_cleared' => $caches_cleared,
            'execution_ms'   => $execution_ms,
        ];
    }
}
