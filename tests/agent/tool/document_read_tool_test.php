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

namespace local_jedomi\agent\tool;

use local_jedomi\ai\fake_curl;
use local_jedomi\ai\testable_provider_api;
use local_jedomi\local\service\provider_upload_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for the document_read tool, which reads the PDF of a course activity with the vision service (M8).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\agent\tool\document_read_tool
 * @covers     \local_jedomi\proxy\handler
 */
final class document_read_tool_test extends \advanced_testcase {
    /** @var fake_curl Transport of the provider client. */
    private fake_curl $curl;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the provider client cannot be built.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('licensekey', 'test-license', 'aiprovider_datacurso');
        \core\plugininfo\aiprovider::enable_plugin('datacurso', 1);
        $this->curl = new fake_curl();
        provider_upload_service::set_api_for_testing(new testable_provider_api(30, $this->curl));
    }

    #[\Override]
    protected function tearDown(): void {
        provider_upload_service::set_api_for_testing(null);
        parent::tearDown();
    }

    /**
     * Create a page activity with a PDF file and return its course module id.
     *
     * @return int
     */
    private function create_module_with_pdf(): int {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($page->cmid)->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'syllabus.pdf',
        ], '%PDF-1.4 fake');
        return (int) $page->cmid;
    }

    /**
     * M8: the agent tool sends the PDF of the activity to files/read and returns its text.
     */
    public function test_agent_tool_returns_the_text_of_the_pdf(): void {
        $cmid = $this->create_module_with_pdf();
        $this->curl->enqueue(json_encode(['text' => 'Unit 1: Algebra', 'model' => 'm', 'pages_total' => 4,
            'pages_vision' => [2], 'pages_skipped' => []]));

        $result = json_decode((new document_read_tool())->execute((object) ['cmid' => $cmid, 'pages' => '1-2']), true);

        $this->assertSame([
            'cmid' => $cmid,
            'filename' => 'syllabus.pdf',
            'pages_total' => 4,
            'pages_vision' => [2],
            'pages_skipped' => [],
            'text' => 'Unit 1: Algebra',
        ], $result);
        $this->assertStringEndsWith('/jedomi/v1/files/read', $this->curl->calls[0]['url']);
        $this->assertSame('1-2', $this->curl->calls[0]['params']['pages']);
        $this->assertSame('syllabus.pdf', $this->curl->calls[0]['params']['file']->getPostFilename());
    }

    /**
     * M8: the web chat tool returns the same text, never images.
     */
    public function test_chat_tool_returns_text(): void {
        $cmid = $this->create_module_with_pdf();
        $this->curl->enqueue(json_encode(['text' => 'Unit 1', 'model' => 'm', 'pages_total' => 1, 'pages_vision' => [],
            'pages_skipped' => []]));

        $result = json_decode(\local_jedomi\proxy\handler::run_tool('document_read', ['cmid' => $cmid], 'admin'), true);

        $this->assertSame('Unit 1', $result['text']);
        $this->assertArrayNotHasKey('pages', $result);
        $this->assertArrayNotHasKey('pages', $this->curl->calls[0]['params']);
    }

    /**
     * M8: with file processing off the tool answers that it is not enabled and sends nothing.
     */
    public function test_tool_is_refused_when_files_are_disabled(): void {
        $cmid = $this->create_module_with_pdf();
        set_config('files_enabled', 0, 'local_jedomi');

        $result = json_decode((new document_read_tool())->execute((object) ['cmid' => $cmid]), true);

        $this->assertSame(get_string('files_error_disabled', 'local_jedomi'), $result['error']);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * M8: an error of files/read is returned with its message, without internal details.
     */
    public function test_service_error_is_returned_with_its_message(): void {
        $cmid = $this->create_module_with_pdf();
        $body = json_encode(['detail' => ['code' => 'jedomi_file_no_text', 'message' => 'internal']]);
        $this->curl->enqueue($body, 422);

        $result = json_decode(\local_jedomi\proxy\handler::run_tool('document_read', ['cmid' => $cmid], 'admin'), true);

        $this->assertSame(get_string('image_error_no_text', 'local_jedomi'), $result['error']);
        $this->assertDebuggingCalled("HTTP error 422 from Datacurso API (" . strlen($body) . ' bytes)');
    }

    /**
     * An activity without a PDF is reported without calling the service.
     */
    public function test_activity_without_pdf_is_reported(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $result = json_decode((new document_read_tool())->execute((object) ['cmid' => $page->cmid]), true);

        $this->assertSame('no PDF files found in this activity', $result['error']);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * M8: document_read is offered to every channel only while file and image processing is on.
     */
    public function test_tool_is_offered_only_when_files_are_enabled(): void {
        $names = static fn(array $definitions): array => array_map(
            static fn(array $definition): string => $definition['function']['name'],
            $definitions
        );

        $this->assertContains('document_read', $names(\local_jedomi_get_tool_definitions('admin')));
        $this->assertContains('document_read', $names(\local_jedomi\agent\tool_executor::get_definitions()));
        $this->assertNotContains('document_read', $names(\local_jedomi_get_tool_definitions('student')));

        set_config('files_enabled', 0, 'local_jedomi');
        $this->assertNotContains('document_read', $names(\local_jedomi_get_tool_definitions('admin')));
        $this->assertNotContains('document_read', $names(\local_jedomi\agent\tool_executor::get_definitions()));
    }
}
