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
 * User profile page for Telegram account linking.
 *
 * Shows the current link status, generates one-time link codes and gives the instructions.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_jedomi\local\service\telegram_link_service;
use local_jedomi\output\telegram_profile;

require_login();

$userid = optional_param('userid', $USER->id, PARAM_INT);
if ($userid != $USER->id && !has_capability('moodle/user:update', context_system::instance())) {
    throw new \moodle_exception('nopermissions', 'error', '', 'view other user Telegram settings');
}

$user = \core_user::get_user($userid);
if (!$user || $user->deleted) {
    throw new \moodle_exception('invaliduser', 'error');
}

$pageurl = new moodle_url('/local/jedomi/telegram_profile.php', ['userid' => $userid]);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('telegram_link_title', 'local_jedomi'));
$PAGE->set_heading(fullname($user));

$bot = telegram_link_service::get_bot_status();
if (!$bot['available'] && !is_siteadmin()) {
    // Regular users see nothing until the bot is configured.
    redirect(new moodle_url('/user/profile.php', ['id' => $userid]));
}
$roleok = local_jedomi_is_telegram_role_allowed();

$code = '';
$codeexpiry = 0;
$action = optional_param('action', '', PARAM_ALPHA);
if ($bot['available'] && $roleok && $action !== '') {
    require_sesskey();
    if ($action === 'generate') {
        $code = \local_jedomi\telegram\manager::generate_link_code($userid);
        $codeexpiry = time() + 600;
    } else if ($action === 'unlink') {
        telegram_link_service::unlink($userid);
        redirect($pageurl, get_string('telegram_msg_unlinked', 'local_jedomi'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$link = telegram_link_service::get_confirmed_link($userid);
if (!$bot['available']) {
    $state = telegram_profile::STATE_NOT_CONFIGURED;
} else if (!$roleok) {
    $state = telegram_profile::STATE_ROLE_BLOCKED;
} else if ($link) {
    $state = telegram_profile::STATE_LINKED;
} else {
    $state = telegram_profile::STATE_NOT_LINKED;
}

$renderer = $PAGE->get_renderer('local_jedomi');
echo $OUTPUT->header();
echo $renderer->render(new telegram_profile($state, $bot['username'], $bot['firstname'], $link, $code, $codeexpiry, $pageurl));
echo $OUTPUT->footer();
