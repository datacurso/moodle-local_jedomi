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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../lib.php');

/**
 * Messages per user and minute of the web chat (JEDOMI-SEC-006), applied in Moodle for both AI services.
 *
 * The limit is the "Messages per user per minute (web chat)" setting of the General tab (chat_rate_limit, 0 to
 * MAX_PER_MINUTE, default DEFAULT_PER_MINUTE; 0 turns it off). Site administrators are never limited.
 *
 * What counts: every request of the sidebar that leads to an AI call, after its access checks and before that call. A
 * question sent in the web chat (chatproxy.php) counts one, and so does every attached file, image or voice message
 * read or transcribed (extract_file.php, read_file.php). A question with two attached files therefore counts three,
 * because it makes three AI calls (RISK-001). Requests refused for access reasons do not count. Telegram keeps its own
 * limit, and the scheduled agents and the companion web service are not limited by this one.
 *
 * Sliding window: the times of the requests a user made in the last WINDOW_SECONDS are kept in the application cache
 * chat_rate (MODE_APPLICATION, so the limit holds across sessions and tabs; across web servers only when the
 * application cache is a shared store such as Redis or Memcached). A request is accepted while fewer than the limit are
 * in the window, and the user is told when the oldest one leaves it. The read and the write of a user's entry happen
 * under the cache lock of that entry, so concurrent requests of the same user cannot both take the last place.
 *
 * Failures: when the lock cannot be taken (ex_unabletolock) the request is refused with its own short message
 * (fail closed: it only happens under concurrent requests of the same user). Any other failure of the cache store lets
 * the request through and logs RATE_LIMIT_UNAVAILABLE (fail open: an infrastructure fault must not stop the chat). If
 * the cache is purged the window starts empty again: the limit protects the credits against floods, it is not an exact
 * quota.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_rate_limit_service {
    /** @var int Messages per user and minute when nothing valid is saved. */
    public const DEFAULT_PER_MINUTE = 10;

    /** @var int Highest accepted limit: two messages per second is far above what a person types. */
    public const MAX_PER_MINUTE = 120;

    /** @var int Length of the window, in seconds. */
    public const WINDOW_SECONDS = 60;

    /** @var string Plugin setting with the limit. */
    public const SETTING = 'chat_rate_limit';

    /** @var int Returned by consume() when the entry of the user is locked by another request. */
    public const BUSY = -1;

    /** @var callable|null Builds the cache instead of cache::make() in PHPUnit tests. */
    private static $cachefactory = null;

    /**
     * Replace the cache used by consume(). Only for PHPUnit tests.
     *
     * @param callable|null $factory Returns the cache (or throws); null to restore cache::make().
     * @return void
     */
    public static function set_cache_factory_for_testing(?callable $factory): void {
        if (!PHPUNIT_TEST) {
            throw new \coding_exception('chat_rate_limit_service::set_cache_factory_for_testing() is only for PHPUnit tests');
        }
        self::$cachefactory = $factory;
    }

    /**
     * Messages a user may send per minute: the saved setting within 0 and MAX_PER_MINUTE, or the default when nothing
     * valid is saved. 0 means no limit.
     *
     * @return int
     */
    public static function per_minute(): int {
        $saved = trim((string) get_config('local_jedomi', self::SETTING));
        if (!preg_match('/^\d+$/', $saved)) {
            return self::DEFAULT_PER_MINUTE;
        }
        return min(self::MAX_PER_MINUTE, (int) $saved);
    }

    /**
     * Count a request of a user, if the limit allows it.
     *
     * @param int $userid User who makes the request.
     * @param int|null $now Current time; null for time() (tests pass their own clock).
     * @return int 0 when the request is accepted (and counted, or the cache is unavailable); BUSY when the entry of the
     *     user is locked; otherwise the seconds to wait (at least 1).
     */
    public static function consume(int $userid, ?int $now = null): int {
        $limit = self::per_minute();
        if ($limit === 0 || is_siteadmin($userid)) {
            return 0;
        }
        $now = $now ?? time();
        $key = (string) $userid;
        try {
            $cache = self::$cachefactory ? (self::$cachefactory)() : \cache::make('local_jedomi', 'chat_rate');
            $cache->acquire_lock($key);
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'ex_unabletolock') {
                return self::BUSY;
            }
            return self::unavailable($e);
        } catch (\Throwable $e) {
            return self::unavailable($e);
        }
        try {
            $times = $cache->get($key);
            $times = array_values(array_filter(
                is_array($times) ? $times : [],
                static fn($time): bool => is_int($time) && $time > $now - self::WINDOW_SECONDS && $time <= $now
            ));
            if (count($times) >= $limit) {
                sort($times);
                return max(1, $times[count($times) - $limit] + self::WINDOW_SECONDS - $now);
            }
            $times[] = $now;
            $cache->set($key, $times);
            return 0;
        } catch (\Throwable $e) {
            return self::unavailable($e);
        } finally {
            try {
                $cache->release_lock($key);
            } catch (\Throwable $e) {
                // The lock expires on its own; the answer is already decided.
                debugging('chat_rate lock not released: ' . get_class($e), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Count a request of a user and return the error to answer when it is refused, logging the refusal.
     *
     * @param int $userid User who makes the request.
     * @param string $channel Where the request comes from, for the log: web (chat) or upload (file endpoints).
     * @param int|null $now Current time; null for time().
     * @return array|null Null when accepted; otherwise ['code' => 'rate_limited', 'seconds' => int, 'message' => string]
     *     or ['code' => 'rate_limit_busy', 'message' => string].
     */
    public static function refusal(int $userid, string $channel, ?int $now = null): ?array {
        $wait = self::consume($userid, $now);
        if ($wait === 0) {
            return null;
        }
        $user = \local_jedomi_log_pseudonym('user', $userid);
        if ($wait === self::BUSY) {
            \local_jedomi_log('RATE_LIMIT_BUSY', ['channel' => $channel, 'user' => $user]);
            return ['code' => 'rate_limit_busy', 'message' => get_string('chat_error_rate_limit_busy', 'local_jedomi')];
        }
        \local_jedomi_log('RATE_LIMITED', ['channel' => $channel, 'user' => $user, 'limit' => self::per_minute(),
            'retry_after' => $wait]);
        return ['code' => 'rate_limited', 'seconds' => $wait,
            'message' => get_string('chat_error_rate_limited', 'local_jedomi', $wait)];
    }

    /**
     * Let a request through when the cache store fails, with one warning in the log (no personal data).
     *
     * @param \Throwable $e Failure of the cache store.
     * @return int 0.
     */
    private static function unavailable(\Throwable $e): int {
        \local_jedomi_log('RATE_LIMIT_UNAVAILABLE', ['class' => get_class($e)]);
        return 0;
    }
}
