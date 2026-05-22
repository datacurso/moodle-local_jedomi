<?php
/**
 * Jedomi CLI Chat — Debug tool for the full AI→Tool→Moodle pipeline.
 *
 * Uso: php chat_cli.php
 * Escribí preguntas, "debug on/off" para ver toolcalls, "exit" para salir.
 */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
global $DB, $CFG, $USER;
// En CLI no hay sesión — usamos admin directamente.
$admin = get_admin();
if ($admin) {
    $USER = $admin;
    \core\session\manager::set_user($admin);
}

echo "\n";
echo str_repeat("=", 60) . "\n";
echo "  JEDOMI CLI CHAT — DEBUG TOOL\n";
echo "  User: " . ($USER->id ?? 'none') . " | DB: {$CFG->dbname}\n";
echo str_repeat("=", 60) . "\n";
echo "  Comandos: debug on/off, tools on/off, exit\n\n";

// ── Config ────────────────────────────────────────────────────────────────
$API_URL = rtrim(get_config('local_jedomi', 'gateway_url') ?: 'http://192.168.1.28:8080', '/');
$API_KEY = get_config('local_jedomi', 'api_key') ?: '016e2b8b6aec7c18155842d6ef68894cc4f6b52f988eefae6bfd1cb06e549fd2';
$MODEL   = get_config('local_jedomi', 'model') ?: 'peg-gemma4';

$TOOL_NAME_MAP = [
    'searchtables'   => 'search_tables',  'search_tables'  => 'search_tables',
    'executesql'     => 'execute_sql',    'execute_sql'    => 'execute_sql',
    'describetable'  => 'describe_table', 'describe_table' => 'describe_table',
    'getsiteconfig'  => 'get_site_config','get_site_config'=> 'get_site_config',
    'wsdiscover'     => 'ws_discover',    'ws_discover'    => 'ws_discover',
    'wsexecute'      => 'ws_execute',     'ws_execute'     => 'ws_execute',
];

// ── State ─────────────────────────────────────────────────────────────────
$debug = true;  // Show raw tool calls by default
$showTools = true;
$messages = [];

// Build system message like chatproxy does
function build_system(): array {
    global $CFG;
    $content = "You are Jedomi, an AI assistant integrated into Moodle ({$CFG->sitename}).\n";
    $content .= "Your function is to help users answer questions using the available tools.\n\n";
    $content .= "AVAILABLE TOOLS:\n";
    $content .= "- execute_sql: Run SELECT queries on the Moodle database. Parameters: query (SQL with ? placeholders), params (array of values).\n";
    $content .= "- describe_table: Describe a table's columns. Parameter: tablename.\n";
    $content .= "- search_tables: Find tables by keyword. Parameter: keyword.\n";
    $content .= "- get_site_config: Get Moodle configuration values. Parameter: key.\n";
    $content .= "- ws_discover: List available web service functions.\n";
    $content .= "- ws_execute: Execute a web service function. Parameters: ws_name, params.\n\n";
    $content .= "ANTI-HALLUCINATION RULES:\n";
    $content .= "1. You MUST call a tool BEFORE answering ANY question about site data.\n";
    $content .= "2. If a tool returns 0 rows or an error, say you couldn't find the data.\n";
    $content .= "3. Only use execute_sql for SELECT queries.\n";
    return ['role' => 'system', 'content' => $content];
}

