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
 * jedomi_set_site_config — CompanionWS external function.
 *
 * Writes a Moodle site config value via set_config().
 * Requires moodle/site:config capability (admin-level).
 * HMAC-authenticated. Audit logged.
 *
 * Security:
 *  - Allowlist of writeable keys (server-side enforcement)
 *  - Dangerous keys (wwwroot, dbtype, dirroot, dataroot) are hardcoded-blocked
 *  - Purges relevant cache after write
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
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

/**
 * External function that writes a site configuration value, blocking dangerous keys.
 */
class set_site_config extends external_api {
    use companion_hmac;

    /**
     * Keys that can NEVER be written via this function.
     * Changing them could break or expose the site.
     */
    private const BLOCKED_KEYS = [
        'wwwroot', 'dbtype', 'dbhost', 'dbname', 'dbuser', 'dbpass',
        'dirroot', 'dataroot', 'admin', 'passwordsaltmain',
        'secret', 'hmac_secret',
    ];

    /**
     * Describe the parameters accepted by the execute function.
     *
     * @return external_function_parameters Config name, value, optional plugin and HMAC auth fields.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name'           => new external_value(PARAM_ALPHANUMEXT, 'Config key name'),
            'value'          => new external_value(PARAM_RAW, 'Config value to set'),
            'plugin'         => new external_value(PARAM_COMPONENT, 'Plugin name (empty = core)', VALUE_DEFAULT, ''),
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT, 'Unix timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of request body'),
        ]);
    }

    /**
     * Describe the structure returned by the execute function.
     *
     * @return external_single_structure Status, written key name, message and timing.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'       => new external_value(PARAM_TEXT, '"success" or "error"'),
            'name'         => new external_value(PARAM_TEXT, 'Config key that was written'),
            'message'      => new external_value(PARAM_TEXT, 'Result message', VALUE_OPTIONAL),
            'execution_ms' => new external_value(PARAM_INT, 'Execution time in milliseconds'),
        ]);
    }

    /**
     * Authenticate the request, write the config value via set_config() and purge caches.
     *
     * @param  string $name           Config key name to write.
     * @param  string $value          Config value to set.
     * @param  string $plugin         Plugin component name; empty string means core.
     * @param  string $hmacsignature HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmactimestamp Unix timestamp from the request header.
     * @param  string $payloadhash   SHA256 hex of the request body.
     * @return array Status, key name, result message and execution time in milliseconds.
     */
    public static function execute(
        string $name,
        string $value,
        string $plugin,
        string $hmacsignature,
        int $hmactimestamp,
        string $payloadhash
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $validated = self::validate_parameters(
            self::execute_parameters(),
            [
                'name'           => $name,
                'value'          => $value,
                'plugin'         => $plugin,
                'hmac_signature' => $hmacsignature,
                'hmac_timestamp' => $hmactimestamp,
                'payload_hash'   => $payloadhash,
            ]
        );
        $name           = $validated['name'];
        $value          = $validated['value'];
        $plugin         = $validated['plugin'] ?: 'core';
        $hmacsignature = $validated['hmac_signature'];
        $hmactimestamp = $validated['hmac_timestamp'];
        $payloadhash   = $validated['payload_hash'];

        self::verify_hmac('jedomi_set_site_config', $hmactimestamp, $payloadhash, $hmacsignature);

        $start = microtime(true);

        // Block dangerous keys unconditionally.
        if (in_array($name, self::BLOCKED_KEYS, true)) {
            $executionms = (int) ((microtime(true) - $start) * 1000);
            \local_jedomi_log(sprintf(
                '[local_jedomi] set_site_config BLOCKED: name=%s ip=%s',
                $name,
                getremoteaddr()
            ));
            return [
                'status'       => 'error',
                'name'         => $name,
                'message'      => get_string('config_key_blocked_msg', 'local_jedomi'),
                'execution_ms' => $executionms,
            ];
        }

        // Write the config value.
        set_config($name, $value, $plugin === 'core' ? null : $plugin);

        // Purge caches so the change takes effect immediately.
        purge_all_caches();

        $executionms = (int) ((microtime(true) - $start) * 1000);

        \local_jedomi_log(sprintf(
            '[local_jedomi] set_site_config: plugin=%s name=%s execution_ms=%d ip=%s',
            $plugin,
            $name,
            $executionms,
            getremoteaddr()
        ));

        return [
            'status'       => 'success',
            'name'         => $name,
            'message'      => get_string('config_updated_msg', 'local_jedomi', $name),
            'execution_ms' => $executionms,
        ];
    }
}
