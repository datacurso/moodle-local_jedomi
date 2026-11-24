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
 * Scheduled task: purge old query profiler records.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\task;

/**
 * Scheduled task that removes the query profiler records older than the retention setting.
 */
class purge_query_profiler extends \core\task\scheduled_task {
    /**
     * Return the localised name of this scheduled task.
     *
     * @return string Task name from the language pack.
     */
    public function get_name(): string {
        return get_string('task_purge_query_profiler', 'local_jedomi');
    }

    /**
     * Delete the profiler records older than the retention setting (30 days by default).
     *
     * @return void
     */
    public function execute(): void {
        $days = \local_jedomi\agent\query_profiler::retention_days();
        $count = \local_jedomi\agent\query_profiler::purge($days);
        mtrace("Deleted {$count} query profiler record(s) older than {$days} day(s).");
    }
}
