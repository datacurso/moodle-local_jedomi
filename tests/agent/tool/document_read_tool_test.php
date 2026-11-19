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
        $GLOBALS['CFG']->forced_plugin_settings['aiprovider_datacurso']['licensekey'] = 'test-license';
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
     * MDL-INT-051: the tool returns the text of the activity PDF in the agents.
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
     * MDL-INT-051: the tool returns the text of the activity PDF in the chat.
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
     * MDL-INT-006: with file processing off the activity PDF tool is refused if called.
     * M8: with file processing off the tool answers that it is not enabled and sends nothing.
     * MDL-INT-051: with file processing off the tool is refused.
     */
    public function test_tool_is_refused_when_files_are_disabled(): void {
        $cmid = $this->create_module_with_pdf();
        set_config('files_enabled', 0, 'local_jedomi');

        $result = json_decode((new document_read_tool())->execute((object) ['cmid' => $cmid]), true);

        $this->assertSame(get_string('files_error_disabled', 'local_jedomi'), $result['error']);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * MDL-INT-051: M8: an error of files/read is returned with its message, without internal details.
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
     * MDL-INT-051: an activity without a PDF is reported.
     */
    public function test_activity_without_pdf_is_reported(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $result = json_decode((new document_read_tool())->execute((object) ['cmid' => $page->cmid]), true);

        $this->assertSame('no PDF files found in this activity', $result['error']);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * MDL-INT-006: with file processing off the activity PDF tool is not offered.
     * MDL-UNIT-094: the activity PDF reader is offered only with file processing on.
     * M8: document_read is offered to every channel only while file and image processing is on.
     * MDL-INT-051: with file processing off the tool is not offered.
     */
    public function test_tool_is_offered_only_when_files_are_enabled(): void {
        $names = static fn(array $definitions): array => array_map(
            static fn(array $definition): string => $definition['function']['name'],
            $definitions
        );

        $this->assertContains('document_read', $names(\local_jedomi_get_tool_definitions('admin')));
        $this->assertContains('document_read', $names((new \local_jedomi\agent\tool_executor())->get_definitions()));
        $this->assertNotContains('document_read', $names(\local_jedomi_get_tool_definitions('student')));

        set_config('files_enabled', 0, 'local_jedomi');
        $this->assertNotContains('document_read', $names(\local_jedomi_get_tool_definitions('admin')));
        $this->assertNotContains('document_read', $names((new \local_jedomi\agent\tool_executor())->get_definitions()));
    }

    /**
     * MDL-INT-032: in the agents the document reading checks the permissions of the agent creator, not of the user the
     * task runs as.
     */
    public function test_agent_reads_only_the_documents_its_creator_can_access(): void {
        $cmid = $this->create_module_with_pdf();
        $othercourse = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');
        $executor = new \local_jedomi\agent\tool_executor(1, (int) $teacher->id);

        $result = json_decode($executor->execute('document_read', json_encode(['cmid' => $cmid])), true);

        $this->assertSame(
            'permission_denied',
            $result['error'] ?? null,
            'A teacher agent must not read the documents of a course its creator does not teach'
        );
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * MDL-INT-041 / MDL-INT-051 / SYS-E2E-007: an editing teacher reads the PDF of an activity of their own course.
     * An editing teacher reads the document of an activity in their own course from the web chat.
     */
    public function test_chat_editing_teacher_of_the_course_can_read(): void {
        $cmid = $this->create_module_with_pdf();
        $course = get_course_and_cm_from_cmid($cmid)[0];
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $this->curl->enqueue(json_encode(['text' => 'Unit 1', 'model' => 'm', 'pages_total' => 1, 'pages_vision' => [],
            'pages_skipped' => []]));

        $result = json_decode(\local_jedomi\proxy\handler::run_tool('document_read', ['cmid' => $cmid], 'teacher'), true);

        $this->assertSame('Unit 1', $result['text'] ?? null, 'An editing teacher must read the documents of their course');
        $this->assertCount(1, $this->curl->calls);
    }

    /**
     * MDL-INT-041 / MDL-INT-051 / SYS-E2E-007: a teacher of another course is refused the PDF.
     * A teacher of another course cannot read the document from the web chat.
     */
    public function test_chat_teacher_of_another_course_cannot_read(): void {
        $cmid = $this->create_module_with_pdf();
        $othercourse = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');
        $this->setUser($teacher);

        $result = json_decode(\local_jedomi\proxy\handler::run_tool('document_read', ['cmid' => $cmid], 'teacher'), true);

        $this->assertSame('permission_denied', $result['error'] ?? null);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * MDL-INT-051: in the agents the creator teacher reads the PDF of their course.
     * An agent created by an editing teacher reads the document of an activity in the creator's course.
     */
    public function test_agent_of_the_course_teacher_can_read(): void {
        $cmid = $this->create_module_with_pdf();
        $course = get_course_and_cm_from_cmid($cmid)[0];
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->curl->enqueue(json_encode(['text' => 'Unit 1', 'model' => 'm', 'pages_total' => 1, 'pages_vision' => [],
            'pages_skipped' => []]));

        $result = json_decode((new document_read_tool((int) $teacher->id))->execute((object) ['cmid' => $cmid]), true);

        $this->assertSame('Unit 1', $result['text'] ?? null);
    }

    /**
     * MDL-INT-051: a hidden activity is refused to a user who cannot see it.
     * A non-editing teacher of the course cannot read the document of a hidden activity.
     */
    public function test_chat_teacher_cannot_read_a_hidden_activity(): void {
        global $DB;
        $cmid = $this->create_module_with_pdf();
        $course = get_course_and_cm_from_cmid($cmid)[0];
        set_coursemodule_visible($cmid, 0);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('moodle/course:viewhiddenactivities', CAP_PROHIBIT, $roleid, \context_course::instance($course->id)->id);
        $this->setUser($teacher);

        $result = json_decode(\local_jedomi\proxy\handler::run_tool('document_read', ['cmid' => $cmid], 'teacher'), true);

        $this->assertSame('permission_denied', $result['error'] ?? null);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * Store a PDF in a module context.
     *
     * @param int $cmid Course module id.
     * @param string $component Component of the file area.
     * @param string $filearea File area.
     * @param int $itemid Item id.
     * @param string $filename File name.
     * @param int $sortorder Sort order (1 marks the main file of a resource).
     */
    private function add_pdf(
        int $cmid,
        string $component,
        string $filearea,
        int $itemid,
        string $filename,
        int $sortorder = 0
    ): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cmid)->id, 'component' => $component, 'filearea' => $filearea,
            'itemid' => $itemid, 'filepath' => '/', 'filename' => $filename, 'sortorder' => $sortorder,
        ], '%PDF-1.4 fake');
    }

    /**
     * Create an assignment whose student submission and annotated feedback PDFs are stored before any other file.
     *
     * @param \stdClass $course Course of the assignment.
     * @param array $options Extra options of the module generator, such as 'visible'.
     * @return int Course module id.
     */
    private function create_assign_with_private_pdfs(\stdClass $course, array $options = []): int {
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id] + $options);
        $this->add_pdf((int) $assign->cmid, 'assignsubmission_file', 'submission_files', 7, 'student_essay.pdf');
        $this->add_pdf((int) $assign->cmid, 'assignfeedback_editpdf', 'combined', 7, 'combined.pdf');
        $this->add_pdf((int) $assign->cmid, 'assignfeedback_editpdf', 'download', 7, 'annotated.pdf');
        return (int) $assign->cmid;
    }

    /**
     * Queue a successful files/read answer.
     */
    private function enqueue_read(): void {
        $this->curl->enqueue(json_encode(['text' => 'Unit 1', 'model' => 'm', 'pages_total' => 1, 'pages_vision' => [],
            'pages_skipped' => []]));
    }

    /**
     * Decision 4: the PDF of an activity of an excluded course is never read, also by a teacher of the course, and
     * nothing is sent to the AI service.
     */
    public function test_activity_of_an_excluded_course_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->add_pdf((int) $page->cmid, 'mod_page', 'content', 0, 'syllabus.pdf');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        set_config('course_mode', 'exclude', 'local_jedomi');
        set_config('course_list', (string) $course->id, 'local_jedomi');

        $result = json_decode(document_read_tool::read((int) $page->cmid, 'teacher', '', (int) $teacher->id), true);

        $this->assertSame(get_string('error_course_excluded', 'local_jedomi'), $result['error'] ?? null);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * MDL-INT-051: a student submission or annotated feedback PDF is never read, even by an editing teacher of the
     * course: the tool reads only the published content of the activity.
     */
    public function test_submission_and_feedback_pdfs_are_never_returned(): void {
        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->create_assign_with_private_pdfs($course);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $result = json_decode(document_read_tool::read($cmid, 'teacher', '', (int) $teacher->id), true);

        $this->assertSame('no PDF files found in this activity', $result['error'] ?? null);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * MDL-INT-051: The published attachment of an assignment is read even when submission PDFs were stored before it.
     */
    public function test_published_assignment_attachment_is_returned_for_the_editing_teacher(): void {
        $course = $this->getDataGenerator()->create_course();
        $cmid = $this->create_assign_with_private_pdfs($course);
        $this->add_pdf($cmid, 'mod_assign', 'introattachment', 0, 'brief.pdf');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->enqueue_read();

        $result = json_decode(document_read_tool::read($cmid, 'teacher', '', (int) $teacher->id), true);

        $this->assertSame('brief.pdf', $result['filename'] ?? null);
        $this->assertSame('brief.pdf', $this->curl->calls[0]['params']['file']->getPostFilename());
    }

    /**
     * MDL-INT-051: a teacher of course A who is a student of course B never reads a submission of B and reads only the
     * published content of a visible activity of B.
     */
    public function test_teacher_elsewhere_reads_only_published_content_where_student(): void {
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($coursea, 'editingteacher');
        $this->getDataGenerator()->enrol_user((int) $user->id, (int) $courseb->id, 'student');
        $onlyprivate = $this->create_assign_with_private_pdfs($courseb);
        $published = $this->create_assign_with_private_pdfs($courseb);
        $this->add_pdf($published, 'mod_assign', 'intro', 0, 'brief.pdf');
        $hidden = $this->create_assign_with_private_pdfs($courseb, ['visible' => 0]);
        $this->add_pdf($hidden, 'mod_assign', 'introattachment', 0, 'draft.pdf');
        $this->enqueue_read();

        $private = json_decode((new document_read_tool((int) $user->id))->execute((object) ['cmid' => $onlyprivate]), true);
        $content = json_decode((new document_read_tool((int) $user->id))->execute((object) ['cmid' => $published]), true);
        $draft = json_decode((new document_read_tool((int) $user->id))->execute((object) ['cmid' => $hidden]), true);

        $this->assertSame('no PDF files found in this activity', $private['error'] ?? null);
        $this->assertSame('brief.pdf', $content['filename'] ?? null);
        $this->assertSame('permission_denied', $draft['error'] ?? null, 'A hidden activity is refused to a student');
        $this->assertCount(1, $this->curl->calls);
    }

    /**
     * MDL-INT-051: A suspended enrolment is refused, even for the published content.
     */
    public function test_suspended_enrolment_is_refused(): void {
        $cmid = $this->create_module_with_pdf();
        $course = get_course_and_cm_from_cmid($cmid)[0];
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user(
            (int) $user->id,
            (int) $course->id,
            'editingteacher',
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );

        $result = json_decode(document_read_tool::read($cmid, 'teacher', '', (int) $user->id), true);

        $this->assertSame('permission_denied', $result['error'] ?? null);
        $this->assertSame([], $this->curl->calls);
    }

    /**
     * MDL-INT-051: A hidden course is refused unless the user can view hidden courses.
     */
    public function test_hidden_course_is_refused_unless_the_user_can_view_hidden_courses(): void {
        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->add_pdf((int) $page->cmid, 'mod_page', 'content', 0, 'syllabus.pdf');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->enqueue_read();

        $refused = json_decode(document_read_tool::read((int) $page->cmid, 'teacher', '', (int) $student->id), true);
        $allowed = json_decode(document_read_tool::read((int) $page->cmid, 'teacher', '', (int) $teacher->id), true);

        $this->assertSame('permission_denied', $refused['error'] ?? null);
        $this->assertSame('syllabus.pdf', $allowed['filename'] ?? null);
        $this->assertCount(1, $this->curl->calls);
    }

    /**
     * MDL-INT-051: An editing teacher can still read the published content of a hidden activity of their course.
     */
    public function test_editing_teacher_reads_the_content_of_a_hidden_activity(): void {
        $cmid = $this->create_module_with_pdf();
        $course = get_course_and_cm_from_cmid($cmid)[0];
        set_coursemodule_visible($cmid, 0);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->enqueue_read();

        $result = json_decode(document_read_tool::read($cmid, 'teacher', '', (int) $teacher->id), true);

        $this->assertSame('syllabus.pdf', $result['filename'] ?? null);
    }

    /**
     * MDL-INT-051: The main file of a resource is read first, whatever the order the files were stored in.
     */
    public function test_main_file_of_a_resource_is_read_first(): void {
        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $fs = get_file_storage();
        $fs->delete_area_files(\context_module::instance($resource->cmid)->id, 'mod_resource', 'content');
        $this->add_pdf((int) $resource->cmid, 'mod_resource', 'content', 0, 'annex.pdf');
        $this->add_pdf((int) $resource->cmid, 'mod_resource', 'content', 0, 'main.pdf', 1);
        $this->enqueue_read();

        $result = json_decode((new document_read_tool())->execute((object) ['cmid' => $resource->cmid]), true);

        $this->assertSame('main.pdf', $result['filename'] ?? null);
    }
}
