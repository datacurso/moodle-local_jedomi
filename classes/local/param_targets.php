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

namespace local_jedomi\local;

/**
 * Targets named by the parameters of a web service function: the shared, side-effect-free reading used by the agent
 * write scope (write_scope) and by the course gate of every web service call (ws_runner).
 *
 * The parameters are read, at any depth, for users (also by username, email or cohort membership), courses, categories,
 * contexts, course modules, activity instances, groups and cohorts, as [kind, id] pairs. It also tells whether an
 * identifier could not be resolved (unknown) and whether a record below the top level has no concrete target of its own
 * (unanchored); what to do with those is up to the caller.
 *
 * Users may be given by username or email, or as the members of a cohort (by id or idnumber, also in the {type, value}
 * form of core_cohort_add_cohort_members). Parameters named like recipients (users, userlist, recipients, members,
 * participants, touser, tousers, to) are user ids when they hold ids; any other value in them is unknown.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class param_targets {
    /** @var string[] Parameter names of users (recipients). */
    private const USER_KEYS = ['userid', 'userids', 'touserid', 'useridto', 'useridfrom', 'user_ids', 'recipientid',
        'recipientids', 'relateduserid', 'memberid', 'memberids'];

    /** @var string[] Parameter names of courses. */
    private const COURSE_KEYS = ['courseid', 'courseids', 'course'];

    /** @var string[] Parameter names of categories (0 is the top level). */
    private const CATEGORY_KEYS = ['categoryid', 'categoryids', 'category', 'parent'];

    /** @var string[] Parameter names of contexts. */
    private const CONTEXT_KEYS = ['contextid', 'contextids'];

    /** @var string[] Parameter names of course modules. */
    private const CM_KEYS = ['cmid', 'cmids', 'coursemodule', 'coursemoduleid', 'coursemoduleids'];

    /** @var string[] Parameter names of groups. */
    private const GROUP_KEYS = ['groupid', 'groupids'];

    /** @var string[] Parameter names of activity instances whose name is not the module name and "id". */
    private const INSTANCE_ALIASES = ['assignmentid' => 'assign'];

    /** @var string[] Parameter names of cohorts, whose members are recipients. */
    private const COHORT_KEYS = ['cohortid', 'cohortids'];

    /** @var string[] Parameter names of users given by username. */
    private const USERNAME_KEYS = ['username', 'usernames'];

    /** @var string[] Parameter names of users given by email. */
    private const EMAIL_KEYS = ['email', 'emails', 'useremail'];

    /** @var string[] Parameter names that suggest recipients: their ids are users, any other value is unknown. */
    private const RECIPIENT_KEYS = ['users', 'userlist', 'recipients', 'members', 'participants', 'touser', 'tousers', 'to'];

    /** @var string[] Parameter names that end like an identifier but are not a Moodle record. */
    private const NOT_TARGETS = ['clientmsgid', 'idnumber'];

    /** @var int Members of a cohort to read as user targets; 0 reads none. */
    private int $memberlimit;

    /**
     * Reader of parameter targets.
     *
     * @param int $memberlimit Members of a cohort to read as user targets (0: none, only the cohort itself).
     */
    public function __construct(int $memberlimit = 0) {
        $this->memberlimit = max(0, $memberlimit);
    }

    /**
     * Read the targets of the parameters of a web service function.
     *
     * @param array $params Parameters of the function, by name.
     * @return array{targets: array, unknown: bool, unanchored: bool} Targets as [kind, id] pairs (kind: user, course,
     *     category, context, cm, group, cohort (by its context id) or "mod:<module>"), whether an identifier could not be
     *     resolved and whether a record below the top level has no concrete target of its own.
     */
    public function resolve(array $params): array {
        $targets = [];
        $unknown = false;
        $unanchored = false;
        $this->walk_record($params, true, $targets, $unknown, $unanchored);
        return ['targets' => $targets, 'unknown' => $unknown, 'unanchored' => $unanchored];
    }

    /**
     * Whether some targets include one that names something concrete of the site, not the site itself.
     *
     * @param array $targets Targets: [kind, id] pairs.
     * @return bool
     */
    public static function has_concrete(array $targets): bool {
        foreach ($targets as [$kind, $id]) {
            if (!self::is_site_itself($kind, $id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Walk a record: its fields give it its own targets, its collections are walked as collections, and an object
     * field with scalars is a nested record. A record below the top level without a concrete target among its own
     * fields (FIX-001) is unanchored.
     *
     * Fields are scalars, lists of scalars (such as userids) and users or cohorts given by {type, value}; an empty array
     * in a field is ignored.
     *
     * @param array $record Record (the parameters themselves at the top level).
     * @param bool $top Whether the record is the parameters themselves.
     * @param array $targets Every target found so far.
     * @param bool $unknown Whether an unknown identifier was found so far.
     * @param bool $unanchored Whether a record without a concrete target of its own was found so far.
     * @return array Own targets of the record.
     */
    private function walk_record(array $record, bool $top, array &$targets, bool &$unknown, bool &$unanchored): array {
        $own = [];
        foreach ($record as $name => $item) {
            $key = is_int($name) ? '' : strtolower((string) $name);
            $field = $this->field($item, $key, $unknown);
            if ($field !== null) {
                array_push($own, ...$field);
            } else if ($this->is_collection($item)) {
                $this->walk_collection($item, $key, $targets, $unknown, $unanchored);
            } else {
                $this->walk_record($item, false, $targets, $unknown, $unanchored);
            }
        }
        array_push($targets, ...$own);
        if (!$top && !self::has_concrete($own)) {
            $unanchored = true;
        }
        return $own;
    }

    /**
     * Walk a collection (a list holding arrays, or an object whose values are all arrays, whatever its keys): every
     * element is a record that needs its own concrete target (R4), also when it is empty or has no scalar field; a list
     * inside it is another collection, and a scalar in a mixed list anchors nothing.
     *
     * @param array $collection Collection.
     * @param string $key Name of the collection (lowercase); its elements take it as theirs.
     * @param array $targets Every target found so far.
     * @param bool $unknown Whether an unknown identifier was found so far.
     * @param bool $unanchored Whether a record without a concrete target of its own was found so far.
     * @return void
     */
    private function walk_collection(array $collection, string $key, array &$targets, bool &$unknown, bool &$unanchored): void {
        foreach ($collection as $name => $item) {
            $itemkey = is_int($name) ? $key : strtolower((string) $name);
            if (!is_array($item)) {
                $this->collect($item, $itemkey, $targets, $unknown);
            } else if (!empty($item) && array_is_list($item)) {
                $this->walk_collection($item, $itemkey, $targets, $unknown, $unanchored);
            } else {
                $typed = [];
                if ($this->collect_typed($item, $itemkey, $typed, $unknown)) {
                    array_push($targets, ...$typed);
                    $unanchored = $unanchored || !self::has_concrete($typed);
                } else {
                    $this->walk_record($item, false, $targets, $unknown, $unanchored);
                }
            }
        }
    }

    /**
     * Targets of a field of a record, or null when the value is a collection or a nested record.
     *
     * @param mixed $value Value.
     * @param string $key Its name (lowercase).
     * @param bool $unknown Whether an unknown identifier was found so far.
     * @return array|null
     */
    private function field($value, string $key, bool &$unknown): ?array {
        $own = [];
        if (!is_array($value)) {
            $this->collect($value, $key, $own, $unknown);
            return $own;
        }
        if (empty($value)) {
            return [];
        }
        if ($this->collect_typed($value, $key, $own, $unknown)) {
            return $own;
        }
        if (array_is_list($value) && $this->all_scalars($value)) {
            foreach ($value as $item) {
                $this->collect($item, $key, $own, $unknown);
            }
            return $own;
        }
        return null;
    }

    /**
     * Whether an array is a collection: a non-empty list holding an array, or an object whose values are all arrays.
     *
     * @param array $value Array.
     * @return bool
     */
    private function is_collection(array $value): bool {
        if (empty($value)) {
            return false;
        }
        if (array_is_list($value)) {
            return !$this->all_scalars($value);
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether every value of an array is a scalar or null.
     *
     * @param array $value Array.
     * @return bool
     */
    private function all_scalars(array $value): bool {
        foreach ($value as $item) {
            if (is_array($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a target is the site itself, which names nothing inside the site: the top level of the categories, the
     * system context, and the front page (its course and context, its activities by course module, instance or context,
     * and its groups; R5).
     *
     * @param string $kind Kind of target.
     * @param int $id Its id.
     * @return bool
     */
    private static function is_site_itself(string $kind, int $id): bool {
        global $DB;
        switch ($kind) {
            case 'category':
                return $id === 0;
            case 'course':
                return $id === (int) SITEID;
            case 'context':
                $context = \context::instance_by_id($id, IGNORE_MISSING);
                if (!$context) {
                    return false;
                }
                $coursecontext = $context->get_course_context(false);
                return $context instanceof \context_system
                    || ($coursecontext && (int) $coursecontext->instanceid === (int) SITEID);
            case 'cm':
                return (int) $DB->get_field('course_modules', 'course', ['id' => $id]) === (int) SITEID;
            case 'group':
                return (int) $DB->get_field('groups', 'courseid', ['id' => $id]) === (int) SITEID;
        }
        if (str_starts_with($kind, 'mod:')) {
            $cm = get_coursemodule_from_instance(substr($kind, 4), $id, 0, false, IGNORE_MISSING);
            return $cm && (int) $cm->course === (int) SITEID;
        }
        return false;
    }

    /**
     * Collect the targets of some parameters: [kind, id] pairs, and whether an unknown identifier was found.
     *
     * A value in a list takes the name of the list.
     *
     * @param mixed $value Value to walk.
     * @param string $key Name of the value (lowercase); empty at the top level.
     * @param array $targets Targets found so far.
     * @param bool $unknown Whether an unknown identifier was found so far.
     * @return void
     */
    private function collect($value, string $key, array &$targets, bool &$unknown): void {
        if (is_array($value)) {
            if ($this->collect_typed($value, $key, $targets, $unknown)) {
                return;
            }
            foreach ($value as $name => $item) {
                $this->collect($item, is_int($name) ? $key : strtolower((string) $name), $targets, $unknown);
            }
            return;
        }
        if ($key === '' || in_array($key, self::NOT_TARGETS, true)) {
            return;
        }
        if (in_array($key, self::USERNAME_KEYS, true)) {
            $this->collect_users('username', $value, $targets, $unknown);
            return;
        }
        if (in_array($key, self::EMAIL_KEYS, true)) {
            $this->collect_users('email', $value, $targets, $unknown);
            return;
        }
        $numeric = is_int($value) || (is_string($value) && ctype_digit($value));
        if (in_array($key, self::RECIPIENT_KEYS, true)) {
            // Ids are users; a name, an email or anything else here cannot be verified (RISK-002).
            if ($numeric) {
                $targets[] = ['user', (int) $value];
            } else {
                $unknown = true;
            }
            return;
        }
        if (in_array($key, self::COHORT_KEYS, true)) {
            $this->collect_cohort($numeric ? 'id' : '', $value, $targets, $unknown);
            return;
        }
        $kind = self::kind_of($key);
        if ($kind === null) {
            // Something named like an identifier that is not a known target cannot be verified, whatever its type (R2).
            if (preg_match('/ids?$/', $key)) {
                $unknown = true;
            }
            return;
        }
        if (!$numeric) {
            $unknown = true;
            return;
        }
        $targets[] = [$kind, (int) $value];
    }

    /**
     * Collect a user or a cohort given as {type, value} (core_cohort_add_cohort_members: usertype and cohorttype).
     *
     * @param array $value Value to check.
     * @param string $key Its name (lowercase).
     * @param array $targets Targets found so far.
     * @param bool $unknown Whether an unknown identifier was found so far.
     * @return bool Whether the value was a user or cohort given by type, so it needs no other walk.
     */
    private function collect_typed(array $value, string $key, array &$targets, bool &$unknown): bool {
        if (!array_key_exists('type', $value) || !array_key_exists('value', $value) || count($value) !== 2) {
            return false;
        }
        $type = strtolower((string) $value['type']);
        if (str_contains($key, 'cohort')) {
            $this->collect_cohort(in_array($type, ['id', 'idnumber'], true) ? $type : '', $value['value'], $targets, $unknown);
            return true;
        }
        if (!str_contains($key, 'user')) {
            return false;
        }
        if ($type === 'id') {
            $this->collect($value['value'], 'userid', $targets, $unknown);
        } else if (in_array($type, ['username', 'email', 'idnumber'], true)) {
            $this->collect_users($type, $value['value'], $targets, $unknown);
        } else {
            $unknown = true;
        }
        return true;
    }

    /**
     * Collect the users of a username, email or idnumber: every active local user with it. None is unknown.
     *
     * @param string $field User field: username, email or idnumber.
     * @param mixed $value Its value.
     * @param array $targets Targets found so far.
     * @param bool $unknown Whether an unknown identifier was found so far.
     * @return void
     */
    private function collect_users(string $field, $value, array &$targets, bool &$unknown): void {
        global $CFG, $DB;
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            $unknown = true;
            return;
        }
        $params = ['value' => $field === 'username' ? \core_text::strtolower($value) : $value,
            'mnethostid' => $CFG->mnet_localhost_id];
        $match = $DB->sql_equal($field, ':value', $field !== 'email');
        $ids = $DB->get_fieldset_select('user', 'id', "{$match} AND deleted = 0 AND mnethostid = :mnethostid", $params);
        if (empty($ids)) {
            $unknown = true;
            return;
        }
        foreach ($ids as $id) {
            $targets[] = ['user', (int) $id];
        }
    }

    /**
     * Collect a cohort: the cohort itself (by its context) and up to the member limit of its members, as users.
     *
     * @param string $field Field the cohort is given by: id or idnumber; empty when it is not known.
     * @param mixed $value Its value.
     * @param array $targets Targets found so far.
     * @param bool $unknown Whether an unknown identifier was found so far.
     * @return void
     */
    private function collect_cohort(string $field, $value, array &$targets, bool &$unknown): void {
        global $DB;
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($field === 'id' && !ctype_digit($value)) {
            $field = '';
        }
        $cohort = ($field === '' || $value === '') ? false
            : $DB->get_record('cohort', [$field => $field === 'id' ? (int) $value : $value], 'id, contextid', IGNORE_MULTIPLE);
        if (!$cohort) {
            $unknown = true;
            return;
        }
        $targets[] = ['cohort', (int) $cohort->contextid];
        if ($this->memberlimit === 0) {
            return;
        }
        $sql = 'SELECT userid FROM {cohort_members} WHERE cohortid = :id ORDER BY userid';
        foreach ($DB->get_records_sql($sql, ['id' => $cohort->id], 0, $this->memberlimit) as $member) {
            $targets[] = ['user', (int) $member->userid];
        }
    }

    /**
     * Kind of target of a parameter name: user, course, category, context, cm, group or "mod:<module>"; null for others.
     *
     * @param string $key Parameter name (lowercase).
     * @return string|null
     */
    public static function kind_of(string $key): ?string {
        $kinds = ['user' => self::USER_KEYS, 'course' => self::COURSE_KEYS, 'category' => self::CATEGORY_KEYS,
            'context' => self::CONTEXT_KEYS, 'cm' => self::CM_KEYS, 'group' => self::GROUP_KEYS];
        foreach ($kinds as $kind => $keys) {
            if (in_array($key, $keys, true)) {
                return $kind;
            }
        }
        if (isset(self::INSTANCE_ALIASES[$key])) {
            return 'mod:' . self::INSTANCE_ALIASES[$key];
        }
        $modules = \core_component::get_plugin_list('mod');
        if (preg_match('/^([a-z]+)ids?$/', $key, $matches) && array_key_exists($matches[1], $modules)) {
            return 'mod:' . $matches[1];
        }
        return null;
    }
}
