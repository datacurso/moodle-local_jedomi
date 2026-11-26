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

/**
 * Telegram manager — token encryption, config helpers, and rate limiting.
 *
 * Security:
 *  - Bot token is encrypted with AES-256-GCM before storage.
 *  - Key is derived from $CFG->passwordsaltmain + a fixed pepper (defense in depth).
 *  - Plaintext token only exists in memory during HTTP request lifecycle.
 *  - Webhook secret is random hex 32 chars, rotated independently of the token.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\messaging\access_guard;
use local_jedomi\messaging\confirmation_service;
use local_jedomi\messaging\rate_limiter;
use local_jedomi\messaging\tool_runner;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Telegram integration manager: encrypted bot token storage, config helpers and rate limiting.
 */
class manager {
    /** Constant of the key derivation of 1.6.x, kept only to read a token saved then. */
    private const PEPPER = 'jedomi_telegram_pepper_v1';

    /** Tools the Telegram channel can run; any other tool is never offered to the AI here. */
    private const SUPPORTED_TOOLS = [
        'describe_table', 'search_tables', 'execute_sql', 'get_site_config', 'site_overview', 'ws_search', 'ws_describe',
        'ws_execute', 'admin_search', 'admin_describe', 'admin_execute', 'create_agent', 'list_agents',
        'delete_agent', 'my_courses', 'my_grades', 'my_assignments',
    ];

    /** TTL for linking codes (seconds). */
    private const CODE_TTL = 600; // 10 minutes

    /** Characters of a link code: no 0, O, 1 or I, which are easy to confuse. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Length of a link code. */
    private const CODE_LENGTH = 6;

    /** @var string Server key of the header in which Telegram sends the webhook secret. */
    private const SECRET_HEADER_KEY = 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN';

    /** @var string Setting that records a webhook the update could not register again with the new secret. */
    private const NEEDS_REGISTRATION_KEY = 'telegram_webhook_needs_registration';

    /**
     * Key of the encryption used up to 1.6.x: derived from a value of the database and a constant of this code, so a
     * dump of the database was enough to rebuild it (JEDOMI-SEC-010). Only used to read a token saved then.
     *
     * @return string 32-byte raw key
     */
    private static function legacy_key(): string {
        global $CFG;
        $secret = $CFG->passwordsaltmain ?? $CFG->siteidentifier ?? 'jedomi_default_secret';
        return hash('sha256', $secret . self::PEPPER, true);
    }

    /**
     * Encrypt a secret with the key of the site, kept in dataroot outside the database (\core\encryption).
     *
     * @param string $plaintext
     * @return string Encrypted value, prefixed with its method
     * @throws \moodle_exception encryption_failed
     */
    public static function encrypt(string $plaintext): string {
        try {
            $encrypted = \local_jedomi\local\secret_store::encrypt($plaintext);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('encryption_failed', 'local_jedomi');
        }
        if ($encrypted === '') {
            throw new \moodle_exception('encryption_failed', 'local_jedomi');
        }
        return $encrypted;
    }

    /**
     * Decrypt a value produced by self::encrypt(), or by the AES-256-GCM encryption of 1.6.x (nonce, ciphertext and tag
     * in base64), which the upgrade encrypts again with the key of the site.
     *
     * @param string $ciphertextbundle Encrypted value
     * @return string Plaintext
     * @throws \moodle_exception decryption_failed
     */
    public static function decrypt(string $ciphertextbundle): string {
        if (\local_jedomi\local\secret_store::is_encrypted($ciphertextbundle)) {
            try {
                return \local_jedomi\local\secret_store::decrypt($ciphertextbundle);
            } catch (\moodle_exception $e) {
                throw new \moodle_exception('decryption_failed', 'local_jedomi');
            }
        }
        return self::legacy_decrypt($ciphertextbundle);
    }

    /**
     * Decrypt a token encrypted by 1.6.x with AES-256-GCM.
     *
     * @param string $ciphertextbundle Base64 of nonce (12 bytes), ciphertext and tag (16 bytes)
     * @return string Plaintext
     * @throws \moodle_exception decryption_failed
     */
    private static function legacy_decrypt(string $ciphertextbundle): string {
        $raw = base64_decode($ciphertextbundle, true);
        if ($raw === false || strlen($raw) < 29) { // 12 nonce + 1 min ciphertext + 16 tag
            throw new \moodle_exception('decryption_failed', 'local_jedomi');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ciphertext = substr($raw, 12, -16);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', self::legacy_key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new \moodle_exception('decryption_failed', 'local_jedomi');
        }
        return $plaintext;
    }

