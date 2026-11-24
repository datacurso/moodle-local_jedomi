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
 * write are read for their targets (by \local_jedomi\local\param_targets, shared with the course gate of ws_runner):
 * users (recipients), courses, categories, contexts, course modules, activity instances and groups. Each target must be
 * inside the context of the agent:
 *
 * - site agent: the whole site; recipients are the creator and users enrolled in a course;
 * - category agent: the category, its subcategories and their courses; recipients are the creator and users enrolled in
 *   one of those courses;
 * - course agent: the course and its activities and groups; recipients are the creator and users enrolled in the course;
 * - user agent: the user context; recipients are the creator and that user.
 *
 * Users may also be given by username or email, or as the members of a cohort (by id or idnumber, also in the
 * {type, value} form of core_cohort_add_cohort_members): they are resolved to user ids, so they are checked and counted as
 * recipients like any other user.
 *
 * Parameters named like recipients but not in the user keys (users, userlist, recipients, members, participants, touser,
 * tousers, to) are user ids when they hold ids; any other value in them cannot be verified (RISK-002). The members of a
 * cohort are read only up to just over write_guard::MAX_RECIPIENTS_PER_RUN, enough to reach the recipient cap (RES-004).
 *
 * Each element of a top-level list of records (such as the categories of core_course_create_categories) must have a
 * concrete target of its own: an element without one makes the whole write UNKNOWN, so a valid element cannot carry an
 * injected one (RISK-001).
 *
 * A target outside the scope makes the write OUT. A parameter that looks like an identifier but is not a known target, a
 * user or cohort that cannot be resolved, or a write without any concrete target cannot be verified and is UNKNOWN
 * (JEDOMI-SEC-014): this applies to site agents too, so a write that names nothing of the site (such as a category
 * created with no parent or at the top level, the front page or the system context) waits for the creator instead of
 * running only because the site agent covers everything.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class write_scope {
    /** @var string Every target is inside the scope. */
    public const IN = 'in';

    /** @var string At least one target is outside the scope. */
    public const OUT = 'out';

    /** @var string The targets cannot be verified. */
    public const UNKNOWN = 'unknown';

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
        // Enough members of a cohort to go over the recipient cap even when one of them is the creator (RES-004).
        $read = (new \local_jedomi\local\param_targets(write_guard::MAX_RECIPIENTS_PER_RUN + 2))->resolve($params);
        // The parameters themselves keep the rule of the whole write: at least one concrete target anywhere.
        ['targets' => $targets, 'unknown' => $unknown, 'unanchored' => $unanchored] = $read;

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
        } else if ($unknown || $unanchored || !\local_jedomi\local\param_targets::has_concrete($targets)) {
            // Nothing ties the write to the context of the agent (JEDOMI-SEC-014), also for a site agent.
            $status = self::UNKNOWN;
        } else {
            $status = self::IN;
        }
        return ['status' => $status, 'recipients' => array_values($recipients)];
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
            case 'cohort':
                // A cohort is checked by its context.
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
