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

namespace local_jedomi\whatsapp;

/**
 * Check of the X-Hub-Signature-256 header Meta sends with every webhook POST.
 *
 * The header is "sha256=" followed by the hexadecimal HMAC-SHA256 of the raw request body, keyed with the App Secret.
 * The body must be the bytes received (php://input): a decoded and encoded again JSON has other bytes (escaped
 * characters, spaces, numbers) and never matches.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class meta_signature {
    /** @var string Pattern of a valid header: the sha256 prefix and 64 hexadecimal characters. */
    private const HEADER_PATTERN = '/^sha256=([0-9a-fA-F]{64})$/D';

    /**
     * Whether the header is the signature of the raw body with the App Secret. The comparison takes constant time.
     *
     * @param string $rawbody Raw request body, exactly as received.
     * @param string|null $header Value of the X-Hub-Signature-256 header; null when it is missing.
     * @param string $appsecret App Secret of the Meta app; an empty secret never validates.
     * @return bool
     */
    public static function verify(string $rawbody, ?string $header, string $appsecret): bool {
        if ($appsecret === '' || $header === null) {
            return false;
        }
        if (preg_match(self::HEADER_PATTERN, trim($header), $matches) !== 1) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawbody, $appsecret);
        return hash_equals($expected, strtolower($matches[1]));
    }
}
