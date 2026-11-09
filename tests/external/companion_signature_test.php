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

namespace local_jedomi\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Tests for the signature that authenticates the requests of the external Jedomi service.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\companion_hmac
 * @covers     \local_jedomi\external\execute_sql
 * @covers     \local_jedomi\external\extract_schema
 * @covers     \local_jedomi\external\ws_execute
 * @covers     \local_jedomi\external\read_file
 * @covers     \local_jedomi\external\get_site_config
 * @covers     \local_jedomi\external\set_site_config
 * @covers     \local_jedomi\external\run_task
 * @covers     \local_jedomi\external\purge_caches
 * @covers     \local_jedomi\external\validate_password
 */
final class companion_signature_test extends \advanced_testcase {
    /** @var string Secret of the site used by the tests. */
    private const SECRET = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2';

    /** @var int Seconds a signed request stays valid, in the past and in the future. */
    private const MARGIN = 60;

    /** @var string Query used by the read requests. */
    private const SQL = 'SELECT id FROM {course} WHERE id = 1';

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the web services check the plugin state through it.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('hmac_secret', self::SECRET, 'local_jedomi');
    }

    /**
     * Content summary of the parameters of a request, as the external service builds it.
     *
     * @param array $params Parameters of the request, without the signature fields.
     * @return string
     */
    private static function payload_hash(array $params): string {
        ksort($params);
        return hash('sha256', json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Signature of a request.
     *
     * @param string $function Signed function name.
     * @param int $timestamp Signed moment.
     * @param string $hash Signed content summary.
     * @param string $secret Secret used to sign.
     * @return string
     */
    private static function sign(string $function, int $timestamp, string $hash, string $secret = self::SECRET): string {
        return hash_hmac('sha256', "{$function}:{$timestamp}:{$hash}", $secret);
    }

    /**
     * Run the read query with the given signature fields.
     *
     * @param string $signature Signature.
     * @param int $timestamp Moment sent.
     * @param string $hash Content summary sent.
     * @param string $sql Query sent.
     * @return array Result of the web service.
     */
    private function query(string $signature, int $timestamp, string $hash, string $sql = self::SQL): array {
        return execute_sql::execute($sql, [], $signature, $timestamp, $hash);
    }

    /**
     * Wait for the start of a new second so the checks run within the same second.
     *
     * @return int The current time.
     */
    private function start_of_a_new_second(): int {
        $now = time();
        while (time() === $now) {
            usleep(10000);
        }
        return time();
    }

    /**
     * Error code of a call, 'accepted' when it does not fail, or the class of any other failure.
     *
     * @param callable $call Call.
     * @return string
     */
    private function outcome(callable $call): string {
        try {
            $call();
            return 'accepted';
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        } catch (\Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }

    /**
     * Path of the active Jedomi log.
     *
     * @return string
     */
    private function logfile(): string {
        global $CFG;
        return $CFG->dataroot . '/local/jedomi/jedomi.log';
    }

    /**
     * MDL-UNIT-099: a request signed with the site secret and a moment within the margin is accepted.
     * MDL-UNIT-103: a request signed over its own parameters is accepted.
     */
    public function test_request_signed_with_the_site_secret_is_accepted(): void {
        $now = time();
        $hash = self::payload_hash(['sql' => self::SQL, 'params' => []]);

        $result = $this->query(self::sign('jedomi_execute_sql', $now, $hash), $now, $hash);

        $this->assertArrayHasKey('row_count', $result);
    }

    /**
     * Ways a signature can fail to match the request.
     *
     * @return array
     */
    public static function wrong_signature_provider(): array {
        return [
            'another secret' => ['secret'],
            'another function' => ['function'],
            'another moment' => ['moment'],
            'another content summary' => ['hash'],
        ];
    }

    /**
     * MDL-UNIT-099: a signature computed with another secret, for another function, with another moment or with another
     * content summary is rejected.
     *
     * @dataProvider wrong_signature_provider
     * @param string $wrong What differs between the signature and the request.
     */
    public function test_signature_that_does_not_match_is_rejected(string $wrong): void {
        $now = time();
        $hash = self::payload_hash(['sql' => self::SQL, 'params' => []]);
        $signature = match ($wrong) {
            'secret' => self::sign('jedomi_execute_sql', $now, $hash, str_repeat('f', 64)),
            'function' => self::sign('jedomi_read_file', $now, $hash),
            'moment' => self::sign('jedomi_execute_sql', $now - 1, $hash),
            'hash' => self::sign('jedomi_execute_sql', $now, hash('sha256', 'other content')),
        };

        $this->assertSame('unauthorized', $this->outcome(fn() => $this->query($signature, $now, $hash)));
    }

    /**
     * MDL-UNIT-099: moments just inside the margin are accepted and just outside are rejected, in the past and the future.
     */
    public function test_moments_at_the_edges_of_the_margin(): void {
        $hash = self::payload_hash(['sql' => self::SQL, 'params' => []]);
        $results = [];
        foreach ([-self::MARGIN, -self::MARGIN - 1, self::MARGIN, self::MARGIN + 1] as $offset) {
            $moment = $this->start_of_a_new_second() + $offset;
            $results[$offset] = $this->outcome(fn() => $this->query(self::sign('jedomi_execute_sql', $moment, $hash), $moment,
                $hash));
        }

        $this->assertSame([
            -self::MARGIN => 'accepted',
            -self::MARGIN - 1 => 'unauthorized',
            self::MARGIN => 'accepted',
            self::MARGIN + 1 => 'unauthorized',
        ], $results);
    }

    /**
     * MDL-UNIT-099: every rejection gives the caller the same message without the reason, and the log records the
     * failure without the secret.
     */
    public function test_rejection_hides_the_reason_and_is_logged_without_the_secret(): void {
        @unlink($this->logfile());
        $now = time();
        $hash = self::payload_hash(['sql' => self::SQL, 'params' => []]);
        $messages = [];

        foreach ([
            [self::sign('jedomi_execute_sql', $now, $hash, str_repeat('f', 64)), $now],
            [self::sign('jedomi_execute_sql', $now - 600, $hash), $now - 600],
        ] as [$signature, $moment]) {
            try {
                $this->query($signature, $moment, $hash);
                $this->fail('The request must be rejected');
            } catch (\moodle_exception $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertSame([get_string('unauthorized', 'local_jedomi'), get_string('unauthorized', 'local_jedomi')], $messages);
        foreach ($messages as $message) {
            $this->assertStringNotContainsStringIgnoringCase('expired', $message);
            $this->assertStringNotContainsStringIgnoringCase('signature', $message);
        }
        $log = file_get_contents($this->logfile());
        $this->assertStringContainsString('HMAC failure', $log);
        $this->assertStringNotContainsString(self::SECRET, $log);
    }

    /**
     * MDL-UNIT-099: without a stored secret one is created the first time and reused afterwards.
     */
    public function test_secret_is_created_once_and_reused(): void {
        unset_config('hmac_secret', 'local_jedomi');
        $now = time();
        $hash = self::payload_hash(['sql' => self::SQL, 'params' => []]);

        $this->assertSame('unauthorized', $this->outcome(fn() => $this->query(str_repeat('0', 64), $now, $hash)));
        $secret = get_config('local_jedomi', 'hmac_secret');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);

        $result = $this->query(self::sign('jedomi_execute_sql', $now, $hash, $secret), $now, $hash);
        $this->assertArrayHasKey('row_count', $result);
        $this->assertSame($secret, get_config('local_jedomi', 'hmac_secret'));
    }

    /**
     * Every function that requires a signature: the parameters signed and different parameters actually sent.
     *
     * @return array
     */
    public static function signed_function_provider(): array {
        return [
            'database query' => ['execute_sql', 'jedomi_execute_sql',
                ['sql' => 'SELECT id FROM {course} WHERE id = 1', 'params' => []],
                ['sql' => 'SELECT id, username FROM {user}', 'params' => []]],
            'database schema' => ['extract_schema', 'jedomi_extract_schema',
                ['include_row_counts' => false, 'domains' => ['course']],
                ['include_row_counts' => true, 'domains' => ['user']]],
            'web service execution' => ['ws_execute', 'jedomi_ws_execute',
                ['wsname' => 'core_webservice_get_site_info', 'params' => '{}'],
                ['wsname' => 'core_user_get_users_by_field', 'params' => '{"field":"id","values":[2]}']],
            'file reading' => ['read_file', 'jedomi_read_file',
                ['path' => 'version.php', 'line_start' => 1, 'line_count' => 5],
                ['path' => 'local/jedomi/version.php', 'line_start' => 1, 'line_count' => 5]],
            'site settings reading' => ['get_site_config', 'jedomi_get_site_config',
                ['keys' => ['sitename']],
                ['keys' => ['wwwroot', 'sitename', 'lang']]],
            'site settings change' => ['set_site_config', 'jedomi_set_site_config',
                ['name' => 'jedomi_signature_probe', 'value' => 'signed', 'plugin' => 'local_jedomi'],
                ['name' => 'jedomi_signature_probe', 'value' => 'tampered', 'plugin' => 'local_jedomi']],
            'task execution' => ['run_task', 'jedomi_run_task',
                ['task_class' => '\\core\\task\\cache_cron_task', 'sync' => false],
                ['task_class' => '\\local_jedomi\\task\\no_such_task', 'sync' => false]],
            'cache purge' => ['purge_caches', 'jedomi_purge_caches',
                ['scope' => 'signed-for-another-request'],
                []],
            'password validation' => ['validate_password', 'jedomi_validate_password',
                ['password' => 'Signed#Pass1', 'username' => 'alice'],
                ['password' => 'other', 'username' => 'bob']],
        ];
    }

    /**
     * MDL-UNIT-103: reusing a valid signature and content summary with other parameters is rejected, for every function
     * that requires a signature.
     * [Pendiente:fail] The server never recomputes the content summary from the parameters it receives.
     *
     * @dataProvider signed_function_provider
     * @param string $class External function class, without namespace.
     * @param string $function Signed function name.
     * @param array $signedparams Parameters the signature was made for.
     * @param array $sentparams Parameters actually sent.
     */
    public function test_signature_reused_with_other_parameters_is_rejected(string $class, string $function,
            array $signedparams, array $sentparams): void {
        $now = time();
        $hash = self::payload_hash($signedparams);
        $signature = self::sign($function, $now, $hash);
        $fqcn = '\\local_jedomi\\external\\' . $class;

        $outcome = $this->outcome(fn() => $fqcn::execute(...array_merge(array_values($sentparams), [$signature, $now, $hash])));

        $this->assertSame('unauthorized', $outcome,
            "{$class}: a signature made for other parameters must be rejected before running the operation");
    }

    /**
     * MDL-UNIT-103: a rejected request does not run the requested operation.
     * [Pendiente:fail] A captured signature changes a site setting with another value.
     */
    public function test_rejected_request_does_not_change_the_setting(): void {
        $now = time();
        $hash = self::payload_hash(['name' => 'jedomi_signature_probe', 'value' => 'signed', 'plugin' => 'local_jedomi']);
        $signature = self::sign('jedomi_set_site_config', $now, $hash);

        $this->outcome(fn() => set_site_config::execute('jedomi_signature_probe', 'tampered', 'local_jedomi', $signature,
            $now, $hash));

        $this->assertNotSame('tampered', get_config('local_jedomi', 'jedomi_signature_probe'),
            'A signature made for another value must not change the setting');
    }
}
