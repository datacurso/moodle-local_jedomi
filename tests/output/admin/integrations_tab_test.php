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
     * MDL-UNIT-055: the template data carries the badge, the webhook state and the setup guide.
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
     * MDL-UNIT-055: the rendered card shows the bot user as a badge and the webhook actions.
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
     * MDL-UNIT-055: without a token the webhook status is not shown.
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

    /**
     * Render the tab with the given settings overriding a verified, enabled bot.
     *
     * @param array $overrides Settings to override.
     * @return string
     */
    private function render_with(array $overrides): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $settings = $overrides + $this->telegram_settings();
        return $PAGE->get_renderer('local_jedomi')->render(new integrations_tab($settings, ''));
    }

    /**
     * MDL-UNIT-055: a valid stored token shows the Connected state with the bot user and name, and the user as a badge.
     *
     * @return void
     */
    public function test_verified_token_shows_the_connected_state(): void {
        $this->resetAfterTest();

        $html = $this->render_with(['last_error' => '', 'last_error_time' => 0, 'webhook_error' => 0]);

        $this->assertStringContainsString(get_string('telegram_status_connected', 'local_jedomi'), $html);
        $this->assertStringNotContainsString(get_string('telegram_status_disconnected', 'local_jedomi'), $html);
        $this->assertStringContainsString('<strong>@jedomibot</strong> (Jedomi)', $html);
        $this->assertStringContainsString('local_jedomi-admin-int-badge ml-2">@jedomibot', $html);
    }

    /**
     * MDL-UNIT-055: an unverified token shows Disconnected and no badge.
     *
     * @return void
     */
    public function test_unverified_token_shows_the_disconnected_state(): void {
        $this->resetAfterTest();

        $html = $this->render_with(['bot_verified' => 0]);

        $this->assertStringContainsString(get_string('telegram_status_disconnected', 'local_jedomi'), $html);
        $this->assertStringNotContainsString(get_string('telegram_status_connected', 'local_jedomi'), $html);
        $this->assertStringNotContainsString('local_jedomi-admin-int-badge', $html);
    }

    /**
     * MDL-UNIT-055: without a token neither the connection state nor the webhook state is shown.
     *
     * @return void
     */
    public function test_without_token_no_connection_or_webhook_state_is_shown(): void {
        $this->resetAfterTest();

        $html = $this->render_with(['bot_token_set' => false, 'bot_verified' => 0, 'bot_username' => '']);

        $this->assertStringNotContainsString(get_string('telegram_status_connected', 'local_jedomi'), $html);
        $this->assertStringNotContainsString(get_string('telegram_status_disconnected', 'local_jedomi'), $html);
        $this->assertStringNotContainsString(get_string('telegram_webhook_status', 'local_jedomi'), $html);
    }

    /**
     * MDL-UNIT-055: with the integration disabled the card content is hidden; enabled it is shown.
     *
     * @return void
     */
    public function test_disabled_integration_hides_the_card_content(): void {
        $this->resetAfterTest();

        $disabled = $this->render_with(['enabled' => 0]);
        $enabled = $this->render_with(['enabled' => 1]);

        $this->assertMatchesRegularExpression('/local_jedomi-admin-int-body d-none"/', $disabled);
        $this->assertDoesNotMatchRegularExpression('/local_jedomi-admin-int-body d-none"/', $enabled);
    }

    /**
     * MDL-UNIT-055: the card includes the webhook URL and the setup guide.
     *
     * @return void
     */
    public function test_card_includes_the_webhook_url_and_the_setup_guide(): void {
        $this->resetAfterTest();

        $html = $this->render_with(['webhook_url' => 'https://example.com/local/jedomi/telegram_webhook.php']);

        $this->assertStringContainsString('value="https://example.com/local/jedomi/telegram_webhook.php"', $html);
        $this->assertStringContainsString(get_string('telegram_setup_guide', 'local_jedomi'), $html);
        foreach ([1, 2, 3, 4] as $step) {
            $this->assertStringContainsString(get_string('telegram_step' . $step . '_title', 'local_jedomi'), $html);
        }
    }

    /**
     * MDL-UNIT-056: the setup guide describes the automatic webhook registration and the real steps, each group and field
     * has a Telegram title and a name that describes it, and no help mentions retired components.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_guide_help_and_labels_describe_the_real_behaviour(): void {
        $this->markTestSkipped('Pending fix: the guide still asks to save the switch that saves itself and misnames the '
            . 'profile section, the section titles do not match their fields and the block time is labelled Wait time.');
    }

    /**
     * MDL-UNIT-087: after a successful registration the webhook box shows the webhook as configured and working, and after
     * a failed one the last error with its date.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_webhook_box_reflects_the_last_registration(): void {
        $this->markTestSkipped('Pending fix: after a successful registration the box shows Not verified instead of '
            . 'configured and working.');
    }

    /**
     * MDL-INT-014: when the webhook registration fails after saving a valid token, the token is kept and the webhook box
     * shows the error of the registration with its date.
     */
    public function test_failed_registration_after_saving_a_token_is_shown_with_its_date(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        set_config('telegram_enabled', 1, 'local_jedomi');
        $client = new class ('123:abc') extends \local_jedomi\telegram\api_client {
            #[\Override]
            public function get_me(): ?array {
                return ['id' => 1, 'username' => 'jedomibot', 'first_name' => 'Jedomi'];
            }
            #[\Override]
            public function delete_webhook(): bool {
                return true;
            }
            #[\Override]
            public function set_webhook(string $url): bool {
                return false;
            }
        };
        $before = time();

        $this->assertTrue(admin_settings_service::verify_and_save_telegram_token('123:abc', $client));
        $data = (new integrations_tab(admin_settings_service::get_telegram_settings(), '<form></form>'))
            ->export_for_template($PAGE->get_renderer('local_jedomi'));

        $this->assertSame('123:abc', \local_jedomi\telegram\manager::get_bot_token());
        $this->assertTrue($data['haslasterror']);
        $this->assertNotSame('', $data['lasterror']);
        $this->assertTrue($data['haslasterrortime']);
        $this->assertGreaterThanOrEqual($before, $data['lasterrortime']);
        $this->assertFalse($data['webhookok']);
    }

    /**
     * MDL-INT-098: Regenerate secret regenerates the webhook secret and registers the webhook again without saving the
     * other fields of the form.
     * [Pendiente:skip]
     *
     * @return void
     */
    public function test_regenerating_the_secret_does_not_save_the_form(): void {
        $this->markTestSkipped('Pending fix: Regenerate secret also saves the token and the limits of the form, and with '
            . 'an invalid token the secret is not regenerated.');
    }

    /**
     * The Spanish Telegram texts (settings, guides, bot messages and errors) address the reader formally, as the rest of
     * the plugin does, without voseo or informal forms.
     */
    public function test_spanish_telegram_strings_use_the_formal_form(): void {
        $informal = '/\b(?:tu|tus|te|tienes|puedes|estás|tenés|podés|vas a|andá|hacé|usá|guardá|exponé|enviá|intentá|contactá|'
            . 'abrí|elegí|seguí|pegá|copiá|activá|verificá|generá|simplificá|empezá|desactivá|vinculá|dividila|buscá|revisá|'
            . 'regeneralo|pégalo|déjalo|inténtalo|envíalo|escríbele|dame)\b/iu';
        $strings = get_string_manager()->load_component_strings('local_jedomi', 'es');
        foreach ($strings as $key => $text) {
            if (preg_match('/^(?:telegram_|error_telegram_)/', $key)) {
                $this->assertDoesNotMatchRegularExpression($informal, $text, "es/{$key}");
            }
        }
    }
}
