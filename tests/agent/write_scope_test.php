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
 * Tests for the fixed scope of the approved writes of an agent.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\write_scope
 */
final class write_scope_test extends \advanced_testcase {
    /**
     * Agent record of a context.
     *
     * @param int $userid Creator.
     * @param string $type Context type.
     * @param int|null $contextid Moodle context id.
     * @return \stdClass
     */
    private function agent(int $userid, string $type, ?int $contextid): \stdClass {
        return (object) ['id' => 7, 'userid' => $userid, 'context_type' => $type, 'context_id' => $contextid];
    }

    /**
     * JEDOMI-SEC-001: a course agent may write to the creator and to the users enrolled in its course, and to its course,
     * activities and groups; other courses and users outside the course are out of scope.
     */
    public function test_course_agent_scope_is_its_course_and_its_users(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $other = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $student = $gen->create_and_enrol($course, 'student');
        $outsider = $gen->create_and_enrol($other, 'student');
        $forum = $gen->create_module('forum', ['course' => $course->id]);
        $otherforum = $gen->create_module('forum', ['course' => $other->id]);
        $group = $gen->create_group(['courseid' => $course->id]);
        $scope = new write_scope($this->agent((int) $teacher->id, 'course', (int) \context_course::instance($course->id)->id));

        $message = static fn(int $userid): array => ['messages' => [['touserid' => $userid, 'text' => 'Hi']]];
        $tostudent = $scope->check($message((int) $student->id));
        $this->assertSame(write_scope::IN, $tostudent['status']);
        $this->assertSame([(int) $student->id], $tostudent['recipients']);
        $this->assertSame(write_scope::IN, $scope->check($message((int) $teacher->id))['status'], 'The creator is allowed');
        $this->assertSame([], $scope->check($message((int) $teacher->id))['recipients'], 'The creator is not counted');
        $this->assertSame(write_scope::OUT, $scope->check($message((int) $outsider->id))['status']);

        $this->assertSame(write_scope::IN, $scope->check(['courseid' => $course->id, 'name' => 'x'])['status']);
        $this->assertSame(write_scope::OUT, $scope->check(['courseid' => $other->id])['status']);
        $this->assertSame(write_scope::IN, $scope->check(['forumid' => $forum->id, 'subject' => 's'])['status']);
        $this->assertSame(write_scope::OUT, $scope->check(['forumid' => $otherforum->id])['status']);
        $this->assertSame(write_scope::IN, $scope->check(['cmid' => $forum->cmid])['status']);
        $this->assertSame(write_scope::IN, $scope->check(['groupid' => $group->id])['status']);
        $this->assertSame(write_scope::OUT, $scope->check(['categoryid' => $course->category])['status']);
        $this->assertSame(
            write_scope::IN,
            $scope->check(['contextid' => \context_module::instance($forum->cmid)->id])['status']
        );
        $this->assertSame(write_scope::OUT, $scope->check(['contextid' => \context_system::instance()->id])['status']);
    }

