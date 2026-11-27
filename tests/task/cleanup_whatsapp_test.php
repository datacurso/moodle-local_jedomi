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

namespace local_jedomi\task;

/**
 * Tests for the cleanup of the WhatsApp tables.
 *
 * Every test freezes the Moodle clock, so the boundaries are exact.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\task\cleanup_whatsapp
 * @covers     \local_jedomi\local\service\whatsapp_link_service
 */
final class cleanup_whatsapp_test extends \advanced_testcase {
    /** @var int Frozen time of the tests. */
    private const NOW = 1798000000;

    /**
     * Run the cleanup task.
     *
     * @return string Task output.
     */
    private function run_cleanup(): string {
        ob_start();
        (new cleanup_whatsapp())->execute();
        return ob_get_clean();
    }

    /**
     * Ids of a column left in a table, sorted.
     *
     * @param string $table Table.
     * @param string $field Column.
     * @return string[]
     */
    private static function left(string $table, string $field): array {
        global $DB;
        $values = array_values(array_map('strval', $DB->get_fieldset_select($table, $field, '1 = 1')));
        sort($values);
        return $values;
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->mock_clock_with_frozen(self::NOW);
    }

    /**
     * The task has a name and is scheduled.
     */
    public function test_the_task_is_registered(): void {
        $this->assertNotEmpty((new cleanup_whatsapp())->get_name());
        $this->assertNotNull(\core\task\manager::get_scheduled_task(cleanup_whatsapp::class));
    }

    /**
     * Conversations idle for more than 30 minutes are removed; the one exactly at the limit is kept.
     */
    public function test_idle_conversations_are_removed(): void {
        global $DB;
        foreach (['wa_US.1' => 60, 'wa_US.2' => 1, 'wa_US.3' => 0, 'wa_US.4' => -60] as $chatid => $beyond) {
            $DB->insert_record('local_jedomi_whatsapp_sessions', (object) ['chatid' => $chatid, 'messages' => '[]',
                'lastactivity' => self::NOW - cleanup_whatsapp::SESSION_TTL - $beyond]);
        }

        $output = $this->run_cleanup();

        $this->assertSame(['wa_US.3', 'wa_US.4'], self::left('local_jedomi_whatsapp_sessions', 'chatid'));
        $this->assertStringContainsString('Deleted 2 expired WhatsApp session(s).', $output);
    }

    /**
     * Held actions older than 5 minutes are removed, whatever their id (also the ones a request claimed); the one at the
     * limit is kept.
     */
    public function test_expired_held_actions_are_removed_even_when_claimed(): void {
        global $DB;
        $actions = [
            'expired' => cleanup_whatsapp::ACTION_TTL + 1,
            'claimed:abc' => cleanup_whatsapp::ACTION_TTL + 60,
            'limit' => cleanup_whatsapp::ACTION_TTL,
            'valid' => 60,
        ];
        foreach ($actions as $callbackid => $age) {
            $DB->insert_record('local_jedomi_whatsapp_pending', (object) ['callbackid' => $callbackid, 'chatid' => 'wa_US.1',
                'actionjson' => '{}', 'courseid' => 0, 'timecreated' => self::NOW - $age]);
        }

        $output = $this->run_cleanup();

        $this->assertSame(['limit', 'valid'], self::left('local_jedomi_whatsapp_pending', 'callbackid'));
        $this->assertStringContainsString('Deleted 2 expired WhatsApp held action(s).', $output);
    }

    /**
     * Message limit records whose window started over an hour ago are removed unless they still block their chat.
     */
    public function test_old_message_limit_records_are_removed_unless_blocking(): void {
        global $DB;
        $old = self::NOW - cleanup_whatsapp::RATE_LIMIT_TTL - 1;
        $limits = [
            'wa_US.1' => [$old, 0],
            'wa_US.2' => [$old, self::NOW - 1],
            'wa_US.3' => [self::NOW - cleanup_whatsapp::RATE_LIMIT_TTL, 0],
            'wa_US.4' => [$old, self::NOW + 60],
        ];
        foreach ($limits as $chatid => [$windowstart, $blockeduntil]) {
            $DB->insert_record('local_jedomi_whatsapp_rate', (object) ['chatid' => $chatid, 'windowstart' => $windowstart,
                'msgcount' => 3, 'blockeduntil' => $blockeduntil]);
        }

        $output = $this->run_cleanup();

        $this->assertSame(['wa_US.3', 'wa_US.4'], self::left('local_jedomi_whatsapp_rate', 'chatid'));
        $this->assertStringContainsString('Deleted 2 expired WhatsApp message limit record(s).', $output);
    }

