<?php
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

use context_system;
use core\task\manager as task_manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_jedomi\companion_hmac;

class run_task extends external_api {

    use companion_hmac;

    // -------------------------------------------------------------------------
    // Parameter / return definitions
    // -------------------------------------------------------------------------

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'task_class'      => new external_value(PARAM_TEXT, 'Fully-qualified PHP class name of the scheduled task'),
            'sync'            => new external_value(PARAM_BOOL, 'If true, run synchronously (max 30s)', VALUE_DEFAULT, false),
            'hmac_signature'  => new external_value(PARAM_ALPHANUMEXT, 'HMAC-SHA256 hex signature'),
            'hmac_timestamp'  => new external_value(PARAM_INT,         'Unix timestamp from X-Jedomi-Timestamp'),
            'payload_hash'    => new external_value(PARAM_ALPHANUMEXT, 'SHA256 hex of the request body'),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'task_class'   => new external_value(PARAM_TEXT, 'The task class that was triggered'),
            'status'       => new external_value(PARAM_TEXT, '"queued" or "success" or "error"'),
            'run_id'       => new external_value(PARAM_TEXT, 'UUID for async tracking (empty string in sync mode)', VALUE_OPTIONAL),
            'output'       => new external_value(PARAM_RAW,  'Captured output (sync mode only)',                    VALUE_OPTIONAL),
            'execution_ms' => new external_value(PARAM_INT,  'Execution time in milliseconds'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Execution
    // -------------------------------------------------------------------------

    public static function execute(
        string $task_class,
        bool   $sync,
        string $hmac_signature,
        int    $hmac_timestamp,
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
        self::verify_payload('jedomi_run_task', $payload_hash, ['task_class' => $task_class, 'sync' => $sync]);
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
                    'output'       => json_encode(['error' => get_string('task_not_found_msg', 'local_jedomi'), 'task_class' => $task_class]),
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

    private static function run_async(string $task_class, string $normalized_class, float $start): array {
        // Queue as an adhoc task wrapping the scheduled task logic.
        // We use a simple adhoc task that re-fires the scheduled task.
        $adhoc = new \local_jedomi\task\run_scheduled_task_adhoc();
        $adhoc->set_custom_data(['classname' => $normalized_class]);
        task_manager::queue_adhoc_task($adhoc, true);

        $run_id = \core\uuid::generate();
        $execution_ms = (int) ((microtime(true) - $start) * 1000);

        error_log(sprintf(
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

        error_log(sprintf(
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
