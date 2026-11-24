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

namespace local_jedomi\privacy;

/**
 * Privacy metadata item for the technical log of the plugin, a file in the data directory of the site.
 *
 * The privacy API has no type for a log file, so this item describes it with the same name, fields and summary that
 * the site privacy registry shows for any other item.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class technical_log implements \core_privacy\local\metadata\types\type {
    /** @var string Name shown in the privacy registry. */
    public const NAME = 'local_jedomi_technical_log';

    #[\Override]
    public function get_name() {
        return self::NAME;
    }

    #[\Override]
    public function get_privacy_fields() {
        return [
            'user' => 'privacy:metadata:technical_log:user',
            'chat' => 'privacy:metadata:technical_log:chat',
            'ipaddress' => 'privacy:metadata:technical_log:ipaddress',
        ];
    }

    #[\Override]
    public function get_summary() {
        return 'privacy:metadata:technical_log';
    }
}
