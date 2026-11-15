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

namespace local_jedomi;

/**
 * Tests for the upgrade helper that removes the companion API prohibit left by older versions.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::local_jedomi_remove_companion_api_prohibits
 * @covers     ::local_jedomi_upgrade_ai_service
 * @covers     ::local_jedomi_upgrade_telegram_webhook
 * @covers     ::local_jedomi_upgrade_agent_write_approvals
 */
final class upgradelib_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/jedomi/db/upgradelib.php');
        $this->resetAfterTest();
    }

    /**
     * Permission of a role for the companion capability at system context, or null when it has none.
     *
     * @param int $roleid Role id.
     * @return int|null
     */
    private function companion_permission(int $roleid): ?int {
        global $DB;
        $permission = $DB->get_field('role_capabilities', 'permission', [
            'roleid' => $roleid,
            'capability' => 'local/jedomi:companion_api',
            'contextid' => \context_system::instance()->id,
        ]);
        return $permission === false ? null : (int) $permission;
    }

    /**
     * MDL-INT-075 / MDL-INT-086: the update removes the prohibits of the companion permission of previous versions and keeps the
     * changes of the administrator.
     * The prohibit left on the authenticated user role is removed, so a manager holds the capability again, while an
     * allow set by an administrator on another role is kept.
     */
    public function test_prohibit_is_removed_and_administrator_values_are_kept(): void {
        global $DB;
        $systemcontext = \context_system::instance();
        $userroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'user']);
        $studentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $teacherroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'teacher']);
        $managerroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);

        // State left by the older defaults, plus values set by an administrator.
        assign_capability('local/jedomi:companion_api', CAP_PROHIBIT, $userroleid, $systemcontext->id, true);
        assign_capability('local/jedomi:companion_api', CAP_ALLOW, $studentroleid, $systemcontext->id, true);
        assign_capability('local/jedomi:companion_api', CAP_PREVENT, $teacherroleid, $systemcontext->id, true);
        $manager = $this->getDataGenerator()->create_user();
        role_assign($managerroleid, $manager->id, $systemcontext);
        $this->assertFalse(has_capability('local/jedomi:companion_api', $systemcontext, $manager));

        $this->assertSame(1, local_jedomi_remove_companion_api_prohibits());

        $this->assertNull($this->companion_permission($userroleid));
        $this->assertSame(CAP_ALLOW, $this->companion_permission($studentroleid));
        $this->assertSame(CAP_PREVENT, $this->companion_permission($teacherroleid));
        $this->assertSame(CAP_ALLOW, $this->companion_permission($managerroleid));
        $this->assertTrue(has_capability('local/jedomi:companion_api', $systemcontext, $manager));

        // Running it again changes nothing.
        $this->assertSame(0, local_jedomi_remove_companion_api_prohibits());
    }

    /**
     * MDL-INT-086: a prohibit set by the administrator on another role is kept.
     * A prohibit on a role whose archetype is not one of the old defaults (for example a manager role) is kept.
     */
    public function test_prohibit_on_other_archetypes_is_kept(): void {
        global $DB;
        $systemcontext = \context_system::instance();
        $customroleid = create_role('Custom manager', 'custommanager', '', 'manager');
        assign_capability('local/jedomi:companion_api', CAP_PROHIBIT, $customroleid, $systemcontext->id, true);

        local_jedomi_remove_companion_api_prohibits();

        $this->assertSame(CAP_PROHIBIT, $this->companion_permission($customroleid));
    }

    /**
     * The retired "none" service, a missing choice or an unknown value become the Datacurso service; the custom
     * service is kept.
     *
     * @return void
     */
    public function test_ai_service_without_a_valid_choice_becomes_datacurso(): void {
        $cases = ['none' => 'datacurso', 'other' => 'datacurso', 'datacurso' => 'datacurso', 'custom' => 'custom'];
        foreach ($cases as $saved => $expected) {
            set_config('ai_service', $saved, 'local_jedomi');
            local_jedomi_upgrade_ai_service();
            $this->assertSame($expected, get_config('local_jedomi', 'ai_service'), $saved);
        }
        unset_config('ai_service', 'local_jedomi');
        local_jedomi_upgrade_ai_service();
        $this->assertSame('datacurso', get_config('local_jedomi', 'ai_service'));
    }

    /**
     * Telegram client that records its requests and answers them with the given result.
     *
     * @param bool $ok Whether Telegram accepts the requests.
     * @return \local_jedomi\telegram\api_client
     */
    private function telegram_client(bool $ok): \local_jedomi\telegram\api_client {
        return new class ($ok) extends \local_jedomi\telegram\api_client {
            /** @var array Requests made, as [method, params]. */
            public array $requests = [];
            /** @var bool Whether Telegram accepts the requests. */
            private bool $ok;

            /**
             * Store the canned answer.
             *
             * @param bool $ok Whether Telegram accepts the requests.
             */
            public function __construct(bool $ok) {
                parent::__construct('123:abc');
                $this->ok = $ok;
            }

            #[\Override]
            protected function post(string $method, array $params): ?array {
                $this->requests[] = [$method, $params];
                return $this->ok ? ['ok' => true, 'result' => true] : null;
            }
        };
    }

    /**
     * JEDOMI-SEC-004 / RES-001: with a bot token the update registers the webhook with a new secret as secret_token and
     * an address without secret by calling setWebhook alone (never deleteWebhook first), with a short total timeout,
     * and stores the new secret once Telegram accepted it; no warning is left and nothing is printed.
     */
    public function test_telegram_webhook_is_registered_again_with_a_new_secret(): void {
        \local_jedomi\telegram\manager::set_bot_token('123:abc');
        $oldsecret = \local_jedomi\telegram\manager::get_webhook_secret();
        $client = $this->telegram_client(true);

        $this->assertTrue(local_jedomi_upgrade_telegram_webhook($client));

        $newsecret = \local_jedomi\telegram\manager::get_webhook_secret();
        $this->assertNotSame($oldsecret, $newsecret);
        $this->assertSame([['setWebhook', [
            'url' => \local_jedomi\telegram\manager::get_webhook_url(),
            'secret_token' => $newsecret,
        ]]], $client->requests);
        $this->assertSame(10, $client->curl_options('')[CURLOPT_TIMEOUT]);
        $this->assertSame(5, $client->curl_options('')[CURLOPT_CONNECTTIMEOUT]);
        $this->assertFalse(\local_jedomi\telegram\manager::needs_webhook_registration());
        $this->assertSame([], \core\task\manager::get_adhoc_tasks(\local_jedomi\task\register_telegram_webhook::class));
        $this->expectOutputString('');
    }

    /**
     * JEDOMI-SEC-004 / RES-001: when Telegram cannot be reached the update still finishes: the webhook is never
     * deleted, the stored secret stays (and is refused while the warning is shown), a retry task is queued, a line is
     * printed in the upgrade output, and saving the bot token again registers a new secret.
     */
    public function test_failed_telegram_registration_leaves_a_warning_and_queues_a_retry(): void {
        \local_jedomi\telegram\manager::set_bot_token('123:abc');
        $oldsecret = \local_jedomi\telegram\manager::get_webhook_secret();
        $client = $this->telegram_client(false);

        $this->expectOutputRegex('/Telegram webhook could not be registered/');
        $this->assertFalse(local_jedomi_upgrade_telegram_webhook($client));

        $this->assertSame(['setWebhook'], array_column($client->requests, 0));
        $this->assertSame($oldsecret, \local_jedomi\telegram\manager::get_webhook_secret());
        $this->assertFalse(\local_jedomi\telegram\manager::is_valid_webhook_request(
            ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $oldsecret]
        ));
        $this->assertTrue(\local_jedomi\telegram\manager::needs_webhook_registration());
        $this->assertTrue(\local_jedomi\local\service\admin_settings_service::get_telegram_settings()['needs_registration']);
        $tasks = \core\task\manager::get_adhoc_tasks(\local_jedomi\task\register_telegram_webhook::class);
        $this->assertCount(1, $tasks);
        $this->assertSame(
            \local_jedomi\task\register_telegram_webhook::ATTEMPTS,
            reset($tasks)->get_attempts_available()
        );

        $saved = \local_jedomi\local\service\admin_settings_service::verify_and_save_telegram_token(
            '123:abc',
            new class ('123:abc') extends \local_jedomi\telegram\api_client {
                #[\Override]
                protected function post(string $method, array $params): ?array {
                    return ['ok' => true, 'result' => ['id' => 1, 'username' => 'jedomibot', 'first_name' => 'Jedomi']];
                }
            }
        );

        $this->assertTrue($saved);
        $this->assertFalse(\local_jedomi\telegram\manager::needs_webhook_registration());
        $this->assertNotSame($oldsecret, \local_jedomi\telegram\manager::get_webhook_secret());
    }

    /**
     * JEDOMI-SEC-004: without a bot token the secret is rotated, nothing is sent to Telegram and no warning is shown.
     */
    public function test_telegram_without_token_only_rotates_the_secret(): void {
        $oldsecret = \local_jedomi\telegram\manager::get_webhook_secret();
        $client = $this->telegram_client(true);

        $this->assertTrue(local_jedomi_upgrade_telegram_webhook($client));

        $this->assertNotSame($oldsecret, \local_jedomi\telegram\manager::get_webhook_secret());
        $this->assertSame([], $client->requests);
        $this->assertFalse(\local_jedomi\telegram\manager::needs_webhook_registration());
    }

    /**
     * JEDOMI-SEC-001: after the upgrade every existing agent has no approved writes, so all its writes wait for its
     * creator, and actions held by agents can name their agent.
     */
    public function test_existing_agents_have_no_approved_writes_after_the_upgrade(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $id = (int) $DB->insert_record('local_jedomi_agents', (object) ['userid' => 2, 'prompt' => 'Old agent',
            'schedule_cron' => '0 8 * * *', 'timecreated' => time(), 'timemodified' => time(),
            'approvedwrites' => json_encode(['core_message_send_instant_messages'])]);

        local_jedomi_upgrade_agent_write_approvals();

        $this->assertTrue($dbman->field_exists('local_jedomi_agents', 'approvedwrites'));
        $this->assertTrue($dbman->field_exists('local_jedomi_pending_actions', 'agentid'));
        $agent = \local_jedomi\agent\agent_manager::get_agent($id);
        $this->assertSame([], \local_jedomi\agent\agent_manager::approved_writes($agent));
    }
}
