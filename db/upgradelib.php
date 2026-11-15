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
 * Upgrade helper functions for local_jedomi.
 *
 * @package   local_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove the companion API prohibit that versions before 2026111000 set by default.
 *
 * Those versions prohibited 'local/jedomi:companion_api' for the coursecreator, editingteacher, teacher, student,
 * guest, user and frontpage archetypes. Every logged-in user holds the authenticated user role, so that prohibit
 * removed the capability from managers too. Only a CAP_PROHIBIT at system context on roles of those archetypes is
 * removed; any other value (allow or prevent) set by an administrator is kept, and no role is granted anything.
 *
 * @return int Number of roles whose prohibit was removed.
 */
function local_jedomi_remove_companion_api_prohibits(): int {
    global $DB;

    $capability = 'local/jedomi:companion_api';
    $archetypes = ['coursecreator', 'editingteacher', 'teacher', 'student', 'guest', 'user', 'frontpage'];
    $systemcontext = context_system::instance();

    [$insql, $params] = $DB->get_in_or_equal($archetypes, SQL_PARAMS_NAMED);
    $params['capability'] = $capability;
    $params['contextid'] = $systemcontext->id;
    $params['prohibit'] = CAP_PROHIBIT;
    $roleids = $DB->get_fieldset_sql(
        "SELECT DISTINCT r.id
           FROM {role} r
           JOIN {role_capabilities} rc ON rc.roleid = r.id
          WHERE r.archetype $insql
            AND rc.capability = :capability
            AND rc.contextid = :contextid
            AND rc.permission = :prohibit",
        $params
    );

    foreach ($roleids as $roleid) {
        unassign_capability($capability, (int) $roleid, $systemcontext->id);
    }
    if ($roleids) {
        $systemcontext->mark_dirty();
    }

    return count($roleids);
}

/**
 * Leave every site on a real AI service: the retired "none" value, a missing choice or any other value becomes the
 * Datacurso service; the custom service is kept. Jedomi is switched off with "Enabled" of the provider instead.
 *
 * @return void
 */
function local_jedomi_upgrade_ai_service(): void {
    if (get_config('local_jedomi', 'ai_service') !== 'custom') {
        set_config('ai_service', 'datacurso', 'local_jedomi');
    }
}

/**
 * Keep one rating for repeated clicks on the same answer, from before ratings had a message id.
 *
 * Ratings of the same user, chat session and answer excerpt are the same answer rated several times: only the newest
 * one stays. Ratings without an excerpt (the "Feedback only" privacy level) cannot be told apart and are all kept.
 *
 * @return int Number of ratings removed.
 */
function local_jedomi_deduplicate_feedback(): int {
    global $DB;

    $seen = [];
    $duplicates = [];
    $records = $DB->get_recordset_select(
        'local_jedomi_feedback',
        'messageid IS NULL',
        [],
        'id DESC',
        'id, userid, sessionid, messagetext'
    );
    foreach ($records as $record) {
        $text = trim((string) $record->messagetext);
        if ($text === '') {
            continue;
        }
        $key = $record->userid . '|' . (string) $record->sessionid . '|' . sha1($text);
        if (isset($seen[$key])) {
            $duplicates[] = (int) $record->id;
        } else {
            $seen[$key] = true;
        }
    }
    $records->close();

    foreach (array_chunk($duplicates, 500) as $chunk) {
        $DB->delete_records_list('local_jedomi_feedback', 'id', $chunk);
    }
    return count($duplicates);
}

/**
 * Rotate the Telegram webhook secret and register the webhook again with it as secret_token (JEDOMI-SEC-004).
 *
 * Earlier versions sent the secret in the webhook address, where access logs and proxies could keep it, and the
 * webhook no longer accepts it there. Without a bot token the secret is only rotated. With a bot token setWebhook is
 * called alone (it replaces the previous webhook at once) with a new secret, stored only once Telegram accepted it,
 * and with a short timeout so the upgrade never waits long (RES-001). When Telegram cannot be reached the update still
 * finishes: the webhook refuses every request, the Integrations tab warns, a line is printed and an adhoc task retries
 * the registration and notifies the site administrators.
 *
 * @param \local_jedomi\telegram\api_client|null $client Client of the stored token; built from it when null.
 * @return bool Whether the webhook is ready: registered again, or no bot token is configured.
 */
function local_jedomi_upgrade_telegram_webhook(?\local_jedomi\telegram\api_client $client = null): bool {
    $token = \local_jedomi\telegram\manager::get_bot_token();
    if ($token === null) {
        \local_jedomi\telegram\manager::replace_webhook_secret();
        \local_jedomi\telegram\manager::clear_webhook_registration_need();
        return true;
    }
    $client = $client ?? new \local_jedomi\telegram\api_client($token);
    $client->set_timeout(\local_jedomi\task\register_telegram_webhook::TIMEOUT);
    if (\local_jedomi\telegram\manager::register_webhook_with_new_secret($client)) {
        \local_jedomi\telegram\manager::clear_webhook_registration_need();
        return true;
    }
    \local_jedomi\telegram\manager::require_webhook_registration();
    \local_jedomi\task\register_telegram_webhook::queue();
    mtrace('local_jedomi: the Telegram webhook could not be registered with its new secret, so the bot does not '
        . 'receive messages. A background task retries it and notifies the site administrators; you can also save '
        . 'the bot token again in the Integrations tab of Jedomi.');
    return false;
}

/**
 * Add the approved writes of the agents and the agent of the held actions (JEDOMI-SEC-001).
 *
 * Every existing agent is left without approved writes: from now on all its writes wait for the approval of its
 * creator until the creator approves some of them in the agent form.
 *
 * @return void
 */
function local_jedomi_upgrade_agent_write_approvals(): void {
    global $DB;
    $dbman = $DB->get_manager();

    $agents = new xmldb_table('local_jedomi_agents');
    $approved = new xmldb_field('approvedwrites', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lasterror');
    if (!$dbman->field_exists($agents, $approved)) {
        $dbman->add_field($agents, $approved);
    }
    $DB->set_field('local_jedomi_agents', 'approvedwrites', null);

    $pending = new xmldb_table('local_jedomi_pending_actions');
    $agentid = new xmldb_field('agentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid');
    if (!$dbman->field_exists($pending, $agentid)) {
        $dbman->add_field($pending, $agentid);
    }
}
