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

namespace local_jedomi;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the configuration handed to the browser sidebar.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_jedomi_get_sidebar_config
 */
final class sidebar_config_test extends \advanced_testcase {
    /**
     * MDL-INT-001: the browser never receives a service address or an API key.
     * MDL-UNIT-072: the configuration has no service address and no API keys.
     * M9: the browser never receives a gateway URL or an API key, even on a site with legacy settings.
     */
    public function test_sidebar_config_has_no_gateway_url_or_api_key(): void {
        global $PAGE;
        $this->resetAfterTest();
        set_config('gateway_url', 'http://legacy-gateway.invalid', 'local_jedomi');
        set_config('api_key', 'legacy-key-value', 'local_jedomi');
        $this->setUser($this->getDataGenerator()->create_user());
        $PAGE->set_url('/local/jedomi/ajax/sidebar_config.php');

        $config = local_jedomi_get_sidebar_config();

        $this->assertArrayNotHasKey('gateway_url', $config);
        $this->assertArrayNotHasKey('api_key', $config);
        $encoded = json_encode($config);
        $this->assertStringNotContainsString('legacy-gateway.invalid', $encoded);
        $this->assertStringNotContainsString('legacy-key-value', $encoded);
        $this->assertStringEndsWith('/local/jedomi/ajax/feedback.php', $config['feedback_url']);
        $this->assertStringEndsWith('/local/jedomi/chatproxy.php', $config['chatproxy_url']);
    }
    /**
     * MDL-UNIT-072: the configuration has the tool and session strings.
     * MDL-UNIT-075: each tool status text and the generic one are delivered to the panel.
     * The sidebar gets every string it shows, so it never falls back to hardcoded text.
     *
     * @return void
     */
    public function test_sidebar_config_has_the_tool_and_session_strings(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $PAGE->set_url('/local/jedomi/ajax/sidebar_config.php');

        $strings = local_jedomi_get_sidebar_config()['strings'];

        foreach (
            ['tool_search_tables', 'tool_execute_sql', 'tool_describe_table', 'tool_get_site_config', 'tool_ws_search',
                'tool_ws_describe', 'tool_ws_execute', 'tool_unknown', 'session_expired_title', 'session_expired_hint',
                'qa_course_current', 'qa_course_with_id'] as $key
        ) {
            $this->assertSame(get_string($key, 'local_jedomi'), $strings[$key] ?? null, $key);
        }
    }

    /**
     * MDL-INT-006: the web chat receives the processing switches that show or hide attachments and microphone.
     * MDL-UNIT-072: the configuration has the state of the file and audio switches.
     * M8, M9: the sidebar receives the feature switches, the read URL and the strings of images and audio.
     *
     * @return void
     */
    public function test_sidebar_config_has_the_feature_switches(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $PAGE->set_url('/local/jedomi/ajax/sidebar_config.php');

        $config = local_jedomi_get_sidebar_config();
        $this->assertTrue($config['files_enabled']);
        $this->assertFalse($config['audio_enabled']);
        $this->assertStringEndsWith('/local/jedomi/read_file.php', $config['read_file_url']);
        foreach (
            ['attach_audio', 'audio_file', 'file_audio', 'reading_image', 'listening', 'transcribing', 'mic_error',
                'voice_input', 'voice_message', 'voice_recording', 'voice_cancel', 'voice_stop', 'voice_play', 'voice_pause',
                'voice_delete', 'voice_send', 'audio_error_unreadable'] as $key
        ) {
            $this->assertSame(get_string($key, 'local_jedomi'), $config['strings'][$key] ?? null, $key);
        }

        set_config('files_enabled', 0, 'local_jedomi');
        set_config('audio_enabled', 1, 'local_jedomi');
        $config = local_jedomi_get_sidebar_config();
        $this->assertFalse($config['files_enabled']);
        $this->assertTrue($config['audio_enabled']);
    }

