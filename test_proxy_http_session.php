<?php
/**
 * Test the REAL proxy via HTTP with a valid Moodle session.
 * Creates a PHP session for admin and uses it for the request.
 */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
global $DB, $CFG, $USER, $SESSION;

$admin = get_admin();
$USER = $admin;
\core\session\manager::set_user($admin);

// ── Get a valid session from the DB ──
// First try to find an existing admin session
$session = $DB->get_record_sql(
    "SELECT s.* FROM {sessions} s JOIN {user} u ON u.id = s.userid WHERE u.deleted = 0 ORDER BY s.timemodified DESC LIMIT 1"
);

if (!$session) {
    // Create a new session record
    $sid = md5(uniqid('test', true)) . md5(uniqid('test2', true));
    $record = new \stdClass();
    $record->state = 0;
    $record->sid = $sid;
    $record->sessdata = null;
    $record->userid = $admin->id;
    $record->timecreated = time();
    $record->timemodified = time();
    $record->firstip = '127.0.0.1';
    $record->lastip = '127.0.0.1';
    $DB->insert_record('sessions', $record);
    $session = $DB->get_record('sessions', ['sid' => $sid]);
}

echo "Session ID: {$session->sid}\n";
echo "User: {$admin->username} (id={$admin->id})\n";

// ── Generate the request payload ──
$spt = local_jedomi_generate_spt('admin');
$sesskey = sesskey();

$payload = json_encode([
    'model' => get_config('local_jedomi', 'model') ?: 'jedomi-e4b',
    'messages' => [
        ['role' => 'assistant', 'content' => '¡Hola! Soy Jedomi, tu asistente IA de Moodle.'],
        ['role' => 'user', 'content' => 'cuantos cursos hay en total'],
    ],
    'spt' => $spt,
    'sesskey' => $sesskey,
    'context' => ['location' => 'dashboard'],
], JSON_UNESCAPED_UNICODE);

echo "Sesskey: {$sesskey}\n";
echo "Payload size: " . strlen($payload) . "\n\n";

// ── Make HTTP request to proxy ──
echo "=== HTTP Request to chatproxy.php ===\n";
$url = $CFG->wwwroot . '/local/jedomi/chatproxy.php';
echo "URL: {$url}\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Cookie: MoodleSession=' . $session->sid . '; MoodleSessionTest' . $CFG->sessioncookie . '=1',
    ],
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HEADERFUNCTION => function($ch, $header) {
        $h = trim($header);
        if (str_contains($h, 'HTTP/') || str_contains($h, 'Content-Type') || str_contains($h, 'Location') || empty($h)) {
            echo "  HDR: {$h}\n";
        }
        return strlen($header);
    },
    CURLOPT_WRITEFUNCTION => function($ch, $data) {
        $d = trim($data);
        if (!empty($d)) {
            echo "  {$d}\n";
        }
        return strlen($data);
    },
]);

curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "\nHTTP Status: {$http}\n";
if ($error) echo "CURL Error: {$error}\n";

// Cleanup test session
if (!isset($session->id) || !$DB->record_exists('sessions', ['id' => $session->id])) {
    $DB->delete_records('sessions', ['sid' => $session->sid]);
}
