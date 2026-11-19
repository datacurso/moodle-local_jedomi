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
        if (!preg_match(self::AUTHORITY_PATTERN, $url, $m)) {
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
}
