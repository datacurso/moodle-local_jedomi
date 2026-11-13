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

namespace local_jedomi\task;

use local_jedomi\local\service\pending_action_service;

/**
 * Tests for the cleanup of Telegram conversations and expired actions.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\task\cleanup_telegram_sessions
 */
final class cleanup_telegram_sessions_test extends \advanced_testcase {
    /** @var int Inactivity after which a Telegram conversation is removed. */
    private const SESSION_TTL = 30 * 60;

    /** @var int Validity of a Telegram pending action. */
    private const TELEGRAM_ACTION_TTL = 5 * 60;

    /**
     * Run the cleanup task.
     *
     * @return string Task output.
     */
    private function run_cleanup(): string {
        ob_start();
        (new cleanup_telegram_sessions())->execute();
        return ob_get_clean();
    }

    /**
     * Store a Telegram conversation last active at a given time.
     *
     * @param string $chatid Chat id.
     * @param int $lastactivity Last activity.
     * @return void
     */
    private function conversation(string $chatid, int $lastactivity): void {
        global $DB;
        $DB->insert_record('local_jedomi_telegram_sessions', (object) ['chatid' => $chatid,
            'messages' => json_encode([['role' => 'user', 'content' => 'Hi']]), 'usercontext' => null,
            'lastactivity' => $lastactivity]);
    }

    /**
     * Store a Telegram pending action created at a given time.
     *
     * @param string $callbackid Callback id.
     * @param int $timecreated Creation time.
     * @return void
     */
    private function telegram_action(string $callbackid, int $timecreated): void {
        global $DB;
        $DB->insert_record('local_jedomi_telegram_pending_actions', (object) ['callbackid' => $callbackid,
            'chatid' => '775000001', 'actionjson' => '{"type":"cache_purge"}', 'timecreated' => $timecreated]);
    }

    /**
     * MDL-INT-084: the cleanup removes the Telegram conversations inactive beyond the defined time and keeps the others,
     * including the one exactly at the limit.
     */
    public function test_inactive_telegram_conversations_are_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $this->conversation('775000010', $now - self::SESSION_TTL - 60);
        $this->conversation('775000011', $now - self::SESSION_TTL - 1);
        $this->conversation('775000012', $now - self::SESSION_TTL + 60);
        $this->conversation('775000013', $now);

        $this->run_cleanup();

        $left = $DB->get_fieldset_select('local_jedomi_telegram_sessions', 'chatid', '1 = 1');
        sort($left);
        $this->assertSame(['775000012', '775000013'], $left);
    }

    /**
     * MDL-INT-084: the cleanup removes only the expired pending actions of Telegram and of the web chat and keeps the
     * valid ones.
     */
    public function test_only_expired_pending_actions_are_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $this->telegram_action('expired', $now - self::TELEGRAM_ACTION_TTL - 60);
        $this->telegram_action('valid', $now - self::TELEGRAM_ACTION_TTL + 60);
        $user = $this->getDataGenerator()->create_user();
        $expired = pending_action_service::hold((int) $user->id, 'send_messages', ['userids' => [2], 'message' => 'Old']);
        $valid = pending_action_service::hold((int) $user->id, 'send_messages', ['userids' => [2], 'message' => 'New']);
        $DB->set_field('local_jedomi_pending_actions', 'timecreated', $now - DAYSECS, ['id' => $expired['id']]);

        $this->run_cleanup();

        $this->assertSame(['valid'], array_values($DB->get_fieldset_select(
            'local_jedomi_telegram_pending_actions',
            'callbackid',
            '1 = 1'
        )));
        $this->assertSame([(int) $valid['id']], array_map('intval', $DB->get_fieldset_select(
            'local_jedomi_pending_actions',
            'id',
            '1 = 1'
        )));
    }

    /**
     * MDL-INT-085: the cleanup removes the old message limit records, keeps the recent and the blocked ones, and reports
     * the real amounts.
     */
    public function test_old_message_limit_records_are_removed_and_reported(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $old = $now - cleanup_telegram_sessions::RATE_LIMIT_TTL - 60;
        $limits = [
            '775000020' => [$old, 0],
            '775000021' => [$old, $now - 60],
            '775000022' => [$now - 30, 0],
            '775000023' => [$old, $now + HOURSECS],
        ];
        foreach ($limits as $chatid => [$windowstart, $blockeduntil]) {
            $DB->insert_record('local_jedomi_telegram_rate_limit', (object) ['chatid' => (string) $chatid,
                'windowstart' => $windowstart, 'msgcount' => 3, 'blockeduntil' => $blockeduntil]);
        }
        $this->conversation('775000030', $now - self::SESSION_TTL - 60);
        $this->conversation('775000031', $now - self::SESSION_TTL - 60);
        $this->conversation('775000032', $now);
        $this->telegram_action('expired-one', $now - self::TELEGRAM_ACTION_TTL - 60);

        $output = $this->run_cleanup();

        $left = $DB->get_fieldset_select('local_jedomi_telegram_rate_limit', 'chatid', '1 = 1');
        sort($left);
        $this->assertSame(['775000022', '775000023'], $left, 'Recent and blocked records are kept');
        $this->assertStringContainsString('Deleted 2 expired Telegram message limit record(s).', $output);
        $this->assertStringContainsString('Deleted 2 expired Telegram session(s).', $output);
        $this->assertStringContainsString('Deleted 1 expired pending action(s).', $output);
        $this->assertStringContainsString('Deleted 0 expired web chat pending action(s).', $output);
    }
}
