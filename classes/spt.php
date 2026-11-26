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
 * Builder of the signed permission tokens (SPT) the gateway verifies.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * The parts every signed permission token (SPT) shares: the capabilities, the course lists of a role, the payload keys,
 * the expiry and the HMAC signature with the secret of the site.
 *
 * The web chat (local_jedomi_generate_spt()), a given user (local_jedomi_generate_spt_for_user()) and a messaging
 * channel (tool_runner::signed_permissions()) build their tokens with it and differ only in the course and the
 * capabilities context, the course lists and the tools offered.
 */
final class spt {
    /** @var int Seconds a token is valid: 30 minutes, for conversations with several tool calls. */
    public const EXPIRY = 30 * MINSECS;

    /**
     * Capabilities of a user, as the gateway reads them. Managing users and sending messages are always checked at
     * system level; the others in the given context.
     *
     * @param \context $context Context of the course capabilities: the course of the page or the system.
     * @param int|null $userid User, or null for the current user.
     * @return array<string, bool>
     */
    public static function capabilities(\context $context, ?int $userid = null): array {
        $sysctx = \context_system::instance();
        return [
            'can_view_all_grades'   => has_capability('moodle/grade:viewall', $context, $userid),
            'can_manage_users'      => has_capability('moodle/user:update', $sysctx, $userid),
            'can_send_messages'     => has_capability('moodle/site:sendmessage', $sysctx, $userid),
            'can_manage_courses'    => has_capability('moodle/course:update', $context, $userid),
            'can_view_reports'      => has_capability('moodle/course:viewhiddenactivities', $context, $userid),
            'can_enrol_users'       => has_capability('enrol/manual:enrol', $context, $userid),
            'can_access_all_groups' => has_capability('moodle/site:accessallgroups', $context, $userid),
        ];
    }

    /**
     * Courses of a user by role (RBAC scope in the gateway): a teacher or manager owns the courses they manage the
     * activities of, a student is enrolled in their courses, an administrator needs no list (full access).
     *
     * @param int $userid Moodle user id.
     * @param string $role Jedomi role (admin|manager|teacher|student).
     * @return array{0: int[], 1: int[]} Owned course ids and enrolled course ids.
     */
    public static function course_ids(int $userid, string $role): array {
        $owned = [];
        $enrolled = [];
        if ($role === 'teacher' || $role === 'manager') {
            foreach (enrol_get_users_courses($userid, true, ['id']) as $course) {
                if (has_capability('moodle/course:manageactivities', \context_course::instance($course->id), $userid)) {
                    $owned[] = (int) $course->id;
                }
            }
        } else if ($role === 'student') {
            foreach (enrol_get_users_courses($userid, true, ['id']) as $course) {
                $enrolled[] = (int) $course->id;
            }
        }
        return [$owned, $enrolled];
    }

    /**
     * Payload of a token, unsigned. Courses excluded from Jedomi are never signed as courses of the user.
     *
     * @param int $userid Moodle user id.
     * @param string $username Username of the user.
     * @param string $role Jedomi role (admin|manager|teacher|student).
     * @param int $courseid Course of the page, or 0 outside the web chat.
     * @param int[] $owned Course ids the user manages.
     * @param int[] $enrolled Course ids the user is enrolled in.
     * @param array<string, bool> $capabilities Capabilities, see capabilities().
     * @param array $tools Tool definitions in OpenAI format; the gateway uses them to configure the model.
     * @return array
     */
    public static function payload(
        int $userid,
        string $username,
        string $role,
        int $courseid,
        array $owned,
        array $enrolled,
        array $capabilities,
        array $tools
    ): array {
        global $CFG;
        return [
            'userid'              => $userid,
            'username'            => $username,
            'role'                => $role,
            'course_id'           => $courseid,
            'course_ids_owned'    => course_access::filter_course_ids($owned),
            'course_ids_enrolled' => course_access::filter_course_ids($enrolled),
            'capabilities'        => $capabilities,
            'tools'               => $tools,
            'moodle_url'          => $CFG->wwwroot,
            'expires'             => time() + self::EXPIRY,
        ];
    }

    /**
     * Sort a payload by key and sign it with the secret of the site (HMAC SHA-256 over
     * local_jedomi_spt_signing_string()), the same secret the gateway verifies it with.
     *
     * @param array $payload Payload without its signature.
     * @return array The payload sorted by key, with its 'signature' last.
     */
    public static function sign(array $payload): array {
        ksort($payload);
        $payload['signature'] = hash_hmac('sha256', \local_jedomi_spt_signing_string($payload), \local_jedomi_get_spt_secret());
        return $payload;
    }
}
