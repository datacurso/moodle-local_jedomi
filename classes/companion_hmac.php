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
 * HMAC-SHA256 authentication trait for CompanionWS functions.
 *
 * Design D4: message = "{function_name}:{timestamp}:{payload_hash}", where payload_hash is the
 * SHA256 of the JSON object of the non-auth parameters (keys sorted). The server recomputes
 * the payload hash from the parameters it actually received, so a captured signature cannot
 * be reused with other parameters.
 * Secret is stored in mdl_config as local_jedomi / hmac_secret.
 * TTL: 60 seconds — drift > 60s is rejected.
 * Single use: a valid signature is recorded (its hash, in the companion_signatures application cache) for longer than
 * twice the margin, and the same signature sent again is rejected, so a captured request cannot be replayed within the margin.
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

require_once(__DIR__ . '/../lib.php');

/**
 * Provides HMAC-SHA256 request authentication helpers for CompanionWS external functions.
 */
trait companion_hmac {
    /** @var \cache_application|null Record of the used signatures set by the tests; null for the plugin cache. */
    private static $signaturestore = null;

    /**
     * Validate the HMAC-SHA256 signature sent by the Jedomi Docker gateway.
     *
     * The gateway sends two HTTP headers (mapped to WS params by the caller):
     *   X-Jedomi-Signature  → $signature  (hex digest, 64 chars)
     *   X-Jedomi-Timestamp  → $timestamp  (Unix int)
     *
     * The payload hash is SHA256 of the serialised WS function parameters
     * (everything except the auth fields themselves), hex-encoded. It must match the
     * hash recomputed from the received parameters: the signature then covers them.
     *
     * @param  string $functionname  WS function name, e.g. "jedomi_execute_sql"
     * @param  int    $timestamp      Unix timestamp from the request header
     * @param  string $payloadhash   Payload hash sent by the gateway, hex
     * @param  string $signature      HMAC-SHA256 hex digest from the gateway
     * @param  array  $params         Non-auth parameters actually received, by name
     * @return void   Throws \moodle_exception on failure — never returns false
     *
     * @throws \moodle_exception  with errorcode 'unauthorized' on any failure
     */
    protected static function verify_hmac(
        string $functionname,
        int $timestamp,
        string $payloadhash,
        string $signature,
        array $params
    ): void {
        // 1. Reject stale / future timestamps (replay protection).
        if (abs(time() - $timestamp) > 60) {
            // Log the failure but reveal nothing in the exception.
            self::log_hmac_failure($functionname, 'timestamp_expired');
            throw new \moodle_exception('unauthorized', 'local_jedomi');
        }

        // 2. Retrieve the pre-shared secret.  Auto-generate on first call.
        $secret = self::get_or_create_hmac_secret();

        // 3. Reconstruct the expected signature.
        $message  = "{$functionname}:{$timestamp}:{$payloadhash}";
        $expected = hash_hmac('sha256', $message, $secret);

        // 4. Timing-safe comparison — never short-circuit.
        if (!hash_equals($expected, $signature)) {
            self::log_hmac_failure($functionname, 'invalid_signature');
            throw new \moodle_exception('unauthorized', 'local_jedomi');
        }

        // 5. The signed payload hash must be the one of the parameters actually received.
        if (!hash_equals(self::build_payload_hash($params), $payloadhash)) {
            self::log_hmac_failure($functionname, 'payload_mismatch');
            throw new \moodle_exception('unauthorized', 'local_jedomi');
        }

        // 6. Each signature runs once: the same request sent again within the margin is a replay. When the record of the
        // used signatures cannot be checked the request is refused too, logged with its own reason.
        $failure = self::consume_signature($signature);
        if ($failure !== null) {
            self::log_hmac_failure($functionname, $failure);
            throw new \moodle_exception('unauthorized', 'local_jedomi');
        }
    }

    /**
     * Replace the record of the used signatures in the tests, for example with one whose lock is never available.
     *
     * @param \cache_application|null $store Record to use; null for the companion_signatures cache of the plugin.
     * @return void
     */
    public static function set_signature_store_for_testing(?\cache_application $store): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('set_signature_store_for_testing() can only be used in PHPUnit tests.');
        }
        self::$signaturestore = $store;
    }

    /**
     * Record a valid signature as used, unless it was used before.
     *
     * Only the SHA-256 hash of the signature is kept, in an application cache that outlives the accepted margin of the
     * timestamp, so a replay is detected across requests and web servers sharing the cache. The check and the record
     * are made under a lock of the cache. When the lock cannot be taken or the cache fails the signature cannot be
     * checked, so the request is rejected (fail closed) with the reason replay_store_unavailable instead of being
     * taken for a replay.
     *
     * @param  string $signature Valid HMAC-SHA256 hex digest of the request.
     * @return string|null Null when the signature was not used before (it is recorded now); otherwise the reason of
     *                     the rejection: replayed_signature or replay_store_unavailable.
     */
    private static function consume_signature(string $signature): ?string {
        $key = hash('sha256', $signature);
        try {
            $cache = self::$signaturestore ?? \cache::make('local_jedomi', 'companion_signatures');
            if (!$cache->acquire_lock($key)) {
                return 'replay_store_unavailable';
            }
        } catch (\Exception $e) {
            return 'replay_store_unavailable';
        }
        try {
            if ($cache->get($key) !== false) {
                return 'replayed_signature';
            }
            return $cache->set($key, time()) ? null : 'replay_store_unavailable';
        } catch (\Exception $e) {
            return 'replay_store_unavailable';
        } finally {
            try {
                $cache->release_lock($key);
            } catch (\Exception $e) {
                // The lock expires by itself; the outcome of the check is kept.
                debugging('local_jedomi: the lock of a companion signature could not be released.', DEBUG_DEVELOPER);
            }
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
     * The parameters covered by the signature: every validated parameter except the auth fields.
     *
     * @param  array $validated Parameters returned by validate_parameters()
     * @return array            Non-auth parameters, by name
     */
    protected static function signed_params(array $validated): array {
        unset($validated['hmac_signature'], $validated['hmac_timestamp'], $validated['payload_hash']);
        return $validated;
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
     * @param string $functionname  WS function that was called
     * @param string $reason         Machine-readable failure reason
     */
    private static function log_hmac_failure(string $functionname, string $reason): void {
        $ip = getremoteaddr();
        \local_jedomi_log(sprintf(
            '[local_jedomi] HMAC failure: function=%s reason=%s ip=%s',
            $functionname,
            $reason,
            $ip
        ));
    }
}
