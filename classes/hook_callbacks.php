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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

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

/**
 * Hook callbacks for the Jedomi Moodle plugin.
 */
class hook_callbacks {
    /** @var string[] Page layouts where the panel is never shown: pop-up, embedded and secure (exam) windows. */
    private const EXCLUDED_LAYOUTS = ['popup', 'embedded', 'secure', 'frametop'];

    /** @var string[] Page types of a quiz attempt in progress, where the panel is never shown. */
    private const EXCLUDED_PAGETYPES = ['mod-quiz-attempt', 'mod-quiz-summary'];

    /**
     * Inject the Jedomi sidebar before the footer is rendered.
     *
     * @param before_footer_html_generation $hook The hook instance.
     */
    public static function before_footer(before_footer_html_generation $hook): void {
        global $PAGE;

        // Auto-generate the HMAC secret for the CompanionWS on first boot, encrypted (JEDOMI-SEC-010).
        if (!get_config('local_jedomi', 'hmac_secret')) {
            \local_jedomi\local\secret_store::set('hmac_secret', bin2hex(random_bytes(32)));
        }

        if (!isloggedin() || isguestuser()) {
            return;
        }

        // Never during a quiz attempt nor in pop-up, embedded or secure windows.
        if (
            in_array($PAGE->pagelayout, self::EXCLUDED_LAYOUTS, true)
                || in_array($PAGE->pagetype, self::EXCLUDED_PAGETYPES, true)
        ) {
            return;
        }

        if (!\aiprovider_datacurso\local\plugin_settings::is_enabled('local_jedomi')) {
            return;
        }

        if (!local_jedomi_is_available_in_course()) {
            return;
        }

        if (!local_jedomi_is_role_allowed()) {
            return;
        }

        // The page the panel is on, read here from the page itself: the configuration is requested by AJAX, where the
        // page is the endpoint. The server checks these values again before using them.
        $configurl = (new \moodle_url('/local/jedomi/ajax/sidebar_config.php', [
            'sesskey' => sesskey(),
            'courseid' => (int) $PAGE->course->id,
            'cmid' => !empty($PAGE->cm) ? (int) $PAGE->cm->id : 0,
            'pagetype' => (string) $PAGE->pagetype,
            'pagepath' => self::page_path(),
        ]))->out(false);

        // The notice shown when the configuration cannot be loaded needs its texts before the configuration.
        $PAGE->requires->js_call_amd('local_jedomi/sidebar', 'init', [[
            'configUrl' => $configurl,
            'strings' => [
                'config_load_error' => get_string('config_load_error', 'local_jedomi'),
                'config_load_retry' => get_string('config_load_retry', 'local_jedomi'),
            ],
        ]]);
        // Mark of the page for local_jedomi/api: the panel is going to start here, so a suggestion of another plugin
        // made while it is still loading waits for it instead of being refused.
        $hook->add_html(\html_writer::span('', '', ['data-region' => 'local_jedomi-panel', 'hidden' => 'hidden']));
    }

    /**
     * Path of the current page relative to the site, without its query string (for example "/user/files.php").
     *
     * @return string Path, or '' when the page has no address.
     */
    private static function page_path(): string {
        global $PAGE;

        try {
            $url = $PAGE->url->out_as_local_url(false);
        } catch (\Throwable $e) {
            return '';
        }
        return (string) preg_replace('/[?#].*$/s', '', $url);
    }
}
