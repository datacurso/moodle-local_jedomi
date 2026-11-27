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

/**
 * Tests for the recording double of the WhatsApp Cloud API client used by the channel tests.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class fake_cloud_api_client_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        require_once(__DIR__ . '/../fixtures/fake_cloud_api_client.php');
    }

    public function test_records_calls_and_answers_with_a_wamid(): void {
        $fake = new fake_cloud_api_client();
        $to = recipient_ref::phone('16505551234');

        $first = $fake->send_text($to, 'Hello');
        $second = $fake->send_buttons($to, 'Confirm?', ['yes' => 'Yes']);
        $fake->mark_read_typing('wamid.in');

        $this->assertSame('wamid.fake.1', $first['messages'][0]['id']);
        $this->assertSame('wamid.fake.2', $second['messages'][0]['id']);
        $this->assertSame(['send_text', 'send_buttons', 'mark_read_typing'], array_column($fake->calls, 'method'));
        $this->assertSame([$to, 'Hello'], $fake->calls[0]['args']);
        $this->assertSame(['wamid.in'], $fake->calls[2]['args']);
        $this->assertSame([['method' => 'send_text', 'args' => [$to, 'Hello']]], $fake->calls_to('send_text'));
    }

    public function test_configured_response_and_exception(): void {
        $fake = new fake_cloud_api_client();
        $fake->responses['health_check'] = ['id' => '42'];
        $fake->responses['send_template'] = new cloud_api_exception(400, 131047, 're-engagement message');

        $this->assertSame(['id' => '42'], $fake->health_check());
        try {
            $fake->send_template(recipient_ref::bsuid('US.1'), 'grade_posted', 'es');
            $this->fail('The configured exception was not thrown');
        } catch (cloud_api_exception $e) {
            $this->assertSame(131047, $e->get_meta_code());
        }
        $this->assertCount(2, $fake->calls);
    }

    public function test_media_is_served_from_memory(): void {
        $this->resetAfterTest();
        $fake = new fake_cloud_api_client();
        $fake->media['555'] = ['content' => 'OggS', 'mime_type' => 'audio/ogg'];

        $info = $fake->get_media_url('555');
        $dest = make_request_directory() . '/a.ogg';
        $bytes = $fake->download_media($info['url'], $dest);

        $this->assertSame('audio/ogg', $info['mime_type']);
        $this->assertSame(4, $info['file_size']);
        $this->assertSame(4, $bytes);
        $this->assertSame('OggS', file_get_contents($dest));
    }

    public function test_unknown_media_fails_like_an_expired_address(): void {
        $fake = new fake_cloud_api_client();

        try {
            $fake->get_media_url('999');
            $this->fail('An unknown media id was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertSame(404, $e->get_http_code());
        }
    }

    /**
     * Calls the real client rejects with a coding_exception before any request.
     *
     * @return array
     */
    public static function invalid_call_provider(): array {
        $rows = [];
        for ($i = 1; $i <= 11; $i++) {
            $rows['r' . $i] = 'Row ' . $i;
        }
        $media = 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=';
        return [
            'non-numeric media id' => ['get_media_url', ['../123/messages']],
            'empty media id' => ['get_media_url', ['']],
            'plain http media url' => ['download_media', ['http://lookaside.fbsbx.com/x', '/tmp/x']],
            'foreign media host' => ['download_media', ['https://attacker.example/?mid=555', '/tmp/x']],
            'lookalike media host' => ['download_media', [str_replace('fbsbx.com', 'fbsbx.com.evil.example', $media) . '555',
                '/tmp/x']],
            'no buttons' => ['send_buttons', ['Pick', []]],
            'four buttons' => ['send_buttons', ['Pick', ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']]],
            'no rows' => ['send_list', ['Pick', 'Open', []]],
            'eleven rows' => ['send_list', ['Pick', 'Open', $rows]],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider invalid_call_provider
     * @param string $method Method.
     * @param array $args Arguments after the recipient (send methods) or all of them.
     */
    public function test_rejects_what_the_real_client_rejects(string $method, array $args): void {
        $fake = new fake_cloud_api_client();
        $fake->media['555'] = ['content' => 'OggS', 'mime_type' => 'audio/ogg'];
        if (str_starts_with($method, 'send_')) {
            array_unshift($args, recipient_ref::phone('1'));
        }

        try {
            $fake->$method(...$args);
            $this->fail('The fake accepted a call the real client rejects');
        } catch (\coding_exception $e) {
            $this->assertSame([], $fake->calls);
        }
    }

    public function test_media_larger_than_the_limit_fails_and_leaves_no_file(): void {
        $this->resetAfterTest();
        $fake = new fake_cloud_api_client();
        $fake->media['555'] = ['content' => 'OggS-audio-bytes', 'mime_type' => 'audio/ogg'];
        $dest = make_request_directory() . '/a.ogg';

        try {
            $fake->download_media($fake->get_media_url('555')['url'], $dest, 4);
            $this->fail('Media larger than the limit was accepted');
        } catch (cloud_api_exception $e) {
            $this->assertStringContainsString('larger than 4 bytes', $e->getMessage());
        }
        $this->assertFileDoesNotExist($dest);
    }

    public function test_is_usable_as_the_test_instance(): void {
        $fake = new fake_cloud_api_client();

        cloud_api_client::set_instance_for_testing($fake);
        try {
            $this->assertSame($fake, cloud_api_client::instance());
        } finally {
            cloud_api_client::set_instance_for_testing(null);
        }
    }
}
