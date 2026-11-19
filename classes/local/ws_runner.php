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

namespace local_jedomi\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Runs a Moodle web service function for the AI, shared by the web chat, Telegram and the companion web service.
 *
 * The role gate (the web service list saved for the role) is the same in every channel; the target function still
 * checks the real Moodle capabilities of the user.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ws_runner {
    /** @var array<string, array<string, string>> Function => [argument path => kind] for arguments with a name
     * param_targets does not read ("id", "ids", "assignmentids"); a "*" step is every element. A kind of no course
     * (user, category, cohort) places the argument without a course. */
    private const FUNCTION_TARGETS = [
        'core_badges_get_badge' => ['id' => 'badge'],
        'core_cohort_update_cohorts' => ['cohorts.*.id' => 'cohort'],
        'core_course_delete_categories' => ['categories.*.id' => 'category'],
        'core_course_edit_module' => ['id' => 'cm'],
        'core_course_edit_section' => ['id' => 'section'],
        'core_course_get_courses' => ['options.ids' => 'course'],
        'core_course_get_module' => ['id' => 'cm'],
        'core_course_set_favourite_courses' => ['courses.*.id' => 'course'],
        'core_course_update_categories' => ['categories.*.id' => 'category'],
        'core_course_update_courses' => ['courses.*.id' => 'course'],
        'core_group_update_groupings' => ['groupings.*.id' => 'grouping'],
        'core_group_update_groups' => ['groups.*.id' => 'group'],
        'core_notes_update_notes' => ['notes.*.id' => 'note'],
        'core_user_update_users' => ['users.*.id' => 'user'],
        'mod_assign_get_grades' => ['assignmentids' => 'mod:assign'],
        'mod_assign_get_submissions' => ['assignmentids' => 'mod:assign'],
        'mod_assign_get_user_flags' => ['assignmentids' => 'mod:assign'],
        'mod_assign_get_user_mappings' => ['assignmentids' => 'mod:assign'],
        'mod_glossary_get_entry_by_id' => ['id' => 'glossary_entry'],
        'mod_glossary_view_entry' => ['id' => 'glossary_entry'],
    ];

    /**
     * @var array<string, string> The only functions run while some course is outside Jedomi: function => how its
     * arguments name the course (read by excluded_courses_in) and how its result is handled. Each one names its course
     * only by arguments the gate reads, and its result is filtered by course or has no course data. Arguments named by
     * other means (ids under other names, {name, value} pairs, context ids under other names) and results with course
     * data but no course field cannot be guarded, so any other function is refused (error_ws_restricted).
     */
    public const RESTRICTED_ALLOWLIST = [
        'core_calendar_create_calendar_events' => 'events.*.courseid, events.*.groupid; result events filtered by courseid',
        'core_calendar_get_calendar_events' => 'events.eventids/courseids/groupids/categoryids; result events filtered by '
            . 'courseid',
        'core_cohort_add_cohort_members' => 'no course (cohorts and users); result warnings only',
        'core_completion_get_activities_completion_status' => 'courseid (required); result of that course',
        'core_completion_get_course_completion_status' => 'courseid (required); result of that course',
        'core_course_get_categories' => 'no course (categories); result categories, no course records',
        'core_course_get_contents' => 'courseid (required); result sections of that course',
        'core_course_get_course_module' => 'cmid (required); result that course module',
        'core_course_get_courses' => 'options.ids; result courses filtered by id',
        'core_course_get_courses_by_field' => 'value of field id/ids/shortname/idnumber; result courses filtered by id',
        'core_course_get_enrolled_courses_by_timeline_classification' => 'no argument; result courses filtered by id',
        'core_course_search_courses' => 'no course id; result courses filtered by id, total lowered',
        'core_course_update_courses' => 'courses.*.id; result warnings only',
        'core_enrol_get_enrolled_users' => 'courseid (required), group in options {name: groupid}; enrolledcourses of '
            . 'users filtered by id',
        'core_enrol_get_users_courses' => 'userid (no course); result courses filtered by id',
        'core_group_add_group_members' => 'members.*.groupid; no result',
        'core_group_create_groups' => 'groups.*.courseid; result groups filtered by courseid',
        'core_group_get_course_groups' => 'courseid (required); result groups filtered by courseid',
        'core_group_get_group_members' => 'groupids; result of those groups',
        'core_group_get_groups' => 'groupids; result groups filtered by courseid',
        'core_message_send_instant_messages' => 'no course (messages.*.touserid); result message ids',
        'core_notes_create_notes' => 'notes.*.courseid; result note ids',
        'core_user_create_users' => 'no course (users); result ids and user names',
        'core_user_get_course_user_profiles' => 'userlist.*.courseid; enrolledcourses filtered by id',
        'core_user_get_users' => 'no course (criteria); result users without course data',
        'core_user_get_users_by_field' => 'no course (field, values); result users without course data',
        'core_webservice_get_site_info' => 'no course; result site and user, no course data',
        'enrol_manual_enrol_users' => 'enrolments.*.courseid; no result',
        'gradereport_overview_get_course_grades' => 'userid (no course); result grades filtered by courseid',
        'gradereport_user_get_grade_items' => 'courseid (required), groupid; result usergrades filtered by courseid',
        'mod_assign_get_assignments' => 'courseids; result courses filtered by id',
        'mod_assign_get_submissions' => 'assignmentids; result of those assignments',
        'mod_assign_save_grade' => 'assignmentid; no result',
        'mod_forum_add_discussion' => 'forumid, groupid (options hold no record of a course); result discussion id',
        'mod_forum_add_discussion_post' => 'postid (options hold no record of a course); result the new post',
        'mod_forum_get_discussion_posts' => 'discussionid; result posts of that discussion',
        'mod_forum_get_forum_discussions' => 'forumid, groupid; result discussions of that forum',
        'mod_forum_get_forums_by_courses' => 'courseids; result forums filtered by course',
        'mod_quiz_get_quizzes_by_courses' => 'courseids; result quizzes filtered by course',
    ];

    /** @var array<string, array<string, string>> Function => [option name => kind] for options given as {name, value}
     * pairs that name a record Moodle does not check against the course of the call. */
    private const OPTION_TARGETS = [
        'core_enrol_get_enrolled_users' => ['groupid' => 'group'],
    ];

    /** @var string[] Keys of nested lists of courses in results, whose records are courses by their "id" (FIX-002). */
    private const COURSE_LIST_KEYS = ['courses', 'enrolledcourses'];

    /** @var array<string, string> Course-listing functions => key of their list of courses ('' for the result itself). */
    private const COURSE_LIST_RESULTS = [
        'core_course_get_courses' => '',
        'core_course_get_courses_by_field' => 'courses',
        'core_course_get_enrolled_courses_by_timeline_classification' => 'courses',
        'core_course_get_recent_courses' => '',
        'core_course_search_courses' => 'courses',
        'core_enrol_get_users_courses' => '',
        'mod_assign_get_assignments' => 'courses',
    ];

    /** @var array<string, int> Course of each activity ("modname:instance") looked up while filtering one result. */
    private static array $activitycourses = [];

    /**
     * Tell whether a chat role may run a web service function: the administrator without a saved list runs every one,
     * any other role (or an administrator with a saved list, even empty) only the functions of its list.
     *
     * @param string $role Chat role (admin, manager, teacher or student).
     * @param string $wsname Web service function name.
     * @return bool
     */
    public static function role_allows(string $role, string $wsname): bool {
        return \local_jedomi_role_has_full_ws_access($role)
            || in_array($wsname, \local_jedomi_get_role_allowlist($role), true);
    }

    /**
     * Functions the web service search of a role may return, the same list that gates description and execution.
     *
     * @param string $role Jedomi role (admin, manager, teacher, student).
     * @return string[]|null Null when the role may call every function; otherwise its list (empty: none).
     */
    public static function searchable_functions(string $role): ?array {
        if (\local_jedomi_role_has_full_ws_access($role)) {
            return null;
        }
        return array_values(array_filter(\local_jedomi_get_role_allowlist($role), 'is_string'));
    }

    /**
     * Method that implements a web service function.
     *
     * @param string $wsname Web service function name.
     * @return \ReflectionMethod
     * @throws \moodle_exception ws_not_found or ws_execute_error.
     */
    public static function resolve(string $wsname): \ReflectionMethod {
        global $DB;
        $function = $DB->get_record('external_functions', ['name' => $wsname], 'classname, classpath, component, methodname');
        if (!$function) {
            throw new \moodle_exception('ws_not_found', 'local_jedomi');
        }
        $classname = \local_jedomi_resolve_ws_classname($wsname);
        if (!$classname || !class_exists($classname)) {
            throw new \moodle_exception('ws_not_found', 'local_jedomi');
        }
        if (!method_exists($classname, $function->methodname)) {
            throw new \moodle_exception(
                'ws_execute_error',
                'local_jedomi',
                '',
                "Method {$classname}::{$function->methodname}() does not exist"
            );
        }
        return new \ReflectionMethod($classname, $function->methodname);
    }

    /**
     * First required parameter of the method that the arguments do not provide, or null when none is missing.
     *
     * @param \ReflectionMethod $method Method of the function.
     * @param array $args Arguments by name.
     * @return string|null
     */
    public static function missing_parameter(\ReflectionMethod $method, array $args): ?string {
        foreach ($method->getParameters() as $parameter) {
            if (!array_key_exists($parameter->getName(), $args) && !$parameter->isDefaultValueAvailable()) {
                return $parameter->getName();
            }
        }
        return null;
    }

    /**
     * Validate the arguments of a web service function against its parameter description, as Moodle does for every
     * web service call: types are checked and cleaned, defaults applied and unknown keys rejected.
     *
     * Functions without a parameter description (not an external function) get their arguments unchanged.
     *
     * @param \ReflectionMethod $method Method of the function (from resolve()).
     * @param array $args Arguments by name.
     * @return array Validated arguments by name.
     * @throws \moodle_exception ws_invalid_parameters with the reason Moodle gives.
     */
    public static function validate(\ReflectionMethod $method, array $args): array {
        $classname = $method->getDeclaringClass()->getName();
        $describer = $method->getName() . '_parameters';
        if (!method_exists($classname, $describer)) {
            return $args;
        }
        $description = $classname::$describer();
        if (!$description instanceof \core_external\external_description) {
            return $args;
        }
        try {
            return \core_external\external_api::validate_parameters($description, $args);
        } catch (\invalid_parameter_exception $e) {
            $detail = !empty($e->debuginfo) ? (string) $e->debuginfo : $e->getMessage();
            throw new \moodle_exception('ws_invalid_parameters', 'local_jedomi', '', $detail, $detail);
        }
    }

    /**
     * Call the method of a web service function with the arguments matched by name, after validating them with the
     * parameter description of the function (the same validation in every channel).
     *
     * @param \ReflectionMethod $method Method of the function (from resolve()).
     * @param array $args Arguments by name.
     * @param bool $intransaction Run inside a delegated transaction committed only when the call succeeds.
     * @return mixed Result of the function, shaped by its return description, without records of excluded courses.
     * @throws \moodle_exception error_ws_restricted, missingparam, ws_invalid_parameters, error_course_excluded,
     *     error_argument_unplaced, ws_execute_error or the error of the function.
     */
    public static function call(\ReflectionMethod $method, array $args, bool $intransaction = false) {
        global $DB;
        $restricted = \local_jedomi\course_access::is_restricted();
        $wsname = $restricted ? self::function_name($method) : '';
        if ($restricted && !isset(self::RESTRICTED_ALLOWLIST[$wsname])) {
            // Only functions whose course the gate can read and whose result is filtered run (RESTRICTED_ALLOWLIST).
            $name = $wsname !== '' ? $wsname : $method->getDeclaringClass()->getName() . '::' . $method->getName();
            throw new \moodle_exception('error_ws_restricted', 'local_jedomi', '', $name);
        }
        $missing = self::missing_parameter($method, $args);
        if ($missing !== null) {
            throw new \moodle_exception('missingparam', 'error', '', $missing);
        }
        $args = self::validate($method, $args);
        $excluded = self::excluded_courses_in($args, $wsname);
        if (!empty($excluded)) {
            // An excluded course is outside Jedomi in every channel, whatever argument names it.
            throw new \moodle_exception('error_course_excluded', 'local_jedomi', '', null, implode(',', $excluded));
        }
        $unplaced = self::unplaced_arguments($args, $wsname);
        if (!empty($unplaced)) {
            // A record Jedomi cannot place in a course could be in an excluded one (fail closed, FIX-001).
            throw new \moodle_exception('error_argument_unplaced', 'local_jedomi', '', implode(', ', $unplaced));
        }
        $callargs = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $callargs[] = array_key_exists($name, $args) ? $args[$name] : $parameter->getDefaultValue();
        }
        $transaction = $intransaction ? $DB->start_delegated_transaction() : null;
        try {
            $result = $method->invokeArgs(null, $callargs);
            if ($transaction) {
                $transaction->allow_commit();
            }
        } catch (\Throwable $e) {
            if ($transaction) {
                try {
                    $transaction->rollback($e);
                    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- rollback() throws $e again.
                } catch (\Throwable $rethrown) {
                    // The error is reported below.
                }
            }
            if ($e instanceof \invalid_parameter_exception) {
                $detail = $e->getMessage();
                if (!empty($e->debuginfo)) {
                    $detail .= ' | debug: ' . $e->debuginfo;
                }
                throw new \moodle_exception('ws_execute_error', 'local_jedomi', '', $detail);
            }
            if ($e instanceof \moodle_exception) {
                throw $e;
            }
            // JEDOMI-SEC-009: the message of an unexpected error can quote a query or a path; only its class is given.
            throw new \moodle_exception('ws_execute_error', 'local_jedomi', '', safe_error::for_model($e));
        }
        return self::without_excluded_courses(self::clean_result($method, $result), $wsname);
    }

    /**
     * Courses excluded from Jedomi that the arguments of a web service call name, at any depth: every target that
     * param_targets reads (a course, a course module, an activity instance such as forumid or assignmentid, a context, a
     * group) is mapped to its course; a context may also be given by contextlevel and instanceid, some functions name
     * records by "id" or "ids" (FUNCTION_TARGETS, core_course_get_courses_by_field), and ws_arguments maps other records
     * (sections, forum discussions and posts, events, attempts, submissions, entries, conversations, imports).
     *
     * A record that does not exist (a deleted activity) names no excluded course: the function reports it. Arguments that
     * cannot be placed in a course are given by unplaced_arguments().
     *
     * @param array $args Arguments by name (validated: the call uses these).
     * @param string $wsname Web service function name; empty when it is not known (no function-specific arguments).
     * @return int[] Excluded course ids, without repetitions.
     */
    public static function excluded_courses_in(array $args, string $wsname = ''): array {
        if (!\local_jedomi\course_access::is_restricted()) {
            return [];
        }
        $targets = array_merge(
            (new param_targets())->resolve($args)['targets'],
            self::function_targets($wsname, $args),
            self::level_targets($args),
            ws_arguments::read($args, $wsname, self::handled_paths($wsname))['targets']
        );
        $excluded = [];
        foreach ($targets as [$kind, $id]) {
            $courseid = self::target_course($kind, (int) $id);
            if (!\local_jedomi\course_access::is_course_in_jedomi($courseid)) {
                $excluded[$courseid] = $courseid;
            }
        }
        return array_values($excluded);
    }

    /**
     * Arguments of a web service call, named like identifiers, that Jedomi cannot place in a course nor in a target of
     * no course (users, cohorts, categories, roles, the system context), while some courses are outside Jedomi: such a
     * record could be in an excluded course, so the call is refused (fail closed, FIX-001). Empty when every course is in
     * Jedomi.
     *
     * @param array $args Arguments by name (validated: the call uses these).
     * @param string $wsname Web service function name; empty when it is not known (no function-specific arguments).
     * @return string[] Paths of the unplaced arguments ("postid", "items.*.itemid").
     */
    public static function unplaced_arguments(array $args, string $wsname = ''): array {
        if (!\local_jedomi\course_access::is_restricted()) {
            return [];
        }
        return ws_arguments::read($args, $wsname, self::handled_paths($wsname))['unplaced'];
    }

    /**
     * Argument paths read by function_targets() for a function.
     *
     * @param string $wsname Web service function name.
     * @return string[]
     */
    private static function handled_paths(string $wsname): array {
        return array_keys(self::FUNCTION_TARGETS[$wsname] ?? []);
    }

    /**
     * Name of the web service function a method implements, or an empty string when none is registered.
     *
     * @param \ReflectionMethod $method Method of the function (from resolve()).
     * @return string
     */
    private static function function_name(\ReflectionMethod $method): string {
        global $DB;
        $class = $method->getDeclaringClass()->getName();
        $functions = $DB->get_records('external_functions', ['methodname' => $method->getName()], 'id', 'id, name, classname');
        foreach ($functions as $function) {
            $classname = ltrim((string) $function->classname, '\\');
            if ($classname === $class || is_subclass_of($classname, $class)) {
                return (string) $function->name;
            }
        }
        return '';
    }

    /**
     * Targets of the function-specific arguments of a call: the "id" and "ids" of FUNCTION_TARGETS, the {name, value}
     * options of OPTION_TARGETS and the value of
     * core_course_get_courses_by_field when its field names a course (id, ids, shortname or idnumber).
     *
     * @param string $wsname Web service function name.
     * @param array $args Arguments by name.
     * @return array Targets as [kind, id] pairs.
     */
    private static function function_targets(string $wsname, array $args): array {
        global $DB;
        $targets = [];
        foreach (self::FUNCTION_TARGETS[$wsname] ?? [] as $path => $kind) {
            foreach (self::values_at($args, explode('.', $path)) as $value) {
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    $targets[] = [$kind, (int) $value];
                }
            }
        }
        foreach (self::OPTION_TARGETS[$wsname] ?? [] as $option => $kind) {
            foreach (is_array($args['options'] ?? null) ? $args['options'] : [] as $pair) {
                // Cast as Moodle casts the option ("12abc" is group 12).
                $value = is_array($pair) && ($pair['name'] ?? null) === $option ? ($pair['value'] ?? null) : null;
                if (is_scalar($value) && (int) $value !== 0) {
                    $targets[] = [$kind, (int) $value];
                }
            }
        }
        if ($wsname === 'core_course_get_courses_by_field') {
            $field = (string) ($args['field'] ?? '');
            $value = trim((string) ($args['value'] ?? ''));
            if ($field === 'id' || $field === 'ids') {
                foreach (explode(',', $value) as $id) {
                    $targets[] = ['course', (int) $id];
                }
            } else if (($field === 'shortname' || $field === 'idnumber') && $value !== '') {
                foreach ($DB->get_fieldset_select('course', 'id', "{$field} = :value", ['value' => $value]) as $id) {
                    $targets[] = ['course', (int) $id];
                }
            }
        }
        return $targets;
    }

    /**
     * Values of the arguments at a path; a "*" step is every element of a list.
     *
     * @param mixed $value Arguments (or a part of them).
     * @param string[] $path Steps.
     * @return array Scalar values found (a list at the end of the path gives its elements).
     */
    private static function values_at($value, array $path): array {
        if (empty($path)) {
            return is_array($value) ? array_values(array_filter($value, 'is_scalar')) : (is_scalar($value) ? [$value] : []);
        }
        $step = array_shift($path);
        if (!is_array($value)) {
            return [];
        }
        $children = $step === '*' ? array_values($value) : (array_key_exists($step, $value) ? [$value[$step]] : []);
        $values = [];
        foreach ($children as $child) {
            array_push($values, ...self::values_at($child, $path));
        }
        return $values;
    }

    /**
     * Contexts given by contextlevel and instanceid (such as core_files_get_files), at any depth, as context targets.
     *
     * @param array $args Arguments (or a part of them).
     * @return array Targets as [kind, id] pairs.
     */
    private static function level_targets(array $args): array {
        $targets = [];
        $bylevel = isset($args['contextlevel'], $args['instanceid']) && is_scalar($args['contextlevel'])
            && is_numeric($args['instanceid']);
        if ($bylevel) {
            $levels = ['course' => CONTEXT_COURSE, 'module' => CONTEXT_MODULE, 'block' => CONTEXT_BLOCK];
            $level = is_numeric($args['contextlevel']) ? (int) $args['contextlevel']
                : ($levels[strtolower((string) $args['contextlevel'])] ?? 0);
            if (in_array($level, $levels, true)) {
                $classname = \core\context_helper::get_class_for_level($level);
                $context = $classname::instance((int) $args['instanceid'], IGNORE_MISSING);
                if ($context) {
                    $targets[] = ['context', (int) $context->id];
                }
            }
        }
        foreach ($args as $value) {
            if (is_array($value)) {
                array_push($targets, ...self::level_targets($value));
            }
        }
        return $targets;
    }

    /**
     * Course a target belongs to, or 0 when it belongs to no course (a user, a category, a cohort) or does not exist;
     * the kinds of ws_arguments (sections, posts, events...) are mapped there.
     *
     * @param string $kind Kind of target (see param_targets::resolve()).
     * @param int $id Its id.
     * @return int
     */
    private static function target_course(string $kind, int $id): int {
        global $DB;
        switch ($kind) {
            case 'course':
                return $id;
            case 'context':
                return \local_jedomi\course_access::context_course_id($id);
            case 'cm':
                return (int) $DB->get_field('course_modules', 'course', ['id' => $id]);
            case 'group':
                return (int) $DB->get_field('groups', 'courseid', ['id' => $id]);
        }
        if (str_starts_with($kind, 'mod:')) {
            $cm = get_coursemodule_from_instance(substr($kind, 4), $id, 0, false, IGNORE_MISSING);
            return $cm ? (int) $cm->course : 0;
        }
        return ws_arguments::course_of($kind, $id);
    }

    /**
     * The result of a call without the records of excluded courses (RISK-002): the courses of the course-listing
     * functions (COURSE_LIST_RESULTS, by their id; a "total" next to the list is lowered too), and at any depth every
     * record of a list whose "courseid" or "course" is an excluded course, and every excluded course (by its "id") of a
     * nested list of courses such as "enrolledcourses" (COURSE_LIST_KEYS, FIX-002). Lists stay lists.
     *
     * @param mixed $result Cleaned result.
     * @param string $wsname Web service function name; empty when it is not known.
     * @return mixed
     */
    private static function without_excluded_courses($result, string $wsname) {
        if (!is_array($result) || !\local_jedomi\course_access::is_restricted()) {
            return $result;
        }
        self::$activitycourses = [];
        $path = self::COURSE_LIST_RESULTS[$wsname] ?? null;
        $list = $path === '' ? $result : ($path !== null ? ($result[$path] ?? null) : null);
        if (is_array($list) && array_is_list($list)) {
            $kept = array_values(array_filter($list, static fn($course): bool => !is_array($course)
                || !isset($course['id']) || !is_numeric($course['id'])
                || \local_jedomi\course_access::is_course_in_jedomi((int) $course['id'])));
            if ($path === '') {
                $result = $kept;
            } else {
                if (isset($result['total']) && is_int($result['total'])) {
                    $result['total'] = max(0, $result['total'] - (count($list) - count($kept)));
                }
                $result[$path] = $kept;
            }
        }
        return self::without_excluded_records($result);
    }

    /**
     * An array without, at any depth, the records of a list whose "courseid" or "course" is an excluded course, and the
     * courses of a nested list of courses (COURSE_LIST_KEYS, such as the "enrolledcourses" of users) whose "id" is an
     * excluded course (FIX-002).
     *
     * @param array $value Array.
     * @param string $name Key of the array in its parent (lowercase); a list passes it to its elements.
     * @return array
     */
    private static function without_excluded_records(array $value, string $name = ''): array {
        $islist = array_is_list($value);
        $courses = $islist && in_array($name, self::COURSE_LIST_KEYS, true);
        $kept = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                if ($islist && self::is_excluded_record($item, $courses)) {
                    continue;
                }
                $item = self::without_excluded_records($item, $islist ? $name : strtolower((string) $key));
            }
            $kept[$key] = $item;
        }
        return $islist ? array_values($kept) : $kept;
    }

    /**
     * Whether a record belongs to an excluded course by its "courseid" or "course" field, by the course of its activity
     * ("modulename" and "instance", such as a user override event with courseid 0), or is an excluded course by its
     * "id" when it is in a list of courses.
     *
     * @param array $record Record.
     * @param bool $iscourse Whether the record is in a list of courses.
     * @return bool
     */
    private static function is_excluded_record(array $record, bool $iscourse = false): bool {
        foreach ($iscourse ? ['courseid', 'course', 'id'] : ['courseid', 'course'] as $field) {
            $courseid = isset($record[$field]) && is_numeric($record[$field]) ? (int) $record[$field] : 0;
            if (!\local_jedomi\course_access::is_course_in_jedomi($courseid)) {
                return true;
            }
        }
        $modname = isset($record['modulename']) && is_string($record['modulename']) ? $record['modulename'] : '';
        $instance = isset($record['instance']) && is_numeric($record['instance']) ? (int) $record['instance'] : 0;
        return $modname !== '' && $instance > 0
            && !\local_jedomi\course_access::is_course_in_jedomi(self::activity_course($modname, $instance));
    }

    /**
     * The course of an activity instance, by its module name (an installed module only); 0 when it is unknown. Looked up
     * once per activity for each call (activitycourses is reset by without_excluded_courses).
     *
     * @param string $modname Module name, such as "quiz".
     * @param int $instance Instance id in the table of the module.
     * @return int
     */
    private static function activity_course(string $modname, int $instance): int {
        global $DB;
        $key = $modname . ':' . $instance;
        if (!array_key_exists($key, self::$activitycourses)) {
            $installed = \core_component::get_plugin_list('mod');
            self::$activitycourses[$key] = isset($installed[$modname])
                ? (int) $DB->get_field($modname, 'course', ['id' => $instance]) : 0;
        }
        return self::$activitycourses[$key];
    }

    /**
     * Shape the result of a web service function with its return description, as Moodle does for every web service
     * call: lists become lists (not arrays keyed by id) and only the described fields are kept.
     *
     * Functions without a return description get their result unchanged. A result that does not match its description
     * is also returned unchanged: the function already ran (and its changes are committed), so it is not reported as
     * failed.
     *
     * @param \ReflectionMethod $method Method of the function (from resolve()).
     * @param mixed $result Result of the function.
     * @return mixed The cleaned result.
     */
    private static function clean_result(\ReflectionMethod $method, $result) {
        $classname = $method->getDeclaringClass()->getName();
        $describer = $method->getName() . '_returns';
        if (!method_exists($classname, $describer)) {
            return $result;
        }
        $description = $classname::$describer();
        if (!$description instanceof \core_external\external_description) {
            return $result;
        }
        try {
            return \core_external\external_api::clean_returnvalue($description, $result);
        } catch (\invalid_response_exception $e) {
            // The debug information can quote values of the result: only the function is logged (JEDOMI-SEC-009).
            \local_jedomi_log('WS: the result of ' . $classname . '::' . $method->getName() . '() does not match its return '
                . 'description');
            return $result;
        }
    }
}
