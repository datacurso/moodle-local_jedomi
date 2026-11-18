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

namespace local_jedomi\local;

use local_jedomi\agent\tool\agent_tool;

/**
 * Runs a plugin tool again, a few times and for a short time, while it answers that its data is still being prepared.
 *
 * The contract, for any plugin (see {@see \local_jedomi\hook\tool_registry}): a tool whose data is not ready yet
 * answers a JSON object with "meta": {"pending": true, ...} at its top level. Jedomi then waits WAIT_SECONDS and runs
 * the same tool with the same arguments again, at most MAX_ATTEMPTS runs in all and never past MAX_TOTAL_SECONDS since
 * the first run started, and gives the AI the last answer. All the waits of one PHP request (one turn of the chat)
 * together never pass MAX_REQUEST_WAIT_SECONDS, so several pending tools in a turn cannot add their waits up. Only
 * tools that declare they only read are run again; an answer with an "error" key, a failing tool or any other shape is
 * returned at once.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plugin_tool_retry {
    /** @var int Runs of a pending tool in all, the first one included. */
    public const MAX_ATTEMPTS = 3;

    /** @var int Seconds waited before each new run. */
    public const WAIT_SECONDS = 10;

    /** @var int Longest time, in seconds since the first run started, at which a wait may still end. */
    public const MAX_TOTAL_SECONDS = 25;

    /** @var int Seconds all the waits of one PHP request may add up to. */
    public const MAX_REQUEST_WAIT_SECONDS = 30;

    /** @var int Seconds already waited in this PHP request. */
    private static int $waited = 0;

    /** @var \Closure|null Replacement of the wait in PHPUnit tests: fn(int $seconds): void. */
    private static ?\Closure $testsleeper = null;

    /**
     * Run a plugin tool as the current user and, while it answers pending and it only reads, wait and run it again.
     *
     * @param agent_tool $tool The tool.
     * @param array $args Arguments decoded from the tool call.
     * @return string The last answer of the tool.
     */
    public static function run(agent_tool $tool, array $args): string {
        $clock = \core\di::get(\core\clock::class);
        $start = $clock->time();
        $result = plugin_tools::execute($tool, $args);
        $pending = self::is_pending($result);
        if (!$pending) {
            return $result;
        }
        $readonly = plugin_tools::is_read_only($tool);
        if (!$readonly) {
            return $result;
        }
        $attempt = 1;
        while ($pending && $attempt < self::MAX_ATTEMPTS) {
            $elapsed = $clock->time() - $start;
            if ($elapsed + self::WAIT_SECONDS > self::MAX_TOTAL_SECONDS) {
                break;
            }
            if (self::$waited + self::WAIT_SECONDS > self::MAX_REQUEST_WAIT_SECONDS) {
                break;
            }
            $attempt++;
            \local_jedomi_log('PLUGIN_TOOL_PENDING_RETRY', ['tool' => $tool->get_name(), 'attempt' => $attempt]);
            self::wait(self::WAIT_SECONDS);
            $result = plugin_tools::execute($tool, $args);
            $pending = self::is_pending($result);
        }
        return $result;
    }

    /**
     * Whether an answer says that its data is still being prepared: a JSON object without "error" whose "meta" object
     * has "pending" exactly true. The text of a message never counts.
     *
     * @param string $result Answer of a tool.
     * @return bool
     */
    public static function is_pending(string $result): bool {
        $decoded = json_decode($result, true);
        if (!is_array($decoded) || array_is_list($decoded) || array_key_exists('error', $decoded)) {
            return false;
        }
        $meta = $decoded['meta'] ?? null;
        if (!is_array($meta)) {
            return false;
        }
        $pending = $meta['pending'] ?? null;
        return $pending === true;
    }

    /**
     * Replace the wait in PHPUnit tests, so they never sleep. It also starts a new request: nothing waited before counts.
     *
     * @param \Closure|null $sleeper fn(int $seconds): void, or null to restore the real wait.
     * @return void
     */
    public static function set_sleeper_for_testing(?\Closure $sleeper): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('plugin_tool_retry::set_sleeper_for_testing() is only for PHPUnit tests');
        }
        self::$testsleeper = $sleeper;
        self::$waited = 0;
    }

    /**
     * Wait some seconds before the next run.
     *
     * @param int $seconds Seconds to wait.
     * @return void
     */
    private static function wait(int $seconds): void {
        self::$waited += $seconds;
        if (self::$testsleeper !== null) {
            (self::$testsleeper)($seconds);
            return;
        }
        sleep($seconds);
    }
}
