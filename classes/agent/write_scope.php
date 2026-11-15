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

namespace local_jedomi\agent;

/**
 * Fixed scope of the approved writes of an agent: its Moodle context and its allowed recipients.
 *
 * The scope comes from the agent record (its creator and context), never from the AI. The parameters of a web service
 * write are read for their targets: users (recipients), courses, categories, contexts, course modules, activity
 * instances and groups. Each target must be inside the context of the agent:
 *
 * - site agent: the whole site; recipients are the creator and users enrolled in a course;
 * - category agent: the category, its subcategories and their courses; recipients are the creator and users enrolled in
 *   one of those courses;
 * - course agent: the course and its activities and groups; recipients are the creator and users enrolled in the course;
 * - user agent: the user context; recipients are the creator and that user.
 *
 * A target outside the scope makes the write OUT. A parameter that looks like an identifier but is not a known target,
 * or a write without any target (except for a site agent), cannot be verified and is UNKNOWN.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class write_scope {
    /** @var string Every target is inside the scope. */
    public const IN = 'in';

    /** @var string At least one target is outside the scope. */
    public const OUT = 'out';

    /** @var string The targets cannot be verified. */
    public const UNKNOWN = 'unknown';

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

    /** @var string[] Parameter names that end like an identifier but are not a Moodle record. */
    private const NOT_TARGETS = ['clientmsgid', 'idnumber'];

    /** @var int Creator of the agent. */
    private int $creatorid;

    /** @var string Context type of the agent. */
    private string $type;

    /** @var \context|null Context of the agent; null when it no longer exists. */
    private ?\context $context;

    /**
     * Scope of an agent.
     *
     * @param \stdClass $agent Agent record (userid, context_type, context_id).
     */
    public function __construct(\stdClass $agent) {
        $this->creatorid = (int) $agent->userid;
        $this->type = (string) ($agent->context_type ?? 'site') ?: 'site';
        if ($this->type === 'site') {
            $this->context = \context_system::instance();
        } else {
            $context = empty($agent->context_id) ? false : \context::instance_by_id((int) $agent->context_id, IGNORE_MISSING);
            $this->context = $context ?: null;
        }
    }

    /**
     * Check the parameters of a web service write against the scope.
     *
     * @param array $params Parameters of the function, by name.
     * @return array ['status' => IN, OUT or UNKNOWN, 'recipients' => int[] distinct users other than the creator].
     */
    public function check(array $params): array {
        $targets = [];
        $unknown = false;
        $this->collect($params, '', $targets, $unknown);

        $recipients = [];
        $out = $this->context === null;
        foreach ($targets as [$kind, $id]) {
            if ($kind === 'user') {
                if ($id !== $this->creatorid) {
                    $recipients[$id] = $id;
                }
                $out = $out || !$this->user_in_scope($id);
            } else {
                $out = $out || !$this->target_in_scope($kind, $id);
            }
        }

        if ($out) {
            $status = self::OUT;
        } else if ($unknown || (empty($targets) && $this->type !== 'site')) {
            $status = self::UNKNOWN;
        } else {
            $status = self::IN;
        }
        return ['status' => $status, 'recipients' => array_values($recipients)];
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
            foreach ($value as $name => $item) {
                $this->collect($item, is_int($name) ? $key : strtolower((string) $name), $targets, $unknown);
            }
            return;
        }
        if ($key === '' || in_array($key, self::NOT_TARGETS, true)) {
            return;
        }
        $kind = $this->kind_of($key);
        $numeric = is_int($value) || (is_string($value) && ctype_digit($value));
        if ($kind === null) {
            // Something named like an identifier that is not a known target cannot be verified.
            if ($numeric && preg_match('/ids?$/', $key)) {
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
     * Kind of target of a parameter name: user, course, category, context, cm, group or "mod:<module>"; null for others.
     *
     * @param string $key Parameter name (lowercase).
     * @return string|null
     */
    private function kind_of(string $key): ?string {
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

    /**
     * Whether a target other than a user is inside the context of the agent.
     *
     * @param string $kind Kind of target.
     * @param int $id Its id.
     * @return bool
     */
    private function target_in_scope(string $kind, int $id): bool {
        switch ($kind) {
            case 'course':
                if ($id === SITEID) {
                    return $this->type === 'site';
                }
                return $this->context_in_scope(\context_course::instance($id, IGNORE_MISSING));
            case 'category':
                if ($id === 0) {
                    // The top level of the categories belongs to the site only.
                    return $this->type === 'site';
                }
                return $this->context_in_scope(\context_coursecat::instance($id, IGNORE_MISSING));
            case 'context':
                return $this->context_in_scope(\context::instance_by_id($id, IGNORE_MISSING));
            case 'cm':
                return $this->context_in_scope(\context_module::instance($id, IGNORE_MISSING));
            case 'group':
                $group = groups_get_group($id, 'id, courseid');
                return $group && $this->target_in_scope('course', (int) $group->courseid);
        }
        // An activity instance, such as forumid.
        $cm = get_coursemodule_from_instance(substr($kind, 4), $id, 0, false, IGNORE_MISSING);
        return $cm && $this->context_in_scope(\context_module::instance((int) $cm->id, IGNORE_MISSING));
    }

    /**
     * Whether a context is the context of the agent or inside it.
     *
     * @param \context|false $context Context, or false when it does not exist.
     * @return bool
     */
    private function context_in_scope($context): bool {
        if (!$context || $this->context === null) {
            return false;
        }
        if ($this->context instanceof \context_system || (int) $context->id === (int) $this->context->id) {
            return true;
        }
        return in_array((int) $this->context->id, array_map('intval', $context->get_parent_context_ids()), true);
    }

    /**
     * Whether a user may receive a write of the agent: the creator, or an active user of the agent context.
     *
     * @param int $userid User id.
     * @return bool
     */
    private function user_in_scope(int $userid): bool {
        if ($userid === $this->creatorid) {
            return true;
        }
        $user = \core_user::get_user($userid, 'id, deleted, suspended');
        if (!$user || !empty($user->deleted) || !empty($user->suspended) || isguestuser($userid) || $this->context === null) {
            return false;
        }
        if ($this->context instanceof \context_user) {
            return (int) $this->context->instanceid === $userid;
        }
        if ($this->context instanceof \context_course) {
            return is_enrolled($this->context, $userid, '', true);
        }
        foreach (enrol_get_all_users_courses($userid, true, ['id']) as $course) {
            if ($this->context_in_scope(\context_course::instance((int) $course->id, IGNORE_MISSING))) {
                return true;
            }
        }
        return false;
    }
}
