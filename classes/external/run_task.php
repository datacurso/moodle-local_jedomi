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
 * jedomi_run_task — CompanionWS external function.
 *
 * Requirement CWS-TASK-01:
 *  - Validates HMAC before any execution
 *  - Verifies the task class exists in mdl_task_scheduled
 *  - Default: async (queues as adhoc task, returns immediately with run_id)
 *  - Optional sync=true with hard 30-second timeout
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

use context_system;
use core\task\manager as task_manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

/**
 * External function that triggers a scheduled or adhoc task, asynchronously by default or synchronously on demand.
 */
class run_task extends external_api {
    use companion_hmac;

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------

    /**
     * Describe the parameters accepted by the execute function.
     *
     * @return external_function_parameters Task class name, sync flag and HMAC auth fields.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'task_class'      => new external_value(PARAM_TEXT, 'Fully-qualified PHP class name of the scheduled task'),
            'sync'            => new external_value(PARAM_BOOL, 'If true, run synchronously (max 30s)', VALUE_DEFAULT, false),
            'hmac_signature'  => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp'  => new external_value(PARAM_INT, 'Unix timestamp from X-Jedomi-Timestamp'),
            'payload_hash'    => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of the request body'),
        ]);
    }

    /**
     * Describe the structure returned by the execute function.
     *
     * @return external_single_structure Task class, status, run id, captured output and timing.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'task_class'   => new external_value(PARAM_TEXT, 'The task class that was triggered'),
            'status'       => new external_value(PARAM_TEXT, '"queued" or "success" or "error"'),
            'run_id'       => new external_value(PARAM_TEXT, 'UUID for async tracking (empty string in sync mode)', VALUE_OPTIONAL),
            'output'       => new external_value(PARAM_RAW, 'Captured output (sync mode only)', VALUE_OPTIONAL),
            'execution_ms' => new external_value(PARAM_INT, 'Execution time in milliseconds'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    /**
     * Authenticate the request, validate the task class and run it async (queued) or sync.
     *
     * @param  string $task_class     Fully-qualified PHP class name of the task.
     * @param  bool   $sync           If true, run synchronously with a 30-second limit.
     * @param  string $hmac_signature HMAC-SHA256 hex signature from the gateway.
     * @param  int    $hmac_timestamp Unix timestamp from the request header.
     * @param  string $payload_hash   SHA256 hex of the request body.
     * @return array Task class, status, run id, optional output and execution time.
     */
    public static function execute(
        string $task_class,
        bool $sync,
        string $hmac_signature,
        int $hmac_timestamp,
        string $payload_hash
    ): array {
        global $DB;

        // --- Capability check ---
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // --- Parameter validation ---
        $validated = self::validate_parameters(
            self::execute_parameters(),
            compact('task_class', 'sync', 'hmac_signature', 'hmac_timestamp', 'payload_hash')
        );
        $task_class     = $validated['task_class'];
        $sync           = $validated['sync'];
        $hmac_signature = $validated['hmac_signature'];
        $hmac_timestamp = $validated['hmac_timestamp'];
        $payload_hash   = $validated['payload_hash'];

        // --- HMAC authentication ---
        self::verify_hmac('jedomi_run_task', $hmac_timestamp, $payload_hash, $hmac_signature);

        $start = microtime(true);

        // --- Validate the task class exists in mdl_task_scheduled ---
        // Normalise: ensure leading backslash for comparison.
        $normalized_class = '\\' . ltrim($task_class, '\\');

        $exists = $DB->record_exists_select(
            'task_scheduled',
            $DB->sql_compare_text('classname') . ' = ' . $DB->sql_compare_text(':cn'),
            ['cn' => $normalized_class]
        );

        if (!$exists) {
            // Also accept if the class itself is a loadable adhoc task class.
            if (!class_exists($normalized_class) || !is_subclass_of($normalized_class, \core\task\adhoc_task::class)) {
                $execution_ms = (int) ((microtime(true) - $start) * 1000);
                return [
                    'task_class'   => $task_class,
                    'status'       => 'error',
                    'run_id'       => '',
                    'output'       => json_encode([
                        'error' => get_string('task_not_found_msg', 'local_jedomi'),
                        'task_class' => $task_class,
                    ]),
                    'execution_ms' => $execution_ms,
                ];
            }
        }

        if ($sync) {
            return self::run_sync($task_class, $normalized_class, $start);
        }

        return self::run_async($task_class, $normalized_class, $start);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Queue the task as an adhoc wrapper and return immediately with a tracking run id.
     *
     * @param  string $task_class       Task class name as received from the caller.
     * @param  string $normalized_class Class name normalised with a leading backslash.
     * @param  float  $start            Request start time from microtime(true), for timing.
     * @return array Task class, "queued" status, generated run id and execution time.
     */
    private static function run_async(string $task_class, string $normalized_class, float $start): array {
        // Queue as an adhoc task wrapping the scheduled task logic.
        // We use a simple adhoc task that re-fires the scheduled task.
        $adhoc = new \local_jedomi\task\run_scheduled_task_adhoc();
        $adhoc->set_custom_data(['classname' => $normalized_class]);
        task_manager::queue_adhoc_task($adhoc, true);

        $run_id = \core\uuid::generate();
        $execution_ms = (int) ((microtime(true) - $start) * 1000);

        \local_jedomi_log(sprintf(
            '[local_jedomi] run_task queued: class=%s run_id=%s execution_ms=%d',
            $normalized_class,
            $run_id,
            $execution_ms
        ));

        return [
            'task_class'   => $task_class,
            'status'       => 'queued',
            'run_id'       => $run_id,
            'execution_ms' => $execution_ms,
        ];
    }

    /**
     * Run the task in-process with a 30-second time limit, capturing its output.
     *
     * @param  string $task_class       Task class name as received from the caller.
     * @param  string $normalized_class Class name normalised with a leading backslash.
     * @param  float  $start            Request start time from microtime(true), for timing.
     * @return array Task class, success/error status, captured output and execution time.
     */
    private static function run_sync(string $task_class, string $normalized_class, float $start): array {
        // Resolve instance — scheduled or adhoc.
        $task = task_manager::get_scheduled_task($normalized_class);
        if (!$task) {
            // Try instantiating as adhoc.
            if (class_exists($normalized_class)) {
                $task = new $normalized_class();
            }
        }

        if (!$task) {
            $execution_ms = (int) ((microtime(true) - $start) * 1000);
            return [
                'task_class'   => $task_class,
                'status'       => 'error',
                'output'       => json_encode(['error' => get_string('task_instantiation_failed_msg', 'local_jedomi')]),
                'execution_ms' => $execution_ms,
            ];
        }

        $output      = '';
        $status      = 'success';
        $time_limit  = 30;

        ob_start();
        $old_limit = ini_get('max_execution_time');
        @set_time_limit($time_limit);

        try {
            $task->execute();
        } catch (\Exception $e) {
            $status  = 'error';
            $output .= "\nException: " . $e->getMessage();
        } finally {
            @set_time_limit((int) $old_limit);
            $output .= ob_get_clean();
        }

        $execution_ms = (int) ((microtime(true) - $start) * 1000);

        \local_jedomi_log(sprintf(
            '[local_jedomi] run_task sync: class=%s status=%s execution_ms=%d',
            $normalized_class,
            $status,
            $execution_ms
        ));

        return [
            'task_class'   => $task_class,
            'status'       => $status,
            'run_id'       => '',
            'output'       => $output,
            'execution_ms' => $execution_ms,
        ];
    }
}
