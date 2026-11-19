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

use aiprovider_datacurso\local\plugin_settings;
defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

/**
 * External function that writes a site configuration value, blocking dangerous keys and logging each change in the
 * config log of Moodle.
 */
class set_site_config extends external_api {
    use companion_hmac;

    /**
     * Keys that can NEVER be written via this function.
     * Changing them could break or expose the site. The last ones decide privileges: who is a site administrator or the
     * guest user, the roles every user, visitor, guest or course creator gets, where and how users log in (enabled
     * authentication plugins: "none" would allow logins without a password). The AI never grants site administrator
     * status; it gives the steps of the Site administrators page instead. Lower case: names are compared in lower case.
     */
    private const BLOCKED_KEYS = [
        'wwwroot', 'dbtype', 'dbhost', 'dbname', 'dbuser', 'dbpass',
        'dirroot', 'dataroot', 'admin', 'passwordsaltmain',
        'secret', 'hmac_secret',
        'siteadmins', 'siteguest', 'defaultuserroleid', 'notloggedinroleid', 'guestroleid', 'creatornewroleid',
        'alternateloginurl', 'auth', 'registerauth',
    ];

    /**
     * Secret settings that can never be written via this function, by component: the value would be stored unencrypted
     * and shown in the config log. Settings shown as passwords on the admin pages are refused too (is_secret_setting()).
     */
    private const SECRET_KEYS = [
        'core' => ['smtppass', 'proxypassword', 'cronremotepassword', 'recaptchaprivatekey'],
        'local_jedomi' => ['custom_api_key', 'telegram_bot_token', 'telegram_webhook_secret', 'spt_secret', 'hmac_secret',
            'log_key'],
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

        plugin_settings::require_enabled('local_jedomi');

        $name           = $validated['name'];
        $value          = $validated['value'];
        $plugin         = $validated['plugin'] ?: 'core';
        $hmacsignature = $validated['hmac_signature'];
        $hmactimestamp = $validated['hmac_timestamp'];
        $payloadhash   = $validated['payload_hash'];

        self::verify_hmac(
            'jedomi_set_site_config',
            $hmactimestamp,
            $payloadhash,
            $hmacsignature,
            self::signed_params($validated)
        );

        $start = microtime(true);

        // Block dangerous keys unconditionally. The config table is case-insensitive on MySQL and MariaDB, so "SiteAdmins"
        // would write the real "siteadmins" row: the checks use the lower-case name.
        $key = \core_text::strtolower($name);
        if (in_array($key, self::BLOCKED_KEYS, true)) {
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

        // Refuse secrets: written here they would be stored unencrypted and shown in the config log.
        if (self::is_secret_setting(\core_text::strtolower($plugin), $key)) {
            $executionms = (int) ((microtime(true) - $start) * 1000);
            \local_jedomi_log(sprintf(
                '[local_jedomi] set_site_config SECRET BLOCKED: plugin=%s name=%s ip=%s',
                $plugin,
                $name,
                getremoteaddr()
            ));
            return [
                'status'       => 'error',
                'name'         => $name,
                'message'      => get_string('config_key_secret_msg', 'local_jedomi'),
                'execution_ms' => $executionms,
            ];
        }

        // Write the config value. A change is recorded in the config log of Moodle (the "Config changes" report and the
        // config_log_created event), as when an administrator saves the setting (JEDOMI-SEC-011).
        $component = $plugin === 'core' ? null : $plugin;
        $previous = get_config($component ?? 'core', $name);
        set_config($name, $value, $component, $previous === false || (string) $previous !== $value);

        // Purge caches so the change takes effect immediately.
        purge_all_caches();

        $executionms = (int) ((microtime(true) - $start) * 1000);

        // A successful change is not a security failure: its source address is not kept.
        \local_jedomi_log(sprintf(
            '[local_jedomi] set_site_config: plugin=%s name=%s execution_ms=%d',
            $plugin,
            $name,
            $executionms
        ));

        return [
            'status'       => 'success',
            'name'         => $name,
            'message'      => get_string('config_updated_msg', 'local_jedomi', $name),
            'execution_ms' => $executionms,
        ];
    }

    /**
     * Whether a setting holds a secret: listed in SECRET_KEYS, or shown as a password on the admin pages.
     *
     * @param string $plugin Component, 'core' for the main settings.
     * @param string $name Setting name.
     * @return bool
     */
    private static function is_secret_setting(string $plugin, string $name): bool {
        global $CFG;
        if (in_array($name, self::SECRET_KEYS[$plugin] ?? [], true)) {
            return true;
        }
        require_once($CFG->libdir . '/adminlib.php');
        $fullname = 's_' . ($plugin === 'core' ? '' : $plugin) . '_' . $name;
        foreach (admin_find_write_settings(admin_get_root(), [$fullname => '']) as $setting) {
            if ($setting instanceof \admin_setting_configpasswordunmask || $setting instanceof \admin_setting_encryptedpassword) {
                return true;
            }
        }
        return false;
    }
}
