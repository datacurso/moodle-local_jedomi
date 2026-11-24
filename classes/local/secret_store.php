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

/**
 * Secrets of local_jedomi kept encrypted in the plugin settings with the key of the site (JEDOMI-SEC-010).
 *
 * The values are encrypted with \core\encryption, whose key is a file in dataroot: a dump of the database alone (a
 * backup, a replica) does not reveal them. A value saved in clear text before it was kept encrypted (1.7.0 for the bot
 * token and the custom API key, 1.8.0 for the HMAC, permission token and webhook secrets) is still read, and the upgrade
 * encrypts it (local_jedomi_upgrade_encrypt_secrets()).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class secret_store {
    /** @var string Component whose settings hold the secrets. */
    private const COMPONENT = 'local_jedomi';

    /** @var string Pattern of a value encrypted by \core\encryption: its method, a colon and base64 data. */
    private const ENCRYPTED_PATTERN = '~^(?:sodium|openssl-aes-256-ctr):[A-Za-z0-9+/=]+$~';

    /**
     * Whether a stored value was encrypted by \core\encryption.
     *
     * @param string $stored Stored value.
     * @return bool
     */
    public static function is_encrypted(string $stored): bool {
        return preg_match(self::ENCRYPTED_PATTERN, $stored) === 1;
    }

    /**
     * Encrypt a secret for storage; an empty secret stays empty.
     *
     * @param string $plain Secret.
     * @return string
     */
    public static function encrypt(string $plain): string {
        return $plain === '' ? '' : \core\encryption::encrypt($plain);
    }

    /**
     * Read a stored secret: decrypted when encrypted, as it is when saved in clear text before it was kept encrypted.
     *
     * @param string $stored Stored value.
     * @return string
     * @throws \moodle_exception When an encrypted value cannot be decrypted (another site key, altered value).
     */
    public static function decrypt(string $stored): string {
        return self::is_encrypted($stored) ? \core\encryption::decrypt($stored) : $stored;
    }

    /**
     * Read a secret setting of local_jedomi; empty when it is not saved or cannot be decrypted (the failure is logged
     * without the value).
     *
     * @param string $name Setting name.
     * @return string
     */
    public static function get(string $name): string {
        $stored = (string) get_config(self::COMPONENT, $name);
        try {
            $plain = self::decrypt($stored);
        } catch (\moodle_exception $e) {
            require_once(__DIR__ . '/../../lib.php');
            \local_jedomi_log('[local_jedomi] A secret setting could not be decrypted', ['name' => $name]);
            self::mark_unreadable($name);
            return '';
        }
        self::clear_unreadable($name);
        return $plain;
    }

    /**
     * Name of the setting that records that a secret could not be decrypted.
     *
     * @param string $name Setting name of the secret.
     * @return string
     */
    private static function unreadable_flag(string $name): string {
        return 'secret_unreadable_' . $name;
    }

    /**
     * Record that a stored secret could not be decrypted (the key of the site changed or the value was altered), so the
     * administrator is warned to enter it again.
     *
     * @param string $name Setting name of the secret.
     * @return void
     */
    public static function mark_unreadable(string $name): void {
        if (!get_config(self::COMPONENT, self::unreadable_flag($name))) {
            set_config(self::unreadable_flag($name), 1, self::COMPONENT);
        }
    }

    /**
     * Forget that a secret could not be decrypted: it was saved again, removed or decrypts again.
     *
     * @param string $name Setting name of the secret.
     * @return void
     */
    public static function clear_unreadable(string $name): void {
        if (get_config(self::COMPONENT, self::unreadable_flag($name)) !== false) {
            unset_config(self::unreadable_flag($name), self::COMPONENT);
        }
    }

    /**
     * Whether a stored secret cannot be decrypted. The secret is read, so the record follows its current state.
     *
     * @param string $name Setting name of the secret.
     * @return bool
     */
    public static function is_unreadable(string $name): bool {
        self::get($name);
        return (bool) get_config(self::COMPONENT, self::unreadable_flag($name));
    }

    /**
     * Read a secret the plugin generates itself, generating and saving a random one (encrypted) when none is saved,
     * when the saved one cannot be decrypted or when it is a rejected value (a former built-in key). A secret saved in
     * clear text before 1.8.0 is read as it is.
     *
     * @param string $name Setting name.
     * @param int $bytes Random bytes of a generated secret; the secret is their hexadecimal form.
     * @param string[] $rejected Values that are never accepted as the secret.
     * @return string Never empty.
     */
    public static function get_or_generate(string $name, int $bytes, array $rejected = []): string {
        $secret = self::get($name);
        if ($secret === '' || in_array($secret, $rejected, true)) {
            $secret = bin2hex(random_bytes($bytes));
            self::set($name, $secret);
        }
        return $secret;
    }

    /**
     * Encrypt a secret setting saved in clear text before it was kept encrypted; an empty or encrypted value is left
     * as it is. No config log row is added: the value does not change.
     *
     * @param string $name Setting name.
     * @return bool Whether the value was encrypted now.
     */
    public static function encrypt_legacy(string $name): bool {
        $stored = (string) get_config(self::COMPONENT, $name);
        if ($stored === '' || self::is_encrypted($stored)) {
            return false;
        }
        self::set($name, $stored);
        return true;
    }

    /**
     * Save a secret setting of local_jedomi encrypted.
     *
     * @param string $name Setting name.
     * @param string $plain Secret; empty clears it.
     * @return void
     */
    public static function set(string $name, string $plain): void {
        set_config($name, self::encrypt($plain), self::COMPONENT);
        self::clear_unreadable($name);
    }
}
