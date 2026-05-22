<?php
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

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

class validate_password extends external_api {

    use companion_hmac;

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'password'       => new external_value(PARAM_RAW,  'Candidate password to validate (not logged)'),
            'username'       => new external_value(PARAM_TEXT, 'Optional username for similarity check', VALUE_DEFAULT, ''),
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT,         'Unix timestamp from X-Jedomi-Timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of the request body'),
        ]);
    }

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
    // -------------------------------------------------------------------------

    public static function execute(
        string $password,
        string $username,
        string $hmac_signature,
        int    $hmac_timestamp,
        string $payload_hash
    ): array {
        global $CFG;

        // --- Capability check ---
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/jedomi:companion_api', $context);

        // --- Parameter validation (must NOT clean/trim the password itself) ---
        // We intentionally skip validate_call_parameters for $password to avoid
        // PARAM_RAW stripping that could alter the password being tested.
        $validated = self::validate_parameters(
            self::execute_parameters(),
            compact('password', 'username', 'hmac_signature', 'hmac_timestamp', 'payload_hash')
        );
        $password       = $validated['password'];   // keep raw
        $username       = $validated['username'];
        $hmac_signature = $validated['hmac_signature'];
        $hmac_timestamp = $validated['hmac_timestamp'];
        $payload_hash   = $validated['payload_hash'];

        // --- HMAC authentication ---
        // IMPORTANT: payload_hash must match what the gateway sent.
        // The gateway hashes the body BEFORE the password is in plaintext on the wire
        // (TLS handles transport); here we just verify the signature.
        self::verify_hmac('jedomi_validate_password', $hmac_timestamp, $payload_hash, $hmac_signature);

        // --- Validate via Moodle's native policy check ---
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
        error_log(sprintf(
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
    // -------------------------------------------------------------------------

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
