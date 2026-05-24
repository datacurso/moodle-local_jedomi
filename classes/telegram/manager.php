<?php
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
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

defined('MOODLE_INTERNAL') || die();

class manager {

    /** Fixed pepper — adds defense-in-depth against DB-only breaches. */
    private const PEPPER = 'jedomi_telegram_pepper_v1';

    /** Default rate limit: max messages per minute. */
    private const DEFAULT_RATE_LIMIT = 30;

    /** Default cooldown in minutes when rate limit exceeded. */
    private const DEFAULT_COOLDOWN = 5;

    /** TTL for linking codes (seconds). */
    private const CODE_TTL = 600; // 10 minutes

    /**
     * Derive the encryption key from site secret + pepper.
     *
     * Uses $CFG->passwordsaltmain when available (set in config.php),
     * falls back to $CFG->siteidentifier which always exists.
     *
     * @return string 32-byte raw key
     */
    private static function derive_key(): string {
        global $CFG;
        $secret = $CFG->passwordsaltmain ?? $CFG->siteidentifier ?? 'jedomi_default_secret';
        return hash('sha256', $secret . self::PEPPER, true);
    }

    /**
     * Encrypt a plaintext string using AES-256-GCM.
     *
     * Returns a string containing: nonce (12 bytes) + ciphertext + tag (16 bytes),
     * all base64-encoded for storage.
     *
     * @param string $plaintext
     * @return string Base64-encoded ciphertext bundle
     */
    public static function encrypt(string $plaintext): string {
        $key = self::derive_key();
        $nonce = random_bytes(12); // 96 bits for GCM
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false) {
            throw new \moodle_exception('encryption_failed', 'local_jedomi');
        }
        return base64_encode($nonce . $ciphertext . $tag);
    }

    /**
     * Decrypt a ciphertext bundle produced by self::encrypt().
     *
     * @param string $ciphertext_bundle Base64-encoded
     * @return string Plaintext
     */
    public static function decrypt(string $ciphertext_bundle): string {
        $raw = base64_decode($ciphertext_bundle, true);
        if ($raw === false || strlen($raw) < 29) { // 12 nonce + 1 min ciphertext + 16 tag
            throw new \moodle_exception('decryption_failed', 'local_jedomi');
        }
        $key = self::derive_key();
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ciphertext = substr($raw, 12, -16);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new \moodle_exception('decryption_failed', 'local_jedomi');
        }
        return $plaintext;
    }

    /**
     * Store the bot token encrypted in config.
     *
     * @param string $token Plaintext bot token from @BotFather
     */
    public static function set_bot_token(string $token): void {
        set_config('telegram_bot_token', self::encrypt($token), 'local_jedomi');
    }

    /**
     * Retrieve and decrypt the bot token.
     *
     * @return string|null Plaintext token, or null if not configured
     */
    public static function get_bot_token(): ?string {
        $encrypted = get_config('local_jedomi', 'telegram_bot_token');
        if (empty($encrypted)) {
            return null;
        }
        try {
            return self::decrypt($encrypted);
        } catch (\Exception $e) {
            error_log('[local_jedomi] Failed to decrypt Telegram bot token');
            return null;
        }
    }

    /**
     * Generate or retrieve the webhook secret.
     *
     * @return string 32-char hex random secret
     */
    public static function get_webhook_secret(): string {
        $secret = get_config('local_jedomi', 'telegram_webhook_secret');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(16));
            set_config('telegram_webhook_secret', $secret, 'local_jedomi');
        }
        return $secret;
    }

    /**
     * Regenerate the webhook secret (invalidates old webhooks)
     * AND automatically updates the webhook URL in Telegram.
     *
     * After changing the secret locally, this method:
     *  1. Deletes the old webhook (drops pending updates)
     *  2. Sets the new webhook URL with the fresh secret
     *
     * If the bot token is not configured, only the local secret is rotated
     * (the webhook registration will happen when the token is saved later).
     *
     * @return string The new secret
     */
    public static function regenerate_webhook_secret(): string {
        $secret = bin2hex(random_bytes(16));
        set_config('telegram_webhook_secret', $secret, 'local_jedomi');

        // Update Telegram webhook with the new secret.
        self::register_webhook();

        return $secret;
    }

    /**
     * Register (or re-register) the webhook URL in Telegram using the current
     * bot token and current webhook secret.
     *
     * Deletes any existing webhook first (with drop_pending_updates=true) so
     * Telegram retries pending updates with the new URL immediately.
     *
     * Stores the result (success/error) in config for UI visibility.
     *
     * @return bool Whether the webhook was registered successfully
     */
    public static function register_webhook(): bool {
        $token = self::get_bot_token();
        if (!$token) {
            self::set_last_webhook_error('No bot token configured — webhook not registered');
            return false;
        }

        $client = new api_client($token);
        $webhook_url = self::get_webhook_url();

        // Delete old webhook first (clears stale pending updates).
        $deleted = $client->delete_webhook();
        if (!$deleted) {
            self::set_last_webhook_error('Failed to delete previous webhook — token may be invalid');
            return false;
        }

        // Set the new webhook URL.
        $ok = $client->set_webhook($webhook_url);
        if ($ok) {
            self::clear_last_webhook_error();
        } else {
            self::set_last_webhook_error('setWebhook failed — check the URL is HTTPS and reachable');
        }

        return $ok;
    }

    /**
     * Persist the last webhook API error message so it's visible in the admin UI.
     *
     * @param string $message
     */
    public static function set_last_webhook_error(string $message): void {
        set_config('telegram_webhook_last_error', $message, 'local_jedomi');
        set_config('telegram_webhook_error', 1, 'local_jedomi');
        set_config('telegram_webhook_error_time', time(), 'local_jedomi');
    }

    /**
     * Clear the last webhook error (marks as healthy).
     */
    public static function clear_last_webhook_error(): void {
        set_config('telegram_webhook_last_error', '', 'local_jedomi');
        set_config('telegram_webhook_error', 0, 'local_jedomi');
        set_config('telegram_webhook_error_time', 0, 'local_jedomi');
    }

    /**
     * Get the last webhook error message (empty string if none).
     *
     * @return string
     */
    public static function get_last_webhook_error(): string {
        return (string) get_config('local_jedomi', 'telegram_webhook_last_error');
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
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_jedomi', 'telegram_enabled');
    }

    /**
     * Get the configured rate limit (messages per minute).
     */
    public static function get_rate_limit(): int {
        $val = get_config('local_jedomi', 'telegram_rate_limit');
        return ($val === false || $val === '') ? self::DEFAULT_RATE_LIMIT : (int) $val;
    }

    /**
     * Get the configured cooldown in minutes.
     */
    public static function get_cooldown(): int {
        $val = get_config('local_jedomi', 'telegram_cooldown');
        return ($val === false || $val === '') ? self::DEFAULT_COOLDOWN : (int) $val;
    }

    /**
     * Build the full webhook URL for @BotFather.
     */
    public static function get_webhook_url(): string {
        global $CFG;
        $secret = self::get_webhook_secret();
        return $CFG->wwwroot . '/local/jedomi/telegram_webhook.php?secret=' . $secret;
    }

    /**
     * Generate a one-time linking code for a user.
     *
     * @param int $userid
     * @return string 6-char uppercase alphanumeric code (no 0, O, 1, I)
     */
    public static function generate_link_code(int $userid): string {
        $code = self::generate_random_code();
        // telegram_link_codes is MODE_APPLICATION so it works cross-request
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
     */
    public static function invalidate_link_code(int $userid, string $code): void {
        $cache = \cache::make('local_jedomi', 'telegram_link_codes');
        $cache->delete('userid_' . $userid);
        $cache->delete('code_' . strtoupper($code));
    }

    /**
     * Generate a random 6-char code.
     */
    private static function generate_random_code(): string {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0, O, 1, I
        $code = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }
}
