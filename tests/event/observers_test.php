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

namespace local_jedomi\event;

use aiprovider_datacurso\local\plugin_settings;
use local_jedomi\telegram\api_client;
use local_jedomi\telegram\fake_telegram_api_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for the proactive Telegram notifications: grade notification and scheduled task alert.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\event\observers
 */
final class observers_test extends \advanced_testcase {
    /** @var string Linked Telegram chat id. */
    private const CHATID = '771000077';

    /** @var int Minimum seconds between two proactive notifications to the same chat. */
    private const INTERVAL = 300;

    /** @var int Maximum number of tasks listed in the alert. */
    private const MAX_TASKS = 10;

    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the plugin state is not available.');
        }
        $this->resetAfterTest();
        plugin_settings::set_enabled('local_jedomi', true);
        set_config('telegram_enabled', 1, 'local_jedomi');
        $this->telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($this->telegram);
    }

    #[\Override]
    protected function tearDown(): void {
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Link a user to the test chat.
     *
     * @param int $userid User id.
     * @param int $lastsent Time of the last proactive notification.
     * @return void
     */
    private function link_chat(int $userid, int $lastsent = 0): void {
        global $DB;
        $DB->insert_record('local_jedomi_telegram_links', (object) [
            'userid' => $userid,
            'chatid' => self::CHATID,
            'username' => 'linked',
            'confirmed' => 1,
            'timecreated' => time(),
            'lastproactivesent' => $lastsent,
        ]);
    }

    /**
     * Set the time of the last proactive notification of the test chat.
     *
     * @param int $time Timestamp.
     * @return void
     */
    private function set_last_sent(int $time): void {
        global $DB;
        $DB->set_field('local_jedomi_telegram_links', 'lastproactivesent', $time, ['chatid' => self::CHATID]);
    }

    /**
     * Wait for the start of a new second so the checks run within the same second.
     *
     * @return int The current time.
     */
    private function start_of_a_new_second(): int {
        $now = time();
        while (time() === $now) {
            usleep(10000);
        }
        return time();
    }

    /**
     * Create a linked student and an assignment in a course.
     *
     * @return array{0: \stdClass, 1: \assign, 2: \stdClass} Student, assignment and course.
     */
    private function linked_student_with_assignment(): array {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Biology 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->link_chat((int) $student->id);
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Cell essay']);
        $context = \context_module::instance($instance->cmid);
        $assign = new \assign($context, get_coursemodule_from_id('assign', $instance->cmid), $course);
        return [$student, $assign, $course];
    }

    /**
     * Fire the graded event for a student.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $student Student.
     * @return void
     */
    private function grade(\assign $assign, \stdClass $student): void {
        $grade = $assign->get_user_grade((int) $student->id, true);
        observers::submission_graded(\mod_assign\event\submission_graded::create_from_grade($assign, $grade));
    }

    /**
     * MDL-UNIT-064: the grade notification names the assignment and the course.
     * MDL-INT-071: the graded linked student receives the notification with the assignment and the course.
     *
     * @return void
     */
    public function test_grade_notification_names_the_assignment_and_the_course(): void {
        [$student, $assign] = $this->linked_student_with_assignment();

        $this->grade($assign, $student);

        $this->assertCount(1, $this->telegram->sent);
        $this->assertSame(self::CHATID, (string) $this->telegram->sent[0]['chatid']);
        $this->assertStringContainsString('Cell essay', $this->telegram->sent[0]['text']);
        $this->assertStringContainsString('Biology 101', $this->telegram->sent[0]['text']);
    }

    /**
     * MDL-UNIT-064: a second notification within the minimum interval is dropped and one after it is sent.
     * MDL-INT-071: no more than one notification is sent within the minimum interval.
     *
     * @return void
     */
    public function test_second_grade_notification_respects_the_minimum_interval(): void {
        [$student, $assign] = $this->linked_student_with_assignment();

        $this->grade($assign, $student);
        $this->grade($assign, $student);
        $this->assertCount(1, $this->telegram->sent);

        $this->set_last_sent(time() - self::INTERVAL * 2);
        $this->grade($assign, $student);
        $this->assertCount(2, $this->telegram->sent);
    }

    /**
     * MDL-UNIT-064: one second before the end of the interval the notification is dropped; exactly at the end it is sent.
     *
     * @return void
     */
    public function test_grade_notification_at_the_interval_limit(): void {
        [$student, $assign] = $this->linked_student_with_assignment();

        $now = $this->start_of_a_new_second();
        $this->set_last_sent($now - self::INTERVAL + 1);
        $this->grade($assign, $student);
        $this->assertCount(0, $this->telegram->sent);

        $now = $this->start_of_a_new_second();
        $this->set_last_sent($now - self::INTERVAL);
        $this->grade($assign, $student);
        $this->assertCount(1, $this->telegram->sent);
    }

    /**
     * Leave every scheduled task healthy and enabled.
     *
     * @return void
     */
    private function all_tasks_healthy(): void {
        global $DB;
        $DB->set_field('task_scheduled', 'faildelay', 0);
        $DB->set_field('task_scheduled', 'disabled', 0);
    }

    /**
     * Make some scheduled tasks fail.
     *
     * @param int $count Number of failing tasks.
     * @return string[] Short class names of the failing tasks.
     */
    private function failing_tasks(int $count): array {
        global $DB;
        $tasks = $DB->get_records('task_scheduled', null, 'id', 'id, classname', 0, $count);
        $this->assertCount($count, $tasks);
        $names = [];
        foreach ($tasks as $task) {
            $DB->set_field('task_scheduled', 'faildelay', 120, ['id' => $task->id]);
            $names[] = basename(str_replace('\\', '/', $task->classname));
        }
        return $names;
    }

    /**
     * Log the linked admin in through the observer.
     *
     * @return string[] Texts sent to Telegram.
     */
    private function admin_logs_in(): array {
        $admin = get_admin();
        observers::user_loggedin(\core\event\user_loggedin::create([
            'userid' => $admin->id,
            'objectid' => $admin->id,
            'other' => ['username' => $admin->username],
        ]));
        return array_column($this->telegram->sent, 'text');
    }

    /**
     * MDL-UNIT-065: the alert lists the scheduled tasks that are failing.
     * MDL-INT-073: a linked administrator who logs in with failing tasks receives the alert.
     *
     * @return void
     */
    public function test_alert_lists_the_failing_tasks(): void {
        $this->link_chat((int) get_admin()->id);
        $this->all_tasks_healthy();
        $names = $this->failing_tasks(3);

        $texts = $this->admin_logs_in();

        $this->assertCount(1, $texts);
        foreach ($names as $name) {
            $this->assertStringContainsString($name, $texts[0]);
        }
    }

    /**
     * MDL-UNIT-065: the list of tasks in the alert is limited to the defined maximum.
     * MDL-INT-073: the alert has a bounded list of tasks.
     *
     * @return void
     */
    public function test_alert_list_is_limited_to_the_maximum(): void {
        $this->link_chat((int) get_admin()->id);
        $this->all_tasks_healthy();
        $this->failing_tasks(self::MAX_TASKS + 5);

        $texts = $this->admin_logs_in();

        $this->assertCount(1, $texts);
        $this->assertSame(self::MAX_TASKS, substr_count($texts[0], '•'));
    }

    /**
     * MDL-UNIT-065: the maximum number of failing tasks is listed in full.
     *
     * @return void
     */
    public function test_alert_lists_every_task_at_the_maximum(): void {
        $this->link_chat((int) get_admin()->id);
        $this->all_tasks_healthy();
        $this->failing_tasks(self::MAX_TASKS);

        $texts = $this->admin_logs_in();

        $this->assertSame(self::MAX_TASKS, substr_count($texts[0], '•'));
    }

    /**
     * MDL-UNIT-065: without tasks with problems no alert is sent.
     *
     * @return void
     */
    public function test_no_alert_without_tasks_with_problems(): void {
        $this->link_chat((int) get_admin()->id);
        $this->all_tasks_healthy();

        $this->assertSame([], $this->admin_logs_in());
    }

    /**
     * MDL-UNIT-092: tasks disabled on purpose are not in the alert, and the alert asks for no answer the bot ignores.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_alert_excludes_tasks_disabled_on_purpose(): void {
        $this->markTestSkipped('Pending fix: the alert lists tasks disabled on purpose, is fixed in English and asks '
            . 'for a FIX answer the bot does not handle.');
    }
    /**
     * Create a linked student and an assignment with the given settings, and grade the student.
     *
     * @param array $settings Assignment settings.
     * @return array{0: \stdClass, 1: \assign, 2: \stdClass} Student, assignment and its module record.
     */
    private function graded_student(array $settings): array {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Biology 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->link_chat((int) $student->id);
        $instance = $this->getDataGenerator()->create_module(
            'assign',
            ['course' => $course->id, 'name' => 'Cell essay'] + $settings
        );
        $context = \context_module::instance($instance->cmid);
        $assign = new \assign($context, get_coursemodule_from_id('assign', $instance->cmid), $course);
        return [$student, $assign, $instance];
    }

    /**
     * MDL-INT-072: a grade hidden from the student is not notified.
     *
     * @return void
     */
    public function test_hidden_grade_is_not_notified(): void {
        [$student, $assign, $instance] = $this->graded_student([]);
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $instance->id, 'courseid' => $instance->course]);
        $gradeitem->set_hidden(1);

        $this->grade($assign, $student);

        $this->assertSame([], $this->telegram->sent, 'The student cannot see the grade yet');
    }

    /**
     * MDL-INT-072: with the marking workflow, a grade not yet released is not notified.
     *
     * @return void
     */
    public function test_grade_not_released_in_the_marking_workflow_is_not_notified(): void {
        [$student, $assign] = $this->graded_student(['markingworkflow' => 1]);

        $this->grade($assign, $student);

        $this->assertSame([], $this->telegram->sent, 'The grade is not released to the student');
    }

    /**
     * MDL-INT-072: with anonymous submissions whose identities are not revealed, the grade is not notified.
     *
     * @return void
     */
    public function test_grade_with_anonymous_submissions_is_not_notified(): void {
        [$student, $assign] = $this->graded_student(['blindmarking' => 1]);

        $this->grade($assign, $student);

        $this->assertSame([], $this->telegram->sent, 'The anonymous grade is not visible to the student yet');
    }

    /**
     * MDL-INT-072: the minimum interval of a notification does not drop the notification of another assignment.
     *
     * @return void
     */
    public function test_interval_of_one_assignment_does_not_drop_another_assignment(): void {
        [$student, $first, $course] = $this->linked_student_with_assignment();
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Lab report']);
        $second = new \assign(
            \context_module::instance($instance->cmid),
            get_coursemodule_from_id('assign', $instance->cmid),
            $course
        );

        $this->grade($first, $student);
        $this->grade($second, $student);

        $texts = array_column($this->telegram->sent, 'text');
        $this->assertCount(2, $texts, 'Each assignment gets its notification');
        $this->assertStringContainsString('Lab report', $texts[1]);
    }
    /**
     * MDL-INT-081: queries, confirmed actions, agent creation and deletion and Telegram linking are logged as Moodle
     * events with user, context and time.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_assistant_activity_is_logged_as_events(): void {
        $this->markTestSkipped('Pending implementation: the plugin triggers no Moodle events.');
    }

    /**
     * MDL-INT-105: a linked student whose role is blocked for Telegram or whose account is suspended gets no grade
     * notification.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_grade_notification_follows_the_role_and_account_rules(): void {
        $this->markTestSkipped('Pending fix: a student with the role blocked for Telegram or the account suspended still '
            . 'gets the grade notification.');
    }

    /**
     * MDL-INT-107: regrading a submission several times after the minimum interval does not repeat the grade
     * notification.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_grade_notification_is_not_repeated_on_each_regrade(): void {
        $this->markTestSkipped('Pending fix: each regrade notifies again once the minimum interval has passed.');
    }
}
