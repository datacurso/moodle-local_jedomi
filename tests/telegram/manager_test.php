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
 * Unit tests for classes/telegram/manager.php in local_jedomi.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/link_code_test_clock.php');

/**
 * Tests for the Telegram manager: link codes and bot token encryption.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
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
     * Encrypt a token as 1.6.x did: AES-256-GCM with a key derived from the site salt (or identifier) and a constant.
     *
     * @param string $token Token.
     * @return string
     */
    private static function legacy_encrypt(string $token): string {
        global $CFG;
        $key = hash('sha256', ($CFG->passwordsaltmain ?? $CFG->siteidentifier ?? 'jedomi_default_secret')
            . 'jedomi_telegram_pepper_v1', true);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        return base64_encode($nonce . $cipher . $tag);
    }

    /**
     * JEDOMI-SEC-010: the token is encrypted with the key of the site (in dataroot), not with a key derived from the
     * database; a token saved by 1.6.x is still read and the upgrade encrypts it again.
     *
     * @return void
     */
    public function test_token_uses_the_site_key_and_legacy_tokens_are_reencrypted(): void {
        $this->resetAfterTest();
        $token = '123456789:legacy-token';

        $this->assertMatchesRegularExpression('/^sodium:/', manager::encrypt($token));

        set_config('telegram_bot_token', self::legacy_encrypt($token), 'local_jedomi');
        $this->assertSame($token, manager::get_bot_token(), 'A token of 1.6.x is still read');

        $this->assertTrue(manager::reencrypt_legacy_bot_token());
        $stored = get_config('local_jedomi', 'telegram_bot_token');
        $this->assertMatchesRegularExpression('/^sodium:/', $stored);
        $this->assertSame($token, \core\encryption::decrypt($stored));
        $this->assertSame($token, manager::get_bot_token());
        $this->assertFalse(manager::reencrypt_legacy_bot_token(), 'A token already encrypted is left as it is');
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
        [$method, $data] = explode(':', manager::encrypt('123456:secret-token'), 2);
        $raw = base64_decode($data);
        switch ($alteration) {
            case 'flip':
                $raw[30] = chr(ord($raw[30]) ^ 0x01);
                $cipher = $method . ':' . base64_encode($raw);
                break;
            case 'truncate':
                $cipher = $method . ':' . base64_encode(substr($raw, 0, -4));
                break;
            case 'short':
                $cipher = $method . ':' . base64_encode(substr($raw, 0, 20));
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
        [$method, $data] = explode(':', manager::encrypt('123456789:stored-token'), 2);
        $raw = base64_decode($data);
        $raw[30] = chr(ord($raw[30]) ^ 0xff);
        set_config('telegram_bot_token', $method . ':' . base64_encode($raw), 'local_jedomi');

        $this->assertNull(manager::get_bot_token());

        $this->assertFileExists($logfile);
        $log = file_get_contents($logfile);
        $this->assertStringContainsString('Failed to decrypt Telegram bot token', $log);
        $this->assertStringNotContainsString('stored-token', $log);
    }

    /**
     * MDL-INT-016: the webhook secret is generated the first time it is needed and kept afterwards; the webhook address
     * never carries it (JEDOMI-SEC-004: Telegram sends it in a header).
     */
    public function test_webhook_address_has_a_secret_generated_once(): void {
        global $CFG;
        $this->resetAfterTest();
        unset_config('telegram_webhook_secret', 'local_jedomi');

        $url = manager::get_webhook_url();
        $secret = manager::get_webhook_secret();

        $this->assertNotSame('', $secret);
        $this->assertSame($secret, \local_jedomi\local\secret_store::get('telegram_webhook_secret'));
        $this->assertSame($CFG->wwwroot . '/local/jedomi/telegram_webhook.php', $url);
        $this->assertSame($secret, manager::get_webhook_secret(), 'The secret is kept once generated');
    }

    /**
     * MDL-INT-016: regenerating the secret registers the webhook again and the previous secret is no longer the one the
     * webhook accepts; the address stays the same.
     */
    public function test_regenerating_the_secret_changes_the_address_and_registers_it_again(): void {
        $this->resetAfterTest();
        $oldurl = manager::get_webhook_url();
        $oldsecret = manager::get_webhook_secret();
        manager::clear_last_webhook_error();

        $newsecret = manager::regenerate_webhook_secret();

        $this->assertNotSame($oldsecret, $newsecret);
        $this->assertSame($oldurl, manager::get_webhook_url());
        $this->assertSame($newsecret, manager::get_webhook_secret(), 'Only the new secret is accepted');
        $this->assertFalse(hash_equals(manager::get_webhook_secret(), $oldsecret));
        // Without a bot token the registration is attempted and its result recorded for the administrator.
        $this->assertSame(get_string('telegram_token_not_configured', 'local_jedomi'), manager::get_last_webhook_error());
        $this->assertGreaterThan(0, manager::get_last_webhook_error_time());
    }

    /**
     * The webhook errors shown in the Integrations tab are language strings, read in the language of whoever opens the
     * tab; an error stored by an earlier version as text is shown as it was stored.
     */
    public function test_webhook_errors_are_shown_in_the_language_of_the_reader(): void {
        $this->resetAfterTest();
        $client = new class ('123:abc') extends \local_jedomi\telegram\api_client {
            /**
             * Telegram refuses the webhook.
             *
             * @param string $url
             * @param string $secrettoken
             * @param bool $droppending
             * @return bool
             */
            public function set_webhook(string $url, string $secrettoken, bool $droppending = false): bool {
                return false;
            }
        };
        set_config('telegram_bot_token', '', 'local_jedomi');

        manager::register_webhook($client);
        $this->assertSame(get_string('telegram_token_not_configured', 'local_jedomi'), manager::get_last_webhook_error());

        manager::set_bot_token('123:abc');
        manager::register_webhook($client);
        $this->assertSame(get_string('telegram_webhook_set_failed', 'local_jedomi'), manager::get_last_webhook_error());
        $this->assertSame('telegram_webhook_set_failed', get_config('local_jedomi', 'telegram_webhook_last_error'));
        manager::register_webhook_with_new_secret($client);
        $this->assertSame(get_string('telegram_webhook_set_failed', 'local_jedomi'), manager::get_last_webhook_error());

        manager::set_last_webhook_error('setWebhook failed — legacy text');
        $this->assertSame('setWebhook failed — legacy text', manager::get_last_webhook_error());
    }

    /**
     * A value encrypted with the key of the site and then altered, as after a change of that key.
     *
     * @return string
     */
    private static function unreadable_secret(): string {
        [$method, $data] = explode(':', \local_jedomi\local\secret_store::encrypt('123:abc'), 2);
        $raw = base64_decode($data);
        $raw[30] = chr(ord($raw[30]) ^ 0x01);
        return $method . ':' . base64_encode($raw);
    }

    /**
     * A bot token that cannot be decrypted is recorded as unreadable until a token is saved again.
     *
     * @return void
     */
    public function test_unreadable_bot_token_is_recorded_until_saved_again(): void {
        $this->resetAfterTest();
        $this->assertFalse(manager::is_bot_token_unreadable(), 'No token is not an unreadable token');
        set_config('telegram_bot_token', self::unreadable_secret(), 'local_jedomi');

        $this->assertNull(manager::get_bot_token());
        $this->assertTrue(manager::is_bot_token_unreadable());
        $this->assertSame('1', get_config('local_jedomi', 'secret_unreadable_telegram_bot_token'));

        manager::set_bot_token('123:new');

        $this->assertFalse(manager::is_bot_token_unreadable());
        $this->assertFalse(get_config('local_jedomi', 'secret_unreadable_telegram_bot_token'));
        $this->assertSame('123:new', manager::get_bot_token());
    }
}
