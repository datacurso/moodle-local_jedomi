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

namespace local_jedomi\local\service;

use local_jedomi\telegram\api_client;

/**
 * Tests for the service that reads and saves the Jedomi admin settings tabs.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\admin_settings_service
 */
final class admin_settings_service_test extends \advanced_testcase {
    /**
     * Build a Telegram client double that never calls the Bot API.
     *
     * @param array|null $botinfo What getMe returns; null simulates an invalid token.
     * @param bool $webhookok Whether setWebhook succeeds.
     * @return api_client
     */
    private function fake_client(?array $botinfo, bool $webhookok = true): api_client {
        return new class ($botinfo, $webhookok) extends api_client {
            /** @var array|null Bot info returned by get_me(). */
            private ?array $botinfo;
            /** @var bool Result of set_webhook(). */
            private bool $webhookok;
            /** @var string[] Bot API methods called, in order. */
            public array $calls = [];

            /**
             * Store the canned answers.
             *
             * @param array|null $botinfo Bot info returned by get_me().
             * @param bool $webhookok Result of set_webhook().
             */
            public function __construct(?array $botinfo, bool $webhookok) {
                parent::__construct('test-token');
                $this->botinfo = $botinfo;
                $this->webhookok = $webhookok;
            }

            #[\Override]
            public function get_me(): ?array {
                $this->calls[] = 'getMe';
                return $this->botinfo;
            }

            #[\Override]
            public function delete_webhook(): bool {
                $this->calls[] = 'deleteWebhook';
                return true;
            }

            #[\Override]
            public function set_webhook(string $url): bool {
                $this->calls[] = 'setWebhook';
                return $this->webhookok;
            }
        };
    }

    /**
     * General settings default to an empty secret and training data collection on.
     *
     * @return void
     */
    public function test_general_settings_defaults(): void {
        $this->resetAfterTest();
        unset_config('spt_secret', 'local_jedomi');
        unset_config('collect_training_data', 'local_jedomi');

        $this->assertSame(['spt_secret' => '', 'collect_training_data' => 1], admin_settings_service::get_general_settings());
    }

    /**
     * Saving the general settings stores the secret and the training data flag.
     *
     * @return void
     */
    public function test_save_general_settings(): void {
        $this->resetAfterTest();

        admin_settings_service::save_general_settings('s3cret', false);

        $this->assertSame('s3cret', get_config('local_jedomi', 'spt_secret'));
        $this->assertSame('0', get_config('local_jedomi', 'collect_training_data'));
        $this->assertSame(['spt_secret' => 's3cret', 'collect_training_data' => 0], admin_settings_service::get_general_settings());
    }

    /**
     * Course availability keeps only valid ids and falls back to "all" for an unknown mode.
     *
     * @return void
     */
    public function test_save_course_settings_normalises_values(): void {
        $this->resetAfterTest();

        admin_settings_service::save_course_settings('include', ['5', 0, 'x', 7]);
        $this->assertSame(['course_mode' => 'include', 'course_ids' => [5, 7]], admin_settings_service::get_course_settings());

        admin_settings_service::save_course_settings('bogus', []);
        $this->assertSame('all', get_config('local_jedomi', 'course_mode'));
        $this->assertSame('', get_config('local_jedomi', 'course_list'));
    }

    /**
     * Privacy settings hide every role that is not marked visible.
     *
     * @return void
     */
    public function test_save_privacy_settings_hides_roles_not_visible(): void {
        global $DB;
        $this->resetAfterTest();
        $roleids = array_map('intval', array_keys($DB->get_records('role', null, 'sortorder ASC', 'id')));
        $visible = array_slice($roleids, 1);

        admin_settings_service::save_privacy_settings(true, 'strict', $visible);

        $settings = admin_settings_service::get_privacy_settings();
        $this->assertSame(1, $settings['hide_from_guests']);
        $this->assertSame('strict', $settings['anonymization_level']);
        $this->assertSame([$roleids[0]], $settings['hidden_role_ids']);
    }

    /**
     * An unknown anonymization level is stored as "basic".
     *
     * @return void
     */
    public function test_save_privacy_settings_rejects_unknown_level(): void {
        $this->resetAfterTest();

        admin_settings_service::save_privacy_settings(false, 'everything', []);

        $this->assertSame('basic', get_config('local_jedomi', 'anonymization_level'));
        $this->assertSame('0', get_config('local_jedomi', 'hide_from_guests'));
    }

