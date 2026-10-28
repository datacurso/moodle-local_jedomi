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
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_jedomi\local\service\feedback_service;

/**
 * Tests for the privacy provider of local_jedomi (answer feedback).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Create two users with feedback.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function create_users_with_feedback(): array {
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        feedback_service::record((int) $user1->id, 'good', 'sess_1', 'Answer one');
        feedback_service::record((int) $user1->id, 'bad', 'sess_1', 'Answer two');
        feedback_service::record((int) $user2->id, 'good', 'sess_2', 'Answer three');
        return [$user1, $user2];
    }

    /**
     * M10: the feedback table is declared in the metadata.
     */
    public function test_metadata_declares_feedback_table(): void {
        $collection = provider::get_metadata(new collection('local_jedomi'));
        $names = array_map(static fn($item) => $item->get_name(), $collection->get_collection());

        $this->assertContains('local_jedomi_feedback', $names);
    }

    /**
     * M10: a user's feedback is found in their user context and exported.
     */
    public function test_export_user_feedback(): void {
        $this->resetAfterTest();
        [$user1] = $this->create_users_with_feedback();
        $context = \context_user::instance($user1->id);

        $contextlist = provider::get_contexts_for_userid((int) $user1->id);
        $this->assertSame([(int) $context->id], array_map('intval', $contextlist->get_contextids()));

        provider::export_user_data(new approved_contextlist($user1, 'local_jedomi', [$context->id]));

        $data = writer::with_context($context)->get_data([get_string('privacy_feedback', 'local_jedomi')]);
        $this->assertCount(2, $data->feedback);
        $this->assertSame('Answer one', $data->feedback[0]['messagetext']);
    }

    /**
     * M10: deleting one user's data removes only their feedback.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        [$user1, $user2] = $this->create_users_with_feedback();
        $context = \context_user::instance($user1->id);

        provider::delete_data_for_user(new approved_contextlist($user1, 'local_jedomi', [$context->id]));

        $this->assertSame(0, $DB->count_records('local_jedomi_feedback', ['userid' => $user1->id]));
        $this->assertSame(1, $DB->count_records('local_jedomi_feedback', ['userid' => $user2->id]));
    }

    /**
     * M10: deleting a user context removes the feedback of that user.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        [$user1, $user2] = $this->create_users_with_feedback();

        provider::delete_data_for_all_users_in_context(\context_user::instance($user1->id));
        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(0, $DB->count_records('local_jedomi_feedback', ['userid' => $user1->id]));
        $this->assertSame(1, $DB->count_records('local_jedomi_feedback', ['userid' => $user2->id]));
    }

    /**
     * M10: the user list of a user context holds that user, and deleting it removes their feedback.
     */
    public function test_userlist_and_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        [$user1, $user2] = $this->create_users_with_feedback();
        $context = \context_user::instance($user1->id);

        $userlist = new userlist($context, 'local_jedomi');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $user1->id], array_map('intval', $userlist->get_userids()));

        provider::delete_data_for_users(new approved_userlist($context, 'local_jedomi', [$user1->id]));

        $this->assertSame(0, $DB->count_records('local_jedomi_feedback', ['userid' => $user1->id]));
        $this->assertSame(1, $DB->count_records('local_jedomi_feedback', ['userid' => $user2->id]));
    }
}
