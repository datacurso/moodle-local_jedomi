<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_jedomi\task;

use local_jedomi\event\observers;

/**
 * Ad hoc task that sends the grade_posted WhatsApp template of a graded assignment, out of the grading request.
 *
 * It carries only ids (student, course, course module and assign_grades record): names and grade are read when it
 * runs, and the recipient (channel enabled, link, consent, opt-out, role), the visibility of the grade, the grade
 * already notified and the minimum interval are checked again then (see observers::send_whatsapp_grade()). A
 * notification that may no longer be sent is skipped, not retried.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_whatsapp_grade_notification extends \core\task\adhoc_task {
    /**
     * A task for a graded assignment of a student.
     *
     * @param int $userid Student.
     * @param int $courseid Course of the assignment.
     * @param int $cmid Course module of the assignment.
     * @param int $gradeid Id of the assign_grades record.
     * @return self
     */
    public static function for_grade(int $userid, int $courseid, int $cmid, int $gradeid): self {
        $task = new self();
        $task->set_component('local_jedomi');
        $task->set_custom_data(['userid' => $userid, 'courseid' => $courseid, 'cmid' => $cmid, 'gradeid' => $gradeid]);
        return $task;
    }

    /**
     * Send the notification when it may still be sent.
     */
    public function execute(): void {
        $data = (array) $this->get_custom_data();
        observers::send_whatsapp_grade(
            (int) ($data['userid'] ?? 0),
            (int) ($data['courseid'] ?? 0),
            (int) ($data['cmid'] ?? 0),
            (int) ($data['gradeid'] ?? 0)
        );
    }
}
