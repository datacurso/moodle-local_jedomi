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
 * AJAX endpoint — drops pending Telegram updates via setWebhook.
 *
 * Re-registers the webhook with drop_pending_updates=true. This clears the
 * Telegram update queue so stale messages aren't processed, and keeps the
 * webhook URL registered.
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

header('Content-Type: application/json');

$token = manager::get_bot_token();
if (!$token) {
    echo json_encode([
        'ok'    => false,
        'error' => get_string('telegram_token_not_configured', 'local_jedomi'),
    ]);
    exit;
}

// The setWebhook call drops the queue itself and keeps the URL registered, so the webhook is never deleted. While the
// registration is pending the secret is rotated (FIX-001).
$result = manager::drop_pending_updates();

if ($result) {
    echo json_encode([
        'ok'      => true,
        'message' => get_string('drop_pending_success', 'local_jedomi'),
    ]);
} else {
    echo json_encode([
        'ok'    => false,
        'error' => get_string('telegram_drop_pending_failed', 'local_jedomi'),
    ]);
}