// ── Tool Functions ────────────────────────────────────────────────────────
function parse_toolcall(string $content): array|null {
    global $TOOL_NAME_MAP;
    foreach ([
        '/<\|tool_?call\|?>\s*call:(\w+)\s*(\{.*\})\s*<tool_?call\|>/s',
        '/<\|tool_?call\|?>\s*call:(\w+)\s*(\{.*\})\s*$/s',
        '/<\|tool_?call\|?>\s*call:(\w+)/s',
    ] as $pat) {
        if (preg_match($pat, $content, $m)) {
            $canonical = $TOOL_NAME_MAP[$m[1]] ?? $m[1];
            $args = [];
            if (isset($m[2]) && !in_array($m[2], ['{}', '[]'], true)) {
                $j = str_replace('<|"|>', '"', $m[2]);
                $args = json_decode($j, true);
                if ($args === null) {
                    $fixed = preg_replace('/(\w+)(?=\s*:)/', '"$1"', $j);
                    $args = json_decode($fixed, true);
                }
                if ($args === null) {
                    $fixed2 = str_replace("'", '"', $fixed ?? $j);
                    $args = json_decode($fixed2, true);
                }
                // Step 3: Quote bare word values (e.g. ws_name:core_course_get_courses)
                if ($args === null) {
                    $fixed3 = preg_replace('/:\s*([a-zA-Z_][\w:.\/-]*?)\s*([,}\]])/', ':"$1"$2', $fixed2);
                    $args = json_decode($fixed3, true);
                }
                if ($args === null) $args = ['raw' => $j];
            }
            return ['name' => $canonical, 'args' => is_array($args) ? $args : [], 'raw' => $m[0]];
        }
    }
    return null;
}

