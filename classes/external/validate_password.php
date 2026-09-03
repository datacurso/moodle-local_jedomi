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
 * jedomi_validate_password — CompanionWS external function.
 *
 * Requirement CWS-PWD-01:
 *  - Validates HMAC before execution
 *  - Uses Moodle's check_password_policy() — no custom reimplementation
 *  - The candidate password is NEVER logged or cached
 *  - Returns {valid, violations[]} with specific policy rules broken
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
 * External function that checks a candidate password against the Moodle password policy.
 */
class validate_password extends external_api {
    use companion_hmac;

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------.

    /**
     * Describe the parameters accepted by the execute function.
     *
     * @return external_function_parameters Candidate password, optional username and HMAC auth fields.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'password'       => new external_value(PARAM_RAW, 'Candidate password to validate (not logged)'),
            'username'       => new external_value(PARAM_TEXT, 'Optional username for similarity check', VALUE_DEFAULT, ''),
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT, 'Unix timestamp from X-Jedomi-Timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of the request body'),
        ]);
    }

    /**
     * Describe the structure returned by the execute function.
     *
     * @return external_single_structure Validity flag and list of violated policy rules.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'valid'      => new external_value(PARAM_BOOL, 'Whether the password meets the policy'),
            'violations' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Specific policy rule violated (e.g. "minlength:8")'),
                'List of violated policy rules (empty if valid)'
            ),
        ]);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------.

    /**
     * Authenticate the request and validate the password via check_password_policy().
     *
     * @param  string $password       Candidate password to validate; never logged.
     * @param  string $username       Optional username for the similarity check.
     * @param  string $hmacsignature HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmactimestamp Unix timestamp from the request header.
     * @param  string $payloadhash   SHA256 hex of the request body.
     * @return array Validity flag and machine-readable violation codes.
     */
    public static function execute(
        string $password,
        string $username,
        string $hmacsignature,
        int $hmactimestamp,
        string $payloadhash
    ): array {
        global $CFG;

        // ... --- Capability check ---
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/jedomi:companion_api', $context);

        // ... --- Parameter validation (must NOT clean/trim the password itself) ---
        // We intentionally skip validate_call_parameters for $password to avoid
        // PARAM_RAW stripping that could alter the password being tested.
        $validated = self::validate_parameters(
            self::execute_parameters(),
            [
                'password'       => $password,
                'username'       => $username,
                'hmac_signature' => $hmacsignature,
                'hmac_timestamp' => $hmactimestamp,
                'payload_hash'   => $payloadhash,
            ]
        );
        $password       = $validated['password'];   // Keep raw.
        $username       = $validated['username'];
        $hmacsignature = $validated['hmac_signature'];
        $hmactimestamp = $validated['hmac_timestamp'];
        $payloadhash   = $validated['payload_hash'];

        // ... --- HMAC authentication ---
        // IMPORTANT: payload_hash must match what the gateway sent.
        // The gateway hashes the body BEFORE the password is in plaintext on the wire
        // (TLS handles transport); here we just verify the signature.
        self::verify_hmac('jedomi_validate_password', $hmactimestamp, $payloadhash, $hmacsignature);

        // ... --- Validate via Moodle's native policy check ---
        // check_password_policy() fills $errmsg with a human-readable concatenated string.
        // We parse it to extract individual violation codes.
        $errmsg = '';
        $valid  = check_password_policy($password, $errmsg, !empty($username) ? (object)['username' => $username] : null);

        $violations = [];
        if (!$valid && !empty($errmsg)) {
            // Moodle's check_password_policy returns a concatenated string of get_string() calls.
            // We produce machine-readable codes based on the CFG settings that were violated.
            $violations = self::extract_violations($password, $username, $errmsg);
        }

        // NEVER log the password or errmsg (contains policy details that could aid brute-force).
        \local_jedomi_log(sprintf(
            '[local_jedomi] validate_password: valid=%s violations=%d',
            $valid ? 'true' : 'false',
            count($violations)
        ));

        return [
            'valid'      => (bool) $valid,
            'violations' => $violations,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------.

    /**
     * Build machine-readable violation codes.
     *
     * Moodle's check_password_policy() returns a free-text error string.
     * We re-derive the codes from the CFG settings ourselves.
     *
     * @param  string $password  The candidate password (NOT logged)
     * @param  string $username  Optional username for similarity check
     * @param  string $errmsg   Error message from check_password_policy()
     * @return string[]
     */
    private static function extract_violations(string $password, string $username, string $errmsg): array {
        global $CFG;

        $violations = [];
        $len        = core_text::strlen($password);

        if (!empty($CFG->passwordpolicy)) {
            $minlen = (int) ($CFG->minpasswordlength ?? 8);
            if ($len < $minlen) {
                $violations[] = "minlength:{$minlen}";
            }

            $mindigits = (int) ($CFG->minpassworddigits ?? 1);
            if ($mindigits > 0 && preg_match_all('/[0-9]/', $password) < $mindigits) {
                $violations[] = "requiredigit";
            }

            $minlower = (int) ($CFG->minpasswordlower ?? 1);
            if ($minlower > 0 && preg_match_all('/[a-z]/', $password) < $minlower) {
                $violations[] = "requirelower";
            }

            $minupper = (int) ($CFG->minpasswordupper ?? 1);
            if ($minupper > 0 && preg_match_all('/[A-Z]/', $password) < $minupper) {
                $violations[] = "requireupper";
            }

            $minnonalpha = (int) ($CFG->minpasswordnonalphanum ?? 1);
            if ($minnonalpha > 0 && preg_match_all('/[^a-zA-Z0-9]/', $password) < $minnonalpha) {
                $violations[] = "requirenonalpha";
            }

            $maxident = (int) ($CFG->maxconsecutiveidentchars ?? 0);
            if ($maxident > 0) {
                // Detect runs of identical characters longer than the limit.
                if (preg_match('/(.)\1{' . $maxident . ',}/', $password)) {
                    $violations[] = "maxconsecutiveidentchars:{$maxident}";
                }
            }

            if (!empty($username) && stripos($password, $username) !== false) {
                $violations[] = "containsusername";
            }
        }

        // Fallback: if our heuristics found nothing but Moodle did, flag generically.
        if (empty($violations)) {
            $violations[] = 'policy_violation';
        }

        return $violations;
    }
}
