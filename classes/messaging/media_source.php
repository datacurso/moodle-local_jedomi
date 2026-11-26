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
 * Media port of a messaging channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * How a channel (Telegram, WhatsApp) downloads the attachment of a message to a temporary file and releases it.
 */
interface media_source {
    /**
     * Download an attachment, pass its local path to a callback and remove the file afterwards, also on a failure.
     *
     * @param media_ref $ref Attachment.
     * @param callable $callback Receives the local path and returns the result.
     * @return array Result of the callback.
     * @throws \moodle_exception emptyresponse (aiprovider_datacurso) when the file cannot be downloaded.
     */
    public function with_file(media_ref $ref, callable $callback): array;
}
