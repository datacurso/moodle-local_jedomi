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

/**
 * Document Read tool — read the PDF file of a course activity as text.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

use local_jedomi\ai\model_settings;
use local_jedomi\local\service\vision_service;

/**
 * Agent tool that reads the first published PDF of a course module through POST /jedomi/v1/files/read.
 *
 * Only the content the activity publishes to its participants is readable: the files of the
 * component of the module ('mod_' . modname) in its content areas (see {@see self::CONTENT_AREAS}).
 * Files of submissions, feedback, responses, forum post attachments, questions and users are never read.
 *
 * The AI service returns only text: pages with text are read directly and scanned pages, tables
 * and diagrams are read by the vision model. Offered only while file and image processing is on.
 */
class document_read_tool implements agent_tool {
    use acts_for_user;

    /** @var string[] Roles that can read activity documents. */
    private const ALLOWED_ROLES = ['teacher', 'editingteacher', 'manager', 'admin'];

    /**
     * @var array File areas of the published content of each module (module name => file areas of 'mod_<module>').
     *
     * Only areas shown to every participant of the activity are listed; areas of submissions, feedback, responses,
     * post attachments, questions or users never are.
     */
    private const CONTENT_AREAS = [
        'assign' => ['intro', 'introattachment'],
        'book' => ['intro', 'chapter'],
        'folder' => ['intro', 'content'],
        'forum' => ['intro'],
        'label' => ['intro'],
        'lesson' => ['intro', 'page_contents'],
        'page' => ['intro', 'content'],
        'resource' => ['intro', 'content'],
        'url' => ['intro'],
        'workshop' => ['intro', 'instructauthors', 'instructreviewers'],
    ];

