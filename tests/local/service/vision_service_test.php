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
use local_jedomi\ai\model_settings;
use local_jedomi\ai\testable_provider_api;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for reading images and PDF files with the vision model (M8, M11).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\vision_service
 * @covers     \local_jedomi\local\service\provider_upload_service
 */
final class vision_service_test extends \advanced_testcase {
    /**
     * Create a user, enable the provider and return the user id.
     *
     * @return int
     */
    private function setup_user(): int {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        return (int) $user->id;
    }

    /**
     * Write a temporary file as PHP receives an upload.
     *
     * @return string Path.
     */
    private function upload(): string {
        $path = make_request_directory() . '/upload.tmp';
        file_put_contents($path, 'fake image bytes');
        return $path;
    }

    /**
     * M11: an image goes to files/read with the vision model and the question; only text comes back.
     */
    public function test_image_is_read_by_the_vision_model(): void {
        global $DB;
        $userid = $this->setup_user();
        set_config('vision_model', 'jedomi/Qwen/Qwen3-VL-235B-A22B-Instruct', 'local_jedomi');
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['text' => 'A bar chart of grades', 'model' => 'jedomi/Qwen/Qwen3-VL-235B-A22B-Instruct',
            'pages_total' => null, 'pages_vision' => [], 'pages_skipped' => []]));

        $result = vision_service::read_upload($userid, $this->upload(), 'chart.png', 'What does it show?',
            new testable_provider_api(30, $curl));

        $this->assertSame([
            'text' => 'A bar chart of grades',
            'model' => 'jedomi/Qwen/Qwen3-VL-235B-A22B-Instruct',
            'pages_total' => null,
            'pages_vision' => [],
            'pages_skipped' => [],
        ], $result);
        $this->assertCount(1, $curl->calls);
        $this->assertStringEndsWith('/jedomi/v1/files/read', $curl->calls[0]['url']);
        $params = $curl->calls[0]['params'];
        $this->assertSame('jedomi/Qwen/Qwen3-VL-235B-A22B-Instruct', $params['model']);
        $this->assertSame('What does it show?', $params['question']);
        $this->assertArrayNotHasKey('pages', $params);
        $this->assertSame('chart.png', $params['file']->getPostFilename());
        $this->assertNotEmpty($params['userid']);
        $this->assertNotEmpty($params['site_id']);
        $this->assertFalse($DB->record_exists('files', ['component' => 'user', 'filearea' => 'draft', 'filename' => 'chart.png']));
    }

    /**
     * M8: a stored PDF is sent with the requested pages.
     */
    public function test_stored_pdf_is_sent_with_pages(): void {
        $userid = $this->setup_user();
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($userid)->id, 'component' => 'user', 'filearea' => 'private',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'syllabus.pdf',
        ], '%PDF-1.4 fake');
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['text' => 'Unit 1', 'model' => 'm', 'pages_total' => 12, 'pages_vision' => [3],
            'pages_skipped' => []]));

        $result = vision_service::read_stored_file($file, '1,3-5', '', new testable_provider_api(30, $curl));

        $this->assertSame(12, $result['pages_total']);
        $this->assertSame([3], $result['pages_vision']);
        $params = $curl->calls[0]['params'];
        $this->assertSame('1,3-5', $params['pages']);
        $this->assertSame(model_settings::DEFAULT_VISION_MODEL, $params['model']);
        $this->assertArrayNotHasKey('question', $params);
    }

    /**
     * M8: with file processing off nothing is sent and the "not enabled" message is returned.
     */
    public function test_disabled_files_are_rejected_without_a_call(): void {
        $userid = $this->setup_user();
        set_config('files_enabled', 0, 'local_jedomi');
        $curl = new fake_curl();

        try {
            vision_service::read_upload($userid, $this->upload(), 'chart.png', '', new testable_provider_api(30, $curl));
            $this->fail('Disabled file processing must stop the read');
        } catch (\moodle_exception $e) {
            $this->assertSame(['code' => 'files_disabled', 'message' => get_string('files_error_disabled', 'local_jedomi')],
                vision_service::error_for($e));
        }
        $this->assertSame([], $curl->calls);
    }

    /**
     * M8: a type the vision service does not read is rejected without a call.
     */
    public function test_unsupported_type_is_rejected_without_a_call(): void {
        $userid = $this->setup_user();
        $curl = new fake_curl();

        try {
            vision_service::read_upload($userid, $this->upload(), 'photo.gif', '', new testable_provider_api(30, $curl));
            $this->fail('An unsupported file must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('file_unsupported', vision_service::error_for($e)['code']);
        }
        $this->assertSame([], $curl->calls);
    }

    /**
     * Status codes of files/read and the message each one shows.
     *
     * @return array
     */
    public static function service_error_provider(): array {
        return [
            'too large' => [413, 'file_too_large', 'file_error_too_large'],
            'unsupported' => [415, 'file_unsupported', 'image_error_unsupported'],
            'no text' => [422, 'file_no_text', 'image_error_no_text'],
            'invalid request' => [400, 'generic', 'ai_error_generic'],
            'not configured' => [503, 'unavailable', 'ai_error_unavailable'],
        ];
    }

    /**
     * M8: errors of files/read get their own message.
     *
     * @dataProvider service_error_provider
     * @param int $status HTTP status from plugins-ai.
     * @param string $code Expected code.
     * @param string $stringkey Expected message string.
     */
    public function test_service_errors_have_their_message(int $status, string $code, string $stringkey): void {
        $userid = $this->setup_user();
        $curl = new fake_curl();
        $body = json_encode(['detail' => ['code' => 'x', 'message' => 'internal detail']]);
        $curl->enqueue($body, $status);

        try {
            vision_service::read_upload($userid, $this->upload(), 'chart.jpg', '', new testable_provider_api(30, $curl));
            $this->fail('A service error must be raised');
        } catch (\moodle_exception $e) {
            $error = vision_service::error_for($e);
            $this->assertSame($code, $error['code']);
            $this->assertSame(get_string($stringkey, 'local_jedomi'), $error['message']);
        }
        $this->assertDebuggingCalled("HTTP error {$status} from Datacurso API (" . strlen($body) . ' bytes)');
    }

    /**
     * M11: the message for the chat model is the question followed by the text read from the image.
     */
    public function test_compose_message_puts_the_question_before_the_image_text(): void {
        $this->assertSame("What is it?\n\n" . get_string('attached_image_content', 'local_jedomi', 'a.png') . "\nA cat",
            vision_service::compose_message(' What is it? ', 'a.png', "A cat\n"));
        $this->assertStringStartsWith(get_string('analyze_image', 'local_jedomi'),
            vision_service::compose_message('', 'a.png', 'A cat'));
    }
}
