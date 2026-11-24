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
 * local_jedomi_get_site_config — CompanionWS external function.
 *
 * Requirement CWS-CONFIG-01:
 *  - Validates HMAC before execution
 *  - Returns ONLY keys in the hardcoded allowlist — no additions without spec update
 *  - Keys not in the allowlist are silently omitted (not an error)
 *  - Sensitive values (smtphosts) are masked: domain only
 *  - Allowlist is enforced server-side; client cannot override it
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\external;

use aiprovider_datacurso\local\plugin_settings;
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
 * External function that returns allowlisted site configuration values, masking sensitive ones.
 */
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
    // -------------------------------------------------------------------------.

    /**
     * Describe the parameters accepted by the execute function.
     *
     * @return external_function_parameters Requested config keys and HMAC auth fields.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'keys' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Config key name'),
                'List of config keys to retrieve (must be subset of the allowlist)'
            ),
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT, 'Unix timestamp from X-Jedomi-Timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of the request body'),
        ]);
    }

    /**
     * Describe the structure returned by the execute function.
     *
     * @return external_single_structure Allowed config values serialised as a JSON object string.
     */
    public static function execute_returns(): external_single_structure {
        // Config values are heterogeneous; return as a JSON string.
        return new external_single_structure([
            'configs_json' => new external_value(PARAM_RAW, 'Allowed config values serialised as a JSON object'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------.

    /**
     * Authenticate the request and return allowlisted config values, silently skipping others.
     *
     * @param  array  $keys           Config key names to retrieve.
     * @param  string $hmacsignature HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmactimestamp Unix timestamp from the request header.
     * @param  string $payloadhash   SHA256 hex of the request body.
     * @param  bool   $skiphmac      Skip HMAC verification (used by chatproxy — auth via SPT).
     * @return array Single configs_json entry with the allowed values as a JSON object.
     */
    public static function execute(
        array $keys,
        string $hmacsignature,
        int $hmactimestamp,
        string $payloadhash,
        bool $skiphmac = false
    ): array {
        // ... --- Capability check ---
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // ... --- Parameter validation ---
        $validated = self::validate_parameters(
            self::execute_parameters(),
            [
                'keys'           => $keys,
                'hmac_signature' => $hmacsignature,
                'hmac_timestamp' => $hmactimestamp,
                'payload_hash'   => $payloadhash,
            ]
        );

        plugin_settings::require_enabled('local_jedomi');

        $keys           = $validated['keys'];
        $hmacsignature = $validated['hmac_signature'];
        $hmactimestamp = $validated['hmac_timestamp'];
        $payloadhash   = $validated['payload_hash'];

        // ... --- HMAC authentication ---
        if (!$skiphmac) {
            self::verify_hmac(
                'local_jedomi_get_site_config',
                $hmactimestamp,
                $payloadhash,
                $hmacsignature,
                self::signed_params($validated)
            );
        }

        // ... --- Build response (server-side allowlist enforcement) ---
        $configs = [];
        $allowset = array_flip(self::ALLOWLIST); // O(1) lookup.

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

        \local_jedomi_log(sprintf(
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
    // -------------------------------------------------------------------------.

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
            // Smtphosts can be a semicolon-separated list.
            $hosts = preg_split('/[;,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
            $masked = array_map(function (string $host): string {
                // Remove credentials: user:pass@host:port → host.
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
