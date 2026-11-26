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
 * Test double for the media source of a messaging channel.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * Media source that records what is requested and serves the contents it was given, without reaching any channel.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_media_source implements media_source {
    /** @var array<string, string> Content of each media id that can be downloaded. */
    public array $files = [];

    /** @var media_ref[] Media requested, in order. */
    public array $requested = [];

    #[\Override]
    public function with_file(media_ref $ref, callable $callback): array {
        $this->requested[] = $ref;
        if (!array_key_exists($ref->id, $this->files)) {
            throw new \moodle_exception('emptyresponse', 'aiprovider_datacurso');
        }
        $path = make_request_directory() . '/' . clean_param($ref->id, PARAM_FILE);
        file_put_contents($path, $this->files[$ref->id]);
        try {
            return $callback($path);
        } finally {
            @unlink($path);
        }
    }
}
