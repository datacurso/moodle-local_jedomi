<?php
/**
 * AJAX endpoint — drops pending Telegram updates via deleteWebhook.
 *
 * Calls deleteWebhook with drop_pending_updates=true. This clears the
 * Telegram update queue so stale messages aren't processed when the
 * webhook comes back online.
 *
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
$result = $client->delete_webhook();

if ($result) {
    echo json_encode([
        'ok'      => true,
        'message' => 'Pending updates dropped successfully.',
    ]);
} else {
    echo json_encode([
        'ok'    => false,
        'error' => 'Failed to drop pending updates — check Telegram API response.',
    ]);
}
