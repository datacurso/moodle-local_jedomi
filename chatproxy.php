<?php
/**
 * Local AI Chat Proxy — SSE endpoint for function-calling tool loop.
 *
 * Receives POST from sidebar.js with {model, messages, spt}, validates the
 * Signed Permission Token (SPT), builds role-scoped tool definitions, streams
 * the request to the AI API via SSE, intercepts tool_calls, executes them
 * locally via direct PHP calls, and re-injects results into the conversation.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

// Sin límite de tiempo — el tool loop puede hacer múltiples calls al modelo.
set_time_limit(0);
ignore_user_abort(true);

// Limpiar cualquier buffer de salida de Moodle que bloquee el SSE.
while (ob_get_level() > 1) {
    ob_end_clean();
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    die('Method Not Allowed');
}

require_login();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['messages'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'invalid_request']));
}

// Validate sesskey from JSON body.
$sesskey = $input['sesskey'] ?? '';
if (!confirm_sesskey($sesskey)) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'invalid_sesskey']));
}

$model    = $input['model'] ?? get_config('local_jedomi', 'model') ?: 'jedomi-e4b';
$messages = $input['messages'];
$spt      = $input['spt'] ?? null;
$context  = $input['context'] ?? [];

// --- SPT validation ---
if (!$spt || !is_array($spt)) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'invalid_spt']));
}

$role = validate_spt($spt);

// --- Build request ---
$system  = build_system_message($role, $spt, $context);
$messages = array_merge([$system], $messages);

// --- SSE headers ---
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
ob_implicit_flush(true);

// --- Run tool loop (handles <|toolcall|> markers, gateway handles SPT) ---
run_tool_loop($model, $messages, $spt, $role);

// =========================================================================
// Helper functions
// =========================================================================

/**
 * Validate SPT signature, TTL, and sesskey binding.
 *
 * @param  array  $spt The signed permission token.
 * @return string      The resolved role (admin|manager|teacher|student).
 */
function validate_spt(array $spt): string {
    // Check sesskey binding.
    $expectedHash = substr(hash('sha256', sesskey()), 0, 16);
    if (!hash_equals($expectedHash, $spt['sesskey_hash'] ?? '')) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'invalid_session']));
    }

    // Check TTL.
    if (time() >= ($spt['expires'] ?? 0)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'token_expired']));
    }

    // Reconstruct and verify signature.
    $secret = get_config('local_jedomi', 'spt_secret') ?: 'jedomi-spt-secret';
    $payload = $spt;
    unset($payload['signature']);
    ksort($payload);
    $expected = hash_hmac('sha256', json_encode($payload), $secret);

    if (!hash_equals($expected, $spt['signature'] ?? '')) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'invalid_signature']));
    }

    return $spt['role'] ?? 'student';
}

/**
 * Build the system message for the AI model.
 *
 * @param  string $role    The user's resolved role.
 * @param  array  $spt     The validated SPT payload.
 * @param  array  $context The current page context.
 * @return array           ['role' => 'system', 'content' => '...']
 */
