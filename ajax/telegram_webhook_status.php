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
        'error' => get_string('telegram_token_not_configured', 'local_jedomi'),
    ]);
    exit;
}

$client = new api_client($token);
$info = $client->get_webhook_info();

if ($info === null) {
    echo json_encode([
        'ok'    => false,
        'error' => get_string('telegram_webhook_info_failed', 'local_jedomi'),
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
