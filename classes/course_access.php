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

/**
 * Course access control for the Jedomi sidebar.
 *
 * Determines whether the sidebar should be visible on a given course
 * based on the admin-configured mode (all / include / exclude) and list.
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

defined('MOODLE_INTERNAL') || die();

/**
 * Static helper — zero DB queries on the hot path (all from config cache).
 */
class course_access {

    /**
     * Returns true when the sidebar should be shown on the given course.
     *
     * Modes:
     *   all     → always visible
     *   include → visible ONLY in the listed courses
     *   exclude → visible everywhere EXCEPT the listed courses
     *
     * @param int $courseid  Current course ID (0 or 1 = site course).
     * @return bool
     */
    public static function is_enabled(int $courseid): bool {

        $mode = get_config('local_jedomi', 'course_mode') ?: 'all';

        if ($mode === 'all') {
            return true;
        }

        $list = get_config('local_jedomi', 'course_list') ?: '';
        $ids  = array_filter(array_map('intval', explode(',', $list)));

        if ($mode === 'include') {
            // Site course (id <= 1) always passes in include mode.
            return $courseid <= 1 || in_array($courseid, $ids, true);
        }

        if ($mode === 'exclude') {
            return !in_array($courseid, $ids, true);
        }

        return true;
    }
}