function build_system_message(string $role, array $spt, array $context = []): array {
    global $CFG;

    $sitename  = get_config('core', 'sitename') ?: 'Moodle';
    $release   = $CFG->release ?? 'unknown';
    $courseId  = $spt['course_id'] ?? 0;
    $courseName = $context['course_name'] ?? '';
    $location   = $context['location'] ?? 'unknown';
    $pageUrl    = $context['page_url'] ?? '';
    $pageTitle  = $context['page_title'] ?? '';

    $locLabels = [
        'course'    => 'Currently viewing a course' . ($courseName ? " \"{$courseName}\"" : '') . " (ID: {$courseId}).",
        'activity'  => 'Currently in an activity of type "' . ($context['activity_type'] ?? 'unknown') . '".',
        'grades'    => 'Currently viewing the gradebook.',
        'admin'     => 'Currently in the Moodle administration panel.',
        'dashboard' => 'Currently on the Moodle dashboard.',
        'messages'  => 'Currently in the messages section.',
        'profile'   => 'Currently viewing a user profile.',
        'calendar'  => 'Currently viewing the calendar.',
        'files'     => 'Currently in the files area.',
    ];

    // Detect database type automatically from Moodle config.
    $dbtype = $CFG->dbtype;
    $dblabel = match ($dbtype) {
        'mysqli', 'mariadb' => 'MySQL/MariaDB',
        'pgsql'             => 'PostgreSQL',
        default             => $dbtype,
    };

    $content = "You are Jedomi, an AI assistant integrated into Moodle ({$sitename}).\n";
    $content .= "Your function is to help users (role: {$role}) answer questions about the site using the available tools.\n\n";
    $content .= "SQL CONFIDENCE RULE:\n";
    $content .= "You know Moodle core schema tables like the back of your hand. These are STABLE across versions:\n";
    $content .= "  course, user, grade_items, grade_grades, enrol, user_enrolments, context, role_assignments,\n";
    $content .= "  course_categories, groups, groupings, group_members, cohorts, cohort_members, course_modules,\n";
    $content .= "  module, tag, tag_instance, files, user_info_data, user_info_field, config, config_plugins.\n";
    $content .= "  IMPORTANT — Moodle naming quirks you MUST know:\n";
    $content .= "    - Table names are PLURAL: course_categories (NOT course_category), course_modules (NOT course_module)\n";
    $content .= "    - course_modules.course is the FK to course.id (NOT course_modules.courseid)\n";
    $content .= "    - user_enrolments is the standard enrolment mapping table\n";
    $content .= "    - context.contextlevel = 50 means course context, = 10 means system, = 70 means user, = 80 means module\n";
    $content .= "  STRATEGY:\n";
    $content .= "    1. FIRST ATTEMPT → Write SQL directly using your schema knowledge. Go for it.\n";
    $content .= "    2. ONLY describe_table if: (a) the SQL fails with 'Unknown column', (b) you need a non-core/plugin\n";
    $content .= "       table, or (c) you're genuinely unsure about a specific column name.\n";
    $content .= "    3. You know the columns. Trust your training data for core tables — it's extremely reliable.\n";
    $content .= "  This saves 5-10 round trips per query. The SQL runs in a try/catch — NOTHING breaks if wrong.\n\n";
    $content .= "  NEW — Complex JOIN queries: If your SQL involves 3+ joined tables OR you are unsure about FK column\n";
    $content .= "    names, call describe_tables FIRST on all tables involved. The error-recovery path wastes more time\n";
    $content .= "    than describing tables upfront. For simple 1-2 table queries, write directly.\n\n";

    $content .= "SQL DEBUGGING FLOW (follow this EXACT order when a query fails):\n";
    $content .= "  Phase 1 — INSPECT: Describe ALL tables you need in ONE call using describe_tables{tables: [\"table1\", \"table2\", ...]}. Do NOT call describe_table one at a time.\n";
    $content .= "  Phase 2 — FIX: Based on the real schema (column names, types), rewrite the SQL.\n";
    $content .= "  Phase 3 — RETRY: Call execute_sql with the corrected query.\n";
    $content .= "  Phase 4 — If retry also fails → repeat Phase 1-3 for any tables you missed, OR respond with data if you already have enough.\n";
    $content .= "  IMPORTANT: Do NOT mix describe_tables and execute_sql. Do ALL describes first, THEN retry.\n\n";

    $content .= "QUERY HISTORY — reusing successful queries:\n";
    $content .= "  After each successful execute_sql call, the result is annotated with a --- QUERY HISTORY ---\n";
    $content .= "  section showing all successful queries in this conversation. Use this to:\n";
    $content .= "  - Reference column names you already discovered (no need to re-describe tables)\n";
    $content .= "  - Reuse join patterns that worked (e.g., JOIN {course_categories} cat ON c.category = cat.id)\n";
    $content .= "  - Build on previous results instead of re-querying from scratch\n";
    $content .= "  Example: if query #0 already fetched course names, query #1 can JOIN on that result's columns.\n\n";

    $content .= "SQL + WS COMBINED STRATEGY:\n";
    $content .= "  Some questions are best answered by using SQL to GET data and then WS to ACT on it.\n";
    $content .= "  Example flow:\n";
    $content .= "    1. execute_sql: SELECT u.id, u.email FROM {user} u JOIN {role_assignments} ... (find users)\n";
    $content .= "    2. Then use that user data to craft a WS call if needed.\n";
    $content .= "  This is more powerful than using either tool alone.\n\n";
    $content .= "  Path A (WS-first): ws_search(query) → ws_describe → ws_execute — safest, use when a WS exists.\n";
    $content .= "  WS STRATEGY (MANDATORY when using Path A):\n";
    $content .= "    1. ALWAYS call ws_search first with the action in ENGLISH (e.g. 'create user', 'enrol student', 'get course grades').\n";
    $content .= "    2. Read the result: check 'name' (exact function name), 'params' (* = REQUIRED — must be present in the JSON).\n";
    $content .= "    3. Call ws_describe with the exact 'name' to confirm the full parameter schema before executing.\n";
    $content .= "    4. Call ws_execute with the EXACT 'name' and a JSON containing EVERY * (required) field.\n";
    $content .= "       If the user did not provide a value for a required field, generate a sensible default rather than omitting it.\n";
    $content .= "       Example: for core_user_create_users, if no password is given, generate a secure random password.\n";
    $content .= "    NEVER invent or guess a WS function name. NEVER call ws_execute without first calling ws_search.\n";
    $content .= "  WS ERROR HANDLING:\n";
    $content .= "    - If ws_execute returns an error, read the full error message in the result.\n";
    $content .= "    - Common causes: missing required (*) field, invalid type, duplicate unique value.\n";
    $content .= "    - If error mentions a missing field, add that field with a sensible default and retry.\n";
    $content .= "    - If error says duplicate value, inform the user that the item already exists.\n";
    $content .= "    - Do NOT retry more than once with the same parameters.\n";
    $content .= "  Path B (SQL): execute_sql — most flexible, use for custom aggregations, cross-table, or when no WS matches.\n";
    $content .= "  Path C (Discovery): search_tables / describe_table — ONLY when you need to inspect the schema (e.g., before writing SQL, or after a SQL error).\n";
    $content .= "  Tip: Use {tablename} syntax (e.g. {course}) for Moodle tables — no need to know the prefix.\n";
    $content .= "  SQL syntax tip: Use `LEFT JOIN table t ON t.col = other.col` WITHOUT parentheses around the ON condition.\n";
    $content .= "  BAD: `LEFT JOIN {enrol} e ON (e.courseid = c.id)`\n";
    $content .= "  GOOD: `LEFT JOIN {enrol} e ON e.courseid = c.id`\n\n";

    $content .= "ANTI-HALLUCINATION RULES:\n";
    $content .= "1. CRITICAL: You MUST call a tool BEFORE answering ANY question about site data. NEVER guess numbers (grades, user counts, dates, etc.).\n";
    $content .= "2. If a tool returns 0 rows or an error, report it — do NOT fabricate results.\n";
    $content .= "3. KNOW WHEN TO STOP: If a tool already returned the data you need to answer the question, STOP calling more tools and respond. Do NOT try to write a \"more perfect\" query if the data is sufficient.\n";
    $content .= "4. RECOVERY — SQL debugging failed: If you followed the SQL DEBUGGING FLOW (steps 1-5) and ALL attempts failed, you MAY answer with general knowledge about how Moodle works (table structures, common patterns, typical column names), but you MUST preface with: \"Según mi conocimiento general (no datos actuales del sitio):\" and NEVER present it as live site data.\n";
    $content .= "5. Only use execute_sql for SELECT queries. Never modify data.\n";
    $content .= "6. Use bound parameters (?) instead of concatenating values in SQL.\n";
    $content .= "7. Respect privacy: do not reveal personal data of other users unless authorized.\n";
    $content .= "8. ALWAYS RESPOND IN SPANISH. Never write responses in English or any other language.\n";
    $content .= "9. BE CONCISE. If SQL fails after 2-3 attempts, do NOT write a long analysis. Instead, report the error briefly in Spanish.\n";
    $content .= "   BAD example: Writing a long paragraph analyzing column names, join logic, debugging steps in English.\n";
    $content .= "   GOOD example: \"Lo siento, no se pudieron obtener las notas. Es posible que no haya calificaciones registradas.\"\n\n";

    $content .= "Current context:\n";
    $content .= "- Moodle version: {$release}\n";
    $content .= "- Database: {$dblabel}\n";
    $content .= "- Location: {$location}\n";
    $content .= "- Page: {$pageTitle} ({$pageUrl})\n";
    $content .= isset($locLabels[$location]) ? "- {$locLabels[$location]}\n" : '';
    $content .= "- User role: {$role}\n";

    return ['role' => 'system', 'content' => $content];
}