    /**
     * Inbox rows in any state are kept for the deduplication TTL of at least 7 days and removed after it: a row left
     * pending or processing (sender not linked, BSUID changed by Meta) must not keep its data forever.
     */
    public function test_inbox_rows_in_any_state_are_removed_after_the_ttl(): void {
        global $DB;
        $this->assertGreaterThanOrEqual(7 * DAYSECS, cleanup_whatsapp::INBOX_TTL);
        $rows = [
            'done-old' => ['done', cleanup_whatsapp::INBOX_TTL + 1],
            'failed-old' => ['failed', cleanup_whatsapp::INBOX_TTL + DAYSECS],
            'pending-old' => ['pending', cleanup_whatsapp::INBOX_TTL + 1],
            'processing-old' => ['processing', cleanup_whatsapp::INBOX_TTL + 1],
            'done-limit' => ['done', cleanup_whatsapp::INBOX_TTL],
            'pending-limit' => ['pending', cleanup_whatsapp::INBOX_TTL],
            'processing-limit' => ['processing', cleanup_whatsapp::INBOX_TTL],
            'done-recent' => ['done', 6 * DAYSECS],
            'pending-recent' => ['pending', 60],
        ];
        foreach ($rows as $wamid => [$status, $age]) {
            $DB->insert_record('local_jedomi_whatsapp_inbox', (object) ['wamid' => $wamid, 'bsuid' => 'US.1', 'payload' => '',
                'status' => $status, 'attempts' => 1, 'timecreated' => self::NOW - $age]);
        }

        $output = $this->run_cleanup();

        $this->assertSame(
            ['done-limit', 'done-recent', 'pending-limit', 'pending-recent', 'processing-limit'],
            self::left('local_jedomi_whatsapp_inbox', 'wamid')
        );
        $this->assertStringContainsString('Deleted 4 old WhatsApp inbox row(s).', $output);
    }

    /**
     * Links unused for more than 30 days are removed with the data of their chat; a link used at the limit, a recent
     * link and the data of other chats are kept. The usage of the current month is kept, so a new link does not reset
     * the monthly limit.
     */
    public function test_expired_links_are_removed_with_their_data(): void {
        global $DB;
        $expiry = \local_jedomi\local\service\whatsapp_link_service::EXPIRY;
        $links = [
            'US.old' => [self::NOW - $expiry - 1, 0],
            'US.used' => [self::NOW - $expiry - DAYSECS, self::NOW - $expiry - 1],
            'US.limit' => [self::NOW - $expiry - DAYSECS, self::NOW - $expiry],
            'US.new' => [self::NOW - DAYSECS, 0],
        ];
        foreach ($links as $bsuid => [$timecreated, $lastused]) {
            $user = $this->getDataGenerator()->create_user();
            $DB->insert_record('local_jedomi_whatsapp_links', (object) ['userid' => $user->id, 'bsuid' => $bsuid,
                'confirmed' => 1, 'timecreated' => $timecreated, 'lastused' => $lastused]);
            $DB->insert_record('local_jedomi_whatsapp_usage', (object) ['userid' => $user->id, 'period' => 202612]);
            $DB->insert_record('local_jedomi_whatsapp_inbox', (object) ['wamid' => 'wamid.' . $bsuid, 'bsuid' => $bsuid,
                'payload' => '', 'status' => 'pending', 'timecreated' => self::NOW]);
        }

        $output = $this->run_cleanup();

        $this->assertSame(['US.limit', 'US.new'], self::left('local_jedomi_whatsapp_links', 'bsuid'));
        $this->assertSame(['wamid.US.limit', 'wamid.US.new'], self::left('local_jedomi_whatsapp_inbox', 'wamid'));
        $this->assertSame(4, $DB->count_records('local_jedomi_whatsapp_usage'));
        $this->assertStringContainsString('Removed 2 expired WhatsApp link(s).', $output);
    }

