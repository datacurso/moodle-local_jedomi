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
     * MDL-UNIT-012: general settings default to an empty secret and training data collection on.
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
     * MDL-UNIT-012: saving the general settings stores the secret and an unchecked training data flag as off.
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
     * MDL-UNIT-007: course availability drops empty and non-numeric ids and never stores an unknown mode.
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
     * MDL-UNIT-010: privacy settings record every role that is not marked visible as hidden.
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
     * MDL-UNIT-010: an unknown anonymization level is never stored; "basic" is kept instead.
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
     * MDL-INT-014: a valid token is checked with Telegram, stored encrypted with the bot name and the webhook is registered.
     * MDL-CTR-009: the success answers of the token check and of the webhook registration are interpreted.
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
     * MDL-INT-014: when the webhook registration fails the token is kept and the error is recorded.
     * MDL-CTR-009: an error answer of the webhook registration is interpreted.
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
     * MDL-INT-014: an invalid token is not stored.
     * MDL-CTR-009: an error answer of the token check is interpreted.
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
     * MDL-INT-017: the saved list of each role is persisted.
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
     * MDL-UNIT-052: the four roles of the APIs tab are recognised and an unknown role falls back to the admin role.
     *
     * @return void
     */
    public function test_normalise_ws_role(): void {
        foreach (['admin', 'manager', 'teacher', 'student'] as $role) {
            $this->assertSame($role, admin_settings_service::normalise_ws_role($role));
        }
        foreach (['guest', '', 'ADMIN', 'editingteacher', 'unknown'] as $role) {
            $this->assertSame('admin', admin_settings_service::normalise_ws_role($role));
        }
    }

    /**
     * MDL-UNIT-052: saving a list for an unknown role stores it as the admin list and creates no list for that role.
     *
     * @return void
     */
    public function test_ws_allowlist_of_unknown_role_is_stored_as_admin_list(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $this->resetAfterTest();

        admin_settings_service::save_ws_allowlist('guest', ['core_course_get_courses']);

        $this->assertSame(['core_course_get_courses'], \local_jedomi_get_role_allowlist('admin'));
        $this->assertFalse(get_config('local_jedomi', 'ws_allowlist_guest'));
    }

    /**
     * The web service table filters by name and type, paginates and counts the types.
     * MDL-INT-019: the table filters by type and name and paginates.
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
     * MDL-INT-018: saving the list from one page or filter keeps the functions allowed on the others.
     *
     * @return void
     */
    public function test_saving_from_one_page_keeps_the_functions_allowed_on_other_pages(): void {
        $this->resetAfterTest();
        unset_config('ws_allowlist_teacher', 'local_jedomi');

        admin_settings_service::save_ws_allowlist('teacher', ['core_course_get_courses'], ['core_course_get_courses']);
        admin_settings_service::save_ws_allowlist('teacher', ['core_user_get_users_by_field'],
            ['core_user_get_users_by_field', 'core_user_get_users']);

        $this->assertEqualsCanonicalizing(['core_course_get_courses', 'core_user_get_users_by_field'],
            \local_jedomi_get_role_allowlist('teacher'));

        admin_settings_service::save_ws_allowlist('teacher', [], ['core_user_get_users_by_field']);
        $this->assertSame(['core_course_get_courses'], \local_jedomi_get_role_allowlist('teacher'),
            'Unchecking a shown function removes only that function');
    }

    /**
     * MDL-INT-018: the first save of the administrator from one page only removes what was unchecked there.
     *
     * @return void
     */
    public function test_first_save_of_the_administrator_only_changes_what_was_unchecked(): void {
        $this->resetAfterTest();
        unset_config('ws_allowlist_admin', 'local_jedomi');

        admin_settings_service::save_ws_allowlist('admin', ['core_course_get_courses'],
            ['core_course_get_courses', 'core_course_delete_courses']);

        $allowed = \local_jedomi_get_role_allowlist('admin');
        $this->assertContains('core_course_get_courses', $allowed);
        $this->assertContains('core_user_get_users_by_field', $allowed, 'Functions on other pages keep their access');
        $this->assertNotContains('core_course_delete_courses', $allowed);
    }

    /**
     * A page beyond the last one is capped to the last page.
     * MDL-INT-019: a page out of range is moved to the last available page.
     *
     * @return void
     */
    public function test_get_ws_table_caps_page(): void {
        $this->resetAfterTest();

        $table = admin_settings_service::get_ws_table('admin', 'core_course_get_courses', '', 'all', 99, 10);

        $this->assertSame($table['pages'], $table['page']);
    }
    /**
     * MDL-UNIT-100: a query of an agent that no longer exists is presented without creator.
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

    /**
     * MDL-INT-014: the stored token is never kept in clear text.
     */
    public function test_verified_token_is_stored_encrypted(): void {
        $this->resetAfterTest();
        $client = $this->fake_client(['id' => 1, 'username' => 'jedomibot', 'first_name' => 'Jedomi']);

        admin_settings_service::verify_and_save_telegram_token('123456:secret-bot-token', $client);

        $this->assertStringNotContainsString('secret-bot-token', (string) get_config('local_jedomi', 'telegram_bot_token'));
        $this->assertSame('123456:secret-bot-token', \local_jedomi\telegram\manager::get_bot_token());
    }

    /**
     * MDL-INT-015: the administrator can remove the stored token, the shown state matches the token in use and the other
     * changes of the form are kept when a token is rejected.
     * [Pendiente:skip]
     */
    public function test_token_can_be_removed_and_state_matches_the_token_in_use(): void {
        $this->markTestSkipped('Pending fix: there is no way to remove the token, the previous token keeps working after an '
            . 'invalid one is rejected and the other changes of the form are lost.');
    }

    /**
     * MDL-INT-019: the component filter and the rows per page limit the table.
     */
    public function test_get_ws_table_filters_by_component_and_rows_per_page(): void {
        $this->resetAfterTest();

        $table = admin_settings_service::get_ws_table('teacher', '', 'mod_assign', 'all', 1, 10);

        $this->assertNotEmpty($table['rows']);
        $this->assertCount(min(10, $table['total']), $table['rows']);
        foreach ($table['rows'] as $row) {
            $this->assertSame('mod_assign', $row['component']);
        }
        $this->assertSame((int) ceil($table['total'] / 10), $table['pages']);
        $wide = admin_settings_service::get_ws_table('teacher', '', 'mod_assign', 'all', 1, 50);
        $this->assertCount(min(50, $wide['total']), $wide['rows']);
        $this->assertSame($table['total'], $wide['total']);
    }

    /**
     * MDL-INT-018: before the first save the table shows the real access of the administrator to every function.
     */
    public function test_first_visit_shows_the_real_access_of_the_administrator(): void {
        $this->resetAfterTest();
        unset_config('ws_allowlist_admin', 'local_jedomi');

        $table = admin_settings_service::get_ws_table('admin', 'core_course_', '', 'all', 1, 200);

        $this->assertContains(true, array_column($table['rows'], 'iswrite'), 'Precondition: the page has write functions');
        foreach ($table['rows'] as $row) {
            $this->assertTrue($row['checked'], "{$row['name']} is allowed to the administrator and must be shown as such");
        }
    }

    /**
     * MDL-INT-018: saving without any function selected does not give full access.
     */
    public function test_saving_without_functions_does_not_give_full_access(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the web service checks the plugin state through it.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        admin_settings_service::save_ws_allowlist('admin', ['core_course_get_courses']);

        admin_settings_service::save_ws_allowlist('admin', []);

        $result = json_decode(\local_jedomi\proxy\handler::run_tool('ws_execute',
            ['wsname' => 'core_webservice_get_site_info', 'params' => '{}'], 'admin'), true);
        $this->assertArrayNotHasKey('result', $result, 'A function that is not selected must not be run');
    }
}
