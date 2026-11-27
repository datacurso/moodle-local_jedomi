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

namespace local_jedomi\telegram;

/**
 * Tests for the Telegram continuation: the next leg of a long tool loop is a self-ping to the Telegram webhook.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\telegram_continuation
 * @covers     \local_jedomi\telegram\manager
 * @covers     \local_jedomi\telegram\webhook_handler
 */
final class telegram_continuation_test extends \advanced_testcase {
    #[\Override]
    protected function tearDown(): void {
        $this->pending_mock_responses(true);
        parent::tearDown();
    }

    /**
     * Mock responses of \curl not used yet.
     *
     * @param bool $clear Whether to remove them.
     * @return array
     */
    private function pending_mock_responses(bool $clear = false): array {
        $property = new \ReflectionProperty(\curl::class, 'mockresponses');
        $pending = $property->getValue();
        if ($clear) {
            $property->setValue(null, []);
        }
        return $pending;
    }

    /**
     * The continuation of the Telegram bot is the Telegram continuation.
     */
    public function test_manager_gives_the_telegram_continuation(): void {
        $this->assertInstanceOf(telegram_continuation::class, manager::continuation());
    }

    /**
     * The request carries the encoded chat and the session generation given, and the secret only in its header.
     */
    public function test_request_targets_the_webhook_with_the_secret_header(): void {
        global $CFG;
        $this->resetAfterTest();
        $secret = manager::get_webhook_secret();

        $request = (new telegram_continuation())->request('-100 1', 42);

        $this->assertSame(
            $CFG->wwwroot . '/local/jedomi/telegram_webhook.php?internal_ping=1&chat_id=-100+1&session_gen=42',
            $request['url']
        );
        $this->assertSame(['Content-Type: application/json', 'X-Telegram-Bot-Api-Secret-Token: ' . $secret], $request['headers']);
        $this->assertSame([
            'CURLOPT_TIMEOUT' => 5,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_MAXREDIRS' => 0,
        ], $request['options']);
    }

    /**
     * The handler keeps its self-ping request: the continuation request with the session generation of the saved loop.
     */
    public function test_handler_request_is_the_continuation_request_of_the_saved_loop(): void {
        $this->resetAfterTest();
        session::set_context('tool_loop_783000003', ['session_gen' => 77]);

        $this->assertSame(
            (new telegram_continuation())->request('783000003', 77),
            webhook_handler::self_ping_request('783000003')
        );
    }

    /**
     * Scheduling posts the request once with \curl (a mock response here, so nothing leaves the test).
     */
    public function test_schedule_posts_the_request(): void {
        $this->resetAfterTest();
        \curl::mock_response('{}');

        (new telegram_continuation())->schedule('783000004', 5);

        $this->assertSame([], $this->pending_mock_responses());
    }
}
