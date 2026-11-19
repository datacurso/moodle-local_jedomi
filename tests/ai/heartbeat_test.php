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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_curl.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/testable_provider_api.php');

/**
 * Tests for the signals of life sent while an AI request waits.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\ai\heartbeat
 */
final class heartbeat_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        heartbeat::stop();
        custom_api::set_curl_factory_for_testing(null);
        parent::tearDown();
    }

    /**
     * The listener runs once per interval, never more often, and never after the heartbeat stops.
     */
    public function test_listener_runs_once_per_interval_until_stopped(): void {
        $clock = $this->mock_clock_with_frozen();
        $beats = 0;
        heartbeat::start(15, static function () use (&$beats): void {
            $beats++;
        });
        $this->assertTrue(heartbeat::is_active());

        heartbeat::beat();
        $this->assertSame(0, $beats, 'No signal before the interval passes');
        $clock->bump(15);
        heartbeat::beat();
        heartbeat::beat();
        $this->assertSame(1, $beats);
        $clock->bump(14);
        heartbeat::beat();
        $this->assertSame(1, $beats);
        $clock->bump(1);
        heartbeat::beat();
        $this->assertSame(2, $beats);

        heartbeat::stop();
        $clock->bump(60);
        heartbeat::beat();
        $this->assertSame(2, $beats);
        $this->assertFalse(heartbeat::is_active());
    }

    /**
     * Without a listener no cURL option is added; with one, the progress callback of cURL beats and lets the request go on.
     */
    public function test_curl_options_beat_only_while_active(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->assertSame([], heartbeat::curl_options());

        $beats = 0;
        heartbeat::start(5, static function () use (&$beats): void {
            $beats++;
        });
        $options = heartbeat::curl_options();
        $this->assertFalse($options['CURLOPT_NOPROGRESS']);
        $clock->bump(5);
        $this->assertSame(0, ($options['CURLOPT_PROGRESSFUNCTION'])(null, 0, 0, 0, 0), 'The request must go on');
        $this->assertSame(1, $beats);
    }

    /**
     * RES-002: a listener that fails never stops the AI request, and is not run again until the next start.
     */
    public function test_failing_listener_never_aborts_the_request_and_is_not_run_again(): void {
        $clock = $this->mock_clock_with_frozen();
        $runs = 0;
        heartbeat::start(4, static function () use (&$runs): void {
            $runs++;
            throw new \RuntimeException('Telegram is down');
        });
        $progress = heartbeat::curl_options()['CURLOPT_PROGRESSFUNCTION'];

        $clock->bump(4);
        $this->assertSame(0, $progress(null, 0, 0, 0, 0), 'The request must go on');
        $clock->bump(4);
        heartbeat::beat();
        $this->assertSame(1, $runs, 'No new attempt after a failure');
        $this->assertTrue(heartbeat::is_active());

        heartbeat::start(4, static function () use (&$runs): void {
            $runs++;
        });
        $clock->bump(4);
        heartbeat::beat();
        $this->assertSame(2, $runs);
    }

    /**
     * RES-001: when the abort check reports a closed client, the progress callback stops the request and the
     * heartbeat remembers it until the next start; without an abort check the request always goes on.
     */
    public function test_abort_check_stops_the_request(): void {
        $this->mock_clock_with_frozen();
        $closed = false;
        heartbeat::start(15, static function (): void {
        }, static function () use (&$closed): bool {
            return $closed;
        });
        $progress = heartbeat::curl_options()['CURLOPT_PROGRESSFUNCTION'];

        $this->assertSame(0, $progress(null, 0, 0, 0, 0));
        $this->assertFalse(heartbeat::aborted());
        $closed = true;
        $this->assertNotSame(0, $progress(null, 0, 0, 0, 0), 'A non-zero result makes cURL abort the transfer');
        $this->assertTrue(heartbeat::aborted());

        heartbeat::start(15, static function (): void {
        });
        $this->assertFalse(heartbeat::aborted());
        $this->assertSame(0, (heartbeat::curl_options()['CURLOPT_PROGRESSFUNCTION'])(null, 0, 0, 0, 0));
    }

    /**
     * The custom service request beats while it waits for the answer.
     */
    public function test_custom_service_request_carries_the_progress_callback(): void {
        $this->resetAfterTest();
        $curl = new fake_curl();
        $curl->enqueue('{"choices":[{"message":{"content":"ok"},"finish_reason":"stop"}]}');
        custom_api::set_curl_factory_for_testing(static fn(bool $bypass): \curl => $curl);
        heartbeat::start(5, static function (): void {
        });

        (new custom_api('https://ai.example.com/v1', '', false))->chat(['model' => 'm', 'messages' => []], 30);

        $this->assertArrayHasKey('CURLOPT_PROGRESSFUNCTION', $curl->calls[0]['options']);
        $this->assertFalse($curl->calls[0]['options']['CURLOPT_NOPROGRESS']);
    }

    /**
     * The Datacurso service request beats while it waits for the answer.
     */
    public function test_provider_request_carries_the_progress_callback(): void {
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed.');
        }
        $this->resetAfterTest();
        $curl = new fake_curl();
        heartbeat::start(5, static function (): void {
        });

        $api = new testable_provider_api(30, $curl);
        $method = new \ReflectionMethod($api, 'create_curl');
        $method->invoke($api);

        $this->assertArrayHasKey('CURLOPT_PROGRESSFUNCTION', $curl->setoptions);
        $this->assertFalse($curl->setoptions['CURLOPT_NOPROGRESS']);
        $this->assertSame(30, $curl->setoptions['CURLOPT_TIMEOUT']);
    }
}
