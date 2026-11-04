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
            'seconds' => 0,
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
            file_extraction_service::extract_upload((int) $user->id, $this->upload('hello'), 'notes.txt',
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

    /**
     * M8: with file processing off a document is rejected without calling the service.
     */
    public function test_documents_are_rejected_when_files_are_disabled(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        set_config('files_enabled', 0, 'local_jedomi');
        $curl = new fake_curl();

        try {
            file_extraction_service::extract_upload((int) $user->id, $this->upload('hello'), 'notes.txt',
                new testable_provider_api(30, $curl));
            $this->fail('Disabled file processing must stop the upload');
        } catch (\moodle_exception $e) {
            $this->assertSame(['code' => 'files_disabled', 'message' => get_string('files_error_disabled', 'local_jedomi')],
                file_extraction_service::error_for($e));
        }
        $this->assertSame([], $curl->calls);
    }

    /**
     * M8: a PDF without text is read again by the vision model through files/read.
     */
    public function test_pdf_without_text_falls_back_to_the_vision_model(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        $curl = new fake_curl();
        $body = json_encode(['detail' => ['code' => 'jedomi_file_no_text']]);
        $curl->enqueue($body, 422);
        $curl->enqueue(json_encode(['text' => 'Scanned page text', 'model' => 'm', 'pages_total' => 3,
            'pages_vision' => [1, 2, 3], 'pages_skipped' => []]));

        $result = file_extraction_service::extract_upload((int) $user->id, $this->upload('%PDF'), 'scan.pdf',
            new testable_provider_api(30, $curl));

        $this->assertSame('Scanned page text', $result['text']);
        $this->assertSame('pdf', $result['format']);
        $this->assertSame(3, $result['pages']);
        $this->assertCount(2, $curl->calls);
        $this->assertStringEndsWith('/jedomi/v1/files/extract', $curl->calls[0]['url']);
        $this->assertStringEndsWith('/jedomi/v1/files/read', $curl->calls[1]['url']);
        $this->assertDebuggingCalled("HTTP error 422 from Datacurso API (" . strlen($body) . ' bytes)');
    }

    /**
     * M9: an attached audio file is transcribed and kept as text, also with file processing off.
     */
    public function test_audio_file_is_transcribed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        set_config('files_enabled', 0, 'local_jedomi');
        set_config('audio_enabled', 1, 'local_jedomi');
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['text' => 'Lecture notes', 'model' => 'm', 'duration_seconds' => 61.6]));

        $result = file_extraction_service::extract_upload((int) $user->id, $this->upload('audio'), 'class.mp3',
            new testable_provider_api(30, $curl));

        $this->assertSame('Lecture notes', $result['text']);
        $this->assertSame('audio', $result['format']);
        $this->assertSame(62, $result['seconds']);
        $this->assertStringEndsWith('/jedomi/v1/audio/transcriptions', $curl->calls[0]['url']);
    }

    /**
     * M9: with audio processing off an audio file is rejected without calling the service.
     */
    public function test_audio_file_is_rejected_when_audio_is_disabled(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        set_config('audio_enabled', 0, 'local_jedomi');
        $curl = new fake_curl();

        try {
            file_extraction_service::extract_upload((int) $user->id, $this->upload('audio'), 'class.wav',
                new testable_provider_api(30, $curl));
            $this->fail('Disabled audio processing must stop the upload');
        } catch (\moodle_exception $e) {
            $this->assertSame('audio_disabled', file_extraction_service::error_for($e)['code']);
        }
        $this->assertSame([], $curl->calls);
    }

    /**
     * Word and PowerPoint files and their media types.
     *
     * @return array
     */
    public static function office_file_provider(): array {
        return [
            'doc' => ['old.doc', 'application/msword', 'word'],
            'docx' => ['essay.DOCX', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'word'],
            'ppt' => ['old.ppt', 'application/vnd.ms-powerpoint', 'presentation'],
            'pptx' => ['deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'presentation'],
        ];
    }

    /**
     * A Word or PowerPoint file goes to files/extract with its media type and its text comes back.
     *
     * @dataProvider office_file_provider
     * @param string $filename File name.
     * @param string $mimetype Media type sent to the service.
     * @param string $format Format the service answers.
     */
    public function test_office_file_is_sent_to_files_extract(string $filename, string $mimetype, string $format): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        $curl = new fake_curl();
        $curl->enqueue(json_encode(['text' => 'Slide text', 'format' => $format, 'chars' => 10, 'truncated' => false]));

        $result = file_extraction_service::extract_upload((int) $user->id, $this->upload('office bytes'), $filename,
            new testable_provider_api(30, $curl));

        $this->assertSame('Slide text', $result['text']);
        $this->assertSame($format, $result['format']);
        $this->assertSame(10, $result['chars']);
        $this->assertCount(1, $curl->calls);
        $this->assertStringEndsWith('/jedomi/v1/files/extract', $curl->calls[0]['url']);
        $file = $curl->calls[0]['params']['file'];
        $this->assertSame($filename, $file->getPostFilename());
        $this->assertSame($mimetype, $file->getMimeType());
    }

    /**
     * A Word file without text gets the "no text" message; only PDFs are read again by the vision model.
     */
    public function test_office_file_without_text_is_not_sent_to_the_vision_model(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        $curl = new fake_curl();
        $body = json_encode(['detail' => ['code' => 'jedomi_file_no_text']]);
        $curl->enqueue($body, 422);

        try {
            file_extraction_service::extract_upload((int) $user->id, $this->upload('x'), 'empty.docx',
                new testable_provider_api(30, $curl));
            $this->fail('A file without text must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame(['code' => 'file_no_text', 'message' => get_string('file_error_no_text', 'local_jedomi')],
                file_extraction_service::error_for($e));
        }
        $this->assertCount(1, $curl->calls);
        $this->assertDebuggingCalled("HTTP error 422 from Datacurso API (" . strlen($body) . ' bytes)');
    }

    /**
     * A Word or PowerPoint file is rejected when file processing is off.
     *
     * @dataProvider office_file_provider
     * @param string $filename File name.
     */
    public function test_office_file_is_rejected_when_files_are_disabled(string $filename): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->enable_provider();
        set_config('files_enabled', 0, 'local_jedomi');
        $curl = new fake_curl();

        try {
            file_extraction_service::extract_upload((int) $user->id, $this->upload('x'), $filename,
                new testable_provider_api(30, $curl));
            $this->fail('Disabled file processing must stop the upload');
        } catch (\moodle_exception $e) {
            $this->assertSame('files_disabled', file_extraction_service::error_for($e)['code']);
        }
        $this->assertSame([], $curl->calls);
    }

    /**
     * Only Word and PowerPoint files are read as office documents.
     */
    public function test_is_office_document(): void {
        foreach (['a.doc', 'a.DOCX', 'a.ppt', 'a.pptx'] as $filename) {
            $this->assertTrue(file_extraction_service::is_office_document($filename), $filename);
        }
        foreach (['a.pdf', 'a.csv', 'a.xlsx', 'a.png', 'docx', ''] as $filename) {
            $this->assertFalse(file_extraction_service::is_office_document($filename), $filename);
        }
    }

    /**
     * The message for the chat model has the question, the file header and the text; without a question, a summary is asked.
     */
    public function test_compose_message(): void {
        $this->assertSame("What is it about?\n\n" . get_string('attached_file_info', 'local_jedomi', 'notes.docx')
            . "\nThe text", file_extraction_service::compose_message(' What is it about? ', 'notes.docx', " The text \n"));
        $this->assertStringStartsWith(get_string('analyze_file', 'local_jedomi') . "\n\n",
            file_extraction_service::compose_message('', 'deck.pptx', 'Slides'));
    }
}
