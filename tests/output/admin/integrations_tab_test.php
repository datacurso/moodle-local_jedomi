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

namespace local_jedomi\output\admin;

use local_jedomi\local\service\admin_settings_service;

/**
 * Tests for the Integrations tab of the Jedomi settings.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\output\admin\integrations_tab
 */
final class integrations_tab_test extends \advanced_testcase {
    /**
     * Telegram settings of a verified bot with a webhook error.
     *
     * @return array
     */
    private function telegram_settings(): array {
        return [
            'enabled' => 1, 'bot_token_set' => true, 'bot_username' => 'jedomibot', 'bot_firstname' => 'Jedomi',
            'bot_verified' => 1, 'webhook_error' => 1, 'webhook_url' => 'https://example.com/hook?secret=x',
            'rate_limit' => 30, 'cooldown' => 5, 'max_turns' => 10, 'gateway_timeout' => 120,
            'hidden_role_ids' => [], 'last_error' => 'setWebhook failed', 'last_error_time' => 1700000000,
            'is_configured' => true,
        ];
    }

    /**
     * The tab exports the bot state, the webhook status and the setup guide.
     *
     * @return void
     */
    public function test_export_for_template(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $tab = new integrations_tab($this->telegram_settings(), '<form></form>');

        $data = $tab->export_for_template($PAGE->get_renderer('local_jedomi'));

        $this->assertTrue($data['enabled']);
        $this->assertTrue($data['showbadge']);
        $this->assertTrue($data['haslasterror']);
        $this->assertFalse($data['webhookok']);
        $this->assertTrue($data['showerroralert']);
        $this->assertCount(4, $data['adminsteps']);
        $this->assertCount(4, $data['usersteps']);
        $this->assertSame(['telegram_step3_title'], array_column(
            array_filter($data['adminsteps'], fn($step) => $step['showwebhookstate']),
            'titlekey'
        ));
    }

    /**
     * The rendered tab has the switch, the webhook actions and the form, with the error escaped.
     *
     * @return void
     */
    public function test_render(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $settings = ['last_error' => '<script>x</script>'] + $this->telegram_settings();

        $html = $PAGE->get_renderer('local_jedomi')->render(new integrations_tab($settings, '<form data-test="tg"></form>'));

        $this->assertStringContainsString('data-action="local_jedomi/admin/integrations/toggle-telegram"', $html);
        $this->assertStringContainsString('data-action="local_jedomi/admin/integrations/check-webhook"', $html);
        $this->assertStringContainsString('data-action="local_jedomi/admin/integrations/drop-pending"', $html);
        $this->assertStringContainsString('data-test="tg"', $html);
        $this->assertStringContainsString('@jedomibot', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('checked', $html);
    }

    /**
     * A disabled integration without a token hides the webhook status block.
     *
     * @return void
     */
    public function test_disabled_without_token(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $settings = admin_settings_service::get_telegram_settings();

        $tab = new integrations_tab($settings, '');
        $data = $tab->export_for_template($PAGE->get_renderer('local_jedomi'));
        $html = $PAGE->get_renderer('local_jedomi')->render($tab);

        $this->assertFalse($data['enabled']);
        $this->assertFalse($data['tokenset']);
        $this->assertStringNotContainsString('local_jedomi/admin/integrations/check-webhook', $html);
    }
}
