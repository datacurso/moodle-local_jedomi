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

namespace local_jedomi\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_jedomi\agent\agent_manager;
use local_jedomi\local\service\feedback_service;
use local_jedomi\local\service\pending_action_service;
use local_jedomi\local\service\telegram_link_service;

/**
 * Privacy provider for local_jedomi: ratings, pending actions, Telegram data, agents and the data sent outside Moodle.
 *
 * Every record is personal data of the user it belongs to and lives in that user's context. Telegram records are
 * keyed by chat and belong to the user linked to that chat.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(feedback_service::TABLE, [
            'userid' => 'privacy:metadata:local_jedomi_feedback:userid',
            'rating' => 'privacy:metadata:local_jedomi_feedback:rating',
            'sessionid' => 'privacy:metadata:local_jedomi_feedback:sessionid',
            'messagetext' => 'privacy:metadata:local_jedomi_feedback:messagetext',
            'timecreated' => 'privacy:metadata:local_jedomi_feedback:timecreated',
        ], 'privacy:metadata:local_jedomi_feedback');

        $collection->add_database_table(pending_action_service::TABLE, [
            'userid' => 'privacy:metadata:local_jedomi_pending_actions:userid',
            'tool' => 'privacy:metadata:local_jedomi_pending_actions:tool',
            'argsjson' => 'privacy:metadata:local_jedomi_pending_actions:argsjson',
            'severity' => 'privacy:metadata:local_jedomi_pending_actions:severity',
            'timecreated' => 'privacy:metadata:local_jedomi_pending_actions:timecreated',
        ], 'privacy:metadata:local_jedomi_pending_actions');

        $collection->add_database_table('local_jedomi_telegram_links', [
            'userid' => 'privacy:metadata:local_jedomi_telegram_links:userid',
            'chatid' => 'privacy:metadata:local_jedomi_telegram_links:chatid',
            'username' => 'privacy:metadata:local_jedomi_telegram_links:username',
            'timecreated' => 'privacy:metadata:local_jedomi_telegram_links:timecreated',
        ], 'privacy:metadata:local_jedomi_telegram_links');

        $collection->add_database_table('local_jedomi_telegram_sessions', [
            'chatid' => 'privacy:metadata:local_jedomi_telegram_sessions:chatid',
            'messages' => 'privacy:metadata:local_jedomi_telegram_sessions:messages',
            'lastactivity' => 'privacy:metadata:local_jedomi_telegram_sessions:lastactivity',
        ], 'privacy:metadata:local_jedomi_telegram_sessions');

        $collection->add_database_table('local_jedomi_telegram_rate_limit', [
            'chatid' => 'privacy:metadata:local_jedomi_telegram_rate_limit:chatid',
            'msgcount' => 'privacy:metadata:local_jedomi_telegram_rate_limit:msgcount',
            'blockeduntil' => 'privacy:metadata:local_jedomi_telegram_rate_limit:blockeduntil',
        ], 'privacy:metadata:local_jedomi_telegram_rate_limit');

        $collection->add_database_table('local_jedomi_telegram_pending_actions', [
            'chatid' => 'privacy:metadata:local_jedomi_telegram_pending_actions:chatid',
            'actionjson' => 'privacy:metadata:local_jedomi_telegram_pending_actions:actionjson',
        ], 'privacy:metadata:local_jedomi_telegram_pending_actions');

        $collection->add_database_table('local_jedomi_agents', [
            'userid' => 'privacy:metadata:local_jedomi_agents:userid',
            'name' => 'privacy:metadata:local_jedomi_agents:name',
            'prompt' => 'privacy:metadata:local_jedomi_agents:prompt',
            'schedule_cron' => 'privacy:metadata:local_jedomi_agents:schedule_cron',
            'timecreated' => 'privacy:metadata:local_jedomi_agents:timecreated',
        ], 'privacy:metadata:local_jedomi_agents');

        $collection->add_database_table('local_jedomi_agent_cache', [
            'agentid' => 'privacy:metadata:local_jedomi_agent_cache:agentid',
            'result_json' => 'privacy:metadata:local_jedomi_agent_cache:result_json',
            'timecreated' => 'privacy:metadata:local_jedomi_agent_cache:timecreated',
        ], 'privacy:metadata:local_jedomi_agent_cache');

        $collection->add_database_table('local_jedomi_query_log', [
            'agentid' => 'privacy:metadata:local_jedomi_query_log:agentid',
            'query' => 'privacy:metadata:local_jedomi_query_log:query',
            'timecreated' => 'privacy:metadata:local_jedomi_query_log:timecreated',
        ], 'privacy:metadata:local_jedomi_query_log');

        $collection->add_user_preference(
            'local_jedomi_grade_notifications',
            'privacy:metadata:preference:local_jedomi_grade_notifications'
        );

        $collection->add_external_location_link('jedomi_ai_service', [
            'userid' => 'privacy:metadata:jedomi_ai_service:userid',
            'messages' => 'privacy:metadata:jedomi_ai_service:messages',
            'lang' => 'privacy:metadata:jedomi_ai_service:lang',
            'site_id' => 'privacy:metadata:jedomi_ai_service:site_id',
            'site_url' => 'privacy:metadata:jedomi_ai_service:site_url',
        ], 'privacy:metadata:jedomi_ai_service');

        $collection->add_external_location_link('jedomi_speech_service', [
            'userid' => 'privacy:metadata:jedomi_speech_service:userid',
            'text' => 'privacy:metadata:jedomi_speech_service:text',
            'lang' => 'privacy:metadata:jedomi_speech_service:lang',
            'site_id' => 'privacy:metadata:jedomi_speech_service:site_id',
            'site_url' => 'privacy:metadata:jedomi_speech_service:site_url',
        ], 'privacy:metadata:jedomi_speech_service');

        $collection->add_external_location_link('telegram', [
            'chatid' => 'privacy:metadata:telegram:chatid',
            'messages' => 'privacy:metadata:telegram:messages',
        ], 'privacy:metadata:telegram');

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $userid = $context instanceof \context_user ? (int) $context->instanceid : 0;
        if ($userid && self::user_has_data($userid)) {
            $userlist->add_user($userid);
        }
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || (int) $context->instanceid !== $userid) {
                continue;
            }
            $writer = writer::with_context($context);

            $feedback = array_map(static fn(\stdClass $record): array => [
                'rating' => $record->rating,
                'sessionid' => $record->sessionid,
                'messagetext' => $record->messagetext,
                'timecreated' => transform::datetime($record->timecreated),
            ], feedback_service::get_for_user($userid));
            if (!empty($feedback)) {
                $writer->export_data([get_string('privacy_feedback', 'local_jedomi')], (object) ['feedback' => $feedback]);
            }

            $actions = array_map(static fn(\stdClass $record): array => [
                'tool' => $record->tool,
                'arguments' => $record->argsjson,
                'severity' => $record->severity,
                'timecreated' => transform::datetime($record->timecreated),
            ], pending_action_service::get_for_user($userid));
            if (!empty($actions)) {
                $writer->export_data([get_string('privacy_pending_actions', 'local_jedomi')], (object) ['actions' => $actions]);
            }

            $telegram = self::export_telegram($userid);
            if ($telegram !== null) {
                $writer->export_data([get_string('privacy_telegram', 'local_jedomi')], $telegram);
            }

            $agents = self::export_agents($userid);
            if (!empty($agents)) {
                $writer->export_data([get_string('privacy_agents', 'local_jedomi')], (object) ['agents' => $agents]);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_user) {
            self::delete_user_data((int) $context->instanceid);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                self::delete_user_data($userid);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        $userid = (int) $context->instanceid;
        if (in_array($userid, array_map('intval', $userlist->get_userids()), true)) {
            self::delete_user_data($userid);
        }
    }

    #[\Override]
    public static function export_user_preferences(int $userid) {
        $notified = get_user_preferences('local_jedomi_grade_notifications', null, $userid);
        if ($notified !== null) {
            writer::export_user_preference(
                'local_jedomi',
                'local_jedomi_grade_notifications',
                $notified,
                get_string('privacy:metadata:preference:local_jedomi_grade_notifications', 'local_jedomi')
            );
        }
    }

    /**
     * Whether the plugin keeps any personal data of a user.
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function user_has_data(int $userid): bool {
        global $DB;
        return feedback_service::user_has_feedback($userid)
            || pending_action_service::user_has_actions($userid)
            || $DB->record_exists('local_jedomi_telegram_links', ['userid' => $userid])
            || $DB->record_exists('local_jedomi_agents', ['userid' => $userid]);
    }

    /**
     * Telegram data of a user: the link and, for each linked chat, the conversation, the message limit and the
     * actions waiting for confirmation.
     *
     * @param int $userid User id.
     * @return \stdClass|null Null when the user has no Telegram link.
     */
    private static function export_telegram(int $userid): ?\stdClass {
        global $DB;
        $links = $DB->get_records('local_jedomi_telegram_links', ['userid' => $userid], 'id ASC');
        if (empty($links)) {
            return null;
        }
        $chats = [];
        foreach ($links as $link) {
            $chatid = (string) $link->chatid;
            $sessions = $DB->get_records_list('local_jedomi_telegram_sessions', 'chatid', [$chatid, 'tool_loop_' . $chatid]);
            $limit = $DB->get_record('local_jedomi_telegram_rate_limit', ['chatid' => $chatid]);
            $actions = $DB->get_records('local_jedomi_telegram_pending_actions', ['chatid' => $chatid], 'id ASC');
            $chats[] = [
                'chatid' => $chatid,
                'username' => $link->username,
                'confirmed' => transform::yesno((bool) $link->confirmed),
                'timecreated' => transform::datetime($link->timecreated),
                'conversations' => array_values(array_map(static fn(\stdClass $session): array => [
                    'messages' => $session->messages,
                    'lastactivity' => transform::datetime($session->lastactivity),
                ], $sessions)),
                'messagelimit' => $limit ? [
                    'msgcount' => (int) $limit->msgcount,
                    'windowstart' => transform::datetime($limit->windowstart),
                    'blockeduntil' => $limit->blockeduntil ? transform::datetime($limit->blockeduntil) : null,
                ] : null,
                'pendingactions' => array_values(array_map(static fn(\stdClass $action): array => [
                    'action' => $action->actionjson,
                    'timecreated' => transform::datetime($action->timecreated),
                ], $actions)),
            ];
        }
        return (object) ['chats' => $chats];
    }

    /**
     * Agents of a user with their results and the queries they ran.
     *
     * @param int $userid User id.
     * @return array[]
     */
    private static function export_agents(int $userid): array {
        global $DB;
        $agents = [];
        foreach ($DB->get_records('local_jedomi_agents', ['userid' => $userid], 'id ASC') as $agent) {
            $results = $DB->get_records('local_jedomi_agent_cache', ['agentid' => $agent->id], 'timecreated ASC, id ASC');
            $queries = $DB->get_records('local_jedomi_query_log', ['agentid' => $agent->id], 'timecreated ASC, id ASC');
            $agents[] = [
                'name' => $agent->name,
                'prompt' => $agent->prompt,
                'schedule' => $agent->schedule_cron,
                'contexttype' => $agent->context_type,
                'enabled' => transform::yesno((bool) $agent->enabled),
                'lastrun' => $agent->lastrun ? transform::datetime($agent->lastrun) : null,
                'timecreated' => transform::datetime($agent->timecreated),
                'results' => array_values(array_map(static fn(\stdClass $result): array => [
                    'result' => $result->result_json,
                    'timecreated' => transform::datetime($result->timecreated),
                ], $results)),
                'queries' => array_values(array_map(static fn(\stdClass $query): array => [
                    'query' => $query->query,
                    'timecreated' => transform::datetime($query->timecreated),
                ], $queries)),
            ];
        }
        return $agents;
    }

    /**
     * Delete every record of a user: ratings, pending actions, Telegram data and agents with their results and queries.
     *
     * @param int $userid User id.
     * @return void
     */
    private static function delete_user_data(int $userid): void {
        global $DB;
        feedback_service::delete_for_users([$userid]);
        pending_action_service::delete_for_users([$userid]);
        telegram_link_service::unlink($userid);
        $agentids = $DB->get_fieldset_select('local_jedomi_agents', 'id', 'userid = ?', [$userid]);
        foreach ($agentids as $agentid) {
            $DB->delete_records('local_jedomi_query_log', ['agentid' => $agentid]);
            agent_manager::delete_agent((int) $agentid);
        }
    }
}
