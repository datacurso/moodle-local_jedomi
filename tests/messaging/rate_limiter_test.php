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

use local_jedomi\telegram\manager;

/**
 * Tests for the channel-neutral rate limiter.
 *
 * Each channel gives the limiter its own table (same shape as local_jedomi_telegram_rate_limit) and its own limit and
 * cooldown settings. The tests run against the Telegram table and against a temporary table created here, which stands
 * in for the table of another channel, so no schema change is needed to prove the limiter is not tied to Telegram.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\messaging\rate_limiter
 * @covers     \local_jedomi\telegram\manager
 */
final class rate_limiter_test extends \advanced_testcase {
    /** @var string Telegram rate limit table. */
    private const TELEGRAM_TABLE = 'local_jedomi_telegram_rate_limit';

    /** @var string Temporary table standing in for the rate limit table of another channel. */
    private const OTHER_TABLE = 'local_jedomi_test_rate_limiter';

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
     * Limiter configurations: the Telegram table and another table, each with its own settings.
     *
     * @return array
     */
    public static function limiter_provider(): array {
        return [
            'telegram table' => [self::TELEGRAM_TABLE, 'telegram_rate_limit', 'telegram_cooldown'],
            'other table' => [self::OTHER_TABLE, 'test_channel_rate_limit', 'test_channel_cooldown'],
        ];
    }

    /**
     * The messages within the limit pass; the one over it blocks the chat for the cooldown, other chats are not affected.
     *
     * @dataProvider limiter_provider
     * @param string $table
     * @param string $limitsetting
     * @param string $cooldownsetting
     */
    public function test_blocks_the_chat_over_the_limit_for_the_cooldown(
        string $table,
        string $limitsetting,
        string $cooldownsetting
    ): void {
        global $DB;
        $this->resetAfterTest();
        $limiter = $this->limiter($table, $limitsetting, $cooldownsetting);
        set_config($limitsetting, 2, 'local_jedomi');
        set_config($cooldownsetting, 3, 'local_jedomi');

        $this->assertFalse($limiter->check_and_record('chat_a'));
        $this->assertFalse($limiter->check_and_record('chat_a'));
        $before = time();
        $this->assertTrue($limiter->check_and_record('chat_a'), 'The message over the limit is rejected');
        $this->assertTrue($limiter->check_and_record('chat_a'), 'The chat stays blocked');
        $this->assertFalse($limiter->check_and_record('chat_b'), 'Another chat has its own window');

        $blockeduntil = (int) $DB->get_field($table, 'blockeduntil', ['chatid' => 'chat_a']);
        $this->assertGreaterThanOrEqual($before + 180, $blockeduntil);
        $this->assertLessThanOrEqual(time() + 180, $blockeduntil);
    }

    /**
     * An expired window starts again with the message; an expired block lets the chat send again.
     *
     * @dataProvider limiter_provider
     * @param string $table
     * @param string $limitsetting
     * @param string $cooldownsetting
     */
    public function test_expired_window_and_block_start_again(
        string $table,
        string $limitsetting,
        string $cooldownsetting
    ): void {
        global $DB;
        $this->resetAfterTest();
        $limiter = $this->limiter($table, $limitsetting, $cooldownsetting);
        set_config($limitsetting, 1, 'local_jedomi');

        $this->assertFalse($limiter->check_and_record('chat_a'));
        $this->assertTrue($limiter->check_and_record('chat_a'));
        $DB->set_field($table, 'blockeduntil', time() - 1, ['chatid' => 'chat_a']);
        $DB->set_field($table, 'windowstart', time() - 120, ['chatid' => 'chat_a']);

        $this->assertFalse($limiter->check_and_record('chat_a'), 'The block and the window expired');
        $record = $DB->get_record($table, ['chatid' => 'chat_a']);
        $this->assertSame(1, (int) $record->msgcount);
        $this->assertSame(0, (int) $record->blockeduntil);
    }

