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
 * Reference to the attachment of an inbound message.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

/**
 * An attachment of a message as the channel announced it: what kind it is, the id the channel downloads it with, and
 * its name and type. Nothing is downloaded until the media pipeline asks the media source of the channel for it.
 */
final class media_ref {
    /** @var string A voice message or an audio file, for the transcription. */
    public const AUDIO = 'audio';

    /** @var string A photo, for the vision model. */
    public const IMAGE = 'image';

    /** @var string A file sent as a document: Office documents are extracted, PDF and images go to the vision model. */
    public const DOCUMENT = 'document';

    /**
     * Constructor.
     *
     * @param string $kind One of the AUDIO, IMAGE or DOCUMENT constants.
     * @param string $id Id the channel downloads the file with; empty when the channel sent none.
     * @param string $filename Name of the file as the channel gives it, not cleaned yet.
     * @param string $mimetype Type of the file as the channel gives it; empty when unknown.
     */
    public function __construct(
        /** @var string One of the AUDIO, IMAGE or DOCUMENT constants. */
        public readonly string $kind,
        /** @var string Id the channel downloads the file with. */
        public readonly string $id,
        /** @var string Name of the file as the channel gives it. */
        public readonly string $filename,
        /** @var string Type of the file as the channel gives it. */
        public readonly string $mimetype,
    ) {
    }
}
