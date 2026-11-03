<?php
/**
 * jedomi_get_site_config — CompanionWS external function.
 *
 * Requirement CWS-CONFIG-01:
 *  - Validates HMAC before execution
 *  - Returns ONLY keys in the hardcoded allowlist — no additions without spec update
 *  - Keys not in the allowlist are silently omitted (not an error)
 *  - Sensitive values (smtphosts) are masked: domain only
 *  - Allowlist is enforced server-side; client cannot override it
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

class get_site_config extends external_api {

    use companion_hmac;

    /**
     * Exhaustive allowlist (spec CWS-CONFIG-01).
     * DO NOT add keys here without an explicit spec update.
     */
    private const ALLOWLIST = [
        'sitename',
        'siteurl',
        'wwwroot',
        'lang',
        'country',
        'timezone',
        'maxbytes',
        'passwordpolicy',
        'minpasswordlength',
        'passwordminlength',
        'passwordmaxlength',
        'enrol_plugins_enabled',
        'auth_plugins_enabled',
        'smtphosts',
    ];

    /**
     * Keys whose values must be masked before returning.
     * Masking: extract the domain portion only (strip credentials/port).
     */
    private const MASKED_KEYS = ['smtphosts'];

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'keys' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Config key name'),
                'List of config keys to retrieve (must be subset of the allowlist)'
            ),
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT,         'Unix timestamp from X-Jedomi-Timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of the request body'),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        // Config values are heterogeneous; return as a JSON string.
        return new external_single_structure([
            'configs_json' => new external_value(PARAM_RAW, 'Allowed config values serialised as a JSON object'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    public static function execute(
        array  $keys,
        string $hmac_signature,
        int    $hmac_timestamp,
        string $payload_hash,
        bool   $skip_hmac = false
    ): array {
        // --- Capability check ---
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // --- Parameter validation ---
        $validated = self::validate_parameters(
            self::execute_parameters(),
            compact('keys', 'hmac_signature', 'hmac_timestamp', 'payload_hash')
        );
        $keys           = $validated['keys'];
        $hmac_signature = $validated['hmac_signature'];
        $hmac_timestamp = $validated['hmac_timestamp'];
        $payload_hash   = $validated['payload_hash'];

        // --- HMAC authentication ---
        if (!$skip_hmac) {
            self::verify_payload('jedomi_get_site_config', $payload_hash, ['keys' => $keys]);
            self::verify_hmac('jedomi_get_site_config', $hmac_timestamp, $payload_hash, $hmac_signature);
        }

        // --- Build response (server-side allowlist enforcement) ---
        $configs = [];
        $allowset = array_flip(self::ALLOWLIST); // O(1) lookup

        foreach ($keys as $key) {
            // Silently skip keys not in the allowlist — no error, no indication.
            if (!isset($allowset[$key])) {
                continue;
            }

            $value = get_config('core', $key);
            // Fallback: model often asks for "siteurl" but Moodle stores it as "wwwroot".
            if ($value === false && $key === 'siteurl') {
                $value = get_config('core', 'wwwroot');
            }
            if ($value === false) {
                // Key not set in this Moodle installation — omit.
                continue;
            }

            // Apply masking for sensitive keys.
            if (in_array($key, self::MASKED_KEYS, true)) {
                $value = self::mask_sensitive($key, (string) $value);
            }

            $configs[$key] = $value;
        }

        error_log(sprintf(
            '[local_jedomi] get_site_config: requested=%d returned=%d',
            count($keys),
            count($configs)
        ));

        return [
            'configs_json' => json_encode($configs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Mask a sensitive config value.
     *
     * For smtphosts: strip credentials and return only the hostname(s).
     * e.g. "user:pass@smtp.gmail.com:587" → "smtp.gmail.com"
     *
     * @param  string $key    Config key name
     * @param  string $value  Raw config value
     * @return string         Masked value
     */
    private static function mask_sensitive(string $key, string $value): string {
        if ($key === 'smtphosts') {
            // smtphosts can be a semicolon-separated list.
            $hosts = preg_split('/[;,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
            $masked = array_map(function (string $host): string {
                // Remove credentials: user:pass@host:port → host
                if (preg_match('/@(.+)$/', $host, $m)) {
                    $host = $m[1];
                }
                // Remove port.
                $host = preg_replace('/:\d+$/', '', $host);
                return $host;
            }, $hosts);
            return implode('; ', $masked);
        }

        // Generic fallback: first 3 chars + mask.
        $prefix = mb_substr($value, 0, 3, 'UTF-8');
        return $prefix . '***';
    }
}
