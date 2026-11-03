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
// along with Moodle.  If not, see <http://www.gnu.org/copyleft/gpl.html>.

/**
 * Hook callbacks for local_jedomi.
 *
 * Registers the sidebar injection via the before_footer_html_generation hook
 * instead of the legacy local_jedomi_before_footer() function.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

use core\hook\output\before_footer_html_generation;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for the Jedomi Moodle plugin.
 */
class hook_callbacks {

    /**
     * Inject the Jedomi sidebar before the footer is rendered.
     *
     * @param before_footer_html_generation $hook The hook instance.
     */
    public static function before_footer(before_footer_html_generation $hook): void {
        global $PAGE;

        // Auto-generate the HMAC secret for the CompanionWS on first boot.
        if (!get_config('local_jedomi', 'hmac_secret')) {
            set_config('hmac_secret', bin2hex(random_bytes(32)), 'local_jedomi');
        }

        if (!isloggedin() || isguestuser()) {
            return;
        }

        if (get_config('local_jedomi', 'enabled') === '0') {
            return;
        }

        if (!local_jedomi_is_available_in_course()) {
            return;
        }

        if (!local_jedomi_is_role_allowed()) {
            return;
        }

        global $COURSE;
        $configUrl = (new \moodle_url('/local/jedomi/ajax/sidebar_config.php', [
            'sesskey'  => sesskey(),
            'courseid' => (int) ($COURSE->id ?? 0),
            'cmid'     => (int) ($PAGE->cm->id ?? 0),
        ]))->out(false);

        $PAGE->requires->js_call_amd('local_jedomi/sidebar', 'init', [[
            'configUrl' => $configUrl,
        ]]);
    }
}