    /**
     * JEDOMI-SEC-001: arguments the scope cannot verify (an unknown identifier, no target at all) are not in scope, so
     * the write waits for the creator instead of running.
     */
    public function test_unverifiable_arguments_are_unknown(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $scope = new write_scope($this->agent((int) $teacher->id, 'course', (int) \context_course::instance($course->id)->id));

        $this->assertSame(write_scope::UNKNOWN, $scope->check(['postid' => 5, 'subject' => 's'])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['categories' => [['name' => 'x', 'parentx' => 'a']]])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['courses' => [['id' => $course->id]]])['status']);
        $this->assertSame(
            write_scope::IN,
            $scope->check(['messages' => [['touserid' => $teacher->id, 'text' => 'x', 'clientmsgid' => '99']]])['status'],
            'A client message id is not a target'
        );
    }

    /**
     * JEDOMI-SEC-001: a site agent covers the whole site, but only sends to the creator and to users enrolled in a course.
     */
    public function test_site_agent_scope_is_the_site_and_enrolled_users(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $loner = $gen->create_user();
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));

        $this->assertSame(
            write_scope::IN,
            $scope->check(['categories' => [['name' => 'x', 'parent' => $course->category]]])['status']
        );
        $this->assertSame(write_scope::IN, $scope->check(['courseid' => $course->id])['status']);
        $this->assertSame(write_scope::IN, $scope->check(['touserid' => $student->id])['status']);
        $this->assertSame(write_scope::OUT, $scope->check(['touserid' => $loner->id])['status']);
        $this->assertSame(write_scope::OUT, $scope->check(['courseid' => 999999])['status'], 'A missing course is never in scope');
    }

    /**
     * JEDOMI-SEC-001: a category agent covers the courses of its category and their users.
     */
    public function test_category_agent_scope_is_its_category(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $category = $gen->create_category();
        $othercategory = $gen->create_category();
        $inside = $gen->create_course(['category' => $category->id]);
        $outside = $gen->create_course(['category' => $othercategory->id]);
        $student = $gen->create_and_enrol($inside, 'student');
        $outsider = $gen->create_and_enrol($outside, 'student');
        $scope = new write_scope($this->agent(
            (int) get_admin()->id,
            'category',
            (int) \context_coursecat::instance($category->id)->id
        ));

        $this->assertSame(write_scope::IN, $scope->check(['courseid' => $inside->id])['status']);
        $this->assertSame(write_scope::OUT, $scope->check(['courseid' => $outside->id])['status']);
        $this->assertSame(write_scope::IN, $scope->check(['touserid' => $student->id])['status']);
        $this->assertSame(write_scope::OUT, $scope->check(['touserid' => $outsider->id])['status']);
    }

    /**
     * JEDOMI-SEC-014 (RE-POC-2c): a site agent no longer treats a write without a concrete target as inside its scope.
     * A write with no target, or whose only targets are the site itself (the top level of the categories, the front
     * page, the system context), cannot be tied to the context of the agent and is UNKNOWN, so it waits for the creator.
     */
    public function test_site_agent_write_without_a_concrete_target_is_unknown(): void {
        $this->resetAfterTest();
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));

        $this->assertSame(write_scope::UNKNOWN, $scope->check(['categories' => [['name' => 'AUDIT-INJECTED']]])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['categories' => [['name' => 'x', 'parent' => 0]]])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['courseid' => SITEID, 'name' => 'x'])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['contextid' => \context_system::instance()->id])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check([])['status']);
    }

    /**
     * RISK-001: recipients given by username, by email or as the members of a cohort are resolved to users, checked
     * against the scope and returned as recipients, so they count toward the recipient cap of the run.
     */
    public function test_recipients_by_username_email_and_cohort_are_scoped_and_counted(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $classmate = $gen->create_and_enrol($course, 'student');
        $loner = $gen->create_user();
        $cohort = $gen->create_cohort(['idnumber' => 'class-a']);
        cohort_add_member($cohort->id, $student->id);
        cohort_add_member($cohort->id, $classmate->id);
        $mixed = $gen->create_cohort(['idnumber' => 'mixed']);
        cohort_add_member($mixed->id, $student->id);
        cohort_add_member($mixed->id, $loner->id);
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));

        $byusername = $scope->check(['users' => [['username' => $student->username]]]);
        $this->assertSame(write_scope::IN, $byusername['status']);
        $this->assertSame([(int) $student->id], $byusername['recipients']);
        $this->assertSame(write_scope::OUT, $scope->check(['username' => $loner->username])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['username' => 'nobody-here'])['status']);

        $byemail = $scope->check(['email' => \core_text::strtoupper($student->email)]);
        $this->assertSame(write_scope::IN, $byemail['status']);
        $this->assertSame([(int) $student->id], $byemail['recipients']);
        $this->assertSame(write_scope::OUT, $scope->check(['email' => $loner->email])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['email' => 'nobody@example.invalid'])['status']);

        $members = $scope->check(['cohortid' => $cohort->id]);
        $this->assertSame(write_scope::IN, $members['status']);
        $this->assertEqualsCanonicalizing([(int) $student->id, (int) $classmate->id], $members['recipients']);
        $this->assertSame(write_scope::OUT, $scope->check(['cohortids' => [$mixed->id]])['status']);

        // Shape of core_cohort_add_cohort_members: the cohort and the user are given by type and value.
        $add = $scope->check(['members' => [[
            'cohorttype' => ['type' => 'idnumber', 'value' => $cohort->idnumber],
            'usertype' => ['type' => 'username', 'value' => $classmate->username],
        ]]]);
        $this->assertSame(write_scope::IN, $add['status']);
        $this->assertEqualsCanonicalizing([(int) $student->id, (int) $classmate->id], $add['recipients']);
        $this->assertSame(write_scope::OUT, $scope->check(['members' => [[
            'cohorttype' => ['type' => 'id', 'value' => $cohort->id],
            'usertype' => ['type' => 'email', 'value' => $loner->email],
        ]]])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['members' => [[
            'cohorttype' => ['type' => 'id', 'value' => $cohort->id],
            'usertype' => ['type' => 'nickname', 'value' => 'x'],
        ]]])['status']);
    }

    /**
     * RISK-001: in a course agent a recipient given by username or email must be enrolled in the course.
     */
    public function test_course_agent_recipients_by_username_or_email_must_be_enrolled(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $student = $gen->create_and_enrol($course, 'student');
        $outsider = $gen->create_and_enrol($gen->create_course(), 'student');
        $scope = new write_scope($this->agent((int) $teacher->id, 'course', (int) \context_course::instance($course->id)->id));

        $this->assertSame(write_scope::IN, $scope->check(['username' => $student->username])['status']);
        $this->assertSame(write_scope::OUT, $scope->check(['username' => $outsider->username])['status']);
        $this->assertSame(write_scope::OUT, $scope->check(['email' => $outsider->email])['status']);
    }

    /**
     * RISK-001 (C1): each element of a list of records needs its own concrete target inside the scope. An injected
     * element without a target, or at the top level, next to a valid one makes the whole write UNKNOWN; a list where
     * every element has a concrete parent is IN.
     */
    public function test_every_element_of_a_list_needs_its_own_concrete_target(): void {
        $this->resetAfterTest();
        $parent = $this->getDataGenerator()->create_category();
        $other = $this->getDataGenerator()->create_category();
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));
        $valid = ['name' => 'y', 'parent' => (int) $parent->id];

        $toplevel = ['categories' => [['name' => 'AUDIT-INJECTED', 'parent' => 0], $valid]];
        $noparent = ['categories' => [['name' => 'AUDIT-INJECTED'], $valid]];
        $allvalid = ['categories' => [$valid, ['name' => 'z', 'parent' => (int) $other->id]]];

        $this->assertSame(write_scope::UNKNOWN, $scope->check($toplevel)['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check($noparent)['status']);
        $this->assertSame(write_scope::IN, $scope->check($allvalid)['status']);
    }

    /**
     * JEDOMI-SEC-014: a site agent covers the whole site, so a category created under an existing category is inside
     * its scope and runs when the function is approved.
     */
    public function test_site_agent_category_under_an_existing_category_is_in_scope(): void {
        $this->resetAfterTest();
        $parent = $this->getDataGenerator()->create_category();
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));

        $this->assertSame(
            write_scope::IN,
            $scope->check(['categories' => [['name' => 'X', 'parent' => (int) $parent->id]]])['status']
        );
    }

    /**
     * RISK-002 (C5): parameters named like recipients (users, userlist, recipients, members, participants, touser,
     * tousers, to) are resolved as user ids when they hold ids, so they are scoped and counted; any other value in them
     * cannot be verified and makes the write UNKNOWN.
     */
    public function test_recipient_like_parameters_are_scoped_or_unknown(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $loner = $gen->create_user();
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));

        foreach (['users', 'userlist', 'recipients', 'members', 'participants', 'touser', 'tousers', 'to'] as $key) {
            $in = $scope->check([$key => [(int) $student->id], 'courseid' => $course->id]);
            $this->assertSame(write_scope::IN, $in['status'], $key);
            $this->assertSame([(int) $student->id], $in['recipients'], $key);
            $this->assertSame(write_scope::OUT, $scope->check([$key => (string) $loner->id])['status'], $key);
            $this->assertSame(
                write_scope::UNKNOWN,
                $scope->check([$key => [$student->username], 'courseid' => $course->id])['status'],
                $key
            );
        }
    }

    /**
     * RES-004 (C7): the members of a cohort are resolved only up to just over the recipient cap, enough to reach it,
     * without a lookup per member of a large cohort.
     */
    public function test_large_cohort_members_are_resolved_only_up_to_the_cap(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $cohort = $gen->create_cohort();
        for ($i = 0; $i < write_guard::MAX_RECIPIENTS_PER_RUN + 10; $i++) {
            cohort_add_member($cohort->id, $gen->create_and_enrol($course, 'student')->id);
        }
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));

        $result = $scope->check(['cohortid' => $cohort->id]);

        $this->assertSame(write_scope::IN, $result['status']);
        $this->assertGreaterThan(write_guard::MAX_RECIPIENTS_PER_RUN, count($result['recipients']));
        $this->assertLessThanOrEqual(write_guard::MAX_RECIPIENTS_PER_RUN + 2, count($result['recipients']));
    }

    /**
     * FIX-001: the anchor rule applies to every collection of records at any depth, whatever its keys: an object-keyed
     * collection, a list nested in a record and a wrapper object holding the list cannot carry an injected record.
     * Mixed lists, empty records and records without a target key are not anchored either.
     */
    public function test_every_record_of_every_collection_needs_its_own_target(): void {
        $this->resetAfterTest();
        $parent = (int) $this->getDataGenerator()->create_category()->id;
        $course = (int) $this->getDataGenerator()->create_course()->id;
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));
        $injected = ['name' => 'AUDIT-INJECTED', 'parent' => 0];
        $valid = ['name' => 'y', 'parent' => $parent];

        $held = [
            'object-keyed collection' => ['categories' => ['a' => $injected, 'b' => $valid]],
            'list nested in a record' => ['categories' => [$valid + ['children' => [$injected]]]],
            'wrapper object' => ['data' => ['categories' => [$injected, $valid]]],
            'object-keyed wrapper' => ['data' => ['x' => ['categories' => ['k' => $injected, 'l' => $valid]]]],
            'record without a target key' => ['categories' => [$valid, ['name' => 'INJ']]],
            'empty record' => ['categories' => [$valid, []]],
            'mixed list of scalars and records' => ['categories' => [$parent, ['name' => 'INJ']]],
            'record anchored only by its ancestor' => ['courseid' => $course, 'categories' => [$valid + ['items' => [
                ['name' => 'INJ'],
            ]]]],
            'typed pair under an unknown key' => ['categories' => [$valid + ['extra' => ['type' => 'x', 'value' => 'y']]]],
        ];
        foreach ($held as $label => $params) {
            $this->assertSame(write_scope::UNKNOWN, $scope->check($params)['status'], $label);
        }

        $nested = $valid + ['children' => [['name' => 'z', 'parent' => $parent]]];
        $in = [
            'object-keyed collection' => ['categories' => ['a' => $valid, 'b' => $valid]],
            'nested list' => ['categories' => [$nested]],
            'wrapper object' => ['data' => ['categories' => [$valid, $valid]]],
            'empty option list of a record' => ['categories' => [$valid + ['options' => []]]],
        ];
        foreach ($in as $label => $params) {
            $this->assertSame(write_scope::IN, $scope->check($params)['status'], $label);
        }
    }

    /**
     * R2: any parameter named like an identifier (ending in id or ids) that is not a known target is unknown, whatever
     * its type, so a float or a boolean cannot slip an identifier past the scope.
     */
    public function test_unknown_identifier_keys_are_unknown_whatever_their_type(): void {
        $this->resetAfterTest();
        $course = (int) $this->getDataGenerator()->create_course()->id;
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));

        foreach ([99, 99.0, '99', true, 'abc', [1, 2]] as $value) {
            $params = ['courses' => [['id' => $value, 'courseid' => $course]]];
            $this->assertSame(write_scope::UNKNOWN, $scope->check($params)['status'], var_export($value, true));
            $this->assertSame(write_scope::UNKNOWN, $scope->check(['roleid' => $value, 'courseid' => $course])['status']);
        }
    }

    /**
     * R4 (RISK-002): every element of a collection, list or object-keyed and at any depth, is a record that needs its
     * own concrete target, also when it has no scalar field or is empty.
     */
    public function test_every_element_of_a_collection_is_a_record(): void {
        $this->resetAfterTest();
        $parent = (int) $this->getDataGenerator()->create_category()->id;
        $course = (int) $this->getDataGenerator()->create_course(['category' => $parent])->id;
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));

        $this->assertSame(write_scope::UNKNOWN, $scope->check(['courseid' => $course, 'items' => [['names' => ['x']]]])['status']);
        $this->assertSame(
            write_scope::UNKNOWN,
            $scope->check(['categories' => ['a' => ['name' => 'x', 'parent' => $parent], 'b' => []]])['status']
        );
        $this->assertSame(
            write_scope::UNKNOWN,
            $scope->check(['categories' => ['a' => ['name' => 'x', 'parent' => $parent], 'b' => ['tags' => ['x']]]])['status']
        );
        $this->assertSame(
            write_scope::IN,
            $scope->check(['categories' => ['a' => ['name' => 'x', 'parent' => $parent]]])['status']
        );
    }

    /**
     * R5 (RISK-003): the front page is the site itself: its context, its activities (by course module or instance) and
     * its groups name nothing concrete, so a site agent holds a write whose only target is one of them.
     */
    public function test_front_page_targets_are_the_site_itself(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $forum = $gen->create_module('forum', ['course' => SITEID]);
        $group = $gen->create_group(['courseid' => SITEID]);
        $scope = new write_scope($this->agent((int) get_admin()->id, 'site', null));

        $this->assertSame(write_scope::UNKNOWN, $scope->check(['contextid' => \context_course::instance(SITEID)->id])['status']);
        $this->assertSame(
            write_scope::UNKNOWN,
            $scope->check(['contextid' => \context_module::instance($forum->cmid)->id])['status']
        );
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['cmid' => $forum->cmid, 'subject' => 'x'])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['forumid' => $forum->id, 'subject' => 'x'])['status']);
        $this->assertSame(write_scope::UNKNOWN, $scope->check(['groupid' => $group->id])['status']);
    }

    /**
     * REL-009: an agent whose context was deleted has no scope left: no write is in scope, not even one to its creator or
     * to the course it belonged to.
     */
    public function test_agent_of_a_deleted_context_has_nothing_in_scope(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $student = $gen->create_and_enrol($course, 'student');
        $contextid = (int) \context_course::instance($course->id)->id;
        delete_course($course, false);

        $scope = new write_scope($this->agent((int) $teacher->id, 'course', $contextid));

        $message = static fn(int $userid): array => ['messages' => [['touserid' => $userid, 'text' => 'Hi']]];
        $this->assertNotSame(write_scope::IN, $scope->check($message((int) $teacher->id))['status']);
        $this->assertNotSame(write_scope::IN, $scope->check($message((int) $student->id))['status']);
        $this->assertNotSame(write_scope::IN, $scope->check(['courseid' => $course->id, 'name' => 'x'])['status']);
        $this->assertNotSame(write_scope::IN, $scope->check(['contextid' => \context_system::instance()->id])['status']);
    }
}
