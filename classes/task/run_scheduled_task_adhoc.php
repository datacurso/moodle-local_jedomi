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

use aiprovider_datacurso\local\plugin_settings;
use core\task\adhoc_task;
use core\task\manager as task_manager;

/**
 * Adhoc task that re-fires another scheduled or adhoc task named in its custom data.
 */
class run_scheduled_task_adhoc extends adhoc_task {
    /**
     * Resolve the task class from custom data and execute it, logging progress via mtrace.
     */
    public function execute(): void {
        if (!plugin_settings::is_enabled('local_jedomi')) {
            mtrace(get_string('task_skipped_plugin_disabled', 'local_jedomi'));
            return;
        }

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
