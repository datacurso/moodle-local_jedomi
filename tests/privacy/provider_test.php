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
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_jedomi\local\service\feedback_service;
use local_jedomi\local\service\pending_action_service;
use local_jedomi\local\service\whatsapp_link_service;

/**
 * Tests for the privacy provider of local_jedomi (answer feedback).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Create two users with feedback.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function create_users_with_feedback(): array {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        feedback_service::record((int) $user1->id, 'good', 'sess_1', 'Answer one');
        feedback_service::record((int) $user1->id, 'bad', 'sess_1', 'Answer two');
        feedback_service::record((int) $user2->id, 'good', 'sess_2', 'Answer three');
        return [$user1, $user2];
    }

    /**
     * M10: the feedback table is declared in the metadata.
     * MDL-INT-079: the declaration includes the ratings.
     */
    public function test_metadata_declares_feedback_table(): void {
        $collection = provider::get_metadata(new collection('local_jedomi'));
        $names = array_map(static fn($item) => $item->get_name(), $collection->get_collection());

        $this->assertContains('local_jedomi_feedback', $names);
    }

    /**
     * M10: a user's feedback is found in their user context and exported.
     * MDL-INT-079: the export gives the ratings of the user.
     */
    public function test_export_user_feedback(): void {
        $this->resetAfterTest();
        [$user1] = $this->create_users_with_feedback();
        $context = \context_user::instance($user1->id);

        $contextlist = provider::get_contexts_for_userid((int) $user1->id);
        $this->assertSame([(int) $context->id], array_map('intval', $contextlist->get_contextids()));

        provider::export_user_data(new approved_contextlist($user1, 'local_jedomi', [$context->id]));

        $data = writer::with_context($context)->get_data([get_string('privacy_feedback', 'local_jedomi')]);
        $this->assertCount(2, $data->feedback);
        $this->assertSame('Answer one', $data->feedback[0]['messagetext']);
    }

    /**
     * M10: deleting one user's data removes only their feedback.
     * MDL-INT-079: deletion by user removes the ratings of that user only.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        [$user1, $user2] = $this->create_users_with_feedback();
        $context = \context_user::instance($user1->id);

        provider::delete_data_for_user(new approved_contextlist($user1, 'local_jedomi', [$context->id]));

        $this->assertSame(0, $DB->count_records('local_jedomi_feedback', ['userid' => $user1->id]));
        $this->assertSame(1, $DB->count_records('local_jedomi_feedback', ['userid' => $user2->id]));
    }

    /**
     * M10: deleting a user context removes the feedback of that user.
     * MDL-INT-079: deletion by context removes the ratings of the user of that context.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        [$user1, $user2] = $this->create_users_with_feedback();

        provider::delete_data_for_all_users_in_context(\context_user::instance($user1->id));
        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(0, $DB->count_records('local_jedomi_feedback', ['userid' => $user1->id]));
        $this->assertSame(1, $DB->count_records('local_jedomi_feedback', ['userid' => $user2->id]));
    }

    /**
     * M10: the user list of a user context holds that user, and deleting it removes their feedback.
     * MDL-INT-079: deletion by list of users removes the ratings.
     */
    public function test_userlist_and_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        [$user1, $user2] = $this->create_users_with_feedback();
        $context = \context_user::instance($user1->id);

        $userlist = new userlist($context, 'local_jedomi');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $user1->id], array_map('intval', $userlist->get_userids()));

        provider::delete_data_for_users(new approved_userlist($context, 'local_jedomi', [$user1->id]));

        $this->assertSame(0, $DB->count_records('local_jedomi_feedback', ['userid' => $user1->id]));
        $this->assertSame(1, $DB->count_records('local_jedomi_feedback', ['userid' => $user2->id]));
    }
    /**
     * Fields declared for an item of the metadata.
     *
     * @param string $name Item name.
     * @return array|null Field names, or null when the item is not declared.
     */
    private function declared_fields(string $name): ?array {
        $collection = provider::get_metadata(new collection('local_jedomi'));
        foreach ($collection->get_collection() as $item) {
            if ($item->get_name() === $name) {
                return array_keys($item->get_privacy_fields());
            }
        }
        return null;
    }

    /**
     * Hold a web chat action that waits for the confirmation of a user.
     *
     * @param int $userid User.
     * @return void
     */
    private function hold_action(int $userid): void {
        $card = pending_action_service::hold($userid, 'send_messages', ['userids' => [2], 'message' => 'Hello']);
        $this->assertNotNull($card, 'Sending messages must wait for confirmation');
    }

    /**
     * MDL-INT-079: the declaration includes the pending actions and the conversation sent to the AI service, where the
     * user is identified with a pseudonymous identifier.
     */
    public function test_metadata_declares_pending_actions_and_the_ai_service(): void {
        $this->assertNotNull($this->declared_fields('local_jedomi_pending_actions'));
        $this->assertContains('userid', $this->declared_fields('local_jedomi_pending_actions'));
        $aiservice = $this->declared_fields('jedomi_ai_service');
        $this->assertNotNull($aiservice, 'The data sent to the AI service must be declared');
        $this->assertContains('userid', $aiservice);
        $this->assertContains('messages', $aiservice);
        foreach (['lang', 'site_id', 'site_url'] as $field) {
            $this->assertContains($field, $aiservice, "The {$field} the Datacurso provider adds to every request must be declared");
        }
        $this->assertStringContainsStringIgnoringCase(
            'pseudonym',
            get_string('privacy:metadata:jedomi_ai_service:userid', 'local_jedomi')
        );
    }

    /**
     * MDL-INT-080: the text read aloud sent to the speech service is declared.
     * The text of the spoken answers sent to the AI service for the voice conversation is declared.
     */
    public function test_metadata_declares_the_speech_service(): void {
        $speech = $this->declared_fields('jedomi_speech_service');
        $this->assertNotNull($speech, 'The text sent for speech must be declared');
        $this->assertContains('userid', $speech);
        $this->assertContains('text', $speech);
        foreach (['lang', 'site_id', 'site_url'] as $field) {
            $this->assertContains($field, $speech, "The {$field} sent with every speech request must be declared");
        }
        $this->assertStringContainsStringIgnoringCase(
            'Privacy tab',
            get_string('privacy:metadata:jedomi_speech_service:text', 'local_jedomi')
        );
        $this->assertStringContainsStringIgnoringCase(
            'pseudonym',
            get_string('privacy:metadata:jedomi_speech_service:userid', 'local_jedomi')
        );
    }

    /**
     * The data sent to a custom AI service is declared, without a user or site identifier.
     */
    public function test_metadata_declares_the_custom_ai_service(): void {
        $custom = $this->declared_fields('jedomi_custom_ai_service');
        $this->assertNotNull($custom, 'The data sent to the custom AI service must be declared');
        $this->assertSame(['messages', 'text', 'files', 'filename', 'mimetype', 'question', 'language'], array_values($custom));
        $files = get_string('privacy:metadata:jedomi_custom_ai_service:files', 'local_jedomi');
        $this->assertStringContainsString('page images', $files);
        $this->assertStringContainsString('not anonymised', $files);
        $this->assertStringContainsString(
            'replaced by placeholders',
            get_string('privacy:metadata:jedomi_custom_ai_service:question', 'local_jedomi')
        );
    }

    /**
     * JEDOMI-SEC-007, SEC-JD-096: the files, images and audio sent through the Datacurso AI provider to be read or
     * transcribed are declared with the fields of the upload and those the provider adds to every request.
     */
    public function test_metadata_declares_the_files_sent_to_the_datacurso_service(): void {
        $fileservice = $this->declared_fields('jedomi_file_service');
        $this->assertNotNull($fileservice, 'The files sent to the Datacurso AI service must be declared');
        $this->assertSame(
            ['file', 'filename', 'mimetype', 'question', 'language', 'userid', 'lang', 'site_id', 'site_url'],
            array_values($fileservice)
        );
        $summary = get_string('privacy:metadata:jedomi_file_service', 'local_jedomi');
        foreach (['image', 'audio', 'PDF', 'Telegram'] as $word) {
            $this->assertStringContainsString($word, $summary);
        }
        $this->assertStringContainsString(
            'not anonymised',
            get_string('privacy:metadata:jedomi_file_service:file', 'local_jedomi')
        );
        $this->assertStringContainsString(
            'replaced by placeholders',
            get_string('privacy:metadata:jedomi_file_service:question', 'local_jedomi')
        );
        $this->assertStringContainsStringIgnoringCase(
            'pseudonym',
            get_string('privacy:metadata:jedomi_file_service:userid', 'local_jedomi')
        );
    }

    /**
     * JEDOMI-SEC-007: every string of the privacy declaration exists in English and in Spanish.
     */
    public function test_every_privacy_string_exists_in_both_languages(): void {
        global $CFG;
        $string = [];
        require($CFG->dirroot . '/local/jedomi/lang/es/local_jedomi.php');
        $spanish = $string;

        $keys = [];
        foreach (provider::get_metadata(new collection('local_jedomi'))->get_collection() as $item) {
            $keys[] = $item->get_summary();
            foreach ($item->get_privacy_fields() ?? [] as $key) {
                $keys[] = $key;
            }
        }
        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertTrue(get_string_manager()->string_exists($key, 'local_jedomi'), "Missing English string {$key}");
            $this->assertArrayHasKey($key, $spanish, "Missing Spanish string {$key}");
        }
    }

    /**
     * The PDF files sent to the document service of the custom AI service, when documents are on, are declared.
     */
    public function test_metadata_declares_the_custom_document_service(): void {
        $docservice = $this->declared_fields('jedomi_custom_document_service');
        $this->assertNotNull($docservice, 'The files sent to the document service must be declared');
        $this->assertSame(['files', 'filename'], array_values($docservice));
        $this->assertStringContainsString('PDF', get_string('privacy:metadata:jedomi_custom_document_service', 'local_jedomi'));
        $files = get_string('privacy:metadata:jedomi_custom_document_service:files', 'local_jedomi');
        $this->assertStringContainsString('images', $files);
    }

    /**
     * MDL-INT-079: deleting by user, by list of users and by context removes the ratings and the pending actions of the
     * user and keeps those of the others.
     */
    public function test_every_deletion_removes_ratings_and_pending_actions(): void {
        global $DB;
        $this->resetAfterTest();
        $users = [];
        for ($i = 0; $i < 4; $i++) {
            $users[$i] = $this->getDataGenerator()->create_user();
            feedback_service::record((int) $users[$i]->id, 'good', 'sess', 'Answer');
            $this->hold_action((int) $users[$i]->id);
        }
        $this->assertSame(
            [(int) \context_user::instance($users[0]->id)->id],
            array_map('intval', provider::get_contexts_for_userid((int) $users[0]->id)->get_contextids())
        );

        $context0 = \context_user::instance($users[0]->id);
        provider::delete_data_for_user(new approved_contextlist($users[0], 'local_jedomi', [$context0->id]));
        $context1 = \context_user::instance($users[1]->id);
        provider::delete_data_for_users(new approved_userlist($context1, 'local_jedomi', [$users[1]->id]));
        provider::delete_data_for_all_users_in_context(\context_user::instance($users[2]->id));

        foreach ([0, 1, 2] as $i) {
            $this->assertSame(0, $DB->count_records('local_jedomi_feedback', ['userid' => $users[$i]->id]));
            $this->assertSame(0, $DB->count_records('local_jedomi_pending_actions', ['userid' => $users[$i]->id]));
        }
        $this->assertSame(1, $DB->count_records('local_jedomi_feedback', ['userid' => $users[3]->id]));
        $this->assertSame(1, $DB->count_records('local_jedomi_pending_actions', ['userid' => $users[3]->id]));
    }

    /**
     * Link a user to Telegram and give them a conversation, a message limit record and an agent.
     *
     * @param \stdClass $user User.
     * @param string $chatid Chat id.
     * @return void
     */
    private function create_telegram_and_agent_data(\stdClass $user, string $chatid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $user->id, 'chatid' => $chatid,
            'username' => 'tg_' . $user->username, 'confirmed' => 1, 'timecreated' => $now, 'lastproactivesent' => 0]);
        $DB->insert_record('local_jedomi_telegram_sessions', (object) ['chatid' => $chatid,
            'messages' => json_encode([['role' => 'user', 'content' => 'My grades please']]), 'usercontext' => null,
            'lastactivity' => $now]);
        $DB->insert_record('local_jedomi_telegram_rate_limit', (object) ['chatid' => $chatid, 'windowstart' => $now,
            'msgcount' => 1, 'blockeduntil' => 0]);
        $DB->insert_record('local_jedomi_agents', (object) ['userid' => $user->id, 'name' => 'Weekly report',
            'prompt' => 'List the students at risk', 'schedule_cron' => '0 8 * * 1', 'context_type' => 'site',
            'enabled' => 1, 'failcount' => 0, 'nextrun' => $now + 3600, 'timecreated' => $now, 'timemodified' => $now]);
    }

    /**
     * MDL-INT-080: the declaration covers the Telegram data, the delivery to Telegram, the agents and their results, the
     * profiler queries and the technical log.
     */
    public function test_metadata_declares_telegram_agents_profiler_and_log(): void {
        $collection = provider::get_metadata(new collection('local_jedomi'));
        $names = array_map(static fn($item) => $item->get_name(), $collection->get_collection());

        $missing = array_values(array_diff([
            'local_jedomi_telegram_links',
            'local_jedomi_telegram_sessions',
            'local_jedomi_telegram_rate_limit',
            'local_jedomi_agents',
            'local_jedomi_agent_cache',
            'local_jedomi_query_log',
        ], $names));
        $this->assertSame([], $missing, 'Personal data tables not declared');
        $external = array_filter($names, static fn(string $name): bool => stripos($name, 'telegram') !== false
            && strpos($name, 'local_jedomi_') !== 0);
        $this->assertNotEmpty($external, 'The delivery of data to Telegram must be declared as an external location');
    }

    /**
     * MDL-INT-080: the export of a user includes their pending actions.
     */
    public function test_export_includes_the_pending_actions(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->hold_action((int) $user->id);
        $context = \context_user::instance($user->id);

        $contextlist = provider::get_contexts_for_userid((int) $user->id);
        $this->assertContains((int) $context->id, array_map('intval', $contextlist->get_contextids()));
        provider::export_user_data(new approved_contextlist($user, 'local_jedomi', $contextlist->get_contextids()));

        $this->assertTrue(writer::with_context($context)->has_any_data(), 'The pending actions of the user are exported');
    }

    /**
     * MDL-INT-080: the export of a user includes their Telegram data and their agents.
     */
    public function test_export_includes_telegram_and_agent_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_telegram_and_agent_data($user, '880000001');

        $contextlist = provider::get_contexts_for_userid((int) $user->id);
        $this->assertNotEmpty($contextlist->get_contextids(), 'A user with Telegram data and agents has data to export');
        provider::export_user_data(new approved_contextlist($user, 'local_jedomi', $contextlist->get_contextids()));

        $writer = writer::with_context(\context_user::instance($user->id));
        $this->assertTrue($writer->has_any_data(), 'The Telegram data and the agents are exported');
        $this->assertNotEmpty($writer->get_data([get_string('privacy_telegram', 'local_jedomi')]), 'Telegram data exported');
        $this->assertNotEmpty($writer->get_data([get_string('privacy_agents', 'local_jedomi')]), 'Agents exported');
    }

    /**
     * MDL-INT-080: deleting the data of a user removes their Telegram link, conversation and limit records and their
     * agents, and keeps those of other users.
     */
    public function test_delete_removes_telegram_and_agent_data(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_telegram_and_agent_data($user, '880000002');
        $this->create_telegram_and_agent_data($other, '880000003');
        $context = \context_user::instance($user->id);

        provider::delete_data_for_user(new approved_contextlist($user, 'local_jedomi', [$context->id]));

        $this->assertSame([
            'links' => 0, 'sessions' => 0, 'limits' => 0, 'agents' => 0,
        ], [
            'links' => $DB->count_records('local_jedomi_telegram_links', ['userid' => $user->id]),
            'sessions' => $DB->count_records('local_jedomi_telegram_sessions', ['chatid' => '880000002']),
            'limits' => $DB->count_records('local_jedomi_telegram_rate_limit', ['chatid' => '880000002']),
            'agents' => $DB->count_records('local_jedomi_agents', ['userid' => $user->id]),
        ]);
        $this->assertSame(1, $DB->count_records('local_jedomi_agents', ['userid' => $other->id]));
    }

    /**
     * MDL-INT-109: the privacy declaration includes the technical log with the data it keeps (pseudonyms of users and
     * Telegram chats, and IP addresses of security failures), its retention and its deletion; the log keeps pseudonyms,
     * never the ids.
     *
     * @return void
     */
    public function test_metadata_declares_the_technical_log(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $this->resetAfterTest();

        $items = provider::get_metadata(new collection('local_jedomi'))->get_collection();
        $logs = array_values(array_filter($items, static fn($item): bool =>
            $item->get_name() === \local_jedomi\privacy\technical_log::NAME));
        $this->assertCount(1, $logs, 'The technical log is declared');
        $log = $logs[0];
        $this->assertSame(['user', 'chat', 'ipaddress'], array_keys($log->get_privacy_fields()));
        $summary = get_string($log->get_summary(), 'local_jedomi');
        foreach (['jedomi.log', 'pseudonyms', '5 MB', '30 MB', 'oldest entries are deleted', 'delete the files'] as $fact) {
            $this->assertStringContainsString($fact, $summary, 'The summary tells what is kept, how long and how it is deleted');
        }
        foreach ($log->get_privacy_fields() as $identifier) {
            $this->assertNotEmpty(get_string($identifier, 'local_jedomi'));
        }

        // The log keeps a pseudonym: stable, different per kind of identifier, without the id itself.
        $chat = \local_jedomi_log_pseudonym('chat', '880000777');
        $this->assertSame($chat, \local_jedomi_log_pseudonym('chat', '880000777'));
        $this->assertStringNotContainsString('880000777', $chat);
        $this->assertNotSame(substr($chat, 5), substr(\local_jedomi_log_pseudonym('user', '880000777'), 5));
        $this->assertMatchesRegularExpression('/^chat#[0-9a-f]{12}$/', $chat);

        // The key is random per site: another key gives another pseudonym.
        set_config('log_key', str_repeat('a', 64), 'local_jedomi');
        $this->assertNotSame($chat, \local_jedomi_log_pseudonym('chat', '880000777'));
    }

    /**
     * Decision 7: the export includes the last use of the link and the PIN state, never the PIN hash; the deletion removes
     * them with the link.
     */
    public function test_export_and_delete_cover_the_link_use_and_the_pin(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_telegram_and_agent_data($user, '880000010');
        $lastused = time() - 60;
        $DB->set_field('local_jedomi_telegram_links', 'lastused', $lastused, ['userid' => $user->id]);
        \local_jedomi\local\service\telegram_pin_service::set_pin((int) $user->id, '135790');
        \local_jedomi\local\service\telegram_pin_service::verify((int) $user->id, '000000');
        $hash = (string) $DB->get_field('local_jedomi_telegram_links', 'pinhash', ['userid' => $user->id]);
        $context = \context_user::instance($user->id);

        provider::export_user_data(new approved_contextlist($user, 'local_jedomi', [$context->id]));
        $chat = writer::with_context($context)->get_data([get_string('privacy_telegram', 'local_jedomi')])->chats[0];
        $this->assertSame(\core_privacy\local\request\transform::datetime($lastused), $chat['lastused']);
        $this->assertSame(\core_privacy\local\request\transform::yesno(true), $chat['pinset']);
        $this->assertSame(1, $chat['pinfailures']);
        $this->assertStringNotContainsString($hash, json_encode($chat));

        $names = array_map(static fn($item) => $item->get_name(), provider::get_metadata(new collection('local_jedomi'))
            ->get_collection());
        $links = provider::get_metadata(new collection('local_jedomi'))->get_collection()[array_search(
            'local_jedomi_telegram_links',
            $names
        )]->get_privacy_fields();
        foreach (['lastused', 'pinhash', 'pinfailures', 'pinlockeduntil', 'pinverifiedat'] as $field) {
            $this->assertArrayHasKey($field, $links, $field);
        }

        provider::delete_data_for_user(new approved_contextlist($user, 'local_jedomi', [$context->id]));
        $this->assertFalse($DB->record_exists('local_jedomi_telegram_links', ['userid' => $user->id]));
    }

    /**
     * Link a user to WhatsApp without a phone id and give the chat a conversation, its tool loop state, a message limit,
     * a held action, an inbox row and usage counters.
     *
     * @param \stdClass $user User.
     * @param string $bsuid BSUID of the user.
     * @return string Chat key of the user.
     */
    private function create_whatsapp_data(\stdClass $user, string $bsuid): string {
        global $DB;
        $now = time();
        $chatkey = 'wa_' . $bsuid;
        $DB->insert_record('local_jedomi_whatsapp_links', (object) ['userid' => $user->id, 'bsuid' => $bsuid,
            'confirmed' => 1, 'consentat' => $now, 'consentversion' => 'v1', 'consentcategories' => 'chat,grades',
            'lastinbound' => $now, 'lastused' => $now, 'timecreated' => $now]);
        $DB->insert_record('local_jedomi_whatsapp_sessions', (object) ['chatid' => $chatkey,
            'messages' => json_encode([['role' => 'user', 'content' => 'My grades please']]), 'lastactivity' => $now]);
        $DB->insert_record('local_jedomi_whatsapp_sessions', (object) ['chatid' => 'tool_loop_' . $chatkey,
            'usercontext' => json_encode([
                'anonymizer' => ['[person_1]' => 'Bruno Thirdparty', '[email_1]' => 'bruno.thirdparty@example.com'],
                'spt' => ['userid' => $user->id, 'expires' => $now + 1800,
                    'signature' => 'sptsignature0123456789abcdef'],
                'tools' => [['name' => 'my_grades']],
                'messages' => [['role' => 'user', 'content' => 'My grades please']],
                'userid' => $user->id,
            ]),
            'iteration' => 2, 'lastactivity' => $now]);
        $DB->insert_record('local_jedomi_whatsapp_rate', (object) ['chatid' => $chatkey, 'windowstart' => $now,
            'msgcount' => 1, 'blockeduntil' => 0]);
        $DB->insert_record('local_jedomi_whatsapp_pending', (object) ['callbackid' => random_string(16),
            'chatid' => $chatkey, 'actionjson' => '{"tool":"my_grades"}', 'courseid' => 0, 'timecreated' => $now]);
        $DB->insert_record('local_jedomi_whatsapp_inbox', (object) ['wamid' => 'wamid.' . sha1($bsuid), 'bsuid' => $bsuid,
            'payload' => \local_jedomi\local\secret_store::encrypt('{"text":{"body":"Hello"}}'), 'status' => 'pending',
            'timecreated' => $now]);
        $DB->insert_record('local_jedomi_whatsapp_usage', (object) ['userid' => $user->id, 'period' => 202611,
            'inbound' => 3, 'outservice' => 2]);
        return $chatkey;
    }

    /**
     * Unit 9: the six WhatsApp tables and the delivery to Meta (phone, BSUID, messages and media) are declared.
     */
    public function test_metadata_declares_the_whatsapp_tables_and_meta(): void {
        $tables = [
            'local_jedomi_whatsapp_links' => ['userid', 'bsuid', 'waid', 'phone', 'consentat', 'consentcategories', 'optedout'],
            'local_jedomi_whatsapp_sessions' => ['chatid', 'messages'],
            'local_jedomi_whatsapp_pending' => ['chatid', 'actionjson'],
            'local_jedomi_whatsapp_rate' => ['chatid', 'msgcount'],
            'local_jedomi_whatsapp_inbox' => ['wamid', 'bsuid', 'payload'],
            'local_jedomi_whatsapp_usage' => ['userid', 'period'],
        ];
        foreach ($tables as $table => $fields) {
            $declared = $this->declared_fields($table);
            $this->assertNotNull($declared, "{$table} must be declared");
            foreach ($fields as $field) {
                $this->assertContains($field, $declared, "{$table}.{$field}");
            }
        }
        $meta = $this->declared_fields('whatsapp');
        $this->assertNotNull($meta, 'The delivery of data to Meta must be declared as an external location');
        foreach (['phone', 'bsuid', 'messages', 'media'] as $field) {
            $this->assertContains($field, $meta, $field);
        }
        $this->assertStringContainsString('Meta', get_string('privacy:metadata:whatsapp', 'local_jedomi'));
    }

    /**
     * Unit 9: Jedomi never uploads media to Meta (design 6.5 and 6.6); the media of the user are only downloaded from
     * Meta by their id, and the declaration says so in both languages.
     */
    public function test_the_media_of_meta_are_declared_as_downloaded_not_sent(): void {
        global $CFG;
        $string = [];
        require($CFG->dirroot . '/local/jedomi/lang/es/local_jedomi.php');

        $english = get_string('privacy:metadata:whatsapp:media', 'local_jedomi');
        $this->assertStringContainsString('downloaded from Meta', $english);
        $this->assertStringContainsString('never uploads', $english);
        $this->assertStringContainsString('se descargan de Meta', $string['privacy:metadata:whatsapp:media']);
        $this->assertStringContainsString('nunca sube', $string['privacy:metadata:whatsapp:media']);
    }

    /**
     * Unit 9: the periods the WhatsApp privacy strings state match the constants that apply them. Privacy metadata
     * strings take no parameters, so each number is checked against its constant in both languages.
     */
    public function test_the_whatsapp_privacy_periods_match_the_constants(): void {
        global $CFG;
        $string = [];
        require($CFG->dirroot . '/local/jedomi/lang/es/local_jedomi.php');
        $spanish = $string;

        $periods = [
            'privacy:metadata:local_jedomi_whatsapp_inbox' => [\local_jedomi\task\cleanup_whatsapp::INBOX_TTL, DAYSECS],
            'privacy:metadata:local_jedomi_whatsapp_links:lastused' => [whatsapp_link_service::EXPIRY, DAYSECS],
            'privacy:metadata:local_jedomi_whatsapp_links:lastinbound' => [whatsapp_link_service::SERVICE_WINDOW, HOURSECS],
        ];
        $units = [DAYSECS => ['days', 'días'], HOURSECS => ['hours', 'horas']];
        foreach ($periods as $key => [$seconds, $unit]) {
            $this->assertSame(0, $seconds % $unit, "{$key} is a whole number of units");
            [$en, $es] = $units[$unit];
            $amount = intdiv($seconds, $unit);
            $this->assertStringContainsString("{$amount} {$en}", get_string($key, 'local_jedomi'), "English {$key}");
            $this->assertStringContainsString("{$amount} {$es}", $spanish[$key], "Spanish {$key}");
        }
    }

    /**
     * Unit 9: every column of the WhatsApp tables in install.xml, except the id, holds data about the user and is
     * declared in the metadata of its table.
     */
    public function test_metadata_declares_every_column_of_the_whatsapp_tables(): void {
        global $CFG;
        $xmldb = new \xmldb_file($CFG->dirroot . '/local/jedomi/db/install.xml');
        $this->assertTrue($xmldb->loadXMLStructure());
        $checked = 0;
        foreach ($xmldb->getStructure()->getTables() as $table) {
            if (strpos($table->getName(), 'local_jedomi_whatsapp_') !== 0) {
                continue;
            }
            $declared = $this->declared_fields($table->getName()) ?? [];
            foreach ($table->getFields() as $field) {
                if ($field->getName() !== 'id') {
                    $this->assertContains($field->getName(), $declared, "{$table->getName()}.{$field->getName()}");
                }
            }
            $checked++;
        }
        $this->assertSame(6, $checked, 'The six WhatsApp tables are checked');
    }

    /**
     * Unit 9: a WhatsApp link without a phone id puts the user in their context and is exported with the BSUID, the
     * consent, the opt-out, the conversation and the inbox state, never the encrypted payload nor the tool loop state.
     */
    public function test_a_whatsapp_link_without_wa_id_is_found_and_exported(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $bsuid = 'US.' . str_repeat('a1B2', 32);
        $this->create_whatsapp_data($user, $bsuid);
        $context = \context_user::instance($user->id);

        $this->assertSame([(int) $context->id], array_map('intval', provider::get_contexts_for_userid((int) $user->id)
            ->get_contextids()));
        $userlist = new userlist($context, 'local_jedomi');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $user->id], array_map('intval', $userlist->get_userids()));

        provider::export_user_data(new approved_contextlist($user, 'local_jedomi', [$context->id]));

        $data = writer::with_context($context)->get_data([get_string('privacy_whatsapp', 'local_jedomi')]);
        $this->assertNotEmpty($data, 'WhatsApp data exported');
        $link = $data->links[0];
        $this->assertSame($bsuid, $link['bsuid']);
        $this->assertNull($link['waid']);
        $this->assertSame('chat,grades', $link['consentcategories']);
        $this->assertSame('v1', $link['consentversion']);
        $this->assertSame(\core_privacy\local\request\transform::yesno(false), $link['optedout']);
        $this->assertCount(2, $link['conversations']);
        $this->assertSame([null, 2], array_column($link['conversations'], 'iteration'));
        $this->assertSame(
            [json_encode([['role' => 'user', 'content' => 'My grades please']]), null],
            array_column($link['conversations'], 'messages')
        );
        // The tool loop state holds a signed permission token and the real names of other users: never exported.
        foreach ($link['conversations'] as $conversation) {
            $this->assertArrayNotHasKey('usercontext', $conversation);
        }
        $exported = json_encode($data);
        $this->assertStringNotContainsString('sptsignature0123456789abcdef', $exported);
        $this->assertStringNotContainsString('Bruno Thirdparty', $exported);
        $this->assertStringNotContainsString('bruno.thirdparty@example.com', $exported);
        $this->assertStringNotContainsString('anonymizer', $exported);
        $this->assertSame(1, $link['messagelimit']['msgcount']);
        $this->assertCount(1, $link['pendingactions']);
        $this->assertSame('pending', $link['inbox'][0]['status']);
        $this->assertArrayNotHasKey('payload', $link['inbox'][0]);
        $this->assertSame(3, $data->usage[0]['inbound']);
    }

    /**
     * Unit 9: each deletion removes the WhatsApp link with the conversation, the tool loop state, the message limit, the
     * held actions, the inbox rows and the usage of the user, and keeps those of other users.
     *
     * @dataProvider deletion_provider
     * @param string $method How the data is deleted.
     */
    public function test_every_deletion_removes_the_whatsapp_data(string $method): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $chatkey = $this->create_whatsapp_data($user, 'US.13491208655302741918');
        $otherkey = $this->create_whatsapp_data($other, 'CO.99999999999');
        $context = \context_user::instance($user->id);

        if ($method === 'user') {
            provider::delete_data_for_user(new approved_contextlist($user, 'local_jedomi', [$context->id]));
        } else if ($method === 'context') {
            provider::delete_data_for_all_users_in_context($context);
        } else {
            provider::delete_data_for_users(new approved_userlist($context, 'local_jedomi', [$user->id]));
        }

        $count = static fn(string $key, string $bsuid, int $userid): array => [
            'links' => $DB->count_records('local_jedomi_whatsapp_links', ['userid' => $userid]),
            'sessions' => $DB->count_records_select(
                'local_jedomi_whatsapp_sessions',
                'chatid = ? OR chatid = ?',
                [$key, whatsapp_link_service::TOOL_LOOP_PREFIX . $key]
            ),
            'rate' => $DB->count_records('local_jedomi_whatsapp_rate', ['chatid' => $key]),
            'pending' => $DB->count_records('local_jedomi_whatsapp_pending', ['chatid' => $key]),
            'inbox' => $DB->count_records('local_jedomi_whatsapp_inbox', ['bsuid' => $bsuid]),
            'usage' => $DB->count_records('local_jedomi_whatsapp_usage', ['userid' => $userid]),
        ];
        $this->assertSame(
            ['links' => 0, 'sessions' => 0, 'rate' => 0, 'pending' => 0, 'inbox' => 0, 'usage' => 0],
            $count($chatkey, 'US.13491208655302741918', (int) $user->id)
        );
        $this->assertSame(
            ['links' => 1, 'sessions' => 2, 'rate' => 1, 'pending' => 1, 'inbox' => 1, 'usage' => 1],
            $count($otherkey, 'CO.99999999999', (int) $other->id)
        );
        $this->assertSame([], provider::get_contexts_for_userid((int) $user->id)->get_contextids());
    }

    /**
     * Ways the data of a user is deleted.
     *
     * @return array
     */
    public static function deletion_provider(): array {
        return [
            'for user' => ['user'],
            'all users in context' => ['context'],
            'users in context' => ['users'],
        ];
    }
}
