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
 * Test double for a messaging channel that is not Telegram.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

use local_jedomi\local\service\telegram_pin_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_transport.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_continuation.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_media_source.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/neutral_chat_commands.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/neutral_confirmation_ui.php');

/**
 * A channel built only from the channel-neutral core, as a WhatsApp channel would be: its own temporary tables for the
 * conversations, the message limit and the held actions, the testchannel_* settings, links kept in $links (no Telegram
 * link table), recording transport, continuation, media source and confirmation UI, and no PIN (no_pin_policy with the
 * role rule of the users). Nothing of it reaches Telegram. Its messages are the keys themselves.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class neutral_channel implements channel {
    /** @var string Channel name, the prefix of its settings. */
    public const NAME = 'testchannel';

    /** @var string Temporary table of the conversations. */
    public const SESSIONS_TABLE = 'local_jedomi_nc_sessions';

    /** @var string Temporary table of the message limit. */
    public const RATE_TABLE = 'local_jedomi_nc_rate';

    /** @var string Temporary table of the held actions. */
    public const PENDING_TABLE = 'local_jedomi_nc_pending';

    /** @var int[] User linked to each chat key. */
    public array $links = [];

    /** @var fake_transport Recording transport. */
    public fake_transport $transport;

    /** @var fake_continuation Recording continuation. */
    public fake_continuation $continuation;

    /** @var fake_media_source Media source without downloads. */
    public fake_media_source $mediasource;

    /** @var neutral_confirmation_ui Recording confirmation UI. */
    public neutral_confirmation_ui $ui;

    /** @var neutral_chat_commands Recording commands. */
    public neutral_chat_commands $commands;

    /**
     * Constructor: creates the temporary tables of the channel and turns the channel on.
     */
    public function __construct() {
        self::create_tables();
        set_config(self::NAME . '_enabled', 1, 'local_jedomi');
        $this->transport = new fake_transport();
        $this->continuation = new fake_continuation();
        $this->mediasource = new fake_media_source();
        $this->ui = new neutral_confirmation_ui();
        $this->commands = new neutral_chat_commands(fn(string $chatkey): ?int => $this->linked_userid($chatkey));
    }

    /**
     * Drop the temporary tables of the channel. Call it from tearDown().
     *
     * @return void
     */
    public static function drop_tables(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach ([self::SESSIONS_TABLE, self::RATE_TABLE, self::PENDING_TABLE] as $name) {
            $table = new \xmldb_table($name);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }
    }

    #[\Override]
    public function name(): string {
        return self::NAME;
    }

    #[\Override]
    public function label(): string {
        return 'Test channel';
    }

    #[\Override]
    public function store(): conversation_store {
        return new conversation_store(self::SESSIONS_TABLE, self::NAME . '_max_turns');
    }

    #[\Override]
    public function access_guard(): access_guard {
        return new access_guard(self::NAME);
    }

    #[\Override]
    public function rate_limiter(): rate_limiter {
        return new rate_limiter(self::RATE_TABLE, self::NAME . '_rate_limit', self::NAME . '_cooldown', '[TEST RATE]');
    }

    #[\Override]
    public function commands(): chat_commands {
        return $this->commands;
    }

    #[\Override]
    public function tool_runner(): tool_runner {
        return new tool_runner(self::NAME, $this->label(), ['create_agent', 'delete_agent', 'list_agents', 'my_courses']);
    }

    #[\Override]
    public function confirmation_service(): confirmation_service {
        return new confirmation_service(
            self::PENDING_TABLE,
            $this->label(),
            $this->ui,
            $this->access_guard(),
            $this->tool_runner(),
            fn(string $chatkey): ?int => $this->linked_userid($chatkey),
            // Who needs a PIN is the rule of the user, in every channel; this channel cannot ask for it.
            new no_pin_policy(static fn(int $userid): bool => telegram_pin_service::is_required($userid))
        );
    }

    #[\Override]
    public function transport(): transport {
        return $this->transport;
    }

    #[\Override]
    public function media_source(): media_source {
        return $this->mediasource;
    }

    #[\Override]
    public function continuation(): continuation {
        return $this->continuation;
    }

    #[\Override]
    public function limits(): channel_limits {
        return new class implements channel_limits {
            #[\Override]
            public function format_rules(string $userlang): string {
                return 'Plain text only.';
            }

            #[\Override]
            public function progress_messages(): bool {
                return true;
            }
        };
    }

    #[\Override]
    public function ai_timeout(): int {
        return 50;
    }

    #[\Override]
    public function activity_interval(): int {
        return 5;
    }

    #[\Override]
    public function activity_timeout(): int {
        return 3;
    }

    #[\Override]
    public function message(string $key, mixed $a = null): string {
        return $key;
    }

    #[\Override]
    public function linked_userid(string $chatkey): ?int {
        return $this->links[$chatkey] ?? null;
    }

    #[\Override]
    public function serves(string $chatkey, int $userid): bool {
        return $this->linked_userid($chatkey) === $userid && $this->access_guard()->served_user($userid) !== null;
    }

    /**
     * Create the temporary tables of the channel, with the shapes of the Telegram ones, unless they exist.
     *
     * @return void
     */
    private static function create_tables(): void {
        global $DB;
        $dbman = $DB->get_manager();

        $sessions = new \xmldb_table(self::SESSIONS_TABLE);
        if (!$dbman->table_exists($sessions)) {
            $sessions->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $sessions->add_field('chatid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL);
            $sessions->add_field('messages', XMLDB_TYPE_TEXT);
            $sessions->add_field('usercontext', XMLDB_TYPE_TEXT);
            $sessions->add_field('iteration', XMLDB_TYPE_INTEGER, '10');
            $sessions->add_field('lastactivity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $sessions->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $sessions->add_key('chatid', XMLDB_KEY_UNIQUE, ['chatid']);
            $dbman->create_temp_table($sessions);
        }

        $rate = new \xmldb_table(self::RATE_TABLE);
        if (!$dbman->table_exists($rate)) {
            $rate->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $rate->add_field('chatid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL);
            $rate->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rate->add_field('msgcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rate->add_field('blockeduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $rate->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $rate->add_key('chatid', XMLDB_KEY_UNIQUE, ['chatid']);
            $dbman->create_temp_table($rate);
        }

        $pending = new \xmldb_table(self::PENDING_TABLE);
        if (!$dbman->table_exists($pending)) {
            $pending->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $pending->add_field('callbackid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $pending->add_field('chatid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL);
            $pending->add_field('actionjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $pending->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $pending->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $pending->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $pending->add_key('callbackid', XMLDB_KEY_UNIQUE, ['callbackid']);
            $dbman->create_temp_table($pending);
        }
    }
}
