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

namespace local_jedomi\local\service;

/**
 * Tests for the service behind the Telegram profile page.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\telegram_link_service
 */
final class telegram_link_service_test extends \advanced_testcase {
    /**
     * The bot is available only when Telegram is enabled and a verified bot has a username.
     *
     * @return void
     */
    public function test_get_bot_status(): void {
        $this->resetAfterTest();
        set_config('telegram_enabled', 1, 'local_jedomi');
        set_config('telegram_bot_verified', 1, 'local_jedomi');
        unset_config('telegram_bot_username', 'local_jedomi');
        unset_config('telegram_bot_firstname', 'local_jedomi');

        $this->assertSame(
            ['available' => false, 'username' => '', 'firstname' => 'Jedomi'],
            telegram_link_service::get_bot_status()
        );

        set_config('telegram_bot_username', 'jedomibot', 'local_jedomi');
        set_config('telegram_bot_firstname', 'Bot', 'local_jedomi');
        $this->assertSame(
            ['available' => true, 'username' => 'jedomibot', 'firstname' => 'Bot'],
            telegram_link_service::get_bot_status()
        );

        set_config('telegram_enabled', 0, 'local_jedomi');
        $this->assertFalse(telegram_link_service::get_bot_status()['available']);
    }

    /**
     * Only confirmed links are returned, and unlinking removes every link of the user.
     *
     * MDL-INT-058: unlinking from the profile removes the link.
     *
     * @return void
     */
    public function test_get_confirmed_link_and_unlink(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $user->id, 'chatid' => '100',
            'username' => 'ada', 'confirmed' => 0, 'timecreated' => time()]);

        $this->assertNull(telegram_link_service::get_confirmed_link((int) $user->id));

        $DB->set_field('local_jedomi_telegram_links', 'confirmed', 1, ['userid' => $user->id]);
        $this->assertSame('ada', telegram_link_service::get_confirmed_link((int) $user->id)->username);

        telegram_link_service::unlink((int) $user->id);
        $this->assertFalse($DB->record_exists('local_jedomi_telegram_links', ['userid' => $user->id]));
    }

    /**
     * MDL-INT-060: unlinking from the web removes the conversation and the state of the bot too.
     *
     * @return void
     */
    public function test_unlink_removes_the_conversation_and_the_bot_state(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $user->id, 'chatid' => '782000001',
            'username' => 'ada', 'confirmed' => 1, 'timecreated' => $now]);
        $DB->insert_record('local_jedomi_telegram_sessions', (object) ['chatid' => '782000001',
            'messages' => json_encode([['role' => 'user', 'content' => 'My grades']]), 'lastactivity' => $now]);
        $DB->insert_record('local_jedomi_telegram_rate_limit', (object) ['chatid' => '782000001', 'windowstart' => $now,
            'msgcount' => 2, 'blockeduntil' => 0]);
        $DB->insert_record('local_jedomi_telegram_pending_actions', (object) ['callbackid' => 'cb-782',
            'chatid' => '782000001', 'actionjson' => '{}', 'timecreated' => $now]);

        telegram_link_service::unlink((int) $user->id);

        $this->assertSame(['sessions' => 0, 'limits' => 0, 'actions' => 0], [
            'sessions' => $DB->count_records('local_jedomi_telegram_sessions', ['chatid' => '782000001']),
            'limits' => $DB->count_records('local_jedomi_telegram_rate_limit', ['chatid' => '782000001']),
            'actions' => $DB->count_records('local_jedomi_telegram_pending_actions', ['chatid' => '782000001']),
        ]);
    }

    /**
     * MDL-INT-060: after unlinking from the web the user receives no more notifications.
     *
     * @return void
     */
    public function test_no_notification_after_unlinking(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the plugin state is not available.');
        }
        $this->resetAfterTest();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_jedomi', true);
        set_config('telegram_enabled', 1, 'local_jedomi');
        $telegram = new \local_jedomi\telegram\fake_telegram_api_client();
        \local_jedomi\telegram\api_client::set_instance_for_testing($telegram);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $student->id, 'chatid' => '782000002',
            'username' => 'ada', 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $assign = new \assign(
            \context_module::instance($instance->cmid),
            get_coursemodule_from_id('assign', $instance->cmid),
            $course
        );

        telegram_link_service::unlink((int) $student->id);
        $grade = $assign->get_user_grade((int) $student->id, true);
        \local_jedomi\event\observers::submission_graded(\mod_assign\event\submission_graded::create_from_grade($assign, $grade));
        \local_jedomi\telegram\api_client::set_instance_for_testing(null);

        $this->assertSame([], $telegram->sent);
    }
}
