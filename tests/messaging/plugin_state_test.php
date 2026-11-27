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

namespace local_jedomi\messaging;

use local_jedomi\telegram\api_client;
use local_jedomi\telegram\fake_telegram_api_client;
use local_jedomi\telegram\webhook_handler;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for the plugin state seam that channel code asks whether Jedomi is enabled.
 *
 * The real state delegates to local_jedomi_plugin_disabled_error(); a state registered in \core\di replaces it, so the
 * channels can be tested without the Datacurso AI provider.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\plugin_state
 * @covers     \local_jedomi\telegram\webhook_handler
 * @covers     ::local_jedomi_telegram_recipient
 */
final class plugin_state_test extends \advanced_testcase {
    /** @var string Linked Telegram chat id. */
    private const CHATID = '771000003';

    /** @var string Disabled message set through the seam, different from the provider message. */
    private const SEAM_MESSAGE = 'Disabled by the plugin state seam.';

    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function tearDown(): void {
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Skip when the Datacurso AI provider, which keeps the real plugin state, is not installed.
     *
     * @return void
     */
    private function require_provider(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the real plugin state is not available.');
        }
    }

    /**
     * Record the Telegram messages and link the admin to the test chat.
     *
     * @return void
     */
    private function link_admin_chat(): void {
        global $DB;
        $this->telegram = new fake_telegram_api_client();
        api_client::set_instance_for_testing($this->telegram);
        $DB->insert_record('local_jedomi_telegram_links', (object) [
            'userid' => (int) get_admin()->id,
            'chatid' => self::CHATID,
            'username' => 'linked',
            'confirmed' => 1,
            'timecreated' => time(),
            'lastproactivesent' => 0,
        ]);
    }

    /**
     * The container gives the real plugin state when no test replaces it.
     *
     * @return void
     */
    public function test_container_gives_the_real_state_by_default(): void {
        $state = \core\di::get(plugin_state::class);

        $this->assertInstanceOf(plugin_state::class, $state);
        $this->assertNotInstanceOf(fake_plugin_state::class, $state);
    }

    /**
     * Enabled in the provider, the real state reports no error, the same as local_jedomi_plugin_disabled_error().
     *
     * @return void
     */
    public function test_real_state_is_enabled_when_the_provider_enables_jedomi(): void {
        $this->require_provider();
        $this->resetAfterTest();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_jedomi', true);

        $state = new plugin_state();

        $this->assertNull($state->disabled_error());
        $this->assertTrue($state->is_enabled());
    }

    /**
     * Disabled in the provider, the real state returns the same error as local_jedomi_plugin_disabled_error().
     *
     * @return void
     */
    public function test_real_state_returns_the_provider_error_when_disabled(): void {
        $this->require_provider();
        $this->resetAfterTest();
        \aiprovider_datacurso\local\plugin_settings::set_enabled('local_jedomi', false);

        $state = new plugin_state();

        $this->assertSame(\local_jedomi_plugin_disabled_error(), $state->disabled_error());
        $this->assertSame('plugindisabled', $state->disabled_error()['code']);
        $this->assertFalse($state->is_enabled());
    }

    /**
     * A state registered in the container replaces the real one and can report Jedomi as enabled.
     *
     * @return void
     */
    public function test_container_state_can_be_forced_enabled(): void {
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());

        $state = \core\di::get(plugin_state::class);

        $this->assertNull($state->disabled_error());
        $this->assertTrue($state->is_enabled());
    }

    /**
     * A state registered in the container can report Jedomi as disabled with its own message.
     *
     * @return void
     */
    public function test_container_state_can_be_forced_disabled(): void {
        \core\di::set(plugin_state::class, fake_plugin_state::disabled(self::SEAM_MESSAGE));

        $state = \core\di::get(plugin_state::class);

        $this->assertSame(['code' => 'plugindisabled', 'message' => self::SEAM_MESSAGE], $state->disabled_error());
        $this->assertFalse($state->is_enabled());
    }

    /**
     * A Telegram message is answered with the disabled message of the plugin state seam.
     *
     * @return void
     */
    public function test_telegram_message_reads_the_plugin_state_seam(): void {
        $this->resetAfterTest();
        $this->link_admin_chat();
        \core\di::set(plugin_state::class, fake_plugin_state::disabled(self::SEAM_MESSAGE));

        webhook_handler::handle(['message' => ['chat' => ['id' => self::CHATID], 'text' => 'How many users?']]);

        $this->assertSame([self::SEAM_MESSAGE], array_column($this->telegram->sent, 'text'));
    }

    /**
     * The Telegram self-ping stops with the disabled message of the plugin state seam.
     *
     * @return void
     */
    public function test_telegram_internal_ping_reads_the_plugin_state_seam(): void {
        $this->resetAfterTest();
        $this->link_admin_chat();
        \core\di::set(plugin_state::class, fake_plugin_state::disabled(self::SEAM_MESSAGE));

        webhook_handler::handle_internal_ping(self::CHATID);

        $this->assertSame([self::SEAM_MESSAGE], array_column($this->telegram->sent, 'text'));
    }

    /**
     * Forced enabled through the seam, a valid Telegram link is a recipient.
     *
     * @return void
     */
    public function test_telegram_recipient_is_served_when_the_seam_is_enabled(): void {
        $this->resetAfterTest();
        $this->link_admin_chat();
        set_config('telegram_enabled', 1, 'local_jedomi');
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());

        $link = \local_jedomi_telegram_recipient((int) get_admin()->id);

        $this->assertNotNull($link);
        $this->assertSame(self::CHATID, (string) $link->chatid);
    }

    /**
     * Forced disabled through the seam, a valid Telegram link is not a recipient.
     *
     * @return void
     */
    public function test_telegram_recipient_is_not_served_when_the_seam_is_disabled(): void {
        $this->resetAfterTest();
        $this->link_admin_chat();
        set_config('telegram_enabled', 1, 'local_jedomi');
        \core\di::set(plugin_state::class, fake_plugin_state::disabled(self::SEAM_MESSAGE));

        $this->assertNull(\local_jedomi_telegram_recipient((int) get_admin()->id));
    }
}
