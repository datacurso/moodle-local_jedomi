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
 * Tests for the Telegram Bot API client.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\api_client
 */
final class api_client_test extends \advanced_testcase {
    /**
     * The request body separates fields with a plain "&" even though Moodle sets arg_separator.output to "&amp;".
     * MDL-CTR-009: the body of the Telegram calls separates the fields correctly.
     *
     * Otherwise Telegram reads only the first field and rejects sendMessage with "message text is empty".
     */
    public function test_build_body_separates_fields_with_a_plain_ampersand(): void {
        $this->assertSame('&amp;', ini_get('arg_separator.output'));

        $body = api_client::build_body(['chat_id' => '7174568550', 'text' => 'Hi & bye', 'parse_mode' => 'HTML']);

        parse_str($body, $fields);
        $this->assertSame('chat_id=7174568550&text=Hi+%26+bye&parse_mode=HTML', $body);
        $this->assertSame(['chat_id' => '7174568550', 'text' => 'Hi & bye', 'parse_mode' => 'HTML'], $fields);
    }

    /**
     * RES-001: every request gives up connecting after a few seconds and has a total limit, so an unreachable Telegram
     * cannot block the caller for long.
     */
    public function test_requests_have_a_connect_timeout_and_a_total_timeout(): void {
        $options = (new api_client('123:abc'))->curl_options('a=1');

        $this->assertSame(api_client::CONNECT_TIMEOUT, $options[CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(5, api_client::CONNECT_TIMEOUT);
        $this->assertSame(30, $options[CURLOPT_TIMEOUT]);
        $this->assertSame('a=1', $options[CURLOPT_POSTFIELDS]);
        $this->assertTrue($options[CURLOPT_POST]);
        $this->assertTrue($options[CURLOPT_RETURNTRANSFER]);
    }

    /**
     * RES-001: a caller that must not wait long (the site upgrade) can shorten the total timeout; the connect timeout
     * never exceeds it.
     */
    public function test_total_timeout_can_be_shortened(): void {
        $client = new api_client('123:abc');

        $client->set_timeout(10);
        $this->assertSame(10, $client->curl_options('')[CURLOPT_TIMEOUT]);
        $this->assertSame(5, $client->curl_options('')[CURLOPT_CONNECTTIMEOUT]);

        $client->set_timeout(3);
        $this->assertSame(3, $client->curl_options('')[CURLOPT_TIMEOUT]);
        $this->assertSame(3, $client->curl_options('')[CURLOPT_CONNECTTIMEOUT]);
    }

    /**
     * RES-002: a chat action can use its own short timeout, and the next requests keep the usual one.
     */
    public function test_chat_action_can_use_a_short_timeout(): void {
        $client = new class ('123:abc') extends api_client {
            /** @var int[] Total timeout of each request. */
            public array $timeouts = [];

            #[\Override]
            protected function post(string $method, array $params): ?array {
                $this->timeouts[] = $this->curl_options('')[CURLOPT_TIMEOUT];
                return ['ok' => true];
            }
        };

        $this->assertTrue($client->send_chat_action('42', 'typing', 2));
        $client->send_chat_action('42');

        $this->assertSame([2, 30], $client->timeouts);
    }

    /**
     * RES-001: setWebhook can drop the pending updates itself, so the webhook never has to be deleted first.
     */
    public function test_set_webhook_can_drop_pending_updates(): void {
        $client = new class ('123:abc') extends api_client {
            /** @var array Requests made, as [method, params]. */
            public array $requests = [];

            #[\Override]
            protected function post(string $method, array $params): ?array {
                $this->requests[] = [$method, $params];
                return ['ok' => true, 'result' => true];
            }
        };

        $this->assertTrue($client->set_webhook('https://example.com/local/jedomi/telegram_webhook.php', 'abc123', true));

        $this->assertSame([['setWebhook', [
            'url' => 'https://example.com/local/jedomi/telegram_webhook.php',
            'secret_token' => 'abc123',
            'drop_pending_updates' => 'true',
        ]]], $client->requests);
    }
}
