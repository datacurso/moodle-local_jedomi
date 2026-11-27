<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * User profile page for WhatsApp linking.
 *
 * Asks for the consent, generates the one-time link code with its wa.me link, and shows the link and opt-out state.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use aiprovider_datacurso\local\plugin_settings;
use local_jedomi\form\whatsapp_consent_form;
use local_jedomi\local\service\whatsapp_link_service;
use local_jedomi\output\whatsapp_profile;
use local_jedomi\whatsapp\link_codes;
use local_jedomi\whatsapp\manager;

require_login();
plugin_settings::require_enabled('local_jedomi');

$userid = optional_param('userid', $USER->id, PARAM_INT);
if ($userid != $USER->id) {
    // A link code lets its holder act with the account in WhatsApp: only the user manages their own link.
    throw new \moodle_exception('nopermissions', 'error', '', 'manage the WhatsApp link of another user');
}

$user = \core_user::get_user($userid);
if (!$user || $user->deleted) {
    throw new \moodle_exception('invaliduser', 'error');
}

$pageurl = new moodle_url('/local/jedomi/whatsapp_profile.php', ['userid' => $userid]);
$PAGE->set_context(context_user::instance($userid));
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('whatsapp_link_title', 'local_jedomi'));
$PAGE->set_heading(fullname($user));

$link = $DB->get_record(whatsapp_link_service::LINKS_TABLE, ['userid' => $userid]) ?: null;
$available = manager::is_enabled() && manager::is_configured() && manager::get_display_number() !== '';
if (!$available && !is_siteadmin() && $link === null) {
    // Regular users see nothing until the channel is configured.
    redirect(new moodle_url('/user/profile.php', ['id' => $userid]));
}
$roleok = local_jedomi_channel_role_allowed_for('whatsapp', $userid);

if (optional_param('action', '', PARAM_ALPHA) === 'unlink') {
    // Any user can unlink, even when their role can no longer use the channel.
    require_sesskey();
    if ($link !== null) {
        whatsapp_link_service::unlink($userid, (int) $USER->id);
    }
    redirect(
        $pageurl,
        get_string('whatsapp_unlinked_notice', 'local_jedomi'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$code = '';
$codeexpiry = 0;
$consentformhtml = '';
if ($link === null && $available && $roleok) {
    // Without the consent, with at least one category, no code is generated.
    $form = new whatsapp_consent_form($pageurl);
    $now = \core\di::get(\core\clock::class)->time();
    $consent = $form->get_consent($now);
    if ($consent !== null) {
        $code = link_codes::generate($userid, $consent);
        $codeexpiry = $now + link_codes::TTL;
    } else {
        $consentformhtml = $form->render();
    }
}

if ($link !== null) {
    // A linked user always sees the link and can remove it.
    $state = whatsapp_profile::STATE_LINKED;
} else if (!$available) {
    $state = whatsapp_profile::STATE_NOT_CONFIGURED;
} else if (!$roleok) {
    $state = whatsapp_profile::STATE_ROLE_BLOCKED;
} else {
    $state = whatsapp_profile::STATE_NOT_LINKED;
}

echo $OUTPUT->header();
echo $PAGE->get_renderer('local_jedomi')->render(new whatsapp_profile(
    $state,
    manager::get_display_number(),
    $link,
    $code,
    $codeexpiry,
    $pageurl,
    $consentformhtml
));
echo $OUTPUT->footer();
