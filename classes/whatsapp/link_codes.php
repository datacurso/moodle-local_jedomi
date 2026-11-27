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

namespace local_jedomi\whatsapp;

/**
 * One-time codes that link a WhatsApp identity to a Moodle account.
 *
 * A code is generated on the WhatsApp profile page after the user gives consent, and is sent from WhatsApp with or
 * without the JEDOMI prefix. Codes live ten minutes in the application cache of the Telegram codes, under keys with
 * the wa_ prefix: the webhook has no user session. The user key keeps the code with the consent and the code key
 * keeps the user, and both must match, as for Telegram.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class link_codes {
    /** @var int Seconds a code is valid; the TTL of the cache definition. */
    public const TTL = 600;

    /** @var string Word that may precede the code: the wa.me link sends "JEDOMI <CODE>". */
    public const PREFIX = 'JEDOMI';

    /** @var string Characters of a code: no 0, O, 1, I or L. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** @var int Length of a code. */
    private const LENGTH = 6;

    /** @var string Cache definition shared with the Telegram codes (MODE_APPLICATION, TTL 600 s). */
    private const CACHE = 'telegram_link_codes';

    /** @var string Prefix of the WhatsApp keys in that cache. */
    private const KEY_PREFIX = 'wa_';

    /**
     * Generate the code of a user; it replaces any earlier code of the user.
     *
     * @param int $userid User id.
     * @param consent $consent Consent the user just gave.
     * @return string Code of six characters with at least one digit.
     */
    public static function generate(int $userid, consent $consent): string {
        $code = self::random_code();
        $cache = self::cache();
        $cache->set(self::user_key($userid), json_encode(['code' => $code, 'consent' => $consent->to_json()]));
        $cache->set(self::code_key($code), (string) $userid);
        return $code;
    }

    /**
     * The user of a code and the consent recorded with it, when the code is valid in both directions.
     *
     * @param string $code Code in any letter case.
     * @return array{userid: int, consent: consent}|null Null when the code is unknown, expired, replaced or malformed.
     */
    public static function resolve(string $code): ?array {
        $code = strtoupper($code);
        if (!self::is_code($code)) {
            return null;
        }
        $cache = self::cache();
        $userid = $cache->get(self::code_key($code));
        if (!is_string($userid) || !ctype_digit($userid)) {
            return null;
        }
        $stored = $cache->get(self::user_key((int) $userid));
        $data = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($data) || !is_string($data['code'] ?? null) || !hash_equals($data['code'], $code)) {
            return null;
        }
        $consent = is_string($data['consent'] ?? null) ? consent::from_json($data['consent']) : null;
        if ($consent === null) {
            return null;
        }
        return ['userid' => (int) $userid, 'consent' => $consent];
    }

    /**
     * Remove a used code.
     *
     * @param int $userid User id.
     * @param string $code Code.
     */
    public static function invalidate(int $userid, string $code): void {
        $cache = self::cache();
        $cache->delete(self::user_key($userid));
        $cache->delete(self::code_key(strtoupper($code)));
    }

    /**
     * The code a message carries: the whole text is a code, optionally after the JEDOMI prefix (also with a slash).
     *
     * @param string $text Text of the message.
     * @param bool $prefixrequired Whether only the JEDOMI form counts: a linked sender's bare word is plain text.
     * @return string|null Code in upper case, or null when the text is not a link code.
     */
    public static function parse(string $text, bool $prefixrequired = false): ?string {
        $prefix = '(?:/?' . self::PREFIX . '\s+)' . ($prefixrequired ? '' : '?');
        if (!preg_match('~^' . $prefix . '(\S+)$~i', trim($text), $matches)) {
            return null;
        }
        $code = strtoupper($matches[1]);
        return self::is_code($code) ? $code : null;
    }

    /**
     * Whether a text in upper case has the format of a code.
     *
     * @param string $code Text.
     * @return bool
     */
    private static function is_code(string $code): bool {
        return strlen($code) === self::LENGTH
            && strspn($code, self::ALPHABET) === self::LENGTH
            && (bool) preg_match('/[2-9]/', $code);
    }

    /**
     * A random code with at least one digit, so a word is never taken as a code.
     *
     * @return string
     */
    private static function random_code(): string {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }
        if (!preg_match('/[2-9]/', $code)) {
            $code[random_int(0, self::LENGTH - 1)] = (string) random_int(2, 9);
        }
        return $code;
    }

    /**
     * Cache of the codes.
     *
     * @return \cache
     */
    private static function cache(): \cache {
        return \cache::make('local_jedomi', self::CACHE);
    }

    /**
     * Key of the code and consent of a user.
     *
     * @param int $userid User id.
     * @return string
     */
    private static function user_key(int $userid): string {
        return self::KEY_PREFIX . 'userid_' . $userid;
    }

    /**
     * Key of the user of a code.
     *
     * @param string $code Code in upper case.
     * @return string
     */
    private static function code_key(string $code): string {
        return self::KEY_PREFIX . 'code_' . $code;
    }
}
