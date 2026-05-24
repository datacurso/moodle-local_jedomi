<?php
/**
 * AJAX endpoint — returns current Telegram webhook status.
 *
 * Calls getWebhookInfo and returns the result as JSON.
 * Requires admin capability.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_login(null, false);
require_capability('moodle/site:config', context_system::instance());
require_sesskey();

use local_jedomi\telegram\manager;
use local_jedomi\telegram\api_client;

header('Content-Type: application/json');

$token = manager::get_bot_token();
if (!$token) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Bot token not configured',
    ]);
    exit;
}

$client = new api_client($token);
$info = $client->get_webhook_info();

if ($info === null) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Failed to call getWebhookInfo — check bot token and outbound HTTPS',
    ]);
    exit;
}

// Also return the stored error from our last registration attempt.
$info['stored_error'] = [
    'message' => manager::get_last_webhook_error(),
    'time'    => manager::get_last_webhook_error_time(),
];

$info['ok'] = true;
echo json_encode($info);
