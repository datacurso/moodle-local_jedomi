<?php
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

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

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

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name'           => new external_value(PARAM_ALPHANUMEXT, 'Config key name'),
            'value'          => new external_value(PARAM_RAW,         'Config value to set'),
            'plugin'         => new external_value(PARAM_COMPONENT,   'Plugin name (empty = core)', VALUE_DEFAULT, ''),
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT,         'Unix timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of request body'),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'       => new external_value(PARAM_TEXT, '"success" or "error"'),
            'name'         => new external_value(PARAM_TEXT, 'Config key that was written'),
            'message'      => new external_value(PARAM_TEXT, 'Result message', VALUE_OPTIONAL),
            'execution_ms' => new external_value(PARAM_INT,  'Execution time in milliseconds'),
        ]);
    }

    public static function execute(
        string $name,
        string $value,
        string $plugin,
        string $hmac_signature,
        int    $hmac_timestamp,
        string $payload_hash
    ): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $validated = self::validate_parameters(
            self::execute_parameters(),
            compact('name', 'value', 'plugin', 'hmac_signature', 'hmac_timestamp', 'payload_hash')
        );
        $name           = $validated['name'];
        $value          = $validated['value'];
        $plugin         = $validated['plugin'] ?: 'core';
        $hmac_signature = $validated['hmac_signature'];
        $hmac_timestamp = $validated['hmac_timestamp'];
        $payload_hash   = $validated['payload_hash'];

        self::verify_payload('jedomi_set_site_config', $payload_hash, ['name' => $name, 'value' => $value, 'plugin' => $plugin]);

        self::verify_hmac('jedomi_set_site_config', $hmac_timestamp, $payload_hash, $hmac_signature);

        $start = microtime(true);

        // Block dangerous keys unconditionally.
        if (in_array($name, self::BLOCKED_KEYS, true)) {
            $execution_ms = (int) ((microtime(true) - $start) * 1000);
            error_log(sprintf(
                '[local_jedomi] set_site_config BLOCKED: name=%s ip=%s',
                $name, getremoteaddr()
            ));
            return [
                'status'       => 'error',
                'name'         => $name,
                'message'      => get_string('config_key_blocked_msg', 'local_jedomi'),
                'execution_ms' => $execution_ms,
            ];
        }

        // Write the config value.
        set_config($name, $value, $plugin === 'core' ? null : $plugin);

        // Purge caches so the change takes effect immediately.
        purge_all_caches();

        $execution_ms = (int) ((microtime(true) - $start) * 1000);

        error_log(sprintf(
            '[local_jedomi] set_site_config: plugin=%s name=%s execution_ms=%d ip=%s',
            $plugin, $name, $execution_ms, getremoteaddr()
        ));

        return [
            'status'       => 'success',
            'name'         => $name,
            'message'      => get_string('config_updated_msg', 'local_jedomi', $name),
            'execution_ms' => $execution_ms,
        ];
    }
}
