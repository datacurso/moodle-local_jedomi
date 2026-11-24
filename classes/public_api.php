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

namespace local_jedomi;

use aiprovider_datacurso\local\plugin_settings;

/**
 * Server API other plugins call to work with Jedomi. Its signatures are stable: an incompatible
 * change raises the major version of Jedomi.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class public_api {
    /**
     * Whether the Jedomi chat is offered to a user in a context, with the same rules as the panel:
     * an active user who is not a guest, Jedomi enabled in the Datacurso AI provider, the course of
     * the context available to Jedomi and a role of the user not hidden from it.
     *
     * Another plugin uses it to decide whether to show something that opens the chat (for example a
     * button that calls local_jedomi/api openWithSuggestion()). Pages that never show the panel (pop-up,
     * embedded or secure layouts, a quiz attempt) are not known here.
     *
     * @param int $userid User.
     * @param \context $context Context of the page: a course, an activity, a category or the system.
     * @return bool
     */
    public static function chat_available(int $userid, \context $context): bool {
        global $CFG;
        require_once($CFG->dirroot . '/local/jedomi/lib.php');

        if (!self::is_active_user($userid) || !plugin_settings::is_enabled('local_jedomi')) {
            return false;
        }
        $coursecontext = $context->get_course_context(false);
        $courseid = $coursecontext ? (int) $coursecontext->instanceid : SITEID;
        if (!course_access::is_enabled($courseid)) {
            return false;
        }
        return \local_jedomi_is_role_allowed_for_user($userid, $context);
    }

    /**
     * Whether a user exists and can log in: not the guest, not deleted, not suspended.
     *
     * @param int $userid User.
     * @return bool
     */
    private static function is_active_user(int $userid): bool {
        global $DB;
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }
        return $DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
    }
}
