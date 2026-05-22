<?php
/**
 * Adhoc task wrapper: re-fires a scheduled task asynchronously.
 *
 * Used by jedomi_run_task when sync=false (default).
 * The task classname is passed via custom data.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\task;

defined('MOODLE_INTERNAL') || die();

use core\task\adhoc_task;
use core\task\manager as task_manager;

class run_scheduled_task_adhoc extends adhoc_task {

    public function execute(): void {
        $data      = $this->get_custom_data();
        $classname = $data->classname ?? '';

        if (empty($classname)) {
            mtrace('[local_jedomi] run_scheduled_task_adhoc: no classname in custom data');
            return;
        }

        $task = task_manager::get_scheduled_task($classname);

        if (!$task) {
            // Try instantiating as adhoc.
            if (class_exists($classname)) {
                $task = new $classname();
            }
        }

        if (!$task) {
            mtrace(sprintf('[local_jedomi] run_scheduled_task_adhoc: class not found: %s', $classname));
            return;
        }

        mtrace(sprintf('[local_jedomi] run_scheduled_task_adhoc: executing %s', $classname));
        $task->execute();
        mtrace(sprintf('[local_jedomi] run_scheduled_task_adhoc: completed %s', $classname));
    }
}
