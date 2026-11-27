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
 * Telegram media source.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\telegram;

use local_jedomi\messaging\media_ref;
use local_jedomi\messaging\media_source;

/**
 * Downloads the attachments of Telegram messages by their file_id through the Bot API client.
 */
class telegram_media_source implements media_source {
    #[\Override]
    public function with_file(media_ref $ref, callable $callback): array {
        $path = $ref->id !== '' ? api_client::instance()->download_file($ref->id) : null;
        if ($path === null || !file_exists($path)) {
            throw new \moodle_exception('emptyresponse', 'aiprovider_datacurso');
        }
        try {
            return $callback($path);
        } finally {
            @unlink($path);
        }
    }
}
