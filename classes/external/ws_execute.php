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
 * local_jedomi_ws_execute — CompanionWS external function.
 *
 * Executes a Moodle web service function on behalf of a user, with
 * double-gate security: (1) role-based allowlist from admin settings,
 * (2) native Moodle capabilities in the target WS class.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\external;

use aiprovider_datacurso\local\plugin_settings;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;
use local_jedomi\local\ws_runner;

/**
 * External function that proxies a call to another Moodle web service function through a role allowlist.
 */
class ws_execute extends external_api {
    use companion_hmac;

    /**
     * Describe the parameters accepted by the execute function.
     *
     * @return external_function_parameters Target WS name, JSON params and HMAC auth fields.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'wsname'         => new external_value(PARAM_ALPHANUMEXT, 'Web service function name'),
            'params'         => new external_value(PARAM_RAW, 'JSON-encoded parameters for the WS'),
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT, 'Unix timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of request body'),
        ]);
    }

    /**
     * Describe the structure returned by the execute function.
     *
     * @return external_single_structure JSON-encoded result from the target web service.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'result' => new external_value(PARAM_RAW, 'JSON-encoded result from the target WS'),
        ]);
    }

    /**
     * Execute a Moodle WS function on behalf of the current user.
     *
     * Security gates:
     *  1. validate_context + require_capability
     *  2. HMAC verification (unless skip_hmac)
     *  3. Role-based allowlist check
     *  4. Native Moodle capabilities inside the target WS class
     *
     * @param  string $wsname         WS function name.
     * @param  string $params         JSON-encoded params.
     * @param  string $hmacsignature HMAC hex signature.
     * @param  int    $hmactimestamp Unix timestamp.
     * @param  string $payloadhash   SHA256 hex of the request body.
     * @param  bool   $skiphmac      Skip HMAC (in-process callers that already authenticated the user).
     * @return array                  ['result' => json_encode($targetresult)]
     */
    public static function execute(
        string $wsname,
        string $params,
        string $hmacsignature,
        int $hmactimestamp,
        string $payloadhash,
        bool $skiphmac = false
    ): array {
        // ... ── Gate 1: validate context + capability ────────────────────────────
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/jedomi:companion_api', $context);

        // ... ── Parameter validation ─────────────────────────────────────────────
        $validated = self::validate_parameters(
            self::execute_parameters(),
            [
                'wsname'         => $wsname,
                'params'         => $params,
                'hmac_signature' => $hmacsignature,
                'hmac_timestamp' => $hmactimestamp,
                'payload_hash'   => $payloadhash,
            ]
        );

        plugin_settings::require_enabled('local_jedomi');

        // ... ── Gate 2: HMAC authentication ──────────────────────────────────────
        if (!$skiphmac) {
            self::verify_hmac(
                'local_jedomi_ws_execute',
                $hmactimestamp,
                $payloadhash,
                $hmacsignature,
                self::signed_params($validated)
            );
        }

        // ... ── Gate 3: role list (shared with the chat channels) ────────────────────
        require_once(__DIR__ . '/../../lib.php');
        if (!ws_runner::role_allows(\local_jedomi_get_user_role(), $wsname)) {
            throw new \moodle_exception('ws_not_in_allowlist', 'local_jedomi');
        }

        // ... ── Gate 4: call the target function (native capabilities inside) ─────
        $decoded = json_decode($params, true);
        $result = ws_runner::call(ws_runner::resolve($wsname), is_array($decoded) ? $decoded : []);

        return ['result' => json_encode($result)];
    }
}
