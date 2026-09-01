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
 * Registers the plugin admin settings page and redirects to the tabbed admin UI.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_jedomi', get_string('pluginname', 'local_jedomi'));
    $ADMIN->add('localplugins', $settings);

    // Redirigir instantáneamente a la UI con pestañas.
    // El script se ejecuta cuando Moodle renderiza la sección de settings.
    $adminurl = (new moodle_url('/local/jedomi/admin_settings.php'))->out(false);
    $settings->add(new admin_setting_heading(
        'local_jedomi/redirect_heading',
        '',
        '<script>window.location.replace("' . $adminurl . '?tab=general");</script>'
        . '<noscript><a href="' . $adminurl . '?tab=general">'
        . get_string('open_admin_settings', 'local_jedomi') . '</a></noscript>'
    ));
}
