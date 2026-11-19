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
 * WebService tool — calls Moodle external functions via the internal API.
 *
 * Authorization uses a two-layer gate (matching Telegram webhook_handler):
 *   1. Role allowlist: the web service list saved for the role of the agent
 *      creator (admin/manager/teacher) must contain the function. Unlike the
 *      chat, an administrator without a saved list runs no function.
 *   2. Moodle's core permission system: the agent creator's userid is
 *      passed so the WS function checks capabilities as that user.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

/**
 * Agent tool that invokes Moodle external (web service) functions
 * directly by resolving their class and method, with role allowlist
 * gating and capability checks as the agent creator.
 */
class ws_tool implements agent_tool {
    /** @var int Agent creator's userid (for permission checks). */
    private int $userid;

    /**
     * Constructor.
     *
     * @param int $userid Agent creator's user ID, used for allowlist and capability checks.
     */
    public function __construct(int $userid = 0) {
        $this->userid = $userid;
    }

    /**
     * Get the tool name used in AI tool calls.
     *
     * @return string The tool identifier 'call_webservice'.
     */
    public function get_name(): string {
        return 'call_webservice';
    }

    /**
     * Build the OpenAI-compatible function definition for this tool.
     *
     * @return array Tool definition with a required WS function name and optional params object.
     */
    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'call_webservice',
                'description' => 'Call a Moodle web service function. The function will only work'
                    . ' if the agent creator has the required permissions in Moodle.'
                    . ' Returns the function result or an error message.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'function' => [
                            'type' => 'string',
                            'description' => 'The web service function name (e.g. core_user_get_users, core_course_get_courses).',
                        ],
                        'params' => [
                            'type' => 'object',
                            'description' => 'Parameters to pass to the function as key-value pairs.',
                        ],
                    ],
                    'required' => ['function'],
                ],
            ],
        ];
    }

    /**
     * Run the named external function as the agent creator, with the same gate and validation as the other channels.
     *
     * The role list of the creator is checked first, then the parameters are decoded (the AI sends them as a JSON
     * string, as the tool definition asks; an object is accepted too) and the call goes through ws_runner::call(), which
     * validates them with the Moodle parameter description of the function. $USER is switched to the creator during
     * the call so the capability checks of the function apply to the agent creator.
     *
     * @param \stdClass $params Decoded tool arguments; expects 'wsname' (or 'function') and 'params'.
     * @return string JSON-encoded function result, or an error message.
     */
    public function execute(\stdClass $params): string {
        // Accept 'wsname' (lib.php definition, shared with the web chat) and 'function' (this class's definition).
        $function = trim((string) ($params->wsname ?? $params->ws_name ?? $params->function ?? ''));
        if ($function === '') {
            return json_encode(['error' => 'Empty function name']);
        }

        // ... ── Role list shared with the web chat and Telegram ──
        $role = \local_jedomi_get_user_role_for_user($this->userid);
        if (\local_jedomi_role_has_full_ws_access($role)) {
            // An agent runs unattended: it never gets the "every function" access of an administrator without a saved
            // list (JEDOMI-SEC-001). The list must be saved in the Web services tab first.
            return json_encode(['error' => get_string('ws_agent_allowlist_required', 'local_jedomi')]);
        }
        if (!\local_jedomi\local\ws_runner::role_allows($role, $function)) {
            return json_encode(['error' => 'WS not in allowlist for role: ' . $role]);
        }

        $args = self::decode_params($params->params ?? null);
        if ($args === null) {
            return json_encode([
                'error' => get_string('ws_invalid_parameters', 'local_jedomi', 'params must be a JSON object'),
                'function' => $function,
            ]);
        }

        global $USER;
        $origuser = $USER;
        try {
            $method = \local_jedomi\local\ws_runner::resolve($function);
            if ($this->userid > 0) {
                $USER = \core_user::get_user($this->userid, '*', MUST_EXIST);
            }
            // Writes run in a transaction committed only when the call succeeds, as in Telegram.
            $result = \local_jedomi\local\ws_runner::call($method, $args, true);
            return json_encode([
                'success' => true,
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            // JEDOMI-SEC-009: a message without the debug information, which can quote a query or a path.
            return json_encode([
                'error' => \local_jedomi\local\safe_error::for_model($e),
                'function' => $function,
            ]);
        } finally {
            $USER = $origuser;
        }
    }

    /**
     * Arguments of the function from the "params" argument of the AI: a JSON string (as the tool definition asks), an
     * object or nothing.
     *
     * @param mixed $raw Value of the "params" argument.
     * @return array|null Arguments by name, or null when they are not a JSON object.
     */
    public static function decode_params($raw): ?array {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        // Nested objects become arrays, as Moodle expects for the structures of a web service.
        $decoded = json_decode(json_encode($raw), true);
        return is_array($decoded) ? $decoded : null;
    }
}