    /**
     * Held replies outlive the idle conversations: they wait for the next message of the student until HELD_TTL, and
     * the one at the limit is kept.
     */
    public function test_held_replies_wait_until_their_own_limit(): void {
        global $DB;
        $held = \local_jedomi\local\service\whatsapp_link_service::HELD_PREFIX;
        $rows = [
            $held . 'wa_US.old' => \local_jedomi\whatsapp\held_replies::TTL + 1,
            $held . 'wa_US.limit' => \local_jedomi\whatsapp\held_replies::TTL,
            $held . 'wa_US.hour' => HOURSECS,
            'wa_US.idle' => HOURSECS,
        ];
        foreach ($rows as $chatid => $age) {
            $DB->insert_record('local_jedomi_whatsapp_sessions', (object) ['chatid' => $chatid, 'messages' => '[]',
                'lastactivity' => self::NOW - $age]);
        }

        $output = $this->run_cleanup();

        $this->assertSame(
            [$held . 'wa_US.hour', $held . 'wa_US.limit'],
            self::left('local_jedomi_whatsapp_sessions', 'chatid')
        );
        $this->assertStringContainsString('Deleted 1 expired WhatsApp session(s).', $output);
        $this->assertStringContainsString('Deleted 1 expired held WhatsApp reply row(s).', $output);
    }

    /**
     * The usage of past months of users without a link is removed; the current month, and every month of a linked
     * user, is kept.
     */
    public function test_old_usage_of_unlinked_users_is_removed(): void {
        global $DB;
        $linked = (int) $this->getDataGenerator()->create_user()->id;
        $unlinked = (int) $this->getDataGenerator()->create_user()->id;
        $DB->insert_record('local_jedomi_whatsapp_links', (object) ['userid' => $linked, 'bsuid' => 'US.linked',
            'confirmed' => 1, 'timecreated' => self::NOW, 'lastused' => self::NOW]);
        foreach ([$linked, $unlinked] as $userid) {
            foreach ([202610, 202611, 202612] as $period) {
                $DB->insert_record('local_jedomi_whatsapp_usage', (object) ['userid' => $userid, 'period' => $period]);
            }
        }

        $output = $this->run_cleanup();

        $this->assertSame([202610, 202611, 202612], array_map('intval', $DB->get_fieldset_select(
            'local_jedomi_whatsapp_usage',
            'period',
            'userid = ? ORDER BY period',
            [$linked]
        )));
        $this->assertSame([202612], array_map('intval', $DB->get_fieldset_select(
            'local_jedomi_whatsapp_usage',
            'period',
            'userid = ?',
            [$unlinked]
        )));
        $this->assertStringContainsString('Deleted 2 old WhatsApp usage row(s).', $output);
    }

    /**
     * Temporary media files a killed process left behind are removed once they are older than the limit; a recent file
     * (a download in progress), the file exactly at the limit and a subdirectory are kept.
     */
    public function test_old_media_temporary_files_are_removed(): void {
        $dir = make_temp_directory(\local_jedomi\whatsapp\whatsapp_media_source::TEMP_DIR);
        $files = [
            'old' => self::NOW - cleanup_whatsapp::MEDIA_FILE_TTL - 1,
            'older' => self::NOW - DAYSECS,
            'limit' => self::NOW - cleanup_whatsapp::MEDIA_FILE_TTL,
            'recent' => self::NOW - MINSECS,
        ];
        foreach ($files as $name => $mtime) {
            file_put_contents("{$dir}/{$name}", 'student media');
            touch("{$dir}/{$name}", $mtime);
        }
        mkdir("{$dir}/subdir");
        touch("{$dir}/subdir", self::NOW - DAYSECS);

        $output = $this->run_cleanup();

        $this->assertFileDoesNotExist("{$dir}/old");
        $this->assertFileDoesNotExist("{$dir}/older");
        $this->assertFileExists("{$dir}/limit");
        $this->assertFileExists("{$dir}/recent");
        $this->assertDirectoryExists("{$dir}/subdir");
        $this->assertStringContainsString('Deleted 2 old WhatsApp media temporary file(s).', $output);
        // A file is never removed while the longest media phase (the largest AI timeout setting) is still running.
        set_config('ai_timeout', \local_jedomi\ai\client::MAX_TIMEOUT, 'local_jedomi');
        $this->assertGreaterThan(\local_jedomi\whatsapp\whatsapp_media_source::time_limit(), cleanup_whatsapp::MEDIA_FILE_TTL);
    }
}
