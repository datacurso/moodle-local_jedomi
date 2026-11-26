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

namespace local_jedomi\telegram;

/**
 * Tests for the Telegram webhook secret, sent by Telegram in a header and never in the address (JEDOMI-SEC-004).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\manager
 * @covers     \local_jedomi\telegram\api_client
 * @covers     \local_jedomi\telegram\webhook_handler
 */
final class webhook_secret_test extends \advanced_testcase {
    /** @var string Server key of the secret header Telegram sends with every update. */
    private const HEADER = 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN';

    #[\Override]
    protected function tearDown(): void {
        unset($_GET['secret'], $_POST['secret'], $_REQUEST['secret']);
        parent::tearDown();
    }

    /**
     * Telegram client that records the requests instead of sending them and answers them with success.
     *
     * @return api_client
     */
    public static function recording_client(): api_client {
        return new class ('123:abc') extends api_client {
            /** @var array Requests made, as [method, params]. */
            public array $requests = [];

            #[\Override]
            protected function post(string $method, array $params): ?array {
                $this->requests[] = [$method, $params];
                return ['ok' => true, 'result' => true];
            }
        };
    }

    /**
     * JEDOMI-SEC-004: a request with the secret in the Telegram header is accepted.
     */
    public function test_request_with_the_secret_header_is_accepted(): void {
        $this->resetAfterTest();

        $this->assertTrue(manager::is_valid_webhook_request([self::HEADER => manager::get_webhook_secret()]));
    }

    /**
     * JEDOMI-SEC-004: a request without the header, with an empty one or with a wrong one is refused.
     */
    public function test_request_without_the_right_header_is_refused(): void {
        $this->resetAfterTest();
        $secret = manager::get_webhook_secret();

        $this->assertFalse(manager::is_valid_webhook_request([]));
        $this->assertFalse(manager::is_valid_webhook_request([self::HEADER => '']));
        $this->assertFalse(manager::is_valid_webhook_request([self::HEADER => 'wrongsecret123']));
        $this->assertFalse(manager::is_valid_webhook_request([self::HEADER => strtoupper($secret)]));
        $this->assertFalse(manager::is_valid_webhook_request([self::HEADER => ['array']]));
    }

    /**
     * JEDOMI-SEC-004: the secret in the address of the request is no longer accepted, and the endpoint does not read it.
     */
    public function test_secret_in_the_address_is_not_accepted(): void {
        global $CFG;
        $this->resetAfterTest();
        $secret = manager::get_webhook_secret();
        $_GET['secret'] = $secret;
        $_POST['secret'] = $secret;
        $_REQUEST['secret'] = $secret;

        $this->assertFalse(manager::is_valid_webhook_request(['QUERY_STRING' => 'secret=' . $secret]));

        $source = file_get_contents($CFG->dirroot . '/local/jedomi/telegram_webhook.php');
        $this->assertStringNotContainsString("optional_param('secret'", $source);
        $this->assertStringContainsString('manager::is_valid_webhook_request($_SERVER)', $source);
    }