    /**
     * Encrypt again with the key of the site a bot token saved by 1.6.x (JEDOMI-SEC-010). A token already encrypted
     * with the key of the site, or none, is left as it is; a token that cannot be read is left too (the page asks for it
     * again).
     *
     * @return bool True when the token was encrypted again.
     */
    public static function reencrypt_legacy_bot_token(): bool {
        $stored = (string) get_config('local_jedomi', 'telegram_bot_token');
        if ($stored === '' || \local_jedomi\local\secret_store::is_encrypted($stored)) {
            return false;
        }
        try {
            $token = self::legacy_decrypt($stored);
        } catch (\moodle_exception $e) {
            return false;
        }
        self::set_bot_token($token);
        return true;
    }

    /**
     * Store the bot token encrypted in config.
     *
     * @param string $token Plaintext bot token from @BotFather
     */
    public static function set_bot_token(string $token): void {
        set_config('telegram_bot_token', self::encrypt($token), 'local_jedomi');
        \local_jedomi\local\secret_store::clear_unreadable('telegram_bot_token');
    }

    /**
     * Retrieve and decrypt the bot token.
     *
     * @return string|null Plaintext token, or null if not configured
     */
    public static function get_bot_token(): ?string {
        $encrypted = get_config('local_jedomi', 'telegram_bot_token');
        if (empty($encrypted)) {
            \local_jedomi\local\secret_store::clear_unreadable('telegram_bot_token');
            return null;
        }
        try {
            $token = self::decrypt($encrypted);
        } catch (\Exception $e) {
            \local_jedomi_log('[local_jedomi] Failed to decrypt Telegram bot token');
            \local_jedomi\local\secret_store::mark_unreadable('telegram_bot_token');
            return null;
        }
        \local_jedomi\local\secret_store::clear_unreadable('telegram_bot_token');
        return $token;
    }

    /**
     * Whether a bot token is stored but cannot be decrypted (the key of the site changed or the value was altered).
     *
     * @return bool
     */
    public static function is_bot_token_unreadable(): bool {
        return self::get_bot_token() === null && !empty(get_config('local_jedomi', 'telegram_bot_token'));
    }

    /**
     * Generate or retrieve the webhook secret, kept encrypted with the key of the site (JEDOMI-SEC-010); one saved in
     * clear text before 1.8.0 is still read.
     *
     * @return string 32-char hex random secret
     */
    public static function get_webhook_secret(): string {
        return \local_jedomi\local\secret_store::get_or_generate('telegram_webhook_secret', 16);
    }

