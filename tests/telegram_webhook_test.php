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

/**
 * Unit tests for telegram_webhook endpoint.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

/**
 * Test cases for telegram_webhook.php — POST guard, E007, logging.
 *
 * Since telegram_webhook.php is a standalone script (not a class),
 * tests verify the script structure and utility functions it depends on.
 *
 * @coversNothing
 */
final class telegram_webhook_test extends \advanced_testcase {
    /**
     * Setup test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // ... ========================
    // E007 error code
    // ========================

    /**
     * Test that E007 lang string exists and contains the error code.
     */
    public function test_e007_lang_string(): void {
        $str = get_string('error_telegram_e007', 'local_jedomi');
        $this->assertStringContainsString(
            'E007',
            $str,
            'E007 lang string should contain the error code identifier'
        );
        $this->assertNotEmpty($str, 'E007 lang string should not be empty');
    }

    /**
     * Test that local_jedomi_log function exists and handles E007 format.
     */
    public function test_e007_log_format(): void {
        // Verify the logging function exists.
        $this->assertTrue(
            function_exists('local_jedomi_log'),
            'local_jedomi_log function should be defined in lib.php'
        );

        // Call it with E007 format — should not throw or error.
        local_jedomi_log('E007: webhook secret mismatch');
        // If we reach here, the call succeeded.
        $this->assertTrue(true, 'local_jedomi_log handles E007 format without error');
    }

    // ... ========================
    // POST guard validation
    // ========================

    /**
     * Test that telegram_webhook.php validates HTTP method (POST only).
     */
    public function test_post_guard_check(): void {
        $source = file_get_contents(__DIR__ . '/../telegram_webhook.php');
        $this->assertStringContainsString(
            'REQUEST_METHOD',
            $source,
            'telegram_webhook.php should validate HTTP method via $_SERVER[\'REQUEST_METHOD\']'
        );
        $this->assertStringContainsString(
            '405',
            $source,
            'telegram_webhook.php should return HTTP 405 for non-POST requests'
        );
        $this->assertStringContainsString(
            'Method Not Allowed',
            $source,
            'telegram_webhook.php should include "Method Not Allowed" in 405 response'
        );
    }

    /**
     * Test that the 405 response returns before processing begins.
     */
    public function test_post_guard_before_processing(): void {
        $source = file_get_contents(__DIR__ . '/../telegram_webhook.php');
        // Verify REQUEST_METHOD check occurs before the webhook secret check.
        $methodpos = strpos($source, 'REQUEST_METHOD');
        $secretpos = strpos($source, 'optional_param');
        $this->assertNotFalse($methodpos);
        $this->assertNotFalse($secretpos);
        $this->assertLessThan(
            $secretpos,
            $methodpos,
            'REQUEST_METHOD check should appear before secret validation'
        );
    }

    // ... ========================
    // E007 in secret mismatch
    // ========================

    /**
     * Test that E007 is included in the secret mismatch response.
     */
    public function test_e007_in_secret_check(): void {
        $source = file_get_contents(__DIR__ . '/../telegram_webhook.php');
        $this->assertStringContainsString(
            'E007',
            $source,
            'telegram_webhook.php should include E007 in secret mismatch response'
        );
        $this->assertStringContainsString(
            'Invalid webhook secret',
            $source,
            'telegram_webhook.php should mention "Invalid webhook secret" with E007'
        );
    }

    /**
     * Test that E007 log call is made on secret mismatch.
     */
    public function test_e007_log_call_in_source(): void {
        $source = file_get_contents(__DIR__ . '/../telegram_webhook.php');
        $this->assertStringContainsString(
            "local_jedomi_log('E007:",
            $source,
            'telegram_webhook.php should call local_jedomi_log with E007 on mismatch'
        );
    }

    // ... ========================
    // Input validation
    // ========================

    /**
     * Test that telegram_webhook.php validates the JSON input.
     */
    public function test_json_validation(): void {
        $source = file_get_contents(__DIR__ . '/../telegram_webhook.php');
        $this->assertStringContainsString(
            'php://input',
            $source,
            'telegram_webhook.php should read raw POST input'
        );
        $this->assertStringContainsString(
            'json_decode',
            $source,
            'telegram_webhook.php should decode JSON payload'
        );
        $this->assertStringContainsString(
            '400',
            $source,
            'telegram_webhook.php should return HTTP 400 for invalid JSON'
        );
    }

    /**
     * Test that telegram_webhook.php always returns 200 OK after processing.
     */
    public function test_always_returns_200(): void {
        $source = file_get_contents(__DIR__ . '/../telegram_webhook.php');
        $this->assertStringContainsString(
            '200',
            $source,
            'telegram_webhook.php should return HTTP 200 after successful processing'
        );
        $this->assertStringContainsString(
            'json_encode',
            $source,
            'telegram_webhook.php should return JSON response'
        );
    }
    /**
     * The early acknowledgement declares the real body length.
     *
     * MDL-CTR-008: the acknowledgement to Telegram declares the real length of its body.
     *
     * A wrong Content-Length keeps the proxy waiting for missing bytes while the update is processed,
     * so Telegram gets an error and sends the same update again, which calls the AI service again.
     */
    public function test_acknowledgement_declares_the_real_body_length(): void {
        $ack = \local_jedomi\telegram\webhook_handler::acknowledgement();

        $this->assertSame('{"ok":true}', $ack['body']);
        $this->assertContains('Content-Length: ' . strlen($ack['body']), $ack['headers']);
        $this->assertContains('Connection: close', $ack['headers']);
    }

    /**
     * telegram_webhook.php sends both early acknowledgements through the shared helper, never a fixed Content-Length.
     */
    public function test_webhook_uses_the_acknowledgement_helper(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/jedomi/telegram_webhook.php');

        $this->assertStringNotContainsString('Content-Length: 15', $source);
        $this->assertSame(2, substr_count($source, 'webhook_handler::acknowledge()'));
    }
    /**
     * Dropping pending updates registers the webhook again, so Telegram keeps delivering new messages.
     *
     * deleteWebhook alone clears the queue but also removes the webhook URL, and the bot stops answering.
     */
    public function test_drop_pending_registers_the_webhook_again(): void {
        global $CFG;
        $source = file_get_contents($CFG->dirroot . '/local/jedomi/ajax/telegram_drop_pending.php');

        $this->assertStringContainsString('manager::register_webhook(null, true)', $source);
        $this->assertStringNotContainsString('->delete_webhook()', $source);
    }
}
