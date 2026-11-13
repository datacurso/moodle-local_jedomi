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
     * Fake Telegram client that answers getMe with a bot and the webhook registration with the given result.
     *
     * @param bool $webhookok Result of set_webhook().
     * @return \local_jedomi\telegram\api_client
     */
    private function fake_client(bool $webhookok): \local_jedomi\telegram\api_client {
        return new class ($webhookok) extends \local_jedomi\telegram\api_client {
            /** @var bool Result of set_webhook(). */
            private bool $webhookok;

            /**
             * Store the canned answer.
             *
             * @param bool $webhookok Result of set_webhook().
             */
            public function __construct(bool $webhookok) {
                parent::__construct('123:abc');
                $this->webhookok = $webhookok;
            }

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
                return $this->webhookok;
            }
        };
    }

    /**
     * MDL-UNIT-056: the setup guide describes the automatic webhook registration and the real steps, each group and
     * field has a Telegram title and a name that describes it, and no help mentions retired components.
     *
     * @return void
     */
    public function test_guide_help_and_labels_describe_the_real_behaviour(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $form = new \local_jedomi\form\telegram_settings_form(null, ['roles' => [], 'tokenset' => true]);

        $data = (new integrations_tab(admin_settings_service::get_telegram_settings(), $form->render()))
            ->export_for_template($PAGE->get_renderer('local_jedomi'));
        $html = $data['form'];

        $userstep1 = $data['usersteps'][0]['desc'];
        $this->assertStringContainsString(get_string('pluginname', 'local_jedomi'), $userstep1, 'Profile section named as shown');
        $strings = get_string_manager();
        foreach (['telegram_bot_heading', 'telegram_limits_heading', 'telegram_access_heading'] as $heading) {
            $this->assertStringContainsString(get_string($heading, 'local_jedomi'), $html);
            $this->assertStringContainsString('Telegram', $strings->get_string($heading, 'local_jedomi', null, 'en'));
        }
        $this->assertStringNotContainsString(get_string('security_heading', 'local_jedomi'), $html);
        $this->assertNotSame(
            $strings->get_string('telegram_cooldown', 'local_jedomi', null, 'en'),
            $strings->get_string('telegram_gateway_timeout', 'local_jedomi', null, 'en')
        );
        $this->assertSame('Block time (minutes)', $strings->get_string('telegram_cooldown', 'local_jedomi', null, 'en'));
        $this->assertStringNotContainsString('toggle', $strings->get_string('telegram_step2_desc', 'local_jedomi', null, 'en'));
    }

    /**
     * MDL-UNIT-087: after a successful registration the webhook box shows the webhook as configured and working, and
     * after a failed one the last error with its date.
     *
     * @return void
     */
    public function test_webhook_box_reflects_the_last_registration(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/admin_settings.php'));
        $renderer = $PAGE->get_renderer('local_jedomi');

        $this->assertTrue(admin_settings_service::verify_and_save_telegram_token('123:abc', $this->fake_client(true)));
        $ok = (new integrations_tab(admin_settings_service::get_telegram_settings(), ''))->export_for_template($renderer);

        $this->assertTrue($ok['webhookok']);
        $this->assertFalse($ok['webhooknotchecked']);
        $this->assertFalse($ok['haslasterror']);

        $before = time();
        $this->assertTrue(admin_settings_service::verify_and_save_telegram_token('123:abc', $this->fake_client(false)));
        $failed = (new integrations_tab(admin_settings_service::get_telegram_settings(), ''))->export_for_template($renderer);

        $this->assertFalse($failed['webhookok']);
        $this->assertTrue($failed['haslasterror']);
        $this->assertGreaterThanOrEqual($before, $failed['lasterrortime']);
    }

    /**
     * MDL-INT-014: when the webhook registration fails after saving a valid token, the token is kept and the webhook
     * box shows the error of the registration with its date.
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
     *
     * @return void
     */
    public function test_regenerating_the_secret_does_not_save_the_form(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        admin_settings_service::save_telegram_settings(30, 5, 10, 45, []);
        $oldsecret = \local_jedomi\telegram\manager::get_webhook_secret();
        \local_jedomi\form\telegram_settings_form::mock_submit([
            'telegram_bot_token' => 'not-a-valid-token',
            'telegram_rate_limit' => 99999,
            'telegram_cooldown' => 9,
            'telegram_max_turns' => 10,
            'telegram_gateway_timeout' => 45,
            'regeneratesecret' => get_string('regenerate', 'local_jedomi'),
        ]);

        $form = new \local_jedomi\form\telegram_settings_form(null, ['roles' => [], 'tokenset' => false]);

        $this->assertNull($form->get_data(), 'The rest of the form is neither validated nor saved');
        $this->assertTrue($form->no_submit_button_pressed());
        \local_jedomi\telegram\manager::regenerate_webhook_secret();
        $this->assertNotSame($oldsecret, \local_jedomi\telegram\manager::get_webhook_secret());
        $settings = admin_settings_service::get_telegram_settings();
        $this->assertSame(30, $settings['rate_limit']);
        $this->assertSame(5, $settings['cooldown']);
        $this->assertNull(\local_jedomi\telegram\manager::get_bot_token(), 'The typed token is not saved');
    }

    /**
     * MDL-UNIT-119: the Spanish Telegram texts (settings, guides, bot messages and errors) address the reader formally,
     * as the rest of the plugin does, without voseo or informal forms.
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
