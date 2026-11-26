<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_jedomi\telegram;

use local_jedomi\messaging\media_ref;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');

/**
 * Tests for the download of Telegram files to a temporary file.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\telegram_media_source
 */
final class telegram_media_source_test extends \advanced_testcase {
    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->telegram = new fake_telegram_api_client();
        $this->telegram->files = ['f-1' => 'file bytes'];
        api_client::set_instance_for_testing($this->telegram);
    }

    #[\Override]
    protected function tearDown(): void {
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * The callback reads the downloaded file, which is removed afterwards.
     */
    public function test_file_is_passed_to_the_callback_and_removed(): void {
        $seen = null;

        $result = (new telegram_media_source())->with_file(
            new media_ref(media_ref::DOCUMENT, 'f-1', 'a.pdf', ''),
            static function (string $path) use (&$seen): array {
                $seen = $path;
                return ['content' => file_get_contents($path)];
            }
        );

        $this->assertSame(['content' => 'file bytes'], $result);
        $this->assertSame(['f-1'], $this->telegram->downloads);
        $this->assertFileDoesNotExist($seen);
    }

    /**
     * The file is removed also when the callback fails.
     */
    public function test_file_is_removed_when_the_callback_fails(): void {
        $seen = null;
        try {
            (new telegram_media_source())->with_file(
                new media_ref(media_ref::IMAGE, 'f-1', 'photo.jpg', ''),
                static function (string $path) use (&$seen): array {
                    $seen = $path;
                    throw new \coding_exception('failed');
                }
            );
            $this->fail('The failure of the callback goes on');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('failed', $e->getMessage());
        }
        $this->assertFileDoesNotExist($seen);
    }

    /**
     * A file Telegram does not give, or one without id, fails as an empty answer; without id nothing is requested.
     */
    public function test_missing_file_fails_as_an_empty_answer(): void {
        $source = new telegram_media_source();
        $never = static fn(string $path): array => ['called' => true];

        foreach (['missing', ''] as $id) {
            try {
                $source->with_file(new media_ref(media_ref::AUDIO, $id, 'voice.ogg', ''), $never);
                $this->fail('A missing file fails');
            } catch (\moodle_exception $e) {
                $this->assertSame('emptyresponse', $e->errorcode);
                $this->assertSame('aiprovider_datacurso', $e->module);
            }
        }
        $this->assertSame(['missing'], $this->telegram->downloads);
    }
}