    /**
     * New random webhook secret, not stored.
     *
     * @return string 32-char hex random secret
     */
    public static function new_webhook_secret(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Replace the stored webhook secret with a new one without registering it in Telegram.
     *
     * @return void
     */
    public static function replace_webhook_secret(): void {
        \local_jedomi\local\secret_store::set('telegram_webhook_secret', self::new_webhook_secret());
    }

    /**
     * Regenerate the webhook secret (invalidates old webhooks)
     * AND automatically updates the webhook URL in Telegram.
     *
     * After changing the secret locally, this method sets the webhook again with the fresh secret and drops the
     * pending updates.
     *
     * If the bot token is not configured, only the local secret is rotated
     * (the webhook registration will happen when the token is saved later).
     *
     * @return string The new secret
     */
    public static function regenerate_webhook_secret(): string {
        $secret = self::new_webhook_secret();
        \local_jedomi\local\secret_store::set('telegram_webhook_secret', $secret);

        // Update Telegram webhook with the new secret.
        self::register_webhook(null, true);

        return $secret;
    }

    /**
     * Register (or re-register) the webhook URL in Telegram using the current
     * bot token and current webhook secret.
     *
     * setWebhook replaces the previous webhook at once, so it is never deleted first: a failed registration leaves
     * the previous webhook working. Pending updates are dropped by setWebhook itself when asked.
     *
     * Stores the result (success/error) in config for UI visibility.
     *
     * @param api_client|null $client Client of the stored token; built from it when null.
     * @param bool $droppending Whether Telegram drops the updates waiting to be delivered.
     * @return bool Whether the webhook was registered successfully
     */
    public static function register_webhook(?api_client $client = null, bool $droppending = false): bool {
        $token = self::get_bot_token();
        if (!$token) {
            self::set_last_webhook_error('telegram_token_not_configured');
            return false;
        }

        $client = $client ?? new api_client($token);
        $ok = $client->set_webhook(self::get_webhook_url(), self::get_webhook_secret(), $droppending);
        if ($ok) {
            self::clear_last_webhook_error();
        } else {
            self::set_last_webhook_error('telegram_webhook_set_failed');
        }

        return $ok;
    }

    /**
     * Register the webhook with a new secret, stored only once Telegram accepted it.
     *
     * The stored secret is therefore always the one Telegram sends: when setWebhook fails, the stored secret and the
     * webhook registered before stay as they were and the error is recorded.
     *
     * @param api_client|null $client Client of the stored token; built from it when null.
     * @param bool $droppending Whether Telegram drops the updates waiting to be delivered.
     * @return bool Whether Telegram accepted the new secret.
     */
    public static function register_webhook_with_new_secret(?api_client $client = null, bool $droppending = false): bool {
        $token = self::get_bot_token();
        if (!$token) {
            self::set_last_webhook_error('telegram_token_not_configured');
            return false;
        }

        $client = $client ?? new api_client($token);
        $secret = self::new_webhook_secret();
        if (!$client->set_webhook(self::get_webhook_url(), $secret, $droppending)) {
            self::set_last_webhook_error('telegram_webhook_set_failed');
            return false;
        }
        \local_jedomi\local\secret_store::set('telegram_webhook_secret', $secret);
        self::clear_last_webhook_error();
        return true;
    }

    /**
     * Drop the updates waiting to be delivered, keeping the webhook registered.
     *
     * While the webhook must be registered again (RES-001) the kept secret is not trusted, so the webhook is registered
     * with a new secret, stored only once Telegram accepts it (FIX-001); otherwise the current secret is kept.
     *
     * @param api_client|null $client Client of the stored token; built from it when null.
     * @return bool Whether Telegram accepted the registration.
     */
    public static function drop_pending_updates(?api_client $client = null): bool {
        if (self::needs_webhook_registration()) {
            return self::register_webhook_with_new_secret($client, true);
        }
        return self::register_webhook($client, true);
    }

    /**
     * Persist the last webhook error so it is visible in the admin UI.
     *
     * The identifier of a language string of local_jedomi is stored, so the Integrations tab shows it in the language of
     * whoever opens it ({@see self::get_last_webhook_error()}).
     *
     * @param string $message Identifier of a language string of local_jedomi.
     */
    public static function set_last_webhook_error(string $message): void {
        set_config('telegram_webhook_last_error', $message, 'local_jedomi');
        set_config('telegram_webhook_error', 1, 'local_jedomi');
        set_config('telegram_webhook_error_time', time(), 'local_jedomi');
        set_config('telegram_webhook_ok_time', 0, 'local_jedomi');
    }

    /**
     * Clear the last webhook error and record the time of the successful registration (marks as healthy).
     */
    public static function clear_last_webhook_error(): void {
        self::clear_webhook_registration_need();
        set_config('telegram_webhook_last_error', '', 'local_jedomi');
        set_config('telegram_webhook_error', 0, 'local_jedomi');
        set_config('telegram_webhook_error_time', 0, 'local_jedomi');
        set_config('telegram_webhook_ok_time', time(), 'local_jedomi');
    }

    /**
     * Forget every registration result: the webhook is neither working nor failing, as with no bot token.
     *
     * @return void
     */
    public static function forget_webhook_state(): void {
        foreach (
            ['telegram_webhook_last_error', 'telegram_webhook_error', 'telegram_webhook_error_time',
                'telegram_webhook_ok_time', self::NEEDS_REGISTRATION_KEY] as $name
        ) {
            unset_config($name, 'local_jedomi');
        }
    }

    /**
     * Record that the webhook must be registered again: the update could not register it with the new secret, so the
     * Integrations tab warns and the webhook refuses every request until the bot token is saved again or a
     * registration succeeds.
     *
     * @return void
     */
    public static function require_webhook_registration(): void {
        set_config(self::NEEDS_REGISTRATION_KEY, 1, 'local_jedomi');
    }

    /**
     * Forget that the webhook must be registered again.
     *
     * @return void
     */
    public static function clear_webhook_registration_need(): void {
        unset_config(self::NEEDS_REGISTRATION_KEY, 'local_jedomi');
    }

    /**
     * Whether the webhook must be registered again (see require_webhook_registration()).
     *
     * @return bool
     */
    public static function needs_webhook_registration(): bool {
        return !empty(get_config('local_jedomi', self::NEEDS_REGISTRATION_KEY));
    }

    /**
     * Get the time of the last successful webhook registration (0 if the last one failed or none was made).
     *
     * @return int
     */
    public static function get_webhook_ok_time(): int {
        return (int) get_config('local_jedomi', 'telegram_webhook_ok_time');
    }

    /**
     * Get the last webhook error message (empty string if none), in the language of the current user.
     *
     * A stored language string identifier is translated; a text stored by an earlier version is returned as it is.
     *
     * @return string
     */
    public static function get_last_webhook_error(): string {
        $error = (string) get_config('local_jedomi', 'telegram_webhook_last_error');
        if (preg_match('/^[a-z_]+$/', $error) && get_string_manager()->string_exists($error, 'local_jedomi')) {
            return get_string($error, 'local_jedomi');
        }
        return $error;
    }

    /**
     * Get the timestamp of the last webhook error (0 if none).
     *
     * @return int
     */
    public static function get_last_webhook_error_time(): int {
        return (int) get_config('local_jedomi', 'telegram_webhook_error_time');
    }

    /**
     * Check if Telegram integration is enabled.
     *
     * @return bool Whether the Telegram integration is enabled.
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_jedomi', 'telegram_enabled');
    }

    /**
     * Get the configured rate limit (messages per minute).
     *
     * @return int Messages per minute; 0 means no limit.
     */
    public static function get_rate_limit(): int {
        return self::rate_limiter()->limit();
    }

    /**
     * Get the configured cooldown in minutes.
     *
     * @return int Minutes.
     */
    public static function get_cooldown(): int {
        return self::rate_limiter()->cooldown_minutes();
    }

    /**
     * Access guard of the Telegram bot: the telegram_enabled and telegram_hidden_roles settings.
     *
     * @return access_guard
     */
    public static function access_guard(): access_guard {
        return new access_guard('telegram');
    }

    /**
     * Rate limiter of the Telegram bot: local_jedomi_telegram_rate_limit with telegram_rate_limit and telegram_cooldown.
     *
     * @return rate_limiter
     */
    public static function rate_limiter(): rate_limiter {
        return new rate_limiter('local_jedomi_telegram_rate_limit', 'telegram_rate_limit', 'telegram_cooldown', '[TELEGRAM RATE]');
    }

    /**
     * Tool runner of the Telegram bot: the telegram tool definitions, limited to the tools Telegram can run.
     *
     * @return tool_runner
     */
    public static function tool_runner(): tool_runner {
        return new tool_runner('telegram', 'Telegram', self::SUPPORTED_TOOLS);
    }

    /**
     * Confirmation service of the Telegram bot: held actions in local_jedomi_telegram_pending_actions, shown with
     * inline buttons, for the user of the confirmed link of the chat, with the Telegram PIN.
     *
     * @return confirmation_service
     */
    public static function confirmation_service(): confirmation_service {
        return new confirmation_service(
            'local_jedomi_telegram_pending_actions',
            'Telegram',
            new telegram_confirmation_ui(),
            self::access_guard(),
            self::tool_runner(),
            static function (string $chatid): ?int {
                global $DB;
                $userid = $DB->get_field('local_jedomi_telegram_links', 'userid', ['chatid' => $chatid, 'confirmed' => 1]);
                return $userid === false ? null : (int) $userid;
            },
            true
        );
    }

    /**
     * Build the webhook URL registered in Telegram. It carries no secret: Telegram sends it in a header.
     *
     * @return string Webhook URL.
     */
    public static function get_webhook_url(): string {
        global $CFG;
        return $CFG->wwwroot . '/local/jedomi/telegram_webhook.php';
    }

    /**
     * Whether a webhook request carries the secret in the header Telegram sends (X-Telegram-Bot-Api-Secret-Token).
     *
     * Only the header is read: a secret in the address of the request is never accepted (JEDOMI-SEC-004). While the
     * webhook must be registered again (RES-001) every request is refused.
     *
     * @param array $server Server variables of the request ($_SERVER).
     * @return bool
     */
    public static function is_valid_webhook_request(array $server): bool {
        $header = $server[self::SECRET_HEADER_KEY] ?? '';
        if (!is_string($header) || $header === '') {
            return false;
        }
        if (self::needs_webhook_registration()) {
            // The kept secret was in the webhook address of earlier versions, where logs could keep it.
            return false;
        }
        return hash_equals(self::get_webhook_secret(), $header);
    }

    /**
     * Whether an update was already received from the current bot, recording it when it was not (SEC-JD-049).
     *
     * Telegram delivers an update again when the webhook does not answer in time; a repeated update_id is acknowledged
     * and not processed. Update ids are counted per bot, so the bot id (the part of the token before the colon) is part
     * of the key. An update without update_id is never taken as repeated.
     *
     * @param array $update Decoded update.
     * @return bool True when the update was already seen; false when it is new (and is now recorded as seen).
     */
    public static function mark_update_seen(array $update): bool {
        $key = self::update_key($update);
        if ($key === null) {
            return false;
        }
        $cache = \cache::make('local_jedomi', 'telegram_updates');
        if ($cache->get($key) !== false) {
            return true;
        }
        $cache->set($key, 1);
        return false;
    }

    /**
     * Forget that an update was seen, so the retry of Telegram is processed (REL-006): used when processing it failed.
     *
     * @param array $update Decoded update.
     * @return void
     */
    public static function forget_update(array $update): void {
        $key = self::update_key($update);
        if ($key !== null) {
            \cache::make('local_jedomi', 'telegram_updates')->delete($key);
        }
    }

    /**
     * Cache key of an update: the bot id and the update id; null when the update has no valid update_id.
     *
     * @param array $update Decoded update.
     * @return string|null
     */
    private static function update_key(array $update): ?string {
        $updateid = $update['update_id'] ?? null;
        if (!is_int($updateid) && !(is_string($updateid) && ctype_digit($updateid))) {
            return null;
        }
        $token = (string) self::get_bot_token();
        $botid = preg_match('/^(\d{1,20}):/', $token, $matches) ? $matches[1] : '0';
        return 'b' . $botid . '_u' . $updateid;
    }

    /**
     * Generate a one-time linking code for a user.
     *
     * @param int $userid
     * @return string 6-char uppercase alphanumeric code (no 0, O, 1, I)
     */
    public static function generate_link_code(int $userid): string {
        $code = self::generate_random_code();
        // Telegram_link_codes is MODE_APPLICATION so it works cross-request
        // (the Moodle webhook has no user session, MODE_SESSION would fail).
        $cache = \cache::make('local_jedomi', 'telegram_link_codes');
        $cache->set('userid_' . $userid, $code);
        $cache->set('code_' . $code, (string)$userid);
        return $code;
    }

    /**
     * Validate a linking code for a specific user.
     *
     * @param int $userid
     * @param string $code
     * @return bool
     */
    public static function validate_link_code(int $userid, string $code): bool {
        $cache = \cache::make('local_jedomi', 'telegram_link_codes');
        $stored = $cache->get('userid_' . $userid);
        if ($stored === false) {
            return false;
        }
        return hash_equals(strtoupper($stored), strtoupper($code));
    }

    /**
     * Resolve a code back to a user ID (used by the webhook /start handler).
     *
     * @param string $code
     * @return int|null userid, or null if code not found / expired
     */
    public static function resolve_link_code(string $code): ?int {
        $cache = \cache::make('local_jedomi', 'telegram_link_codes');
        $userid = $cache->get('code_' . strtoupper($code));
        return ($userid !== false && $userid !== null) ? (int)$userid : null;
    }

    /**
     * Invalidate a linking code after use.
     *
     * @param int $userid
     * @param string $code
     */
    public static function invalidate_link_code(int $userid, string $code): void {
        $cache = \cache::make('local_jedomi', 'telegram_link_codes');
        $cache->delete('userid_' . $userid);
        $cache->delete('code_' . strtoupper($code));
    }

    /**
     * Generate a random 6-char code.
     *
     * @return string Random code.
     */
    private static function generate_random_code(): string {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }
        if (!preg_match('/[2-9]/', $code)) {
            // Every code has a digit, so a word sent to the bot is never taken as a code.
            $code[random_int(0, self::CODE_LENGTH - 1)] = (string) random_int(2, 9);
        }
        return $code;
    }

    /**
     * Whether a text has the exact format of a link code: six characters of the code alphabet, at least one of them a
     * digit. Letters may be written in lower case.
     *
     * @param string $text Text to check.
     * @return bool
     */
    public static function is_link_code_format(string $text): bool {
        $code = strtoupper($text);
        return strlen($code) === self::CODE_LENGTH
            && strspn($code, self::CODE_ALPHABET) === self::CODE_LENGTH
            && (bool) preg_match('/[2-9]/', $code);
    }
}
