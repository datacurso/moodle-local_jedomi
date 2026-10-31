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

use core\hook\output\before_footer_html_generation;

/**
 * Tests for the Jedomi state kept by the Datacurso AI provider (S6) and the removal of the old setting (S7).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\hook_callbacks
 */
final class plugin_state_test extends \advanced_testcase {
    /**
     * Run the sidebar hook as a logged-in admin and tell whether the sidebar was injected.
     *
     * @return bool True when the sidebar AMD module was requested.
     */
    private function sidebar_injected(): bool {
        global $PAGE;
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/my/'));
        $PAGE->set_context(\context_system::instance());

        hook_callbacks::before_footer(new before_footer_html_generation($PAGE->get_renderer('core')));

        return str_contains($PAGE->requires->get_end_code(), 'local_jedomi/sidebar');
    }

    /**
     * S6: with no state saved in the provider, the sidebar is injected as before.
     *
     * @return void
     */
    public function test_sidebar_is_injected_when_enabled_in_provider(): void {
        $this->resetAfterTest();
        unset_config('plugin_enabled_local_jedomi', 'aiprovider_datacurso');

        $this->assertTrue($this->sidebar_injected());
    }

    /**
     * S6: disabled in the provider, the sidebar is not injected (as when the old setting was off).
     *
     * @return void
     */
    public function test_sidebar_is_not_injected_when_disabled_in_provider(): void {
        $this->resetAfterTest();
        set_config('plugin_enabled_local_jedomi', '0', 'aiprovider_datacurso');

        $this->assertFalse($this->sidebar_injected());
    }

    /**
     * S6: the old local_jedomi/enabled setting is ignored; only the provider state counts.
     *
     * @return void
     */
    public function test_old_enabled_setting_is_ignored(): void {
        $this->resetAfterTest();
        set_config('enabled', '0', 'local_jedomi');
        set_config('plugin_enabled_local_jedomi', '1', 'aiprovider_datacurso');

        $this->assertTrue($this->sidebar_injected());
    }

    /**
     * S7: the 2026102902 upgrade step deletes the old setting and keeps the AI service settings.
     *
     * @return void
     */
    public function test_upgrade_removes_old_enabled_setting(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/jedomi/db/upgrade.php');
        $this->resetAfterTest();
        set_config('enabled', '1', 'local_jedomi');
        set_config('model', 'jedomi/zai-org/GLM-5.3-Flash', 'local_jedomi');
        set_config('docservice_url', 'http://docs.local:8000', 'local_jedomi');
        set_config('max_tokens', 4096, 'local_jedomi');
        set_config('plugin_enabled_local_jedomi', '0', 'aiprovider_datacurso');
        set_config('version', 2026102901, 'local_jedomi');

        $this->assertTrue(xmldb_local_jedomi_upgrade(2026102901));

        $this->assertFalse(get_config('local_jedomi', 'enabled'));
        $this->assertSame('jedomi/zai-org/GLM-5.3-Flash', get_config('local_jedomi', 'model'));
        $this->assertSame('http://docs.local:8000', get_config('local_jedomi', 'docservice_url'));
        $this->assertSame('4096', get_config('local_jedomi', 'max_tokens'));
        $this->assertSame('0', get_config('aiprovider_datacurso', 'plugin_enabled_local_jedomi'));
        $this->assertSame('2026102902', get_config('local_jedomi', 'version'));
    }

    /**
     * S7: the General tab links to the Jedomi settings page in the provider instead of the removed fields.
     *
     * @return void
     */
    public function test_provider_settings_link_template_points_to_provider_page(): void {
        global $OUTPUT, $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $url = (new \moodle_url('/ai/provider/datacurso/admin/plugin_settings.php', ['component' => 'local_jedomi']))->out(false);

        $html = $OUTPUT->render_from_template('local_jedomi/provider_settings_link', ['url' => $url]);

        $this->assertStringContainsString('href="' . s($url) . '"', $html);
        $this->assertStringContainsString(get_string('provider_settings_link', 'local_jedomi'), $html);
        $this->assertStringContainsString(get_string('provider_settings_info', 'local_jedomi'), $html);
    }
}
