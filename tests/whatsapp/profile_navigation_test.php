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

namespace local_jedomi\whatsapp;

use local_jedomi\local\service\whatsapp_link_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the WhatsApp node of the profile navigation (Mi perfil > Jedomi on WhatsApp).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_jedomi_myprofile_navigation
 */
final class profile_navigation_test extends \advanced_testcase {
    /**
     * Enable Jedomi and configure the channel, with Telegram off; skip without the provider, which holds the state.
     */
    private function setup_channel(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the plugin state is not available.');
        }
        $this->resetAfterTest();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_jedomi', true);
        set_config('telegram_enabled', 0, 'local_jedomi');
        manager::set_enabled(true);
        manager::set_phone_number_id('123456789');
        manager::set_waba_id('987654321');
        manager::set_access_token('test-token');
        manager::set_app_secret('test-secret');
        manager::set_verify_token('test-verify');
        manager::set_display_number('573001234567');
    }

    /**
     * Whether the profile of the current user offers the WhatsApp page.
     *
     * @return bool
     */
    private static function offers_whatsapp(): bool {
        global $USER;
        $tree = new \core_user\output\myprofile\tree();
        local_jedomi_myprofile_navigation($tree, $USER, true, null);
        return array_key_exists('whatsapp', $tree->nodes);
    }

    /**
     * The node is shown with the channel configured, also when Telegram is off; it is hidden while the channel is off
     * or has no institution number.
     */
    public function test_node_needs_the_configured_channel(): void {
        $this->setup_channel();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertTrue(self::offers_whatsapp());
        manager::set_display_number('');
        $this->assertFalse(self::offers_whatsapp());
        manager::set_display_number('573001234567');
        manager::set_enabled(false);
        $this->assertFalse(self::offers_whatsapp());
    }

    /**
     * A blocked role sees the node only while linked, to remove the link.
     */
    public function test_blocked_role_sees_the_node_only_while_linked(): void {
        global $DB;
        $this->setup_channel();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        set_config('whatsapp_hidden_roles', (string) $DB->get_field('role', 'id', ['shortname' => 'student']), 'local_jedomi');
        $this->setUser($user);

        $this->assertFalse(self::offers_whatsapp());
        $DB->insert_record(whatsapp_link_service::LINKS_TABLE, (object) ['userid' => $user->id,
            'bsuid' => 'US.13491208655302741918', 'confirmed' => 1, 'timecreated' => time()]);
        $this->assertTrue(self::offers_whatsapp());
    }
}
