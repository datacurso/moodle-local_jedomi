<?php
/**
 * WebService tool — calls Moodle external functions via the internal API.
 *
 * Authorization uses a two-layer gate (matching Telegram webhook_handler):
 *   1. Role allowlist: the user's role (admin/manager/teacher) must be
 *      allowlisted for the specific WS function, OR be a bare admin with
 *      no explicit allowlist (full access).
 *   2. Moodle's core permission system: the agent creator's userid is
 *      passed so the WS function checks capabilities as that user.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\agent\tool;

defined('MOODLE_INTERNAL') || die();

class ws_tool implements agent_tool {

    /** @var int Agent creator's userid (for permission checks). */
    private int $userid;

    public function __construct(int $userid = 0) {
        $this->userid = $userid;
    }

    public function get_name(): string {
        return 'call_webservice';
    }

    public function get_definition(): array {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'call_webservice',
                'description' => 'Call a Moodle web service function. The function will only work if the agent creator has the required permissions in Moodle. Returns the function result or an error message.',
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

    public function execute(\stdClass $params): string {
        global $CFG;

        $function = trim($params->function ?? '');
        if (empty($function)) {
            return json_encode(['error' => 'Empty function name']);
        }

        // ── Check role allowlist (matches Telegram webhook_handler behaviour) ──
        $role = \local_jedomi_get_user_role_for_user($this->userid);
        $allowlist = \local_jedomi_get_role_allowlist($role);
        // Admin sin allowlist configurada → acceso total.
        // El resto de roles (o admin con allowlist explícita) → debe matchear.
        if ($role !== 'admin' || !empty($allowlist)) {
            if (!in_array($function, $allowlist, true)) {
                return json_encode(['error' => 'WS not in allowlist for role: ' . $role]);
            }
        }

        // ── Prepare params ───────────────────────────────────────────────────
        $args = $params->params ?? new \stdClass();
        $args = (array) $args;

        // ── Call via internal external API ───────────────────────────────────
        try {
            require_once($CFG->libdir . '/externallib.php');

            // Pass the agent creator's userid — Moodle's permission system
            // will check capabilities and return an error if unauthorized.
            $result = \external_api::call_external_function(
                $function,
                $args,
                true,   // $ajaxonly — true for security (no Ajax-only bypass)
                $this->userid > 0 ? $this->userid : null
            );

            if (!empty($result['error'])) {
                $errorDetail = '';
                if (isset($result['exception'])) {
                    $ex = $result['exception'];
                    $errorDetail = $ex->errorcode ?? $ex->message ?? 'Unknown error';
                }
                return json_encode([
                    'error' => 'Permission denied or function error: ' . $errorDetail,
                    'function' => $function,
                ]);
            }

            return json_encode([
                'success' => true,
                'data' => $result['data'],
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => $e->getMessage(),
                'function' => $function,
            ]);
        }
    }
}