/**
 * Send an SSE event line to the client.
 *
 * @param string $event Event name (e.g. _jedomi_tool_executing).
 * @param mixed  $data  Data payload (will be JSON-encoded).
 */
function sse_send(string $event, $data): void {
    $payload = json_encode(
        array_merge(
            ['event' => $event],
            is_array($data) ? $data : ['data' => $data]
        ),
        JSON_UNESCAPED_UNICODE
    );
    echo "data: {$payload}\n\n";
    ob_flush();
    flush();
}

/**
 * Call the AI API with streaming (buffered) using native OpenAI function calling.
 *
 * Passes tool definitions to the model and returns either a structured text
 * response or a list of tool_calls to execute. Streaming is preserved for the
 * final text response phase (streamed to client via stream_sse_content).
 *
 * Gemma 4 via Ollama streams tool_call arguments across multiple SSE chunks;
 * this function accumulates them before returning.
 *
 * @param  string $model    Model name.
 * @param  array  $messages Conversation messages.
 * @param  array  $spt      SPT payload (unused directly — auth via sesskey).
 * @param  array  $tools    OpenAI-format tool definitions to pass to the model.
 * @return array            ['type'=>'text',       'content'=>'...']
 *                          ['type'=>'tool_calls', 'tool_calls'=>[...]]
 *                          ['type'=>'error',      'error'=>'...']
 */
