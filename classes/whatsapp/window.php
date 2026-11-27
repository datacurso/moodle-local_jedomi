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

use local_jedomi\local\service\whatsapp_link_service;

/**
 * Customer service window of WhatsApp: Meta accepts free-form messages only within 24 hours of the last message of the
 * user. Jedomi closes it a margin earlier, so a reply never reaches Meta just after the window closed.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class window {
    /** @var int Seconds before the end of the 24 hours from which no free-form message is sent. */
    public const MARGIN = 10 * MINSECS;

    /**
     * Whether free-form messages can be sent to a link now.
     *
     * @param \stdClass $link Link, with its lastinbound time.
     * @param int $now Current time.
     * @return bool
     */
    public static function is_open(\stdClass $link, int $now): bool {
        $lastinbound = (int) ($link->lastinbound ?? 0);
        return $lastinbound > 0 && $now - $lastinbound < whatsapp_link_service::SERVICE_WINDOW - self::MARGIN;
    }
}
