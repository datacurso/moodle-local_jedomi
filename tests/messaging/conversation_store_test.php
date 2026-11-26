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

use local_jedomi\telegram\session;

/**
 * Tests for the channel-neutral conversation store.
 *
 * Each channel gives the store its own table (same shape as local_jedomi_telegram_sessions) and its own max turns
 * setting. The tests run against the Telegram table and against a temporary table created here, which stands in for
 * the table of another channel, so no schema change is needed to prove the store is not tied to Telegram.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\conversation_store
 * @covers     \local_jedomi\telegram\session
 */
final class conversation_store_test extends \advanced_testcase {
    /** @var string Telegram session table. */
    private const TELEGRAM_TABLE = 'local_jedomi_telegram_sessions';

    /** @var string Temporary table standing in for the session table of another channel. */
    private const OTHER_TABLE = 'local_jedomi_test_conv_store';

    #[\Override]
    protected function tearDown(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::OTHER_TABLE);
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        parent::tearDown();
    }

    /**
     * Store configurations: the Telegram table and another table, each with its own max turns setting.
     *
     * @return array
     */
    public static function store_provider(): array {
        return [
            'telegram table' => [self::TELEGRAM_TABLE, 'telegram_max_turns'],
            'other table' => [self::OTHER_TABLE, 'test_channel_max_turns'],
        ];
    }

    /**
     * Messages are kept per chat key in the given table and trimmed to the turns of the given setting.
     *
     * @dataProvider store_provider
     * @param string $table
     * @param string $setting
     */
    public function test_append_keeps_the_last_configured_turns(string $table, string $setting): void {
        $this->resetAfterTest();
        $store = $this->store($table, $setting);
        set_config($setting, 2, 'local_jedomi');

        $store->append('chat_a', 'user', 'one');
        $store->append('chat_a', 'assistant', 'two');
        $store->append('chat_a', 'user', 'three');
        $store->append('chat_b', 'user', 'other chat');

        $this->assertSame([
            ['role' => 'assistant', 'content' => 'two'],
            ['role' => 'user', 'content' => 'three'],
        ], $store->get('chat_a'));
        $this->assertSame([['role' => 'user', 'content' => 'other chat']], $store->get('chat_b'));
        $this->assertSame([], $store->get('unknown_chat'));
    }

    /**
     * Without the setting the store keeps 10 turns; out of range values are clamped to 1..100.
     *
     * @dataProvider store_provider
     * @param string $table
     * @param string $setting
     */
    public function test_max_turns_default_and_clamp(string $table, string $setting): void {
        $this->resetAfterTest();
        $store = $this->store($table, $setting);

        unset_config($setting, 'local_jedomi');
        for ($i = 1; $i <= 11; $i++) {
            $store->append('default', 'user', "msg{$i}");
        }
        $this->assertCount(10, $store->get('default'));
        $this->assertSame('msg2', $store->get('default')[0]['content']);

        set_config($setting, 0, 'local_jedomi');
        $store->append('low', 'user', 'first');
        $store->append('low', 'user', 'second');
        $this->assertSame([['role' => 'user', 'content' => 'second']], $store->get('low'));

        set_config($setting, 999, 'local_jedomi');
        for ($i = 1; $i <= 101; $i++) {
            $store->append('high', 'user', "msg{$i}");
        }
        $this->assertCount(100, $store->get('high'));
    }

    /**
     * The max turns setting of one store does not affect a store with another setting.
     */
    public function test_each_store_reads_its_own_max_turns_setting(): void {
        $this->resetAfterTest();
        $telegram = $this->store(self::TELEGRAM_TABLE, 'telegram_max_turns');
        $other = $this->store(self::OTHER_TABLE, 'test_channel_max_turns');
        set_config('telegram_max_turns', 1, 'local_jedomi');
        set_config('test_channel_max_turns', 3, 'local_jedomi');

        foreach (['a', 'b', 'c'] as $content) {
            $telegram->append('chat', 'user', $content);
            $other->append('chat', 'user', $content);
        }

        $this->assertCount(1, $telegram->get('chat'));
        $this->assertCount(3, $other->get('chat'));
    }

    /**
     * Writes through a store land only in its table: Telegram data is untouched by another channel.
     */
    public function test_writes_stay_in_the_store_table(): void {
        global $DB;
        $this->resetAfterTest();
        $other = $this->store(self::OTHER_TABLE, 'test_channel_max_turns');

        $other->append('wa_573001112233', 'user', 'hello');
        $other->set_context('wa_573001112233', ['iteration' => 1, 'step' => 'tools']);

        $this->assertSame(1, $DB->count_records(self::OTHER_TABLE));
        $this->assertSame(0, $DB->count_records(self::TELEGRAM_TABLE));
        $this->assertSame([], session::get('wa_573001112233'));
        $this->assertNull(session::get_context('wa_573001112233'));

        session::append('wa_573001112233', 'user', 'telegram text');
        $this->assertSame([['role' => 'user', 'content' => 'hello']], $other->get('wa_573001112233'));
    }

    /**
     * Context is saved per chat key; stale iterations are skipped and iteration 0 always overwrites.
     *
     * @dataProvider store_provider
     * @param string $table
     * @param string $setting
     */
    public function test_context_uses_optimistic_iteration_guard(string $table, string $setting): void {
        $this->resetAfterTest();
        $store = $this->store($table, $setting);

        $this->assertNull($store->get_context('chat'));

        $store->set_context('chat', ['iteration' => 3, 'state' => 'third']);
        $this->assertSame(['iteration' => 3, 'state' => 'third'], $store->get_context('chat'));
        $this->assertSame([], $store->get('chat'), 'A context-only row has no messages');

        $store->set_context('chat', ['iteration' => 2, 'state' => 'stale']);
        $store->set_context('chat', ['iteration' => 3, 'state' => 'same iteration']);
        $this->assertSame(['iteration' => 3, 'state' => 'third'], $store->get_context('chat'));

        $store->set_context('chat', ['iteration' => 4, 'state' => 'newer']);
        $this->assertSame(['iteration' => 4, 'state' => 'newer'], $store->get_context('chat'));

        $store->set_context('chat', ['iteration' => 0, 'state' => 'reset']);
        $this->assertSame(['iteration' => 0, 'state' => 'reset'], $store->get_context('chat'));
    }

    /**
     * Clearing the context keeps the history; clearing the session removes both.
     *
     * @dataProvider store_provider
     * @param string $table
     * @param string $setting
     */
    public function test_clear_context_and_clear(string $table, string $setting): void {
        $this->resetAfterTest();
        $store = $this->store($table, $setting);
        $store->append('chat', 'user', 'hello');
        $store->set_context('chat', ['iteration' => 1]);
        $store->append('keep', 'user', 'kept');

        $store->clear_context('chat');
        $this->assertNull($store->get_context('chat'));
        $this->assertSame([['role' => 'user', 'content' => 'hello']], $store->get('chat'));

        $store->clear('chat');
        $this->assertSame([], $store->get('chat'));
        $this->assertSame([['role' => 'user', 'content' => 'kept']], $store->get('keep'));

        $store->clear_context('missing');
        $store->clear('missing');
        $this->assertNull($store->get_context('missing'));
    }

    /**
     * Telegram sessions use the store over the Telegram table and the Telegram max turns setting.
     */
    public function test_telegram_session_store_uses_the_telegram_table(): void {
        $this->resetAfterTest();
        set_config('telegram_max_turns', 1, 'local_jedomi');

        session::store()->append('777', 'user', 'first');
        session::append('777', 'user', 'second');

        $this->assertSame([['role' => 'user', 'content' => 'second']], session::store()->get('777'));
        $this->assertSame(session::get('777'), session::store()->get('777'));
    }

    /**
     * Build a store over the given table, creating the temporary table for the other channel when needed.
     *
     * @param string $table
     * @param string $setting
     * @return conversation_store
     */
    private function store(string $table, string $setting): conversation_store {
        if ($table === self::OTHER_TABLE) {
            $this->create_other_table();
        }
        return new conversation_store($table, $setting);
    }

    /**
     * Create a temporary table with the shape of local_jedomi_telegram_sessions, unless it already exists.
     */
    private function create_other_table(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table(self::OTHER_TABLE);
        if ($dbman->table_exists($table)) {
            return;
        }
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('chatid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL);
        $table->add_field('messages', XMLDB_TYPE_TEXT);
        $table->add_field('usercontext', XMLDB_TYPE_TEXT);
        $table->add_field('iteration', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('lastactivity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('chatid', XMLDB_KEY_UNIQUE, ['chatid']);
        $dbman->create_temp_table($table);
    }
}
