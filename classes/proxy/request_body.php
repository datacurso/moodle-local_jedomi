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

namespace local_jedomi\proxy;

/**
 * Body of a web chat request (chatproxy.php), read only up to a fixed size before it is decoded (JEDOMI-SEC-006).
 *
 * The panel sends at most handler::MAX_BROWSER_MESSAGES messages, and the text of every attached file travels inside the
 * user message it was attached to, so it is sent again with the history. The largest legitimate message carries five
 * files (the attachment cap of the panel) at the character cap of a read file
 * (file_extraction_service::MAX_TEXT_CHARS, 200,000 characters), about 3 MB in the worst UTF-8 case (3 bytes per
 * character). MAX_BYTES (8 MB, PHP's default post_max_size) leaves room for two such messages plus the rest of the
 * history, stays well under the 20 MB body the Datacurso AI service accepts, and still refuses a flood of data before
 * json_decode() holds it in memory several times. Larger histories are trimmed to the context window anyway.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_body {
    /** @var int Largest body accepted, in bytes (8 MB). */
    public const MAX_BYTES = 8388608;

    /**
     * Read the body of the request, or null when it is larger than MAX_BYTES.
     *
     * A declared Content-Length above the cap is refused before anything is read; a body without one (chunked) is read
     * only up to one byte past the cap.
     *
     * @param string $source Stream to read: php://input for the request (tests pass a file).
     * @param string|null $contentlength Content-Length declared by the request; null or '' when there is none.
     * @return string|null The body, or null when it is too large.
     */
    public static function read(string $source = 'php://input', ?string $contentlength = null): ?string {
        // A length too long for an integer becomes PHP_INT_MAX, above the cap too.
        if ($contentlength !== null && ctype_digit($contentlength) && (int) $contentlength > self::MAX_BYTES) {
            return null;
        }
        $body = file_get_contents($source, false, null, 0, self::MAX_BYTES + 1);
        if ($body === false) {
            return '';
        }
        return strlen($body) > self::MAX_BYTES ? null : $body;
    }
}
