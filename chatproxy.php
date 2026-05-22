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

/**
 * Map tool function names from the gateway's format to canonical PHP names.
 *
 * The gateway transforms model output: search_tables → searchtables.
 * We need to match both variants.
 *
 * NOTA: usamos define() en vez de const para evitar un bug de OPcache
 * con revalidate_freq + file_update_protection que impide que las
 * constantes compile-time se recacheen correctamente tras ediciones.
 */
define('TOOL_NAME_MAP', [
    'searchtables'   => 'search_tables',
    'search_tables'  => 'search_tables',
    'executesql'     => 'execute_sql',
    'execute_sql'    => 'execute_sql',
    'wsdiscover'     => 'ws_discover',
    'ws_discover'    => 'ws_discover',
    'wsexecute'      => 'ws_execute',
    'ws_execute'     => 'ws_execute',
    'describetables' => 'describe_tables',
    'describe_tables' => 'describe_tables',
    'describetable'  => 'describe_table',
    'describe_table' => 'describe_table',
    'getsiteconfig'  => 'get_site_config',
    'get_site_config'=> 'get_site_config',
]);

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

    $content .= "AVAILABLE TOOLS — use ONLY these tool names, exactly as written:\n";
    $content .= "1. ws_discover — List available web service functions for your role. Parameters: filter (keyword, optional), page (1-based, optional), per_page (default 50, optional).\n";
    $content .= "2. ws_execute — Execute a web service function. Parameters: ws_name (function name), params (object of parameters).\n";
    $content .= "3. execute_sql — Run SELECT queries on the {$dblabel} database. Parameters: sql (SQL with ? placeholders, REQUIRED), params (array of values, optional). Use {tablename} syntax (e.g. {course}).\n";
    $content .= "4. describe_table — Describe ONE table's columns, types and keys. Parameter: tablename (table name, REQUIRED).\n";
    $content .= "5. describe_tables — Describe MULTIPLE tables in ONE call (fast, batch). Parameter: tables (array of table names, REQUIRED). Use this instead of calling describe_table 5 times.\n";
    $content .= "6. search_tables — Find tables by keyword. Parameter: query (keyword, REQUIRED).\n";
    $content .= "7. get_site_config — Get Moodle configuration values. Parameter: keys (array of config key names).\n\n";
    $content .= "Tool call format: <|tool_call|>call:FUNCTION_NAME{parameter: \"value\"}<tool_call|>\n";
    $content .= "IMPORTANT: Use the EXACT parameter names listed above. For example: call:describe_table{tablename: \"course\"} NOT call:describe_table{table: \"course\"}.\n\n";

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
    $content .= "  Path A (WS-first): ws_discover → ws_execute — safest, use when a WS exists for the question.\n";
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
 * Call the AI API with streaming, but buffer the raw SSE into a complete
 * response buffer instead of forwarding to the client.
 *
 * @param  string $model    Model name.
 * @param  array  $messages Conversation messages.
 * @param  array  $spt      SPT payload.
 * @return string           The complete assistant response text.
 */
