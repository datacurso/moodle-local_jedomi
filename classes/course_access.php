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
 * Course access control for Jedomi.
 *
 * Determines whether a course is in Jedomi based on the admin-configured mode (all / include / exclude) and list.
 * An excluded course is outside Jedomi, not only without the panel: it gives no role outside a course and
 * no course-level tool, signed course list, teacher SQL or agent may use it.
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

/**
 * Static helper — zero DB queries on the hot path (all from config cache).
 */
class course_access {
    /** @var array{0: string, 1: array<int, true>}|null The saved course list and its ids as keys, parsed once per value. */
    private static ?array $listed = null;

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

        $ids = self::listed_ids();

        if ($mode === 'include') {
            // Site course (id <= 1) always passes in include mode.
            return $courseid <= 1 || isset($ids[$courseid]);
        }

        if ($mode === 'exclude') {
            return !isset($ids[$courseid]);
        }

        return true;
    }

    /**
     * The ids of the saved course list, as keys. The list is parsed once per request and again only when its saved value
     * changes (REL-004), so a change made during the request is seen at once.
     *
     * @return array<int, true>
     */
    private static function listed_ids(): array {
        $list = (string) (get_config('local_jedomi', 'course_list') ?: '');
        if (self::$listed === null || self::$listed[0] !== $list) {
            self::$listed = [$list, array_fill_keys(array_filter(array_map('intval', explode(',', $list))), true)];
        }
        return self::$listed[1];
    }

    /**
     * Whether a course is in Jedomi: the single check used by every channel (web chat, Telegram, agents and the
     * companion web service) to leave excluded courses out.
     *
     * In "exclude" mode the listed courses are out; in "include" mode (only the listed courses) every course that is
     * not listed is out. The site (0 or the site course) is always in Jedomi.
     *
     * @param int $courseid Course id; 0 or the site course outside a course.
     * @return bool
     */
    public static function is_course_in_jedomi(int $courseid): bool {
        return $courseid <= SITEID || self::is_enabled($courseid);
    }

    /**
     * Whether some course may be outside Jedomi: false in "all" mode, where no course check is needed.
     *
     * @return bool
     */
    public static function is_restricted(): bool {
        return (get_config('local_jedomi', 'course_mode') ?: 'all') !== 'all';
    }

    /**
     * The course ids of a list that are in Jedomi, in their order.
     *
     * @param int[] $courseids Course ids.
     * @return int[]
     */
    public static function filter_course_ids(array $courseids): array {
        return array_values(array_filter(
            array_map('intval', $courseids),
            static fn(int $courseid): bool => self::is_course_in_jedomi($courseid)
        ));
    }

    /**
     * The course a Moodle context belongs to (the course itself, or an activity or block of it), or 0 when the context
     * belongs to no course or does not exist.
     *
     * @param int $contextid Moodle context id.
     * @return int
     */
    public static function context_course_id(int $contextid): int {
        $context = $contextid > 0 ? \context::instance_by_id($contextid, IGNORE_MISSING) : false;
        $coursecontext = $context ? $context->get_course_context(false) : false;
        return $coursecontext ? (int) $coursecontext->instanceid : 0;
    }
}
