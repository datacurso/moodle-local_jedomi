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

namespace local_jedomi\telegram;

/**
 * Clock of the cache API, moved by the tests to check when link codes expire.
 *
 * The cache API takes every TTL decision from cache::now(); moving it simulates time passing.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class link_code_test_clock extends \cache {
    /**
     * Set the current time seen by the cache API.
     *
     * @param float $now Timestamp.
     * @return void
     */
    public static function set_now(float $now): void {
        self::$now = $now;
    }

    /**
     * Restore the real clock.
     *
     * @return void
     */
    public static function reset(): void {
        self::$now = null;
    }
}

/**
 * Tests for the Telegram manager: link codes and bot token encryption.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\manager
 */
final class manager_test extends \advanced_testcase {
    /** @var string Alphabet of link codes: no 0, O, 1 or I. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @var int Validity of a link code in seconds. */
    private const CODE_TTL = 600;

    /**
     * Restore the cache clock after each test.
     *
     * @return void
     */
    protected function tearDown(): void {
        link_code_test_clock::reset();
        parent::tearDown();
    }

    /**
     * MDL-UNIT-061: generated codes have the defined length and alphabet, and consecutive codes differ.
     *
     * @return void
     */
    public function test_link_codes_have_the_defined_format_and_differ(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $codes = [];
        for ($i = 0; $i < 20; $i++) {
            $codes[] = manager::generate_link_code((int) $user->id);
        }

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[' . self::CODE_ALPHABET . ']{6}$/', $code);
        }
        for ($i = 1; $i < count($codes); $i++) {
            $this->assertNotSame($codes[$i - 1], $codes[$i]);
        }
    }

    /**
     * MDL-UNIT-061: only the latest code of a user is valid and it resolves back to the user.
     *
     * @return void
     */
    public function test_latest_code_is_valid_and_resolves_to_its_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $code = manager::generate_link_code((int) $user->id);

        $this->assertTrue(manager::validate_link_code((int) $user->id, $code));
        $this->assertTrue(manager::validate_link_code((int) $user->id, strtolower($code)));
        $this->assertSame((int) $user->id, manager::resolve_link_code($code));
        $this->assertFalse(manager::validate_link_code((int) $user->id + 1, $code));

        manager::invalidate_link_code((int) $user->id, $code);
        $this->assertFalse(manager::validate_link_code((int) $user->id, $code));
        $this->assertNull(manager::resolve_link_code($code));
    }

    /**
     * MDL-UNIT-061: a code is valid during its validity and stops being valid once it ends.
     *
     * @return void
     */
    public function test_link_code_expires_when_its_validity_ends(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $start = (float) time();
        link_code_test_clock::set_now($start);

        $code = manager::generate_link_code((int) $user->id);

        foreach ([1, self::CODE_TTL - 1] as $elapsed) {
            link_code_test_clock::set_now($start + $elapsed);
            $this->assertTrue(manager::validate_link_code((int) $user->id, $code), "Valid after {$elapsed}s");
            $this->assertSame((int) $user->id, manager::resolve_link_code($code), "Resolves after {$elapsed}s");
        }
        foreach ([self::CODE_TTL + 1, self::CODE_TTL * 2] as $elapsed) {
            link_code_test_clock::set_now($start + $elapsed);
            $this->assertFalse(manager::validate_link_code((int) $user->id, $code), "Expired after {$elapsed}s");
            $this->assertNull(manager::resolve_link_code($code), "Not resolved after {$elapsed}s");
        }
    }

    /**
     * MDL-UNIT-098: an encrypted token is recovered identical when decrypted.
     *
     * @return void
     */
    public function test_encrypted_token_is_recovered_identical(): void {
        foreach (['123456:ABC-def_ghi', 'x', str_repeat('7', 300), 'ñandú:€'] as $token) {
            $this->assertSame($token, manager::decrypt(manager::encrypt($token)));
        }
    }

    /**
     * MDL-UNIT-098: encrypting the same token twice gives different results and neither contains the readable token.
     *
     * @return void
     */
    public function test_encryption_is_randomised_and_hides_the_token(): void {
        $token = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

        $first = manager::encrypt($token);
        $second = manager::encrypt($token);

        $this->assertNotSame($first, $second);
        foreach ([$first, $second] as $cipher) {
            $this->assertStringNotContainsString($token, $cipher);
            $this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw', base64_decode($cipher));
        }
    }

    /**
     * Encrypted values that have been altered, truncated or are not valid.
     *
     * @return array
     */
    public static function altered_cipher_provider(): array {
        return [
            'one byte of the content flipped' => ['flip'],
            'tag removed' => ['truncate'],
            'too short' => ['short'],
            'not base64' => ['notbase64'],
            'empty' => ['empty'],
        ];
    }

    /**
     * MDL-UNIT-098: an altered, truncated or invalid encrypted value is rejected when decrypting.
     *
     * @dataProvider altered_cipher_provider
     * @param string $alteration Kind of alteration.
     * @return void
     */
    public function test_altered_cipher_is_rejected(string $alteration): void {
        $raw = base64_decode(manager::encrypt('123456:secret-token'));
        switch ($alteration) {
            case 'flip':
                $raw[14] = chr(ord($raw[14]) ^ 0x01);
                $cipher = base64_encode($raw);
                break;
            case 'truncate':
                $cipher = base64_encode(substr($raw, 0, -4));
                break;
            case 'short':
                $cipher = base64_encode(substr($raw, 0, 20));
                break;
            case 'notbase64':
                $cipher = '***not base64***';
                break;
            default:
                $cipher = '';
        }

        $this->expectException(\moodle_exception::class);
        manager::decrypt($cipher);
    }

    /**
     * MDL-UNIT-098: the token is stored only encrypted and is recovered intact.
     *
     * @return void
     */
    public function test_stored_token_is_encrypted_and_recovered(): void {
        $this->resetAfterTest();
        $token = '123456789:stored-token';

        manager::set_bot_token($token);

        $stored = get_config('local_jedomi', 'telegram_bot_token');
        $this->assertStringNotContainsString($token, $stored);
        $this->assertSame($token, manager::get_bot_token());
    }

    /**
     * MDL-UNIT-098: a stored token that cannot be decrypted is treated as no bot token and the failure is logged.
     *
     * @return void
     */
    public function test_undecryptable_stored_token_is_treated_as_not_configured(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');
        $this->resetAfterTest();
        $logfile = $CFG->dataroot . '/local/jedomi/jedomi.log';
        @unlink($logfile);
        $raw = base64_decode(manager::encrypt('123456789:stored-token'));
        $raw[13] = chr(ord($raw[13]) ^ 0xff);
        set_config('telegram_bot_token', base64_encode($raw), 'local_jedomi');

        $this->assertNull(manager::get_bot_token());

        $this->assertFileExists($logfile);
        $log = file_get_contents($logfile);
        $this->assertStringContainsString('Failed to decrypt Telegram bot token', $log);
        $this->assertStringNotContainsString('stored-token', $log);
    }

    /**
     * MDL-INT-016: the webhook address carries a secret generated the first time it is needed and kept afterwards.
     */
    public function test_webhook_address_has_a_secret_generated_once(): void {
        global $CFG;
        $this->resetAfterTest();
        unset_config('telegram_webhook_secret', 'local_jedomi');

        $url = manager::get_webhook_url();

        $secret = (string) get_config('local_jedomi', 'telegram_webhook_secret');
        $this->assertNotSame('', $secret);
        $this->assertSame($CFG->wwwroot . '/local/jedomi/telegram_webhook.php?secret=' . $secret, $url);
        $this->assertSame($url, manager::get_webhook_url(), 'The secret is kept once generated');
    }

    /**
     * MDL-INT-016: regenerating the secret changes the address, registers it again and the previous secret is no longer
     * the one the webhook accepts.
     */
    public function test_regenerating_the_secret_changes_the_address_and_registers_it_again(): void {
        $this->resetAfterTest();
        $oldurl = manager::get_webhook_url();
        $oldsecret = manager::get_webhook_secret();
        manager::clear_last_webhook_error();

        $newsecret = manager::regenerate_webhook_secret();

        $this->assertNotSame($oldsecret, $newsecret);
        $this->assertNotSame($oldurl, manager::get_webhook_url());
        $this->assertSame($newsecret, manager::get_webhook_secret(), 'Only the new secret is accepted');
        $this->assertFalse(hash_equals(manager::get_webhook_secret(), $oldsecret));
        // Without a bot token the registration is attempted and its result recorded for the administrator.
        $this->assertNotSame('', manager::get_last_webhook_error());
        $this->assertGreaterThan(0, manager::get_last_webhook_error_time());
    }
}
