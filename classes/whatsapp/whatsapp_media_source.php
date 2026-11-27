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

use local_jedomi\messaging\media_ref;
use local_jedomi\messaging\media_source;

/**
 * Media source of the WhatsApp channel. Media is not downloaded yet: the conversation handler answers a media message
 * with the text only message before the core asks for a file, so any call here is refused.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_media_source implements media_source {
    #[\Override]
    public function with_file(media_ref $ref, callable $callback): array {
        throw new \moodle_exception('whatsapp_msg_text_only', 'local_jedomi');
    }
}
