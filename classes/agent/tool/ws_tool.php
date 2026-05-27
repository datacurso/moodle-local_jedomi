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
        global $DB;

        // Accept both 'function' (this class's definition) and 'wsname' (lib.php definition — web chat compat).
        $function = trim($params->function ?? $params->wsname ?? '');
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
        $callerParams = $params->params ?? new \stdClass();
        $callerParams = (array) $callerParams;

        // ── Resolve target class + method (same approach as web chat handler) ──
        // Do NOT use call_external_function — it requires a registered web service
        // with a token. The agent runner is a cron task, not a WS client.
        // Directly resolve and call the external function's class::method().
        try {
            $funcRecord = $DB->get_record(
                'external_functions',
                ['name' => $function],
                'classname, classpath, component, methodname'
            );
            if (!$funcRecord) {
                return json_encode(['error' => "Web service function '{$function}' not found"]);
            }

            $classname = \local_jedomi_resolve_ws_classname($function);
            if (!$classname || !class_exists($classname)) {
                return json_encode(['error' => "Class '{$classname}' not found"]);
            }

            $methodname = $funcRecord->methodname;
            if (!method_exists($classname, $methodname)) {
                return json_encode(['error' => "Method '{$classname}::{$methodname}()' not found"]);
            }

            // ── Map params by name via reflection ────────────────────────────
            $ref = new \ReflectionMethod($classname, $methodname);
            $callargs = [];
            foreach ($ref->getParameters() as $param) {
                $name = $param->getName();
                if (array_key_exists($name, $callerParams)) {
                    $callargs[] = $callerParams[$name];
                } elseif ($param->isDefaultValueAvailable()) {
                    $callargs[] = $param->getDefaultValue();
                } else {
                    return json_encode(['error' => "Missing required parameter: {$name}"]);
                }
            }

            // ── Temporarily override $USER for capability checks ─────────────
            global $USER;
            $origUser = $USER;
            if ($this->userid > 0) {
                $USER = \core_user::get_user($this->userid, '*', MUST_EXIST);
            }

            try {
                $result = $ref->invokeArgs(null, $callargs);
            } finally {
                $USER = $origUser;
            }

            return json_encode([
                'success' => true,
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return json_encode([
                'error' => $e->getMessage(),
                'function' => $function,
            ]);
        }
    }
}