    /**
     * MDL-UNIT-068 / MDL-UNIT-111 / MDL-UNIT-115: the panel gets the voice conversation, the text of each phase and the failure notices only while it is enabled, never with audio off.
     * V9: the sidebar receives the voice conversation, its endpoint and its strings only while it is enabled, and never
     * while audio processing is off.
     *
     * @return void
     */
    public function test_sidebar_config_has_the_voice_conversation_only_when_enabled(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $PAGE->set_url('/local/jedomi/ajax/sidebar_config.php');
        unset_config('voice_enabled', 'local_jedomi');

        $config = local_jedomi_get_sidebar_config();
        $this->assertFalse($config['voice_enabled']);
        $this->assertArrayNotHasKey('speech_url', $config);

        set_config('audio_enabled', 1, 'local_jedomi');
        set_config('voice_enabled', 1, 'local_jedomi');
        $config = local_jedomi_get_sidebar_config();
        $this->assertTrue($config['voice_enabled']);
        $this->assertStringEndsWith('/local/jedomi/speech.php', $config['speech_url']);
        foreach (
            ['voice_mode', 'voice_mode_exit', 'voice_mode_listening', 'voice_mode_thinking', 'voice_mode_speaking',
                'voice_mode_paused', 'voice_mode_confirm_notice', 'voice_mode_transcription_error',
                'voice_mode_speech_error'] as $key
        ) {
            $this->assertSame(get_string($key, 'local_jedomi'), $config['strings'][$key] ?? null, $key);
        }
        $encoded = json_encode($config, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('jedomi/Qwen', $encoded, 'The model is chosen on the server');

        set_config('audio_enabled', 0, 'local_jedomi');
        $config = local_jedomi_get_sidebar_config();
        $this->assertFalse($config['voice_enabled']);
        $this->assertArrayNotHasKey('speech_url', $config);
    }

    /**
     * MDL-UNIT-018: outside a course the quick actions never use an id of another kind as the course, and the admin
     * quick actions appear in the administration.
     * [Pendiente:skip]
     */
    public function test_quick_actions_use_the_real_course_of_the_page(): void {
        $this->markTestSkipped('Pending fix: the admin quick actions of the administration never appear and, outside a '
            . 'course, the course id is taken from any id parameter of the page (the user id on the profile).');
    }

    /**
     * Sidebar configuration of a user on a page.
     *
     * @param \stdClass $user User.
     * @param \stdClass|null $course Course of the page; null for the site home.
     * @return array
     */
    private function config_on_page(\stdClass $user, ?\stdClass $course): array {
        global $PAGE;
        $this->setUser($user);
        $PAGE = new \moodle_page();
        if ($course) {
            $PAGE->set_course($course);
            $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        } else {
            $PAGE->set_course(get_site());
            $PAGE->set_url('/index.php');
        }
        return local_jedomi_get_sidebar_config();
    }

    /**
     * SYS-E2E-002: each role gets, inside a course and outside it, the role and the course of the page its quick actions
     * are built with, and a teacher the permissions its conditional actions depend on. Behat: chat_system.feature.
     *
     * @return void
     */
    public function test_quick_actions_by_role_use_the_course_of_the_page(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Quick Actions Course']);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $student = $gen->create_and_enrol($course, 'student');
        $manager = $gen->create_user();
        role_assign((int) $DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id, \context_system::instance());

        $expected = ['admin' => get_admin(), 'manager' => $manager, 'teacher' => $teacher, 'student' => $student];
        foreach ($expected as $role => $user) {
            $config = $this->config_on_page($user, $course);
            $this->assertSame($role, $config['user_role'], "{$role} inside the course");
            $this->assertSame((int) $course->id, (int) $config['context']['course_id'], "{$role} inside the course");
            $this->assertSame('Quick Actions Course', $config['context']['course_name']);
            $this->assertSame('course', $config['context']['location']);
        }

        $this->config_on_page($teacher, $course);
        $spt = local_jedomi_generate_spt('teacher');
        $this->assertSame((int) $course->id, (int) $spt['course_id']);
        $this->assertTrue($spt['capabilities']['can_send_messages'], 'Notify group needs the messaging permission');
        $this->assertTrue($spt['capabilities']['can_view_all_grades'], 'Top students needs the grades permission');

        foreach (['admin' => get_admin(), 'manager' => $manager, 'student' => $student] as $role => $user) {
            $config = $this->config_on_page($user, null);
            $this->assertSame($role, $config['user_role'], "{$role} outside a course");
            $this->assertSame(0, (int) $config['context']['course_id'], "{$role} outside a course");
            $this->assertSame('', $config['context']['course_name'], "{$role} outside a course");
        }
    }
    /**
     * MDL-INT-057: the chat permissions are renewed while the Moodle session is active and not after it ends.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_chat_permissions_are_renewed_while_the_session_is_active(): void {
        $this->markTestSkipped('Pending fix: the chat permissions expire 30 minutes after loading the page and are never '
            . 'renewed, also during the voice conversation.');
    }
}
