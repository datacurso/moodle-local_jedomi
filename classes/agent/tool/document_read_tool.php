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
 * Agent tool that reads the first PDF of a course module through POST /jedomi/v1/files/read.
 *
 * The AI service returns only text: pages with text are read directly and scanned pages, tables
 * and diagrams are read by the vision model. Offered only while file and image processing is on.
 */
class document_read_tool implements agent_tool {
    use acts_for_user;

    /** @var string[] Roles that can read activity documents. */
    private const ALLOWED_ROLES = ['teacher', 'editingteacher', 'manager', 'admin'];

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
     * Read the PDF of the module of the current user.
     *
     * @param \stdClass $params Decoded tool arguments; requires 'cmid', accepts 'pages' (such as "1,3-5").
     * @return string JSON-encoded text with its pages, or an error message.
     */
    public function execute(\stdClass $params): string {
        return self::read((int) ($params->cmid ?? 0), $this->actor_role(), (string) ($params->pages ?? ''), $this->userid);
    }

    /**
     * Locate a PDF in the module context and return its text read by the AI service.
     *
     * Requires a teacher-or-above role and access to the course module (see {@see self::can_read_module()}).
     *
     * @param int $cmid Course module id.
     * @param string $role Jedomi role of the user.
     * @param string $pages Pages to read, such as "1,3-5"; empty for the whole document.
     * @param int|null $userid User whose permissions are checked (the agent creator); null for the session user.
     * @return string JSON-encoded {cmid, filename, pages_total, pages_vision, pages_skipped, text}, or {error}.
     */
    public static function read(int $cmid, string $role, string $pages = '', ?int $userid = null): string {
        global $DB, $USER;

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
            $context = \context_module::instance($cmid);
            if (!self::can_read_module($cmid, $context, $userid ?? (int) ($USER->id ?? 0))) {
                return json_encode(['error' => 'permission_denied']);
            }

            $sql = "SELECT id
                      FROM {files}
                     WHERE contextid = ?
                       AND filename != '.'
                       AND mimetype = 'application/pdf'
                  ORDER BY id";
            $ids = $DB->get_fieldset_sql($sql, [$context->id]);
            if (empty($ids)) {
                return json_encode(['error' => 'no PDF files found in this activity']);
            }
            $file = get_file_storage()->get_file_by_id((int) reset($ids));
            if (!$file) {
                return json_encode(['error' => 'file not found in storage']);
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
     * Whether a user can see a course module and therefore read its documents.
     *
     * The user must be able to manage the activity, or access its course, see the module (visibility and
     * availability) and hold the view capability of the module when it defines one.
     *
     * @param int $cmid Course module id.
     * @param \context_module $context Context of the module.
     * @param int $userid User whose permissions are checked; 0 is always denied.
     * @return bool
     */
    private static function can_read_module(int $cmid, \context_module $context, int $userid): bool {
        if ($userid <= 0) {
            return false;
        }
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, '', 0, $userid);
        if (has_capability('moodle/course:manageactivities', $context, $userid)) {
            return true;
        }
        if (!can_access_course($course, $userid, '', true) || !$cm->uservisible) {
            return false;
        }
        $viewcapability = 'mod/' . $cm->modname . ':view';
        return !get_capability_info($viewcapability) || has_capability($viewcapability, $context, $userid);
    }
}