    /**
     * A limit of 0 means no limit: nothing is recorded.
     *
     * @dataProvider limiter_provider
     * @param string $table
     * @param string $limitsetting
     * @param string $cooldownsetting
     */
    public function test_zero_limit_is_unlimited(string $table, string $limitsetting, string $cooldownsetting): void {
        global $DB;
        $this->resetAfterTest();
        $limiter = $this->limiter($table, $limitsetting, $cooldownsetting);
        set_config($limitsetting, 0, 'local_jedomi');

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($limiter->check_and_record('chat_a'));
        }
        $this->assertFalse($DB->record_exists($table, ['chatid' => 'chat_a']));
    }

    /**
     * Without saved settings the limit is 30 messages per minute and the cooldown 5 minutes.
     *
     * @dataProvider limiter_provider
     * @param string $table
     * @param string $limitsetting
     * @param string $cooldownsetting
     */
    public function test_defaults_when_settings_are_not_saved(
        string $table,
        string $limitsetting,
        string $cooldownsetting
    ): void {
        $this->resetAfterTest();
        $limiter = $this->limiter($table, $limitsetting, $cooldownsetting);
        unset_config($limitsetting, 'local_jedomi');
        unset_config($cooldownsetting, 'local_jedomi');
        $this->assertSame(30, $limiter->limit());
        $this->assertSame(5, $limiter->cooldown_minutes());

        set_config($limitsetting, '', 'local_jedomi');
        set_config($cooldownsetting, '', 'local_jedomi');
        $this->assertSame(30, $limiter->limit());
        $this->assertSame(5, $limiter->cooldown_minutes());

        set_config($limitsetting, 7, 'local_jedomi');
        set_config($cooldownsetting, 2, 'local_jedomi');
        $this->assertSame(7, $limiter->limit());
        $this->assertSame(2, $limiter->cooldown_minutes());
    }

    /**
     * Each limiter reads its own settings and writes only to its own table.
     */
    public function test_each_limiter_uses_its_own_settings_and_table(): void {
        global $DB;
        $this->resetAfterTest();
        $telegram = $this->limiter(self::TELEGRAM_TABLE, 'telegram_rate_limit', 'telegram_cooldown');
        $other = $this->limiter(self::OTHER_TABLE, 'test_channel_rate_limit', 'test_channel_cooldown');
        set_config('telegram_rate_limit', 1, 'local_jedomi');
        set_config('test_channel_rate_limit', 0, 'local_jedomi');

        $this->assertFalse($other->check_and_record('chat_a'));
        $this->assertFalse($other->check_and_record('chat_a'), 'The other limiter is unlimited');
        $this->assertFalse($telegram->check_and_record('chat_a'));
        $this->assertTrue($telegram->check_and_record('chat_a'), 'The Telegram limiter allows one message');

        $this->assertSame(0, $DB->count_records(self::OTHER_TABLE));
        $this->assertSame(1, $DB->count_records(self::TELEGRAM_TABLE));
    }

    /**
     * A database failure is reported as null, not as allowed or blocked.
     */
    public function test_database_failure_returns_null(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/failing_table_database.php');
        $this->resetAfterTest();
        set_config('telegram_rate_limit', 5, 'local_jedomi');
        $limiter = $this->limiter(self::TELEGRAM_TABLE, 'telegram_rate_limit', 'telegram_cooldown');
        $realdb = $DB;

        try {
            $DB = new \local_jedomi\failing_table_database($realdb, self::TELEGRAM_TABLE);
            $result = $limiter->check_and_record('chat_a');
        } finally {
            $DB = $realdb;
        }

        $this->assertNull($result);
        $this->assertFalse($limiter->check_and_record('chat_a'), 'The limiter works again once the database recovers');
    }

    /**
     * The Telegram limiter works on the Telegram table and the Telegram settings, as the manager reports them.
     */
    public function test_telegram_limiter_uses_the_telegram_table_and_settings(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('telegram_rate_limit', 4, 'local_jedomi');
        set_config('telegram_cooldown', 9, 'local_jedomi');

        $limiter = manager::rate_limiter();
        $this->assertSame(4, $limiter->limit());
        $this->assertSame(9, $limiter->cooldown_minutes());
        $this->assertSame(manager::get_rate_limit(), $limiter->limit());
        $this->assertSame(manager::get_cooldown(), $limiter->cooldown_minutes());

        $limiter->check_and_record('123');
        $this->assertTrue($DB->record_exists(self::TELEGRAM_TABLE, ['chatid' => '123']));
    }

    /**
     * A limiter on the given table, creating the temporary table when needed.
     *
     * @param string $table
     * @param string $limitsetting
     * @param string $cooldownsetting
     * @return rate_limiter
     */
    private function limiter(string $table, string $limitsetting, string $cooldownsetting): rate_limiter {
        if ($table === self::OTHER_TABLE) {
            $this->create_other_table();
        }
        return new rate_limiter($table, $limitsetting, $cooldownsetting, '[TEST RATE]');
    }

    /**
     * Create a temporary table with the shape of local_jedomi_telegram_rate_limit, unless it already exists.
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
        $table->add_field('windowstart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('msgcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('blockeduntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('chatid', XMLDB_KEY_UNIQUE, ['chatid']);
        $dbman->create_temp_table($table);
    }
}
