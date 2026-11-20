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

use local_jedomi\telegram\api_client;
use local_jedomi\telegram\manager;

/**
 * Tests for the task that retries the registration of the Telegram webhook (RES-001).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\task\register_telegram_webhook
 */
final class register_telegram_webhook_test extends \advanced_testcase {
    /** @var api_client|null Client the task uses, recording its requests. */
    private ?api_client $client = null;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        manager::set_bot_token('123:abc');
        manager::require_webhook_registration();
    }

    #[\Override]
    protected function tearDown(): void {
        api_client::set_instance_for_testing(null);
        $this->client = null;
        parent::tearDown();
    }

    /**
     * Make the task use a client that records its requests and answers them with the given result.
     *
     * @param bool $ok Whether Telegram accepts the requests.
     * @return void
     */
    private function use_client(bool $ok): void {
        $this->client = new class ($ok) extends api_client {
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
        api_client::set_instance_for_testing($this->client);
    }

    /**
     * Task with the given remaining attempts.
     *
     * @param int $attempts Remaining attempts.
     * @return register_telegram_webhook
     */
    private function task(int $attempts): register_telegram_webhook {
        $task = new register_telegram_webhook();
        $task->set_attempts_available($attempts);
        return $task;
    }

    /**
     * A successful retry registers a new secret with setWebhook alone, stores it and clears the warning.
     */
    public function test_success_stores_the_new_secret_and_clears_the_warning(): void {
        $this->use_client(true);
        $oldsecret = manager::get_webhook_secret();
        $sink = $this->redirectMessages();

        $this->expectOutputRegex('/registered/');
        $this->task(register_telegram_webhook::ATTEMPTS)->execute();

        $this->assertSame(['setWebhook'], array_column($this->client->requests, 0));
        $newsecret = manager::get_webhook_secret();
        $this->assertNotSame($oldsecret, $newsecret);
        $this->assertSame($newsecret, $this->client->requests[0][1]['secret_token']);
        $this->assertFalse(manager::needs_webhook_registration());
        $this->assertSame(10, $this->client->curl_options()['CURLOPT_TIMEOUT']);
        $this->assertSame(0, $sink->count());
    }

    /**
     * The first failed attempt notifies the site administrators and fails, so the task is retried later.
     */
    public function test_first_failure_notifies_the_administrators_and_retries(): void {
        $this->use_client(false);
        $oldsecret = manager::get_webhook_secret();
        $sink = $this->redirectMessages();

        try {
            $this->task(register_telegram_webhook::ATTEMPTS)->execute();
            $this->fail('A failed attempt must throw so the task is retried');
        } catch (\moodle_exception $e) {
            $this->assertSame('telegram_webhook_retry_failed', $e->errorcode);
        }

        $this->assertSame(['setWebhook'], array_column($this->client->requests, 0));
        $this->assertSame($oldsecret, manager::get_webhook_secret());
        $this->assertTrue(manager::needs_webhook_registration());
        $messages = $sink->get_messages();
        $this->assertCount(count(get_admins()), $messages);
        $this->assertSame('local_jedomi', $messages[0]->component);
        $this->assertSame('telegramwebhook', $messages[0]->eventtype);
        $this->assertSame(get_string('telegram_webhook_failed_subject', 'local_jedomi'), $messages[0]->subject);
        $this->assertStringNotContainsString($oldsecret, $messages[0]->fullmessage);
    }

    /**
     * A failed attempt that is neither the first nor the last only fails, without notifying anybody again.
     */
    public function test_intermediate_failure_retries_without_notification(): void {
        $this->use_client(false);
        $sink = $this->redirectMessages();

        $this->expectException(\moodle_exception::class);
        try {
            $this->task(5)->execute();
        } finally {
            $this->assertSame(0, $sink->count());
            $this->assertTrue(manager::needs_webhook_registration());
        }
    }

    /**
     * The last failed attempt notifies the administrators that the retries stopped and ends the task; the warning stays.
     */
    public function test_final_failure_notifies_the_administrators_and_stops(): void {
        $this->use_client(false);
        $sink = $this->redirectMessages();

        $this->expectOutputRegex('/stopped/');
        $this->task(1)->execute();

        $this->assertTrue(manager::needs_webhook_registration());
        $messages = $sink->get_messages();
        $this->assertCount(count(get_admins()), $messages);
        $this->assertSame(get_string('telegram_webhook_failed_final_body', 'local_jedomi'), $messages[0]->fullmessage);
    }

    /**
     * When the webhook was registered meanwhile (for example by saving the bot token), the task does nothing.
     */
    public function test_nothing_is_done_when_the_registration_is_no_longer_needed(): void {
        $this->use_client(true);
        manager::clear_webhook_registration_need();
        $secret = manager::get_webhook_secret();

        $this->expectOutputRegex('/no longer/');
        $this->task(register_telegram_webhook::ATTEMPTS)->execute();

        $this->assertSame([], $this->client->requests);
        $this->assertSame($secret, manager::get_webhook_secret());
    }

    /**
     * When the bot token was removed meanwhile, the task clears the warning without calling Telegram.
     */
    public function test_without_bot_token_the_warning_is_cleared(): void {
        $this->use_client(true);
        unset_config('telegram_bot_token', 'local_jedomi');

        $this->expectOutputRegex('/no bot token/');
        $this->task(register_telegram_webhook::ATTEMPTS)->execute();

        $this->assertSame([], $this->client->requests);
        $this->assertFalse(manager::needs_webhook_registration());
    }

    /**
     * Queuing keeps a single task with the limited number of attempts (about 17 hours of retries with the backoff).
     */
    public function test_queue_keeps_a_single_task_with_limited_attempts(): void {
        register_telegram_webhook::queue();
        register_telegram_webhook::queue();

        $tasks = \core\task\manager::get_adhoc_tasks(register_telegram_webhook::class);
        $this->assertCount(1, $tasks);
        $this->assertSame(11, reset($tasks)->get_attempts_available());
        $this->assertSame(register_telegram_webhook::ATTEMPTS, reset($tasks)->get_attempts_available());
    }

    /**
     * A value encrypted with the key of the site and then altered, as after a change of that key.
     *
     * @return string
     */
    private static function unreadable_secret(): string {
        [$method, $data] = explode(':', \local_jedomi\local\secret_store::encrypt('123:abc'), 2);
        $raw = base64_decode($data);
        $raw[30] = chr(ord($raw[30]) ^ 0x01);
        return $method . ':' . base64_encode($raw);
    }

    /**
     * A bot token that cannot be decrypted keeps the registration pending and records the real cause.
     */
    public function test_unreadable_bot_token_keeps_the_registration_pending(): void {
        $this->use_client(true);
        set_config('telegram_bot_token', self::unreadable_secret(), 'local_jedomi');

        $this->expectOutputRegex('/could not be decrypted/');
        $this->task(register_telegram_webhook::ATTEMPTS)->execute();

        $this->assertSame([], $this->client->requests);
        $this->assertTrue(manager::needs_webhook_registration(), 'The pending registration is kept');
        $this->assertSame(get_string('telegram_token_unreadable', 'local_jedomi'), manager::get_last_webhook_error());
        $this->assertNotSame('', get_config('local_jedomi', 'telegram_bot_token'), 'The stored token is kept');
    }
}
