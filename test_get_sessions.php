<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
global $DB;

// Show recent sessions
$sessions = $DB->get_records_sql(
    "SELECT s.sid, s.userid, u.username, s.timemodified, 
            LENGTH(COALESCE(s.sessdata,'')) as datalen
     FROM {sessions} s 
     JOIN {user} u ON u.id = s.userid 
     ORDER BY s.timemodified DESC LIMIT 5"
);

echo "Recent sessions:\n";
foreach ($sessions as $s) {
    echo "  sid={$s->sid} user={$s->username}({$s->userid}) data=" . $s->datalen . "bytes time=" . date('H:i:s', $s->timemodified) . "\n";
}

// The cookie name for session
global $CFG;
echo "\nSession cookie name: MoodleSession" . $CFG->sessioncookie . "\n";

// Generate a valid sesskey for admin
$admin = get_admin();
$USER = $admin;
\core\session\manager::set_user($admin);
echo "Current sesskey: " . sesskey() . "\n";

// Show PHPSESSID from environment
echo "PHPSESSID: " . (session_id() ?: 'none') . "\n";
