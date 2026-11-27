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

namespace local_jedomi\event;

use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\messaging\fake_plugin_state;
use local_jedomi\messaging\plugin_state;
use local_jedomi\telegram\api_client;
use local_jedomi\task\send_whatsapp_grade_notification;
use local_jedomi\telegram\fake_telegram_api_client;
use local_jedomi\whatsapp\cloud_api_client;
use local_jedomi\whatsapp\consent;
use local_jedomi\whatsapp\fake_cloud_api_client;
use local_jedomi\whatsapp\manager;
use local_jedomi\whatsapp\templates;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_cloud_api_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for the grade notification on WhatsApp: the observer of submission_graded hands it to the notification
 * dispatcher, which sends the grade_posted template to a linked student who accepted the grades category and did not
 * stop the messages, within the minimum interval between proactive messages. Nothing reaches Meta or Telegram, and the
 * Datacurso AI provider is not needed.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\event\observers
 * @covers     \local_jedomi\local\service\notification_dispatcher
 * @covers     \local_jedomi\task\send_whatsapp_grade_notification
 * @covers     \local_jedomi\whatsapp\template_sender
 * @covers     ::local_jedomi_whatsapp_recipient
 */
final class whatsapp_grade_notification_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Phone of the student. */
    private const WAID = '16505551234';

    /** @var int Minimum seconds between two proactive notifications to the same student. */
    private const INTERVAL = 300;

    /** @var fake_cloud_api_client WhatsApp double. */
    private fake_cloud_api_client $client;

    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        set_config('whatsapp_enabled', 1, 'local_jedomi');
        set_config('telegram_enabled', 1, 'local_jedomi');
        $this->client = new fake_cloud_api_client();
        cloud_api_client::set_instance_for_testing($this->client);
        $this->telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($this->telegram);
    }

    #[\Override]
    protected function tearDown(): void {
        cloud_api_client::set_instance_for_testing(null);
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Link a student on WhatsApp.
     *
     * @param int $userid User id.
     * @param array $fields Fields that replace the default ones.
     */
    private function link(int $userid, array $fields = []): void {
        global $DB;
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) array_merge(['userid' => $userid,
            'bsuid' => self::BSUID, 'waid' => self::WAID, 'phone' => '+' . self::WAID, 'confirmed' => 1,
            'consentat' => time() - DAYSECS, 'consentversion' => consent::VERSION, 'consentcategories' => 'chat,grades',
            'lastused' => time() - HOURSECS, 'lastinbound' => time() - 3 * DAYSECS, 'lastproactivesent' => 0,
            'timecreated' => time() - 2 * DAYSECS], $fields));
    }

    /**
     * A student linked on WhatsApp, enrolled in a course with an assignment.
     *
     * @param array $fields Fields of the link that replace the default ones.
     * @return array{0: \stdClass, 1: \assign, 2: \stdClass} Student, assignment and course.
     */
    private function linked_student_with_assignment(array $fields = []): array {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Biology 101']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->link((int) $student->id, $fields);
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Cell essay']);
        $assign = new \assign(
            \context_module::instance($instance->cmid),
            get_coursemodule_from_id('assign', $instance->cmid),
            $course
        );
        return [$student, $assign, $course];
    }

    /**
     * Fire the graded event for a student with a grade, as the grading request does, without running the queued tasks.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $student Student.
     * @param float $value Grade.
     * @return \stdClass The assign_grades record.
     */
    private function fire(\assign $assign, \stdClass $student, float $value = 85.0): \stdClass {
        global $DB;
        $grade = $assign->get_user_grade((int) $student->id, true);
        $DB->set_field('assign_grades', 'grade', $value, ['id' => $grade->id]);
        $grade->grade = $value;
        observers::submission_graded(\mod_assign\event\submission_graded::create_from_grade($assign, $grade));
        return $grade;
    }

    /**
     * Fire the graded event for a student with a grade, then run the queued WhatsApp notifications as cron does.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $student Student.
     * @param float $value Grade.
     */
    private function grade(\assign $assign, \stdClass $student, float $value = 85.0): void {
        $this->fire($assign, $student, $value);
        $this->run_queued();
    }

    /**
     * Run the queued WhatsApp grade notifications.
     */
    private function run_queued(): void {
        $this->runAdhocTasks(send_whatsapp_grade_notification::class);
    }

    /**
     * WhatsApp grade notifications waiting in the queue.
     *
     * @return \core\task\adhoc_task[]
     */
    private function queued(): array {
        return \core\task\manager::get_adhoc_tasks(send_whatsapp_grade_notification::class);
    }

    /**
     * Set the last proactive message of the WhatsApp link.
     *
     * @param int $time Time.
     */
    private function set_last_sent(int $time): void {
        global $DB;
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'lastproactivesent', $time, ['bsuid' => self::BSUID]);
    }

    /**
     * The graded student gets grade_posted, outside the 24-hour window, with the course, the activity and the grade as
     * the parameters, the name and language of the settings, to the phone; it counts as a template of the month and
     * records the time of the proactive message.
     */
    public function test_grade_posted_is_sent_with_course_activity_and_grade(): void {
        global $DB;
        manager::set_template_name(templates::GRADE_POSTED, 'jedomi_grade');
        manager::set_template_language('es');
        [$student, $assign] = $this->linked_student_with_assignment();

        $this->grade($assign, $student);

        $calls = $this->client->calls_to('send_template');
        $this->assertCount(1, $calls);
        [$to, $name, $language, $components] = $calls[0]['args'];
        $this->assertSame(['to' => self::WAID], $to->to_payload());
        $this->assertSame(['jedomi_grade', 'es'], [$name, $language]);
        $this->assertSame(
            ['Biology 101', 'Cell essay', '85.00'],
            array_column($components[0]['parameters'], 'text')
        );
        $this->assertSame([], $this->client->calls_to('send_text'), 'A notification is always a template');
        $this->assertSame(1, (int) $DB->get_field(
            whatsapp_link_service::USAGE_TABLE,
            'outtemplate',
            ['userid' => $student->id]
        ));
        $this->assertGreaterThanOrEqual(time() - 5, (int) $DB->get_field(
            whatsapp_link_service::LINKS_TABLE,
            'lastproactivesent',
            ['bsuid' => self::BSUID]
        ));
    }

    /**
     * A student who accepted only the chat category gets no grade notification.
     */
    public function test_no_notification_without_the_grades_consent(): void {
        [$student, $assign] = $this->linked_student_with_assignment(['consentcategories' => 'chat']);

        $this->grade($assign, $student);

        $this->assertSame([], $this->client->calls);
    }

    /**
     * A student who stopped the messages (BAJA) gets no grade notification.
     */
    public function test_no_notification_after_opting_out(): void {
        [$student, $assign] = $this->linked_student_with_assignment(['optedout' => 1, 'optoutat' => time() - 10]);

        $this->grade($assign, $student);

        $this->assertSame([], $this->client->calls);
    }

    /**
     * Nothing is sent while the WhatsApp integration is off, to an unconfirmed or expired link, or to a role hidden
     * for WhatsApp.
     */
    public function test_no_notification_when_the_channel_or_the_link_cannot_serve(): void {
        global $DB;
        [$student, $assign] = $this->linked_student_with_assignment();

        set_config('whatsapp_enabled', 0, 'local_jedomi');
        $this->grade($assign, $student, 50.0);
        set_config('whatsapp_enabled', 1, 'local_jedomi');

        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'confirmed', 0, ['bsuid' => self::BSUID]);
        $this->grade($assign, $student, 60.0);
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'confirmed', 1, ['bsuid' => self::BSUID]);

        $old = time() - whatsapp_link_service::EXPIRY - DAYSECS;
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'lastused', $old, ['bsuid' => self::BSUID]);
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'timecreated', $old, ['bsuid' => self::BSUID]);
        $this->grade($assign, $student, 70.0);
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'lastused', time(), ['bsuid' => self::BSUID]);

        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        set_config('whatsapp_hidden_roles', (string) $studentrole, 'local_jedomi');
        $this->grade($assign, $student, 80.0);

        $this->assertSame([], $this->client->calls);
    }

    /**
     * The minimum interval between proactive messages applies to WhatsApp: a second grade within it is dropped, a
     * grade after it is sent.
     */
    public function test_the_minimum_interval_is_respected(): void {
        [$student, $assign] = $this->linked_student_with_assignment();

        $this->grade($assign, $student, 85.0);
        $this->grade($assign, $student, 86.0);
        $this->assertCount(1, $this->client->calls_to('send_template'));

        $this->set_last_sent(time() - self::INTERVAL * 2);
        $this->grade($assign, $student, 87.0);
        $this->assertCount(2, $this->client->calls_to('send_template'));
    }

    /**
     * A regrade with the same grade is not notified again.
     */
    public function test_the_same_grade_is_not_notified_twice(): void {
        [$student, $assign] = $this->linked_student_with_assignment();

        $this->grade($assign, $student, 85.0);
        $this->set_last_sent(time() - self::INTERVAL * 2);
        $this->grade($assign, $student, 85.0);

        $this->assertCount(1, $this->client->calls_to('send_template'));
    }

    /**
     * A student linked on Telegram and on WhatsApp gets the notification on both channels; the Telegram message is
     * the one it always was.
     */
    public function test_both_channels_are_notified(): void {
        global $DB;
        [$student, $assign] = $this->linked_student_with_assignment();
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $student->id, 'chatid' => '771000077',
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => time(), 'lastused' => time(),
            'lastproactivesent' => 0]);

        $this->fire($assign, $student);
        $this->assertCount(1, $this->telegram->sent, 'Telegram is sent in the grading request, as before');
        $this->assertSame([], $this->client->calls, 'WhatsApp waits for the queued task');
        $this->run_queued();

        $this->assertCount(1, $this->client->calls_to('send_template'));
        $this->assertCount(1, $this->telegram->sent);
        $this->assertStringContainsString('Cell essay', $this->telegram->sent[0]['text']);
        $this->assertStringContainsString('Biology 101', $this->telegram->sent[0]['text']);
    }

    /**
     * An error of Meta does not break the grading, and the grade is notified again on the next try.
     */
    public function test_an_error_of_meta_does_not_break_the_grading(): void {
        [$student, $assign] = $this->linked_student_with_assignment();
        $this->client->responses['send_template'] = new \local_jedomi\whatsapp\cloud_api_exception(400, 132001, 'x');

        $this->grade($assign, $student);

        unset($this->client->responses['send_template']);
        $this->grade($assign, $student);
        $this->assertCount(2, $this->client->calls_to('send_template'));
    }

    /**
     * Grading does not call Meta: one task is queued for the WhatsApp recipient, carrying only ids (no names, no
     * grade); running it sends grade_posted with the course, the activity and the grade.
     */
    public function test_grading_queues_the_whatsapp_notification(): void {
        [$student, $assign, $course] = $this->linked_student_with_assignment();

        $grade = $this->fire($assign, $student);

        $this->assertSame([], $this->client->calls, 'Nothing reaches Meta in the grading request');
        $tasks = $this->queued();
        $this->assertCount(1, $tasks);
        $data = (array) reset($tasks)->get_custom_data();
        ksort($data);
        $this->assertSame([
            'cmid' => (int) $assign->get_course_module()->id,
            'courseid' => (int) $course->id,
            'gradeid' => (int) $grade->id,
            'userid' => (int) $student->id,
        ], $data);

        $this->run_queued();

        $calls = $this->client->calls_to('send_template');
        $this->assertCount(1, $calls);
        $this->assertSame(
            ['Biology 101', 'Cell essay', '85.00'],
            array_column($calls[0]['args'][3][0]['parameters'], 'text')
        );
        $this->assertSame([], $this->queued());
    }

    /**
     * A regrade before the queued task runs queues no second task, and the task sends the grade as it is when it runs.
     */
    public function test_a_regrade_before_the_task_runs_sends_the_current_grade_once(): void {
        [$student, $assign] = $this->linked_student_with_assignment();

        $this->fire($assign, $student, 70.0);
        $this->fire($assign, $student, 90.0);
        $this->assertCount(1, $this->queued());
        $this->run_queued();

        $calls = $this->client->calls_to('send_template');
        $this->assertCount(1, $calls);
        $this->assertSame('90.00', $calls[0]['args'][3][0]['parameters'][2]['text']);
    }

    /**
     * Changes made between the grading and the task are checked again when the task runs.
     *
     * @return array<string, array{0: string}>
     */
    public static function changed_meanwhile_provider(): array {
        return [
            'grades consent withdrawn' => ['consent'],
            'messages stopped' => ['optout'],
            'grade hidden' => ['hidden'],
            'channel disabled' => ['disabled'],
        ];
    }

    /**
     * The task skips the notification when the consent, the opt-out, the visibility of the grade or the channel
     * changed after the grading.
     *
     * @dataProvider changed_meanwhile_provider
     * @param string $change Change made before the task runs.
     */
    public function test_the_task_rechecks_the_recipient_and_the_grade(string $change): void {
        global $DB;
        [$student, $assign] = $this->linked_student_with_assignment();
        $this->fire($assign, $student);
        $this->assertCount(1, $this->queued());

        switch ($change) {
            case 'consent':
                $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'consentcategories', 'chat', ['bsuid' => self::BSUID]);
                break;
            case 'optout':
                $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'optedout', 1, ['bsuid' => self::BSUID]);
                break;
            case 'hidden':
                $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign',
                    'iteminstance' => $assign->get_instance()->id, 'itemnumber' => 0]);
                $item->set_hidden(1);
                break;
            case 'disabled':
                set_config('whatsapp_enabled', 0, 'local_jedomi');
                break;
        }
        $this->run_queued();

        $this->assertSame([], $this->client->calls);
        $this->assertSame([], $this->queued(), 'A skipped notification is not retried');
    }

    /**
     * A grade notified on Telegram is still notified on WhatsApp when the queued task runs: each channel remembers
     * the grades it notified.
     */
    public function test_the_telegram_record_does_not_drop_the_whatsapp_notification(): void {
        global $DB;
        [$student, $assign] = $this->linked_student_with_assignment();
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $student->id, 'chatid' => '771000077',
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => time(), 'lastused' => time(),
            'lastproactivesent' => 0]);

        $this->grade($assign, $student, 85.0);
        $this->assertCount(1, $this->telegram->sent);
        $this->assertCount(1, $this->client->calls_to('send_template'));

        // The same grade again is notified on neither channel.
        $this->set_last_sent(time() - self::INTERVAL * 2);
        $DB->set_field('local_jedomi_telegram_links', 'lastproactivesent', time() - self::INTERVAL * 2, []);
        $this->grade($assign, $student, 85.0);
        $this->assertCount(1, $this->telegram->sent);
        $this->assertCount(1, $this->client->calls_to('send_template'));
        $this->assertSame([], $this->queued());
    }
}
