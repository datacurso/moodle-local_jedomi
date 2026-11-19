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

namespace local_jedomi\local;

use core\hook\output\before_footer_html_generation;
use local_jedomi\hook_callbacks;
use local_jedomi\telegram\manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * JEDOMI-SEC-010: the companion HMAC secret, the permission token secret and the Telegram webhook secret are kept
 * encrypted with the key of the site, values saved in clear text before 1.8.0 are still read, and every check that uses
 * them keeps working.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\secret_store
 * @covers     \local_jedomi\companion_hmac
 * @covers     \local_jedomi\hook_callbacks
 * @covers     \local_jedomi\telegram\manager
 * @covers     ::local_jedomi_get_spt_secret
 */
final class secrets_at_rest_test extends \advanced_testcase {
    /** @var string Header in which Telegram sends the webhook secret. */
    private const HEADER = 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN';

    /**
     * Reset the database after each test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Assert that a setting of local_jedomi is stored encrypted and does not contain its secret.
     *
     * @param string $name Setting name.
     * @param string $plain Secret it holds.
     * @return void
     */
    private function assert_stored_encrypted(string $name, string $plain): void {
        $stored = (string) get_config('local_jedomi', $name);
        $this->assertTrue(secret_store::is_encrypted($stored), "$name must be stored encrypted");
        $this->assertStringNotContainsString($plain, $stored);
        $this->assertSame($plain, secret_store::get($name));
    }

    /**
     * Check a companion signature with the HMAC secret of the site.
     *
     * @param string $secret Secret the signature is made with.
     * @return string 'accepted' or the error code.
     */
    private function companion_outcome(string $secret): string {
        $checker = new class {
            use \local_jedomi\companion_hmac;

            /**
             * Check a signature made with the given secret.
             *
             * @param string $secret Secret.
             * @return void
             */
            public static function check(string $secret): void {
                $timestamp = time();
                $hash = self::build_payload_hash(['sql' => 'SELECT 1']);
                $signature = hash_hmac('sha256', "jedomi_execute_sql:{$timestamp}:{$hash}", $secret);
                self::verify_hmac('jedomi_execute_sql', $timestamp, $hash, $signature, ['sql' => 'SELECT 1']);
            }
        };
        try {
            $checker::check($secret);
            return 'accepted';
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
    }

    /**
     * The HMAC secret generated on the first signed request is stored encrypted and signs the next requests.
     *
     * @return void
     */
    public function test_generated_hmac_secret_is_encrypted_and_validates(): void {
        unset_config('hmac_secret', 'local_jedomi');

        $this->assertSame('unauthorized', $this->companion_outcome(str_repeat('0', 64)));

        $secret = secret_store::get('hmac_secret');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assert_stored_encrypted('hmac_secret', $secret);
        $this->assertSame('accepted', $this->companion_outcome($secret));
    }

    /**
     * The HMAC secret generated when a page is shown is stored encrypted.
     *
     * @return void
     */
    public function test_hmac_secret_generated_by_the_page_footer_is_encrypted(): void {
        global $PAGE;
        unset_config('hmac_secret', 'local_jedomi');

        hook_callbacks::before_footer(new before_footer_html_generation($PAGE->get_renderer('core')));

        $secret = secret_store::get('hmac_secret');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assert_stored_encrypted('hmac_secret', $secret);
    }

    /**
     * An HMAC secret saved in clear text before 1.8.0 still validates the signatures made with it.
     *
     * @return void
     */
    public function test_legacy_plain_hmac_secret_still_validates(): void {
        set_config('hmac_secret', 'legacy-plain-hmac-secret', 'local_jedomi');

        $this->assertSame('accepted', $this->companion_outcome('legacy-plain-hmac-secret'));
        $this->assertSame('legacy-plain-hmac-secret', get_config('local_jedomi', 'hmac_secret'), 'Reading does not rewrite it');
    }

    /**
     * The permission token secret generated for a site without one (or with the former built-in key) is stored
     * encrypted and read back the same.
     *
     * @return void
     */
    public function test_generated_spt_secret_is_encrypted(): void {
        foreach (['', 'jedomi-spt-secret'] as $old) {
            set_config('spt_secret', $old, 'local_jedomi');

            $secret = local_jedomi_get_spt_secret();

            $this->assertGreaterThanOrEqual(64, strlen($secret));
            $this->assert_stored_encrypted('spt_secret', $secret);
            $this->assertSame($secret, local_jedomi_get_spt_secret(), 'The secret is kept once generated');
        }
    }

    /**
     * The former built-in key is replaced even when it was stored encrypted.
     *
     * @return void
     */
    public function test_encrypted_builtin_spt_key_is_replaced(): void {
        secret_store::set('spt_secret', 'jedomi-spt-secret');

        $this->assertNotSame('jedomi-spt-secret', local_jedomi_get_spt_secret());
    }

    /**
     * A permission token secret saved in clear text before 1.8.0 is still read and signs the tokens.
     *
     * @return void
     */
    public function test_legacy_plain_spt_secret_is_still_read(): void {
        set_config('spt_secret', 'legacy-plain-spt-secret', 'local_jedomi');

        $this->assertSame('legacy-plain-spt-secret', local_jedomi_get_spt_secret());
        $spt = local_jedomi_generate_spt_for_user((int) get_admin()->id, 'admin');
        $signature = $spt['signature'];
        unset($spt['signature']);
        $this->assertSame(hash_hmac('sha256', local_jedomi_spt_signing_string($spt), 'legacy-plain-spt-secret'), $signature);
    }

    /**
     * The permission token secret generated when the plugin is installed is stored encrypted.
     *
     * @return void
     */
    public function test_installed_spt_secret_is_encrypted(): void {
        $stored = (string) get_config('local_jedomi', 'spt_secret');

        // The key of the test site is created again after the installation, so only the stored form is checked here.
        $this->assertTrue(secret_store::is_encrypted($stored));
        $this->assertDoesNotMatchRegularExpression('/^[0-9a-f]{64}$/', $stored);
    }

    /**
     * The webhook secret generated the first time it is needed is stored encrypted and validates the Telegram header.
     *
     * @return void
     */
    public function test_generated_webhook_secret_is_encrypted_and_validates(): void {
        unset_config('telegram_webhook_secret', 'local_jedomi');

        $secret = manager::get_webhook_secret();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $secret);
        $this->assert_stored_encrypted('telegram_webhook_secret', $secret);
        $this->assertSame($secret, manager::get_webhook_secret(), 'The secret is kept once generated');
        if (!manager::needs_webhook_registration()) {
            $this->assertTrue(manager::is_valid_webhook_request([self::HEADER => $secret]));
            $this->assertFalse(manager::is_valid_webhook_request([self::HEADER => (string) get_config('local_jedomi',
                'telegram_webhook_secret')]), 'The stored value is not the secret');
        }
    }

    /**
     * A replaced webhook secret is stored encrypted.
     *
     * @return void
     */
    public function test_replaced_webhook_secret_is_encrypted(): void {
        manager::replace_webhook_secret();

        $secret = manager::get_webhook_secret();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $secret);
        $this->assert_stored_encrypted('telegram_webhook_secret', $secret);
    }

    /**
     * A webhook secret saved in clear text before 1.8.0 is still read.
     *
     * @return void
     */
    public function test_legacy_plain_webhook_secret_is_still_read(): void {
        set_config('telegram_webhook_secret', 'legacyplainwebhooksecret', 'local_jedomi');

        $this->assertSame('legacyplainwebhooksecret', manager::get_webhook_secret());
    }
}
