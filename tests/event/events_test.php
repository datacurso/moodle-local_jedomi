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

use local_jedomi\agent\agent_manager;
use local_jedomi\local\service\admin_settings_service;
use local_jedomi\local\service\pending_action_service;
use local_jedomi\local\service\telegram_link_service;

/**
 * Tests for the Moodle events of the assistant: who did what, where and when, never the conversation text.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\event\chat_answered
 * @covers     \local_jedomi\event\action_confirmed
 * @covers     \local_jedomi\event\action_cancelled
 * @covers     \local_jedomi\event\agent_created
 * @covers     \local_jedomi\event\agent_updated
 * @covers     \local_jedomi\event\agent_deleted
 * @covers     \local_jedomi\event\agent_run
 * @covers     \local_jedomi\event\telegram_linked
 * @covers     \local_jedomi\event\telegram_unlinked
 * @covers     \local_jedomi\event\settings_updated
 */
final class events_test extends \advanced_testcase {
    /**
     * Assert that an event carries none of the given texts in its data or its description.
     *
     * @param \core\event\base $event Event.
     * @param string[] $texts Texts that must not appear.
     * @return void
     */
    private function assert_without_texts(\core\event\base $event, array $texts): void {
        $dump = json_encode($event->get_data()) . ' ' . $event->get_description();
        foreach ($texts as $text) {
            $this->assertStringNotContainsString($text, $dump, get_class($event) . ' must not carry conversation text');
        }
    }

    /**
     * MDL-INT-081: creating, updating, running and deleting an agent are logged with the agent and its owner, and the
     * run tells its result; the instruction and the answer are never part of the events.
     */
    public function test_agent_lifecycle_is_logged_without_the_prompt(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $sink = $this->redirectEvents();

        $id = agent_manager::create_agent((object) ['userid' => $owner->id, 'prompt' => 'Secret instruction',
            'schedule_cron' => '0 8 * * *']);
        agent_manager::update_agent($id, (object) ['prompt' => 'Another secret instruction']);
        agent_manager::mark_run($id, true, 'Secret answer');
        agent_manager::mark_run($id, false, 'Secret failure');
        agent_manager::delete_agent($id);

        $events = array_values(array_filter($sink->get_events(), fn($e) => str_starts_with(get_class($e), __NAMESPACE__)));
        $sink->close();
        $this->assertSame(
            [agent_created::class, agent_updated::class, agent_run::class, agent_run::class, agent_deleted::class],
            array_map('get_class', $events)
        );
        foreach ($events as $event) {
            $this->assertSame($id, (int) $event->objectid);
            $this->assertSame((int) $owner->id, (int) $event->relateduserid);
            $this->assertSame(\context_system::instance()->id, (int) $event->contextid);
            $this->assert_without_texts($event, ['Secret instruction', 'Another secret', 'Secret answer', 'Secret failure']);
            $this->assertNotEmpty($event->get_name());
        }
        $this->assertTrue($events[2]->other['success']);
        $this->assertFalse($events[3]->other['success']);
    }

    /**
     * MDL-INT-081: a confirmed and a cancelled action are logged with the action name and the channel, never with the
     * arguments (the instruction of the agent).
     */
    public function test_confirmed_and_cancelled_actions_are_logged(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $args = ['name' => 'Secret subject', 'prompt' => 'Secret message', 'schedule_cron' => '0 8 * * *'];
        $held = pending_action_service::hold((int) $user->id, 'create_agent', $args);
        $this->assertNotNull($held);

        $sink = $this->redirectEvents();
        pending_action_service::resolve((int) $held['id'], (int) $user->id, false);
        pending_action_service::action_event(true, 'delete_agent', 'telegram', (int) $user->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertInstanceOf(action_cancelled::class, $events[0]);
        $this->assertSame(['action' => 'create_agent', 'channel' => 'web'], $events[0]->other);
        $this->assertSame((int) $user->id, (int) $events[0]->userid);
        $this->assert_without_texts($events[0], ['Secret subject', 'Secret message']);
        $this->assertInstanceOf(action_confirmed::class, $events[1]);
        $this->assertSame(['action' => 'delete_agent', 'channel' => 'telegram'], $events[1]->other);
    }

    /**
     * MDL-INT-081: unlinking Telegram is logged for the user whose link was removed; a user without a link logs nothing.
     */
    public function test_telegram_unlink_is_logged(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $user->id, 'chatid' => '783000001',
            'username' => 'ada_tg_user', 'confirmed' => 1, 'timecreated' => time()]);

        $sink = $this->redirectEvents();
        telegram_link_service::unlink((int) $user->id);
        telegram_link_service::unlink((int) $user->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events, 'Only a real unlink is logged');
        $this->assertInstanceOf(telegram_unlinked::class, $events[0]);
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
        $this->assert_without_texts($events[0], ['783000001', 'ada_tg_user']);
    }

    /**
     * MDL-INT-081: saving a settings tab is logged with the tab, never with the values.
     */
    public function test_settings_updates_are_logged_without_values(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();
        admin_settings_service::save_general_settings('Secret value', 500, 10);
        admin_settings_service::save_course_settings('all', []);
        // The course settings also write config_log_created events of Moodle (SEC-JD-112); only the tab events matter here.
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event): bool => !$event instanceof \core\event\config_log_created
        ));
        $sink->close();

        $this->assertSame([settings_updated::class, settings_updated::class], array_map('get_class', $events));
        $this->assertSame('general', $events[0]->other['tab']);
        $this->assertSame('course', $events[1]->other['tab']);
        $this->assert_without_texts($events[0], ['Secret value']);
    }

    /**
     * MDL-INT-081: an answer of the assistant is logged with the channel and the course, and the linking of Telegram
     * with the user; both events are valid and readable in the logs.
     */
    public function test_chat_answer_and_telegram_link_events(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);

        $sink = $this->redirectEvents();
        chat_answered::create_for_channel('web', $coursecontext, (int) $user->id)->trigger();
        chat_answered::create_for_channel('telegram', null, (int) $user->id)->trigger();
        telegram_linked::create([
            'context' => \context_system::instance(),
            'userid' => $user->id,
            'relateduserid' => $user->id,
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame($coursecontext->id, (int) $events[0]->contextid);
        $this->assertSame('web', $events[0]->other['channel']);
        $this->assertSame((int) $course->id, (int) $events[0]->courseid);
        $this->assertSame('telegram', $events[1]->other['channel']);
        $this->assertSame(\context_system::instance()->id, (int) $events[1]->contextid);
        $this->assertInstanceOf(telegram_linked::class, $events[2]);
        foreach ($events as $event) {
            $this->assertSame((int) $user->id, (int) $event->userid);
            $this->assertNotEmpty($event->get_description());
            $this->assertNotEmpty($event::get_name());
        }
    }
}
