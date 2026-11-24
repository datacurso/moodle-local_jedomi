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

namespace local_jedomi\local\service;

/**
 * Read-only data of a user about themselves: their courses, the grades they can see and their assignments.
 *
 * Every method takes the user it reads for from the caller (the session user, the linked Telegram user or the
 * agent creator), never from the AI, and returns only what that user can see in the Moodle web interface.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_data_service {
    /** @var int Days ahead for the upcoming calendar events. */
    private const UPCOMING_DAYS = 60;

    /** @var int Most upcoming calendar events returned. */
    private const UPCOMING_LIMIT = 20;

    /**
     * Whether a user can read their own data (a real, active, non-guest account).
     *
     * @param int $userid User.
     * @return \stdClass|null The user record, or null when the user cannot.
     */
    private static function active_user(int $userid): ?\stdClass {
        if ($userid <= 0 || isguestuser($userid)) {
            return null;
        }
        $user = \core_user::get_user($userid);
        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return null;
        }
        return $user;
    }

    /**
     * Visible courses in Jedomi with an active enrolment of a user, keyed by id.
     *
     * @param int $userid User.
     * @return \stdClass[]
     */
    private static function enrolled_courses(int $userid): array {
        $courses = [];
        foreach (enrol_get_users_courses($userid, true, 'enddate, showgrades') as $course) {
            if ((int) $course->id === SITEID || !\local_jedomi\course_access::is_course_in_jedomi((int) $course->id)) {
                // The site home and the courses excluded from Jedomi are not courses of the user here.
                continue;
            }
            $context = \context_course::instance($course->id);
            if (empty($course->visible) && !has_capability('moodle/course:viewhiddencourses', $context, $userid)) {
                continue;
            }
            $courses[(int) $course->id] = $course;
        }
        return $courses;
    }

    /**
     * Own courses of a user, optionally only one of them.
     *
     * @param int $userid User.
     * @param int $courseid Only this course; 0 for all.
     * @return \stdClass[]
     * @throws \moodle_exception When the course is not one of the courses of the user.
     */
    private static function selected_courses(int $userid, int $courseid): array {
        $courses = self::enrolled_courses($userid);
        if ($courseid <= 0) {
            return $courses;
        }
        if (!isset($courses[$courseid])) {
            throw new \moodle_exception('error_not_own_course', 'local_jedomi');
        }
        return [$courseid => $courses[$courseid]];
    }

    /**
     * Format a timestamp in the timezone of the user, or null when not set.
     *
     * @param int $time Timestamp.
     * @param \stdClass $user User.
     * @return string|null
     */
    private static function date(int $time, \stdClass $user): ?string {
        if ($time <= 0) {
            return null;
        }
        return userdate($time, get_string('strftimedatetime', 'core_langconfig'), \core_date::get_user_timezone($user));
    }

    /**
     * Courses of a user with an active enrolment that the user can see.
     *
     * @param int $userid User the data is read for.
     * @return array List of courses (id, shortname, fullname, start and end dates).
     * @throws \moodle_exception When the user cannot read their data.
     */
    public static function my_courses(int $userid): array {
        $user = self::active_user($userid);
        if (!$user) {
            throw new \moodle_exception('nopermissions', 'error', '', 'my_courses');
        }
        $result = [];
        foreach (self::enrolled_courses($userid) as $course) {
            $context = \context_course::instance($course->id);
            $result[] = [
                'id' => (int) $course->id,
                'shortname' => format_string($course->shortname, true, ['context' => $context]),
                'fullname' => format_string($course->fullname, true, ['context' => $context]),
                'startdate' => self::date((int) $course->startdate, $user),
                'enddate' => self::date((int) $course->enddate, $user),
            ];
        }
        return $result;
    }

    /**
     * Grades of a user that the user can see in the gradebook.
     *
     * Hidden grade items and grades, grades not released by the marking workflow, grades of anonymous assignments
     * whose identities are not revealed and grades of activities the user cannot see are left out. Course and
     * category totals are only given when no grade of the course was left out, so they never reveal hidden grades.
     *
     * @param int $userid User the data is read for.
     * @param int $courseid Only this course (one of the courses of the user); 0 for all.
     * @return array List of courses, each with its visible grades.
     * @throws \moodle_exception When the user cannot read their data or the course is not theirs.
     */
    public static function my_grades(int $userid, int $courseid = 0): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $user = self::active_user($userid);
        if (!$user) {
            throw new \moodle_exception('nopermissions', 'error', '', 'my_grades');
        }
        $result = [];
        foreach (self::selected_courses($userid, $courseid) as $course) {
            $context = \context_course::instance($course->id);
            if (empty($course->showgrades) || !has_capability('moodle/grade:view', $context, $userid)) {
                continue;
            }
            $modinfo = get_fast_modinfo($course->id, $userid);
            $grades = [];
            $totals = [];
            $leftout = false;
            foreach (\grade_item::fetch_all(['courseid' => $course->id]) ?: [] as $item) {
                $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
                if (!$grade || $grade->finalgrade === null) {
                    continue;
                }
                if (!self::item_grade_is_visible($item, $grade, $modinfo, $userid)) {
                    $leftout = true;
                    continue;
                }
                $entry = [
                    'item' => $item->get_name(true),
                    'grade' => grade_format_gradevalue($grade->finalgrade, $item, true),
                    'max' => grade_format_gradevalue($item->grademax, $item, true),
                ];
                if ($item->is_course_item() || $item->is_category_item()) {
                    $totals[] = $entry;
                } else {
                    $grades[] = $entry;
                }
            }
            $result[] = [
                'course' => format_string($course->fullname, true, ['context' => $context]),
                'courseid' => (int) $course->id,
                'grades' => $leftout ? $grades : array_merge($grades, $totals),
            ];
        }
        return $result;
    }

    /**
     * Whether the user can see a grade of a grade item.
     *
     * @param \grade_item $item Grade item.
     * @param \grade_grade $grade Grade of the user.
     * @param \course_modinfo $modinfo Course information for the user.
     * @param int $userid User.
     * @return bool
     */
    private static function item_grade_is_visible(
        \grade_item $item,
        \grade_grade $grade,
        \course_modinfo $modinfo,
        int $userid
    ): bool {
        if ($item->is_hidden() || $grade->is_hidden()) {
            return false;
        }
        if ($item->itemtype !== 'mod') {
            return true;
        }
        $cm = $modinfo->instances[$item->itemmodule][$item->iteminstance] ?? null;
        if (!$cm || !$cm->uservisible) {
            return false;
        }
        if ($item->itemmodule === 'assign') {
            return self::assign_grade_is_visible((int) $item->iteminstance, (int) $item->courseid, $userid);
        }
        return true;
    }

    /**
     * Whether a student can see their grade of an assignment now.
     *
     * A grade is not visible while its grade item or the grade itself is hidden, while the marking workflow
     * has not released it, or while the identities of an anonymous assignment are not revealed.
     *
     * @param int $assignid Assignment instance id.
     * @param int $courseid Course of the assignment.
     * @param int $studentid Student.
     * @return bool
     */
    public static function assign_grade_is_visible(int $assignid, int $courseid, int $studentid): bool {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $assign = $DB->get_record('assign', ['id' => $assignid], 'id, markingworkflow, blindmarking, revealidentities');
        if (!$assign) {
            return false;
        }
        if (!empty($assign->blindmarking) && empty($assign->revealidentities)) {
            return false;
        }
        if (!empty($assign->markingworkflow)) {
            $state = $DB->get_field('assign_user_flags', 'workflowstate', ['assignment' => $assign->id, 'userid' => $studentid]);
            if ($state !== ASSIGN_MARKING_WORKFLOW_STATE_RELEASED) {
                return false;
            }
        }
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'courseid' => $courseid, 'itemnumber' => 0]);
        if ($gradeitem) {
            if ($gradeitem->is_hidden()) {
                return false;
            }
            $grade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $studentid]);
            if ($grade && $grade->is_hidden()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Assignments of the courses of a user, with their dates and the submission status, and the upcoming
     * calendar events with an action for the user.
     *
     * @param int $userid User the data is read for.
     * @param int $courseid Only this course (one of the courses of the user); 0 for all.
     * @return array 'assignments' and 'upcoming_events'.
     * @throws \moodle_exception When the user cannot read their data or the course is not theirs.
     */
    public static function my_assignments(int $userid, int $courseid = 0): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $user = self::active_user($userid);
        if (!$user) {
            throw new \moodle_exception('nopermissions', 'error', '', 'my_assignments');
        }
        $courses = self::selected_courses($userid, $courseid);
        $assignments = [];
        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course->id, $userid);
            foreach ($modinfo->get_instances_of('assign') as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                $instance = $DB->get_record(
                    'assign',
                    ['id' => $cm->instance],
                    'id, duedate, cutoffdate, allowsubmissionsfromdate, teamsubmission'
                );
                if (!$instance) {
                    continue;
                }
                // The dates the user sees: get_instance() only applies the relative dates of the course, so the user and
                // group overrides are applied with update_effective_access(), and the extension comes from the flags.
                $assign = new \assign($cm->context, $cm->get_course_module_record(), $modinfo->get_course());
                $assign->update_effective_access($userid);
                $dates = $assign->get_instance($userid);
                $extension = (int) $DB->get_field(
                    'assign_user_flags',
                    'extensionduedate',
                    ['assignment' => $instance->id, 'userid' => $userid]
                );
                $submission = $DB->get_record('assign_submission', ['assignment' => $instance->id, 'userid' => $userid,
                    'latest' => 1], 'status');
                $assignments[] = [
                    'name' => $cm->get_formatted_name(),
                    'course' => format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
                    'duedate' => self::date((int) $dates->duedate, $user),
                    'cutoffdate' => self::date((int) $dates->cutoffdate, $user),
                    'opens' => self::date((int) $dates->allowsubmissionsfromdate, $user),
                    'extensionduedate' => self::date($extension, $user),
                    'submission' => $submission ? $submission->status : 'new',
                ];
            }
        }
        return [
            'assignments' => $assignments,
            'upcoming_events' => self::upcoming_events($user, array_keys($courses)),
        ];
    }

    /**
     * Upcoming calendar events with an action for the user (deadlines of activities), from the calendar API.
     *
     * @param \stdClass $user User.
     * @param int[] $courseids Courses the events must belong to.
     * @return array
     */
    private static function upcoming_events(\stdClass $user, array $courseids): array {
        global $CFG;
        if (empty($courseids)) {
            return [];
        }
        // The calendar API uses CALENDAR_EVENT_TYPE_ACTION, which is defined in calendar/lib.php.
        require_once($CFG->dirroot . '/calendar/lib.php');
        $events = [];
        $now = time();
        $actionevents = \core_calendar\local\api::get_action_events_by_timesort(
            $now,
            $now + self::UPCOMING_DAYS * DAYSECS,
            null,
            self::UPCOMING_LIMIT,
            true,
            $user
        );
        foreach ($actionevents as $event) {
            $course = $event->get_course();
            $eventcourseid = $course ? (int) $course->get('id') : 0;
            if (!in_array($eventcourseid, $courseids, true)) {
                continue;
            }
            $events[] = [
                'name' => format_string($event->get_name()),
                'course' => format_string((string) $course->get('fullname')),
                'date' => self::date((int) $event->get_times()->get_sort_time()->getTimestamp(), $user),
            ];
        }
        return $events;
    }
}
