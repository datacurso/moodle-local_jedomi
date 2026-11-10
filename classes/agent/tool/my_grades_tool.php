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

namespace local_jedomi\agent\tool;

use local_jedomi\local\service\student_data_service;

/**
 * Read-only tool that gives a user their own grades.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class my_grades_tool extends own_data_tool {
    #[\Override]
    public function get_name(): string {
        return 'my_grades';
    }

    #[\Override]
    protected function description_key(): string {
        return 'tool_my_grades_desc';
    }

    #[\Override]
    protected function read(int $userid, int $courseid): array {
        return student_data_service::my_grades($userid, $courseid);
    }
}