function call_ai_api_buffer(string $model, array $messages, array $spt, array $tools = []): array {
    $apiUrl = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://192.168.1.28:8080', '/');
    $apiKey = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';

    $payload = [
        'model'      => $model,
        'messages'   => $messages,
        'stream'     => true,
        'max_tokens' => 16384,
    ];
    if (!empty($tools)) {
        $payload['tools']       = $tools;
        $payload['tool_choice'] = 'auto';
    }

    $buffer = '';
    $ch = curl_init($apiUrl . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer) {
            $buffer .= $data;
            return strlen($data);
        },
    ]);

    curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($buffer)) {
        $buf_preview = substr($buffer, 0, 500);
        error_log("[local_jedomi] AI API error: http=$httpCode curl='$curlError' preview="
            . json_encode($buf_preview, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
        return ['type' => 'error', 'error' => 'ai_api_error'];
    }

    $text_buf = '';
    $reason_buf = ''; // Gemma reasoning_content (thinking tokens — fallback only).
    $tool_calls_acc = []; // Indexed by tool_call index, accumulated across SSE chunks.
    $finish_reason = '';

    $lines = explode("\n", $buffer);
    foreach ($lines as $line) {
        $line = trim($line);
        if (!str_starts_with($line, 'data: ')) {
            continue;
        }
        $raw = trim(substr($line, 6));
        if ($raw === '[DONE]') {
            break;
        }
        $json = json_decode($raw, true);
        if (!$json) {
            continue;
        }

        $delta = $json['choices'][0]['delta'] ?? [];
        $finish_reason = $json['choices'][0]['finish_reason'] ?? $finish_reason;

        // Accumulate text content.
        if (isset($delta['content']) && $delta['content'] !== null) {
            $text_buf .= $delta['content'];
        }
        // Gemma reasoning_content (thinking tokens — fallback only).
        if (isset($delta['reasoning_content']) && $delta['reasoning_content'] !== null) {
            $reason_buf .= $delta['reasoning_content'];
        }
        // Accumulate tool_calls streamed across multiple delta chunks.
        // Ollama streams arguments token-by-token; some versions send an object directly.
        if (!empty($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tc) {
                $idx = $tc['index'] ?? 0;
                if (!isset($tool_calls_acc[$idx])) {
                    $tool_calls_acc[$idx] = [
                        'id'       => $tc['id'] ?? '',
                        'type'     => $tc['type'] ?? 'function',
                        'function' => ['name' => '', 'arguments' => ''],
                    ];
                }
                if (!empty($tc['id'])) {
                    $tool_calls_acc[$idx]['id'] = $tc['id'];
                }
                if (!empty($tc['function']['name'])) {
                    $tool_calls_acc[$idx]['function']['name'] = $tc['function']['name'];
                }
                if (isset($tc['function']['arguments'])) {
                    $arg = $tc['function']['arguments'];
                    // Some Ollama versions send arguments as a pre-decoded object.
                    if (is_array($arg)) {
                        $tool_calls_acc[$idx]['function']['arguments'] = json_encode($arg, JSON_UNESCAPED_UNICODE);
                    } else {
                        $tool_calls_acc[$idx]['function']['arguments'] .= $arg;
                    }
                }
            }
        }
    }

    // Tool calls detected — ensure every entry has a non-empty id.
    if (!empty($tool_calls_acc) || $finish_reason === 'tool_calls') {
        ksort($tool_calls_acc);
        $tool_calls = array_values($tool_calls_acc);
        foreach ($tool_calls as &$tc) {
            if (empty($tc['id'])) {
                $tc['id'] = 'call_' . substr(md5($tc['function']['name'] . microtime()), 0, 12);
            }
        }
        unset($tc);
        return ['type' => 'tool_calls', 'tool_calls' => $tool_calls];
    }

    // Text response: prefer content, fall back to reasoning (Gemma thinking tokens).
    $text = !empty($text_buf) ? $text_buf : $reason_buf;
    if (empty($text) && !empty($buffer)) {
        error_log("[local_jedomi] SSE parse warning: no content or tool_calls extracted. First 2KB: "
            . json_encode(substr($buffer, 0, 2048), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
    }
    return ['type' => 'text', 'content' => $text];
}

/**
 * Execute a tool by name, dispatching to the correct handler.
 * Accepts both canonical and gateway-transformed names.
 *
 * @param  string $name Tool name (canonical: search_tables).
 * @param  array  $args Tool arguments.
 * @param  string $role User role for permission checks.
 * @return string       JSON-encoded result.
 */
function execute_tool_by_name(string $name, array $args, string $role): string {
    switch ($name) {
        case 'describe_tables':
            return describe_tables_tool($args);
        case 'describe_table':
            return describe_table_tool($args);
        case 'search_tables':
            return search_tables_tool($args);
        case 'execute_sql':
            return execute_sql_tool($args, $role);
        case 'get_site_config':
            return get_site_config_tool($args, $role);
        case 'ws_search':
            return ws_search_tool($args, $role);
        case 'ws_describe':
            return ws_describe_tool($args, $role);
        case 'ws_execute':
            return ws_execute_tool($args, $role);
        default:
            return json_encode(['error' => "Unknown tool: {$name}"]);
    }
}

/**
 * Execute SQL tool.
 *
 * @param  array  $args Tool arguments (sql, params).
 * @param  string $role User role.
 * @return string       JSON-encoded result.
 */
function execute_sql_tool(array $args, string $role): string {
    if (!in_array($role, ['admin', 'manager', 'teacher'], true)) {
        return json_encode(['error' => 'permission_denied']);
    }
    $sql    = $args['sql'] ?? $args['query'] ?? '';  // Accept both 'sql' and 'query' keys.
    $params = $args['params'] ?? [];
    if (!is_array($params)) {
        $params = [$params];
    }
    try {
        $result = \local_jedomi\external\execute_sql::execute(
            $sql,
            $params,
            '', 0, '', true
        );
        return json_encode($result);
    } catch (\Exception $e) {
        // Include the real DB error message so the model can diagnose (e.g. "Unknown column", "Table not found").
        $realError = $e->getMessage();
        if ($e instanceof \dml_exception && !empty($e->debuginfo)) {
            $realError .= ' | DBG: ' . $e->debuginfo;
        }
        $prev = $e->getPrevious();
        if ($prev) {
            $realError .= ' | PREV: ' . $prev->getMessage();
            if ($prev instanceof \dml_exception && !empty($prev->debuginfo)) {
                $realError .= ' | PREV_DBG: ' . $prev->debuginfo;
            }
        }
        // Log the full error for debugging (model only sees the returned JSON).
        error_log("[local_jedomi] execute_sql_tool error: class=" . get_class($e) . " msg=" . json_encode($realError, JSON_UNESCAPED_UNICODE));
        return json_encode(['error' => $realError]);
    }
}

/**
 * Run the tool-calling loop using native OpenAI function calling.
 *
 * Passes tool definitions to the model via the API. The model returns structured
 * tool_calls (no text parsing). Tool results are re-injected as role=tool messages
 * (OpenAI standard). Final text response is streamed to the client via SSE.
 *
 * @param string $model    Model name.
 * @param array  $messages Conversation messages (passed by reference, mutated).
 * @param array  $spt      SPT payload.
 * @param string $role     User role.
 */
function run_tool_loop(string $model, array &$messages, array $spt, string $role): void {
    $query_history = [];    // Track successful SQL queries for cross-reference.
    $described_tables = []; // Cache describe_table/describe_tables results.
    $tools = local_jedomi_get_tool_definitions($role);

    for ($i = 0; $i < 10; $i++) {
        echo ": thinking\n\n";
        ob_flush();
        flush();

        $response = call_ai_api_buffer($model, $messages, $spt, $tools);

        if ($response['type'] === 'error') {
            echo "data: {\"error\":\"ai_api_error\"}\n\n";
            ob_flush();
            flush();
            return;
        }

        if ($response['type'] === 'text') {
            // No tool calls — stream the final response to the client.
            stream_sse_content($response['content']);
            return;
        }

        // type === 'tool_calls': execute each one and collect results.
        $tool_calls = $response['tool_calls'];

        // Add the assistant message with tool_calls to conversation history.
        $messages[] = [
            'role'       => 'assistant',
            'content'    => null,
            'tool_calls' => $tool_calls,
        ];

        foreach ($tool_calls as $tc) {
            $name = $tc['function']['name'];
            $tc_id = $tc['id'];
            $args_raw = $tc['function']['arguments'];
            $args = is_array($args_raw) ? $args_raw : (json_decode($args_raw, true) ?? []);

            // Notify the client that a tool is being executed.
            echo "data: " . json_encode([
                '_jedomi_tool_executing' => ['tool' => $name, 'args' => $args],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            ob_flush();
            flush();

            // ── Describe cache: intercept describe_tables / describe_table ─────────────
            $from_cache = false;
            $result = null;

            if ($name === 'describe_tables') {
                $tables = $args['tables'] ?? [];
                $missing = array_diff($tables, array_keys($described_tables));
                if (empty($missing) && !empty($tables)) {
                    $result = json_encode(['tables' => array_intersect_key($described_tables, array_flip($tables))]);
                    $from_cache = true;
                }
            } elseif ($name === 'describe_table') {
                $tbl = $args['tablename'] ?? $args['table_name'] ?? $args['table'] ?? '';
                if ($tbl !== '' && isset($described_tables[$tbl])) {
                    $result = json_encode($described_tables[$tbl]);
                    $from_cache = true;
                }
            }

            if ($result === null) {
                try {
                    $result = execute_tool_by_name($name, $args, $role);
                } catch (\Throwable $e) {
                    $result = json_encode([
                        'error' => 'tool_failed',
                        'tool'  => $name,
                        'detail' => get_class($e) . ': ' . $e->getMessage(),
                    ]);
                }
            }

            // Update schema cache.
            $result_decoded = json_decode($result, true);
            if ($name === 'describe_tables' && !$from_cache && !isset($result_decoded['error'])) {
                foreach ($result_decoded['tables'] ?? [] as $tbl_name => $tbl_schema) {
                    $described_tables[$tbl_name] = $tbl_schema;
                }
            }
            if ($name === 'describe_table' && !$from_cache && !isset($result_decoded['error'])) {
                $tbl = $args['tablename'] ?? $args['table_name'] ?? $args['table'] ?? '';
                if ($tbl !== '') {
                    $described_tables[$tbl] = $result_decoded;
                }
            }

            // Track successful SQL queries for context in subsequent calls.
            if ($name === 'execute_sql' && !isset($result_decoded['error'])) {
                $rows = $result_decoded['rows'] ?? [];
                $first_row = !empty($rows) ? ($rows[0]['json_row'] ?? null) : null;
                $first_decoded = $first_row ? json_decode($first_row, true) : null;
                $query_history[] = [
                    'sql'     => $args['sql'] ?? $args['query'] ?? '?',
                    'params'  => $args['params'] ?? [],
                    'rows'    => $result_decoded['row_count'] ?? count($rows),
                    'columns' => $first_decoded ? array_keys($first_decoded) : [],
                ];
            }

            // Build enriched result with query history + described tables context.
            $enriched_result = $result;
            $extra_notes = [];
            if (!empty($query_history)) {
                $refs = [];
                foreach ($query_history as $idx => $qh) {
                    $refs[] = "#{$idx}: {$qh['sql']} → {$qh['rows']} rows, cols=[" . implode(', ', $qh['columns']) . ']';
                }
                $extra_notes[] = "-- QUERY HISTORY (successful queries in this conversation) --\n"
                    . implode("\n", $refs);
            }
            if (!empty($described_tables)) {
                $extra_notes[] = "-- DESCRIBED TABLES (already known in this conversation) --\n"
                    . implode(', ', array_keys($described_tables));
            }
            if (!empty($extra_notes)) {
                $enriched_result .= "\n\n" . implode("\n\n", $extra_notes) . "\n---";
            }

            // Notify client if result came from cache.
            if ($from_cache) {
                echo "data: " . json_encode([
                    '_jedomi_tool_cache' => [
                        'tool'   => $name,
                        'tables' => $name === 'describe_tables'
                            ? ($args['tables'] ?? [])
                            : [$args['tablename'] ?? $args['table_name'] ?? $args['table'] ?? ''],
                    ],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                ob_flush();
                flush();
            }

            // Send result summary to client.
            echo "data: " . json_encode([
                '_jedomi_tool_result' => [
                    'tool'    => $name,
                    'summary' => ($from_cache ? '[cached] ' : '') . summarize_tool_result($name, $result),
                ],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            ob_flush();
            flush();

            // Inject tool result as role=tool message (OpenAI standard format).
            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $tc_id,
                'content'      => $enriched_result,
            ];
        }
    }

    stream_sse_content("Lo siento, no pude completar la operación después de varios intentos.");
}

/**
 * Summarize a tool result for the _jedomi_tool_result SSE event.
 */
function summarize_tool_result(string $name, string $result): string {
    $decoded = json_decode($result, true);
    if (!$decoded) {
        return 'error: invalid JSON response';
    }
    if (isset($decoded['error'])) {
        return 'error: ' . $decoded['error'];
    }
    return match ($name) {
        'execute_sql' => ($decoded['row_count'] ?? count($decoded['rows'] ?? [])) . ' rows returned',
        'describe_table' => 'table structure retrieved',
        'search_tables' => (is_array($decoded) ? count($decoded) : 0) . ' matching tables',
        'get_site_config' => 'config data retrieved',
        'ws_search'   => count($decoded['results'] ?? []) . ' functions found',
            'ws_describe' => 'function details retrieved',
        'ws_execute' => 'web service executed successfully',
        default => 'done',
    };
}

/**
 * Stream text content to the client as OpenAI-format SSE tokens.
 *
 * @param string $content The text to stream.
 */
function stream_sse_content(string $content): void {
    if (empty($content)) {
        echo "data: [DONE]\n\n";
        ob_flush();
        flush();
        return;
    }

    // Split into natural word-sized chunks for smooth streaming.
    // Larger chunks = fewer HTTP round-trips = faster perceived speed.
    $chunks = mb_str_split($content, 15);
    foreach ($chunks as $chunk) {
        echo "data: " . json_encode([
            'choices' => [['delta' => ['content' => $chunk]]],
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        ob_flush();
        flush();
        usleep(2000); // 2ms between chunks
    }
    echo "data: [DONE]\n\n";
    ob_flush();
    flush();
}

/**
 * Describe table tool.
 *
 * @param  array  $args Tool arguments (tablename).
 * @return string       JSON-encoded result.
 */
function describe_tables_tool(array $args): string {
    try {
        $tables = $args['tables'] ?? [];
        if (!is_array($tables) || empty($tables)) {
            return json_encode(['error' => 'tables must be a non-empty array']);
        }
        $results = [];
        foreach ($tables as $t) {
            $results[$t] = \local_jedomi\schema\table_descriptor::describe((string) $t);
        }
        return json_encode(['tables' => $results]);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function describe_table_tool(array $args): string {
    try {
        // Accept "tablename" (tool definition), "table_name" (gateway), and "table" (model output).
        $tablename = $args['tablename'] ?? $args['table_name'] ?? $args['table'] ?? '';
        $result = \local_jedomi\schema\table_descriptor::describe($tablename);
        return json_encode($result);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * Search tables tool.
 *
 * @param  array  $args Tool arguments (query).
 * @return string       JSON-encoded result.
 */
function search_tables_tool(array $args): string {
    try {
        $keyword = $args['query'] ?? $args['keyword'] ?? '';
        $result = \local_jedomi\schema\table_descriptor::search_tables($keyword);
        return json_encode($result);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * Get site config tool.
 *
 * @param  array  $args Tool arguments (keys).
 * @param  string $role The user's resolved role.
 * @return string       JSON-encoded result.
 */
function get_site_config_tool(array $args, string $role): string {
    if ($role !== 'admin') {
        return json_encode(['error' => 'permission_denied']);
    }
    try {
        // Accept both 'keys' (array) and 'key' (singular string from model).
        $cfgKeys = $args['keys'] ?? [];
        if (empty($cfgKeys) && isset($args['key'])) {
            $cfgKeys = is_array($args['key']) ? $args['key'] : [$args['key']];
        }
        $result = \local_jedomi\external\get_site_config::execute(
            $cfgKeys,
            '',     // hmac_signature
            0,      // hmac_timestamp
            '',     // payload_hash
            true    // skip_hmac
        );
        return json_encode($result);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

/**
 * WS Search tool — semantic keyword search over available web service functions.
 *
 * Returns the top-N most relevant functions with full compact documentation
 * (description, parameter structure, return structure), so the AI can determine
 * the correct function name and parameter shape before calling ws_execute.
 *
 * @param  array  $args Tool arguments (query, limit).
 * @param  string $role The user's resolved role.
 * @return string       JSON-encoded result.
 */
function ws_search_tool(array $args, string $role): string {
    try {
        $query = trim($args['query'] ?? $args['keyword'] ?? $args['filter'] ?? '');
        $limit = max(1, min(15, (int) ($args['limit'] ?? 6)));

        if (empty($query)) {
            return json_encode(['error' => 'query is required']);
        }

        $allowlist = local_jedomi_get_role_allowlist($role);

        if (empty($allowlist) && $role !== 'admin') {
            return json_encode(['results' => [], 'note' => 'No web services configured for your role']);
        }

        // For admin, pass empty allowlist so ws_indexer pre-filters from DB.
        $wsnames = $role === 'admin' ? [] : $allowlist;

        $results = \local_jedomi\schema\ws_indexer::search($query, $wsnames, $limit);

        return json_encode([
            'results' => $results,
            'count'   => count($results),
            'note'    => count($results) === 0
                ? 'No matching functions found. Try different keywords.'
                : 'Use the exact "name" field with ws_execute. Check "params" for required(*) parameters.',
        ]);
    } catch (\Throwable $e) {
        return json_encode([
            'error' => 'ws_search failed: ' . get_class($e) . ': ' . $e->getMessage(),
        ]);
    }
}

/**
 * WS Describe tool — returns full compact documentation for a specific WS function.
 *
 * @param  array  $args Tool arguments (wsname).
 * @param  string $role The user's resolved role.
 * @return string       JSON-encoded result.
 */
function ws_describe_tool(array $args, string $role): string {
    try {
        $wsname = trim($args['wsname'] ?? $args['name'] ?? $args['ws_name'] ?? '');

        if (empty($wsname)) {
            return json_encode(['error' => 'wsname is required']);
        }

        // Permission check: non-admin must have function in allowlist.
        if ($role !== 'admin') {
            $allowlist = local_jedomi_get_role_allowlist($role);
            if (!empty($allowlist) && !in_array($wsname, $allowlist, true)) {
                return json_encode(['error' => 'permission_denied: function not in allowlist']);
            }
        }

        return json_encode(\local_jedomi\schema\ws_indexer::describe($wsname));
    } catch (\Throwable $e) {
        return json_encode([
            'error' => 'ws_describe failed: ' . get_class($e) . ': ' . $e->getMessage(),
        ]);
    }
}

/**
 * WS Execute tool — executes a web service on behalf of the user.
 *
 * @param  array  $args Tool arguments (wsname, params).
 * @param  string $role The user's resolved role (unused — checked inside external class).
 * @return string       JSON-encoded result.
 */
function ws_execute_tool(array $args, string $role): string {
    try {
        $wsname = $args['wsname'] ?? $args['ws_name'] ?? '';
        $raw = $args['params'] ?? '{}';
        $params = is_array($raw) ? json_encode($raw) : $raw;

        $result = \local_jedomi\external\ws_execute::execute(
            $wsname,
            $params,
            '',     // hmac_signature — unused when skip_hmac=true
            0,      // hmac_timestamp
            '',     // payload_hash
            true    // skip_hmac
        );
        return json_encode($result);
    } catch (\moodle_exception $e) {
        $detail = $e->getMessage();
        if (method_exists($e, 'getDebugInfo') && $e->getDebugInfo()) {
            $detail .= ' | debug: ' . $e->getDebugInfo();
        }
        return json_encode([
            'error'    => 'ws_execute failed: ' . $detail,
            'wsname'   => $wsname ?? '',
            'params'   => $params ?? '{}',
            'errclass' => get_class($e),
        ]);
    } catch (\Throwable $e) {
        return json_encode([
            'error'    => 'ws_execute failed: ' . $e->getMessage(),
            'wsname'   => $wsname ?? '',
            'params'   => $params ?? '{}',
            'errclass' => get_class($e),
        ]);
    }
}

// =========================================================================
// Tool definition helpers (OpenAI function-calling format)
// =========================================================================

/**
 * Build the tools array based on the user's role.
 *
 * Access matrix:
 * - Student:          describe_table, search_tables
 * - Teacher/Manager:  + execute_sql
 * - Admin:            + get_site_config
 *
 * @param  string $role The user's resolved role.
 * @return array        List of tool definitions in OpenAI format.
 */
function build_tools_for_role(string $role): array {
    return local_jedomi_get_tool_definitions($role);
}
