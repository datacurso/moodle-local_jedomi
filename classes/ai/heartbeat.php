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

namespace local_jedomi\ai;

/**
 * Signals of life while a question waits for the AI service.
 *
 * A slow model can take minutes per round. While one listener is active, the cURL request of the AI call
 * ({@see provider_api}, {@see custom_api}) reports its progress here about once per second, and the listener runs at
 * most once per interval: the web chat sends an SSE event, so a proxy (Cloudflare closes an idle request after
 * 100 seconds) keeps the connection and the panel shows that Jedomi is still working; Telegram sends the "typing"
 * status again. The time comes from the Moodle clock.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class heartbeat {
    /** @var \Closure|null Listener of the question in progress; null when none. */
    private static ?\Closure $listener = null;

    /** @var int Seconds between two runs of the listener. */
    private static int $interval = 0;

    /** @var int Time of the start or of the last run of the listener. */
    private static int $last = 0;

    /** @var bool Whether the listener failed since the start; it is not run again until the next start. */
    private static bool $failed = false;

    /** @var \Closure|null Returns true when the client is gone and the request should stop; null when none. */
    private static ?\Closure $abortcheck = null;

    /** @var bool Whether the abort check stopped a request since the start. */
    private static bool $aborted = false;

    /**
     * Start sending signals of life for the question in progress, replacing any earlier listener.
     *
     * @param int $interval Seconds between two runs of the listener, at least 1.
     * @param \Closure $listener Run with no arguments; its result is ignored. A listener that throws is not run again
     *     until the next start, and never stops the request.
     * @param \Closure|null $abortcheck Returns true when the client is gone (the web chat: the browser closed the
     *     request); the waiting request is then aborted. Null to never abort.
     * @return void
     */
    public static function start(int $interval, \Closure $listener, ?\Closure $abortcheck = null): void {
        self::$listener = $listener;
        self::$interval = max(1, $interval);
        self::$last = self::now();
        self::$failed = false;
        self::$abortcheck = $abortcheck;
        self::$aborted = false;
    }

    /**
     * Stop sending signals of life.
     *
     * @return void
     */
    public static function stop(): void {
        self::$listener = null;
        self::$abortcheck = null;
    }

    /**
     * Whether the abort check stopped a request since the last start.
     *
     * @return bool
     */
    public static function aborted(): bool {
        return self::$aborted;
    }

    /**
     * Whether a listener is active.
     *
     * @return bool
     */
    public static function is_active(): bool {
        return self::$listener !== null;
    }

    /**
     * Run the listener when the interval has passed since the start or its last run.
     *
     * @return void
     */
    public static function beat(): void {
        if (self::$listener === null || self::$failed) {
            return;
        }
        $now = self::now();
        if ($now - self::$last < self::$interval) {
            return;
        }
        self::$last = $now;
        try {
            (self::$listener)();
        } catch (\Throwable $e) {
            // A signal of life is never worth the answer: the request goes on without further signals.
            self::$failed = true;
            if (function_exists('local_jedomi_log')) {
                \local_jedomi_log('HEARTBEAT_LISTENER_FAILED', ['error' => get_class($e)]);
            }
        }
    }

    /**
     * cURL options that make a request beat while it waits; none while no listener is active.
     *
     * @return array Options for the Moodle cURL wrapper.
     */
    public static function curl_options(): array {
        if (self::$listener === null) {
            return [];
        }
        return [
            'CURLOPT_NOPROGRESS' => false,
            // Returning 0 lets the request go on; any other value makes cURL abort it.
            'CURLOPT_PROGRESSFUNCTION' => static function ($handle, $downtotal, $downloaded, $uptotal, $uploaded): int {
                if (self::client_gone()) {
                    return 1;
                }
                self::beat();
                return 0;
            },
        ];
    }

    /**
     * Whether the abort check reports that the client is gone; remembered until the next start.
     *
     * @return bool
     */
    private static function client_gone(): bool {
        if (!self::$aborted && self::$abortcheck !== null && (self::$abortcheck)()) {
            self::$aborted = true;
        }
        return self::$aborted;
    }

    /**
     * Current time of the Moodle clock.
     *
     * @return int
     */
    private static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