function call_ai_api_buffer(string $model, array $messages, array $spt): string {
    // Call the AI model DIRECTLY via OpenAI-compatible API.
    // NO gateway — the model API is at 192.168.1.28:8080.
    $apiUrl  = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://192.168.1.28:8080', '/');
    $apiKey  = get_config('local_jedomi', 'api_key') ?: 'jedomi-local-key';

    // Standard OpenAI chat completions payload.
    // The model does NOT support function_calling — tools are described in system prompt text.
    $payload = [
        'model'      => $model,
        'messages'   => $messages,
        'stream'     => true,
        'max_tokens' => 16384,  // Room for reasoning + response. Model needs to think.
    ];

    $buffer  = '';  // Full raw SSE text
    $textBuf = '';  // Extracted delta.content (actual response)
    $reasonBuf = ''; // Fallback: Gemma reasoning_content (thinking tokens, discarded when content exists)

    $ch = curl_init($apiUrl . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 180,   // 3 min for slow reasoning models
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$buffer) {
            $buffer .= $data;
            return strlen($data);
        },
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($buffer)) {
        $bufLen = strlen($buffer);
        $bufPreview = substr($buffer, 0, 500);
        error_log("[local_jedomi] AI API error: http=$httpCode curl='$curlError' bufLen=$bufLen empty=" . (empty($buffer) ? 'yes' : 'no') . " preview=" . json_encode($bufPreview, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
        return '';
    }

    // Parse SSE buffer — extract content tokens from streaming response.
    // Gemma-4 sends reasoning_content (thinking in English) BEFORE delta.content (response in Spanish).
    // We return content if present; fall back to reasoning only when no content exists.
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

        // delta.content = actual response (always prefer this).
        if (isset($json['choices'][0]['delta']['content']) && $json['choices'][0]['delta']['content'] !== null) {
            $textBuf .= $json['choices'][0]['delta']['content'];
            continue;
        }
        // reasoning_content = thinking tokens (Gemma). Collected as fallback only.
        if (isset($json['choices'][0]['delta']['reasoning_content'])
            && $json['choices'][0]['delta']['reasoning_content'] !== null) {
            $reasonBuf .= $json['choices'][0]['delta']['reasoning_content'];
            continue;
        }
    }

    // Return content if available (discards reasoning — keeps response clean).
    if (!empty($textBuf)) {
        return $textBuf;
    }

    // Fallback: no content found, use reasoning (better than empty response).
    if (!empty($reasonBuf)) {
        error_log("[local_jedomi] SSE fallback: returning reasoning_content (no delta.content found, "
            . strlen($reasonBuf) . " chars)");
        return $reasonBuf;
    }

    // If nothing extracted but buffer was valid, log the raw SSE (first 2KB) for diagnosis.
    if (!empty($buffer)) {
        error_log("[local_jedomi] SSE parse warning: buffer populated but no content or reasoning extracted. First 2KB: "
            . json_encode(substr($buffer, 0, 2048), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
    }

    return '';
}

/**
 * Parse an AI response for <|toolcall|> markers.
 *
 * The model uses text markers. Observed formats (ALL variants):
 *
 * Native (model directo):
 *   <|tool_call>call:search_tables{keyword: 'course'}<tool_call|>
 *   <|tool_call>call:execute_sql{query: "SELECT ..."}<tool_call|>
 *
 * Gateway-transformed:
 *   <|toolcall>call:searchtables{keyword:<|"|>course<|"|>}<tool_call|>
 *   <|toolcall>call:getsiteconfig{key:"sitename"}<toolcall|>
 *
 * @param  string $content The raw response text.
 * @return array|null      ['name' (canonical), 'args' (array), 'raw' (full marker)] or null.
 */
function parse_toolcall_marker(string $content): array|null {
    // Unified regex: handles ALL observed variants of <|toolcall|>call:func{args}<tool_call|>
    $patterns = [
        // Matches both native format (tool_call with underscore)
        // and gateway format (toolcall without underscore).
        '/<\|tool_?call\|?>\s*call:(\w+)\s*(\{.*\})\s*<tool_?call\|>/s',
        '/<\|tool_?call\|?>\s*call:(\w+)/s',
    ];

    foreach ($patterns as $pat) {
        if (preg_match($pat, $content, $m)) {
            $rawName = $m[1];
            $canonical = TOOL_NAME_MAP[$rawName] ?? $rawName;
            $args = [];

            if (isset($m[2]) && $m[2] !== '{}' && $m[2] !== '[]') {
                $argsJson = str_replace('<|"|>', '"', $m[2]);
                $args = json_decode($argsJson, true);
                if ($args === null) {
                    $args = parse_relaxed_json($argsJson);
                }
                if ($args === null) {
                    $args = ['raw' => $argsJson];
                }
            }

            return [
                'name' => $canonical,
                'args' => is_array($args) ? $args : [],
                'raw'  => $m[0],
            ];
        }
    }
    return null;
}

/**
 * Parse JSON with relaxed syntax (keys without quotes, single quotes).
 *
 * @param  string $jsonString The malformed JSON.
 * @return array|null         Decoded array or null on failure.
 */
function parse_relaxed_json(string $jsonString): array|null {
    // Step 1: Add quotes to unquoted keys.
    $fixed = preg_replace('/(\w+)(?=\s*:)/', '"$1"', $jsonString);

    // Try decoding with double-quoted keys (handles SQL with single-quoted strings).
    $result = json_decode($fixed, true);
    if (is_array($result)) {
        return $result;
    }

    // Step 2: Replace single-quoted VALUES with double-quoted values (for {keyword: 'course'} format).
    // Uses regex instead of blind str_replace to avoid breaking SQL strings containing single quotes.
    $fixed2 = preg_replace('/:\s*\'(.*?)\'\s*([,}\]])/', ':"$1"$2', $fixed);
    $result = json_decode($fixed2, true);
    if (is_array($result)) {
        return $result;
    }

    // Step 3: Quote bare word values (e.g. ws_name:core_course_get_courses).
    $fixed3 = preg_replace('/:\s*([a-zA-Z_][\w:.\/-]*?)\s*([,}\]])/', ':"$1"$2', $fixed2);
    $result = json_decode($fixed3, true);
    if (is_array($result)) {
        return $result;
    }

    // Last resort: extract key-value pairs manually.
    preg_match_all('/(\w+)\s*:\s*(?:"([^"]*)"|\'([^\']*)\'|(\w+))/', $jsonString, $matches, PREG_SET_ORDER);
    if (!empty($matches)) {
        $result = [];
        foreach ($matches as $m) {
            $result[$m[1]] = $m[2] ?: $m[3] ?: $m[4] ?: '';
        }
        return $result;
    }

    return null;
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
    // Normalize just in case.
    $name = TOOL_NAME_MAP[$name] ?? $name;

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
        case 'ws_discover':
            return ws_discover_tool($args, $role);
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
        if (method_exists($e, 'debuginfo') && !empty($e->debuginfo)) {
            $realError .= ' | DBG: ' . $e->debuginfo;
        }
        $prev = $e->getPrevious();
        if ($prev) {
            $realError .= ' | PREV: ' . $prev->getMessage();
            if (method_exists($prev, 'debuginfo') && !empty($prev->debuginfo)) {
                $realError .= ' | PREV_DBG: ' . $prev->debuginfo;
            }
        }
        // Log the full error for debugging (model only sees the returned JSON).
        error_log("[local_jedomi] execute_sql_tool error: class=" . get_class($e) . " msg=" . json_encode($realError, JSON_UNESCAPED_UNICODE));
        return json_encode(['error' => $realError]);
    }
}

/**
 * Run the tool-calling loop with <|toolcall> marker parsing.
 *
 * The model generates <|toolcall>call:function{...}<tool_call|> markers as text.
 * We: 1) Call AI (streaming, buffered), 2) Parse markers, 3) Execute tools,
 * 4) Re-inject results, 5) Loop until no more tools, 6) Stream final response.
 *
 * @param string $model    Model name.
 * @param array  $messages Conversation messages (passed by reference, mutated).
 * @param array  $spt      SPT payload.
 * @param string $role     User role.
 */
function run_tool_loop(string $model, array &$messages, array $spt, string $role): void {
    $queryHistory = [];   // Track successful SQL queries for cross-reference.
    $describedTables = []; // Cache describe_tables results (table name → schema).

    for ($i = 0; $i < 10; $i++) {
        // Send a keepalive so the client knows we're thinking.
        echo ": thinking\n\n";
        ob_flush();
        flush();

        // Call AI with streaming but buffer the response.
        $responseText = call_ai_api_buffer($model, $messages, $spt);

        if (empty($responseText)) {
            echo "data: {\"error\":\"ai_api_error\"}\n\n";
            ob_flush();
            flush();
            return;
        }

        // Parse for tool call markers.
        $toolCall = parse_toolcall_marker($responseText);

        if (!$toolCall) {
            // No tool calls — stream the final response to client.
            stream_sse_content($responseText);
            return;
        }

        // Notify client that we're executing a tool.
        echo "data: " . json_encode([
            '_jedomi_tool_executing' => [
                'tool' => $toolCall['name'],
                'args' => $toolCall['args'],
            ],
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        ob_flush();
        flush();

        // ── Describe cache: intercept describe_tables/describe_table ──────────
        $fromCache = false;
        $intercepted = false;

        if ($toolCall['name'] === 'describe_tables') {
            $tables = $toolCall['args']['tables'] ?? [];
            $missing = array_diff($tables, array_keys($describedTables));
            if (empty($missing) && !empty($tables)) {
                // All tables already cached → return cached.
                $result = json_encode(['tables' => array_intersect_key($describedTables, array_flip($tables))]);
                $fromCache = true;
                $intercepted = true;
            }
        } elseif ($toolCall['name'] === 'describe_table') {
            $tbl = $toolCall['args']['tablename'] ?? $toolCall['args']['table_name'] ?? $toolCall['args']['table'] ?? '';
            if ($tbl !== '' && isset($describedTables[$tbl])) {
                $result = json_encode($describedTables[$tbl]);
                $fromCache = true;
                $intercepted = true;
            }
        }

        if (!$intercepted) {
            // Execute the tool normally.
            $result = execute_tool_by_name($toolCall['name'], $toolCall['args'], $role);
        }

        // Cache describe results for future iterations.
        $resultDecoded = json_decode($result, true);
        if ($toolCall['name'] === 'describe_tables' && !$fromCache && !isset($resultDecoded['error'])) {
            foreach ($resultDecoded['tables'] ?? [] as $tblName => $tblSchema) {
                $describedTables[$tblName] = $tblSchema;
            }
        }
        if ($toolCall['name'] === 'describe_table' && !$fromCache && !isset($resultDecoded['error'])) {
            $tbl = $toolCall['args']['tablename'] ?? $toolCall['args']['table_name'] ?? $toolCall['args']['table'] ?? '';
            if ($tbl !== '') {
                $describedTables[$tbl] = $resultDecoded;
            }
        }

        // If successful SQL query, save to history for cross-reference by subsequent calls.
        if ($toolCall['name'] === 'execute_sql' && !isset($resultDecoded['error'])) {
            // execute_sql returns: {rows: [{json_row: "..."}], row_count: N, truncated, execution_ms}.
            $rows = $resultDecoded['rows'] ?? [];
            $firstRow = !empty($rows) ? ($rows[0]['json_row'] ?? null) : null;
            $firstRowDecoded = $firstRow ? json_decode($firstRow, true) : null;
            $queryHistory[] = [
                'sql'     => $toolCall['args']['sql'] ?? $toolCall['args']['query'] ?? '?',
                'params'  => $toolCall['args']['params'] ?? [],
                'rows'    => $resultDecoded['row_count'] ?? count($rows),
                'columns' => $firstRowDecoded ? array_keys($firstRowDecoded) : [],
            ];
        }

        // Build enriched result with query history + described tables context.
        $enrichedResult = $result;
        $extraNotes = [];
        if (!empty($queryHistory)) {
            $refs = [];
            foreach ($queryHistory as $idx => $qh) {
                $refs[] = "#{$idx}: {$qh['sql']} → {$qh['rows']} rows, cols=[" . implode(', ', $qh['columns']) . ']';
            }
            $extraNotes[] = "-- QUERY HISTORY (successful queries in this conversation) --\n"
                . implode("\n", $refs);
        }
        if (!empty($describedTables)) {
            $extraNotes[] = "-- DESCRIBED TABLES (already known in this conversation) --\n"
                . implode(', ', array_keys($describedTables));
        }
        if (!empty($extraNotes)) {
            $enrichedResult .= "\n\n" . implode("\n\n", $extraNotes) . "\n---";
        }

        // Notify client if result came from cache.
        if ($fromCache) {
            echo "data: " . json_encode([
                '_jedomi_tool_cache' => [
                    'tool'   => $toolCall['name'],
                    'tables' => $toolCall['name'] === 'describe_tables'
                        ? ($toolCall['args']['tables'] ?? [])
                        : [$toolCall['args']['tablename'] ?? $toolCall['args']['table_name'] ?? $toolCall['args']['table'] ?? ''],
                ],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            ob_flush();
            flush();
        }

        // Send result summary to client.
        echo "data: " . json_encode([
            '_jedomi_tool_result' => [
                'tool'    => $toolCall['name'],
                'summary' => ($fromCache ? '[cached] ' : '') . summarize_tool_result($toolCall['name'], $result),
            ],
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        ob_flush();
        flush();

        // Add tool call + result to conversation.
        $messages[] = [
            'role'    => 'assistant',
            'content' => $toolCall['raw'],
        ];
        $messages[] = [
            'role'    => 'user',
            'content' => "FUNCTION RESULT for {$toolCall['name']}:\n{$enrichedResult}",
        ];
    }

    // Max iterations.
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
        'ws_discover' => ($decoded['total'] ?? 0) . ' web services discovered',
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
 * Classify a web service function as read (Lectura) or write (Escritura)
 * based on naming conventions.
 *
 * @param  string $wsname The web service function name.
 * @return string         'Lectura', 'Escritura', or empty if unknown.
 */
function classify_ws_access(string $wsname): string {
    // Extract the action part after the component prefix.
    // e.g. "core_course_get_courses" → action is after "core_course_"
    $parts = explode('_', $wsname);
    $action = end($parts);

    // Read keywords (lectura).
    if (preg_match('/^(get|view|search|list|is|check|validate|export|download|fetch|retrieve|count|exists)$/i', $action)) {
        return 'Lectura';
    }

    // Also check the full name for compound read patterns.
    if (preg_match('/_(get|view|search|list|check|export|download|fetch|retrieve)_/i', $wsname)) {
        return 'Lectura';
    }

    // Write keywords (escritura).
    if (preg_match('/^(create|update|delete|add|remove|set|edit|save|insert|upload|import|purge|reset|restore|reorder|toggle|approve|reject|block|unblock|lock|unlock|archive|trash|publish|unpublish|submit|enrol|unenrol|assign|unassign|mark|flag|clear|move|copy|duplicate|switch|change|revoke|grant)$/i', $action)) {
        return 'Escritura';
    }
    if (preg_match('/_(create|update|delete|add|remove|save|insert|upload|import|purge|enrol|assign|grant|revoke)_/i', $wsname)) {
        return 'Escritura';
    }

    return '';
}

/**
 * Build a categorized entry for a web service function.
 *
 * @param  string      $wsname The web service function name.
 * @param  string|null $component The component name (optional).
 * @return array
 */
function build_ws_entry(string $wsname, ?string $component = null): array {
    $entry = [
        'name' => $wsname,
        'mode' => classify_ws_access($wsname),
    ];
    try { $schema = local_jedomi_get_ws_schema($wsname); } catch (\Exception $e) { $schema = null; }
    if ($schema !== null) {
        $entry['schema'] = $schema;
    }
    if ($component !== null) {
        $entry['component'] = $component;
    }
    return $entry;
}

/**
 * WS Discover tool — returns available web services for the current role.
 *
 * Accepts optional filter, page, and per_page parameters for large result sets.
 *
 * @param  array  $args Tool arguments (filter, page, per_page).
 * @param  string $role The user's resolved role.
 * @return string       JSON-encoded result.
 */
function ws_discover_tool(array $args, string $role): string {
    global $DB;

    $filter   = trim($args['filter'] ?? $args['keyword'] ?? '');
    $page     = max(1, (int) ($args['page'] ?? 1));
    $perPage  = max(5, min(200, (int) ($args['per_page'] ?? $args['limit'] ?? 50)));

    $allowlist = local_jedomi_get_role_allowlist($role);
    $all = [];

    if (empty($allowlist)) {
        if ($role !== 'admin') {
            return json_encode([
                'discovery' => [],
                'total' => 0,
                'note' => get_string('ws_no_services', 'local_jedomi'),
            ]);
        }
        // Admin ve TODAS las funciones.
        $records = $DB->get_records('external_functions', null, 'component, name', 'name, component');
        foreach ($records as $ws) {
            $all[] = build_ws_entry($ws->name, $ws->component);
        }
    } else {
        foreach ($allowlist as $wsname) {
            $ws = $DB->get_record('external_functions', ['name' => $wsname], 'name, component');
            if (!$ws) { continue; }
            $all[] = build_ws_entry($wsname, $ws->component);
        }
    }

    // Apply keyword filter (matches name and component).
    if ($filter !== '') {
        $filterLower = strtolower($filter);
        $all = array_values(array_filter($all, function ($e) use ($filterLower) {
            return strpos(strtolower($e['name']), $filterLower) !== false
                || strpos(strtolower($e['component'] ?? ''), $filterLower) !== false;
        }));
    }

    $total = count($all);

    // Paginate.
    $offset = ($page - 1) * $perPage;
    $pageEntries = array_slice($all, $offset, $perPage);

    // Group by component for the response.
    $grouped = [];
    $nonAdminGrouped = !empty($allowlist);
    foreach ($pageEntries as $entry) {
        $comp = $entry['component'] ?? 'other';
        unset($entry['component']);
        $grouped[$comp][] = $entry;
    }

    return json_encode([
        'discovery'    => $grouped,
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => (int) ceil($total / $perPage),
    ]);
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
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
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
