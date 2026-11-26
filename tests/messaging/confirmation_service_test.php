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

namespace local_jedomi\messaging;

use local_jedomi\local\service\pending_action_service;
use local_jedomi\local\service\telegram_pin_service;

/**
 * Tests for the channel-neutral confirmation service: hold, find, resolve and audit the actions that change data.
 *
 * Each channel gives the service its own table (same shape as local_jedomi_telegram_pending_actions), its own
 * confirmation_ui and the lookup of the user linked to a chat. The tests run against the Telegram table and against a
 * temporary table created here, which stands in for the table of another channel, with a recording confirmation_ui.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\confirmation_service
 * @covers     \local_jedomi\messaging\confirmation_card
 * @covers     \local_jedomi\messaging\confirmation_reply
 */
final class confirmation_service_test extends \advanced_testcase {
    /** @var string Telegram table of held actions. */
    private const TELEGRAM_TABLE = 'local_jedomi_telegram_pending_actions';

    /** @var string Temporary table standing in for the held actions table of another channel. */
    private const OTHER_TABLE = 'local_jedomi_test_pending';

    /** @var string Chat key used by the tests. */
    private const CHAT = 'chat_1';

    /** @var object|null Recording confirmation_ui of the last service built. */
    private ?object $ui = null;

    /** @var int[] User linked to each chat key, as the channel lookup returns it. */
    private array $links = [];

    /** @var \Closure|null Run once by the next lookup of the linked user, after the service has read the action. */
    private ?\Closure $beforelookup = null;

