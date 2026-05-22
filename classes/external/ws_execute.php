<?php
/**
 * jedomi_ws_execute — CompanionWS external function.
 *
 * Executes a Moodle web service function on behalf of a user, with
 * double-gate security: (1) role-based allowlist from admin settings,
 * (2) native Moodle capabilities in the target WS class.
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

class ws_execute extends external_api {

    use companion_hmac;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'wsname'         => new external_value(PARAM_ALPHANUMEXT, 'Web service function name'),
            'params'         => new external_value(PARAM_RAW, 'JSON-encoded parameters for the WS'),
            'hmac_signature' => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp' => new external_value(PARAM_INT, 'Unix timestamp'),
            'payload_hash'   => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of request body'),
        ]);
    }

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
     * @param  string $hmac_signature HMAC hex signature.
     * @param  int    $hmac_timestamp Unix timestamp.
     * @param  string $payload_hash   SHA256 hex of the request body.
     * @param  bool   $skip_hmac      Skip HMAC (used by chatproxy — auth via SPT).
     * @return array                  ['result' => json_encode($target_result)]
     */
    public static function execute(
        string $wsname,
        string $params,
        string $hmac_signature,
        int    $hmac_timestamp,
        string $payload_hash,
        bool   $skip_hmac = false
    ): array {
        global $DB;

        // ── Gate 1: validate context + capability ────────────────────────────
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/jedomi:companion_api', $context);

        // ── Parameter validation ─────────────────────────────────────────────
        self::validate_parameters(
            self::execute_parameters(),
            compact('wsname', 'params', 'hmac_signature', 'hmac_timestamp', 'payload_hash')
        );

        // ── Gate 2: HMAC authentication ──────────────────────────────────────
        if (!$skip_hmac) {
            self::verify_hmac('jedomi_ws_execute', $hmac_timestamp, $payload_hash, $hmac_signature);
        }

        // ── Gate 3: allowlist validation ─────────────────────────────────────
        require_once(__DIR__ . '/../../lib.php');
        $role = \local_jedomi_get_user_role();
        $allowlist = \local_jedomi_get_role_allowlist($role);
        // Admin sin allowlist configurada explícita → acceso total.
        // El resto de roles necesitan allowlist o se bloquean.
        if ($role !== 'admin' || !empty($allowlist)) {
            if (!in_array($wsname, $allowlist, true)) {
                throw new \moodle_exception('ws_not_in_allowlist', 'local_jedomi');
            }
        }

        // ── Resolve target class + method ──────────────────────────────────────
        $func_record = $DB->get_record('external_functions', ['name' => $wsname], 'classname, classpath, component, methodname');
        if (!$func_record) {
            throw new \moodle_exception('ws_not_found', 'local_jedomi');
        }
        $classname = \local_jedomi_resolve_ws_classname($wsname);
        // class_exists check uses the resolved classname (with require_once fallback).
        if (!$classname || !class_exists($classname)) {
            throw new \moodle_exception('ws_not_found', 'local_jedomi');
        }
        $methodname = $func_record->methodname;
        if (!method_exists($classname, $methodname)) {
            throw new \moodle_exception('ws_execute_error', 'local_jedomi', '', "Method {$classname}::{$methodname}() does not exist");
        }

        // ── Decode params ────────────────────────────────────────────────────
        $decoded = json_decode($params, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        // ── Map params by name via reflection ────────────────────────────────
        try {
            $ref = new \ReflectionMethod($classname, $methodname);
            $callargs = [];
            foreach ($ref->getParameters() as $param) {
                $name = $param->getName();
                if (array_key_exists($name, $decoded)) {
                    $callargs[] = $decoded[$name];
                } elseif ($param->isDefaultValueAvailable()) {
                    $callargs[] = $param->getDefaultValue();
                } else {
                    throw new \moodle_exception('missingparam', 'error', '', $name);
                }
            }

            // ── Gate 4: call target WS (native capabilities inside) ──────────
            $result = $ref->invokeArgs(null, $callargs);
        } catch (\moodle_exception $e) {
            throw $e;
        } catch (\Exception $e) {
            // Re-throw with original message preserved for debugging.
            throw new \moodle_exception('ws_execute_error', 'local_jedomi', '', $e->getMessage() . ' [' . get_class($e) . ']');
        }

        return ['result' => json_encode($result)];
    }
}
