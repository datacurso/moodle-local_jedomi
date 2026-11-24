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

namespace local_jedomi\local\service;

/**
 * Tests for the per-user message limit of the web chat (JEDOMI-SEC-006).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\service\chat_rate_limit_service
 */
final class chat_rate_limit_service_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        chat_rate_limit_service::set_cache_factory_for_testing(null);
        parent::tearDown();
    }

    /**
     * Read the Jedomi log and empty it.
     *
     * @return string
     */
    private function take_log(): string {
        global $CFG;
        $path = $CFG->dataroot . '/local/jedomi/jedomi.log';
        $log = is_file($path) ? (string) file_get_contents($path) : '';
        @unlink($path);
        return $log;
    }

    /**
     * A cache double whose operations fail as asked.
     *
     * @param \Throwable|null $lockfailure Thrown by acquire_lock(); null to lock.
     * @param \Throwable|null $readfailure Thrown by get() and set(); null to work.
     * @return object
     */
    private function failing_cache(?\Throwable $lockfailure, ?\Throwable $readfailure): object {
        return new class ($lockfailure, $readfailure) {
            /** @var \Throwable|null Thrown by acquire_lock(). */
            private ?\Throwable $lockfailure;

            /** @var \Throwable|null Thrown by get() and set(). */
            private ?\Throwable $readfailure;

            /**
             * Keep the failures.
             *
             * @param \Throwable|null $lockfailure Thrown by acquire_lock().
             * @param \Throwable|null $readfailure Thrown by get() and set().
             */
            public function __construct(?\Throwable $lockfailure, ?\Throwable $readfailure) {
                $this->lockfailure = $lockfailure;
                $this->readfailure = $readfailure;
            }

            /**
             * Lock a key, or fail.
             *
             * @param string $key Key.
             * @return bool
             */
            public function acquire_lock($key): bool {
                if ($this->lockfailure) {
                    throw $this->lockfailure;
                }
                return true;
            }

            /**
             * Release a key.
             *
             * @param string $key Key.
             * @return bool
             */
            public function release_lock($key): bool {
                return true;
            }

            /**
             * Read a key, or fail.
             *
             * @param string $key Key.
             * @return mixed
             */
            public function get($key) {
                if ($this->readfailure) {
                    throw $this->readfailure;
                }
                return false;
            }

            /**
             * Write a key, or fail.
             *
             * @param string $key Key.
             * @param mixed $value Value.
             * @return bool
             */
            public function set($key, $value): bool {
                if ($this->readfailure) {
                    throw $this->readfailure;
                }
                return true;
            }
        };
    }

    /**
     * Send messages for a user at a given time.
     *
     * @param int $userid User.
     * @param int $count Messages sent.
     * @param int $now Time of the messages.
     * @return int[] Seconds to wait returned for each message (0 when accepted).
     */
    private function send(int $userid, int $count, int $now): array {
        $waits = [];
        for ($i = 0; $i < $count; $i++) {
            $waits[] = chat_rate_limit_service::consume($userid, $now);
        }
        return $waits;
    }

    /**
     * With the default of 10 messages per minute, the 11th message of a user within a minute is refused and the user is
     * told how long to wait; another user is not affected.
     */
    public function test_eleventh_message_within_a_minute_is_refused_by_default(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $now = 1800000000;

        $this->assertSame(10, chat_rate_limit_service::per_minute());
        $this->assertSame(array_fill(0, 10, 0), $this->send((int) $user->id, 10, $now));
        $this->assertSame(60, chat_rate_limit_service::consume((int) $user->id, $now));
        $this->assertSame(45, chat_rate_limit_service::consume((int) $user->id, $now + 15));
        $this->assertSame(0, chat_rate_limit_service::consume((int) $other->id, $now + 15));
    }

    /**
     * The window slides: a message is counted for one minute after it was accepted, then the user can send again.
     */
    public function test_window_frees_a_message_one_minute_after_it_was_sent(): void {
        $this->resetAfterTest();
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $now = 1800000000;

        $this->send($userid, 5, $now);
        $this->send($userid, 5, $now + 30);
        $this->assertSame(30, chat_rate_limit_service::consume($userid, $now + 30));
        $this->assertSame(1, chat_rate_limit_service::consume($userid, $now + 59));
        // The first five are a minute old: five more fit, not six.
        $this->assertSame(array_fill(0, 5, 0), $this->send($userid, 5, $now + 60));
        $this->assertSame(30, chat_rate_limit_service::consume($userid, $now + 60));
        // A minute after the last message the whole limit is free again.
        $this->assertSame(array_fill(0, 10, 0), $this->send($userid, 10, $now + 120));
    }

    /**
     * Site administrators are never limited.
     */
    public function test_site_administrators_are_exempt(): void {
        $this->resetAfterTest();
        $adminid = (int) get_admin()->id;

        $this->assertSame(array_fill(0, 30, 0), $this->send($adminid, 30, 1800000000));
    }

    /**
     * A limit of 0 turns the limit off; a saved limit is used, kept within 0 and MAX_PER_MINUTE.
     */
    public function test_saved_limit_is_used_and_zero_turns_it_off(): void {
        $this->resetAfterTest();
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $now = 1800000000;

        set_config('chat_rate_limit', '0', 'local_jedomi');
        $this->assertSame(0, chat_rate_limit_service::per_minute());
        $this->assertSame(array_fill(0, 50, 0), $this->send($userid, 50, $now));

        set_config('chat_rate_limit', '2', 'local_jedomi');
        $this->assertSame([0, 0, 60], $this->send($userid, 3, $now + 600));

        set_config('chat_rate_limit', '5000', 'local_jedomi');
        $this->assertSame(chat_rate_limit_service::MAX_PER_MINUTE, chat_rate_limit_service::per_minute());

        foreach (['', 'abc', '-3'] as $invalid) {
            set_config('chat_rate_limit', $invalid, 'local_jedomi');
            $this->assertSame(chat_rate_limit_service::DEFAULT_PER_MINUTE, chat_rate_limit_service::per_minute());
        }
    }

    /**
     * The refusal over the limit carries the code, the seconds to wait and the message, and is logged without the user id.
     */
    public function test_refusal_over_the_limit_has_the_seconds_and_is_logged(): void {
        $this->resetAfterTest();
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        set_config('chat_rate_limit', '1', 'local_jedomi');
        $this->take_log();

        $this->assertNull(chat_rate_limit_service::refusal($userid, 'web', 1800000000));
        $this->assertSame(
            ['code' => 'rate_limited', 'seconds' => 45,
                'message' => get_string('chat_error_rate_limited', 'local_jedomi', 45)],
            chat_rate_limit_service::refusal($userid, 'upload', 1800000015)
        );
        $log = $this->take_log();
        $this->assertStringContainsString('RATE_LIMITED', $log);
        $this->assertStringContainsString('"channel":"upload"', $log);
        $this->assertStringNotContainsString('"userid"', $log);
    }

    /**
     * RES-002: a failure of the cache store never breaks the chat: the message goes through (fail open) and one warning
     * without personal data is logged.
     */
    public function test_cache_failure_lets_the_message_through(): void {
        $this->resetAfterTest();
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $this->take_log();

        chat_rate_limit_service::set_cache_factory_for_testing(static function () {
            throw new \RuntimeException('store down');
        });
        $this->assertSame(0, chat_rate_limit_service::consume($userid));
        $this->assertNull(chat_rate_limit_service::refusal($userid, 'web'));

        chat_rate_limit_service::set_cache_factory_for_testing(
            fn() => $this->failing_cache(null, new \RuntimeException('read failed'))
        );
        $this->assertSame(0, chat_rate_limit_service::consume($userid));

        $log = $this->take_log();
        $this->assertStringContainsString('RATE_LIMIT_UNAVAILABLE', $log);
        $this->assertStringNotContainsString('store down', $log);
        $this->assertStringNotContainsString('"userid"', $log);
        $this->assertStringNotContainsString('"user"', $log);
    }

    /**
     * READ-001: when the lock of the user's entry cannot be taken the message is refused (fail closed) with its own short
     * message and code, and the log tells it apart.
     */
    public function test_lock_failure_is_refused_with_its_own_message(): void {
        $this->resetAfterTest();
        $userid = (int) $this->getDataGenerator()->create_user()->id;
        $this->take_log();
        chat_rate_limit_service::set_cache_factory_for_testing(
            fn() => $this->failing_cache(new \moodle_exception('ex_unabletolock', 'cache'), null)
        );

        $this->assertSame(chat_rate_limit_service::BUSY, chat_rate_limit_service::consume($userid));
        $this->assertSame(
            ['code' => 'rate_limit_busy', 'message' => get_string('chat_error_rate_limit_busy', 'local_jedomi')],
            chat_rate_limit_service::refusal($userid, 'web')
        );
        $log = $this->take_log();
        $this->assertStringContainsString('RATE_LIMIT_BUSY', $log);
        $this->assertStringNotContainsString('RATE_LIMITED', $log);
    }
}
