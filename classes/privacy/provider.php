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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_jedomi\local\service\feedback_service;

/**
 * Privacy provider for local_jedomi: answer ratings stored in Moodle and conversations sent to the AI service.
 *
 * Ratings are personal data of the user who gives them and live in that user's context.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(feedback_service::TABLE, [
            'userid' => 'privacy:metadata:local_jedomi_feedback:userid',
            'rating' => 'privacy:metadata:local_jedomi_feedback:rating',
            'sessionid' => 'privacy:metadata:local_jedomi_feedback:sessionid',
            'messagetext' => 'privacy:metadata:local_jedomi_feedback:messagetext',
            'timecreated' => 'privacy:metadata:local_jedomi_feedback:timecreated',
        ], 'privacy:metadata:local_jedomi_feedback');

        $collection->add_external_location_link('jedomi_ai_service', [
            'userid' => 'privacy:metadata:jedomi_ai_service:userid',
            'messages' => 'privacy:metadata:jedomi_ai_service:messages',
        ], 'privacy:metadata:jedomi_ai_service');

        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (feedback_service::user_has_feedback($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && feedback_service::user_has_feedback((int) $context->instanceid)) {
            $userlist->add_user((int) $context->instanceid);
        }
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || (int) $context->instanceid !== $userid) {
                continue;
            }
            $feedback = array_map(static fn(\stdClass $record): array => [
                'rating' => $record->rating,
                'sessionid' => $record->sessionid,
                'messagetext' => $record->messagetext,
                'timecreated' => transform::datetime($record->timecreated),
            ], feedback_service::get_for_user($userid));
            if (!empty($feedback)) {
                writer::with_context($context)->export_data(
                    [get_string('privacy_feedback', 'local_jedomi')],
                    (object) ['feedback' => $feedback]
                );
            }
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_user) {
            feedback_service::delete_for_users([(int) $context->instanceid]);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && (int) $context->instanceid === $userid) {
                feedback_service::delete_for_users([$userid]);
            }
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }
        $userid = (int) $context->instanceid;
        if (in_array($userid, array_map('intval', $userlist->get_userids()), true)) {
            feedback_service::delete_for_users([$userid]);
        }
    }
}
