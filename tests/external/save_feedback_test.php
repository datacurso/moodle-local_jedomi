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

namespace local_jedomi\external;

use core_external\external_api;

/**
 * Tests for the answer feedback web service.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\external\save_feedback
 * @covers     \local_jedomi\local\service\feedback_service
 */
final class save_feedback_test extends \advanced_testcase {
    /**
     * M10: feedback is stored for the current user only.
     */
    public function test_feedback_is_stored_for_current_user(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = save_feedback::execute('good', 'sess_abc123', 'The answer text');
        $result = external_api::clean_returnvalue(save_feedback::execute_returns(), $result);

        $this->assertTrue($result['saved']);
        $records = $DB->get_records('local_jedomi_feedback');
        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertSame((int) $user->id, (int) $record->userid);
        $this->assertSame('good', $record->rating);
        $this->assertSame('sess_abc123', $record->sessionid);
        $this->assertSame('The answer text', $record->messagetext);
        $this->assertFalse($DB->record_exists('local_jedomi_feedback', ['userid' => $other->id]));
    }

    /**
     * M10: only the two known ratings are accepted.
     */
    public function test_invalid_rating_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\invalid_parameter_exception::class);
        save_feedback::execute('excellent', 'sess_1', 'x');
    }

    /**
     * M10: guests cannot leave feedback.
     */
    public function test_guest_cannot_leave_feedback(): void {
        $this->resetAfterTest();
        $this->setGuestUser();

        $this->expectException(\moodle_exception::class);
        save_feedback::execute('bad', 'sess_1', 'x');
    }

    /**
     * M10: the answer excerpt is cut, and not stored at all with the "feedback only" anonymisation level.
     */
    public function test_message_text_is_trimmed_and_dropped_when_feedback_only(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        save_feedback::execute('bad', 'sess_1', str_repeat('a', 5000));
        set_config('anonymization_level', 'feedback_only', 'local_jedomi');
        save_feedback::execute('good', 'sess_2', 'Private answer');

        $long = $DB->get_record('local_jedomi_feedback', ['sessionid' => 'sess_1']);
        $this->assertSame(\local_jedomi\local\service\feedback_service::MAX_TEXT_LENGTH, \core_text::strlen($long->messagetext));
        $private = $DB->get_record('local_jedomi_feedback', ['sessionid' => 'sess_2']);
        $this->assertSame('', (string) $private->messagetext);
    }
}
