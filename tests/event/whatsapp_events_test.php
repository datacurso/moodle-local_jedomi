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

/**
 * Tests for the WhatsApp events and for the observers that remove the WhatsApp link of an account.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\event\whatsapp_linked
 * @covers     \local_jedomi\event\whatsapp_unlinked
 * @covers     \local_jedomi\event\whatsapp_opted_out
 * @covers     \local_jedomi\event\whatsapp_opted_in
 * @covers     \local_jedomi\event\observers
 */
final class whatsapp_events_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /** @var string Phone of the student. */
    private const WAID = '16505551234';

    /**
     * Insert a WhatsApp link with a conversation.
     *
     * @param int $userid User id.
     */
    private static function insert_link(int $userid): void {
        global $DB;
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) ['userid' => $userid, 'bsuid' => self::BSUID,
            'waid' => self::WAID, 'phone' => '+' . self::WAID, 'confirmed' => 1, 'timecreated' => time()]);
        $DB->insert_record(whatsapp_link_service::SESSIONS_TABLE, (object) [
            'chatid' => whatsapp_link_service::chatkey(self::BSUID), 'messages' => '[]', 'lastactivity' => time()]);
    }

    /**
     * Each event is valid, has a name and a description with ids only: never the BSUID or the phone.
     */
    public function test_events_carry_ids_only(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $classes = [whatsapp_linked::class, whatsapp_unlinked::class, whatsapp_opted_out::class, whatsapp_opted_in::class];

        $sink = $this->redirectEvents();
        foreach ($classes as $class) {
            $class::create_for_user((int) $user->id)->trigger();
        }
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame($classes, array_map('get_class', $events));
        foreach ($events as $event) {
            $this->assertSame((int) $user->id, (int) $event->relateduserid);
            $this->assertSame(\context_system::instance()->id, (int) $event->contextid);
            $this->assertNotEmpty($event::get_name());
            $this->assertStringContainsString("'{$user->id}'", $event->get_description());
            $dump = json_encode($event->get_data()) . $event->get_description();
            $this->assertStringNotContainsString(self::BSUID, $dump);
            $this->assertStringNotContainsString(self::WAID, $dump);
        }
    }

    /**
     * An event needs the user whose link changed.
     */
    public function test_event_needs_the_related_user(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        whatsapp_linked::create(['context' => \context_system::instance()]);
    }

    /**
     * A deleted user loses the WhatsApp link and its conversation.
     */
    public function test_deleted_user_loses_the_whatsapp_link(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);

        delete_user($user);

        $this->assertFalse($DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists(whatsapp_link_service::SESSIONS_TABLE, []));
    }

    /**
     * A user whose password changed loses the WhatsApp link.
     */
    public function test_password_change_removes_the_whatsapp_link(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);

        \core\event\user_password_updated::create_from_user($user)->trigger();

        $this->assertFalse($DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['userid' => $user->id]));
    }

    /**
     * A suspended user loses the WhatsApp link; another profile update keeps it.
     */
    public function test_suspension_removes_the_whatsapp_link(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);

        $user->firstname = 'Renamed';
        user_update_user($user, false);
        $this->assertTrue($DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['userid' => $user->id]));

        $user->suspended = 1;
        user_update_user($user, false);
        $this->assertFalse($DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['userid' => $user->id]));
    }

    /**
     * The events of an observer run: the triggering core event is captured first (observers do not run while the
     * events are redirected), then the observer is called with it.
     *
     * @param callable $action Action that triggers the core event.
     * @param string $eventclass Class of the core event.
     * @param callable $observer Observer to call with the event.
     * @return \core\event\base[] Events triggered by the observer.
     */
    private function observer_events(callable $action, string $eventclass, callable $observer): array {
        $sink = $this->redirectEvents();
        $action();
        $core = array_values(array_filter($sink->get_events(), static fn($e): bool => $e instanceof $eventclass));
        $sink->close();
        $this->assertCount(1, $core);

        $sink = $this->redirectEvents();
        $observer($core[0]);
        $events = $sink->get_events();
        $sink->close();
        return array_values(array_filter($events, static fn($e): bool => $e instanceof whatsapp_unlinked));
    }

    /**
     * Deleting a linked user triggers whatsapp_unlinked once, in the system context, for the deleted user.
     */
    public function test_deleted_user_triggers_unlinked_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);

        $events = $this->observer_events(
            static fn() => delete_user($user),
            \core\event\user_deleted::class,
            [observers::class, 'user_deleted']
        );

        $this->assertCount(1, $events);
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
        $this->assertSame(\context_system::instance()->id, (int) $events[0]->contextid);
    }

    /**
     * A password change of a linked user triggers whatsapp_unlinked once.
     */
    public function test_password_change_triggers_unlinked_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);

        $events = $this->observer_events(
            static fn() => \core\event\user_password_updated::create_from_user($user)->trigger(),
            \core\event\user_password_updated::class,
            [observers::class, 'user_password_updated']
        );

        $this->assertCount(1, $events);
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
    }

    /**
     * Suspending a linked user triggers whatsapp_unlinked once.
     */
    public function test_suspension_triggers_unlinked_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);
        $user->suspended = 1;

        $events = $this->observer_events(
            static fn() => user_update_user($user, false),
            \core\event\user_updated::class,
            [observers::class, 'user_updated']
        );

        $this->assertCount(1, $events);
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
    }

    /**
     * Unlinking with an actor (the profile page) triggers whatsapp_unlinked once, done by that actor.
     */
    public function test_unlink_with_actor_triggers_unlinked_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $admin = get_admin();
        self::insert_link((int) $user->id);

        $sink = $this->redirectEvents();
        whatsapp_link_service::unlink((int) $user->id, (int) $admin->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame([whatsapp_unlinked::class], array_map('get_class', $events));
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
        $this->assertSame((int) $admin->id, (int) $events[0]->userid);
    }

    /**
     * The expiry cleanup triggers whatsapp_unlinked once for each expired link.
     */
    public function test_expired_links_trigger_unlinked_once(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        self::insert_link((int) $user->id);
        $old = time() - whatsapp_link_service::EXPIRY - DAYSECS;
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'lastused', $old, ['userid' => $user->id]);
        $DB->set_field(whatsapp_link_service::LINKS_TABLE, 'timecreated', $old, ['userid' => $user->id]);

        $sink = $this->redirectEvents();
        $this->assertSame(1, whatsapp_link_service::unlink_expired());
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame([whatsapp_unlinked::class], array_map('get_class', $events));
        $this->assertSame((int) $user->id, (int) $events[0]->relateduserid);
    }

    /**
     * Unlinking keeps the usage counters (the monthly limit must not reset on a new link); deleting the user removes
     * them, while a suspension or a password change keeps them.
     */
    public function test_only_the_deletion_of_the_user_removes_the_usage(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $usage = static fn(): int => $DB->count_records(whatsapp_link_service::USAGE_TABLE, ['userid' => $user->id]);
        $DB->insert_record(whatsapp_link_service::USAGE_TABLE, (object) ['userid' => $user->id, 'period' => 202611,
            'outservice' => 4]);

        self::insert_link((int) $user->id);
        whatsapp_link_service::unlink((int) $user->id);
        $this->assertSame(1, $usage(), 'A plain unlink keeps the usage');

        self::insert_link((int) $user->id);
        \core\event\user_password_updated::create_from_user($user)->trigger();
        $this->assertFalse($DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['userid' => $user->id]));
        $this->assertSame(1, $usage(), 'A password change keeps the usage');

        self::insert_link((int) $user->id);
        $user->suspended = 1;
        user_update_user($user, false);
        $this->assertFalse($DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['userid' => $user->id]));
        $this->assertSame(1, $usage(), 'A suspension keeps the usage');

        delete_user($DB->get_record('user', ['id' => $user->id]));
        $this->assertSame(0, $usage(), 'Deleting the user removes the usage');
    }

    /**
     * Unlinking a user without a WhatsApp link triggers nothing.
     */
    public function test_unlink_without_link_triggers_nothing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $sink = $this->redirectEvents();
        whatsapp_link_service::unlink((int) $user->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame([], $events);
    }

    /**
     * Load the user library.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        parent::setUpBeforeClass();
    }
}
