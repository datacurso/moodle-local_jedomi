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

namespace local_jedomi\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Runs a Moodle web service function for the AI, shared by the web chat, Telegram and the companion web service.
 *
 * The role gate (the web service list saved for the role) is the same in every channel; the target function still
 * checks the real Moodle capabilities of the user.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ws_runner {
    /**
     * Tell whether a chat role may run a web service function: the administrator without a saved list runs every one,
     * any other role (or an administrator with a saved list, even empty) only the functions of its list.
     *
     * @param string $role Chat role (admin, manager, teacher or student).
     * @param string $wsname Web service function name.
     * @return bool
     */
    public static function role_allows(string $role, string $wsname): bool {
        return \local_jedomi_role_has_full_ws_access($role)
            || in_array($wsname, \local_jedomi_get_role_allowlist($role), true);
    }

    /**
     * Functions the web service search of a role may return, the same list that gates description and execution.
     *
     * @param string $role Jedomi role (admin, manager, teacher, student).
     * @return string[]|null Null when the role may call every function; otherwise its list (empty: none).
     */
    public static function searchable_functions(string $role): ?array {
        if (\local_jedomi_role_has_full_ws_access($role)) {
            return null;
        }
        return array_values(array_filter(\local_jedomi_get_role_allowlist($role), 'is_string'));
    }

    /**
     * Method that implements a web service function.
     *
     * @param string $wsname Web service function name.
     * @return \ReflectionMethod
     * @throws \moodle_exception ws_not_found or ws_execute_error.
     */
    public static function resolve(string $wsname): \ReflectionMethod {
        global $DB;
        $function = $DB->get_record('external_functions', ['name' => $wsname], 'classname, classpath, component, methodname');
        if (!$function) {
            throw new \moodle_exception('ws_not_found', 'local_jedomi');
        }
        $classname = \local_jedomi_resolve_ws_classname($wsname);
        if (!$classname || !class_exists($classname)) {
            throw new \moodle_exception('ws_not_found', 'local_jedomi');
        }
        if (!method_exists($classname, $function->methodname)) {
            throw new \moodle_exception(
                'ws_execute_error',
                'local_jedomi',
                '',
                "Method {$classname}::{$function->methodname}() does not exist"
            );
        }
        return new \ReflectionMethod($classname, $function->methodname);
    }

    /**
     * First required parameter of the method that the arguments do not provide, or null when none is missing.
     *
     * @param \ReflectionMethod $method Method of the function.
     * @param array $args Arguments by name.
     * @return string|null
     */
    public static function missing_parameter(\ReflectionMethod $method, array $args): ?string {
        foreach ($method->getParameters() as $parameter) {
            if (!array_key_exists($parameter->getName(), $args) && !$parameter->isDefaultValueAvailable()) {
                return $parameter->getName();
            }
        }
        return null;
    }

    /**
     * Validate the arguments of a web service function against its parameter description, as Moodle does for every
     * web service call: types are checked and cleaned, defaults applied and unknown keys rejected.
     *
     * Functions without a parameter description (not an external function) get their arguments unchanged.
     *
     * @param \ReflectionMethod $method Method of the function (from resolve()).
     * @param array $args Arguments by name.
     * @return array Validated arguments by name.
     * @throws \moodle_exception ws_invalid_parameters with the reason Moodle gives.
     */
    public static function validate(\ReflectionMethod $method, array $args): array {
        $classname = $method->getDeclaringClass()->getName();
        $describer = $method->getName() . '_parameters';
        if (!method_exists($classname, $describer)) {
            return $args;
        }
        $description = $classname::$describer();
        if (!$description instanceof \core_external\external_description) {
            return $args;
        }
        try {
            return \core_external\external_api::validate_parameters($description, $args);
        } catch (\invalid_parameter_exception $e) {
            $detail = !empty($e->debuginfo) ? (string) $e->debuginfo : $e->getMessage();
            throw new \moodle_exception('ws_invalid_parameters', 'local_jedomi', '', $detail, $detail);
        }
    }

    /**
     * Call the method of a web service function with the arguments matched by name, after validating them with the
     * parameter description of the function (the same validation in every channel).
     *
     * @param \ReflectionMethod $method Method of the function (from resolve()).
     * @param array $args Arguments by name.
     * @param bool $intransaction Run inside a delegated transaction committed only when the call succeeds.
     * @return mixed Result of the function.
     * @throws \moodle_exception missingparam, ws_invalid_parameters, ws_execute_error or the error of the function.
     */
    public static function call(\ReflectionMethod $method, array $args, bool $intransaction = false) {
        global $DB;
        $missing = self::missing_parameter($method, $args);
        if ($missing !== null) {
            throw new \moodle_exception('missingparam', 'error', '', $missing);
        }
        $args = self::validate($method, $args);
        $callargs = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $callargs[] = array_key_exists($name, $args) ? $args[$name] : $parameter->getDefaultValue();
        }
        $transaction = $intransaction ? $DB->start_delegated_transaction() : null;
        try {
            $result = $method->invokeArgs(null, $callargs);
            if ($transaction) {
                $transaction->allow_commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($transaction) {
                try {
                    $transaction->rollback($e);
                    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- rollback() throws $e again.
                } catch (\Throwable $rethrown) {
                    // The error is reported below.
                }
            }
            if ($e instanceof \invalid_parameter_exception) {
                $detail = $e->getMessage();
                if (!empty($e->debuginfo)) {
                    $detail .= ' | debug: ' . $e->debuginfo;
                }
                throw new \moodle_exception('ws_execute_error', 'local_jedomi', '', $detail);
            }
            if ($e instanceof \moodle_exception) {
                throw $e;
            }
            throw new \moodle_exception('ws_execute_error', 'local_jedomi', '', $e->getMessage() . ' [' . get_class($e) . ']');
        }
    }
}
