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

namespace local_jedomi\local\service;

use local_jedomi\ai\fake_curl;
use local_jedomi\ai\testable_provider_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for reading the text of a file attached in the web chat.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\file_extraction_service
 */
final class file_extraction_service_test extends \advanced_testcase {
    /**
     * Write a temporary file as PHP receives an upload.
     *
     * @param string $content File content.
     * @return string Path.
     */
    private function upload(string $content): string {
        $path = make_request_directory() . '/upload.tmp';
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Enable the provider with a license, as a configured site has it.
     */
    private function enable_provider(): void {
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
    }

    /**
     * A7: the file goes through the provider to plugins-ai and its text comes back; the temporary file is removed.
     */
    public function test_upload_is_sent_through_the_provider_and_text_returned(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['text' => "name,score\nAna,5", 'format' => 'csv', 'rows' => 1, 'columns' => 2,
            'truncated' => false]));

        $result = file_extraction_service::extract_upload(
            (int) $user->id,
            $this->upload("name,score\nAna,5\n"),
            'grades.csv',
            new testable_provider_api(30, $curl)
        );

        $this->assertSame([
            'text' => "name,score\nAna,5",
            'format' => 'csv',
            'pages' => 0,
            'rows' => 1,
            'columns' => 2,
            'chars' => 0,
            'truncated' => false,
        ], $result);
        $this->assertCount(1, $curl->calls);
        $this->assertStringEndsWith('/jedomi/v1/files/extract', $curl->calls[0]['url']);
        $file = $curl->calls[0]['params']['file'];
        $this->assertInstanceOf(\CURLFile::class, $file);
        $this->assertSame('grades.csv', $file->getPostFilename());
        $this->assertFalse($DB->record_exists_select('files', "component = 'user' AND filearea = 'draft' AND filename = ?",
            ['grades.csv']));
    }

    /**
     * A7: a type other than PDF, CSV, TXT or MD is rejected without calling the service.
     */
    public function test_unsupported_type_is_rejected_without_a_call(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        $curl = new fake_curl();

        try {
            file_extraction_service::extract_upload((int) $user->id, $this->upload('x'), 'photo.png',
                new testable_provider_api(30, $curl));
            $this->fail('An unsupported file must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame(['code' => 'file_unsupported', 'message' => get_string('file_error_unsupported', 'local_jedomi')],
                file_extraction_service::error_for($e));
        }
        $this->assertSame([], $curl->calls);
    }

    /**
     * A7: with the provider disabled the provider's message is returned and nothing is sent.
     */
    public function test_provider_disabled_returns_its_message(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 0);
        $curl = new fake_curl();

        try {
            file_extraction_service::extract_upload((int) $user->id, $this->upload('hello'), 'notes.txt',
                new testable_provider_api(30, $curl));
            $this->fail('A disabled provider must stop the upload');
        } catch (\moodle_exception $e) {
            $this->assertSame([
                'code' => 'provider_disabled',
                'message' => get_string('providerdisabled', 'aiprovider_datacurso'),
            ], file_extraction_service::error_for($e));
        }
        $this->assertSame([], $curl->calls);
    }

    /**
     * Status codes from plugins-ai and the message each one shows.
     *
     * @return array
     */
    public static function service_error_provider(): array {
        return [
            'too large' => [413, 'file_too_large', 'file_error_too_large'],
            'unsupported' => [415, 'file_unsupported', 'file_error_unsupported'],
            'no text' => [422, 'file_no_text', 'file_error_no_text'],
        ];
    }

    /**
     * A7: errors of plugins-ai about the file get their own message.
     *
     * @dataProvider service_error_provider
     * @param int $status HTTP status from plugins-ai.
     * @param string $code Expected code.
     * @param string $stringkey Expected message string.
     */
    public function test_service_file_errors_have_their_message(int $status, string $code, string $stringkey): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        $curl = new fake_curl();
        $body = json_encode(['detail' => ['code' => 'x', 'message' => 'internal detail']]);
        $curl->enqueue($body, $status);

        try {
            file_extraction_service::extract_upload((int) $user->id, $this->upload('hello'), 'notes.pdf',
                new testable_provider_api(30, $curl));
            $this->fail('A service error must be raised');
        } catch (\moodle_exception $e) {
            $this->assertSame(['code' => $code, 'message' => get_string($stringkey, 'local_jedomi')],
                file_extraction_service::error_for($e));
        }
        // The provider logs the status for developers, never the body.
        $this->assertDebuggingCalled("HTTP error {$status} from Datacurso API (" . strlen($body) . ' bytes)');
    }

    /**
     * A7: other failures use the shared AI error messages.
     */
    public function test_other_errors_use_the_shared_messages(): void {
        $this->resetAfterTest();
        $error = new \moodle_exception('notenoughtokens', 'aiprovider_datacurso');

        $this->assertSame(['code' => 'no_credit', 'message' => get_string('ai_error_nocredit', 'local_jedomi')],
            file_extraction_service::error_for($error));
    }
}