function search_tables_tool(array $args): string {
    global $DB, $CFG;
    $kw = $args['query'] ?? $args['keyword'] ?? '';
    if (!$kw) return json_encode(['error' => 'empty_keyword']);
    try {
        $rows = $DB->get_records_sql(
            "SELECT TABLE_NAME, TABLE_ROWS, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS SIZE_MB
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?",
            [$CFG->dbname, '%' . $kw . '%']
        );
        $tables = [];
        foreach ($rows as $r) {
            $tables[] = ['name' => $r->table_name, 'rows' => (int)$r->table_rows, 'size_mb' => (float)$r->size_mb];
        }
        return json_encode(['tables' => $tables, 'count' => count($tables)]);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function describe_table_tool(array $args): string {
    global $DB, $CFG;
    $tbl = $args['tablename'] ?? $args['table'] ?? '';
    if (!$tbl) return json_encode(['error' => 'empty_tablename']);
    try {
        $prefix = $CFG->prefix;
        if (str_starts_with($tbl, $prefix)) $tbl = substr($tbl, strlen($prefix));
        $cols = $DB->get_records_sql(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION",
            [$CFG->dbname, $prefix . $tbl]
        );
        $result = [];
        foreach ($cols as $c) {
            $result[] = [
                'name' => $c->column_name, 'type' => $c->column_type,
                'null' => $c->is_nullable, 'default' => $c->column_default,
            ];
        }
        return json_encode(['columns' => $result, 'count' => count($result)]);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

function execute_sql_tool(array $args): string {
    global $DB;
    $sql = $args['sql'] ?? $args['query'] ?? '';
    $params = $args['params'] ?? [];
    if (!is_array($params)) $params = [$params];
    if (!$sql) return json_encode(['error' => 'empty_sql']);
    $sql = rtrim(trim($sql), ';'); // Moodle DML no tolera punto y coma final.
    try {
        $rows = $DB->get_records_sql($sql, $params);
        $data = [];
        foreach ($rows as $row) {
            $data[] = (array)$row;
        }
        return json_encode(['rows' => $data, 'total' => count($data)]);
    } catch (\Exception $e) {
        // Get the REAL error, not Moodle's translation.
        $prev = $e->getPrevious();
        $realError = $prev ? $prev->getMessage() : $e->getMessage();
        $debug = '';
        if (method_exists($e, 'debuginfo')) {
            $debug = ' | ' . $e->debuginfo();
        }
        return json_encode(['error' => $realError . $debug . ' (SQL: ' . substr($sql, 0, 200) . ')']);
    }
}

function execute_tool(string $name, array $args): string {
    return match ($name) {
        'search_tables' => search_tables_tool($args),
        'describe_table' => describe_table_tool($args),
        'execute_sql' => execute_sql_tool($args),
        'get_site_config' => get_site_config_tool($args),
        'ws_discover' => ws_discover_tool($args),
        'ws_execute' => ws_execute_tool($args),
        default => json_encode(['error' => "unknown_tool: $name"]),
    };
}

function get_site_config_tool(array $args): string {
    global $CFG;
    $cfgKeys = $args['keys'] ?? [];
    if (empty($cfgKeys) && isset($args['key'])) {
        $cfgKeys = is_array($args['key']) ? $args['key'] : [$args['key']];
    }
    if (empty($cfgKeys)) return json_encode(['error' => 'empty_key']);
    $config = [];
    foreach ($cfgKeys as $key) {
        $val = get_config('core', $key);
        if ($val === false) $val = $CFG->$key ?? null;
        $config[$key] = $val;
    }
    return json_encode(['config' => $config, 'count' => count($config)]);
}

function ws_discover_tool(array $args): string {
    global $DB;
    $role = $args['role'] ?? 'admin';
    $allowlist = local_jedomi_get_role_allowlist($role);
    // Admin ve TODAS las funciones, incluso si no hay allowlist configurada.
    if (empty($allowlist)) {
        if ($role === 'admin') {
            $all = $DB->get_records('external_functions', null, 'component, name', 'name, component');
            $grouped = [];
            foreach ($all as $ws) {
                $entry = ['name' => $ws->name];
                try { $schema = local_jedomi_get_ws_schema($ws->name); } catch (\Throwable $e) { $schema = null; }
                if ($schema !== null) $entry['schema'] = $schema;
                $grouped[$ws->component][] = $entry;
            }
            $total = array_sum(array_map('count', $grouped));
            return json_encode(['discovery' => $grouped, 'total' => $total]);
        }
        return json_encode(['discovery' => [], 'total' => 0]);
    }
    $grouped = [];
    foreach ($allowlist as $wsname) {
        $ws = $DB->get_record('external_functions', ['name' => $wsname], 'name, component');
        if (!$ws) continue;
        $entry = ['name' => $wsname];
        try { $schema = local_jedomi_get_ws_schema($wsname); } catch (\Exception $e) { $schema = null; }
        if ($schema !== null) $entry['schema'] = $schema;
        $grouped[$ws->component][] = $entry;
    }
    $total = array_sum(array_map('count', $grouped));
    return json_encode(['discovery' => $grouped, 'total' => $total]);
}

function ws_execute_tool(array $args): string {
    try {
        $raw = $args['params'] ?? '{}';
        $params = is_array($raw) ? json_encode($raw) : $raw;
        $wsname = $args['wsname'] ?? $args['ws_name'] ?? '';
        $result = \local_jedomi\external\ws_execute::execute(
            $wsname,
            $params,
            '', 0, '', true
        );
        return json_encode($result);
    } catch (\Exception $e) {
        return json_encode(['error' => $e->getMessage()]);
    }
}

// ── AI Call ───────────────────────────────────────────────────────────────
function call_ai(array $messages, bool $stream = false): string|false {
    global $API_URL, $API_KEY, $MODEL;
    $payload = json_encode([
        'model' => $MODEL,
        'messages' => $messages,
        'stream' => $stream,
        'max_tokens' => 4096,
    ]);

    $ch = curl_init($API_URL . '/v1/chat/completions');
    $buf = '';
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $API_KEY],
        CURLOPT_RETURNTRANSFER => !$stream,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if ($stream) {
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buf) {
            $buf .= $data;
            $lines = explode("\n", $data);
            foreach ($lines as $l) {
                $l = trim($l);
                if (str_starts_with($l, 'data: ') && $l !== 'data: [DONE]') {
                    $j = json_decode(substr($l, 6), true);
                    $c = $j['choices'][0]['delta']['content'] ?? null;
                    if ($c !== null) echo $c;
                }
            }
            return strlen($data);
        });
        curl_exec($ch);
    } else {
        $buf = curl_exec($ch);
    }

    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($http !== 200 || empty($buf)) {
        echo "\n  ⚠️  API ERROR: HTTP {$http}" . ($error ? " - {$error}" : '') . "\n";
        return false;
    }

    if (!$stream) {
        $data = json_decode($buf, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    // For streaming: reconstruct full text from buffer
    $full = '';
    foreach (explode("\n", $buf) as $l) {
        $l = trim($l);
        if (!str_starts_with($l, 'data: ') || $l === 'data: [DONE]') continue;
        $j = json_decode(substr($l, 6), true);
        if (!$j) continue;
        $c = $j['choices'][0]['delta']['content'] ?? null;
        if ($c !== null) $full .= $c;
    }
    return $full;
}

// ── Main Loop ─────────────────────────────────────────────────────────────
$messages[] = build_system();

while (true) {
    echo "\n\e[1;33m>>> \e[0m";
    $input = readline();
    if ($input === false || $input === 'exit') break;
    $input = trim($input);
    if (empty($input)) continue;

    if ($input === 'debug on') { $debug = true; echo "  Debug ON\n"; continue; }
    if ($input === 'debug off') { $debug = false; echo "  Debug OFF\n"; continue; }
    if ($input === 'tools on') { $showTools = true; echo "  Tools ON\n"; continue; }
    if ($input === 'tools off') { $showTools = false; echo "  Tools OFF\n"; continue; }

    $messages[] = ['role' => 'user', 'content' => $input];

    $toolLoop = 0;
    while ($toolLoop < 5) {
        $toolLoop++;
        echo "\n  \e[2m⏳ AI pensando... (turno {$toolLoop}/5)\e[0m\n";

        // Non-streaming for tool detection
        $response = call_ai($messages, false);

        if ($response === false) {
            echo "  \e[31m✗ Error en llamada AI\e[0m\n";
            break;
        }

        $toolCall = parse_toolcall($response);

        if (!$toolCall) {
            // Final response
            echo "\n  \e[1;32m🤖:\e[0m {$response}\n";
            if ($debug) echo "  \e[2m(no tool call detected)\e[0m\n";
            $messages[] = ['role' => 'assistant', 'content' => $response];
            break;
        }

        if ($debug) {
            echo "  \e[36m🔧 Tool call: {$toolCall['name']}(" .
                 json_encode($toolCall['args'], JSON_UNESCAPED_UNICODE) . ")\e[0m\n";
            echo "  \e[2m  Raw: {$toolCall['raw']}\e[0m\n";
        }

        if (!$showTools) {
            echo "  \e[33m⚠️  Tools disabled. Would call: {$toolCall['name']}\e[0m\n";
            $messages[] = ['role' => 'assistant', 'content' => "No tools available."];
            break;
        }

        // Execute tool
        echo "  \e[35m⚡ Ejecutando...\e[0m\n";
        $result = execute_tool($toolCall['name'], $toolCall['args']);
        $resultPreview = json_decode($result, true);
        $isError = isset($resultPreview['error']);
        $count = $resultPreview['count'] ?? $resultPreview['total'] ?? count($resultPreview['rows'] ?? []) ?? '?';

        echo "  \e[2m  Resultado: " . ($isError ? "\e[31mERROR: {$resultPreview['error']}\e[0m" : "\e[32mOK ({$count} items)\e[0m") . "\e[0m\n";
        if ($debug) echo "  \e[2m  " . substr($result, 0, 200) . "\e[0m\n";

        // Re-inject
        $messages[] = ['role' => 'assistant', 'content' => $toolCall['raw']];
        $messages[] = ['role' => 'user', 'content' => "FUNCTION RESULT for {$toolCall['name']}:\n{$result}"];
    }

    if ($toolLoop >= 5) {
        echo "  \e[31m✗ Máximo de iteraciones alcanzado\e[0m\n";
    }
}

echo "\n¡Chau!\n";