    /**
     * Roles are listed with a display name and their short name.
     *
     * @return void
     */
    public function test_get_roles_lists_every_role_with_a_label(): void {
        global $DB;
        $this->resetAfterTest();

        $roles = admin_settings_service::get_roles();

        $this->assertCount($DB->count_records('role'), $roles);
        foreach ($roles as $role) {
            $this->assertIsInt($role['id']);
            $this->assertNotSame('', $role['name']);
            $this->assertNotSame('', $role['shortname']);
        }
    }

    /**
     * A valid token is stored, the bot details are saved and the webhook is registered.
     *
     * @return void
     */
    public function test_verify_and_save_telegram_token_with_a_valid_token(): void {
        $this->resetAfterTest();
        $client = $this->fake_client(['id' => 1, 'username' => 'jedomibot', 'first_name' => 'Jedomi']);

        $this->assertTrue(admin_settings_service::verify_and_save_telegram_token('123:abc', $client));

        $this->assertSame('123:abc', \local_jedomi\telegram\manager::get_bot_token());
        $this->assertSame('jedomibot', get_config('local_jedomi', 'telegram_bot_username'));
        $this->assertSame('Jedomi', get_config('local_jedomi', 'telegram_bot_firstname'));
        $this->assertSame('1', get_config('local_jedomi', 'telegram_bot_verified'));
        $this->assertSame(['getMe', 'deleteWebhook', 'setWebhook'], $client->calls);
        $this->assertSame('', \local_jedomi\telegram\manager::get_last_webhook_error());
    }

    /**
     * A failed webhook registration after a valid token keeps the token and records the error.
     *
     * @return void
     */
    public function test_verify_and_save_telegram_token_records_webhook_failure(): void {
        $this->resetAfterTest();
        $client = $this->fake_client(['id' => 1, 'username' => 'jedomibot', 'first_name' => 'Jedomi'], false);

        $this->assertTrue(admin_settings_service::verify_and_save_telegram_token('123:abc', $client));

        $this->assertSame('123:abc', \local_jedomi\telegram\manager::get_bot_token());
        $this->assertNotSame('', \local_jedomi\telegram\manager::get_last_webhook_error());
    }

    /**
     * An invalid token is not stored and the bot is marked as not verified.
     *
     * @return void
     */
    public function test_verify_and_save_telegram_token_with_an_invalid_token(): void {
        $this->resetAfterTest();
        $client = $this->fake_client(null);

        $this->assertFalse(admin_settings_service::verify_and_save_telegram_token('bad', $client));

        $this->assertNull(\local_jedomi\telegram\manager::get_bot_token());
        $this->assertSame('0', get_config('local_jedomi', 'telegram_bot_verified'));
        $this->assertSame(['getMe'], $client->calls);
    }

    /**
     * Telegram limits and role visibility are saved.
     *
     * @return void
     */
    public function test_save_telegram_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $roleids = array_map('intval', array_keys($DB->get_records('role', null, 'sortorder ASC', 'id')));

        admin_settings_service::save_telegram_settings(40, 6, 12, 90, [$roleids[0]]);

