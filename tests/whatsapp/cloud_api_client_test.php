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

use local_jedomi\ai\fake_curl;

/**
 * Tests for the WhatsApp Cloud API client. No request reaches the network: every transport is a fake_curl.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\cloud_api_client
 * @covers     \local_jedomi\whatsapp\cloud_api_exception
 */
final class cloud_api_client_test extends \advanced_testcase {
    /** @var string Access token of the tests. */
    private const TOKEN = 'EAAGsecret-token-123';

    /** @var string Phone number ID of the tests. */
    private const PHONE_ID = '106540352242922';

    /** @var string Messages endpoint of the tests. */
    private const MESSAGES_URL = 'https://graph.facebook.com/v25.0/106540352242922/messages';

    /** @var string Answer of Meta to a sent message. */
    private const SENT = '{"messaging_product":"whatsapp","contacts":[{"input":"16505551234","wa_id":"16505551234"}],'
        . '"messages":[{"id":"wamid.HBgLMTY1MDU1NTEyMzQVAgARGBI5"}]}';

    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/filelib.php');
        require_once(__DIR__ . '/../fixtures/fake_curl.php');
    }

    /**
     * Client whose transports answer, in order, the responses given, and are kept in its public $curls.
     *
     * @param array $responses Responses as [body, http code, curl error, curl errno].
     * @param string $token Access token.
     * @return cloud_api_client
     */
    private static function client(array $responses, string $token = self::TOKEN, int $chunksize = 4): cloud_api_client {
        return new class ($responses, $token, $chunksize) extends cloud_api_client {
            /** @var fake_curl[] Transports created, one per request. */
            public array $curls = [];
            /** @var array Responses still to give. */
            private array $responses;
            /** @var int Bytes handed to a write callback at a time. */
            private int $chunksize;

            /**
             * Client with the responses of its transports.
             *
             * @param array $responses Responses.
             * @param string $token Access token.
             * @param int $chunksize Bytes handed to a write callback at a time.
             */
            public function __construct(array $responses, string $token, int $chunksize) {
                parent::__construct($token, '106540352242922', 'v25.0');
                $this->responses = $responses;
                $this->chunksize = $chunksize;
            }

            #[\Override]
            protected function new_curl(): \curl {
                $curl = cloud_api_client_test::streaming_curl($this->chunksize);
                $response = array_shift($this->responses) ?? ['', 0];
                $curl->enqueue($response[0], $response[1], $response[2] ?? '', $response[3] ?? 0);
                $this->curls[] = $curl;
                return $curl;
            }
        };
    }

    /**
     * Transport whose GET hands the body to a CURLOPT_WRITEFUNCTION in chunks, as libcurl does, and stops as soon as the
     * callback takes less than a chunk (CURLE_WRITE_ERROR). The body is written before a queued cURL error is reported,
     * as a connection dropped in the middle of a download leaves part of the file.
     *
     * @param int $chunksize Bytes handed to the callback at a time.
     * @return fake_curl Transport; its public $accepted holds the bytes the callback took.
     */
    public static function streaming_curl(int $chunksize): fake_curl {
        return new class ($chunksize) extends fake_curl {
            /** @var int Bytes the write callback took. */
            public int $accepted = 0;
            /** @var int Bytes handed to the callback at a time. */
            private int $chunksize;

            /**
             * Transport with the size of its chunks.
             *
             * @param int $chunksize Bytes handed to the callback at a time.
             */
            public function __construct(int $chunksize) {
                parent::__construct();
                $this->chunksize = $chunksize;
            }

            #[\Override]
            public function get($url, $params = [], $options = []) {
                if (!isset($options['CURLOPT_WRITEFUNCTION'])) {
                    return parent::get($url, $params, $options);
                }
                $this->calls[] = ['url' => (string) $url, 'params' => $params, 'options' => $options, 'method' => 'GET'];
                $response = array_shift($this->queue) ?? ['body' => '', 'http_code' => 0, 'error' => '', 'errno' => 0];
                $this->info = ['http_code' => $response['http_code']];
                foreach (str_split($response['body'], $this->chunksize) as $chunk) {
                    if ($chunk === '') {
                        continue;
                    }
                    $taken = $options['CURLOPT_WRITEFUNCTION'](null, $chunk);
                    if ($taken !== strlen($chunk)) {
                        $this->errno = CURLE_WRITE_ERROR;
                        $this->error = 'Failure writing output to destination';
                        return false;
                    }
                    $this->accepted += $taken;
                }
                $this->error = $response['error'];
                $this->errno = $response['errno'];
                return true;
            }
        };
    }

    /**
     * Only request made by a client.
     *
     * @param cloud_api_client $client Client.
     * @return array Call recorded by fake_curl, plus its headers.
     */
    private static function only_request(cloud_api_client $client): array {
        self::assertCount(1, $client->curls);
        $curl = $client->curls[0];
        self::assertCount(1, $curl->calls);
        return $curl->calls[0] + ['headers' => $curl->header];
    }

    /**
     * Content of the technical log, emptied first by {@see self::clear_log()}.
     *
     * @return string
     */
    private static function log(): string {
        global $CFG;
        $logfile = $CFG->dataroot . '/local/jedomi/jedomi.log';
        return file_exists($logfile) ? (string) file_get_contents($logfile) : '';
    }

    /**
     * Empty the technical log.
     */
    private static function clear_log(): void {
        global $CFG;
        $logfile = $CFG->dataroot . '/local/jedomi/jedomi.log';
        if (file_exists($logfile)) {
            unlink($logfile);
        }
    }

    public function test_send_text_to_a_phone(): void {
        $client = self::client([[self::SENT, 200]]);

        $result = $client->send_text(recipient_ref::phone('16505551234'), 'Hello *world*');

        $this->assertSame('wamid.HBgLMTY1MDU1NTEyMzQVAgARGBI5', $result['messages'][0]['id']);
        $request = self::only_request($client);
        $this->assertSame(self::MESSAGES_URL, $request['url']);
        $this->assertArrayNotHasKey('method', $request);
        $this->assertContains('Authorization: Bearer ' . self::TOKEN, $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '16505551234',
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => 'Hello *world*'],
        ], json_decode($request['params'], true));
    }

    public function test_send_text_to_a_bsuid_uses_recipient_only(): void {
        $client = self::client([[self::SENT, 200]]);

        $client->send_text(recipient_ref::from_link(null, 'US.13491208655302741918'), 'Hi');

        $body = json_decode(self::only_request($client)['params'], true);
        $this->assertSame('US.13491208655302741918', $body['recipient']);
        $this->assertArrayNotHasKey('to', $body);
    }

    public function test_body_keeps_unicode_and_slashes_readable(): void {
        $client = self::client([[self::SENT, 200]]);

        $client->send_text(recipient_ref::phone('1'), 'Café 😀 https://x.test/a');

        $raw = self::only_request($client)['params'];
        $this->assertStringContainsString('Café 😀 https://x.test/a', $raw);
    }

    public function test_requests_never_follow_redirects_and_verify_tls(): void {
        $client = self::client([[self::SENT, 200]]);

        $client->send_text(recipient_ref::phone('1'), 'x');

        $options = self::only_request($client)['options'];
        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $options['CURLOPT_MAXREDIRS']);
        $this->assertTrue($options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertSame(30, $options['CURLOPT_TIMEOUT']);
        $this->assertSame(5, $options['CURLOPT_CONNECTTIMEOUT']);
    }

    /**
     * Timeouts set and the options they give.
     *
     * @return array
     */
    public static function timeout_provider(): array {
        return [
            'longer than the connect timeout' => [60, 60, 5],
            'equal to the connect timeout' => [5, 5, 5],
            'shorter than the connect timeout' => [3, 3, 3],
            'zero is raised to one second' => [0, 1, 1],
            'negative is raised to one second' => [-10, 1, 1],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider timeout_provider
     * @param int $seconds Timeout set.
     * @param int $total Expected CURLOPT_TIMEOUT.
     * @param int $connect Expected CURLOPT_CONNECTTIMEOUT.
     */
    public function test_set_timeout_clamps_the_request_and_connect_timeouts(int $seconds, int $total, int $connect): void {
        $client = self::client([[self::SENT, 200]]);

        $client->set_timeout($seconds);
        $client->send_text(recipient_ref::phone('1'), 'x');

        $options = self::only_request($client)['options'];
        $this->assertSame($total, $options['CURLOPT_TIMEOUT']);
        $this->assertSame($connect, $options['CURLOPT_CONNECTTIMEOUT']);
    }

    /**
     * Calls that need the phone number ID.
     *
     * @return array
     */
    public static function phone_number_call_provider(): array {
        return [
            'send_text' => ['send_text'],
            'mark_read_typing' => ['mark_read_typing'],
            'health_check' => ['health_check'],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider phone_number_call_provider
     * @param string $method Method.
     */
    public function test_missing_phone_number_id_fails_without_request(string $method): void {
        $this->resetAfterTest();
        $client = new class (self::TOKEN, '', 'v25.0') extends cloud_api_client {
            /** @var int Transports created. */
            public int $requests = 0;

            #[\Override]
            protected function new_curl(): \curl {
                $this->requests++;
                return new fake_curl();
            }
        };
        $args = [
            'send_text' => [recipient_ref::phone('1'), 'Hi'],
            'mark_read_typing' => ['wamid.in'],
            'health_check' => [],
        ][$method];

        try {
            $client->$method(...$args);
            $this->fail('A request without phone number ID was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertStringContainsString('phone number ID not configured', $e->getMessage());
        }
        $this->assertSame(0, $client->requests);
    }

    public function test_send_template_with_components(): void {
        $client = self::client([[self::SENT, 200]]);
        $components = [[
            'type' => 'body',
            'parameters' => [['type' => 'text', 'text' => 'Math'], ['type' => 'text', 'text' => '4.5']],
        ]];

        $client->send_template(recipient_ref::phone('16505551234'), 'grade_posted', 'es', $components);

        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '16505551234',
            'type' => 'template',
            'template' => [
                'name' => 'grade_posted',
                'language' => ['code' => 'es'],
                'components' => $components,
            ],
        ], json_decode(self::only_request($client)['params'], true));
    }

    public function test_send_template_without_components_omits_them(): void {
        $client = self::client([[self::SENT, 200]]);

        $client->send_template(recipient_ref::phone('16505551234'), 'hello_world', 'en_US');

        $body = json_decode(self::only_request($client)['params'], true);
        $this->assertSame(['name' => 'hello_world', 'language' => ['code' => 'en_US']], $body['template']);
    }

    public function test_send_buttons(): void {
        $client = self::client([[self::SENT, 200]]);

        $client->send_buttons(recipient_ref::bsuid('CO.abc'), 'Confirm the change?', ['yes:12' => 'Yes', 'no:12' => 'No']);

        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'recipient' => 'CO.abc',
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => 'Confirm the change?'],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => 'yes:12', 'title' => 'Yes']],
                    ['type' => 'reply', 'reply' => ['id' => 'no:12', 'title' => 'No']],
                ]],
            ],
        ], json_decode(self::only_request($client)['params'], true));
    }

    public function test_numeric_button_ids_are_sent_as_strings(): void {
        $client = self::client([[self::SENT, 200]]);

        $client->send_buttons(recipient_ref::phone('1'), 'Pick', [1 => 'One']);

        $body = json_decode(self::only_request($client)['params'], true);
        $this->assertSame('1', $body['interactive']['action']['buttons'][0]['reply']['id']);
    }

    /**
     * Button sets Meta rejects.
     *
     * @return array
     */
    public static function invalid_buttons_provider(): array {
        return [
            'none' => [[]],
            'four' => [['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider invalid_buttons_provider
     * @param array $buttons Buttons.
     */
    public function test_invalid_button_count_is_rejected_without_request(array $buttons): void {
        $client = self::client([[self::SENT, 200]]);

        try {
            $client->send_buttons(recipient_ref::phone('1'), 'Pick', $buttons);
            $this->fail('An invalid button count was accepted');
        } catch (\coding_exception $e) {
            $this->assertCount(0, $client->curls);
        }
    }

    public function test_send_list(): void {
        $client = self::client([[self::SENT, 200]]);

        $rows = ['c:2' => 'Math', 'c:3' => 'Art'];
        $client->send_list(recipient_ref::phone('16505551234'), 'Choose a course', 'Courses', $rows, 'Your courses');

        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '16505551234',
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => 'Choose a course'],
                'action' => [
                    'button' => 'Courses',
                    'sections' => [[
                        'title' => 'Your courses',
                        'rows' => [['id' => 'c:2', 'title' => 'Math'], ['id' => 'c:3', 'title' => 'Art']],
                    ]],
                ],
            ],
        ], json_decode(self::only_request($client)['params'], true));
    }

    public function test_send_list_without_section_title_omits_it(): void {
        $client = self::client([[self::SENT, 200]]);

        $client->send_list(recipient_ref::phone('1'), 'Choose', 'Open', ['a' => 'A']);

        $body = json_decode(self::only_request($client)['params'], true);
        $this->assertSame([['rows' => [['id' => 'a', 'title' => 'A']]]], $body['interactive']['action']['sections']);
    }

    public function test_send_list_rejects_more_than_ten_rows(): void {
        $client = self::client([[self::SENT, 200]]);
        $rows = [];
        for ($i = 1; $i <= 11; $i++) {
            $rows['r' . $i] = 'Row ' . $i;
        }

        $this->expectException(\coding_exception::class);
        $client->send_list(recipient_ref::phone('1'), 'Choose', 'Open', $rows);
    }

    public function test_send_list_rejects_no_rows(): void {
        $client = self::client([[self::SENT, 200]]);

        $this->expectException(\coding_exception::class);
        $client->send_list(recipient_ref::phone('1'), 'Choose', 'Open', []);
    }

    /**
     * A timeout given to mark_read_typing limits that request only; the next request has the usual timeout again.
     */
    public function test_mark_read_typing_with_its_own_timeout(): void {
        $client = self::client([['{"success":true}', 200], ['{"success":true}', 200]]);

        $client->mark_read_typing('wamid.in', 3);
        $client->mark_read_typing('wamid.in');

        $this->assertCount(2, $client->curls);
        $first = $client->curls[0]->calls[0]['options'];
        $this->assertSame(3, $first['CURLOPT_TIMEOUT']);
        $this->assertSame(3, $first['CURLOPT_CONNECTTIMEOUT']);
        $this->assertSame(30, $client->curls[1]->calls[0]['options']['CURLOPT_TIMEOUT']);
    }

    public function test_mark_read_typing(): void {
        $client = self::client([['{"success":true}', 200]]);

        $result = $client->mark_read_typing('wamid.HBgLMTY1MDU1NTEyMzQVAgASGBQ');

        $this->assertSame(['success' => true], $result);
        $request = self::only_request($client);
        $this->assertSame(self::MESSAGES_URL, $request['url']);
        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => 'wamid.HBgLMTY1MDU1NTEyMzQVAgASGBQ',
            'typing_indicator' => ['type' => 'text'],
        ], json_decode($request['params'], true));
    }

    /**
     * Successful JSON answers to a sent message that carry no usable wamid.
     *
     * @return array
     */
    public static function send_answer_without_wamid_provider(): array {
        $answers = [
            'empty object' => '{}',
            'no messages' => '{"messaging_product":"whatsapp","contacts":[]}',
            'empty messages' => '{"messages":[]}',
            'empty id' => '{"messages":[{"id":""}]}',
            'numeric id' => '{"messages":[{"id":123}]}',
            'messages not a list' => '{"messages":"wamid.x"}',
        ];
        $cases = [];
        foreach (['send_text', 'send_template', 'send_buttons', 'send_list'] as $method) {
            foreach ($answers as $name => $answer) {
                $cases[$method . ': ' . $name] = [$method, $answer];
            }
        }
        return $cases;
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider send_answer_without_wamid_provider
     * @param string $method Send method.
     * @param string $answer Answer of Meta.
     */
    public function test_send_answer_without_wamid_fails(string $method, string $answer): void {
        $this->resetAfterTest();
        $client = self::client([[$answer, 200]]);
        $to = recipient_ref::phone('16505551234');
        $args = [
            'send_text' => [$to, 'Hi'],
            'send_template' => [$to, 'hello_world', 'en_US'],
            'send_buttons' => [$to, 'Pick', ['a' => 'A']],
            'send_list' => [$to, 'Pick', 'Open', ['a' => 'A']],
        ][$method];

        try {
            $client->$method(...$args);
            $this->fail('An answer without wamid was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertSame(200, $e->get_http_code());
            $this->assertStringContainsString('message id', $e->getMessage());
        }
    }

    /**
     * Successful JSON answers to a read receipt that do not confirm it.
     *
     * @return array
     */
    public static function mark_read_answer_provider(): array {
        return [
            'empty object' => ['{}'],
            'success false' => ['{"success":false}'],
            'success as text' => ['{"success":"true"}'],
            'success as number' => ['{"success":1}'],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider mark_read_answer_provider
     * @param string $answer Answer of Meta.
     */
    public function test_mark_read_typing_without_success_fails(string $answer): void {
        $this->resetAfterTest();
        $client = self::client([[$answer, 200]]);

        $this->expectException(cloud_api_exception::class);
        $client->mark_read_typing('wamid.in');
    }

    public function test_get_media_url(): void {
        $answer = '{"messaging_product":"whatsapp","url":"https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1",'
            . '"mime_type":"audio/ogg","sha256":"abc","file_size":"8192","id":"1037543291543636"}';
        $client = self::client([[$answer, 200]]);

        $media = $client->get_media_url('1037543291543636');

        $this->assertSame([
            'url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1',
            'mime_type' => 'audio/ogg',
            'file_size' => 8192,
        ], $media);
        $request = self::only_request($client);
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://graph.facebook.com/v25.0/1037543291543636', $request['url']);
        $this->assertContains('Authorization: Bearer ' . self::TOKEN, $request['headers']);
    }

    public function test_get_media_url_without_url_fails(): void {
        $client = self::client([['{"id":"1","mime_type":"audio/ogg"}', 200]]);

        try {
            $client->get_media_url('1');
            $this->fail('An answer without url was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertSame(cloud_api_client::HTTP_OK_UNUSABLE, $e->get_http_code());
        }
    }

    public function test_one_numeric_id_pattern_is_shared_with_the_settings(): void {
        $this->assertSame(1, preg_match(cloud_api_client::ID_PATTERN, '106540352242922'));
        $this->assertSame(0, preg_match(cloud_api_client::ID_PATTERN, "123\n"));
        $this->assertFalse((new \ReflectionClass(manager::class))->hasConstant('ID_PATTERN'));
    }

    public function test_non_numeric_media_id_is_rejected_without_request(): void {
        $client = self::client([['{}', 200]]);

        try {
            $client->get_media_url('../106540352242922/messages');
            $this->fail('A non-numeric media id was accepted');
        } catch (\coding_exception $e) {
            $this->assertCount(0, $client->curls);
        }
    }

    public function test_download_media_writes_the_file_with_the_bearer(): void {
        $this->resetAfterTest();
        $client = self::client([['OggS-audio-bytes', 200]]);
        $dest = make_request_directory() . '/audio.ogg';
        $url = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1&ext=2';

        $bytes = $client->download_media($url, $dest);

        $this->assertSame(16, $bytes);
        $this->assertSame('OggS-audio-bytes', file_get_contents($dest));
        $request = self::only_request($client);
        $this->assertSame('GET', $request['method']);
        $this->assertSame($url, $request['url']);
        $this->assertContains('Authorization: Bearer ' . self::TOKEN, $request['headers']);
        $this->assertFalse($request['options']['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(cloud_api_client::MAX_MEDIA_BYTES, $request['options']['CURLOPT_MAXFILESIZE']);
    }

    public function test_download_media_streams_to_the_file_and_stops_at_the_limit(): void {
        $this->resetAfterTest();
        // No Content-Length: CURLOPT_MAXFILESIZE alone would let the whole body through.
        $client = self::client([[str_repeat('x', 64), 200]], self::TOKEN, 8);
        $dest = make_request_directory() . '/video.mp4';

        try {
            $client->download_media('https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', $dest, 20);
            $this->fail('A file larger than the limit was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertStringContainsString('larger than 20 bytes', $e->getMessage());
        }
        $request = self::only_request($client);
        $this->assertSame(20, $request['options']['CURLOPT_MAXFILESIZE']);
        // The third chunk would pass the limit: the transfer stops there, nothing more is read.
        $this->assertSame(16, $client->curls[0]->accepted);
    }

    public function test_download_media_returns_the_bytes_on_disk(): void {
        $this->resetAfterTest();
        $content = str_repeat('0123456789', 7) . 'end';
        $client = self::client([[$content, 200]], self::TOKEN, 16);
        $dest = make_request_directory() . '/image.jpg';

        $bytes = $client->download_media('https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', $dest);

        $this->assertSame(strlen($content), $bytes);
        $this->assertSame(filesize($dest), $bytes);
        $this->assertSame($content, file_get_contents($dest));
    }

    public function test_file_larger_than_the_limit_leaves_no_file(): void {
        $this->resetAfterTest();
        $client = self::client([[str_repeat('x', 64), 200]], self::TOKEN, 8);
        $dest = make_request_directory() . '/video.mp4';

        try {
            $client->download_media('https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', $dest, 20);
            $this->fail('A file larger than the limit was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertFileDoesNotExist($dest);
        }
    }

    public function test_download_cut_by_a_transport_error_leaves_no_file(): void {
        $this->resetAfterTest();
        $client = self::client([['partial-bytes', 200, 'Connection reset by peer', 56]]);
        $dest = make_request_directory() . '/audio.ogg';

        try {
            $client->download_media('https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', $dest);
            $this->fail('A cut download was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertSame(0, $e->get_meta_code());
            $this->assertFileDoesNotExist($dest);
        }
    }

    public function test_write_failure_fails_and_leaves_no_file(): void {
        $this->resetAfterTest();
        $client = new class (self::TOKEN, self::PHONE_ID, 'v25.0') extends cloud_api_client {
            #[\Override]
            protected function new_curl(): \curl {
                $curl = cloud_api_client_test::streaming_curl(4);
                $curl->enqueue('OggS-audio-bytes', 200);
                return $curl;
            }

            #[\Override]
            protected function open_destination(string $destfile) {
                // The file exists, but nothing can be written to it, as on a full disk.
                touch($destfile);
                return fopen('php://memory', 'rb');
            }
        };
        $dest = make_request_directory() . '/audio.ogg';

        try {
            $client->download_media('https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', $dest);
            $this->fail('A download that could not be written was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertStringContainsString('could not be written', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dest);
    }

    public function test_destination_that_cannot_be_opened_fails_without_request(): void {
        $this->resetAfterTest();
        $client = self::client([['bytes', 200]]);
        $dest = make_request_directory() . '/missing-dir/audio.ogg';

        try {
            $client->download_media('https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', $dest);
            $this->fail('A destination that cannot be opened was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertCount(0, $client->curls);
        }
        $this->assertFileDoesNotExist($dest);
    }

    public function test_expired_media_url_fails_with_its_http_code(): void {
        $this->resetAfterTest();
        $client = self::client([['', 404]]);
        $dest = make_request_directory() . '/audio.ogg';

        try {
            $client->download_media('https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', $dest);
            $this->fail('A failed download was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertSame(404, $e->get_http_code());
        }
        $this->assertFileDoesNotExist($dest);
    }

    /**
     * Download addresses of Meta the token is sent to.
     *
     * @return array
     */
    public static function meta_media_url_provider(): array {
        return [
            'lookaside.fbsbx.com' => ['https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1'],
            'subdomain of fbcdn.net' => ['https://scontent.xx.fbcdn.net/v/t61/audio.ogg?oh=1'],
            'subdomain of whatsapp.net' => ['https://mmg.whatsapp.net/v/t62/image.jpg'],
            'upper case host' => ['https://MMG.WhatsApp.NET/v/t62/image.jpg'],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider meta_media_url_provider
     * @param string $url Download address.
     */
    public function test_media_is_downloaded_from_a_host_of_meta(string $url): void {
        $this->resetAfterTest();
        $client = self::client([['bytes', 200]]);
        $dest = make_request_directory() . '/x';

        $this->assertSame(5, $client->download_media($url, $dest));

        $request = self::only_request($client);
        $this->assertSame($url, $request['url']);
        $this->assertContains('Authorization: Bearer ' . self::TOKEN, $request['headers']);
    }

    /**
     * Download addresses the token is never sent to.
     *
     * @return array
     */
    public static function foreign_media_url_provider(): array {
        return [
            'plain http' => ['http://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1'],
            'other host' => ['https://attacker.example/steal'],
            'lookalike host' => ['https://lookaside.fbsbx.com.attacker.example/x'],
            'suffix without dot' => ['https://evilfbsbx.com/x'],
            'credentials' => ['https://user@lookaside.fbsbx.com/x'],
            'not a url' => ['lookaside.fbsbx.com/x'],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider foreign_media_url_provider
     * @param string $url Download address.
     */
    public function test_media_is_never_downloaded_from_another_host(string $url): void {
        $this->resetAfterTest();
        $client = self::client([['bytes', 200]]);

        try {
            $client->download_media($url, make_request_directory() . '/x');
            $this->fail('A foreign download address was accepted');
        } catch (\coding_exception $e) {
            $this->assertCount(0, $client->curls);
        }
    }

    public function test_health_check_reads_the_phone_number(): void {
        $answer = '{"verified_name":"Jedomi","display_phone_number":"+1 555-0100","quality_rating":"GREEN",'
            . '"id":"106540352242922"}';
        $client = self::client([[$answer, 200]]);

        $result = $client->health_check();

        $this->assertSame('106540352242922', $result['id']);
        $this->assertSame('Jedomi', $result['verified_name']);
        $request = self::only_request($client);
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://graph.facebook.com/v25.0/106540352242922', $request['url']);
    }

    public function test_configured_version_is_part_of_the_address(): void {
        $client = new class (self::TOKEN, self::PHONE_ID, 'v26.0') extends cloud_api_client {
            /** @var fake_curl|null Transport of the request. */
            public ?fake_curl $curl = null;

            #[\Override]
            protected function new_curl(): \curl {
                $this->curl = new fake_curl();
                $this->curl->enqueue('{"id":"1"}', 200);
                return $this->curl;
            }
        };

        $client->health_check();

        $this->assertSame('https://graph.facebook.com/v26.0/106540352242922', $client->curl->calls[0]['url']);
    }

    public function test_meta_error_is_thrown_with_its_code_and_never_shows_the_token(): void {
        $this->resetAfterTest();
        self::clear_log();
        $answer = '{"error":{"message":"Error validating access token ' . self::TOKEN . '","type":"OAuthException",'
            . '"code":190,"error_subcode":463,"fbtrace_id":"AbCd"}}';
        $client = self::client([[$answer, 401]]);

        try {
            $client->send_text(recipient_ref::phone('16505551234'), 'Hi');
            $this->fail('A Meta error was not thrown');
        } catch (cloud_api_exception $e) {
            $this->assertSame(401, $e->get_http_code());
            $this->assertSame(190, $e->get_meta_code());
            $this->assertStringContainsString('190', $e->getMessage());
            $this->assertStringContainsString('Error validating access token', $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, (string) $e->debuginfo);
            $this->assertStringNotContainsString(self::TOKEN, $e->getTraceAsString());
        }
        $log = self::log();
        $this->assertStringContainsString('190', $log);
        $this->assertStringNotContainsString(self::TOKEN, $log);
    }

    public function test_meta_error_never_logs_the_recipient(): void {
        $this->resetAfterTest();
        self::clear_log();
        $answer = '{"error":{"message":"Recipient 16505551234 not in allowed list","code":131030}}';
        $client = self::client([[$answer, 400]]);

        try {
            $client->send_text(recipient_ref::phone('16505551234'), 'Hi');
            $this->fail('A Meta error was not thrown');
        } catch (cloud_api_exception $e) {
            $this->assertSame(131030, $e->get_meta_code());
            $this->assertStringContainsString('[recipient]', $e->getMessage());
            $this->assertStringNotContainsString('16505551234', $e->getMessage());
        }
        $this->assertStringNotContainsString('16505551234', self::log());
    }

    public function test_curl_error_never_shows_the_token(): void {
        $this->resetAfterTest();
        self::clear_log();
        $client = self::client([['', 0, 'Could not resolve, header Bearer ' . self::TOKEN, 6]]);

        try {
            $client->send_text(recipient_ref::phone('1'), 'Hi');
            $this->fail('A transport error was not thrown');
        } catch (cloud_api_exception $e) {
            $this->assertSame(0, $e->get_http_code());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        $log = self::log();
        $this->assertStringContainsString('[token]', $log);
        $this->assertStringNotContainsString(self::TOKEN, $log);
    }

    public function test_invalid_json_answer_fails(): void {
        $this->resetAfterTest();
        $client = self::client([['<html>proxy error</html>', 200]]);

        $this->expectException(cloud_api_exception::class);
        $client->send_text(recipient_ref::phone('1'), 'Hi');
    }

    public function test_missing_token_fails_without_request(): void {
        $this->resetAfterTest();
        $client = self::client([[self::SENT, 200]], '');

        try {
            $client->send_text(recipient_ref::phone('1'), 'Hi');
            $this->fail('A request without token was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertCount(0, $client->curls);
        }
    }

    public function test_default_client_reads_the_saved_settings(): void {
        $this->resetAfterTest();
        manager::set_access_token(self::TOKEN);
        manager::set_phone_number_id('999');
        manager::set_graph_api_version('v26.2');
        $client = new class extends cloud_api_client {
            /** @var fake_curl|null Transport of the request. */
            public ?fake_curl $curl = null;

            #[\Override]
            protected function new_curl(): \curl {
                $this->curl = new fake_curl();
                $this->curl->enqueue('{"id":"999"}', 200);
                return $this->curl;
            }
        };

        $client->health_check();

        $this->assertSame('https://graph.facebook.com/v26.2/999', $client->curl->calls[0]['url']);
        $this->assertContains('Authorization: Bearer ' . self::TOKEN, $client->curl->header);
    }

    public function test_test_instance_replaces_the_default(): void {
        $client = new cloud_api_client('t', '1', 'v25.0');

        cloud_api_client::set_instance_for_testing($client);
        try {
            $this->assertSame($client, cloud_api_client::instance());
        } finally {
            cloud_api_client::set_instance_for_testing(null);
        }
        $this->assertNotSame($client, cloud_api_client::instance());
    }
}
