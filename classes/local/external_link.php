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
 * Tells links to other sites from links to this site, in the answers the AI writes (JEDOMI-SEC-005).
 *
 * The host is read as a browser reads it: a backslash counts as a slash, the authority ends at the first slash,
 * backslash, "?" or "#", and anything before the last "@" is a user name. Only an address whose host is exactly the host
 * of the site counts as internal, so an address that merely contains the site address is external.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external_link {
    /** @var string Start of an address that names a host: http or https with any slashes, or two slashes. */
    private const AUTHORITY_PATTERN = '~^\s*(?:https?:[/\\\\]*|[/\\\\]{2})([^/\\\\?#]*)~i';

    /**
     * Host of an address that names one, in lower case and without user name or port.
     *
     * @param string $url Address as written.
     * @return string|null Host (may be empty), or null for an address that names no host (a relative path, another scheme).
     */
    public static function host(string $url): ?string {
        if (!preg_match(self::AUTHORITY_PATTERN, self::normalise($url), $m)) {
            return null;
        }
        $authority = $m[1];
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }
        if (str_starts_with($authority, '[')) {
            $end = strpos($authority, ']');
            $host = $end === false ? $authority : substr($authority, 0, $end + 1);
        } else {
            $host = (string) preg_replace('/:\d*$/', '', $authority);
        }
        return \core_text::strtolower($host);
    }

    /**
     * Whether an address opens a page of another site.
     *
     * @param string $url Address as written.
     * @return bool True for an address naming a host other than the host of the site; false for this site, a relative
     *     path or an address of another scheme.
     */
    public static function is_external(string $url): bool {
        global $CFG;
        $host = self::host($url);
        return $host !== null && $host !== self::host($CFG->wwwroot);
    }

    /**
     * An address as a browser reads it: TAB, LF and CR are removed anywhere ("/<TAB>/evil.example" opens
     * "//evil.example"), and control characters and spaces are removed at both ends.
     *
     * @param string $url Address as written.
     * @return string
     */
    public static function normalise(string $url): string {
        $url = str_replace(["\t", "\n", "\r"], '', $url);
        return (string) preg_replace('/^[\x00-\x20]+|[\x00-\x20]+$/', '', $url);
    }

    /**
     * Whether a link destination may send what it carries out of the site: an address of another host, any scheme
     * other than http and https (mailto:, tg:, tel:, sms:, data:, javascript:...), or an address that names no
     * readable host. Only a site-relative path ("/x", not "//x" or "/\\x") and an http or https address of the site
     * are internal.
     *
     * @param string $url Link destination as written.
     * @return bool
     */
    public static function leaves_site(string $url): bool {
        $url = self::normalise($url);
        if ($url === '' || preg_match('~^/(?![/\\\\])~', $url)) {
            return false;
        }
        if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $url, $m)) {
            return !in_array(\core_text::strtolower($m[1]), ['http', 'https'], true) || self::is_external($url);
        }
        if (!preg_match('~^[/\\\\]{2}~', $url)) {
            // A bare name ("attacker.example/x") or a path relative to the page: read as a host, so only the site counts.
            $url = '//' . $url;
        }
        return self::is_external($url);
    }
}