    #[\Override]
    protected function tearDown(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::OTHER_TABLE);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $this->ui = null;
        parent::tearDown();
    }

    /**
     * Service tables: the Telegram table and another table.
     *
     * @return array
     */
    public static function table_provider(): array {
        return [
            'telegram table' => [self::TELEGRAM_TABLE],
            'other table' => [self::OTHER_TABLE],
        ];
    }

    /**
     * A tool that changes nothing is not held: it runs at once.
     *
     * @dataProvider table_provider
     * @param string $table
     */
    public function test_tool_that_changes_nothing_is_not_held(string $table): void {
        global $DB;
        $this->resetAfterTest();
        $service = $this->service($table);

        $this->assertNull($service->hold(self::CHAT, (int) get_admin()->id, 'list_agents', []));
        $this->assertSame(0, $DB->count_records($table));
        $this->assertSame([], $this->ui->shown);
    }

    /**
     * A medium action is stored in the table of the service only and shown with its card; the AI is told to wait.
     *
     * @dataProvider table_provider
     * @param string $table
     */
    public function test_medium_action_is_held_in_the_service_table(string $table): void {
        global $DB;
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;
        $service = $this->service($table);
        $args = ['name' => 'Digest', 'prompt' => 'Summarise', 'schedule_cron' => '0 8 * * 1'];

        $result = json_decode((string) $service->hold(self::CHAT, $adminid, 'create_agent', $args), true);

        $this->assertSame('awaiting_confirmation', $result['status']);
        $this->assertStringContainsString('confirm it with the buttons', $result['note']);
        $this->assertSame(1, $DB->count_records($table));
        $other = $table === self::TELEGRAM_TABLE ? self::OTHER_TABLE : self::TELEGRAM_TABLE;
        $this->assertSame(0, $this->count_if_exists($other), 'Nothing is written to the table of another channel');
        $record = $DB->get_record($table, ['chatid' => self::CHAT], '*', MUST_EXIST);
        $this->assertSame(16, strlen($record->callbackid));
        $this->assertSame(
            ['name' => 'create_agent', 'params' => $args, 'userid' => $adminid],
            json_decode($record->actionjson, true)
        );

        $this->assertCount(1, $this->ui->shown);
        [$chatkey, $card] = $this->ui->shown[0];
        $this->assertSame(self::CHAT, $chatkey);
        $this->assertInstanceOf(confirmation_card::class, $card);
        $this->assertSame($record->callbackid, $card->actionid);
        $this->assertSame('create_agent', $card->tool);
        $this->assertSame(pending_action_service::severity('create_agent', $args), $card->severity);
        $this->assertFalse($card->askpin);
        $this->assertFalse($card->is_critical());
        $expected = pending_action_service::card($adminid, 'create_agent', $args, $card->severity, 3000);
        $this->assertSame($expected['description'], $card->card['description']);
        $this->assertSame($expected['details'], $card->card['details']);
    }

    /**
     * A critical action waits for the confirmation word.
     */
    public function test_critical_action_waits_for_the_word(): void {
        $this->resetAfterTest();
        $service = $this->service(self::OTHER_TABLE);

        $result = json_decode((string) $service->hold(self::CHAT, (int) get_admin()->id, 'delete_agent', ['id' => 5]), true);

        $this->assertSame('awaiting_confirmation', $result['status']);
        $this->assertStringContainsString('type the confirmation word', $result['note']);
        $this->assertTrue($this->ui->shown[0][1]->is_critical());
    }

    /**
     * An action whose data does not fit whole in the channel is not held, and the AI is told to use the web chat.
     */
    public function test_action_longer_than_the_channel_shows_is_not_held(): void {
        global $DB;
        $this->resetAfterTest();
        $service = $this->service(self::OTHER_TABLE, 20);
        $args = ['name' => 'Digest', 'prompt' => str_repeat('Summarise the week. ', 5), 'schedule_cron' => '0 8 * * 1'];

        $result = json_decode((string) $service->hold(self::CHAT, (int) get_admin()->id, 'create_agent', $args), true);

        $this->assertSame('action_too_long_for_testchannel', $result['error']);
        $this->assertStringContainsString('too long to show in Test channel', $result['note']);
        $this->assertSame(0, $DB->count_records(self::OTHER_TABLE));
        $this->assertSame([], $this->ui->shown);
    }

    /**
     * The newest action waiting in the chat is found, and the newest critical one for the word; expired ones are not.
     *
     * @dataProvider table_provider
     * @param string $table
     */
    public function test_waiting_actions_are_the_newest_in_time(string $table): void {
        global $DB;
        $this->resetAfterTest();
        $service = $this->service($table);
        $adminid = (int) get_admin()->id;
        $this->assertNull($service->waiting(self::CHAT));
        $this->assertNull($service->waiting_critical(self::CHAT));

        $service->hold(self::CHAT, $adminid, 'delete_agent', ['id' => 1]);
        $critical = $this->ui->shown[0][1]->actionid;
        $service->hold(self::CHAT, $adminid, 'create_agent', ['name' => 'A', 'prompt' => 'B', 'schedule_cron' => '0 8 * * 1']);
        $medium = $this->ui->shown[1][1]->actionid;
        $service->hold('chat_2', $adminid, 'delete_agent', ['id' => 2]);
        $DB->set_field($table, 'timecreated', time() - 10, ['callbackid' => $critical]);

        $this->assertSame($medium, $service->waiting(self::CHAT));
        $this->assertSame($critical, $service->waiting_critical(self::CHAT));

        $DB->set_field($table, 'timecreated', time() - pending_action_service::EXPIRY - 1, []);
        $this->assertNull($service->waiting(self::CHAT));
        $this->assertNull($service->waiting_critical(self::CHAT));
    }

    /**
     * Resolving: an unknown action is not found; an expired one is removed; a button never confirms a critical action.
     *
     * @dataProvider table_provider
     * @param string $table
     */
    public function test_resolve_rejects_unknown_expired_and_button_for_critical(string $table): void {
        global $DB;
        $this->resetAfterTest();
        $service = $this->service($table);
        $adminid = (int) get_admin()->id;
        $this->links[self::CHAT] = $adminid;

        $this->assertSame(
            get_string('pending_action_not_found', 'local_jedomi'),
            $service->resolve(self::CHAT, 'unknown', confirmation_service::MODE_BUTTON)
        );

        $service->hold(self::CHAT, $adminid, 'delete_agent', ['id' => 1]);
        $actionid = $this->ui->shown[0][1]->actionid;
        $this->assertSame(
            get_string('pending_action_not_found', 'local_jedomi'),
            $service->resolve('chat_2', $actionid, confirmation_service::MODE_KEYWORD),
            'The action of another chat is not found'
        );
        $this->assertSame('keyword prompt', $service->resolve(self::CHAT, $actionid, confirmation_service::MODE_BUTTON));
        $this->assertTrue($DB->record_exists($table, ['callbackid' => $actionid]), 'The action is kept');

        $DB->set_field($table, 'timecreated', time() - pending_action_service::EXPIRY - 1, []);
        $this->assertSame(
            get_string('pending_action_expired', 'local_jedomi'),
            $service->resolve(self::CHAT, $actionid, confirmation_service::MODE_KEYWORD)
        );
        $this->assertSame(0, $DB->count_records($table));
    }

    /**
     * Cancelling removes the action without running it and records the cancellation with the channel name.
     *
     * @dataProvider table_provider
     * @param string $table
     */
    public function test_cancel_removes_the_action_and_audits_it(string $table): void {
        global $DB;
        $this->resetAfterTest();
        $agentid = $this->agent();
        $service = $this->service($table);
        $adminid = (int) get_admin()->id;
        $this->links[self::CHAT] = $adminid;
        $service->hold(self::CHAT, $adminid, 'delete_agent', ['id' => $agentid]);
        $actionid = $this->ui->shown[0][1]->actionid;

        $sink = $this->redirectEvents();
        $message = $service->resolve(self::CHAT, $actionid, confirmation_service::MODE_CANCEL);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame(get_string('confirm_cancelled', 'local_jedomi'), $message);
        $this->assertSame(0, $DB->count_records($table));
        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]));
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\local_jedomi\event\action_cancelled::class, $events[0]);
        $this->assertSame(['action' => 'delete_agent', 'channel' => 'testchannel'], $events[0]->other);
        $this->assertSame($adminid, (int) $events[0]->userid);
    }

    /**
     * Confirming runs the action once for the linked user, through the tool runner, and records the confirmation.
     *
     * @dataProvider table_provider
     * @param string $table
     */
    public function test_confirm_runs_the_action_once(string $table): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $agentid = $this->agent();
        $service = $this->service($table);
        $adminid = (int) get_admin()->id;
        $this->links[self::CHAT] = $adminid;
        $service->hold(self::CHAT, $adminid, 'delete_agent', ['id' => $agentid]);
        $actionid = $this->ui->shown[0][1]->actionid;

        $sink = $this->redirectEvents();
        $message = $service->resolve(self::CHAT, $actionid, confirmation_service::MODE_KEYWORD);
        $confirmed = array_values(array_filter($sink->get_events(), static fn($e): bool =>
            $e instanceof \local_jedomi\event\action_confirmed));
        $sink->close();

        $this->assertSame(get_string('confirm_done', 'local_jedomi'), $message);
        $this->assertFalse($DB->record_exists('local_jedomi_agents', ['id' => $agentid]));
        $this->assertSame($adminid, (int) $USER->id, 'The action runs as the linked user');
        $this->assertCount(1, $confirmed);
        $this->assertSame(['action' => 'delete_agent', 'channel' => 'testchannel'], $confirmed[0]->other);
        $this->assertSame(
            get_string('pending_action_not_found', 'local_jedomi'),
            $service->resolve(self::CHAT, $actionid, confirmation_service::MODE_KEYWORD)
        );
    }

    /**
     * Two answers to the same action that arrive together (a double press, a callback delivered twice) both read the
     * action before either removes it: only the first one runs it, the other is told the action is not there.
     *
     * The second answer runs to its end while the first one is between reading the action and removing it, which is
     * the order of two requests served at the same time.
     *
     * @dataProvider table_provider
     * @param string $table
     */
    public function test_concurrent_confirmations_run_the_action_once(string $table): void {
        global $DB;
        $this->resetAfterTest();
        $service = $this->service($table);
        $adminid = (int) get_admin()->id;
        $this->links[self::CHAT] = $adminid;
        $args = ['name' => 'Digest', 'prompt' => 'Summarise', 'schedule_cron' => '0 8 * * 1'];
        $service->hold(self::CHAT, $adminid, 'create_agent', $args);
        $actionid = $this->ui->shown[0][1]->actionid;
        $concurrent = null;
        $this->beforelookup = static function () use ($service, $actionid, &$concurrent): void {
            $concurrent = $service->resolve(self::CHAT, $actionid, confirmation_service::MODE_KEYWORD);
        };

        $first = $service->resolve(self::CHAT, $actionid, confirmation_service::MODE_KEYWORD);

        $this->assertSame(get_string('confirm_done', 'local_jedomi'), $concurrent);
        $this->assertSame(get_string('pending_action_not_found', 'local_jedomi'), $first);
        $this->assertSame(1, $DB->count_records('local_jedomi_agents', ['name' => 'Digest']), 'The action runs once');
        $this->assertSame(0, $DB->count_records($table));
    }

    /**
     * A cancel and a confirmation of the same action that arrive together: the cancel that removes the action first
     * wins, and the confirmation that read the action before it was removed does not run it.
     */
    public function test_confirmation_racing_a_cancel_does_not_run_the_action(): void {
        global $DB;
        $this->resetAfterTest();
        $service = $this->service(self::TELEGRAM_TABLE);
        $adminid = (int) get_admin()->id;
        $this->links[self::CHAT] = $adminid;
        $args = ['name' => 'Digest', 'prompt' => 'Summarise', 'schedule_cron' => '0 8 * * 1'];
        $service->hold(self::CHAT, $adminid, 'create_agent', $args);
        $actionid = $this->ui->shown[0][1]->actionid;
        $concurrent = null;
        $this->beforelookup = static function () use ($service, $actionid, &$concurrent): void {
            $concurrent = $service->resolve(self::CHAT, $actionid, confirmation_service::MODE_CANCEL);
        };

        $confirm = $service->resolve(self::CHAT, $actionid, confirmation_service::MODE_KEYWORD);

        $this->assertSame(get_string('confirm_cancelled', 'local_jedomi'), $concurrent);
        $this->assertSame(get_string('pending_action_not_found', 'local_jedomi'), $confirm);
        $this->assertSame(0, $DB->count_records('local_jedomi_agents', ['name' => 'Digest']));
        $this->assertSame(0, $DB->count_records(self::TELEGRAM_TABLE));
    }

    /**
     * An action held for one user never runs for another user linked to the chat later, nor without a link.
     */
    public function test_confirm_is_denied_to_another_or_no_linked_user(): void {
        global $DB;
        $this->resetAfterTest();
        $agentid = $this->agent();
        $service = $this->service(self::OTHER_TABLE);
        $other = $this->getDataGenerator()->create_user();
        $service->hold(self::CHAT, (int) get_admin()->id, 'delete_agent', ['id' => $agentid]);
        $first = $this->ui->shown[0][1]->actionid;
        $service->hold(self::CHAT, (int) get_admin()->id, 'delete_agent', ['id' => $agentid]);
        $second = $this->ui->shown[1][1]->actionid;

        $this->links[self::CHAT] = (int) $other->id;
        $this->assertSame(
            get_string('pending_action_denied', 'local_jedomi'),
            $service->resolve(self::CHAT, $first, confirmation_service::MODE_KEYWORD)
        );
        unset($this->links[self::CHAT]);
        $this->assertSame(
            get_string('pending_action_denied', 'local_jedomi'),
            $service->resolve(self::CHAT, $second, confirmation_service::MODE_KEYWORD)
        );
        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]));
        $this->assertSame(0, $DB->count_records(self::OTHER_TABLE), 'A denied action is removed');
    }

    /**
     * A user who needs a PIN and has none is refused and told how to set it; with a PIN the card asks for it and only
     * the PIN confirms; a wrong PIN is counted and a right one runs the action.
     */
    public function test_pin_rules_where_the_channel_confirms_with_a_pin(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $adminid = (int) get_admin()->id;
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $adminid, 'chatid' => self::CHAT,
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => time(), 'lastproactivesent' => 0]);
        $this->links[self::CHAT] = $adminid;
        $agentid = $this->agent();
        $service = $this->service(self::TELEGRAM_TABLE);

        $refused = json_decode((string) $service->hold(self::CHAT, $adminid, 'delete_agent', ['id' => $agentid]), true);
        $this->assertSame(['refused', 'pin_required'], [$refused['status'], $refused['reason']]);
        $this->assertStringContainsString('/local/jedomi/telegram_profile.php', $this->ui->notified[0][1]);
        $this->assertSame(0, $DB->count_records(self::TELEGRAM_TABLE));

        telegram_pin_service::set_pin($adminid, '246810');
        $held = json_decode((string) $service->hold(self::CHAT, $adminid, 'delete_agent', ['id' => $agentid]), true);
        $this->assertStringContainsString('type their PIN', $held['note']);
        $card = $this->ui->shown[0][1];
        $this->assertTrue($card->askpin);
        $this->assertSame('pin prompt', $service->resolve(self::CHAT, $card->actionid, confirmation_service::MODE_KEYWORD));

        $this->assertSame(
            get_string('telegram_pin_wrong', 'local_jedomi', 2),
            $service->confirm_with_pin(self::CHAT, $adminid, '000000', $card->actionid)
        );
        $this->assertTrue($DB->record_exists('local_jedomi_agents', ['id' => $agentid]));
        $this->assertSame(
            get_string('confirm_done', 'local_jedomi'),
            $service->confirm_with_pin(self::CHAT, $adminid, '246810', $card->actionid)
        );
        $this->assertFalse($DB->record_exists('local_jedomi_agents', ['id' => $agentid]));
    }

    /**
     * A channel that cannot ask for a PIN refuses the writes of a user who needs one, without holding them.
     */
    public function test_channel_without_pin_refuses_writes_that_need_a_pin(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('telegram_require_pin', 1, 'local_jedomi');
        $service = $this->service(self::OTHER_TABLE, 3000, false);

        $refused = json_decode((string) $service->hold(self::CHAT, (int) get_admin()->id, 'delete_agent', ['id' => 1]), true);

        $this->assertSame(['refused', 'pin_unsupported'], [$refused['status'], $refused['reason']]);
        $this->assertStringContainsString('web chat', $refused['note']);
        $this->assertSame(0, $DB->count_records(self::OTHER_TABLE));
        $this->assertSame([], $this->ui->shown);

        // A user who needs no PIN holds the write as usual.
        set_config('telegram_require_pin', 0, 'local_jedomi');
        $held = json_decode((string) $service->hold(self::CHAT, (int) get_admin()->id, 'delete_agent', ['id' => 1]), true);
        $this->assertSame('awaiting_confirmation', $held['status']);
    }

    /**
     * Build a service over the given table with a recording confirmation_ui.
     *
     * @param string $table Table of held actions.
     * @param int $detailslength Characters of the data the channel shows whole.
     * @param bool $pinconfirmation Whether the channel can confirm with a PIN.
     * @return confirmation_service
     */
    private function service(string $table, int $detailslength = 3000, bool $pinconfirmation = true): confirmation_service {
        if ($table === self::OTHER_TABLE) {
            $this->create_other_table();
        }
        $this->ui = new class ($detailslength) implements confirmation_ui {
            /** @var array[] Cards shown, as [chatkey, card]. */
            public array $shown = [];

            /** @var array[] Messages sent, as [chatkey, message]. */
            public array $notified = [];

            /**
             * Constructor.
             *
             * @param int $detailslength Characters of the data shown whole.
             */
            public function __construct(
                /** @var int Characters of the data shown whole. */
                private readonly int $detailslength,
            ) {
            }

            #[\Override]
            public function details_length(): int {
                return $this->detailslength;
            }

            #[\Override]
            public function show(string $chatkey, confirmation_card $card): void {
                $this->shown[] = [$chatkey, $card];
            }

            #[\Override]
            public function notify(string $chatkey, string $message): void {
                $this->notified[] = [$chatkey, $message];
            }

            #[\Override]
            public function parse_reply(string $payload): ?confirmation_reply {
                return null;
            }

            #[\Override]
            public function keyword_prompt(): string {
                return 'keyword prompt';
            }

            #[\Override]
            public function pin_prompt(): string {
                return 'pin prompt';
            }
        };
        $links = &$this->links;
        $beforelookup = &$this->beforelookup;
        return new confirmation_service(
            $table,
            'Test channel',
            $this->ui,
            new access_guard('testchannel'),
            new tool_runner('testchannel', 'Test channel', ['create_agent', 'delete_agent', 'list_agents']),
            static function (string $chatkey) use (&$links, &$beforelookup): ?int {
                if ($beforelookup !== null) {
                    $run = $beforelookup;
                    $beforelookup = null;
                    $run();
                }
                return $links[$chatkey] ?? null;
            },
            $pinconfirmation
        );
    }

    /**
     * Create an agent owned by a new user.
     *
     * @return int Agent id.
     */
    private function agent(): int {
        $owner = $this->getDataGenerator()->create_user();
        return \local_jedomi\agent\agent_manager::create_agent((object) ['userid' => $owner->id, 'name' => 'Report',
            'prompt' => 'List the students at risk', 'schedule_cron' => '0 8 * * 1', 'context_type' => 'site']);
    }

    /**
     * Records of a table, or 0 when the table does not exist.
     *
     * @param string $table
     * @return int
     */
    private function count_if_exists(string $table): int {
        global $DB;
        return $DB->get_manager()->table_exists($table) ? $DB->count_records($table) : 0;
    }

    /**
     * Create a temporary table with the shape of local_jedomi_telegram_pending_actions, unless it already exists.
     */
    private function create_other_table(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::OTHER_TABLE);
        if ($dbman->table_exists($table)) {
            return;
        }
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('callbackid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('chatid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL);
        $table->add_field('actionjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('callbackid', XMLDB_KEY_UNIQUE, ['callbackid']);
        $dbman->create_temp_table($table);
    }
}