    /** @var string[] File areas read for a module not listed in {@see self::CONTENT_AREAS}. */
    private const DEFAULT_CONTENT_AREAS = ['intro', 'content'];

    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'document_read'.
     */
    public function get_name(): string {
        return 'document_read';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with a required cmid and optional pages.
     */
    public function get_definition(): array {
        return self::definition();
    }

    /**
     * OpenAI-compatible function definition of document_read, shared by every channel.
     *
     * @return array
     */
    public static function definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'document_read',
                'description' => get_string('tool_document_read_desc', 'local_jedomi'),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'cmid' => [
                            'type' => 'integer',
                            'description' => get_string('tool_document_read_cmid', 'local_jedomi'),
                        ],
                        'pages' => [
                            'type' => 'string',
                            'description' => get_string('tool_document_read_pages', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['cmid'],
                ],
            ],
        ];
    }

    /**
     * Whether the tool can be offered to a role.
     *
     * @param string $role Jedomi role.
     * @return bool
     */
    public static function is_offered(string $role): bool {
        return model_settings::is_files_enabled() && in_array($role, self::ALLOWED_ROLES, true);
    }

    /**
     * Read the published PDF of a module for the user the tool runs for.
     *
     * @param \stdClass $params Decoded tool arguments; requires 'cmid', accepts 'pages' (such as "1,3-5").
     * @return string JSON-encoded text with its pages, or an error message.
     */
    public function execute(\stdClass $params): string {
        return self::read((int) ($params->cmid ?? 0), $this->actor_role(), (string) ($params->pages ?? ''), $this->userid);
    }

    /**
     * Locate the first published PDF of a module and return its text read by the AI service.
     *
     * Requires a teacher-or-above role and access to the course module (see {@see self::can_read_module()}). Only the
     * published content of the module is searched (see {@see self::find_content_pdf()}).
     *
     * @param int $cmid Course module id.
     * @param string $role Jedomi role of the user.
     * @param string $pages Pages to read, such as "1,3-5"; empty for the whole document.
     * @param int|null $userid User whose permissions are checked (the agent creator); null for the session user.
     * @return string JSON-encoded {cmid, filename, pages_total, pages_vision, pages_skipped, text}, or {error}.
     */
    public static function read(int $cmid, string $role, string $pages = '', ?int $userid = null): string {
        global $USER;

        if (!model_settings::is_files_enabled()) {
            return json_encode(['error' => get_string('files_error_disabled', 'local_jedomi')]);
        }
        if ($cmid <= 0) {
            return json_encode(['error' => 'cmid is required']);
        }
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        try {
            $userid = $userid ?? (int) ($USER->id ?? 0);
            if ($userid <= 0) {
                return json_encode(['error' => 'permission_denied']);
            }
            [$course, $cm] = get_course_and_cm_from_cmid($cmid, '', 0, $userid);
            $context = \context_module::instance($cmid);
            if (!self::can_read_module($course, $cm, $context, $userid)) {
                return json_encode(['error' => 'permission_denied']);
            }
            $file = self::find_content_pdf($context, $cm->modname);
            if (!$file) {
                return json_encode(['error' => 'no PDF files found in this activity']);
            }
        } catch (\Throwable $e) {
            return json_encode(['error' => 'document read failed: ' . $e->getMessage()]);
        }

        try {
            $read = vision_service::read_stored_file($file, trim($pages));
        } catch (\Throwable $e) {
            return json_encode(['error' => vision_service::error_for($e)['message']], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'cmid' => $cmid,
            'filename' => $file->get_filename(),
            'pages_total' => $read['pages_total'],
            'pages_vision' => $read['pages_vision'],
            'pages_skipped' => $read['pages_skipped'],
            'text' => $read['text'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * File areas of the published content of a module.
     *
     * @param string $modname Module name, such as 'assign'.
     * @return string[] File areas of the component 'mod_' . $modname.
     */
    public static function content_areas(string $modname): array {
        return self::CONTENT_AREAS[$modname] ?? self::DEFAULT_CONTENT_AREAS;
    }

    /**
     * First PDF of the published content of a module.
     *
     * Searches only the component 'mod_' . $modname in the areas of {@see self::content_areas()}, so files of
     * submissions, feedback, responses, post attachments, questions or users are never returned. The order is
     * deterministic: highest sort order first (the main file of a resource), then lowest file id.
     *
     * @param \context_module $context Context of the module.
     * @param string $modname Module name, such as 'assign'.
     * @return \stored_file|null The PDF, or null when the published content has none.
     */
    public static function find_content_pdf(\context_module $context, string $modname): ?\stored_file {
        $fs = get_file_storage();
        $pdfs = [];
        foreach (self::content_areas($modname) as $filearea) {
            foreach ($fs->get_area_files($context->id, 'mod_' . $modname, $filearea, false, 'id', false) as $file) {
                if ($file->get_mimetype() === 'application/pdf') {
                    $pdfs[] = $file;
                }
            }
        }
        usort($pdfs, static fn(\stored_file $a, \stored_file $b): int =>
            [$b->get_sortorder(), (int) $a->get_id()] <=> [$a->get_sortorder(), (int) $b->get_id()]);
        return $pdfs[0] ?? null;
    }

    /**
     * Whether a user can see a course module and therefore read its published content.
     *
     * The user must first be able to access the course with an active enrolment (or view it without one, such as a
     * manager) and see it when hidden. Then a user who can manage the activities of the module is allowed, so hidden
     * activities stay readable to them; anyone else must see the module (visibility and availability) and hold the
     * view capability of the module when it defines one. That is the access a participant needs to download the same
     * published content in Moodle.
     *
     * @param \stdClass $course Course of the module.
     * @param \cm_info $cm Module, as seen by the user.
     * @param \context_module $context Context of the module.
     * @param int $userid User whose permissions are checked.
     * @return bool
     */
    private static function can_read_module(\stdClass $course, \cm_info $cm, \context_module $context, int $userid): bool {
        if (!can_access_course($course, $userid, '', true)) {
            return false;
        }
        if (has_capability('moodle/course:manageactivities', $context, $userid)) {
            return true;
        }
        if (!$cm->uservisible) {
            return false;
        }
        $viewcapability = 'mod/' . $cm->modname . ':view';
        return !get_capability_info($viewcapability) || has_capability($viewcapability, $context, $userid);
    }
}
