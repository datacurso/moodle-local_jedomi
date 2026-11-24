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
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_datacurso\local\plugin_settings;
use local_jedomi\form\telegram_pin_form;
use local_jedomi\local\service\telegram_link_service;
use local_jedomi\local\service\telegram_pin_service;
use local_jedomi\output\telegram_profile;

require_login();
plugin_settings::require_enabled('local_jedomi');

$userid = optional_param('userid', $USER->id, PARAM_INT);
if ($userid != $USER->id) {
    // A link code lets its holder act with the account in Telegram: only the user manages their own link.
    throw new \moodle_exception('nopermissions', 'error', '', 'manage the Telegram link of another user');
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
if (!$bot['available'] && !is_siteadmin() && !telegram_link_service::get_confirmed_link($userid)) {
    // Regular users see nothing until the bot is configured.
    redirect(new moodle_url('/user/profile.php', ['id' => $userid]));
}
$roleok = local_jedomi_is_telegram_role_allowed();

$code = '';
$codeexpiry = 0;
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'unlink') {
    // Any user can unlink, even when their role can no longer use the bot.
    require_sesskey();
    telegram_link_service::unlink($userid);
    redirect($pageurl, get_string('telegram_msg_unlinked', 'local_jedomi'), null, \core\output\notification::NOTIFY_SUCCESS);
}
$pinform = null;
if (telegram_link_service::get_confirmed_link($userid) && telegram_pin_service::is_required($userid)) {
    // Decision 7: the PIN of an administrator or manager, set only by the user and stored only as a hash.
    $pinform = new telegram_pin_form($pageurl);
    if ($pindata = $pinform->get_data()) {
        if (telegram_pin_service::set_pin($userid, (string) $pindata->pin)) {
            redirect($pageurl, get_string('telegram_pin_saved', 'local_jedomi'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($pageurl, telegram_pin_form::format_description(), null, \core\output\notification::NOTIFY_ERROR);
    }
}
if ($bot['available'] && $roleok && $action === 'generate') {
    require_sesskey();
    $code = \local_jedomi\telegram\manager::generate_link_code($userid);
    $codeexpiry = time() + 600;
}

$link = telegram_link_service::get_confirmed_link($userid);
if ($link) {
    // A linked user always sees the link and can remove it.
    $state = telegram_profile::STATE_LINKED;
} else if (!$bot['available']) {
    $state = telegram_profile::STATE_NOT_CONFIGURED;
} else if (!$roleok) {
    $state = telegram_profile::STATE_ROLE_BLOCKED;
} else {
    $state = telegram_profile::STATE_NOT_LINKED;
}

$renderer = $PAGE->get_renderer('local_jedomi');
echo $OUTPUT->header();
$pinrequired = $link !== null && $pinform !== null;
echo $renderer->render(new telegram_profile(
    $state,
    $bot['username'],
    $bot['firstname'],
    $link,
    $code,
    $codeexpiry,
    $pageurl,
    $pinrequired,
    $pinrequired && telegram_pin_service::has_pin($userid),
    $pinrequired ? $pinform->render() : ''
));
echo $OUTPUT->footer();
