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
 * Registers the Jedomi settings page in the site administration.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_jedomi',
        new lang_string('pluginname', 'local_jedomi'),
        new moodle_url('/local/jedomi/admin_settings.php'),
        'moodle/site:config'
    ));
} else {
    // Managers who cannot configure the site but can manage agents reach the Agents tab from the AI category.
    // Site administrators already get the full settings page above, so the link is not duplicated for them.
    $ADMIN->add('ai', new admin_externalpage(
        'local_jedomi_agents',
        new lang_string('agents_menu_link', 'local_jedomi'),
        new moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'agents']),
        'local/jedomi:manageagents'
    ));
}
