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
use local_jedomi\local\service\whatsapp_link_service;

/**
 * Privacy provider for local_jedomi: ratings, pending actions, Telegram and WhatsApp data, agents and the data sent
 * outside Moodle.
 *
 * Every record is personal data of the user it belongs to and lives in that user's context. Telegram records are
 * keyed by chat and belong to the user linked to that chat; WhatsApp records are keyed by the BSUID of Meta (the chat
 * key is 'wa_' plus the BSUID) and belong to the user linked to that BSUID.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
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
            'messageid' => 'privacy:metadata:local_jedomi_feedback:messageid',
            'feedbackcomment' => 'privacy:metadata:local_jedomi_feedback:feedbackcomment',
            'timemodified' => 'privacy:metadata:local_jedomi_feedback:timemodified',
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
            'lastused' => 'privacy:metadata:local_jedomi_telegram_links:lastused',
            'pinhash' => 'privacy:metadata:local_jedomi_telegram_links:pinhash',
            'pinfailures' => 'privacy:metadata:local_jedomi_telegram_links:pinfailures',
            'pinlockeduntil' => 'privacy:metadata:local_jedomi_telegram_links:pinlockeduntil',
            'pinverifiedat' => 'privacy:metadata:local_jedomi_telegram_links:pinverifiedat',
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

        $collection->add_database_table(whatsapp_link_service::LINKS_TABLE, [
            'userid' => 'privacy:metadata:local_jedomi_whatsapp_links:userid',
            'bsuid' => 'privacy:metadata:local_jedomi_whatsapp_links:bsuid',
            'waid' => 'privacy:metadata:local_jedomi_whatsapp_links:waid',
            'phone' => 'privacy:metadata:local_jedomi_whatsapp_links:phone',
            'confirmed' => 'privacy:metadata:local_jedomi_whatsapp_links:confirmed',
            'consentat' => 'privacy:metadata:local_jedomi_whatsapp_links:consentat',
            'consentversion' => 'privacy:metadata:local_jedomi_whatsapp_links:consentversion',
            'consentcategories' => 'privacy:metadata:local_jedomi_whatsapp_links:consentcategories',
            'optedout' => 'privacy:metadata:local_jedomi_whatsapp_links:optedout',
            'optoutat' => 'privacy:metadata:local_jedomi_whatsapp_links:optoutat',
            'lastinbound' => 'privacy:metadata:local_jedomi_whatsapp_links:lastinbound',
            'lastused' => 'privacy:metadata:local_jedomi_whatsapp_links:lastused',
            'lastproactivesent' => 'privacy:metadata:local_jedomi_whatsapp_links:lastproactivesent',
            'timecreated' => 'privacy:metadata:local_jedomi_whatsapp_links:timecreated',
        ], 'privacy:metadata:local_jedomi_whatsapp_links');

        $collection->add_database_table(whatsapp_link_service::SESSIONS_TABLE, [
            'chatid' => 'privacy:metadata:local_jedomi_whatsapp_sessions:chatid',
            'messages' => 'privacy:metadata:local_jedomi_whatsapp_sessions:messages',
            'usercontext' => 'privacy:metadata:local_jedomi_whatsapp_sessions:usercontext',
            'iteration' => 'privacy:metadata:local_jedomi_whatsapp_sessions:iteration',
            'lastactivity' => 'privacy:metadata:local_jedomi_whatsapp_sessions:lastactivity',
        ], 'privacy:metadata:local_jedomi_whatsapp_sessions');

        $collection->add_database_table(whatsapp_link_service::PENDING_TABLE, [
            'callbackid' => 'privacy:metadata:local_jedomi_whatsapp_pending:callbackid',
            'chatid' => 'privacy:metadata:local_jedomi_whatsapp_pending:chatid',
            'actionjson' => 'privacy:metadata:local_jedomi_whatsapp_pending:actionjson',
            'courseid' => 'privacy:metadata:local_jedomi_whatsapp_pending:courseid',
            'timecreated' => 'privacy:metadata:local_jedomi_whatsapp_pending:timecreated',
        ], 'privacy:metadata:local_jedomi_whatsapp_pending');

        $collection->add_database_table(whatsapp_link_service::RATE_TABLE, [
            'chatid' => 'privacy:metadata:local_jedomi_whatsapp_rate:chatid',
            'windowstart' => 'privacy:metadata:local_jedomi_whatsapp_rate:windowstart',
            'msgcount' => 'privacy:metadata:local_jedomi_whatsapp_rate:msgcount',
            'blockeduntil' => 'privacy:metadata:local_jedomi_whatsapp_rate:blockeduntil',
        ], 'privacy:metadata:local_jedomi_whatsapp_rate');

        $collection->add_database_table(whatsapp_link_service::INBOX_TABLE, [
            'wamid' => 'privacy:metadata:local_jedomi_whatsapp_inbox:wamid',
            'bsuid' => 'privacy:metadata:local_jedomi_whatsapp_inbox:bsuid',
            'payload' => 'privacy:metadata:local_jedomi_whatsapp_inbox:payload',
            'status' => 'privacy:metadata:local_jedomi_whatsapp_inbox:status',
            'attempts' => 'privacy:metadata:local_jedomi_whatsapp_inbox:attempts',
            'timecreated' => 'privacy:metadata:local_jedomi_whatsapp_inbox:timecreated',
            'timeprocessed' => 'privacy:metadata:local_jedomi_whatsapp_inbox:timeprocessed',
        ], 'privacy:metadata:local_jedomi_whatsapp_inbox');

        $collection->add_database_table(whatsapp_link_service::USAGE_TABLE, [
            'userid' => 'privacy:metadata:local_jedomi_whatsapp_usage:userid',
            'period' => 'privacy:metadata:local_jedomi_whatsapp_usage:period',
            'inbound' => 'privacy:metadata:local_jedomi_whatsapp_usage:inbound',
            'outservice' => 'privacy:metadata:local_jedomi_whatsapp_usage:outservice',
            'outtemplate' => 'privacy:metadata:local_jedomi_whatsapp_usage:outtemplate',
            'media' => 'privacy:metadata:local_jedomi_whatsapp_usage:media',
        ], 'privacy:metadata:local_jedomi_whatsapp_usage');

        $collection->add_database_table('local_jedomi_agents', [
            'userid' => 'privacy:metadata:local_jedomi_agents:userid',
            'name' => 'privacy:metadata:local_jedomi_agents:name',
            'prompt' => 'privacy:metadata:local_jedomi_agents:prompt',
            'runwrites' => 'privacy:metadata:local_jedomi_agents:runwrites',
            'schedule_cron' => 'privacy:metadata:local_jedomi_agents:schedule_cron',
            'lastresult' => 'privacy:metadata:local_jedomi_agents:lastresult',
            'lasterror' => 'privacy:metadata:local_jedomi_agents:lasterror',
            'timecreated' => 'privacy:metadata:local_jedomi_agents:timecreated',
        ], 'privacy:metadata:local_jedomi_agents');

        $collection->add_database_table('local_jedomi_agent_cache', [
            'agentid' => 'privacy:metadata:local_jedomi_agent_cache:agentid',
            'result_json' => 'privacy:metadata:local_jedomi_agent_cache:result_json',
            'timecreated' => 'privacy:metadata:local_jedomi_agent_cache:timecreated',
        ], 'privacy:metadata:local_jedomi_agent_cache');

        $collection->add_database_table('local_jedomi_query_log', [
            'agentid' => 'privacy:metadata:local_jedomi_query_log:agentid',
            'userid' => 'privacy:metadata:local_jedomi_query_log:userid',
            'channel' => 'privacy:metadata:local_jedomi_query_log:channel',
            'query' => 'privacy:metadata:local_jedomi_query_log:query',
            'timecreated' => 'privacy:metadata:local_jedomi_query_log:timecreated',
        ], 'privacy:metadata:local_jedomi_query_log');

        // The technical log (a file in the data directory) keeps pseudonyms instead of user and chat ids, and the
        // source address of security failures only.
        $collection->add_type(new technical_log());

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

        // With the Datacurso service, files, images and audio are uploaded through the provider (provider_upload_service)
        // to /jedomi/v1/files/extract, /jedomi/v1/files/read and /jedomi/v1/audio/transcriptions, as they are: unlike the
        // chat, neither the file nor the question is anonymised. The provider adds the pseudonymised user and the site.
        $collection->add_external_location_link('jedomi_file_service', [
            'file' => 'privacy:metadata:jedomi_file_service:file',
            'filename' => 'privacy:metadata:jedomi_file_service:filename',
            'mimetype' => 'privacy:metadata:jedomi_file_service:mimetype',
            'question' => 'privacy:metadata:jedomi_file_service:question',
            'language' => 'privacy:metadata:jedomi_file_service:language',
            'userid' => 'privacy:metadata:jedomi_file_service:userid',
            'lang' => 'privacy:metadata:jedomi_file_service:lang',
            'site_id' => 'privacy:metadata:jedomi_file_service:site_id',
            'site_url' => 'privacy:metadata:jedomi_file_service:site_url',
        ], 'privacy:metadata:jedomi_file_service');

        // Only when the administrator chooses the custom AI service: no user id, site id or site address is sent.
        $collection->add_external_location_link('jedomi_custom_ai_service', [
            'messages' => 'privacy:metadata:jedomi_custom_ai_service:messages',
            'text' => 'privacy:metadata:jedomi_custom_ai_service:text',
            'files' => 'privacy:metadata:jedomi_custom_ai_service:files',
            'filename' => 'privacy:metadata:jedomi_custom_ai_service:filename',
            'mimetype' => 'privacy:metadata:jedomi_custom_ai_service:mimetype',
            'question' => 'privacy:metadata:jedomi_custom_ai_service:question',
            'language' => 'privacy:metadata:jedomi_custom_ai_service:language',
        ], 'privacy:metadata:jedomi_custom_ai_service');

        // Only with the custom AI service and its documents on: the PDF files go to its document service.
        $collection->add_external_location_link('jedomi_custom_document_service', [
            'files' => 'privacy:metadata:jedomi_custom_document_service:files',
            'filename' => 'privacy:metadata:jedomi_custom_document_service:filename',
        ], 'privacy:metadata:jedomi_custom_document_service');

        $collection->add_external_location_link('telegram', [
            'chatid' => 'privacy:metadata:telegram:chatid',
            'messages' => 'privacy:metadata:telegram:messages',
        ], 'privacy:metadata:telegram');

        // Meta receives the messages directly through the WhatsApp Cloud API, without an intermediary. Jedomi never
        // uploads media: the media of the user are only downloaded from Meta by their id.
        $collection->add_external_location_link('whatsapp', [
            'phone' => 'privacy:metadata:whatsapp:phone',
            'bsuid' => 'privacy:metadata:whatsapp:bsuid',
            'messages' => 'privacy:metadata:whatsapp:messages',
            'media' => 'privacy:metadata:whatsapp:media',
        ], 'privacy:metadata:whatsapp');

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
        global $DB;
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
                'comment' => $record->feedbackcomment,
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => transform::datetime($record->timemodified ?: $record->timecreated),
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

            $whatsapp = self::export_whatsapp($userid);
            if ($whatsapp !== null) {
                $writer->export_data([get_string('privacy_whatsapp', 'local_jedomi')], $whatsapp);
            }

            $agents = self::export_agents($userid);
            if (!empty($agents)) {
                $writer->export_data([get_string('privacy_agents', 'local_jedomi')], (object) ['agents' => $agents]);
            }

            // Queries the AI ran for the user in the chat channels and the previews of agents.
            $queries = array_values(array_map(static fn(\stdClass $query): array => [
                'channel' => $query->channel,
                'query' => $query->query,
                'timecreated' => transform::datetime($query->timecreated),
            ], $DB->get_records('local_jedomi_query_log', ['userid' => $userid], 'timecreated ASC, id ASC')));
            if (!empty($queries)) {
                $writer->export_data([get_string('privacy_queries', 'local_jedomi')], (object) ['queries' => $queries]);
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
            || $DB->record_exists(whatsapp_link_service::LINKS_TABLE, ['userid' => $userid])
            || $DB->record_exists(whatsapp_link_service::USAGE_TABLE, ['userid' => $userid])
            || $DB->record_exists('local_jedomi_agents', ['userid' => $userid])
            || $DB->record_exists('local_jedomi_query_log', ['userid' => $userid]);
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
                'lastused' => $link->lastused ? transform::datetime($link->lastused) : null,
                // The PIN hash itself is never exported: only whether a PIN is set and its state.
                'pinset' => transform::yesno((string) $link->pinhash !== ''),
                'pinfailures' => (int) $link->pinfailures,
                'pinlockeduntil' => $link->pinlockeduntil ? transform::datetime($link->pinlockeduntil) : null,
                'pinverifiedat' => $link->pinverifiedat ? transform::datetime($link->pinverifiedat) : null,
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
     * WhatsApp data of a user: the link with its consent and opt-out and, for its chat, the conversation, the message
     * limit, the actions waiting for confirmation and the state of the received messages; and the monthly usage. The
     * encrypted payload of a received message is transient (emptied once processed) and is not exported.
     *
     * @param int $userid User id.
     * @return \stdClass|null Null when the user has no WhatsApp data.
     */
    private static function export_whatsapp(int $userid): ?\stdClass {
        global $DB;
        $links = $DB->get_records(whatsapp_link_service::LINKS_TABLE, ['userid' => $userid], 'id ASC');
        $usage = $DB->get_records(whatsapp_link_service::USAGE_TABLE, ['userid' => $userid], 'period ASC');
        if (empty($links) && empty($usage)) {
            return null;
        }
        $optionaldate = static fn($time): ?string => $time ? transform::datetime($time) : null;
        $exported = [];
        foreach ($links as $link) {
            $chatkey = whatsapp_link_service::chatkey((string) $link->bsuid);
            $sessions = $DB->get_records_list(
                whatsapp_link_service::SESSIONS_TABLE,
                'chatid',
                whatsapp_link_service::session_keys($chatkey),
                'id ASC'
            );
            $limit = $DB->get_record(whatsapp_link_service::RATE_TABLE, ['chatid' => $chatkey]);
            $actions = $DB->get_records(whatsapp_link_service::PENDING_TABLE, ['chatid' => $chatkey], 'id ASC');
            $inbox = $DB->get_records(whatsapp_link_service::INBOX_TABLE, ['bsuid' => $link->bsuid], 'id ASC');
            $exported[] = [
                'bsuid' => $link->bsuid,
                'waid' => $link->waid,
                'phone' => $link->phone,
                'confirmed' => transform::yesno((bool) $link->confirmed),
                'consentat' => $optionaldate($link->consentat),
                'consentversion' => $link->consentversion,
                'consentcategories' => $link->consentcategories,
                'optedout' => transform::yesno((bool) $link->optedout),
                'optoutat' => $optionaldate($link->optoutat),
                'lastinbound' => $optionaldate($link->lastinbound),
                'lastused' => $optionaldate($link->lastused),
                'lastproactivesent' => $optionaldate($link->lastproactivesent),
                'timecreated' => transform::datetime($link->timecreated),
                // The conversation. The tool loop state (usercontext) is not exported, like in Telegram: it holds a signed
                // permission token and the real names and emails of other users behind the anonymisation placeholders.
                'conversations' => array_values(array_map(static fn(\stdClass $session): array => [
                    'messages' => $session->messages,
                    'iteration' => $session->iteration === null ? null : (int) $session->iteration,
                    'lastactivity' => transform::datetime($session->lastactivity),
                ], $sessions)),
                'messagelimit' => $limit ? [
                    'msgcount' => (int) $limit->msgcount,
                    'windowstart' => transform::datetime($limit->windowstart),
                    'blockeduntil' => $optionaldate($limit->blockeduntil),
                ] : null,
                // The callback id is a short-lived confirmation token and is not exported, like the PIN hash.
                'pendingactions' => array_values(array_map(static fn(\stdClass $action): array => [
                    'action' => $action->actionjson,
                    'courseid' => (int) $action->courseid,
                    'timecreated' => transform::datetime($action->timecreated),
                ], $actions)),
                'inbox' => array_values(array_map(static fn(\stdClass $message): array => [
                    'wamid' => $message->wamid,
                    'status' => $message->status,
                    'attempts' => (int) $message->attempts,
                    'timecreated' => transform::datetime($message->timecreated),
                    'timeprocessed' => $optionaldate($message->timeprocessed),
                ], $inbox)),
            ];
        }
        return (object) [
            'links' => $exported,
            'usage' => array_values(array_map(static fn(\stdClass $month): array => [
                'period' => (int) $month->period,
                'inbound' => (int) $month->inbound,
                'outservice' => (int) $month->outservice,
                'outtemplate' => (int) $month->outtemplate,
                'media' => (int) $month->media,
            ], $usage)),
        ];
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
                'lastresult' => $agent->lastresult,
                'lasterror' => $agent->lasterror,
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
     * Delete every record of a user: ratings, pending actions, Telegram and WhatsApp data and agents with their results
     * and queries.
     *
     * @param int $userid User id.
     * @return void
     */
    private static function delete_user_data(int $userid): void {
        global $DB;
        feedback_service::delete_for_users([$userid]);
        pending_action_service::delete_for_users([$userid]);
        telegram_link_service::unlink($userid);
        whatsapp_link_service::unlink($userid, null, true);
        $agentids = $DB->get_fieldset_select('local_jedomi_agents', 'id', 'userid = ?', [$userid]);
        foreach ($agentids as $agentid) {
            $DB->delete_records('local_jedomi_query_log', ['agentid' => $agentid]);
            agent_manager::delete_agent((int) $agentid);
        }
        $DB->delete_records('local_jedomi_query_log', ['userid' => $userid]);
    }
}
