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
 * Document Read tool — read PDF files from course activities and convert to images.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that extracts the first PDF from a course module and sends
 * it to the external document service to be converted into page images.
 */
class document_read_tool implements agent_tool {
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
     * @return array Tool definition with a required cmid and optional dpi and image format.
     */
    public function get_definition(): array {
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
                        'dpi' => [
                            'type' => 'integer',
                            'description' => get_string('tool_document_read_dpi', 'local_jedomi'),
                        ],
                        'fmt' => [
                            'type' => 'string',
                            'description' => get_string('tool_document_read_fmt', 'local_jedomi'),
                        ],
                    ],
                    'required' => ['cmid'],
                ],
            ],
        ];
    }

    /**
     * Locate a PDF in the module context, upload it to the document service, and return the rendered pages.
     *
     * Requires a teacher-or-above role and course view capability. Fails with a JSON
     * error when no PDF exists, the service is unreachable, or conversion fails.
     *
     * @param \stdClass $params Decoded tool arguments; requires 'cmid', accepts 'dpi' (default 150) and 'fmt' (JPEG|PNG).
     * @return string JSON-encoded page images with metadata, or an error message.
     */
    public function execute(\stdClass $params): string {
        global $CFG, $DB;

        $cmid = (int) ($params->cmid ?? 0);
        $dpi  = (int) ($params->dpi ?? 150);
        $fmt  = in_array($params->fmt ?? '', ['JPEG', 'PNG'], true) ? $params->fmt : 'JPEG';

        if ($cmid <= 0) {
            return json_encode(['error' => 'cmid is required']);
        }

        // Role check — teacher+.
        $role = \local_jedomi_get_user_role();
        $allowed = ['teacher', 'editingteacher', 'manager', 'admin'];
        if (!in_array($role, $allowed, true)) {
            return json_encode(['error' => 'permission_denied']);
        }

        $serviceurl = rtrim((string) get_config('local_jedomi', 'docservice_url'), '/');
        if (empty($serviceurl)) {
            return json_encode(['error' => 'document service not configured']);
        }

        try {
            $context = \context_module::instance($cmid);
            $cm      = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            require_capability('moodle/course:view', $context->get_course_context());

            // Find all PDFs in this module context.
            $sql = "SELECT id, filename, filepath, mimetype, component, filearea, itemid
                      FROM {files}
                     WHERE contextid = ?
                       AND filename != '.'
                       AND mimetype = 'application/pdf'";
            $rows = $DB->get_records_sql($sql, [$context->id]);

            if (empty($rows)) {
                return json_encode(['error' => 'no PDF files found in this activity']);
            }

            $row  = reset($rows);
            $fs   = get_file_storage();
            $file = $fs->get_file_by_id($row->id);
            if (!$file) {
                return json_encode(['error' => 'file not found in storage']);
            }

            $filename = $file->get_filename();

            // Send to document-to-image service.
            $tmpdir  = make_temp_directory('jedomi/docread');
            $tmppath = $tmpdir . '/' . $filename;
            $file->copy_content_to($tmppath);

            $ch = curl_init($serviceurl . '/api/v1/pdf/to-images?dpi=' . $dpi . '&fmt=' . $fmt);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_POSTFIELDS     => ['file' => new \CURLFile($tmppath, 'application/pdf', $filename)],
            ]);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlerr  = curl_error($ch);
            curl_close($ch);

            unlink($tmppath);

            if ($curlerr) {
                return json_encode(['error' => 'document service request failed: ' . $curlerr]);
            }

            if ($httpcode !== 200) {
                return json_encode(['error' => 'document service returned HTTP ' . $httpcode]);
            }

            $data = json_decode($response, true);
            if (!isset($data['pages']) || empty($data['pages'])) {
                return json_encode(['error' => 'document service returned no pages']);
            }

            return json_encode([
                'cmid'        => $cmid,
                'filename'    => $filename,
                'total_pages' => $data['total_pages'],
                'dpi'         => $data['dpi'],
                'pages'       => $data['pages'],
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => 'document read failed: ' . $e->getMessage()]);
        }
    }
}
