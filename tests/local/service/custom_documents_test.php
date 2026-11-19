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

use local_jedomi\agent\tool\document_read_tool;
use local_jedomi\ai\ai_service;
use local_jedomi\ai\custom_api;
use local_jedomi\ai\docservice_client;
use local_jedomi\ai\fake_curl;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_curl.php');

/**
 * Tests for documents with the custom AI service: PDF files through the document service and the custom vision model,
 * text files read on the site, and Word and PowerPoint files refused.
 *
 * Needs only core: nothing goes through the Datacurso provider.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\file_extraction_service
 * @covers     \local_jedomi\local\service\vision_service
 * @covers     \local_jedomi\agent\tool\document_read_tool
 */
final class custom_documents_test extends \advanced_testcase {
    /** @var fake_curl Transport double of the custom AI service. */
    private fake_curl $curl;

    /** @var fake_curl Transport double of the document service. */
    private fake_curl $doccurl;

    /** @var \stdClass User of the tests. */
    private \stdClass $user;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $this->curl = new fake_curl();
        $this->doccurl = new fake_curl();
        custom_api::set_curl_factory_for_testing(fn(bool $bypass): \curl => $this->curl);
        docservice_client::set_curl_factory_for_testing(fn(bool $bypass): \curl => $this->doccurl);
        $settings = [
            'ai_service' => ai_service::CUSTOM,
            'custom_gateway_url' => 'https://api.example.com',
            'custom_api_key' => 'sk-test',
            'custom_model' => 'chat-model',
            'custom_files_enabled' => 1,
            'custom_vision_model' => 'vision-model',
            'custom_documents_enabled' => 1,
            'custom_docservice_url' => 'https://docs.example.com',
        ];
        foreach ($settings as $name => $value) {
            set_config($name, $value, 'local_jedomi');
        }
        $this->user = $this->getDataGenerator()->create_user(['lang' => 'en']);
        $this->setUser($this->user);
    }

    #[\Override]
    protected function tearDown(): void {
        custom_api::set_curl_factory_for_testing(null);
        docservice_client::set_curl_factory_for_testing(null);
        vision_service::set_clock_for_testing(null);
        parent::tearDown();
    }

    /**
     * Write a temporary file.
     *
     * @param string $name File name.
     * @param string $content Content.
     * @return string Path.
     */
    private function file(string $name, string $content): string {
        $path = make_request_directory() . '/' . $name;
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Queue the pages of a PDF in the document service.
     *
     * @param int $count Number of pages.
     * @return void
     */
    private function queue_pages(int $count): void {
        $pages = [];
        for ($i = 1; $i <= $count; $i++) {
            $pages[] = ['page' => $i, 'format' => 'JPEG', 'image' => base64_encode('PAGE' . $i)];
        }
        $this->doccurl->enqueue(json_encode(['total_pages' => $count, 'dpi' => 150, 'pages' => $pages]));
    }

    /**
     * Queue an answer of the custom vision model.
     *
     * @param string $text Text of the answer.
     * @return void
     */
    private function queue_vision(string $text): void {
        $this->curl->enqueue(json_encode(['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]]));
    }

    /**
     * Image sent to the custom vision model in a call.
     *
     * @param int $index Call.
     * @return string Data URL.
     */
    private function sent_image(int $index): string {
        $body = json_decode($this->curl->calls[$index]['params'], true);
        return $body['messages'][0]['content'][1]['image_url']['url'];
    }

    /**
     * A PDF attached in the chat goes to the document service, and each page image is read by the custom vision model.
     *
     * @return void
     */
    public function test_attached_pdf_is_read_page_by_page(): void {
        $this->queue_pages(2);
        $this->queue_vision('First page text');
        $this->queue_vision('Second page text');

        $result = file_extraction_service::extract_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF-1.4'), 'doc.pdf');

        $this->assertSame('pdf', $result['format']);
        $this->assertSame(2, $result['pages']);
        $this->assertStringContainsString('First page text', $result['text']);
        $this->assertStringContainsString('Second page text', $result['text']);
        $this->assertLessThan(strpos($result['text'], 'Second'), strpos($result['text'], 'First'));
        $this->assertFalse($result['truncated']);
        $this->assertSame('https://docs.example.com/api/v1/pdf/to-images?dpi=150&fmt=JPEG', $this->doccurl->calls[0]['url']);
        $this->assertCount(2, $this->curl->calls);
        $this->assertSame('data:image/jpeg;base64,' . base64_encode('PAGE1'), $this->sent_image(0));
        $this->assertSame('data:image/jpeg;base64,' . base64_encode('PAGE2'), $this->sent_image(1));
        $this->assertSame('vision-model', json_decode($this->curl->calls[0]['params'], true)['model']);
    }

    /**
     * The document_read tool is offered and reads the published PDF of an activity the same way, only the pages asked.
     *
     * @return void
     */
    public function test_document_read_uses_the_document_service(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($page->cmid)->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'syllabus.pdf',
        ], '%PDF-1.4 fake');
        $this->queue_pages(3);
        $this->queue_vision('Page two');
        $this->queue_vision('Page three');

        $this->assertTrue(document_read_tool::is_offered('admin'));
        $result = json_decode(document_read_tool::read((int) $page->cmid, 'admin', '2-3'), true);

        $this->assertSame('syllabus.pdf', $result['filename']);
        $this->assertSame(3, $result['pages_total']);
        $this->assertSame([2, 3], $result['pages_vision']);
        // Only the pages asked count: none of them was left unread.
        $this->assertSame([], $result['pages_skipped']);
        $this->assertStringContainsString('Page two', $result['text']);
        $this->assertSame('data:image/jpeg;base64,' . base64_encode('PAGE2'), $this->sent_image(0));
        $this->assertSame('syllabus.pdf', $this->doccurl->calls[0]['params']['file']->getPostFilename());
    }

    /**
     * Pages over the cap of the document service are not read, and the result says it was cut.
     *
     * @return void
     */
    public function test_long_pdf_is_cut(): void {
        $this->queue_pages(docservice_client::MAX_PAGES + 2);
        for ($i = 0; $i < docservice_client::MAX_PAGES; $i++) {
            $this->queue_vision('p' . $i);
        }

        $result = file_extraction_service::extract_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF'), 'doc.pdf');

        $this->assertTrue($result['truncated']);
        $this->assertSame(docservice_client::MAX_PAGES + 2, $result['pages']);
        $this->assertCount(docservice_client::MAX_PAGES, $this->curl->calls);
    }

    /**
     * Text files are read on the site, without any AI call; a CSV file reports its rows and columns.
     *
     * @return void
     */
    public function test_text_files_are_read_on_the_site(): void {
        $userid = (int) $this->user->id;

        $txt = file_extraction_service::extract_upload($userid, $this->file('notes.txt', "\xEF\xBB\xBFHello\nWorld"), 'notes.txt');
        $this->assertSame("Hello\nWorld", $txt['text']);
        $this->assertSame('text', $txt['format']);
        $this->assertSame(11, $txt['chars']);

        $md = file_extraction_service::extract_upload($userid, $this->file('readme.md', '# Title'), 'readme.md');
        $this->assertSame('# Title', $md['text']);

        $path = $this->file('grades.csv', "name,grade\nAna,9\nLuis,8\n");
        $csv = file_extraction_service::extract_upload($userid, $path, 'grades.csv');
        $this->assertSame('csv', $csv['format']);
        $this->assertSame(3, $csv['rows']);
        $this->assertSame(2, $csv['columns']);

        // Text in another encoding (Windows-1252) is converted to UTF-8.
        $latin = file_extraction_service::extract_upload($userid, $this->file('latin.txt', "Canci\xF3n"), 'latin.txt');
        $this->assertSame('Canción', $latin['text']);

        $this->assertSame([], $this->curl->calls);
        $this->assertSame([], $this->doccurl->calls);
    }

    /**
     * A long text file is cut to the text cap and says so; a text file over the size cap is refused.
     *
     * @return void
     */
    public function test_text_files_have_a_size_cap(): void {
        $userid = (int) $this->user->id;

        $long = file_extraction_service::extract_upload(
            $userid,
            $this->file('long.txt', str_repeat('a', file_extraction_service::MAX_TEXT_CHARS + 10)),
            'long.txt'
        );
        $this->assertTrue($long['truncated']);
        $this->assertSame(file_extraction_service::MAX_TEXT_CHARS, \core_text::strlen($long['text']));

        try {
            file_extraction_service::extract_upload(
                $userid,
                $this->file('big.txt', str_repeat('a', file_extraction_service::MAX_TEXT_BYTES + 1)),
                'big.txt'
            );
            $this->fail('A text file over the size cap must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('file_error_too_large', $e->errorcode);
        }
    }

    /**
     * Word and PowerPoint files stay refused with the custom service, with a message that names them.
     *
     * @return void
     */
    public function test_office_documents_are_refused_with_a_clear_message(): void {
        foreach (['notes.docx', 'old.doc', 'slides.pptx', 'old.ppt'] as $name) {
            try {
                file_extraction_service::extract_upload((int) $this->user->id, $this->file($name, 'PK'), $name);
                $this->fail("{$name} must be refused");
            } catch (\moodle_exception $e) {
                $this->assertSame('documents_error_custom_office', $e->errorcode);
                $this->assertSame(
                    get_string('documents_error_custom_office', 'local_jedomi'),
                    file_extraction_service::error_for($e)['message']
                );
            }
        }
        $this->assertSame([], $this->curl->calls);
        $this->assertSame([], $this->doccurl->calls);
    }

    /**
     * A failure of the document service gives the user a generic message, never its answer or its address.
     *
     * @return void
     */
    public function test_document_service_failures_have_a_generic_message(): void {
        $this->doccurl->enqueue('{"detail":"internal stack trace"}', 500);

        try {
            file_extraction_service::extract_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF'), 'doc.pdf');
            $this->fail('The failure must be raised');
        } catch (\moodle_exception $e) {
            $error = file_extraction_service::error_for($e);
            $this->assertSame('documents_unavailable', $error['code']);
            $this->assertSame(get_string('documents_error_service', 'local_jedomi'), $error['message']);
            $this->assertStringNotContainsString('stack trace', $error['message']);
            $this->assertStringNotContainsString('docs.example.com', $error['message']);
            $this->assertSame($error, vision_service::error_for($e));
        }
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * Without the documents switch, or without the address of the document service, PDF and text files are refused as
     * before; images are still read.
     *
     * @return void
     */
    public function test_documents_off_keep_the_refusal(): void {
        foreach (['custom_documents_enabled' => 0, 'custom_docservice_url' => ''] as $name => $value) {
            $saved = get_config('local_jedomi', $name);
            set_config($name, $value, 'local_jedomi');
            foreach (['doc.pdf', 'notes.txt'] as $filename) {
                try {
                    file_extraction_service::extract_upload((int) $this->user->id, $this->file($filename, 'x'), $filename);
                    $this->fail("{$filename} must be refused without {$name}");
                } catch (\moodle_exception $e) {
                    $this->assertSame('documents_error_custom', $e->errorcode);
                }
            }
            try {
                vision_service::read_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF'), 'doc.pdf');
                $this->fail('A PDF must be refused');
            } catch (\moodle_exception $e) {
                $this->assertSame('documents_error_custom', $e->errorcode);
            }
            $this->assertFalse(document_read_tool::is_offered('admin'));
            set_config($name, $saved, 'local_jedomi');
        }
        $this->assertSame([], $this->doccurl->calls);
    }

    /**
     * Create a page activity with a PDF file and return its course module id.
     *
     * @return int
     */
    private function module_with_pdf(): int {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($page->cmid)->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'syllabus.pdf',
        ], '%PDF-1.4 fake');
        return (int) $page->cmid;
    }

    /**
     * U1: a page the vision model fails on is skipped; the pages already read and the next ones are kept.
     *
     * @return void
     */
    public function test_failed_page_keeps_the_other_pages(): void {
        $this->queue_pages(3);
        $this->queue_vision('First page text');
        $this->curl->enqueue('{"error":"bad image"}', 500);
        $this->queue_vision('Third page text');

        $result = vision_service::read_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF'), 'doc.pdf');

        $this->assertStringContainsString('First page text', $result['text']);
        $this->assertStringContainsString('Third page text', $result['text']);
        $this->assertSame([1, 3], $result['pages_vision']);
        $this->assertSame([2], $result['pages_skipped']);
        $this->assertTrue($result['truncated']);
    }

    /**
     * U1: a connection failure or a timeout would repeat on every page: the read stops there and keeps what was read.
     *
     * @return void
     */
    public function test_connection_failure_stops_and_keeps_the_pages_read(): void {
        $this->queue_pages(3);
        $this->queue_vision('First page text');
        $this->curl->enqueue('', 0, 'Operation timed out', 28);

        $result = file_extraction_service::extract_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF'), 'doc.pdf');

        $this->assertStringContainsString('First page text', $result['text']);
        $this->assertTrue($result['truncated']);
        $this->assertCount(2, $this->curl->calls, 'No page is tried after a timeout');
    }

    /**
     * U1: when no page could be read, the failure is raised with its message.
     *
     * @return void
     */
    public function test_failure_on_every_page_is_raised(): void {
        $this->queue_pages(2);
        $this->curl->enqueue('{"error":"x"}', 500);
        $this->curl->enqueue('{"error":"x"}', 500);

        try {
            vision_service::read_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF'), 'doc.pdf');
            $this->fail('The failure must be raised');
        } catch (\moodle_exception $e) {
            $this->assertSame('custom_error_http', $e->errorcode);
        }
    }

    /**
     * U2: the whole read has one deadline, the AI request timeout: the document service and each page get the time
     * that is left, and the pages after the deadline are skipped.
     *
     * @return void
     */
    public function test_whole_read_has_one_deadline(): void {
        set_config('ai_timeout', '100', 'local_jedomi');
        $now = 0;
        vision_service::set_clock_for_testing(function () use (&$now): int {
            $time = $now;
            $now += 40;
            return $time;
        });
        $this->queue_pages(3);
        $this->queue_vision('First page text');

        $result = vision_service::read_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF'), 'doc.pdf');

        $this->assertSame(60, $this->doccurl->calls[0]['options']['CURLOPT_TIMEOUT']);
        $this->assertCount(1, $this->curl->calls);
        $this->assertSame(20, $this->curl->calls[0]['options']['CURLOPT_TIMEOUT']);
        $this->assertSame([1], $result['pages_vision']);
        $this->assertSame([2, 3], $result['pages_skipped']);
        $this->assertTrue($result['truncated']);
    }

    /**
     * When the deadline runs out before any page is read, the user is told that the document took too long, not that
     * the document service failed.
     *
     * @return void
     */
    public function test_deadline_before_any_page_has_its_own_message(): void {
        set_config('ai_timeout', '100', 'local_jedomi');
        $now = 0;
        vision_service::set_clock_for_testing(function () use (&$now): int {
            $time = $now;
            $now += 60;
            return $time;
        });
        $this->queue_pages(2);

        try {
            vision_service::read_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF'), 'doc.pdf');
            $this->fail('The deadline must be raised');
        } catch (\moodle_exception $e) {
            $this->assertSame('documents_error_deadline', $e->errorcode);
            $error = vision_service::error_for($e);
            $this->assertSame('documents_unavailable', $error['code']);
            $this->assertSame(get_string('documents_error_deadline', 'local_jedomi'), $error['message']);
            $this->assertSame($error, file_extraction_service::error_for($e));
        }
        $this->assertSame([], $this->curl->calls, 'No page is sent after the deadline');
    }

    /**
     * U5: the pages asked are chosen before the page cap, so pages after the first 20 can be read.
     *
     * @return void
     */
    public function test_pages_after_the_cap_can_be_read(): void {
        $this->setAdminUser();
        $cmid = $this->module_with_pdf();
        $this->queue_pages(30);
        for ($i = 25; $i <= 30; $i++) {
            $this->queue_vision('Page ' . $i);
        }

        $result = json_decode(document_read_tool::read($cmid, 'admin', '25-30'), true);

        $this->assertSame([25, 26, 27, 28, 29, 30], $result['pages_vision']);
        $this->assertSame(30, $result['pages_total']);
        $this->assertStringContainsString('Page 30', $result['text']);
        $this->assertSame('data:image/jpeg;base64,' . base64_encode('PAGE25'), $this->sent_image(0));
    }

    /**
     * U5: pages that are not in the document give a clear error instead of an empty text.
     *
     * @return void
     */
    public function test_pages_not_in_the_document_are_reported(): void {
        $this->setAdminUser();
        $cmid = $this->module_with_pdf();
        $this->queue_pages(3);

        $result = json_decode(document_read_tool::read($cmid, 'admin', '40-41'), true);

        $this->assertSame(['error' => get_string('documents_error_pages', 'local_jedomi', 3)], $result);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * U6: a huge page count told by the document service never builds a huge list of skipped pages: the list is capped
     * and its full count is given apart.
     *
     * @return void
     */
    public function test_skipped_pages_list_is_capped(): void {
        $this->doccurl->enqueue(json_encode(['total_pages' => 999999999, 'pages' => [
            ['page' => 1, 'format' => 'JPEG', 'image' => base64_encode('P1')],
        ]]));
        $this->queue_vision('First page text');

        $result = vision_service::read_upload((int) $this->user->id, $this->file('doc.pdf', '%PDF'), 'doc.pdf');

        $this->assertSame(docservice_client::MAX_TOTAL_PAGES, $result['pages_total']);
        $this->assertCount(vision_service::MAX_SKIPPED_LISTED, $result['pages_skipped']);
        $this->assertSame(docservice_client::MAX_TOTAL_PAGES - 1, $result['pages_skipped_total']);
    }
}
