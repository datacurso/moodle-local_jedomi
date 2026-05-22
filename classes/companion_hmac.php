<?php
/**
 * HMAC-SHA256 authentication trait for CompanionWS functions.
 *
 * Design D4: message = "{function_name}:{timestamp}:{sha256(raw_body_bytes)}"
 * Secret is stored in mdl_config as local_jedomi / hmac_secret.
 * TTL: 60 seconds — drift > 60s is rejected.
 *
 * Security invariants:
 *  - Timing-safe comparison via hash_equals() always
 *  - Secret is NEVER exposed in exceptions or logs
 *  - All HMAC failures return the same generic message ("Unauthorized")
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

defined('MOODLE_INTERNAL') || die();

trait companion_hmac {

    /**
     * Validate the HMAC-SHA256 signature sent by the Jedomi Docker gateway.
     *
     * The gateway sends two HTTP headers (mapped to WS params by the caller):
     *   X-Jedomi-Signature  → $signature  (hex digest, 64 chars)
     *   X-Jedomi-Timestamp  → $timestamp  (Unix int)
     *
     * The payload hash is SHA256 of the serialised WS function parameters
     * (everything except the auth fields themselves), hex-encoded.
     *
     * @param  string $function_name  WS function name, e.g. "jedomi_execute_sql"
     * @param  int    $timestamp      Unix timestamp from the request header
     * @param  string $payload_hash   SHA256(raw request body bytes), hex
     * @param  string $signature      HMAC-SHA256 hex digest from the gateway
     * @return void   Throws \moodle_exception on failure — never returns false
     *
     * @throws \moodle_exception  with errorcode 'unauthorized' on any failure
     */
    protected static function verify_hmac(
        string $function_name,
        int    $timestamp,
        string $payload_hash,
        string $signature
    ): void {
        // 1. Reject stale / future timestamps (replay protection).
        if (abs(time() - $timestamp) > 60) {
            // Log the failure but reveal nothing in the exception.
            self::log_hmac_failure($function_name, 'timestamp_expired');
            throw new \moodle_exception('unauthorized', 'local_jedomi');
        }

        // 2. Retrieve the pre-shared secret.  Auto-generate on first call.
        $secret = self::get_or_create_hmac_secret();

        // 3. Reconstruct the expected signature.
        $message  = "{$function_name}:{$timestamp}:{$payload_hash}";
        $expected = hash_hmac('sha256', $message, $secret);

        // 4. Timing-safe comparison — never short-circuit.
        if (!hash_equals($expected, $signature)) {
            self::log_hmac_failure($function_name, 'invalid_signature');
            throw new \moodle_exception('unauthorized', 'local_jedomi');
        }
    }

    /**
     * Build the payload hash that the gateway should have signed.
     *
     * The gateway serialises all non-auth WS params as a JSON object
     * (keys sorted), then SHA256s the UTF-8 bytes.
     *
     * On the PHP side we reconstruct the same hash from the received params.
     *
     * @param  array $params  Associative array of the non-auth function params
     * @return string         Hex SHA256 digest
     */
    protected static function build_payload_hash(array $params): string {
        ksort($params);
        return hash('sha256', json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Ensure the HMAC secret exists, generating one if it does not.
     *
     * The secret is 64 hex characters (256 bits of entropy).
     * It is stored in mdl_config under plugin='local_jedomi', name='hmac_secret'.
     *
     * @return string  The hex-encoded secret
     */
    private static function get_or_create_hmac_secret(): string {
        $secret = get_config('local_jedomi', 'hmac_secret');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(32)); // 256 bits
            set_config('hmac_secret', $secret, 'local_jedomi');
        }
        return $secret;
    }

    /**
     * Write a security log entry for a failed HMAC validation.
     * Never includes the secret or the expected hash value.
     *
     * @param string $function_name  WS function that was called
     * @param string $reason         Machine-readable failure reason
     */
    private static function log_hmac_failure(string $function_name, string $reason): void {
        $ip = getremoteaddr();
        error_log(sprintf(
            '[local_jedomi] HMAC failure: function=%s reason=%s ip=%s',
            $function_name,
            $reason,
            $ip
        ));
    }
}