    /**
     * JEDOMI-SEC-004: the webhook address carries no secret, and the secret is generated once and kept.
     */
    public function test_webhook_address_has_no_secret(): void {
        global $CFG;
        $this->resetAfterTest();
        unset_config('telegram_webhook_secret', 'local_jedomi');

        $url = manager::get_webhook_url();

        $this->assertSame($CFG->wwwroot . '/local/jedomi/telegram_webhook.php', $url);
        $secret = manager::get_webhook_secret();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $secret);
        $this->assertSame($secret, manager::get_webhook_secret());
    }

    /**
     * JEDOMI-SEC-004: setWebhook registers the address without secret and the secret as secret_token.
     */
    public function test_set_webhook_sends_the_secret_token(): void {
        $client = self::recording_client();

        $this->assertTrue($client->set_webhook('https://example.com/local/jedomi/telegram_webhook.php', 'abc123'));

        $this->assertSame([['setWebhook', [
            'url' => 'https://example.com/local/jedomi/telegram_webhook.php',
            'secret_token' => 'abc123',
        ]]], $client->requests);
    }

    /**
     * JEDOMI-SEC-004: registering the webhook sends the current secret as secret_token, never in the address.
     */
    public function test_register_webhook_sends_the_current_secret_in_the_token(): void {
        $this->resetAfterTest();
        manager::set_bot_token('123:abc');
        $client = self::recording_client();

        $this->assertTrue(manager::register_webhook($client));

        $set = array_values(array_filter($client->requests, static fn(array $r): bool => $r[0] === 'setWebhook'));
        $this->assertCount(1, $set);
        $this->assertSame(manager::get_webhook_url(), $set[0][1]['url']);
        $this->assertStringNotContainsString('secret', $set[0][1]['url']);
        $this->assertSame(manager::get_webhook_secret(), $set[0][1]['secret_token']);
    }

    /**
     * RES-001: registering the webhook calls setWebhook alone, which replaces the previous webhook at once; it never
     * deletes it first, so a failed setWebhook cannot leave the bot without webhook.
     */
    public function test_register_webhook_never_deletes_the_webhook_first(): void {
        $this->resetAfterTest();
        manager::set_bot_token('123:abc');
        $client = self::recording_client();

        $this->assertTrue(manager::register_webhook($client));

        $this->assertSame(['setWebhook'], array_column($client->requests, 0));
        $this->assertArrayNotHasKey('drop_pending_updates', $client->requests[0][1]);
    }

    /**
     * RES-001: dropping the pending updates is asked to setWebhook itself, still without deleting the webhook.
     */
    public function test_register_webhook_can_drop_pending_updates_without_deleting_the_webhook(): void {
        $this->resetAfterTest();
        manager::set_bot_token('123:abc');
        $client = self::recording_client();

        $this->assertTrue(manager::register_webhook($client, true));

        $this->assertSame(['setWebhook'], array_column($client->requests, 0));
        $this->assertSame('true', $client->requests[0][1]['drop_pending_updates']);
    }

    /**
     * RES-001: a new secret is stored only after Telegram accepted it in setWebhook, so the stored secret is always the
     * one Telegram sends.
     */
    public function test_new_secret_is_stored_only_after_telegram_accepts_it(): void {
        $this->resetAfterTest();
        manager::set_bot_token('123:abc');
        $oldsecret = manager::get_webhook_secret();
        $client = self::recording_client();

        $this->assertTrue(manager::register_webhook_with_new_secret($client));

        $this->assertSame(['setWebhook'], array_column($client->requests, 0));
        $newsecret = manager::get_webhook_secret();
        $this->assertNotSame($oldsecret, $newsecret);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $newsecret);
        $this->assertSame($newsecret, $client->requests[0][1]['secret_token']);
        $this->assertSame('', manager::get_last_webhook_error());
    }

    /**
     * RES-001: when Telegram refuses the new secret, the stored secret stays and the error is recorded.
     */
    public function test_new_secret_is_not_stored_when_telegram_refuses_it(): void {
        $this->resetAfterTest();
        manager::set_bot_token('123:abc');
        $oldsecret = manager::get_webhook_secret();
        $client = new class ('123:abc') extends api_client {
            /** @var array Requests made, as [method, params]. */
            public array $requests = [];

            #[\Override]
            protected function post(string $method, array $params): ?array {
                $this->requests[] = [$method, $params];
                return null;
            }
        };

        $this->assertFalse(manager::register_webhook_with_new_secret($client));

        $this->assertSame(['setWebhook'], array_column($client->requests, 0));
        $this->assertSame($oldsecret, manager::get_webhook_secret());
        $this->assertNotSame('', manager::get_last_webhook_error());
    }

    /**
     * RES-001: while the webhook must be registered again, the kept secret (sent in the webhook address by earlier
     * versions) is refused in the header too, so nobody who read it from a log can send updates.
     */
    public function test_kept_secret_is_refused_while_the_webhook_must_be_registered_again(): void {
        $this->resetAfterTest();
        $secret = manager::get_webhook_secret();

        manager::require_webhook_registration();
        $this->assertFalse(manager::is_valid_webhook_request([self::HEADER => $secret]));

        manager::clear_webhook_registration_need();
        $this->assertTrue(manager::is_valid_webhook_request([self::HEADER => $secret]));
    }

    /**
     * JEDOMI-SEC-004: the self-ping that continues a long turn verifies TLS and sends the secret in the header.
     */
    public function test_self_ping_verifies_tls_and_sends_the_secret_in_the_header(): void {
        global $CFG;
        $this->resetAfterTest();
        $secret = manager::get_webhook_secret();

        $request = webhook_handler::self_ping_request('783000001');

        $this->assertStringStartsWith($CFG->wwwroot . '/local/jedomi/telegram_webhook.php?', $request['url']);
        $this->assertStringNotContainsString('secret', $request['url']);
        $this->assertStringNotContainsString($secret, $request['url']);
        $this->assertStringContainsString('internal_ping=1', $request['url']);
        $options = $request['options'];
        $this->assertTrue($options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertContains('X-Telegram-Bot-Api-Secret-Token: ' . $secret, $request['headers']);
        // JEDOMI-SEC-018: sent with Moodle's \curl, which never follows a redirect with the secret.
        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $options['CURLOPT_MAXREDIRS']);
    }

    /**
     * The self-ping carries the encoded chat id and the session generation of the saved tool loop, with the JSON
     * content type, a five-second timeout and no other header.
     */
    public function test_self_ping_carries_the_chat_and_its_session_generation(): void {
        global $CFG;
        $this->resetAfterTest();
        $secret = manager::get_webhook_secret();
        session::set_context('tool_loop_-1001', ['session_gen' => 1764100000]);

        $request = webhook_handler::self_ping_request('-1001');
        $fresh = webhook_handler::self_ping_request('783000002');

        $this->assertSame(
            $CFG->wwwroot . '/local/jedomi/telegram_webhook.php?internal_ping=1&chat_id=-1001&session_gen=1764100000',
            $request['url']
        );
        $this->assertSame(['Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $secret], $request['headers']);
        $this->assertSame([
            'CURLOPT_TIMEOUT' => 5,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXREDIRS' => 0,
        ], $request['options']);
        $this->assertStringEndsWith('?internal_ping=1&chat_id=783000002&session_gen=0', $fresh['url'], 'No saved loop: 0');
    }

    /**
     * FIX-001: dropping the pending updates while the webhook must be registered again registers it with a new secret
     * and only then clears the need; otherwise it keeps the secret.
     */
    public function test_drop_pending_rotates_the_secret_while_the_registration_is_pending(): void {
        $this->resetAfterTest();
        manager::set_bot_token('123:abc');
        $secret = manager::get_webhook_secret();

        $client = self::recording_client();
        $this->assertTrue(manager::drop_pending_updates($client));
        $this->assertSame($secret, manager::get_webhook_secret(), 'Normal case: the secret is kept');
        $this->assertSame('true', $client->requests[0][1]['drop_pending_updates']);

        manager::require_webhook_registration();
        $client = self::recording_client();
        $this->assertTrue(manager::drop_pending_updates($client));
        $newsecret = manager::get_webhook_secret();
        $this->assertNotSame($secret, $newsecret, 'The pending registration gets a new secret');
        $this->assertSame($newsecret, $client->requests[0][1]['secret_token']);
        $this->assertSame('true', $client->requests[0][1]['drop_pending_updates']);
        $this->assertFalse(manager::needs_webhook_registration());
    }
}