        $settings = admin_settings_service::get_telegram_settings();
        $this->assertSame(40, $settings['rate_limit']);
        $this->assertSame(6, $settings['cooldown']);
        $this->assertSame(12, $settings['max_turns']);
        $this->assertSame(90, $settings['gateway_timeout']);
        $this->assertSame(array_slice($roleids, 1), $settings['hidden_role_ids']);
    }

    /**
     * Telegram settings report a configured bot only when it is enabled, has a token and was verified.
     *
     * @return void
     */
    public function test_get_telegram_settings_reports_configuration_state(): void {
        $this->resetAfterTest();
        set_config('telegram_enabled', 1, 'local_jedomi');
        set_config('telegram_bot_verified', 1, 'local_jedomi');

        $this->assertFalse(admin_settings_service::get_telegram_settings()['is_configured']);

        \local_jedomi\telegram\manager::set_bot_token('123:abc');
        $settings = admin_settings_service::get_telegram_settings();
        $this->assertTrue($settings['is_configured']);
        $this->assertTrue($settings['bot_token_set']);
        $this->assertSame(10, $settings['max_turns']);
        $this->assertSame(120, $settings['gateway_timeout']);
        $this->assertStringContainsString('/local/jedomi/telegram_webhook.php?secret=', $settings['webhook_url']);
    }

    /**
     * The web service allowlist of a role is replaced by the given names.
     *
     * @return void
     */
    public function test_save_ws_allowlist(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $this->resetAfterTest();

        admin_settings_service::save_ws_allowlist('teacher', ['core_course_get_courses', 'core_user_get_users']);

        $this->assertSame(
            ['core_course_get_courses', 'core_user_get_users'],
            \local_jedomi_get_role_allowlist('teacher')
        );
    }

    /**
     * An unknown role name falls back to the admin role.
     *
     * @return void
     */
    public function test_normalise_ws_role(): void {
        $this->assertSame('student', admin_settings_service::normalise_ws_role('student'));
        $this->assertSame('admin', admin_settings_service::normalise_ws_role('guest'));
    }

    /**
     * The web service table filters by name and type, paginates and counts the types.
     *
     * @return void
     */
    public function test_get_ws_table_filters_and_paginates(): void {
        $this->resetAfterTest();
        set_config('ws_allowlist_teacher', json_encode(['core_course_get_courses']), 'local_jedomi');

        $table = admin_settings_service::get_ws_table('teacher', 'core_course_get_course', '', 'all', 1, 10);

        $names = array_column($table['rows'], 'name');
        $this->assertContains('core_course_get_courses', $names);
        $this->assertGreaterThanOrEqual($table['count_read'] + $table['count_write'], $table['count_all']);
        $this->assertSame(1, $table['page']);
        $row = $table['rows'][array_search('core_course_get_courses', $names)];
        $this->assertTrue($row['checked']);
        $this->assertFalse($row['iswrite']);
        $this->assertSame('moodle', $row['component']);

        $write = admin_settings_service::get_ws_table('teacher', '', '', 'Escritura', 1, 10);
        $this->assertNotEmpty($write['rows']);
        foreach ($write['rows'] as $writerow) {
            $this->assertTrue($writerow['iswrite']);
        }
        $this->assertLessThanOrEqual(10, count($write['rows']));
        $this->assertSame((int) ceil($write['total'] / 10), $write['pages']);
    }

    /**
     * On the first visit the admin role has every read function preselected.
     *
     * @return void
     */
    public function test_get_ws_table_preselects_read_functions_for_admin_first_visit(): void {
        $this->resetAfterTest();
        unset_config('ws_allowlist_admin', 'local_jedomi');

        $table = admin_settings_service::get_ws_table('admin', 'core_course_get_course', '', 'all', 1, 10);

        foreach ($table['rows'] as $row) {
            $this->assertSame(!$row['iswrite'], $row['checked']);
        }
    }

    /**
     * A page beyond the last one is capped to the last page.
     *
     * @return void
     */
    public function test_get_ws_table_caps_page(): void {
        $this->resetAfterTest();

        $table = admin_settings_service::get_ws_table('admin', 'core_course_get_courses', '', 'all', 99, 10);

        $this->assertSame($table['pages'], $table['page']);
    }
    /**
     * Recent profiled queries come newest first with the name of the agent owner.
     *
     * @return void
     */
    public function test_get_recent_profiler_entries(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $agentid = $DB->insert_record('local_jedomi_agents', (object) ['userid' => $user->id, 'prompt' => 'p',
            'schedule_cron' => '0 * * * *', 'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('local_jedomi_query_log', (object) ['agentid' => $agentid, 'query' => 'SELECT 1',
            'duration_ms' => 5, 'rowcount' => 1, 'timecreated' => 100]);
        $DB->insert_record('local_jedomi_query_log', (object) ['agentid' => 999, 'query' => 'SELECT 2',
            'duration_ms' => 1500, 'rowcount' => 3, 'timecreated' => 200]);

        $entries = admin_settings_service::get_recent_profiler_entries(0, 'timecreated');

        $this->assertSame(['SELECT 2', 'SELECT 1'], array_column($entries, 'query'));
        $this->assertSame('', $entries[0]['userfullname']);
        $this->assertSame('Ada Lovelace', $entries[1]['userfullname']);
        $this->assertSame((int) $agentid, $entries[1]['agentid']);

        $slow = admin_settings_service::get_recent_profiler_entries(1000, 'duration_ms');
        $this->assertSame(['SELECT 2'], array_column($slow, 'query'));
    }
}
