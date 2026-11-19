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
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/filelib.php');
    }

    /**
     * Client whose transport records each request instead of sending it, and answers with a fixed status and body.
     *
     * @param int $status HTTP status of every answer.
     * @param string $body Body of every answer.
     * @return api_client Client with a public $requests array of [method, url, options].
     */
    private static function recording_client(int $status, string $body): api_client {
        return new class ('123:secret-token', $status, $body) extends api_client {
            /** @var array Requests made, as [method, url, options]. */
            public array $requests = [];
            /** @var int HTTP status of every answer. */
            private int $status;
            /** @var string Body of every answer. */
            private string $body;

            /**
             * Client with the answer its transport gives.
             *
             * @param string $token Bot token.
             * @param int $status HTTP status.
             * @param string $body Body.
             */
            public function __construct(string $token, int $status, string $body) {
                parent::__construct($token);
                $this->status = $status;
                $this->body = $body;
            }

            #[\Override]
            protected function new_curl(): \curl {
                $client = $this;
                return new class ($client, $this->status, $this->body) extends \curl {
                    /** @var object Client that records the requests. */
                    private object $client;
                    /** @var int HTTP status. */
                    private int $status;
                    /** @var string Body. */
                    private string $answer;

                    /**
                     * Transport with a fixed answer.
                     *
                     * @param object $client Recording client.
                     * @param int $status HTTP status.
                     * @param string $answer Body.
                     */
                    public function __construct(object $client, int $status, string $answer) {
                        parent::__construct();
                        $this->client = $client;
                        $this->status = $status;
                        $this->answer = $answer;
                    }

                    #[\Override]
                    protected function request($url, $options = []) {
                        $this->client->requests[] = [isset($options['CURLOPT_HTTPGET']) ? 'GET' : 'POST', $url, $options];
                        $this->info = ['http_code' => $this->status];
                        $this->error = '';
                        $this->errno = 0;
                        return $this->answer;
                    }
                };
            }
        };
    }

    /**
     * JEDOMI-SEC-018: every request goes through Moodle's \curl with redirects off and TLS verified, so the proxy and
     * the cURL security of the site apply and the bot token is never sent to another host.
     */
    public function test_requests_use_moodle_curl_without_redirects(): void {
        $client = self::recording_client(200, '{"ok":true,"result":{"id":1,"username":"bot","first_name":"Bot"}}');

        $this->assertSame(['id' => 1, 'username' => 'bot', 'first_name' => 'Bot'], $client->get_me());

        $this->assertCount(1, $client->requests);
        [$method, $url, $options] = $client->requests[0];
        $this->assertSame('POST', $method);
        $this->assertSame('https://api.telegram.org/bot123:secret-token/getMe', $url);
        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $options['CURLOPT_MAXREDIRS']);
        $this->assertTrue($options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertSame(30, $options['CURLOPT_TIMEOUT']);
    }

    /**
     * JEDOMI-SEC-018: a file download that gets a redirect fails instead of following it with the token in the address.
     */
    public function test_download_with_a_redirect_fails(): void {
        $this->resetAfterTest();
        $client = new class ('123:secret-token') extends api_client {
            /** @var array Options of the download request. */
            public array $downloadoptions = [];

            #[\Override]
            protected function post(string $method, array $params): ?array {
                return ['ok' => true, 'result' => ['file_path' => 'documents/file_1.pdf']];
            }

            #[\Override]
            protected function new_curl(): \curl {
                $client = $this;
                return new class ($client) extends \curl {
                    /** @var object Client that records the options. */
                    private object $client;

                    /**
                     * Transport that answers every request with a redirect.
                     *
                     * @param object $client Recording client.
                     */
                    public function __construct(object $client) {
                        parent::__construct();
                        $this->client = $client;
                    }

                    #[\Override]
                    protected function request($url, $options = []) {
                        $this->client->downloadoptions = $options;
                        $this->info = ['http_code' => 302, 'redirect_url' => 'https://attacker.example/steal'];
                        $this->error = '';
                        $this->errno = 0;
                        return '';
                    }
                };
            }
        };

        $this->assertNull($client->download_file('file-id'));
        $this->assertFalse($client->downloadoptions['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $client->downloadoptions['CURLOPT_MAXREDIRS']);
    }

    /**
     * JEDOMI-SEC-018: a download answered with 200 is saved to a temporary file.
     */
    public function test_download_saves_the_file(): void {
        $this->resetAfterTest();
        $client = new class ('123:secret-token') extends api_client {
            #[\Override]
            protected function post(string $method, array $params): ?array {
                return ['ok' => true, 'result' => ['file_path' => 'documents/file_2.txt']];
            }
        };
        \curl::mock_response('file content');

        $path = $client->download_file('file-id');

        $this->assertNotNull($path);
        $this->assertSame('file content', file_get_contents($path));
        unlink($path);
    }

    /**
     * JEDOMI-SEC-018: a host blocked by curlsecurityblockedhosts is refused, so no request reaches it, and the bot
     * token does not reach the site logs through the url_blocked event of \curl.
     */
    public function test_blocked_host_is_refused(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', 'api.telegram.org');
        $client = new class ('123:secret-token') extends api_client {
            /** @var int Transports created, that is requests about to be sent. */
            public int $requests = 0;

            #[\Override]
            protected function new_curl(): \curl {
                $this->requests++;
                return parent::new_curl();
            }
        };
        $logfile = $CFG->dataroot . '/local/jedomi/jedomi.log';
        if (file_exists($logfile)) {
            unlink($logfile);
        }
        $sink = $this->redirectEvents();

        $this->assertNull($client->get_me());

        $events = $sink->get_events();
        $sink->close();
        $this->assertSame(0, $client->requests, 'No request was sent to the blocked host');
        $this->assertEmpty(array_filter($events, static fn($event) => $event instanceof \core\event\url_blocked));
        $log = file_exists($logfile) ? (string) file_get_contents($logfile) : '';
        $this->assertStringContainsString('blocked by the cURL security', $log);
        $this->assertStringNotContainsString('secret-token', $log);
    }

    /**
     * JEDOMI-SEC-018: a failed request never logs the bot token, even when the error quotes the address.
     */
    public function test_failure_never_logs_the_token(): void {
        global $CFG;
        $this->resetAfterTest();
        $client = new class ('123:secret-token') extends api_client {
            #[\Override]
            protected function new_curl(): \curl {
                return new class extends \curl {
                    #[\Override]
                    protected function request($url, $options = []) {
                        $this->info = ['http_code' => 0];
                        $this->errno = 6;
                        $this->error = 'Could not resolve ' . $url;
                        return $this->error;
                    }
                };
            }
        };
        $logfile = $CFG->dataroot . '/local/jedomi/jedomi.log';
        if (file_exists($logfile)) {
            unlink($logfile);
        }

        $this->assertNull($client->get_me());

        $log = file_exists($logfile) ? (string) file_get_contents($logfile) : '';
        $this->assertStringContainsString('E002', $log);
        $this->assertStringContainsString('[token]', $log);
        $this->assertStringNotContainsString('secret-token', $log);
    }

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
        $options = (new api_client('123:abc'))->curl_options();

        $this->assertSame(api_client::CONNECT_TIMEOUT, $options['CURLOPT_CONNECTTIMEOUT']);
        $this->assertSame(5, api_client::CONNECT_TIMEOUT);
        $this->assertSame(30, $options['CURLOPT_TIMEOUT']);
    }

    /**
     * RES-001: a caller that must not wait long (the site upgrade) can shorten the total timeout; the connect timeout
     * never exceeds it.
     */
    public function test_total_timeout_can_be_shortened(): void {
        $client = new api_client('123:abc');

        $client->set_timeout(10);
        $this->assertSame(10, $client->curl_options()['CURLOPT_TIMEOUT']);
        $this->assertSame(5, $client->curl_options()['CURLOPT_CONNECTTIMEOUT']);

        $client->set_timeout(3);
        $this->assertSame(3, $client->curl_options()['CURLOPT_TIMEOUT']);
        $this->assertSame(3, $client->curl_options()['CURLOPT_CONNECTTIMEOUT']);
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
                $this->timeouts[] = $this->curl_options()['CURLOPT_TIMEOUT'];
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
