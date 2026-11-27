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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Default status sink: it only writes the error codes of failed statuses to the technical log.
 *
 * No recipient, message id or text is logged. Usage counters and opt-out from statuses come in a later work unit.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class log_status_sink implements status_sink {
    /**
     * Log a failed status with its error codes; other statuses are ignored.
     *
     * @param delivery_status $status Status.
     */
    public function record(delivery_status $status): void {
        if ($status->is_failed()) {
            \local_jedomi_log('WhatsApp: message not delivered', ['codes' => $status->errorcodes]);
        }
    }
}
