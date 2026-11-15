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

        $this->assertSame(write_scope::IN, $scope->check(['categories' => [['name' => 'x', 'parent' => 0]]])['status']);
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
}
