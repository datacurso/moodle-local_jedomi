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

/**
 * Places in a course the identifiers of a web service call that param_targets does not read, for the course gate of
 * ws_runner (FIX-001). param_targets (shared with the agent write scope) is not changed.
 *
 * Every argument named like an identifier (ending in "id" or "ids", and "instance", "importfrom", "importto") is either
 * read by param_targets or by a path of ws_runner, mapped here to a record whose course is known (KEY_KINDS, an activity
 * by "module" and "instance"), known to belong to no course (users, cohorts, roles, client ids, draft areas), scoped by a
 * context given next to it ("itemid", "instanceid"), or unplaced: the caller refuses unplaced arguments while some
 * courses are outside Jedomi (fail closed).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ws_arguments {
    /** @var array<string, array<string, string>> Function name prefix ('' for every function) => [argument => kind]. */
    private const KEY_KINDS = [
        '' => ['enrolid' => 'enrol', 'groupingid' => 'grouping', 'groupingids' => 'grouping', 'defaultgroupingid' => 'grouping',
            'pagecontextid' => 'context', 'scaleid' => 'scale', 'ueid' => 'user_enrolment'],
        'core_badges_' => ['badgeid' => 'badge', 'badgeids' => 'badge'],
        'core_calendar_' => ['aftereventid' => 'event', 'eventid' => 'event', 'eventids' => 'event'],
        'core_course_' => ['sectionid' => 'section', 'sectionids' => 'section'],
        'core_course_import_course' => ['importfrom' => 'course', 'importto' => 'course'],
        'core_courseformat_' => ['sectionid' => 'section', 'sectionids' => 'section', 'targetcmid' => 'cm',
            'targetsectionid' => 'section'],
        'core_grades_create_gradecategories' => ['parentcategoryid' => 'grade_category'],
        'core_grades_get_feedback' => ['itemid' => 'grade_item'],
        'core_message_' => ['conversationid' => 'conversation', 'conversationids' => 'conversation', 'convid' => 'conversation',
            'messageid' => 'message'],
        'enrol_' => ['instanceid' => 'enrol'],
        'mod_assign_' => ['submissionid' => 'assign_submission'],
        'mod_assign_save_grade' => ['criterionid' => '', 'levelid' => ''],
        'mod_book_' => ['chapterid' => 'book_chapter'],
        'mod_data_' => ['databaseid' => 'mod:data', 'entryid' => 'data_record', 'fieldid' => 'data_field'],
        'mod_forum_' => ['discussionid' => 'discussion', 'discussionids' => 'discussion', 'postid' => 'post',
            'postids' => 'post'],
        'mod_glossary_' => ['entryid' => 'glossary_entry', 'id' => 'mod:glossary'],
        'mod_h5pactivity_' => ['attemptid' => 'h5pactivity_attempt', 'attemptids' => 'h5pactivity_attempt',
            'h5pactivityid' => 'mod:h5pactivity'],
        'mod_quiz_' => ['attemptid' => 'quiz_attempt'],
        'mod_scorm_' => ['scoid' => 'scorm_sco'],
        'mod_wiki_' => ['subwikiid' => 'wiki_subwiki'],
        'mod_workshop_' => ['assessmentid' => 'workshop_assessment', 'attachmentsid' => '', 'inlineattachmentsid' => '',
            'submissionid' => 'workshop_submission'],
    ];

    /** @var array<string, string> Kind => SQL that gives the course of a record of that kind (:id). */
    private const COURSE_SQL = [
        'assign_submission' => 'SELECT a.course FROM {assign_submission} s JOIN {assign} a ON a.id = s.assignment
            WHERE s.id = :id',
        'badge' => 'SELECT courseid FROM {badge} WHERE id = :id',
        'book_chapter' => 'SELECT b.course FROM {book_chapters} c JOIN {book} b ON b.id = c.bookid WHERE c.id = :id',
        'data_field' => 'SELECT d.course FROM {data_fields} f JOIN {data} d ON d.id = f.dataid WHERE f.id = :id',
        'data_record' => 'SELECT d.course FROM {data_records} r JOIN {data} d ON d.id = r.dataid WHERE r.id = :id',
        'discussion' => 'SELECT course FROM {forum_discussions} WHERE id = :id',
        'enrol' => 'SELECT courseid FROM {enrol} WHERE id = :id',
        'event' => 'SELECT courseid FROM {event} WHERE id = :id',
        'glossary_entry' => 'SELECT g.course FROM {glossary_entries} e JOIN {glossary} g ON g.id = e.glossaryid
            WHERE e.id = :id',
        'grade_category' => 'SELECT courseid FROM {grade_categories} WHERE id = :id',
        'grade_item' => 'SELECT courseid FROM {grade_items} WHERE id = :id',
        'grouping' => 'SELECT courseid FROM {groupings} WHERE id = :id',
        'h5pactivity_attempt' => 'SELECT h.course FROM {h5pactivity_attempts} a
            JOIN {h5pactivity} h ON h.id = a.h5pactivityid WHERE a.id = :id',
        'note' => "SELECT courseid FROM {post} WHERE id = :id AND module = 'notes'",
        'post' => 'SELECT d.course FROM {forum_posts} p JOIN {forum_discussions} d ON d.id = p.discussion WHERE p.id = :id',
        'quiz_attempt' => 'SELECT q.course FROM {quiz_attempts} a JOIN {quiz} q ON q.id = a.quiz WHERE a.id = :id',
        'scale' => 'SELECT courseid FROM {scale} WHERE id = :id',
        'scorm_sco' => 'SELECT s.course FROM {scorm_scoes} c JOIN {scorm} s ON s.id = c.scorm WHERE c.id = :id',
        'section' => 'SELECT course FROM {course_sections} WHERE id = :id',
        'user_enrolment' => 'SELECT e.courseid FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid WHERE ue.id = :id',
        'wiki_subwiki' => 'SELECT w.course FROM {wiki_subwikis} s JOIN {wiki} w ON w.id = s.wikiid WHERE s.id = :id',
        'workshop_assessment' => 'SELECT w.course FROM {workshop_assessments} a
            JOIN {workshop_submissions} s ON s.id = a.submissionid JOIN {workshop} w ON w.id = s.workshopid WHERE a.id = :id',
        'workshop_submission' => 'SELECT w.course FROM {workshop_submissions} s JOIN {workshop} w ON w.id = s.workshopid
            WHERE s.id = :id',
    ];

    /** @var string[] Identifier-like arguments that belong to no course: cohorts, roles, tags, users, client and device
     * ids, language strings, draft areas (only ever read in the area of the current user). */
    private const NON_COURSE_KEYS = ['appid', 'authorid', 'clientmsgid', 'clientnoteid', 'cohortid', 'cohortids', 'draftid',
        'draftitemid', 'pushid', 'roleid', 'roleids', 'stringid', 'studentid', 'tagid', 'tagids', 'uuid'];

    /** @var string[] Context levels given by name or number (system, user, category, course, module, block). */
    private const LEVELS = ['system' => CONTEXT_SYSTEM, 'user' => CONTEXT_USER, 'coursecat' => CONTEXT_COURSECAT,
        'course' => CONTEXT_COURSE, 'module' => CONTEXT_MODULE, 'block' => CONTEXT_BLOCK];

    /**
     * Read the identifier-like arguments of a call that param_targets does not read.
     *
     * @param array $args Validated arguments by name.
     * @param string $wsname Web service function name ('' when not known: only the arguments of every function).
     * @param string[] $handled Paths read by the caller ("options.ids", "courses.*.id"); "*" is every element.
     * @return array{targets: array, unplaced: string[]} Targets as [kind, id] pairs (see course_of()) and the paths of
     *     the arguments that cannot be placed.
     */
    public static function read(array $args, string $wsname, array $handled = []): array {
        $result = ['targets' => [], 'unplaced' => []];
        self::walk($args, [], '', $args, $wsname, $handled, $result);
        $result['unplaced'] = array_values(array_unique($result['unplaced']));
        return $result;
    }

    /**
     * Course of a record of a kind read here, or 0 when it belongs to no course (a site event, a site scale, a message
     * of an individual conversation) or does not exist.
     *
     * @param string $kind Kind (a key of COURSE_SQL, "conversation" or "message").
     * @param int $id Its id.
     * @return int
     */
    public static function course_of(string $kind, int $id): int {
        global $DB;
        if ($kind === 'message') {
            $kind = 'conversation';
            $id = (int) $DB->get_field('messages', 'conversationid', ['id' => $id]);
        }
        if ($kind === 'conversation') {
            $contextid = (int) $DB->get_field('message_conversations', 'contextid', ['id' => $id]);
            return $contextid ? \local_jedomi\course_access::context_course_id($contextid) : 0;
        }
        if (!isset(self::COURSE_SQL[$kind])) {
            return 0;
        }
        return (int) $DB->get_field_sql(self::COURSE_SQL[$kind], ['id' => $id]);
    }

    /**
     * Walk the arguments at any depth: a scalar takes the name of its key, or of its list.
     *
     * @param mixed $value Value.
     * @param string[] $path Steps to the value ("*" for list elements).
     * @param string $name Name of the value (lowercase).
     * @param array $record Nearest record (array with named keys) holding the value.
     * @param string $wsname Web service function name.
     * @param string[] $handled Paths read by the caller.
     * @param array $result Targets and unplaced paths found so far.
     * @return void
     */
    private static function walk(
        $value,
        array $path,
        string $name,
        array $record,
        string $wsname,
        array $handled,
        array &$result
    ): void {
        if (!is_array($value)) {
            self::check($value, $path, $name, $record, $wsname, $handled, $result);
            return;
        }
        $islist = array_is_list($value);
        foreach ($value as $key => $item) {
            $itemname = $islist ? $name : strtolower((string) $key);
            $itempath = array_merge($path, [$islist ? '*' : $itemname]);
            self::walk($item, $itempath, $itemname, $islist ? $record : $value, $wsname, $handled, $result);
        }
    }

    /**
     * Place one scalar argument: add its target, or its path to the unplaced ones.
     *
     * @param mixed $value Value.
     * @param string[] $path Steps to the value.
     * @param string $name Its name (lowercase).
     * @param array $record Record holding it.
     * @param string $wsname Web service function name.
     * @param string[] $handled Paths read by the caller.
     * @param array $result Targets and unplaced paths found so far.
     * @return void
     */
    private static function check(
        $value,
        array $path,
        string $name,
        array $record,
        string $wsname,
        array $handled,
        array &$result
    ): void {
        $empty = $value === null || $value === '' || $value === 0 || $value === '0' || is_bool($value);
        $idlike = in_array($name, ['instance', 'importfrom', 'importto'], true) || preg_match('/ids?$/', $name);
        if ($empty || !$idlike) {
            return;
        }
        $steps = $path;
        while (end($steps) === '*') {
            array_pop($steps);
        }
        $nocourse = in_array($name, self::NON_COURSE_KEYS, true) || preg_match('/userids?$/', $name);
        if (in_array(implode('.', $steps), $handled, true) || $nocourse) {
            return;
        }
        $numeric = is_int($value) || (is_string($value) && ctype_digit($value));
        $kind = self::kind_of($name, $wsname);
        if ($kind === '') {
            // Scoped by another argument of the call (the activity being graded, the draft area of the user).
            return;
        }
        if ($kind === null) {
            if (param_targets::kind_of($name) !== null || self::is_scoped($name, $record)) {
                return;
            }
            if ($name === 'instance') {
                $kind = self::instance_kind($record);
            }
        }
        if ($kind !== null && $numeric) {
            $result['targets'][] = [$kind, (int) $value];
            return;
        }
        $result['unplaced'][] = implode('.', $path);
    }

    /**
     * Kind of an argument for a function, by the longest matching prefix of KEY_KINDS.
     *
     * @param string $name Argument name (lowercase).
     * @param string $wsname Web service function name.
     * @return string|null
     */
    private static function kind_of(string $name, string $wsname): ?string {
        $kind = null;
        $length = -1;
        foreach (self::KEY_KINDS as $prefix => $keys) {
            if (isset($keys[$name]) && str_starts_with($wsname, $prefix) && strlen($prefix) > $length) {
                $kind = $keys[$name];
                $length = strlen($prefix);
            }
        }
        return $kind;
    }

    /**
     * Whether an argument is scoped by a context given next to it: an "instanceid" with a known "contextlevel" (read by
     * ws_runner), an "itemid" next to a context, or the draft "itemid" of an editor ({text, format, itemid}), which
     * Moodle reads only in the draft area of the current user.
     *
     * @param string $name Argument name (lowercase).
     * @param array $record Record holding it.
     * @return bool
     */
    private static function is_scoped(string $name, array $record): bool {
        $level = $record['contextlevel'] ?? null;
        $haslevel = is_scalar($level) && (in_array((int) $level, self::LEVELS, true)
            || array_key_exists(strtolower((string) $level), self::LEVELS));
        if ($name === 'instanceid') {
            return $haslevel;
        }
        if ($name === 'itemid') {
            $hascontext = !empty($record['contextid']) && is_numeric($record['contextid']);
            $iseditor = array_key_exists('text', $record) && array_key_exists('format', $record);
            return $hascontext || ($haslevel && isset($record['instanceid'])) || $iseditor;
        }
        return false;
    }

    /**
     * Kind of an "instance" given with the name of its module ("module" or "modname", with or without "mod_").
     *
     * @param array $record Record holding it.
     * @return string|null "mod:<module>", or null when the module is not given or not installed.
     */
    private static function instance_kind(array $record): ?string {
        $module = $record['module'] ?? ($record['modname'] ?? null);
        if (!is_string($module)) {
            return null;
        }
        $module = preg_replace('/^mod_/', '', strtolower($module));
        return array_key_exists($module, \core_component::get_plugin_list('mod')) ? 'mod:' . $module : null;
    }
}
